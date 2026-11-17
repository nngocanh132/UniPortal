<?php
/**
 * UniPortal – SePay-compatible webhook endpoint.
 *
 * POST /UniPortal/public/api/payment_webhook.php
 *
 * The provider authenticates with either:
 *   Authorization: Apikey <SEPAY_API_KEY>
 *   or X-SePay-Timestamp + X-SePay-Signature (HMAC-SHA256, 5-minute window).
 *
 * Local test mode (PAYMENT_TEST_MODE=1) uses PAYMENT_TEST_WEBHOOK_KEY and marks
 * every processed transaction as is_test=1. The browser is never trusted.
 */
require_once dirname(__DIR__, 2) . '/src/src/services/PaymentService.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty body']);
    exit;
}
if (strlen($raw) > 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Payload too large']);
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!$headers && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
}

try {
    $result = PaymentService::handleWebhook($raw, $headers);
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Payment webhook endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error']);
}
