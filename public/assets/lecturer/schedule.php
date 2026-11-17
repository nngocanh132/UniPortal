<?php
/**
 * UniPortal – Lịch giảng dạy (Lecturer)
 *
 * Sessions on this page are generated automatically from the official
 * teaching schedule set by the Academic Affairs office. Lecturers cannot
 * create or delete sessions here; they can only request a cancellation or
 * a make-up session, which require Admin approval.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';
require_once $root . '/src/src/models/TeachingSchedule.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId     = (int)($_SESSION['lecturer_id'] ?? 0);
$accountId = (int)($_SESSION['account_id'] ?? 0);
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);
$sections   = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount  = LecturerModel::getUnreadWarningCount($lecId);

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$selSection = (int)($_GET['section'] ?? 0);
$sectionIds = array_map(fn($s) => (int)$s['section_id'], $sections);
if ($selSection && !in_array($selSection, $sectionIds, true)) $selSection = 0;

// ── POST: cancellation / make-up request (the only lecturer actions) ──
$flash = '';
if (isset($_SESSION['lec_sched_flash'])) {
    $flash = $_SESSION['lec_sched_flash'];
    unset($_SESSION['lec_sched_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'cancel_request') {
        $res = TeachingSchedule::createCancellationRequest(
            (int)($_POST['session_id'] ?? 0), $lecId, $accountId, (string)($_POST['reason'] ?? '')
        );
    } elseif ($action === 'makeup_request') {
        $res = TeachingSchedule::createMakeupRequest(
            (int)($_POST['session_id'] ?? 0), $lecId, $accountId,
            (string)($_POST['proposed_date'] ?? ''), (string)($_POST['proposed_start'] ?? ''),
            (string)($_POST['proposed_end'] ?? ''), (string)($_POST['proposed_room'] ?? ''),
            (string)($_POST['reason'] ?? '')
        );
    } else {
        $res = ['ok' => false, 'message' => 'Thao tác không hợp lệ. Giảng viên không thể tự tạo hoặc tự hủy buổi học.'];
    }

    $_SESSION['lec_sched_flash'] = ($res['ok'] ? 'success:' : 'error:') . $res['message'];
    header('Location: schedule.php' . ($selSection ? '?section=' . $selSection : ''));
    exit;
}

// ── Data ─────────────────────────────────────────────────────
$sessions  = $semId ? TeachingSchedule::getLecturerSessions($lecId, $semId, $selSection ?: null) : [];
$requests  = TeachingSchedule::getLecturerRequests($lecId);
if ($selSection) {
    $requests = array_values(array_filter($requests, fn($r) => (int)$r['section_id'] === $selSection));
}
$scheduleInfo = $selSection ? TeachingSchedule::getScheduleBySection($selSection) : false;

$today = date('Y-m-d');

$todaySessions = $upcomingSessions = $completedSessions = $cancelledSessions = [];
foreach ($sessions as $s) {
    if ($s['status'] === 'Cancelled') {
        $cancelledSessions[] = $s;
    } elseif ($s['session_date'] === $today) {
        $todaySessions[] = $s;
    } elseif ($s['session_date'] > $today) {
        $upcomingSessions[] = $s;
    } else {
        $completedSessions[] = $s;
    }
}
$completedSessions = array_reverse($completedSessions);

$scheduledSections = 0;
if ($semId) {
    foreach (TeachingSchedule::getSchedules($semId, null, $lecId) as $ts) $scheduledSections++;
}

$pendingReqs   = count(array_filter($requests, fn($r) => $r['status'] === 'Pending'));
$awaitingCount = count(array_filter($sessions, fn($s) => (int)$s['pending_cancel'] > 0 || (int)$s['pending_makeup'] > 0));

function ts_status_pill(array $s): array {
    if ($s['status'] === 'Cancelled') return ['Đã hủy', 'lpill--red'];
    if ((int)$s['pending_cancel'] > 0) return ['Chờ duyệt nghỉ', 'lpill--orange'];
    if ((int)$s['pending_makeup'] > 0) return ['Chờ duyệt học bù', 'lpill--orange'];
    if (($s['session_type'] ?? 'Regular') === 'Makeup') return ['Học bù', 'lpill--blue'];
    return ['Đã xếp lịch', 'lpill--green'];
}

function ts_day_label(string $date): string {
    $names = ['Mon' => 'T2', 'Tue' => 'T3', 'Wed' => 'T4', 'Thu' => 'T5', 'Fri' => 'T6', 'Sat' => 'T7', 'Sun' => 'CN'];
    return $names[date('D', strtotime($date))] ?? date('D', strtotime($date));
}

$pageTitle  = 'Lịch giảng dạy';
$activePage = 'schedule';
require __DIR__ . '/_layout.php';
?>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div class="lec-flash lec-flash--<?= $ft === 'success' ? 'success' : 'error' ?>">
    <?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:flex-end;flex-wrap:wrap;gap:12px;">
    <a href="grading.php<?= $selSection ? '?section=' . $selSection : '' ?>" class="lbtn lbtn--primary">✅ Điểm danh buổi học</a>
</div>

<!-- KPI row -->
<div class="lec-kpi-row">
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Lớp có lịch chính thức</span>
            <span class="lec-kpi__icon lec-kpi__icon--blue"><img src="../img/db-nav-grades.svg" alt="" /></span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= $scheduledSections ?></span><span class="lec-kpi__unit">/ <?= count($sections) ?> lớp</span></div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Buổi sắp tới</span>
            <span class="lec-kpi__icon lec-kpi__icon--yellow"><img src="../img/db-icon-schedule-time.svg" alt="" /></span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= count($upcomingSessions) ?></span><span class="lec-kpi__unit">buổi</span></div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Đã hoàn thành</span>
            <span class="lec-kpi__icon lec-kpi__icon--green"><img src="../img/db-icon-attendance.svg" alt="" /></span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= count($completedSessions) ?></span><span class="lec-kpi__unit">buổi</span></div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Đề xuất chờ duyệt</span>
            <span class="lec-kpi__icon lec-kpi__icon--red"><img src="../img/db-icon-alert-warn.svg" alt="" /></span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= $pendingReqs ?></span><span class="lec-kpi__unit">đề xuất</span></div>
    </div>
</div>

<?php if ($awaitingCount > 0): ?>
<div class="lec-flash lec-flash--info">
    ⏳ Có <?= $awaitingCount ?> buổi học đang chờ Phòng Đào tạo xử lý thay đổi lịch.
    <strong>Lịch chính thức chưa thay đổi</strong> cho đến khi đề xuất được duyệt.
</div>
<?php endif; ?>

<!-- Section selector -->
<?php if (empty($sections)): ?>
<div class="lcard" style="padding:40px;text-align:center;color:#94a3b8;">
    Bạn chưa được phân công lớp học phần nào trong học kỳ hiện tại.
</div>
<?php else: ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Xem theo lớp:</span>
    <a href="schedule.php" class="lpill <?= $selSection === 0 ? 'lpill--blue' : 'lpill--gray' ?>">Tất cả</a>
    <?php foreach ($sections as $s): ?>
    <a href="schedule.php?section=<?= (int)$s['section_id'] ?>"
       class="lpill <?= (int)$s['section_id'] === $selSection ? 'lpill--blue' : 'lpill--gray' ?>">
        <?= h($s['section_code']) ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($selSection): ?>
<div class="lcard" style="padding:16px 20px;">
    <?php if ($scheduleInfo): ?>
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:center;">
        <div>
            <div style="font-size:14px;font-weight:800;color:#001634;">
                🗓️ Lịch chính thức: <?= h(TeachingSchedule::DAYS[(int)$scheduleInfo['day_of_week']] ?? '') ?> hằng tuần
            </div>
            <div style="font-size:12px;color:#64748b;margin-top:3px;">
                <?= h($scheduleInfo['course_code']) ?> – <?= h($scheduleInfo['section_code']) ?> •
                <?= substr($scheduleInfo['start_time'], 0, 5) ?>–<?= substr($scheduleInfo['end_time'], 0, 5) ?>
                <?= $scheduleInfo['room'] ? ' • 📍 ' . h($scheduleInfo['room']) : '' ?>
                • Hiệu lực <?= date('d/m/Y', strtotime($scheduleInfo['effective_start'])) ?>–<?= date('d/m/Y', strtotime($scheduleInfo['effective_end'])) ?>
                <?php if (!empty($scheduleInfo['expected_sessions'])): ?>
                • Số buổi dự kiến: <?= (int)$scheduleInfo['expected_sessions'] ?>
                <?php endif; ?>
            </div>
        </div>
        <span class="lpill lpill--green"><?= $scheduleInfo['status'] === 'Active' ? 'Đang áp dụng' : 'Tạm dừng' ?></span>
    </div>
    <?php else: ?>
    <div style="font-size:13px;color:#b45309;font-weight:600;">
        ⚠️ Lớp này chưa có lịch tuần chính thức. Vui lòng liên hệ Phòng Đào tạo để được xếp lịch.
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$groups = [
    ['🏫 Hôm nay', $todaySessions],
    ['⏭️ Sắp tới', $upcomingSessions],
    ['✅ Đã hoàn thành', $completedSessions],
    ['🚫 Đã hủy', $cancelledSessions],
];
foreach ($groups as [$gTitle, $rows]):
    if (empty($rows)) continue;
?>
<div class="lcard" style="padding:0;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:14px;font-weight:800;color:#001634;"><?= $gTitle ?></div>
        <span class="lpill lpill--gray"><?= count($rows) ?> buổi</span>
    </div>
    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Ngày học</th>
                    <th>Giờ</th>
                    <th>Học phần &amp; Lớp</th>
                    <th>Phòng</th>
                    <th style="text-align:center;">Điểm danh</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $sess):
                [$statusLabel, $statusCls] = ts_status_pill($sess);
                $isCancelled = $sess['status'] === 'Cancelled';
                $canCancel   = !$isCancelled && $sess['session_date'] >= $today
                               && (int)$sess['pending_cancel'] === 0 && (int)$sess['approved_cancel'] === 0;
                $canMakeup   = (int)$sess['pending_makeup'] === 0
                               && ($isCancelled || (int)$sess['approved_cancel'] > 0 || (int)$sess['pending_cancel'] > 0);
                $label = $sess['course_code'] . ' – ' . $sess['section_code'] . ' • ' .
                         ($sess['session_no'] !== null ? 'Buổi ' . (int)$sess['session_no'] . ' • ' : 'Học bù • ') .
                         date('d/m/Y', strtotime($sess['session_date'])) . ' ' .
                         substr($sess['start_time'], 0, 5) . '–' . substr($sess['end_time'], 0, 5);
            ?>
                <tr style="<?= $isCancelled ? 'background:#fff5f5;' : ($sess['session_date'] === $today ? 'background:#eff4ff;' : '') ?>">
                    <td>
                        <div style="font-weight:700;color:<?= $isCancelled ? '#b91c1c' : '#001634' ?>;"><?= date('d/m/Y', strtotime($sess['session_date'])) ?></div>
                        <div style="font-size:11px;color:#94a3b8;">
                            <?= $sess['session_no'] !== null ? 'Buổi ' . (int)$sess['session_no'] . ' • ' : ($sess['session_type'] === 'Makeup' ? 'Học bù • ' : '') ?><?= ts_day_label($sess['session_date']) ?><?= $sess['session_date'] === $today ? ' • HÔM NAY' : '' ?>
                        </div>
                    </td>
                    <td style="font-size:13px;font-weight:600;"><?= substr($sess['start_time'], 0, 5) ?> – <?= substr($sess['end_time'], 0, 5) ?></td>
                    <td>
                        <div style="font-weight:700;font-size:13px;color:#001634;<?= $isCancelled ? 'text-decoration:line-through;' : '' ?>">
                            <?= h($sess['course_code']) ?> – <?= h($sess['course_name']) ?>
                        </div>
                        <div style="font-size:11px;color:#64748b;"><?= h($sess['section_code']) ?><?= $sess['session_type'] === 'Makeup' ? ' • Buổi học bù' : '' ?></div>
                    </td>
                    <td style="font-size:12px;color:#64748b;"><?= $sess['room'] ? h($sess['room']) : '—' ?></td>
                    <td style="text-align:center;">
                        <?php if ($isCancelled): ?>
                        <span style="font-size:11px;color:#b91c1c;">Không thể điểm danh</span>
                        <?php elseif ((int)$sess['recorded_count'] > 0): ?>
                        <span class="lpill lpill--green" style="font-size:10px;">✅ <?= (int)$sess['recorded_count'] ?>/<?= (int)$sess['total_students'] ?></span>
                        <?php else: ?>
                        <span class="lpill lpill--gray" style="font-size:10px;">Chưa điểm danh</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="lpill <?= $statusCls ?>" style="font-size:10px;"><?= h($statusLabel) ?></span></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <?php if (!$isCancelled): ?>
                            <a href="grading.php?section=<?= (int)$sess['section_id'] ?>&session=<?= (int)$sess['session_id'] ?>"
                               class="lbtn lbtn--yellow lbtn--sm">✅ Điểm danh</a>
                            <?php endif; ?>
                            <?php if ($canCancel): ?>
                            <button type="button" class="lbtn lbtn--outline lbtn--sm js-cancel"
                                    data-session="<?= (int)$sess['session_id'] ?>"
                                    data-label="<?= h($label) ?>">🚫 Đề xuất nghỉ</button>
                            <?php endif; ?>
                            <?php if ($canMakeup): ?>
                            <button type="button" class="lbtn lbtn--outline lbtn--sm js-makeup"
                                    data-session="<?= (int)$sess['session_id'] ?>"
                                    data-label="<?= h($label) ?>"
                                    data-room="<?= h((string)$sess['room']) ?>"
                                    data-start="<?= substr($sess['start_time'], 0, 5) ?>"
                                    data-end="<?= substr($sess['end_time'], 0, 5) ?>">🔁 Đề xuất học bù</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($sessions)): ?>
<div class="lcard" style="padding:40px;text-align:center;color:#94a3b8;">
    Chưa có buổi học nào được tạo. Lịch học sẽ xuất hiện sau khi Phòng Đào tạo xếp lịch chính thức.
</div>
<?php endif; ?>

<!-- Request history -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;font-size:14px;font-weight:800;color:#001634;">
        📨 Lịch sử đề xuất thay đổi
    </div>
    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Loại</th>
                    <th>Buổi gốc</th>
                    <th>Đề xuất</th>
                    <th>Lý do</th>
                    <th>Gửi lúc</th>
                    <th>Trạng thái</th>
                    <th>Phản hồi từ Phòng Đào tạo</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;font-style:italic;">Chưa gửi đề xuất nào.</td></tr>
            <?php else: foreach ($requests as $r):
                $rCls = $r['status'] === 'Pending' ? 'lpill--orange' : ($r['status'] === 'Approved' ? 'lpill--green' : 'lpill--red');
                $rLbl = ['Pending' => 'Chờ duyệt', 'Approved' => 'Đã duyệt', 'Rejected' => 'Từ chối'][$r['status']] ?? $r['status'];
            ?>
                <tr>
                    <td><span class="lpill <?= $r['request_type'] === 'Cancellation' ? 'lpill--red' : 'lpill--blue' ?>" style="font-size:10px;"><?= $r['request_type'] === 'Cancellation' ? 'Xin nghỉ' : 'Học bù' ?></span></td>
                    <td style="font-size:12px;color:#44474e;">
                        <?= h($r['course_code']) ?> – <?= h($r['section_code']) ?><br />
                        <span style="color:#94a3b8;"><?= date('d/m/Y', strtotime($r['original_date'])) ?> <?= substr($r['original_start'], 0, 5) ?>–<?= substr($r['original_end'], 0, 5) ?></span>
                    </td>
                    <td style="font-size:12px;color:#44474e;">
                        <?php if ($r['request_type'] === 'Makeup'): ?>
                            <?= date('d/m/Y', strtotime($r['proposed_date'])) ?><br />
                            <span style="color:#94a3b8;"><?= substr($r['proposed_start_time'], 0, 5) ?>–<?= substr($r['proposed_end_time'], 0, 5) ?><?= $r['proposed_room'] ? ' • ' . h($r['proposed_room']) : '' ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#64748b;max-width:220px;"><?= nl2br(h($r['reason'])) ?></td>
                    <td style="font-size:11px;color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                    <td><span class="lpill <?= $rCls ?>" style="font-size:10px;"><?= $rLbl ?></span></td>
                    <td style="font-size:12px;color:#64748b;max-width:220px;">
                        <?= $r['admin_note'] ? nl2br(h($r['admin_note'])) : '<span style="color:#94a3b8;">—</span>' ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: cancellation request -->
<div id="modalCancel" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:480px;padding:28px;position:relative;">
    <button type="button" onclick="closeModal('modalCancel')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:6px;">🚫 Đề xuất nghỉ buổi học</h2>
    <div style="font-size:12px;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:14px;">
        Buổi học <strong>chưa bị hủy</strong>. Lịch chính thức chỉ thay đổi sau khi Phòng Đào tạo duyệt.
    </div>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="cancel_request" />
        <input type="hidden" name="session_id" id="cancelSessionId" value="" />
        <div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:10px;" id="cancelSessionLabel"></div>
        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Lý do xin nghỉ *</label>
        <textarea name="reason" required minlength="5" rows="3" placeholder="VD: Giảng viên ốm, cần nghỉ buổi học này..."
                  style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none;resize:vertical;"></textarea>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalCancel')" class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--red">Gửi đề xuất</button>
        </div>
    </form>
</div></div>

<!-- MODAL: make-up request -->
<div id="modalMakeup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:520px;padding:28px;position:relative;">
    <button type="button" onclick="closeModal('modalMakeup')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:6px;">🔁 Đề xuất học bù</h2>
    <div style="font-size:12px;color:#1e40af;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px;margin-bottom:14px;">
        Buổi học bù chỉ được thêm vào lịch chính thức sau khi Phòng Đào tạo duyệt.
    </div>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="makeup_request" />
        <input type="hidden" name="session_id" id="makeupSessionId" value="" />
        <div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:10px;" id="makeupSessionLabel"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày học bù *</label>
                <input type="date" name="proposed_date" id="makeupDate" value="<?= date('Y-m-d') ?>" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bắt đầu *</label>
                <input type="time" name="proposed_start" id="makeupStart" value="07:30" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Kết thúc *</label>
                <input type="time" name="proposed_end" id="makeupEnd" value="09:00" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Phòng học</label>
                <input type="text" name="proposed_room" id="makeupRoom" maxlength="30" placeholder="VD: B2-305"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Lý do học bù *</label>
                <textarea name="reason" required minlength="5" rows="2" placeholder="VD: Dạy bù buổi đã nghỉ do bão..."
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none;resize:vertical;"></textarea>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalMakeup')" class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">Gửi đề xuất</button>
        </div>
    </form>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
function showModal(id){document.getElementById(id).style.display='flex';document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).style.display='none';document.body.style.overflow='';}

document.querySelectorAll('.js-cancel').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('cancelSessionId').value = this.dataset.session;
        document.getElementById('cancelSessionLabel').textContent = this.dataset.label;
        showModal('modalCancel');
    });
});
document.querySelectorAll('.js-makeup').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('makeupSessionId').value = this.dataset.session;
        document.getElementById('makeupSessionLabel').textContent = this.dataset.label;
        if (this.dataset.start) document.getElementById('makeupStart').value = this.dataset.start;
        if (this.dataset.end) document.getElementById('makeupEnd').value = this.dataset.end;
        document.getElementById('makeupRoom').value = this.dataset.room || '';
        showModal('modalMakeup');
    });
});
['modalCancel', 'modalMakeup'].forEach(function (id) {
    var m = document.getElementById(id);
    if (m) m.addEventListener('click', function (e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeModal('modalCancel'); closeModal('modalMakeup'); }
});
</script>
