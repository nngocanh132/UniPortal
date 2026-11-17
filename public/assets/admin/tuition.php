<?php
/**
 * UniPortal – Quản lý thanh toán học phí (Admin)
 * Hóa đơn, giao dịch ngân hàng đã xác thực, giao dịch chưa khớp và thu vượt.
 * Thanh toán đã được webhook xác thực không cần Admin duyệt thủ công.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/PaymentService.php';
require_once $root . '/src/src/config/payment.php';

require_login();
if (($_SESSION['role'] ?? '') !== 'AcademicAffairs') {
    header('Location: ' . app_url('index.php'));
    exit;
}
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo      = get_db();
$adminId  = (int)($_SESSION['account_id'] ?? 0);
$cfg      = payment_config();
$q        = trim((string)($_GET['q'] ?? ''));
$tab      = (string)($_GET['tab'] ?? 'invoices');
$flash    = '';

if (!function_exists('vnd')) {
    function vnd(int $n): string { return number_format($n, 0, ',', '.') . ' ₫'; }
}

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'resolve_tx') {
        $res = PaymentService::resolveUnmatched(
            (int)($_POST['bank_tx_id'] ?? 0),
            (int)($_POST['tuition_id'] ?? 0),
            $adminId,
            trim((string)($_POST['note'] ?? ''))
        );
        $flash = ($res['ok'] ? 'success:' : 'error:') . $res['message'];
        $tab = 'unmatched';
    } elseif ($action === 'reject_tx') {
        $res = PaymentService::rejectUnmatched(
            (int)($_POST['bank_tx_id'] ?? 0),
            $adminId,
            trim((string)($_POST['note'] ?? ''))
        );
        $flash = ($res['ok'] ? 'success:' : 'error:') . $res['message'];
        $tab = 'unmatched';
    } elseif ($action === 'resolve_overpay') {
        $res = PaymentService::resolveOverpayment(
            (int)($_POST['payment_id'] ?? 0),
            $adminId,
            trim((string)($_POST['note'] ?? ''))
        );
        $flash = ($res['ok'] ? 'success:' : 'error:') . $res['message'];
        $tab = 'overpaid';
    }
}

// ── CSV export ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $like = '%' . $q . '%';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="uniportal-payments-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    if ($_GET['export'] === 'invoices') {
        fputcsv($out, ['tuition_id','student_id','full_name','semester','academic_year',
            'credits','amount_vnd','confirmed_vnd','outstanding_vnd','status','due_date']);
        $s = $pdo->prepare(
            "SELECT t.tuition_id, t.student_id, stu.full_name, sem.semester_name, sem.academic_year,
                    t.credits, t.amount_vnd, t.status, t.due_date,
                    (SELECT COALESCE(SUM(p.amount_vnd),0) FROM Payment p
                     WHERE p.tuition_id=t.tuition_id AND p.status='Confirmed') AS confirmed
             FROM Tuition t JOIN Student stu ON stu.student_id=t.student_id
             JOIN Semester sem ON sem.semester_id=t.semester_id
             WHERE (t.student_id LIKE :q1 OR stu.full_name LIKE :q2 OR sem.semester_name LIKE :q3)
             ORDER BY sem.start_date DESC"
        );
        $s->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
        foreach ($s as $r) {
            $amount = (int)$r['amount_vnd'];
            $conf   = min((int)$r['confirmed'], $amount);
            if ($r['status'] === 'Paid') $conf = $amount;
            fputcsv($out, [$r['tuition_id'],$r['student_id'],$r['full_name'],$r['semester_name'],
                $r['academic_year'],$r['credits'],$amount,$conf,max(0,$amount-$conf),$r['status'],$r['due_date']]);
        }
    } else {
        fputcsv($out, ['bank_transaction_id','provider','provider_txn_id','gateway','transfer_type',
            'transfer_amount','account_number','reference','content','transaction_date','status',
            'student_id','tuition_id','payment_id','is_test','received_at','match_note']);
        $s = $pdo->prepare(
            "SELECT bt.*, pr.reference, pr.student_id, pr.tuition_id
             FROM BankTransaction bt
             LEFT JOIN PaymentRequest pr ON pr.payment_request_id=bt.payment_request_id
             LEFT JOIN Student stu ON stu.student_id=pr.student_id
             WHERE (bt.provider_transaction_id LIKE :q1 OR bt.reference_code LIKE :q2
                    OR bt.content LIKE :q3 OR pr.reference LIKE :q4
                    OR pr.student_id LIKE :q5 OR stu.full_name LIKE :q6)
             ORDER BY bt.received_at DESC"
        );
        $s->execute([':q1'=>$like,':q2'=>$like,':q3'=>$like,':q4'=>$like,':q5'=>$like,':q6'=>$like]);
        foreach ($s as $r) {
            fputcsv($out, [$r['bank_transaction_id'],$r['provider'],$r['provider_transaction_id'],
                $r['gateway'],$r['transfer_type'],$r['transfer_amount'],$r['account_number'],
                $r['reference'],$r['content'],$r['transaction_date'],$r['status'],
                $r['student_id'],$r['tuition_id'],$r['payment_id'],$r['is_test'],
                $r['received_at'],$r['match_note']]);
        }
    }
    fclose($out);
    exit;
}

// ── KPI counts ────────────────────────────────────────────────
$kpi = $pdo->query(
    "SELECT
        (SELECT COALESCE(SUM(amount_vnd),0) FROM Tuition WHERE status <> 'Waived') AS billed,
        (SELECT COALESCE(SUM(amount_vnd),0) FROM Payment WHERE status='Confirmed') AS paid,
        (SELECT COUNT(*) FROM BankTransaction WHERE status='Unmatched') AS unmatched,
        (SELECT COUNT(*) FROM Payment WHERE overpaid_vnd > 0 AND review_status='Review') AS overpaid,
        (SELECT COUNT(*) FROM PaymentRequest WHERE status='Active' AND expires_at > NOW()) AS active_qr"
)->fetch();

// ── Invoice list ──────────────────────────────────────────────
$like = '%' . $q . '%';
$invStmt = $pdo->prepare(
    "SELECT t.*, stu.full_name, stu.class_code, sem.semester_name, sem.academic_year,
            (SELECT COALESCE(SUM(p.amount_vnd),0) FROM Payment p
             WHERE p.tuition_id=t.tuition_id AND p.status='Confirmed') AS confirmed,
            (SELECT COUNT(*) FROM Payment p2 WHERE p2.tuition_id=t.tuition_id) AS payment_count,
            (SELECT MAX(p3.confirmed_at) FROM Payment p3
             WHERE p3.tuition_id=t.tuition_id AND p3.status='Confirmed') AS last_paid
     FROM Tuition t
     JOIN Student stu ON stu.student_id=t.student_id
     JOIN Semester sem ON sem.semester_id=t.semester_id
     WHERE (t.student_id LIKE :q1 OR stu.full_name LIKE :q2
            OR sem.semester_name LIKE :q3 OR sem.academic_year LIKE :q4
            OR EXISTS (SELECT 1 FROM PaymentRequest pr
                       WHERE pr.tuition_id=t.tuition_id AND pr.reference LIKE :q5))
     ORDER BY sem.start_date DESC, stu.student_id
     LIMIT 200"
);
$invStmt->execute([':q1'=>$like,':q2'=>$like,':q3'=>$like,':q4'=>$like,':q5'=>$like]);
$invoices = $invStmt->fetchAll();

$activeQr = [];
foreach ($pdo->query("SELECT tuition_id, COUNT(*) c FROM PaymentRequest
                      WHERE status='Active' AND expires_at > NOW() GROUP BY tuition_id") as $r) {
    $activeQr[(int)$r['tuition_id']] = (int)$r['c'];
}
$unmatchedByInvoice = [];
foreach ($pdo->query(
    "SELECT pr.tuition_id, COUNT(*) c
     FROM BankTransaction bt JOIN PaymentRequest pr ON pr.payment_request_id=bt.payment_request_id
     WHERE bt.status='Unmatched' GROUP BY pr.tuition_id"
) as $r) {
    $unmatchedByInvoice[(int)$r['tuition_id']] = (int)$r['c'];
}

// ── Transactions ──────────────────────────────────────────────
$txStatus = (string)($_GET['tx_status'] ?? '');
$txWhere  = '(bt.provider_transaction_id LIKE :q1 OR bt.reference_code LIKE :q2
              OR bt.content LIKE :q3 OR pr.reference LIKE :q4
              OR pr.student_id LIKE :q5 OR stu.full_name LIKE :q6)';
$txParams = [':q1'=>$like,':q2'=>$like,':q3'=>$like,':q4'=>$like,':q5'=>$like,':q6'=>$like];
if (in_array($txStatus, ['Processed','Unmatched','Ignored','Rejected','Duplicate'], true)) {
    $txWhere .= ' AND bt.status = :st';
    $txParams[':st'] = $txStatus;
}
$txStmt = $pdo->prepare(
    "SELECT bt.*, pr.reference, pr.student_id, pr.amount_vnd AS request_amount,
            pr.tuition_id, stu.full_name
     FROM BankTransaction bt
     LEFT JOIN PaymentRequest pr ON pr.payment_request_id=bt.payment_request_id
     LEFT JOIN Student stu ON stu.student_id=pr.student_id
     WHERE $txWhere
     ORDER BY bt.received_at DESC, bt.bank_transaction_id DESC
     LIMIT 200"
);
$txStmt->execute($txParams);
$transactions = $txStmt->fetchAll();

$unmatched = $pdo->query(
    "SELECT bt.*, pr.reference, pr.student_id, pr.tuition_id, stu.full_name
     FROM BankTransaction bt
     LEFT JOIN PaymentRequest pr ON pr.payment_request_id=bt.payment_request_id
     LEFT JOIN Student stu ON stu.student_id=pr.student_id
     WHERE bt.status='Unmatched'
     ORDER BY bt.received_at DESC LIMIT 200"
)->fetchAll();

$overpaid = $pdo->query(
    "SELECT p.*, t.student_id, stu.full_name, t.amount_vnd AS tuition_amount,
            sem.semester_name, sem.academic_year, pr.reference
     FROM Payment p
     JOIN Tuition t ON t.tuition_id=p.tuition_id
     JOIN Student stu ON stu.student_id=t.student_id
     JOIN Semester sem ON sem.semester_id=t.semester_id
     LEFT JOIN PaymentRequest pr ON pr.payment_request_id=p.payment_request_id
     WHERE p.overpaid_vnd > 0 AND p.review_status='Review'
     ORDER BY p.created_at DESC"
)->fetchAll();

// Invoice options for manual matching
$openInvoices = $pdo->query(
    "SELECT t.tuition_id, t.student_id, stu.full_name, sem.semester_name, sem.academic_year,
            t.amount_vnd,
            (SELECT COALESCE(SUM(p.amount_vnd),0) FROM Payment p
             WHERE p.tuition_id=t.tuition_id AND p.status='Confirmed') AS confirmed
     FROM Tuition t JOIN Student stu ON stu.student_id=t.student_id
     JOIN Semester sem ON sem.semester_id=t.semester_id
     WHERE t.status NOT IN ('Paid','Waived')
     HAVING confirmed < t.amount_vnd
     ORDER BY t.tuition_id DESC LIMIT 300"
)->fetchAll();

// Student history lookup
$studentView = null;
$viewSid = trim((string)($_GET['student'] ?? ''));
if ($viewSid !== '' && preg_match('/^\d{8}$/', $viewSid)) {
    $s = $pdo->prepare('SELECT * FROM Student WHERE student_id=:sid LIMIT 1');
    $s->execute([':sid' => $viewSid]);
    $studentView = $s->fetch() ?: null;
    if ($studentView) {
        $si = $pdo->prepare(
            "SELECT t.*, sem.semester_name, sem.academic_year,
                    (SELECT COALESCE(SUM(p.amount_vnd),0) FROM Payment p
                     WHERE p.tuition_id=t.tuition_id AND p.status='Confirmed') AS confirmed
             FROM Tuition t JOIN Semester sem ON sem.semester_id=t.semester_id
             WHERE t.student_id=:sid ORDER BY sem.start_date DESC"
        );
        $si->execute([':sid' => $viewSid]);
        $studentView['invoices'] = $si->fetchAll();

        $sp = $pdo->prepare(
            'SELECT p.*, bt.reference_code, bt.gateway, bt.transaction_date, pr.reference
             FROM Payment p
             LEFT JOIN BankTransaction bt ON bt.payment_id=p.payment_id
             LEFT JOIN PaymentRequest pr ON pr.payment_request_id=p.payment_request_id
             WHERE p.student_id=:sid ORDER BY p.created_at DESC LIMIT 100'
        );
        $sp->execute([':sid' => $viewSid]);
        $studentView['payments'] = $sp->fetchAll();

        $st = $pdo->prepare(
            'SELECT bt.*, pr.reference, pr.tuition_id
             FROM BankTransaction bt
             JOIN PaymentRequest pr ON pr.payment_request_id=bt.payment_request_id
             WHERE pr.student_id=:sid ORDER BY bt.received_at DESC LIMIT 100'
        );
        $st->execute([':sid' => $viewSid]);
        $studentView['transactions'] = $st->fetchAll();
    }
}

$statusMeta = [
    'Paid'     => ['Đã thanh toán',        '#d1fae5', '#065f46'],
    'Partial'  => ['Một phần',             '#fef9c3', '#854d0e'],
    'Awaiting' => ['Chờ chuyển khoản',     '#e0f2fe', '#0369a1'],
    'Review'   => ['Cần xác minh',         '#ffedd5', '#9a3412'],
    'Unpaid'   => ['Chưa thanh toán',      '#fee2e2', '#b91c1c'],
    'Waived'   => ['Miễn học phí',         '#dbeafe', '#1e40af'],
];
$txStatusMeta = [
    'Processed' => ['Đã xử lý',   '#d1fae5', '#065f46'],
    'Unmatched' => ['Chưa khớp',  '#ffedd5', '#9a3412'],
    'Ignored'   => ['Bỏ qua',     '#f1f5f9', '#475569'],
    'Rejected'  => ['Từ chối',    '#fee2e2', '#b91c1c'],
    'Duplicate' => ['Trùng lặp',  '#ede9fe', '#5b21b6'],
];

$pageTitle  = 'Thanh toán học phí';
$activePage = 'reports';
$breadcrumb = 'Quản trị › Tài chính › Thanh toán học phí';
$warnCount  = (int)$kpi['unmatched'];
require_once __DIR__ . '/_layout.php';

$tabUrl = function (string $t) use ($q): string {
    return '?tab=' . urlencode($t) . ($q !== '' ? '&q=' . urlencode($q) : '');
};
?>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div class="adm-flash adm-flash--<?= $ft === 'success' ? 'success' : 'error' ?>"
     style="margin-bottom:14px;font-size:13px;font-weight:600;">
    <?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<div class="adm-kpi-row" style="margin-bottom:16px;">
    <div class="adm-kpi">
        <div class="adm-kpi__label">Tổng học phí</div>
        <div class="adm-kpi__num"><?= vnd((int)$kpi['billed']) ?></div>
    </div>
    <div class="adm-kpi">
        <div class="adm-kpi__label">Đã xác nhận</div>
        <div class="adm-kpi__num adm-kpi__num--green"><?= vnd((int)$kpi['paid']) ?></div>
    </div>
    <div class="adm-kpi">
        <div class="adm-kpi__label">Giao dịch chưa khớp</div>
        <div class="adm-kpi__num <?= (int)$kpi['unmatched'] > 0 ? 'adm-kpi__num--red' : '' ?>"><?= (int)$kpi['unmatched'] ?></div>
    </div>
    <div class="adm-kpi">
        <div class="adm-kpi__label">Khoản thu vượt cần xử lý</div>
        <div class="adm-kpi__num <?= (int)$kpi['overpaid'] > 0 ? 'adm-kpi__num--red' : '' ?>"><?= (int)$kpi['overpaid'] ?></div>
        <div class="adm-kpi__sub">QR đang hoạt động: <?= (int)$kpi['active_qr'] ?></div>
    </div>
</div>

<div class="adm-toolbar" style="margin-bottom:14px;">
    <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="<?= h($tab) ?>" />
        <div class="adm-toolbar__search" style="flex:1;min-width:240px;">
            <img class="si" src="../img/icon-search.svg" alt="" />
            <input type="search" name="q" value="<?= h($q) ?>"
                   placeholder="MSSV, họ tên, học kỳ, mã tham chiếu giao dịch…" />
        </div>
        <?php if ($tab === 'transactions'): ?>
        <select name="tx_status">
            <option value="">Mọi trạng thái</option>
            <?php foreach (['Processed'=>'Đã xử lý','Unmatched'=>'Chưa khớp','Ignored'=>'Bỏ qua','Rejected'=>'Từ chối','Duplicate'=>'Trùng lặp'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= $txStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="abtn abtn--sm">🔍 Tìm kiếm</button>
        <?php if ($q !== ''): ?>
        <a class="abtn abtn--sm abtn--ghost" href="<?= h($tabUrl($tab)) ?>">Xóa lọc</a>
        <?php endif; ?>
    </form>
    <div class="adm-toolbar__right">
        <a class="abtn abtn--sm abtn--ghost"
           href="?export=<?= $tab === 'invoices' ? 'invoices' : 'transactions' ?>&amp;q=<?= urlencode($q) ?>">⬇️ Xuất CSV</a>
    </div>
</div>

<div class="adm-tabs" style="margin-bottom:16px;">
    <a class="adm-tab <?= $tab === 'invoices' ? 'active' : '' ?>" href="<?= h($tabUrl('invoices')) ?>">🧾 Hóa đơn</a>
    <a class="adm-tab <?= $tab === 'transactions' ? 'active' : '' ?>" href="<?= h($tabUrl('transactions')) ?>">🏦 Giao dịch ngân hàng</a>
    <a class="adm-tab <?= $tab === 'unmatched' ? 'active' : '' ?>" href="<?= h($tabUrl('unmatched')) ?>">
        ⚠️ Chưa khớp <?= (int)$kpi['unmatched'] > 0 ? '(' . (int)$kpi['unmatched'] . ')' : '' ?>
    </a>
    <a class="adm-tab <?= $tab === 'overpaid' ? 'active' : '' ?>" href="<?= h($tabUrl('overpaid')) ?>">
        💸 Thu vượt <?= (int)$kpi['overpaid'] > 0 ? '(' . (int)$kpi['overpaid'] . ')' : '' ?>
    </a>
    <a class="adm-tab <?= $tab === 'setup' ? 'active' : '' ?>" href="<?= h($tabUrl('setup')) ?>">⚙️ Cấu hình</a>
</div>

<?php if ($tab === 'invoices'): ?>
<div class="adm-table-wrap">
    <table class="adm-table">
        <thead><tr>
            <th>Hóa đơn</th><th>Sinh viên</th><th>Học kỳ</th><th>Tổng</th>
            <th>Đã trả</th><th>Còn lại</th><th>Trạng thái</th><th>Hạn</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($invoices as $r):
            $amount = (int)$r['amount_vnd'];
            $conf   = min((int)$r['confirmed'], $amount);
            if ($r['status'] === 'Paid') $conf = $amount;
            $out    = max(0, $amount - $conf);
            $tid    = (int)$r['tuition_id'];
            $st = match (true) {
                $r['status'] === 'Waived'                 => 'Waived',
                $out === 0                                => 'Paid',
                !empty($unmatchedByInvoice[$tid])         => 'Review',
                $conf > 0                                 => 'Partial',
                !empty($activeQr[$tid])                   => 'Awaiting',
                default                                   => 'Unpaid',
            };
            [$sl, $sb, $sc] = $statusMeta[$st];
        ?>
        <tr>
            <td>#<?= $tid ?></td>
            <td><strong><?= h($r['student_id']) ?></strong><br>
                <span style="font-size:11px;color:#64748b;"><?= h($r['full_name']) ?> • <?= h($r['class_code']) ?></span></td>
            <td><?= h($r['semester_name']) ?><br>
                <span style="font-size:11px;color:#64748b;"><?= h($r['academic_year']) ?></span></td>
            <td><?= vnd($amount) ?></td>
            <td style="color:#059669;"><?= vnd($conf) ?></td>
            <td style="color:<?= $out > 0 ? '#ef4444' : '#059669' ?>;"><?= vnd($out) ?></td>
            <td><span class="adm-result-badge" style="background:<?= $sb ?>;color:<?= $sc ?>;"><?= $sl ?></span></td>
            <td><?= $r['due_date'] ? date('d/m/Y', strtotime($r['due_date'])) : '—' ?></td>
            <td><a class="abtn abtn--sm abtn--ghost"
                   href="?tab=student&amp;student=<?= urlencode($r['student_id']) ?>">Chi tiết</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$invoices): ?>
        <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:28px;">Không có hóa đơn phù hợp.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($tab === 'transactions' || $tab === 'unmatched'): ?>
<?php $rows = $tab === 'unmatched' ? $unmatched : $transactions; ?>
<div class="adm-table-wrap">
    <table class="adm-table">
        <thead><tr>
            <th>ID / Provider</th><th>Thời gian</th><th>Số tiền</th><th>Nội dung / Tham chiếu</th>
            <th>Sinh viên / Hóa đơn</th><th>Tài khoản nhận</th><th>Trạng thái</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $t):
            [$tsl, $tsb, $tsc] = $txStatusMeta[$t['status']] ?? ['?', '#f1f5f9', '#475569'];
        ?>
        <tr>
            <td><strong>#<?= (int)$t['bank_transaction_id'] ?></strong><br>
                <span style="font-size:11px;color:#64748b;"><?= h($t['provider']) ?> • <?= h($t['provider_transaction_id']) ?></span>
                <?php if ((int)$t['is_test']): ?><span class="adm-result-badge adm-result-badge--warn">TEST</span><?php endif; ?>
            </td>
            <td style="font-size:12px;"><?= $t['transaction_date'] ? date('d/m/Y H:i', strtotime($t['transaction_date'])) : '—' ?><br>
                <span style="font-size:10px;color:#94a3b8;">Nhận: <?= date('d/m/Y H:i', strtotime($t['received_at'])) ?></span></td>
            <td style="font-weight:700;color:<?= $t['transfer_type'] === 'in' ? '#059669' : '#b91c1c' ?>;">
                <?= ($t['transfer_type'] === 'in' ? '+' : ($t['transfer_type'] === 'out' ? '−' : '')) ?><?= vnd((int)$t['transfer_amount']) ?>
                <div style="font-size:10px;color:#94a3b8;font-weight:400;"><?= h($t['transfer_type']) ?></div>
            </td>
            <td style="font-size:12px;max-width:260px;"><?= h($t['content'] ?: $t['description']) ?><br>
                <code style="font-size:11px;background:#f1f5f9;padding:1px 5px;border-radius:4px;"><?= h($t['reference'] ?: ($t['code'] ?: '—')) ?></code></td>
            <td style="font-size:12px;">
                <?php if ($t['student_id']): ?>
                <strong><?= h($t['student_id']) ?></strong> <?= h($t['full_name'] ?? '') ?><br>
                <a href="?tab=student&amp;student=<?= urlencode($t['student_id']) ?>" style="font-size:11px;color:#3b82f6;">Hóa đơn #<?= (int)$t['tuition_id'] ?></a>
                <?php else: ?><span style="color:#b45309;">Chưa xác định</span><?php endif; ?>
            </td>
            <td style="font-size:11px;"><?= h($t['account_number'] ?: '—') ?><?= $t['sub_account'] ? ' / ' . h($t['sub_account']) : '' ?></td>
            <td><span class="adm-result-badge" style="background:<?= $tsb ?>;color:<?= $tsc ?>;"><?= $tsl ?></span>
                <?php if ($t['match_note']): ?><div style="font-size:10px;color:#94a3b8;margin-top:3px;"><?= h($t['match_note']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:11px;">
                <?php if ($t['payment_id']): ?>Payment #<?= (int)$t['payment_id'] ?><?php endif; ?>
                <?php if ($t['status'] === 'Unmatched'): ?>
                <button type="button" class="abtn abtn--sm abtn--primary js-resolve"
                        data-tx="<?= (int)$t['bank_transaction_id'] ?>"
                        data-amount="<?= (int)$t['transfer_amount'] ?>"
                        data-label="#<?= (int)$t['bank_transaction_id'] ?> – <?= h($t['provider_transaction_id']) ?>">Xử lý</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px;">
            <?= $tab === 'unmatched' ? 'Không có giao dịch chưa khớp. 🎉' : 'Không có giao dịch phù hợp.' ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($tab === 'unmatched' && $unmatched): ?>
<datalist id="openInvoices">
    <?php foreach ($openInvoices as $oi):
        $remain = max(0, (int)$oi['amount_vnd'] - (int)$oi['confirmed']);
    ?>
    <option value="<?= (int)$oi['tuition_id'] ?>">
        #<?= (int)$oi['tuition_id'] ?> – <?= h($oi['student_id']) ?> <?= h($oi['full_name']) ?> –
        HK <?= h($oi['semester_name']) ?> <?= h($oi['academic_year']) ?> – còn <?= vnd($remain) ?>
    </option>
    <?php endforeach; ?>
</datalist>

<dialog id="resolveDialog" style="border:none;border-radius:12px;padding:0;max-width:460px;width:92%;box-shadow:0 20px 60px rgba(2,25,54,.3);">
    <form method="POST" style="padding:18px 20px;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="resolve_tx" />
        <input type="hidden" name="bank_tx_id" id="resolveTxId" value="" />
        <div style="font-size:15px;font-weight:700;color:#001634;margin-bottom:4px;">Gán giao dịch vào hóa đơn</div>
        <div id="resolveLabel" style="font-size:12px;color:#64748b;margin-bottom:14px;"></div>
        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã hóa đơn (tuition_id)</label>
        <input type="number" name="tuition_id" id="resolveTuition" list="openInvoices" required min="1"
               style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;" />
        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin:10px 0 4px;">Ghi chú xác minh *</label>
        <input type="text" name="note" required placeholder="VD: Đã đối chiếu sao kê ngày…"
               style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;" />
        <div style="margin-top:14px;display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="abtn abtn--sm abtn--ghost" onclick="document.getElementById('resolveDialog').close()">Hủy</button>
            <button type="submit" class="abtn abtn--sm abtn--primary">Xác nhận gán</button>
        </div>
    </form>
</dialog>

<script>
document.querySelectorAll('.js-resolve').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('resolveTxId').value = btn.dataset.tx;
        document.getElementById('resolveLabel').textContent =
            'Giao dịch ' + btn.dataset.label + ' – ' + Number(btn.dataset.amount).toLocaleString('vi-VN') + ' ₫';
        document.getElementById('resolveTuition').value = '';
        document.getElementById('resolveDialog').showModal();
    });
});
</script>
<?php endif; ?>

<?php elseif ($tab === 'overpaid'): ?>
<div class="adm-table-wrap">
    <table class="adm-table">
        <thead><tr>
            <th>Payment</th><th>Sinh viên</th><th>Hóa đơn</th><th>Đã nhận</th>
            <th>Thừa</th><th>Tham chiếu</th><th>Ngày</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($overpaid as $p): ?>
        <tr>
            <td>#<?= (int)$p['payment_id'] ?><?= (int)$p['is_test'] ? ' <span class="adm-result-badge adm-result-badge--warn">TEST</span>' : '' ?></td>
            <td><strong><?= h($p['student_id']) ?></strong><br><span style="font-size:11px;color:#64748b;"><?= h($p['full_name']) ?></span></td>
            <td>#<?= (int)$p['tuition_id'] ?><br><span style="font-size:11px;color:#64748b;">HK <?= h($p['semester_name']) ?> <?= h($p['academic_year']) ?></span></td>
            <td><?= vnd((int)$p['amount_vnd']) ?></td>
            <td style="color:#b45309;font-weight:700;"><?= vnd((int)$p['overpaid_vnd']) ?></td>
            <td><code style="font-size:11px;"><?= h($p['reference'] ?: ($p['bank_reference'] ?: '—')) ?></code></td>
            <td><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
            <td>
                <form method="POST" style="display:flex;gap:6px;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="resolve_overpay" />
                    <input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>" />
                    <input type="text" name="note" required placeholder="Cách xử lý…"
                           style="height:30px;border:1px solid #e2e8f0;border-radius:6px;padding:0 8px;font-size:11px;width:150px;" />
                    <button type="submit" class="abtn abtn--sm abtn--green">Đã xử lý</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$overpaid): ?>
        <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px;">Không có khoản thu vượt nào cần xử lý.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($tab === 'setup'): ?>
<div class="card" style="padding:22px 24px;background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(2,25,54,.06);">
    <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:12px;">Cấu hình thanh toán tự động</div>
    <table class="adm-table">
        <tbody>
            <tr><td style="width:220px;color:#64748b;">Nhà cung cấp</td><td><strong><?= h($cfg['provider']) ?></strong> (<?= h($cfg['gateway']) ?>)</td></tr>
            <tr><td style="color:#64748b;">Ngân hàng nhận tiền</td><td><?= h($cfg['bank_code']) ?> • BIN <?= h($cfg['bank_bin']) ?> • <?= h($cfg['account_number']) ?> • <?= h($cfg['account_holder']) ?></td></tr>
            <tr><td style="color:#64748b;">Webhook endpoint</td><td><code><?= h(app_absolute_url('api/payment_webhook.php')) ?></code></td></tr>
            <tr><td style="color:#64748b;">Live credentials</td>
                <td><?= $cfg['api_key'] !== '' ? '✅ SEPAY_API_KEY đã nạp' : '❌ Thiếu SEPAY_API_KEY'; ?>
                    • <?= $cfg['webhook_secret'] !== '' ? '✅ SEPAY_WEBHOOK_SECRET đã nạp' : '❌ Thiếu SEPAY_WEBHOOK_SECRET'; ?></td></tr>
            <tr><td style="color:#64748b;">Xác thực webhook</td>
                <td><?= $cfg['webhook_enabled'] ? 'Bật' : 'Tắt' ?> • chế độ <strong><?= h($cfg['auth_mode']) ?></strong></td></tr>
            <tr><td style="color:#64748b;">Webhook sẵn sàng</td>
                <td><?= $cfg['webhook_ready'] ? '✅ Có' : '❌ Chưa' ?> (live: <?= $cfg['live_ready'] ? 'sẵn sàng' : 'chưa' ?>, test: <?= $cfg['test_ready'] ? 'sẵn sàng' : 'chưa' ?>)</td></tr>
            <tr><td style="color:#64748b;">Thời hạn QR / thu một phần / thu vượt</td>
                <td><?= (int)$cfg['ttl_minutes'] ?> phút • <?= $cfg['partial_allowed'] ? 'cho phép' : 'không cho phép' ?> • chính sách <strong><?= h($cfg['overpay_policy']) ?></strong></td></tr>
        </tbody>
    </table>
    <?php if ($cfg['issues']): ?>
    <div style="margin-top:14px;padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#854d0e;">
        <strong>Cần hoàn tất:</strong>
        <ul style="margin:6px 0 0 18px;padding:0;">
            <?php foreach ($cfg['issues'] as $issue): ?><li><?= h($issue) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <div style="margin-top:14px;padding:12px 14px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b;">
        Giao dịch TEST được tạo qua <code>database/test_payment_webhook.php</code> và luôn được gắn nhãn <strong>TEST</strong>.
        Chỉ giao dịch webhook thật từ ngân hàng/nhà cung cấp mới có thể xác nhận thanh toán thật.
    </div>
</div>

<?php endif; ?>

<?php if ($studentView): ?>
<?php $sv = $studentView; ?>
<div class="card" style="margin-top:18px;padding:22px 24px;background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(2,25,54,.06);">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
        <div>
            <div style="font-size:16px;font-weight:700;color:#001634;">Lịch sử thanh toán – <?= h($sv['student_id']) ?> <?= h($sv['full_name']) ?></div>
            <div style="font-size:12px;color:#64748b;">Lớp <?= h($sv['class_code']) ?> • <?= h($sv['email']) ?></div>
        </div>
        <a class="abtn abtn--sm abtn--ghost" href="<?= h($tabUrl('invoices')) ?>">Đóng</a>
    </div>

    <div class="adm-section-title" style="margin-bottom:6px;">Hóa đơn</div>
    <div class="adm-table-wrap" style="margin-bottom:16px;">
        <table class="adm-table">
            <thead><tr><th>#</th><th>Học kỳ</th><th>Tổng</th><th>Đã trả</th><th>Còn lại</th><th>Trạng thái</th></tr></thead>
            <tbody>
            <?php foreach ($sv['invoices'] as $ti):
                $a = (int)$ti['amount_vnd']; $c = min((int)$ti['confirmed'], $a);
                if ($ti['status'] === 'Paid') $c = $a;
            ?>
            <tr>
                <td><?= (int)$ti['tuition_id'] ?></td>
                <td><?= h($ti['semester_name']) ?> <?= h($ti['academic_year']) ?></td>
                <td><?= vnd($a) ?></td>
                <td style="color:#059669;"><?= vnd($c) ?></td>
                <td style="color:<?= $a-$c>0?'#ef4444':'#059669' ?>;"><?= vnd(max(0,$a-$c)) ?></td>
                <td><?= h($ti['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="adm-section-title" style="margin-bottom:6px;">Các khoản đã ghi nhận</div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>Payment</th><th>Số tiền</th><th>Trạng thái</th><th>Provider / Tham chiếu</th><th>Thời gian</th><th>Ghi chú</th></tr></thead>
            <tbody>
            <?php foreach ($sv['payments'] as $p): ?>
            <tr>
                <td>#<?= (int)$p['payment_id'] ?><?= (int)$p['is_test'] ? ' <span class="adm-result-badge adm-result-badge--warn">TEST</span>' : '' ?></td>
                <td style="font-weight:700;"><?= vnd((int)$p['amount_vnd']) ?>
                    <?php if ((int)$p['overpaid_vnd'] > 0): ?><div style="font-size:10px;color:#b45309;">Thừa <?= vnd((int)$p['overpaid_vnd']) ?></div><?php endif; ?>
                </td>
                <td><?= h($p['status']) ?><?= $p['review_status'] !== 'None' ? ' / ' . h($p['review_status']) : '' ?></td>
                <td style="font-size:11px;"><?= h($p['provider'] ?: $p['method']) ?><br>
                    <code><?= h($p['reference'] ?: ($p['bank_reference'] ?: ($p['reference_code'] ?: ($p['reference_no'] ?: '—')))) ?></code></td>
                <td><?= date('d/m/Y H:i', strtotime($p['transaction_date'] ?: $p['created_at'])) ?></td>
                <td style="font-size:11px;color:#64748b;"><?= h($p['note'] ?: ($p['match_note'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$sv['payments']): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8;">Chưa có khoản thanh toán.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($sv['transactions']): ?>
    <div class="adm-section-title" style="margin:16px 0 6px;">Giao dịch ngân hàng</div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>#</th><th>Số tiền</th><th>Nội dung</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
            <tbody>
            <?php foreach ($sv['transactions'] as $t): ?>
            <tr>
                <td><?= (int)$t['bank_transaction_id'] ?><?= (int)$t['is_test'] ? ' <span class="adm-result-badge adm-result-badge--warn">TEST</span>' : '' ?></td>
                <td><?= vnd((int)$t['transfer_amount']) ?></td>
                <td style="font-size:11px;"><?= h($t['content']) ?><br><code><?= h($t['reference'] ?: '') ?></code></td>
                <td><?= h($t['status']) ?></td>
                <td><?= date('d/m/Y H:i', strtotime($t['received_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($viewSid !== ''): ?>
<div class="adm-flash adm-flash--error" style="margin-top:14px;">Không tìm thấy sinh viên <?= h($viewSid) ?>.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
