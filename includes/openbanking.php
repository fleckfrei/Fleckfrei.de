<?php
/**
 * Enable Banking API Integration
 * Docs: https://enablebanking.com/docs/api/reference/
 * Auth: JWT signed with RSA private key
 */

class OpenBanking {
    private $baseUrl = 'https://api.enablebanking.com';
    private $token = null;

    public function isConfigured() {
        return !empty(OPENBANKING_APP_ID) && defined('OPENBANKING_PEM_PATH') && file_exists(OPENBANKING_PEM_PATH);
    }

    private function getToken() {
        if ($this->token) return $this->token;

        $pemPath = OPENBANKING_PEM_PATH;
        $privateKey = openssl_pkey_get_private(file_get_contents($pemPath));
        if (!$privateKey) return null;

        // Build JWT
        $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $payload = base64url_encode(json_encode([
            'iss' => 'enablebanking.com',
            'aud' => 'api.enablebanking.com',
            'iat' => $now,
            'exp' => $now + 3600,
            'sub' => OPENBANKING_APP_ID
        ]));

        $signature = '';
        openssl_sign("$header.$payload", $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $this->token = "$header.$payload." . base64url_encode($signature);

        return $this->token;
    }

    // Start bank authorization
    public function startSession($bankName = 'N26', $country = 'DE') {
        $redirectUrl = 'https://app.' . SITE_DOMAIN . '/admin/bank-callback.php';
        return $this->request('/sessions', 'POST', [
            'access' => [
                'valid_until' => date('Y-m-d\TH:i:s\Z', strtotime('+90 days'))
            ],
            'aspsp' => [
                'name' => $bankName,
                'country' => $country
            ],
            'state' => bin2hex(random_bytes(16)),
            'redirect_url' => $redirectUrl,
            'psu_type' => 'personal'
        ]);
    }

    // Get session (after redirect back)
    public function getSession($sessionId) {
        return $this->request("/sessions/$sessionId");
    }

    // List available banks
    public function getBanks($country = 'DE') {
        return $this->request("/aspsps?country=$country");
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

    private function request($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        $token = $this->getToken();
        if (!$token) return ['error' => 'JWT auth failed'];

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $resp ? json_decode($resp, true) : ['error' => 'Request failed', 'http_code' => $code];
    }
}

// Base64url encode helper (for JWT)
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Auto-match bank transactions with open invoices
 */
function matchTransactionsWithInvoices($transactions) {
    $openInvoices = all("SELECT i.*, c.name as cname FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.invoice_paid='no' AND i.remaining_price > 0");
    $results = ['matched' => [], 'unmatched' => []];

    foreach ($transactions as $tx) {
        $amount = abs((float)($tx['transaction_amount']['amount'] ?? $tx['transactionAmount']['amount'] ?? $tx['amount'] ?? 0));
        $ref = $tx['remittance_information_unstructured'] ?? $tx['remittanceInformationUnstructured'] ?? $tx['reference'] ?? '';
        $debtor = $tx['debtor_name'] ?? $tx['debtorName'] ?? $tx['payee'] ?? '';
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
        // Strategy 3: Unique amount
        if (!$match) {
            $candidates = array_filter($openInvoices, fn($inv) => abs($amount - $inv['remaining_price']) < 0.02 || abs($amount - $inv['total_price']) < 0.02);
            if (count($candidates) === 1) { $match = reset($candidates); $matchType = 'Betrag eindeutig'; }
        }

        $txData = ['amount' => $amount, 'date' => $tx['booking_date'] ?? $tx['bookingDate'] ?? $tx['date'] ?? '', 'debtor' => $debtor, 'reference' => $ref];
        if ($match) $results['matched'][] = ['tx' => $txData, 'invoice' => $match, 'type' => $matchType];
        else $results['unmatched'][] = $txData;
    }
    return $results;
}

/**
 * Auto-apply matched transactions
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
            [(int)$inv['inv_id'], $amount, $m['tx']['date'] ?: date('Y-m-d'), 'Bank (Auto)', 'Auto: ' . $m['type']]);
        audit('auto_payment', 'invoice', (int)$inv['inv_id'], "Bank Auto: {$amount} EUR, {$m['type']}");
        $applied++;
    }
    return $applied;
}
