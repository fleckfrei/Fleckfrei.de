<?php
/**
 * PayPal Capture — Placeholder
 * PayPal credentials not yet configured.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://fleckfrei.de');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

http_response_code(503);
echo json_encode([
    'success' => false,
    'error' => 'PayPal ist derzeit nicht verfügbar.'
]);
