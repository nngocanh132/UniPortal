<?php
/**
 * UniPortal – QR image endpoint
 * Serves the VietQR SVG for one payment request owned by the current student.
 * The payload is regenerated from the invoice's current outstanding balance,
 * so a stale URL can never encode an out-of-date amount.
 */
$root = dirname(__DIR__);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/PaymentService.php';
require_once $root . '/src/src/services/VietQrService.php';

require_student();

$rid = (int)($_GET['rid'] ?? 0);
if ($rid < 1) { http_response_code(404); exit; }

$pdo = get_db();
$stmt = $pdo->prepare(
    'SELECT payment_request_id, tuition_id FROM PaymentRequest
     WHERE payment_request_id = :rid AND student_id = :sid LIMIT 1'
);
$stmt->execute([':rid' => $rid, ':sid' => (string)($_SESSION['student_id'] ?? '')]);
$owned = $stmt->fetch();
if (!$owned) { http_response_code(404); exit; }

$req = PaymentService::ensureRequest(
    $pdo, (int)$owned['tuition_id'], (string)$_SESSION['student_id'], (int)($_SESSION['account_id'] ?? 0)
);
if (!$req || empty($req['qr_payload'])) {
    http_response_code(410);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Mã QR đã hết hạn hoặc hóa đơn đã được thanh toán.');
}

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
echo VietQrService::renderSvg($req['qr_payload'], 7);
