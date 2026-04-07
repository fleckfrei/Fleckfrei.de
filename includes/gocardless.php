<?php
/**
 * GoCardless Bank Account Data API (Open Banking)
 * Docs: https://developer.gocardless.com/bank-account-data/overview
 */

class GoCardless {
    private $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';
    private $token = null;

    public function __construct() {
        if (!defined('GOCARDLESS_SECRET_ID') || !GOCARDLESS_SECRET_ID || !GOCARDLESS_SECRET_KEY) return;
        $this->authenticate();
    }

    public function isConfigured() {
        return defined('GOCARDLESS_SECRET_ID') && !empty(GOCARDLESS_SECRET_ID) && !empty(GOCARDLESS_SECRET_KEY);
    }

    private function authenticate() {
        $resp = $this->request('/token/new/', 'POST', [
            'secret_id' => GOCARDLESS_SECRET_ID,
            'secret_key' => GOCARDLESS_SECRET_KEY
        ], false);
        if ($resp && !empty($resp['access'])) {
            $this->token = $resp['access'];
        }
    }

    // Step 1: Create a requisition (link to bank)
    public function createRequisition($institutionId, $redirectUrl) {
        return $this->request('/requisitions/', 'POST', [
            'institution_id' => $institutionId,
            'redirect' => $redirectUrl
        ]);
    }

    // Step 2: Get requisition status + account IDs
    public function getRequisition($reqId) {
        return $this->request("/requisitions/$reqId/");
    }

    // Get list of banks (institutions) for a country
    public function getInstitutions($country = 'DE') {
        return $this->request("/institutions/?country=$country");
    }

    // Get account details
    public function getAccount($accountId) {
        return $this->request("/accounts/$accountId/");
    }

    // Get account balances
    public function getBalances($accountId) {
        return $this->request("/accounts/$accountId/balances/");
    }

    // Get transactions (last 90 days)
    public function getTransactions($accountId, $dateFrom = null, $dateTo = null) {
        $params = '';
        if ($dateFrom) $params .= "?date_from=$dateFrom";
        if ($dateTo) $params .= ($params ? '&' : '?') . "date_to=$dateTo";
        return $this->request("/accounts/$accountId/transactions/$params");
    }

    private function request($endpoint, $method = 'GET', $data = null, $auth = true) {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth && $this->token) $headers[] = 'Authorization: Bearer ' . $this->token;

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

        if (!$resp) return null;
        return json_decode($resp, true);
    }
}
