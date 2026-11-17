<?php
/**
 * UniPortal – Student payment status API
 * JSON snapshot of the current student's invoices for live polling.
 * Never reports a payment as successful – it only reflects confirmed
 * bank transactions already recorded by the webhook pipeline.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/PaymentService.php';

require_student();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$pdo        = get_db();
$student_id = (string)($_SESSION['student_id'] ?? '');
$account_id = (int)($_SESSION['account_id'] ?? 0);

$labels = [
    'Unpaid'   => ['Chưa thanh toán',         '#fee2e2', '#b91c1c'],
    'Awaiting' => ['Chờ chuyển khoản',        '#fef9c3', '#854d0e'],
    'Partial'  => ['Thanh toán một phần',     '#fef9c3', '#854d0e'],
    'Paid'     => ['Đã thanh toán',           '#d1fae5', '#065f46'],
    'Waived'   => ['Miễn học phí',            '#dbeafe', '#1e40af'],
    'Review'   => ['Cần xác minh thanh toán', '#ffedd5', '#9a3412'],
];

try {
    $stmt = $pdo->prepare(
        'SELECT t.tuition_id
         FROM Tuition t JOIN Semester sem ON sem.semester_id = t.semester_id
         WHERE t.student_id = :sid
         ORDER BY sem.start_date DESC'
    );
    $stmt->execute([':sid' => $student_id]);

    $invoices = [];
    foreach ($stmt->fetchAll() as $row) {
        $sum = PaymentService::summary($pdo, (int)$row['tuition_id'], $student_id, $account_id);
        if (!$sum) continue;

        $status = $sum['display_status'];
        [$label, $bg, $color] = $labels[$status] ?? ['Không rõ', '#f1f5f9', '#64748b'];
        $req = $sum['request'];

        $payments = [];
        foreach (array_slice($sum['payments'], 0, 8) as $p) {
            $payments[] = [
                'payment_id'  => (int)$p['payment_id'],
                'amount_vnd'  => (int)$p['amount_vnd'],
                'status'      => $p['status'],
                'provider'    => $p['provider'] ?? null,
                'is_test'     => (int)($p['is_test'] ?? 0),
                'bank_ref'    => $p['bank_reference'] ?? ($p['reference_code'] ?? null),
                'paid_at'     => $p['transaction_date'] ?: $p['created_at'],
                'overpaid'    => (int)($p['overpaid_vnd'] ?? 0),
            ];
        }

        $invoices[] = [
            'tuition_id'  => (int)$sum['tuition']['tuition_id'],
            'semester'    => $sum['tuition']['semester_name'] . ' ' . $sum['tuition']['academic_year'],
            'status'      => $status,
            'status_label'=> $label,
            'status_bg'   => $bg,
            'status_color'=> $color,
            'amount'      => (int)$sum['amount'],
            'confirmed'   => (int)$sum['confirmed'],
            'outstanding' => (int)$sum['outstanding'],
            'request'     => $req ? [
                'id'         => (int)$req['payment_request_id'],
                'amount_vnd' => (int)$req['amount_vnd'],
                'reference'  => $req['reference'],
                'expires_at' => $req['expires_at'],
            ] : null,
            'payments'    => $payments,
        ];
    }

    echo json_encode([
        'ok'       => true,
        'time'     => date('c'),
        'invoices' => $invoices,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Không thể tải trạng thái thanh toán.']);
}
