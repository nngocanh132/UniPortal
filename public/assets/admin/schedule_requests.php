<?php
/**
 * UniPortal – Đề xuất thay đổi lịch học (Admin)
 * Lecturers propose cancellation / make-up sessions; Admin approves or rejects.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/TeachingSchedule.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: ' . app_url('assets/student/dashboard.php')); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: ' . app_url('assets/lecturer/dashboard.php')); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo = get_db();
$accountId = (int)($_SESSION['account_id'] ?? 0);

if (isset($_SESSION['admin_req_flash'])) {
    $flash = $_SESSION['admin_req_flash'];
    unset($_SESSION['admin_req_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'review_request') {
    csrf_verify();
    $res = TeachingSchedule::reviewRequest(
        (int)($_POST['request_id'] ?? 0),
        $accountId,
        $_POST['decision'] ?? '',
        $_POST['admin_note'] ?? ''
    );
    $_SESSION['admin_req_flash'] = ($res['ok'] ? 'success' : 'error') . ':' . $res['message'];
    $qs = http_build_query(array_filter([
        'status' => $_POST['return_status'] ?? '',
        'type'   => $_POST['return_type'] ?? '',
        'sem'    => $_POST['return_sem'] ?? '',
    ], fn($v) => $v !== ''));
    header('Location: schedule_requests.php' . ($qs ? '?' . $qs : ''));
    exit;
}

$semesters = TeachingSchedule::getSemesters();
$activeSem = null;
foreach ($semesters as $sem) { if ($sem['status'] === 'Active') { $activeSem = $sem; break; } }

$filterStatus = in_array($_GET['status'] ?? '', ['Pending','Approved','Rejected'], true) ? $_GET['status'] : '';
$filterType   = in_array($_GET['type'] ?? '', ['Cancellation','Makeup'], true) ? $_GET['type'] : '';
$filterSem    = (int)($_GET['sem'] ?? 0);

$requests = TeachingSchedule::getRequests([
    'status' => $filterStatus,
    'type' => $filterType,
    'semester_id' => $filterSem,
]);

$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM ScheduleChangeRequest GROUP BY status')->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['n'];
}

$pageTitle  = 'Đề xuất thay đổi lịch học';
$activePage = 'schedule_requests';
$breadcrumb = 'Quản trị › Đào tạo & Lịch học';
$warnCount  = 0;
require __DIR__ . '/_layout.php';

$statusMeta = [
    'Pending'  => ['Chờ duyệt', 'apill--orange'],
    'Approved' => ['Đã duyệt',  'apill--green'],
    'Rejected' => ['Từ chối',   'apill--red'],
];
$typeMeta = [
    'Cancellation' => ['Nghỉ buổi học', '#b91c1c', '#fee2e2'],
    'Makeup'       => ['Học bù',        '#1d4ed8', '#dbeafe'],
];
?>

<?php if (isset($flash)): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft === 'success' ? '#d1fae5' : '#fee2e2' ?>;
            border:1px solid <?= $ft === 'success' ? '#6ee7b7' : '#fca5a5' ?>;
            color:<?= $ft === 'success' ? '#065f46' : '#b91c1c' ?>;">
    <?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:flex-end;flex-wrap:wrap;gap:12px;">
    <a href="schedule.php?sem=<?= (int)($activeSem['semester_id'] ?? 0) ?>" class="abtn abtn--outline">📅 Lịch giảng dạy</a>
</div>

<div class="adm-kpi-row">
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Chờ duyệt</span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num" style="color:#b45309;"><?= $counts['Pending'] ?></span></div>
        <div class="adm-kpi__sub">Cần Admin xử lý</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Đã duyệt</span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num" style="color:#166534;"><?= $counts['Approved'] ?></span></div>
        <div class="adm-kpi__sub">Lịch đã được cập nhật</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Từ chối</span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num" style="color:#b91c1c;"><?= $counts['Rejected'] ?></span></div>
        <div class="adm-kpi__sub">Lịch gốc giữ nguyên</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Đang hiển thị</span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= count($requests) ?></span></div>
        <div class="adm-kpi__sub">Theo bộ lọc</div>
    </div>
</div>

<!-- FILTERS -->
<div class="acard" style="padding:16px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái</label>
            <select name="status" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:150px;">
                <option value="">Tất cả</option>
                <?php foreach ($statusMeta as $k => $m): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= h($m[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Loại đề xuất</label>
            <select name="type" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:160px;">
                <option value="">Tất cả</option>
                <?php foreach ($typeMeta as $k => $m): ?>
                <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= h($m[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Học kỳ</label>
            <select name="sem" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:190px;">
                <option value="">Tất cả học kỳ</option>
                <?php foreach ($semesters as $sem): ?>
                <option value="<?= (int)$sem['semester_id'] ?>" <?= $filterSem === (int)$sem['semester_id'] ? 'selected' : '' ?>>
                    <?= h($sem['semester_name'] . ' – ' . $sem['academic_year']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lọc</button>
        <a href="schedule_requests.php" class="abtn abtn--outline">Đặt lại</a>
    </form>
</div>

<!-- REQUESTS -->
<div class="acard" style="padding:0;overflow:hidden;">
    <div style="padding:14px 16px;border-bottom:1px solid #f1f5f9;font-size:14px;font-weight:700;color:#0d1b2e;">
        Danh sách đề xuất <span style="font-size:12px;font-weight:400;color:#64748b;">(<?= count($requests) ?> bản ghi)</span>
    </div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>GIẢNG VIÊN</th>
                    <th>MÔN HỌC</th>
                    <th>LỚP</th>
                    <th>BUỔI GỐC</th>
                    <th>LOẠI</th>
                    <th>ĐỀ XUẤT</th>
                    <th>LÝ DO</th>
                    <th>GỬI LÚC</th>
                    <th>TRẠNG THÁI</th>
                    <th>GHI CHÚ ADMIN</th>
                    <th>THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="11" style="text-align:center;padding:36px;color:#94a3b8;font-style:italic;">Không có đề xuất phù hợp.</td></tr>
            <?php else: foreach ($requests as $r):
                $sm = $statusMeta[$r['status']] ?? ['?', 'apill--gray'];
                $tm = $typeMeta[$r['request_type']] ?? ['?', '#475569', '#e2e8f0'];
                $isPending = $r['status'] === 'Pending';
            ?>
                <tr>
                    <td>
                        <div style="font-weight:700;color:#0d1b2e;"><?= h($r['lecturer_name']) ?></div>
                        <div style="font-size:11px;color:#64748b;">#<?= (int)$r['request_id'] ?></div>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= h($r['course_code']) ?></div>
                        <div style="font-size:11px;color:#64748b;"><?= h($r['course_name']) ?></div>
                    </td>
                    <td style="font-weight:600;"><?= h($r['section_code']) ?></td>
                    <td style="font-size:12px;white-space:nowrap;">
                        <div style="font-weight:700;"><?= date('d/m/Y', strtotime($r['original_date'])) ?></div>
                        <div style="color:#64748b;"><?= substr($r['original_start'], 0, 5) ?>–<?= substr($r['original_end'], 0, 5) ?></div>
                        <?php if ($r['original_status'] === 'Cancelled'): ?>
                        <span class="apill apill--red" style="font-size:10px;">Đã hủy</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="apill" style="background:<?= $tm[2] ?>;color:<?= $tm[1] ?>;font-size:10px;"><?= h($tm[0]) ?></span>
                    </td>
                    <td style="font-size:12px;">
                        <?php if ($r['request_type'] === 'Makeup'): ?>
                            <div style="font-weight:700;color:#1d4ed8;"><?= date('d/m/Y', strtotime($r['proposed_date'])) ?></div>
                            <div style="color:#64748b;"><?= substr($r['proposed_start_time'], 0, 5) ?>–<?= substr($r['proposed_end_time'], 0, 5) ?></div>
                            <div style="color:#64748b;">📍 <?= h($r['proposed_room'] ?: '—') ?></div>
                            <?php if ($r['created_session_id']): ?>
                            <div style="font-size:10px;color:#166534;">✔ Buổi #<?= (int)$r['created_session_id'] ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;max-width:220px;color:#44474e;"><?= h($r['reason']) ?></td>
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;"><?= date('d/m H:i', strtotime($r['created_at'])) ?></td>
                    <td><span class="apill <?= $sm[1] ?>"><?= h($sm[0]) ?></span></td>
                    <td style="font-size:11px;color:#64748b;max-width:180px;">
                        <?= $r['admin_note'] ? h($r['admin_note']) : '—' ?>
                        <?php if ($r['reviewed_at']): ?>
                        <div style="font-size:10px;color:#94a3b8;"><?= date('d/m H:i', strtotime($r['reviewed_at'])) ?> • <?= h($r['reviewer_name'] ?: '' ) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isPending): ?>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <button type="button" class="abtn abtn--green abtn--sm"
                                    onclick='openReview(<?= (int)$r['request_id'] ?>, "Approved", <?= json_encode($r['course_code'] . ' – ' . $r['section_code'] . ' • ' . ($r['request_type'] === 'Cancellation' ? 'nghỉ buổi' : 'học bù') . ' ' . date('d/m/Y', strtotime($r['request_type'] === 'Makeup' ? $r['proposed_date'] : $r['original_date'])), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✔ Duyệt</button>
                            <button type="button" class="abtn abtn--red abtn--sm"
                                    onclick='openReview(<?= (int)$r['request_id'] ?>, "Rejected", <?= json_encode($r['course_code'] . ' – ' . $r['section_code'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✖ Từ chối</button>
                        </div>
                        <?php if ($r['request_type'] === 'Cancellation' && (int)$r['recorded_count'] > 0): ?>
                        <div style="font-size:10px;color:#b45309;margin-top:3px;">⚠ Đã có <?= (int)$r['recorded_count'] ?> dòng điểm danh (sẽ được giữ nguyên).</div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="font-size:11px;color:#94a3b8;">Đã xử lý</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- REVIEW MODAL -->
<div id="reviewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;padding:26px;position:relative;">
    <button type="button" onclick="closeReview()" style="position:absolute;top:14px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
    <h2 id="reviewTitle" style="font-size:17px;font-weight:800;color:#0d1b2e;margin-bottom:6px;">Xử lý đề xuất</h2>
    <p id="reviewSub" style="font-size:12px;color:#64748b;margin-bottom:16px;"></p>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="review_request" />
        <input type="hidden" name="request_id" id="rvRequestId" value="" />
        <input type="hidden" name="decision" id="rvDecision" value="" />
        <input type="hidden" name="return_status" value="<?= h($filterStatus) ?>" />
        <input type="hidden" name="return_type" value="<?= h($filterType) ?>" />
        <input type="hidden" name="return_sem" value="<?= $filterSem ?: '' ?>" />
        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">
            Ghi chú của Admin <span id="rvNoteHint" style="font-weight:400;color:#94a3b8;"></span>
        </label>
        <textarea name="admin_note" id="rvNote" rows="3" maxlength="2000"
                  style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none;resize:vertical;"
                  placeholder="Nhập ghi chú xử lý..."></textarea>
        <div id="rvError" style="display:none;margin-top:8px;font-size:12px;color:#b91c1c;"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeReview()" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" id="rvSubmit" style="background:#0d1b2e;">Xác nhận</button>
        </div>
    </form>
</div></div>

<script>
function openReview(id, decision, label){
    document.getElementById('rvRequestId').value = id;
    document.getElementById('rvDecision').value = decision;
    var isReject = decision === 'Rejected';
    document.getElementById('reviewTitle').textContent = isReject ? 'Từ chối đề xuất' : 'Duyệt đề xuất';
    document.getElementById('reviewSub').textContent = label;
    document.getElementById('rvNote').value = '';
    document.getElementById('rvNoteHint').textContent = isReject ? '(bắt buộc – lưu lý do từ chối)' : '(không bắt buộc)';
    document.getElementById('rvError').style.display = 'none';
    document.getElementById('rvSubmit').textContent = isReject ? 'Từ chối đề xuất' : 'Duyệt đề xuất';
    document.getElementById('rvSubmit').style.background = isReject ? '#b91c1c' : '#0d1b2e';
    document.getElementById('reviewModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeReview(){
    document.getElementById('reviewModal').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('reviewModal').addEventListener('click', function(e){ if (e.target === this) closeReview(); });
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeReview(); });
document.querySelector('#reviewModal form').addEventListener('submit', function(e){
    var decision = document.getElementById('rvDecision').value;
    var note = document.getElementById('rvNote').value.trim();
    if (decision === 'Rejected' && note === '') {
        e.preventDefault();
        document.getElementById('rvError').textContent = 'Vui lòng nhập lý do từ chối.';
        document.getElementById('rvError').style.display = 'block';
        document.getElementById('rvNote').focus();
    }
});
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
