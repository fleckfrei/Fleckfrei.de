<?php
/**
 * Open Banking Integration — Enable Banking API
 * Docs: https://enablebanking.com/docs
 *
 * Flow:
 * 1. Admin clicks "Bank verbinden" → redirect to bank auth
 * 2. User authenticates at N26 → redirect back with auth code
 * 3. System gets session → fetches transactions
 * 4. Auto-matches transactions with open invoices
 * 5. n8n cron fetches daily → fully automatic
 */

class OpenBanking {
    private $baseUrl = 'https://api.enablebanking.com';
    private $token = null;

    public function isConfigured() {
        return !empty(OPENBANKING_APP_ID) && !empty(OPENBANKING_SECRET);
    }

    private function getToken() {
        if ($this->token) return $this->token;
        // Enable Banking uses JWT auth
        $payload = json_encode([
            'application_id' => OPENBANKING_APP_ID,
            'secret' => OPENBANKING_SECRET
        ]);
        $resp = $this->request('/auth/token', 'POST', $payload, false);
        if ($resp && !empty($resp['access_token'])) {
            $this->token = $resp['access_token'];
        }
        return $this->token;
    }

    // Get list of supported banks for country
    public function getBanks($country = 'DE') {
        return $this->request("/aspsps?country=$country");
    }

    // Start authorization (redirect user to bank)
    public function startAuth($bankId, $redirectUri) {
        return $this->request('/sessions', 'POST', json_encode([
            'aspsp' => ['name' => $bankId, 'country' => 'DE'],
            'redirect_url' => $redirectUri,
            'psu_type' => 'personal',
            'access' => ['valid_until' => date('Y-m-d', strtotime('+90 days'))]
        ]));
    }

    // Complete auth after redirect
    public function completeAuth($sessionId) {
        return $this->request("/sessions/$sessionId");
    }

    // Get accounts for a session
    public function getAccounts($sessionId) {
        return $this->request("/sessions/$sessionId/accounts");
    }

    // Get account balances
    public function getBalances($accountId) {
        return $this->request("/accounts/$accountId/balances");
    }

    // Get transactions
    public function getTransactions($accountId, $dateFrom = null, $dateTo = null) {
        $params = [];
        if ($dateFrom) $params[] = "date_from=$dateFrom";
        if ($dateTo) $params[] = "date_to=$dateTo";
        $qs = $params ? '?' . implode('&', $params) : '';
        return $this->request("/accounts/$accountId/transactions$qs");
    }

    private function request($endpoint, $method = 'GET', $body = null, $auth = true) {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth) {
            $token = $this->getToken();
            if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp ? json_decode($resp, true) : null;
    }
}

/**
 * Auto-match bank transactions with open invoices
 * Returns array of matched + unmatched transactions
 */
function matchTransactionsWithInvoices($transactions) {
    $openInvoices = all("SELECT i.*, c.name as cname FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.invoice_paid='no' AND i.remaining_price > 0");
    $results = ['matched' => [], 'unmatched' => []];

    foreach ($transactions as $tx) {
        $amount = abs((float)($tx['transactionAmount']['amount'] ?? $tx['amount'] ?? 0));
        $ref = $tx['remittanceInformationUnstructured'] ?? $tx['reference'] ?? '';
        $debtor = $tx['debtorName'] ?? $tx['payee'] ?? '';
        if ($amount <= 0) continue;

        $match = null;
        $matchType = '';

        // Strategy 1: Invoice number in reference
        foreach ($openInvoices as $inv) {
            if ($inv['invoice_number'] && stripos($ref, $inv['invoice_number']) !== false) {
                $match = $inv; $matchType = 'Rechnungsnr.'; break;
            }
        }
        // Strategy 2: Amount + customer name
        if (!$match) {
            foreach ($openInvoices as $inv) {
                $amtOk = abs($amount - $inv['remaining_price']) < 0.02 || abs($amount - $inv['total_price']) < 0.02;
                $nameOk = $inv['cname'] && (stripos($debtor, $inv['cname']) !== false || stripos($ref, $inv['cname']) !== false);
                if ($amtOk && $nameOk) { $match = $inv; $matchType = 'Betrag+Name'; break; }
            }
        }
        // Strategy 3: Unique amount match
        if (!$match) {
            $candidates = array_filter($openInvoices, fn($inv) => abs($amount - $inv['remaining_price']) < 0.02 || abs($amount - $inv['total_price']) < 0.02);
            if (count($candidates) === 1) { $match = reset($candidates); $matchType = 'Betrag eindeutig'; }
        }

        $txData = ['amount' => $amount, 'date' => $tx['bookingDate'] ?? $tx['date'] ?? '', 'debtor' => $debtor, 'reference' => $ref];
        if ($match) {
            $results['matched'][] = ['tx' => $txData, 'invoice' => $match, 'type' => $matchType];
        } else {
            $results['unmatched'][] = $txData;
        }
    }
    return $results;
}

/**
 * Auto-apply matched transactions (mark invoices as paid)
 */
function autoApplyMatches($matched) {
    $applied = 0;
    foreach ($matched as $m) {
        $inv = $m['invoice'];
        $amount = $m['tx']['amount'];
        $newRemaining = max(0, $inv['remaining_price'] - $amount);
        $paid = $newRemaining <= 0 ? 'yes' : 'no';
        q("UPDATE invoices SET remaining_price=?, invoice_paid=? WHERE inv_id=?", [$newRemaining, $paid, (int)$inv['inv_id']]);
        qLocal("INSERT INTO invoice_payments (invoice_id_fk, amount, payment_date, payment_method, note) VALUES (?,?,?,?,?)",
            [(int)$inv['inv_id'], $amount, $m['tx']['date'] ?: date('Y-m-d'), 'Bank (Auto)', 'Auto-Import: ' . $m['type']]);
        audit('auto_payment', 'invoice', (int)$inv['inv_id'], "Auto Bank: {$amount}€, {$m['type']}");
        $applied++;
    }
    return $applied;
}
