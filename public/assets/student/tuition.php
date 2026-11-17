<?php
/**
 * UniPortal – Học phí & Thanh toán (thanh toán bằng VietQR)
 * Mỗi hóa đơn có một mã QR riêng; hệ thống chỉ ghi nhận thanh toán
 * sau khi nhận webhook giao dịch đã được xác thực từ ngân hàng.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/PaymentService.php';
require_once $root . '/src/src/config/payment.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo        = get_db();
$student_id = $_SESSION['student_id'] ?? '';
$account_id = (int)($_SESSION['account_id'] ?? 0);
$cfg        = payment_config();

$flash = '';

// ── Regenerate QR (does not create a Payment record) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_qr'])) {
    csrf_verify();
    $tid = (int)($_POST['tuition_id'] ?? 0);
    $req = PaymentService::ensureRequest($pdo, $tid, $student_id, $account_id, true);
    if (!$req) {
        $flash = 'error:Không thể tạo mã QR cho hóa đơn này.';
    } else {
        header('Location: tuition.php?regenerated=' . (int)$tid);
        exit;
    }
}

// ── Load invoices ─────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT t.tuition_id
     FROM Tuition t JOIN Semester sem ON sem.semester_id = t.semester_id
     WHERE t.student_id = :sid
     ORDER BY sem.start_date DESC'
);
$stmt->execute([':sid' => $student_id]);
$tuitionRows = $stmt->fetchAll();

$summaries = [];
$totalAmount = $totalConfirmed = $totalOutstanding = 0;
foreach ($tuitionRows as $row) {
    $sum = PaymentService::summary($pdo, (int)$row['tuition_id'], $student_id, $account_id);
    if (!$sum) continue;
    $summaries[] = $sum;
    $totalAmount      += (int)$sum['amount'];
    $totalConfirmed   += (int)$sum['confirmed'];
    $totalOutstanding += (int)$sum['outstanding'];
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function vnd(int $n): string { return number_format($n, 0, ',', '.') . ' ₫'; }

$statusMeta = [
    'Unpaid'   => ['Chưa thanh toán',            '#fee2e2', '#b91c1c'],
    'Awaiting' => ['Chờ chuyển khoản',           '#fef9c3', '#854d0e'],
    'Partial'  => ['Thanh toán một phần',        '#fef9c3', '#854d0e'],
    'Paid'     => ['Đã thanh toán',              '#d1fae5', '#065f46'],
    'Waived'   => ['Miễn học phí',               '#dbeafe', '#1e40af'],
    'Review'   => ['Cần xác minh thanh toán',    '#ffedd5', '#9a3412'],
];
$paymentMeta = [
    'Pending'   => ['⏳ Chờ duyệt',      '#fef9c3', '#854d0e'],
    'Confirmed' => ['✅ Đã xác nhận',    '#d1fae5', '#065f46'],
    'Rejected'  => ['❌ Từ chối',        '#fee2e2', '#b91c1c'],
];
$methodLabel = ['BankTransfer' => 'Chuyển khoản', 'Cash' => 'Tiền mặt', 'Online' => 'Thanh toán online'];

$pageTitle  = 'Học phí & Thanh toán';
$activePage = 'tuition';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<?php if (isset($_GET['regenerated'])): ?>
<div class="pay-flash pay-flash--ok">🔄 Đã tạo mã QR mới cho hóa đơn. Mã QR cũ đã được vô hiệu hóa.</div>
<?php endif; ?>
<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div class="pay-flash pay-flash--<?= $ft === 'success' ? 'ok' : 'err' ?>"><?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?></div>
<?php endif; ?>

<div class="stat-row">
    <div class="stat-mini">
        <div class="stat-mini__label">Còn phải thanh toán</div>
        <div class="stat-mini__value" style="color:#ef4444;"><?= vnd($totalOutstanding) ?></div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Đã thanh toán</div>
        <div class="stat-mini__value" style="color:#059669;"><?= vnd($totalConfirmed) ?></div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Tổng học phí</div>
        <div class="stat-mini__value"><?= vnd($totalAmount) ?></div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Số hóa đơn</div>
        <div class="stat-mini__value"><?= count($summaries) ?></div>
    </div>
</div>

<?php if (empty($summaries)): ?>
<div class="card" style="padding:40px;text-align:center;color:#94a3b8;">
    <div style="font-size:32px;margin-bottom:8px;">💰</div>
    <p>Chưa có thông tin học phí. Vui lòng liên hệ Phòng Kế hoạch Tài chính.</p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:18px;">
<?php foreach ($summaries as $sum):
    $t      = $sum['tuition'];
    $tid    = (int)$t['tuition_id'];
    $req    = $sum['request'];
    $status = $sum['display_status'];
    [$stLabel, $stBg, $stColor] = $statusMeta[$status] ?? ['Không rõ', '#f1f5f9', '#64748b'];
    $isPaid  = in_array($status, ['Paid', 'Waived'], true);
    $expired = $req && strtotime($req['expires_at']) < time();
?>
<div class="card pay-invoice" id="invoice-<?= $tid ?>"
     data-tuition="<?= $tid ?>"
     data-status="<?= h($status) ?>"
     data-outstanding="<?= (int)$sum['outstanding'] ?>">
    <!-- Header -->
    <div class="pay-invoice__head">
        <div>
            <div class="pay-invoice__title">
                <?= h($t['semester_name']) ?> <?= h($t['academic_year']) ?>
            </div>
            <div class="pay-invoice__meta">
                Mã hóa đơn: #<?= $tid ?>
                <?php if ($t['due_date']): ?>
                    • Hạn thanh toán: <?= date('d/m/Y', strtotime($t['due_date'])) ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div class="pay-invoice__amount"><?= vnd((int)$sum['amount']) ?></div>
            <span class="pay-pill js-status" style="background:<?= $stBg ?>;color:<?= $stColor ?>;"><?= $stLabel ?></span>
        </div>
    </div>

    <!-- Balance breakdown -->
    <div class="pay-balances">
        <div><span>Tổng học phí</span><strong><?= vnd((int)$sum['amount']) ?></strong></div>
        <div><span>Đã thanh toán</span><strong class="js-confirmed" style="color:#059669;"><?= vnd((int)$sum['confirmed']) ?></strong></div>
        <div><span>Còn lại</span><strong class="js-outstanding" style="color:<?= $sum['outstanding'] > 0 ? '#ef4444' : '#059669' ?>;"><?= vnd((int)$sum['outstanding']) ?></strong></div>
        <div><span>Hạn thanh toán</span><strong><?= $t['due_date'] ? date('d/m/Y', strtotime($t['due_date'])) : '—' ?></strong></div>
    </div>

    <?php if ($status === 'Review'): ?>
    <div class="pay-note pay-note--warn">
        🕵️ Có giao dịch chuyển khoản cần xác minh cho hóa đơn này. Phòng Kế hoạch Tài chính đang kiểm tra và sẽ cập nhật sớm.
    </div>
    <?php elseif ($isPaid): ?>
    <div class="pay-note pay-note--ok">
        ✅ Học phí đã được thanh toán đầy đủ. Không cần thao tác thêm.
    </div>
    <?php elseif ($req): ?>
    <!-- QR payment -->
    <div class="pay-qr">
        <div class="pay-qr__code">
            <img src="../../payment_qr.php?rid=<?= (int)$req['payment_request_id'] ?>&amp;v=<?= (int)strtotime($req['expires_at']) ?>"
                 alt="Mã QR thanh toán học phí" width="240" height="240" />
            <div class="pay-qr__hint">Mở ứng dụng ngân hàng, quét mã QR này và xác nhận chuyển khoản.</div>
        </div>
        <div class="pay-qr__info">
            <div class="pay-qr__bank">
                <span class="pay-bank-badge"><?= h($cfg['bank_code']) ?></span>
                <span><?= h($cfg['bank_name']) ?></span>
            </div>
            <dl class="pay-qr__list">
                <div><dt>Chủ tài khoản</dt><dd><?= h($req['account_holder']) ?></dd></div>
                <div><dt>Số tài khoản</dt><dd><?= h($req['account_number']) ?>
                    <button type="button" class="pay-copy" data-copy="<?= h($req['account_number']) ?>">Sao chép</button></dd></div>
                <div><dt>Số tiền</dt><dd class="js-qr-amount" style="color:#ef4444;font-weight:800;"><?= vnd((int)$req['amount_vnd']) ?></dd></div>
                <div><dt>Nội dung chuyển khoản</dt><dd><code class="js-reference"><?= h($req['reference']) ?></code>
                    <button type="button" class="pay-copy" data-copy="<?= h($req['reference']) ?>">Sao chép</button></dd></div>
                <div><dt>Mã QR hết hạn</dt><dd class="js-expiry"><?= date('d/m/Y H:i', strtotime($req['expires_at'])) ?></dd></div>
            </dl>
            <div class="pay-qr__warn">
                <?php if ($expired): ?>
                ⚠️ Mã QR đã hết hạn – vui lòng tạo mã mới.
                <?php else: ?>
                ⚠️ Vui lòng chuyển <strong>đúng số tiền</strong> và <strong>đúng nội dung</strong> để hệ thống tự động xác nhận.
                <?php endif; ?>
            </div>
            <form method="POST" style="margin-top:10px;">
                <?= csrf_input() ?>
                <input type="hidden" name="regenerate_qr" value="1" />
                <input type="hidden" name="tuition_id" value="<?= $tid ?>" />
                <button type="submit" class="pay-btn-ghost">🔄 Tạo lại mã QR</button>
            </form>
        </div>
    </div>
    <div class="pay-status-line">
        <span class="pay-live-dot"></span>
        <span class="js-live-text">Trạng thái tự động cập nhật sau khi ngân hàng xác nhận giao dịch.</span>
    </div>
    <?php else: ?>
    <div class="pay-note pay-note--warn">
        ⚠️ Chưa tạo được mã QR thanh toán. Vui lòng thử lại sau hoặc liên hệ Phòng Kế hoạch Tài chính.
    </div>
    <?php endif; ?>

    <!-- Verified / legacy payment history -->
    <?php if (!empty($sum['payments'])): ?>
    <div class="pay-history">
        <div class="pay-history__title">Lịch sử giao dịch</div>
        <?php foreach ($sum['payments'] as $p):
            [$pLabel, $pBg, $pColor] = $paymentMeta[$p['status']] ?? ['Không rõ', '#f1f5f9', '#64748b'];
        ?>
        <div class="pay-history__row">
            <div>
                <div class="pay-history__amt"><?= vnd((int)$p['amount_vnd']) ?>
                    <?php if (!empty($p['provider'])): ?>
                    <span class="pay-badge"><?= h($p['provider']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($p['is_test'])): ?>
                    <span class="pay-badge pay-badge--test">TEST</span>
                    <?php endif; ?>
                </div>
                <div class="pay-history__meta">
                    <?= h($methodLabel[$p['method']] ?? $p['method']) ?>
                    <?php if (!empty($p['bank_reference']) || !empty($p['reference_code'])): ?>
                        • Mã GD: <?= h($p['bank_reference'] ?: $p['reference_code']) ?>
                    <?php elseif (!empty($p['reference_no'])): ?>
                        • Mã GD: <?= h($p['reference_no']) ?>
                    <?php endif; ?>
                    • <?= date('d/m/Y H:i', strtotime($p['transaction_date'] ?: $p['created_at'])) ?>
                    <?php if (!empty($p['overpaid_vnd']) && (int)$p['overpaid_vnd'] > 0): ?>
                        • <span style="color:#b45309;">Thừa <?= vnd((int)$p['overpaid_vnd']) ?> – đang xử lý</span>
                    <?php endif; ?>
                </div>
            </div>
            <span class="pay-pill" style="background:<?= $pBg ?>;color:<?= $pColor ?>;"><?= $pLabel ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="pay-support">
    <strong>Lưu ý:</strong> Hệ thống chỉ ghi nhận thanh toán sau khi ngân hàng xác nhận giao dịch thực tế.
    Liên hệ Phòng Kế hoạch Tài chính:
    <a href="mailto:taichinhketoan@vnu.edu.vn">taichinhketoan@vnu.edu.vn</a> • ĐT: 024 3557 5993
</div>

<style>
.pay-flash{padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:14px;}
.pay-flash--ok{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;}
.pay-flash--err{background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;}
.pay-invoice{padding:20px 24px;}
.pay-invoice__head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.pay-invoice__title{font-size:16px;font-weight:700;color:#001634;}
.pay-invoice__meta{font-size:12px;color:#64748b;margin-top:3px;}
.pay-invoice__amount{font-size:22px;font-weight:800;color:#001634;}
.pay-pill{display:inline-block;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;margin-top:4px;}
.pay-balances{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;background:#f8fafc;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;}
.pay-balances span{display:block;color:#64748b;margin-bottom:2px;}
.pay-balances strong{color:#001634;font-size:13px;}
.pay-note{padding:12px 14px;border-radius:8px;font-size:12.5px;margin-bottom:12px;}
.pay-note--warn{background:#fffbeb;border:1px solid #fde68a;color:#854d0e;}
.pay-note--ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;}
.pay-qr{display:grid;grid-template-columns:260px 1fr;gap:20px;align-items:start;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;}
.pay-qr__code{text-align:center;}
.pay-qr__code img{width:240px;height:240px;background:#fff;border-radius:8px;padding:6px;box-shadow:0 2px 10px rgba(2,25,54,.08);}
.pay-qr__hint{font-size:11.5px;color:#64748b;margin-top:8px;line-height:1.5;}
.pay-qr__bank{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#001634;margin-bottom:10px;}
.pay-bank-badge{background:#102b50;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:700;letter-spacing:.4px;}
.pay-qr__list{margin:0;font-size:13px;}
.pay-qr__list > div{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed #e2e8f0;}
.pay-qr__list dt{color:#64748b;font-size:12px;}
.pay-qr__list dd{margin:0;color:#001634;font-weight:600;text-align:right;}
.pay-qr__list code{background:#eef2f7;padding:2px 6px;border-radius:4px;font-size:12.5px;}
.pay-copy{border:none;background:#e2e8f0;color:#334155;border-radius:5px;font-size:10px;padding:2px 6px;margin-left:6px;cursor:pointer;}
.pay-qr__warn{font-size:11.5px;color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;border-radius:7px;padding:8px 10px;margin-top:10px;}
.pay-btn-ghost{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;}
.pay-status-line{display:flex;align-items:center;gap:8px;font-size:11.5px;color:#64748b;margin-top:10px;}
.pay-live-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18);}
.pay-history{margin-top:14px;}
.pay-history__title{font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;}
.pay-history__row{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 12px;background:#f8fafc;border-radius:8px;margin-bottom:6px;}
.pay-history__amt{font-size:13px;font-weight:700;color:#001634;}
.pay-history__meta{font-size:11px;color:#64748b;margin-top:2px;}
.pay-badge{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:4px;background:#e0f2fe;color:#0369a1;font-size:9.5px;font-weight:700;vertical-align:middle;}
.pay-badge--test{background:#fef3c7;color:#92400e;}
.pay-support{margin-top:16px;padding:14px 16px;background:#f8fafc;border-radius:10px;font-size:12px;color:#64748b;}
.pay-support a{color:#3b82f6;}
@media (max-width:760px){.pay-qr{grid-template-columns:1fr;}.pay-qr__code img{width:200px;height:200px;}}
</style>

<script>
document.querySelectorAll('.pay-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy') || '';
        var done = function () { var o = btn.textContent; btn.textContent = 'Đã chép'; setTimeout(function () { btn.textContent = o; }, 1400); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(function () {});
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            document.body.removeChild(ta);
        }
    });
});
</script>
<script src="../../assets/js/payment-poll.js"></script>

</div></main></div>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
