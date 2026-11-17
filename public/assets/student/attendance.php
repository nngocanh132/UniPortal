<?php
/**
 * UniPortal – Điểm danh & Chuyên cần (Attendance)
 * Figma frame: ATT (3:2)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/Gpa.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/SystemConfig.php';
require_once $root . '/src/src/services/AttendanceService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$student_id = $_SESSION['student_id'] ?? '';
$full_name  = $_SESSION['full_name']  ?? '';

$currentSem = StudentModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);

$pdo = get_db();

// All semesters for filter
$allSems = $pdo->prepare('SELECT semester_id, semester_name, academic_year FROM Semester ORDER BY start_date DESC');
$allSems->execute();
$semesters = $allSems->fetchAll();

$selSemId = isset($_GET['sem']) ? (int)$_GET['sem'] : $semId;

// Attendance summary per course in the selected semester.
// Every figure comes from AttendanceService (official sessions, not semester days).
function getAttSummary(string $sid, int $sem): array {
    if (!$sem) return [];
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT e.enrollment_id, e.status AS enroll_status,
                c.course_code, c.course_name, c.credits,
                cs.section_id, cs.section_code
         FROM Enrollment e
         JOIN ClassSection cs ON cs.section_id = e.section_id
         JOIN Course c        ON c.course_id   = cs.course_id
         WHERE e.student_id = :sid AND cs.semester_id = :sem
           AND e.status IN (\'Enrolled\',\'Completed\')
         ORDER BY c.course_code ASC'
    );
    $stmt->execute([':sid' => $sid, ':sem' => $sem]);

    $rows = [];
    $policy = AttendanceService::policy();
    foreach ($stmt->fetchAll() as $row) {
        $stats = AttendanceService::studentSectionStats(
            $pdo, (int)$row['enrollment_id'], (int)$row['section_id'], $policy
        );
        $rows[] = array_merge($row, $stats);
    }
    return $rows;
}

// Attendance history for the detail modal, grouped by section.
// Actual Attendance rows only – cancelled sessions are excluded.
function getAttDetails(string $sid, int $sem): array {
    if (!$sem) return [];
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT
            e.section_id,
            ats.session_date, ats.start_time, ats.end_time, ats.topic,
            COALESCE(ats.room, cs.room) AS room,
            c.course_code, c.course_name,
            a.status, a.note
         FROM Attendance a
         JOIN Enrollment e    ON e.enrollment_id  = a.enrollment_id
         JOIN ClassSection cs ON cs.section_id    = e.section_id
         JOIN Course c        ON c.course_id      = cs.course_id
         JOIN AttendanceSession ats ON ats.session_id = a.session_id
         WHERE e.student_id = :sid AND cs.semester_id = :sem
           AND ats.status <> \'Cancelled\'
         ORDER BY ats.session_date DESC, ats.start_time DESC'
    );
    $stmt->execute([':sid' => $sid, ':sem' => $sem]);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int)$row['section_id']][] = $row;
    }
    return $grouped;
}

$attSummary = getAttSummary($student_id, $selSemId ?: $semId);
$attDetails = getAttDetails($student_id, $selSemId ?: $semId);
$policy     = AttendanceService::policy();
$banPct     = $policy['ban_pct'];
$warnFrom   = $policy['warn_from'];

// Aggregate stats – official sessions only
$totalOfficial  = array_sum(array_column($attSummary, 'official_total'));
$totalCompleted = array_sum(array_column($attSummary, 'completed_total'));
$totalPresent   = array_sum(array_column($attSummary, 'present'));
$totalLate      = array_sum(array_column($attSummary, 'late'));
$totalExcused   = array_sum(array_column($attSummary, 'excused'));
$totalAbsent    = array_sum(array_column($attSummary, 'absent_counted'));
$attendedTotal  = $totalPresent + $totalLate + $totalExcused;
$attRate        = $totalCompleted > 0 ? round($attendedTotal / $totalCompleted * 100, 1) : null;
$overallAbsPct  = $totalOfficial > 0 ? round($totalAbsent / $totalOfficial * 100, 1) : 0.0;
$ineligibleCount = count(array_filter($attSummary, fn($r) => $r['status'] === 'ineligible'));
$atRiskCount     = count(array_filter($attSummary, fn($r) =>
    $r['status'] === 'at_risk' || ($r['status'] === 'eligible' && (int)$r['risk_level'] === 2)));

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$statusLabel = ['Present'=>'Có mặt','Absent'=>'Vắng','Late'=>'Đi muộn','Excused'=>'Vắng có phép'];
$statusClass = ['Present'=>'present','Absent'=>'absent','Late'=>'late','Excused'=>'excused'];

$pageTitle  = 'Điểm danh - Chuyên cần';
$activePage = 'attendance';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<?php
$hasRisk = $atRiskCount > 0;
$hasProhibition = $ineligibleCount > 0;
?>
<?php if ($hasProhibition): ?>
<div class="warning-card warning-card--critical">
    <img class="warning-card__icon" src="<?= $imgBase ?>db-icon-alert-warn.svg" alt="Cảnh báo" />
    <div class="warning-card__body">
        <h3>🚫 CẢNH BÁO CẤM THI</h3>
        <p>Tỷ lệ vắng của bạn đã <strong>vượt quá <?= (int)$banPct ?>%</strong> tổng số buổi chính thức
        ở <strong><?= $ineligibleCount ?></strong> môn. Theo quy chế, bạn <strong>không đủ điều kiện dự thi</strong>
        các môn này. Vui lòng liên hệ giảng viên hoặc Phòng Đào tạo ngay.</p>
    </div>
</div>
<?php elseif ($hasRisk): ?>
<div class="warning-card warning-card--warn">
    <img class="warning-card__icon" src="<?= $imgBase ?>db-icon-alert-info.svg" alt="Chú ý" />
    <div class="warning-card__body">
        <h3>⚠️ Chú ý chuyên cần</h3>
        <p>Tỷ lệ vắng đang ở mức cảnh báo (từ <?= rtrim(rtrim(number_format($warnFrom,1,'.',''),'0'),'.') ?>%) ở một số môn.
        Vắng <strong>vượt quá <?= (int)$banPct ?>%</strong> tổng số buổi chính thức sẽ mất điều kiện dự thi.
        Hãy cải thiện tỷ lệ chuyên cần.</p>
    </div>
</div>
<?php endif; ?>

<!-- Stat summary row -->
<div class="stat-row">
    <div class="stat-mini">
        <div class="stat-mini__label">Tỷ lệ chuyên cần</div>
        <div class="stat-mini__value" style="color:<?= ($attRate ?? 100) < 80 ? '#ef4444' : '#102b50' ?>">
            <?= $attRate !== null ? number_format($attRate,1).'%' : '—' ?>
        </div>
        <div class="stat-mini__sub">trên <?= $totalCompleted ?> buổi đã hoàn thành</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Tổng buổi chính thức</div>
        <div class="stat-mini__value"><?= $totalOfficial ?></div>
        <div class="stat-mini__sub">đã loại buổi hủy</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Vắng (tính điều kiện)</div>
        <div class="stat-mini__value" style="color:#ef4444;">
            <?= $totalAbsent ?><span style="font-size:14px;color:#94a3b8;font-weight:600;">/<?= $totalOfficial ?></span>
        </div>
        <div class="stat-mini__sub"><?= number_format($overallAbsPct,1) ?>% tổng buổi chính thức</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Có mặt · Muộn · Có phép</div>
        <div class="stat-mini__value" style="color:#059669;"><?= $totalPresent ?></div>
        <div class="stat-mini__sub"><?= $totalLate ?> muộn · <?= $totalExcused ?> có phép</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Điều kiện dự thi</div>
        <div class="stat-mini__value" style="color:<?= $ineligibleCount > 0 ? '#ef4444' : ($atRiskCount > 0 ? '#d97706' : '#059669') ?>;">
            <?= $ineligibleCount > 0 ? $ineligibleCount.' môn không đủ ĐK' : ($atRiskCount > 0 ? $atRiskCount.' môn nguy cơ' : 'Đủ điều kiện') ?>
        </div>
        <div class="stat-mini__sub">ngưỡng cấm thi &gt; <?= (int)$banPct ?>%</div>
    </div>
</div>

<!-- Semester filter -->
<div class="filter-ribbon">
    <span style="font-size:14px;font-weight:600;color:#001634;">Tóm tắt theo môn học</span>
    <form method="GET" style="display:flex;align-items:center;gap:8px;">
        <label style="font-size:13px;font-weight:600;color:#44474e;">Học kỳ:</label>
        <select name="sem" onchange="this.form.submit()">
            <?php foreach ($semesters as $s): ?>
            <option value="<?= h($s['semester_id']) ?>"
                <?= (int)$s['semester_id'] === ($selSemId ?: $semId) ? 'selected' : '' ?>>
                <?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<div style="font-size:11.5px;color:#94a3b8;margin:-2px 0 10px;line-height:1.5;">
    Cách tính: tỷ lệ vắng = số lượt vắng đã ghi nhận / <strong>tổng số buổi học chính thức</strong> của lớp
    (buổi đã hủy bị loại). Buổi học tương lai và buổi chưa điểm danh không bị tính là vắng.
    Chỉ khi vắng <strong>vượt quá <?= (int)$banPct ?>%</strong> sinh viên mới mất điều kiện dự thi.
</div>
<div class="card">
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Mã môn</th>
                    <th>Tên môn học</th>
                    <th>TC</th>
                    <th>Lớp</th>
                    <th>Tổng buổi chính thức</th>
                    <th>Đã hoàn thành</th>
                    <th>Có mặt</th>
                    <th>Vắng (tính)</th>
                    <th>Tỷ lệ vắng</th>
                    <th>Còn được vắng</th>
                    <th>Điều kiện dự thi</th>
                    <th style="text-align:center;">Chi tiết</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attSummary)): ?>
                <tr><td colspan="12" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có dữ liệu điểm danh cho học kỳ này.
                </td></tr>
                <?php else: ?>
                <?php foreach ($attSummary as $row):
                    $official = (int)$row['official_total'];
                    $absPct   = (float)$row['absent_pct'];
                    $counted  = (int)$row['absent_counted'];

                    if ($row['status'] === 'no_data')       { $riskClass='present'; $risk='— Chưa có buổi'; $color='#94a3b8'; }
                    elseif ($row['status'] === 'ineligible'){ $riskClass='absent';  $risk='🚫 Không đủ ĐK'; $color='#ef4444'; }
                    elseif ((int)$row['risk_level'] === 2)  { $riskClass='absent';  $risk='⚠️ Chạm ngưỡng'; $color='#ef4444'; }
                    elseif ($row['status'] === 'at_risk')   { $riskClass='late';    $risk='⚠️ Nguy cơ';     $color='#d97706'; }
                    else                                    { $riskClass='present'; $risk='✅ Đủ điều kiện'; $color='#059669'; }
                ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= h($row['course_code']) ?></span></td>
                    <td style="font-weight:600;color:#001634;"><?= h($row['course_name']) ?></td>
                    <td style="text-align:center;"><?= h($row['credits']) ?></td>
                    <td style="font-family:monospace;font-size:12px;"><?= h($row['section_code']) ?></td>
                    <td style="text-align:center;font-weight:600;"><?= $official ?><?php if ($row['cancelled_total'] > 0): ?><div style="font-size:10px;color:#94a3b8;font-weight:400;"><?= (int)$row['cancelled_total'] ?> buổi hủy</div><?php endif; ?></td>
                    <td style="text-align:center;"><?= (int)$row['completed_total'] ?></td>
                    <td style="text-align:center;color:#059669;font-weight:600;"><?= (int)$row['present'] ?></td>
                    <td style="text-align:center;color:#ef4444;font-weight:700;">
                        <?= $counted ?> / <?= $official ?>
                        <div style="font-size:10px;color:#94a3b8;font-weight:400;">
                            Vắng <?= (int)$row['absent'] ?><?= $row['late_counts'] ? ' · muộn '.$row['late'] : '' ?><?= $row['excused_counts'] ? ' · phép '.$row['excused'] : '' ?>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:6px;background:#e5eeff;border-radius:9999px;overflow:hidden;min-width:48px;">
                                <div style="height:100%;width:<?= min(100, $absPct) ?>%;background:<?= $color ?>;border-radius:9999px;"></div>
                            </div>
                            <span style="font-size:12px;font-weight:700;color:<?= $color ?>;white-space:nowrap;">
                                <?= $official > 0 ? number_format($absPct,1).'%' : '—' ?>
                            </span>
                        </div>
                        <?php if ($row['status'] === 'ineligible' || $row['status'] === 'at_risk' || (int)$row['risk_level'] === 2): ?>
                        <div style="font-size:10px;color:<?= $color ?>;margin-top:2px;max-width:230px;"><?= h($row['explanation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;color:<?= (int)$row['remaining_allowed'] === 0 && $official > 0 ? '#ef4444' : '#102b50' ?>;">
                        <?= $official > 0 ? (int)$row['remaining_allowed'] : '—' ?>
                    </td>
                    <td><span class="status-pill status-pill--<?= $riskClass ?>" title="<?= h($row['explanation']) ?>"><?= h($risk) ?></span></td>
                    <td style="text-align:center;">
                        <button type="button"
                                class="att-detail-btn"
                                data-section="<?= (int)$row['section_id'] ?>"
                                data-course="<?= h($row['course_code'] . ' – ' . $row['course_name']) ?>"
                                data-class="<?= h($row['section_code']) ?>">
                            Chi tiết
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Per-course attendance detail modal -->
<div id="attDetailModal" class="att-modal" aria-hidden="true">
    <div class="att-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="attModalTitle">
        <div class="att-modal__head">
            <div>
                <div class="att-modal__title" id="attModalTitle"></div>
                <div class="att-modal__sub" id="attModalSub"></div>
            </div>
            <button type="button" class="att-modal__close" id="attModalClose" aria-label="Đóng">✕</button>
        </div>
        <div class="att-modal__body">
            <div class="att-modal__empty" id="attModalEmpty" hidden>Chưa có dữ liệu điểm danh cho môn này.</div>
            <div class="data-table-wrap" id="attModalTableWrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ngày</th>
                            <th>Giờ</th>
                            <th>Môn học</th>
                            <th>Phòng</th>
                            <th>Chủ đề</th>
                            <th>Trạng thái</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody id="attModalRows"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
window.ATT_DETAILS = <?= json_encode($attDetails, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.ATT_STATUS_LABELS = <?= json_encode($statusLabel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.ATT_STATUS_CLASSES = <?= json_encode($statusClass, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

(function () {
    var modal = document.getElementById('attDetailModal');
    var titleEl = document.getElementById('attModalTitle');
    var subEl = document.getElementById('attModalSub');
    var rowsEl = document.getElementById('attModalRows');
    var emptyEl = document.getElementById('attModalEmpty');
    var tableWrap = document.getElementById('attModalTableWrap');
    var closeBtn = document.getElementById('attModalClose');
    var lastFocus = null;

    function cell(text, extraStyle) {
        var td = document.createElement('td');
        td.textContent = text;
        if (extraStyle) td.style.cssText = extraStyle;
        return td;
    }

    function openModal(btn) {
        var sectionId = String(btn.dataset.section || '');
        var rows = (window.ATT_DETAILS && window.ATT_DETAILS[sectionId]) || [];
        var labels = window.ATT_STATUS_LABELS || {};
        var classes = window.ATT_STATUS_CLASSES || {};

        titleEl.textContent = btn.dataset.course || 'Lịch sử điểm danh';
        var absentCount = rows.filter(function (r) { return r.status === 'Absent'; }).length;
        subEl.textContent = (btn.dataset.class ? 'Lớp ' + btn.dataset.class + ' · ' : '')
            + rows.length + ' buổi đã ghi nhận'
            + (absentCount > 0 ? ' · ' + absentCount + ' buổi vắng' : '');

        rowsEl.textContent = '';
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            if (r.status === 'Absent') tr.className = 'att-row--absent';

            var date = (r.session_date || '').split('-');
            tr.appendChild(cell(date.length === 3 ? date[2] + '/' + date[1] + '/' + date[0] : (r.session_date || '—'), 'white-space:nowrap;'));

            var start = (r.start_time || '').substring(0, 5);
            var end = (r.end_time || '').substring(0, 5);
            tr.appendChild(cell(start && end ? start + ' – ' + end : '—', 'white-space:nowrap;font-size:12px;'));

            var courseTd = document.createElement('td');
            var code = document.createElement('span');
            code.textContent = r.course_code || '';
            code.style.cssText = 'font-family:monospace;font-size:11px;background:#f1f5f9;padding:1px 5px;border-radius:3px;margin-right:4px;';
            courseTd.appendChild(code);
            courseTd.appendChild(document.createTextNode(r.course_name || ''));
            tr.appendChild(courseTd);

            tr.appendChild(cell(r.room || '—'));
            tr.appendChild(cell(r.topic || '—', 'font-size:12px;color:#44474e;max-width:200px;'));
            tr.appendChild(cell(r.note || '—', 'font-size:12px;color:#64748b;'));

            var statusTd = document.createElement('td');
            var pill = document.createElement('span');
            pill.className = 'status-pill status-pill--' + (classes[r.status] || 'absent');
            pill.textContent = labels[r.status] || r.status;
            statusTd.appendChild(pill);
            tr.insertBefore(statusTd, tr.lastChild);

            rowsEl.appendChild(tr);
        });

        emptyEl.hidden = rows.length > 0;
        tableWrap.style.display = rows.length > 0 ? '' : 'none';

        lastFocus = document.activeElement;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    document.querySelectorAll('.att-detail-btn').forEach(function (btn) {
        btn.addEventListener('click', function () { openModal(btn); });
    });
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
})();
</script>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
