<?php
/**
 * UniPortal – Thời khóa biểu (Student)
 *
 * Two sections only:
 *   1. Buổi học kế tiếp – next official session (cancelled excluded)
 *   2. Lịch theo tuần chính thức – week calendar with date/week navigation
 *
 * Only confirmed sessions from AttendanceSession are shown. Cancelled
 * sessions stay visible with a marker; approved make-up sessions appear as
 * their own entries. No examination dates are fabricated: the exam filter
 * shows an explicit empty state because the database holds no exam schedule.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/TeachingSchedule.php';
require_once $root . '/src/src/services/RegistrationService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$studentId = $_SESSION['student_id'] ?? '';
$pdo       = get_db();

$currentSem = StudentModel::getCurrentSemester();
$semId      = (int)($_GET['sem'] ?? ($currentSem['semester_id'] ?? 0));

$semesters = $pdo->query(
    'SELECT semester_id, semester_name, academic_year, status
     FROM Semester ORDER BY start_date DESC'
)->fetchAll();

$sessions = $semId ? TeachingSchedule::getStudentTimetable($studentId, $semId) : [];
// Synthetic rows (negative ids) are unconfirmed plans, not official sessions.
$sessions = array_values(array_filter($sessions, static fn($s) => (int)$s['session_id'] > 0));

$filter = $_GET['filter'] ?? 'class';
if (!in_array($filter, ['class', 'exam', 'all'], true)) $filter = 'class';

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || !strtotime($selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$weekStart = (new DateTimeImmutable($selectedDate))->modify('monday this week');
$weekDates = [];
for ($i = 0; $i < 7; $i++) {
    $weekDates[] = $weekStart->modify("+$i day")->format('Y-m-d');
}
$weekStartStr = $weekDates[0];
$weekEndStr   = $weekDates[6];

$focusSession = (int)($_GET['session'] ?? 0);
$nowStamp     = time();
$today        = date('Y-m-d');

$nextSession = null;
foreach ($sessions as $s) {
    if ($s['status'] === 'Cancelled') continue;
    if (strtotime($s['session_date'] . ' ' . $s['start_time']) < $nowStamp) continue;
    $nextSession = $s;
    break;
}

$byDate = [];
foreach ($sessions as $s) {
    if ($s['session_date'] < $weekStartStr || $s['session_date'] > $weekEndStr) continue;
    $byDate[$s['session_date']][] = $s;
}

// One table, two session rows: Sáng (starts before 13:00) and Chiều
// (13:00 onward). Cards always show the exact times stored in the database.
$sessionOf = static function (array $s): string {
    return substr($s['start_time'], 0, 5) < '13:00' ? 'morning' : 'afternoon';
};
$daySchedule = [];
foreach ($weekDates as $d) {
    $daySchedule[$d] = ['morning' => [], 'afternoon' => []];
}
foreach ($byDate as $d => $rows) {
    foreach ($rows as $r) {
        $daySchedule[$d][$sessionOf($r)][] = $r;
    }
}
foreach ($daySchedule as &$daySlots) {
    foreach ($daySlots as &$slotRows) {
        usort($slotRows, static fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
    }
    unset($slotRows);
}
unset($daySlots);

$attLabels = ['Present' => 'Có mặt', 'Absent' => 'Vắng', 'Late' => 'Đi muộn', 'Excused' => 'Có phép'];

$weekCount   = 0;
$cancelledInWeek = 0;
foreach ($byDate as $rows) {
    foreach ($rows as $r) {
        $weekCount++;
        if ($r['status'] === 'Cancelled') $cancelledInWeek++;
    }
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$dayNames = ['1' => 'Thứ 2', '2' => 'Thứ 3', '3' => 'Thứ 4', '4' => 'Thứ 5', '5' => 'Thứ 6', '6' => 'Thứ 7', '7' => 'Chủ nhật'];

$navQs = function (array $over) use ($semId, $filter, $selectedDate): string {
    return '?' . http_build_query(array_merge(
        ['sem' => $semId, 'filter' => $filter, 'date' => $selectedDate],
        $over
    ));
};

$renderCard = function (array $s, string $d) use ($navQs, $focusSession, $attLabels): string {
    $isCancelled = $s['status'] === 'Cancelled';
    $isMakeup    = $s['session_type'] === 'Makeup';
    $attStatus   = (string)($s['attendance_status'] ?? '');
    $sid         = (int)$s['session_id'];
    ob_start(); ?>
    <a id="session-<?= $sid ?>"
       class="sch-card<?= $isCancelled ? ' is-cancelled' : '' ?><?= $isMakeup && !$isCancelled ? ' is-makeup' : '' ?><?= $focusSession === $sid ? ' is-focus' : '' ?>"
       href="<?= h($navQs(['date' => $d, 'session' => $sid])) ?>#session-<?= $sid ?>">
        <div class="sch-card__name"><?= h($s['course_name']) ?></div>
        <div class="sch-card__line"><?= h($s['course_code']) ?> · Lớp <?= h($s['section_code']) ?></div>
        <div class="sch-card__time">
            <?= substr($s['start_time'], 0, 5) ?>–<?= substr($s['end_time'], 0, 5) ?>
            <?= $isCancelled ? ' · Đã hủy' : ($isMakeup ? ' · Học bù' : '') ?>
        </div>
        <?php if ($s['room']): ?>
        <div class="sch-card__line">Phòng: <?= h($s['room']) ?></div>
        <?php endif; ?>
        <?php if ($s['lecturer_name']): ?>
        <div class="sch-card__line">GV: <?= h($s['lecturer_name']) ?></div>
        <?php endif; ?>
        <?php if ($attStatus !== '' && isset($attLabels[$attStatus])): ?>
        <div class="sch-card__line">Điểm danh: <?= h($attLabels[$attStatus]) ?></div>
        <?php endif; ?>
    </a>
    <?php return (string)ob_get_clean();
};

$pageTitle  = 'Thời khóa biểu';
$activePage = 'schedule';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($studentId);
require_once __DIR__ . '/../partials/layout.php';
?>

<style>
.sch-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.sch-toolbar__group{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.sch-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:34px;padding:0 12px;
    border-radius:8px;background:#fff;border:1px solid #e2e8f0;color:#334155;font-size:12.5px;font-weight:600;
    text-decoration:none;cursor:pointer;}
.sch-btn.is-active{background:#102b50;border-color:#102b50;color:#fff;}
.sch-tab{padding:6px 12px;border-radius:20px;border:1px solid #e2e8f0;background:#fff;color:#475569;
    font-size:12px;font-weight:600;text-decoration:none;}
.sch-tab.is-active{background:#102b50;border-color:#102b50;color:#fff;}
.sch-table-wrap{overflow-x:auto;}
.sch-table{width:100%;min-width:1000px;border-collapse:collapse;table-layout:fixed;background:#fff;}
.sch-table th,.sch-table td{border:1px solid #dbe3ec;vertical-align:top;}
.sch-table thead th{background:#f1f5f9;color:#334155;font-size:11.5px;font-weight:800;
    text-align:center;padding:8px 6px;line-height:1.3;}
.sch-table thead th.sch-table__ca{width:104px;background:#fef6e4;color:#92400e;}
.sch-table__date{display:block;font-size:10px;font-weight:600;color:#94a3b8;margin-top:1px;}
.sch-table__session{width:104px;background:#fffbeb;text-align:center;vertical-align:middle;padding:10px 6px;}
.sch-table__session-name{font-size:13px;font-weight:800;color:#92400e;line-height:1.2;}
.sch-table__session-time{font-size:10px;font-weight:600;color:#b45309;margin-top:3px;white-space:nowrap;}
.sch-table tbody td{padding:6px;background:#fff;}
.sch-table tbody td.is-today{background:#f5f9ff;}
.sch-table tbody td.is-selected{outline:2px solid #102b50;outline-offset:-2px;}
.sch-nocls{font-size:10.5px;color:#b6c2d2;font-style:italic;text-align:center;padding:12px 4px;}
.sch-card{display:block;border:1px solid #dbe4ef;border-left:3px solid #3b82f6;border-radius:6px;
    background:#fbfdff;padding:6px 7px;margin-bottom:6px;text-decoration:none;color:inherit;
    overflow:hidden;min-width:0;}
.sch-card:last-child{margin-bottom:0;}
.sch-card:hover{border-color:#93c5fd;}
.sch-card.is-cancelled{background:#fff5f5;border-left-color:#ef4444;}
.sch-card.is-makeup{border-left-color:#8b5cf6;}
.sch-card.is-focus{box-shadow:0 0 0 2px #102b50;}
.sch-card__name{font-size:11.5px;font-weight:800;color:#001634;line-height:1.3;overflow-wrap:anywhere;}
.sch-card__line{font-size:10.5px;color:#64748b;line-height:1.4;margin-top:1px;overflow-wrap:anywhere;}
.sch-card__time{font-size:11px;font-weight:700;color:#1e40af;margin-top:2px;}
.sch-card.is-cancelled .sch-card__name,
.sch-card.is-cancelled .sch-card__time{text-decoration:line-through;color:#b91c1c;}
.sch-empty{padding:40px 20px;text-align:center;color:#94a3b8;font-size:13px;}
.sch-print-title{display:none;}
@media print{
    .db-sidebar,.db-header,.sch-toolbar,.sch-print-hide{display:none!important;}
    .db-layout,.db-main,.db-content{display:block!important;padding:0!important;max-width:none!important;margin:0!important;}
    body{background:#fff!important;}
    .card{border:0!important;box-shadow:none!important;margin:0!important;padding:0!important;}
    .sch-print-title{display:block;font-size:14px;font-weight:800;color:#001634;margin:0 0 8px;}
    .sch-table{min-width:0;font-size:9.5px;}
    .sch-table thead th,.sch-table__session{padding:5px 4px;}
    .sch-card{padding:4px 5px;margin-bottom:4px;}
    .sch-table-wrap{overflow:visible;}
    tr,.sch-card{break-inside:avoid;}
}
</style>

<!-- 1. NEXT CLASS -->
<div class="card sch-print-hide" style="padding:16px 20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">
        <div style="font-size:14px;font-weight:800;color:#001634;">⏭ Buổi học kế tiếp</div>
        <?php if ($nextSession): ?>
        <span class="status-pill status-pill--<?= $nextSession['session_type'] === 'Makeup' ? 'enrolled' : 'active' ?>" style="font-size:10px;">
            <?= $nextSession['session_type'] === 'Makeup' ? 'Học bù' : 'Theo lịch' ?>
        </span>
        <?php endif; ?>
    </div>
    <?php if (!$nextSession): ?>
    <div style="padding:14px 16px;background:#f8fafc;border-radius:8px;color:#94a3b8;font-size:13px;">
        Không còn buổi học nào sắp tới trong học kỳ này.
    </div>
    <?php else:
        $nDate = date('d/m/Y', strtotime($nextSession['session_date']));
        $nDay  = $dayNames[date('N', strtotime($nextSession['session_date']))] ?? '';
        $focusQs = '?sem=' . $semId . '&filter=class&date=' . $nextSession['session_date'] . '&session=' . (int)$nextSession['session_id'];
    ?>
    <a href="<?= h($focusQs) ?>#session-<?= (int)$nextSession['session_id'] ?>"
       style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
              padding:14px 16px;border:1px solid #bfdbfe;border-left:4px solid #1e40af;border-radius:10px;
              background:#f8fbff;text-decoration:none;color:inherit;">
        <div style="min-width:0;">
            <div style="font-size:14px;font-weight:800;color:#001634;">
                <?= h($nextSession['course_code']) ?> – <?= h($nextSession['course_name']) ?>
            </div>
            <div style="font-size:12px;color:#64748b;margin-top:3px;">
                Lớp <?= h($nextSession['section_code']) ?>
                <?= $nextSession['room'] ? ' · 📍 ' . h($nextSession['room']) : '' ?>
                <?= $nextSession['lecturer_name'] ? ' · 👨‍🏫 ' . h($nextSession['lecturer_name']) : '' ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:13px;font-weight:700;color:#1e40af;"><?= h($nDay) ?>, <?= $nDate ?></div>
            <div style="font-size:12px;color:#334155;">
                <?= substr($nextSession['start_time'], 0, 5) ?>–<?= substr($nextSession['end_time'], 0, 5) ?>
            </div>
        </div>
    </a>
    <?php endif; ?>
</div>

<!-- 2. OFFICIAL WEEK -->
<div class="card" style="margin-top:14px;padding:0;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;">
        <div class="sch-toolbar">
            <div class="sch-toolbar__group">
                <div style="font-size:14px;font-weight:800;color:#001634;margin-right:6px;">🗓 Lịch theo tuần chính thức</div>
                <?php foreach (['class' => 'Lịch học', 'exam' => 'Lịch thi', 'all' => 'Tất cả'] as $k => $label): ?>
                <a class="sch-tab<?= $filter === $k ? ' is-active' : '' ?>"
                   href="<?= h($navQs(['filter' => $k, 'session' => 0])) ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <div class="sch-toolbar__group">
                <form method="GET" style="display:flex;align-items:center;gap:6px;margin:0;">
                    <input type="hidden" name="filter" value="<?= h($filter) ?>" />
                    <select name="sem" onchange="this.form.submit()"
                            style="height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 8px;font-size:12px;background:#fff;">
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= (int)$sem['semester_id'] ?>" <?= (int)$sem['semester_id'] === $semId ? 'selected' : '' ?>>
                            <?= h($sem['semester_name'] . ' – ' . $sem['academic_year']) ?><?= $sem['status'] === 'Active' ? ' (hiện tại)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date" value="<?= h($selectedDate) ?>" onchange="this.form.submit()"
                           style="height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 8px;font-size:12px;background:#fff;" />
                </form>
            </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:10px;">
            <div style="font-size:12.5px;color:#475569;font-weight:600;">
                Tuần: <?= date('d/m', strtotime($weekStartStr)) ?> – <?= date('d/m/Y', strtotime($weekEndStr)) ?>
                <span style="font-weight:400;color:#94a3b8;">· <?= $weekCount ?> buổi<?= $cancelledInWeek > 0 ? ' (' . $cancelledInWeek . ' đã hủy)' : '' ?></span>
            </div>
            <div class="sch-toolbar__group">
                <a class="sch-btn" href="<?= h($navQs(['date' => $weekStart->modify('-7 day')->format('Y-m-d')])) ?>">‹ Tuần trước</a>
                <a class="sch-btn" href="<?= h($navQs(['date' => date('Y-m-d')])) ?>">Tuần này</a>
                <a class="sch-btn" href="<?= h($navQs(['date' => $weekStart->modify('+7 day')->format('Y-m-d')])) ?>">Tuần sau ›</a>
                <button type="button" class="sch-btn" onclick="window.print()">In thời khóa biểu</button>
            </div>
        </div>
    </div>

    <?php if ($filter === 'exam'): ?>
    <div class="sch-empty">
        <div style="font-size:26px;margin-bottom:6px;">🗒️</div>
        Chưa có lịch thi chính thức cho học kỳ này.<br/>
        Lịch thi sẽ hiển thị tại đây ngay khi Nhà trường công bố.
    </div>
    <?php else: ?>
    <div class="sch-print-title">
        Thời khóa biểu tuần <?= date('d/m/Y', strtotime($weekStartStr)) ?> – <?= date('d/m/Y', strtotime($weekEndStr)) ?>
    </div>
    <div class="sch-table-wrap">
    <table class="sch-table">
        <thead>
            <tr>
                <th class="sch-table__ca">Ca học</th>
                <?php foreach ($weekDates as $d): $dow = date('N', strtotime($d)); ?>
                <th>
                    <?= h($dayNames[$dow] ?? '') ?>
                    <span class="sch-table__date"><?= date('d/m', strtotime($d)) ?></span>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach (['morning' => ['Sáng', '07:00–12:30'], 'afternoon' => ['Chiều', '13:00–18:30']] as $sessKey => [$sessName, $sessTime]): ?>
            <tr>
                <th class="sch-table__session">
                    <div class="sch-table__session-name"><?= h($sessName) ?></div>
                    <div class="sch-table__session-time"><?= h($sessTime) ?></div>
                </th>
                <?php foreach ($weekDates as $d):
                    $isToday = $d === $today;
                    $isSel = $d === $selectedDate;
                    $rows = $daySchedule[$d][$sessKey];
                ?>
                <td class="<?= $isToday ? 'is-today' : '' ?><?= $isSel && !$isToday ? ' is-selected' : '' ?>">
                    <?php if (empty($rows)): ?>
                    <div class="sch-nocls">Không có lớp</div>
                    <?php else: foreach ($rows as $s): ?>
                    <?= $renderCard($s, $d) ?>
                    <?php endforeach; endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($filter === 'all'): ?>
    <div style="padding:0 18px 14px;font-size:11.5px;color:#94a3b8;">
        Hệ thống chưa có dữ liệu lịch thi chính thức nên chỉ hiển thị các buổi học.
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
