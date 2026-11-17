<?php
/**
 * UniPortal – local webhook test sender (CLI only).
 *
 * Sends a signed SePay-shaped transaction to the local webhook so the complete
 * QR → webhook → invoice update workflow can be verified without a real bank.
 * Test transactions are marked is_test=1 by the endpoint.
 *
 * Examples:
 *   php database/test_payment_webhook.php --tuition=8 --amount=7200000
 *   php database/test_payment_webhook.php --tuition=8 --amount=400000
 *   php database/test_payment_webhook.php --content="UNI... chuyen tien" --amount=100000
 *
 * Options:
 *   --tuition=ID     Use the active PaymentRequest reference of this invoice.
 *   --content=TEXT   Explicit transfer description (overrides --tuition).
 *   --amount=N       Amount in VND (required).
 *   --id=STRING      Provider transaction id (default: TEST-<timestamp>).
 *   --gateway=NAME   Bank name (default: VPBank).
 *   --out            Send transferType=out (must be ignored, no credit).
 *   --account=NUMBER Override receiving account (to test mismatch).
 *   --live           Use SEPAY_API_KEY instead of the local test key.
 *   --hmac           Sign with SEPAY_WEBHOOK_SECRET (X-SePay-Timestamp/Signature).
 *   --url=URL        Override webhook URL.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/src/services/PaymentService.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) continue;
    $arg = substr($arg, 2);
    $eq = strpos($arg, '=');
    if ($eq === false) $opts[$arg] = true;
    else $opts[substr($arg, 0, $eq)] = substr($arg, $eq + 1);
}

$cfg = payment_config();
$pdo = get_db();

$content = (string)($opts['content'] ?? '');
if ($content === '' && isset($opts['tuition'])) {
    $q = $pdo->prepare(
        "SELECT reference FROM PaymentRequest
         WHERE tuition_id = :tid AND status = 'Active' ORDER BY payment_request_id DESC LIMIT 1"
    );
    $q->execute([':tid' => (int)$opts['tuition']]);
    $reference = $q->fetchColumn();
    if (!$reference) {
        fwrite(STDERR, "No active PaymentRequest for tuition {$opts['tuition']}. Open the student tuition page first.\n");
        exit(1);
    }
    $content = $reference . ' chuyen tien hoc phi';
}
if ($content === '') {
    fwrite(STDERR, "Provide --tuition=ID or --content=TEXT.\n");
    exit(1);
}
if (!isset($opts['amount']) || !is_numeric($opts['amount'])) {
    fwrite(STDERR, "Provide --amount=N.\n");
    exit(1);
}

$txnId = (string)($opts['id'] ?? ('TEST-' . time() . '-' . random_int(100, 999)));
$payload = [
    'id' => $txnId,
    'gateway' => (string)($opts['gateway'] ?? $cfg['bank_code']),
    'transactionDate' => date('Y-m-d H:i:s'),
    'accountNumber' => (string)($opts['account'] ?? $cfg['account_number']),
    'subAccount' => '',
    'code' => VietQrService::extractReference($content) ?? '',
    'content' => $content,
    'transferType' => isset($opts['out']) ? 'out' : 'in',
    'transferAmount' => (int)$opts['amount'],
    'accumulated' => 0,
    'referenceCode' => 'FT' . date('His') . random_int(100, 999),
    'description' => $content,
];
$body = json_encode($payload, JSON_UNESCAPED_UNICODE);

$headers = ['Content-Type: application/json'];
if (isset($opts['hmac'])) {
    if ($cfg['webhook_secret'] === '') {
        fwrite(STDERR, "SEPAY_WEBHOOK_SECRET is empty.\n");
        exit(1);
    }
    $ts = (string)time();
    $headers[] = 'X-SePay-Timestamp: ' . $ts;
    $headers[] = 'X-SePay-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $body, $cfg['webhook_secret']);
} elseif (isset($opts['live'])) {
    if ($cfg['api_key'] === '') {
        fwrite(STDERR, "SEPAY_API_KEY is empty.\n");
        exit(1);
    }
    $headers[] = 'Authorization: Apikey ' . $cfg['api_key'];
} else {
    if (!$cfg['test_ready']) {
        fwrite(STDERR, "Test webhook is not configured (PAYMENT_TEST_MODE / PAYMENT_TEST_WEBHOOK_KEY).\n");
        exit(1);
    }
    $headers[] = 'Authorization: Apikey ' . $cfg['test_webhook_key'];
    $headers[] = 'X-UniPortal-Test: 1';
}

$url = (string)($opts['url'] ?? 'http://localhost/UniPortal/public/api/payment_webhook.php');
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 15,
    ],
]);

echo "POST $url\n";
echo $body . "\n";
$response = file_get_contents($url, false, $context);
$status = 0;
if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
    $status = (int)$m[1];
}
echo "HTTP $status\n";
echo ($response === false ? '(no response)' : $response) . "\n";
exit($status >= 200 && $status < 300 ? 0 : 2);
