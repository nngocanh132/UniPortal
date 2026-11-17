<?php
/**
 * UniPortal – Kết quả học tập (GPA / Grades)
 * Figma frame: KQHT (1:479)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/Gpa.php';

require_student();

if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$student_id = $_SESSION['student_id'] ?? '';
$full_name  = $_SESSION['full_name']  ?? '';
$class_code = $_SESSION['class_code'] ?? '';

// Current semester
$currentSem = StudentModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);

$pdo = get_db();

// Semester filter: 0 = show every studied semester (default).
$semFilter = isset($_GET['sem']) ? max(0, (int)$_GET['sem']) : 0;
$selSemId  = $semFilter ?: $semId; // semester shown in the KPI card

// Cumulative data
$cumData       = GpaModel::getCumulative($student_id);
$cumulativeGpa = $cumData['cumulative_gpa'];
$earnedCredits = $cumData['earned_credits'];
$classification = GpaModel::getClassification($cumulativeGpa);

// Semesters the student actually studied (completed + current), newest first.
$studiedStmt = $pdo->prepare(
    "SELECT DISTINCT s.semester_id, s.semester_name, s.academic_year, s.start_date
     FROM Semester s
     JOIN ClassSection cs ON cs.semester_id = s.semester_id
     JOIN Enrollment e    ON e.section_id   = cs.section_id
     WHERE e.student_id = :sid
       AND e.status IN ('Enrolled','Completed')
     ORDER BY s.start_date DESC, s.semester_id DESC"
);
$studiedStmt->execute([':sid' => $student_id]);
$semesterOptions = $studiedStmt->fetchAll();

// Keep the current semester visible even if it has no enrollment yet.
if ($semId && !in_array($semId, array_map('intval', array_column($semesterOptions, 'semester_id')), true)) {
    array_unshift($semesterOptions, [
        'semester_id'   => $semId,
        'semester_name' => $currentSem['semester_name'] ?? 'Học kỳ hiện tại',
        'academic_year' => $currentSem['academic_year'] ?? '',
        'start_date'    => $currentSem['start_date'] ?? null,
    ]);
}

// Grades for selected semester (detailed)
function getGradesForSemester(string $student_id, int $semester_id): array {
    if (!$semester_id) return [];
    $pdo  = get_db();
    // Fix: aggregate functions cannot appear in JOIN ON clauses (MySQL error 1111).
    // Two-step approach:
    //   Step 1 – compute the weighted score per enrollment (aggregated subquery).
    //   Step 2 – join GradeScale on the pre-computed score in the outer query.
    $stmt = $pdo->prepare(
        'SELECT
            base.course_code,
            base.course_name,
            base.credits,
            base.enroll_status,
            base.enrollment_id,
            base.section_code,
            base.lecturer_name,
            base.weighted_score,
            gs.letter_grade,
            gs.grade_point
         FROM (
             SELECT
                 c.course_code,
                 c.course_name,
                 c.credits,
                 e.status        AS enroll_status,
                 e.enrollment_id,
                 cs.section_code,
                 l.full_name     AS lecturer_name,
                 ROUND(
                     SUM(g.score * a.weight_percent / 100) / 10,
                     1
                 )               AS weighted_score
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id   = e.section_id
             JOIN Course c        ON c.course_id     = cs.course_id
             JOIN Lecturer l      ON l.lecturer_id   = cs.lecturer_id
             LEFT JOIN Grade g    ON g.enrollment_id = e.enrollment_id
             LEFT JOIN Assessment a ON a.assessment_id = g.assessment_id
             WHERE e.student_id = :sid AND cs.semester_id = :sem
             GROUP BY
                 e.enrollment_id,
                 c.course_code, c.course_name, c.credits,
                 e.status, cs.section_code, l.full_name
         ) AS base
         LEFT JOIN GradeScale gs
             ON base.weighted_score IS NOT NULL
            AND base.weighted_score >= gs.min_score
            AND (base.weighted_score <  gs.max_score
                 OR gs.max_score = 10.0)
         ORDER BY base.course_code ASC'
    );
    $stmt->execute([':sid' => $student_id, ':sem' => $semester_id]);
    return $stmt->fetchAll();
}

$semGpa = $selSemId ? GpaModel::getSemesterGpa($student_id, $selSemId) : null;

// Academic results for every studied semester, most recent first.
$visibleSemesters = $semFilter
    ? array_values(array_filter($semesterOptions, fn($s) => (int)$s['semester_id'] === $semFilter))
    : $semesterOptions;
if ($semFilter && !$visibleSemesters) $visibleSemesters = $semesterOptions;

$semesterResults = [];
foreach ($visibleSemesters as $s) {
    $rowSemId  = (int)$s['semester_id'];
    $semGrades = getGradesForSemester($student_id, $rowSemId);
    $semCredits = 0;
    foreach ($semGrades as $g) $semCredits += (int)$g['credits'];
    $semesterResults[] = [
        'semester' => $s,
        'grades'   => $semGrades,
        'credits'  => $semCredits,
        'gpa'      => GpaModel::getSemesterGpa($student_id, $rowSemId),
    ];
}

$kpiSemName = $currentSem['semester_name'] ?? '—';
foreach ($semesterOptions as $s) {
    if ((int)$s['semester_id'] === $selSemId) {
        $kpiSemName = trim(($s['semester_name'] ?? '') . ' ' . ($s['academic_year'] ?? ''));
        break;
    }
}

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function gf($v):string{ return $v !== null ? number_format((float)$v,2) : '—'; }

function gradeBadgeClass(?string $letter):string {
    if (!$letter) return '';
    $l = strtoupper($letter[0]);
    return match($l) { 'A' => 'grade-badge--A', 'B' => 'grade-badge--B',
                        'C' => 'grade-badge--C', 'D' => 'grade-badge--D',
                        'F' => 'grade-badge--F', default => '' };
}

$pageTitle  = 'Kết quả học tập';
$activePage = 'gpa';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Summary KPI cards -->
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr);">
    <div class="card kpi-card">
        <div class="kpi-card__header">
            <span class="kpi-card__label">GPA Tích lũy</span>
            <span class="kpi-card__badge kpi-card__badge--green"><?= h($classification) ?></span>
        </div>
        <div class="kpi-card__value">
            <span class="kpi-card__num"><?= gf($cumulativeGpa) ?></span>
            <span class="kpi-card__unit">/ 4.00</span>
        </div>
        <div class="kpi-card__footer">
            <div class="progress-bar">
                <div class="progress-bar__fill progress-bar__fill--green"
                     style="width:<?= $cumulativeGpa !== null ? round($cumulativeGpa/4*100,1) : 0 ?>%"></div>
            </div>
            <div class="kpi-card__meta">Mục tiêu tốt nghiệp Giỏi: ≥ 3.20</div>
        </div>
    </div>
    <div class="card kpi-card">
        <div class="kpi-card__header">
            <span class="kpi-card__label">GPA học kỳ <?= $selSemId && $selSemId !== $semId ? 'đã chọn' : 'hiện tại' ?></span>
            <span class="kpi-card__badge <?= $semGpa !== null ? 'kpi-card__badge--blue' : 'kpi-card__badge--muted' ?>"><?= $semGpa !== null ? GpaModel::getClassification($semGpa) : '—' ?></span>
        </div>
        <div class="kpi-card__value">
            <span class="kpi-card__num"><?= gf($semGpa) ?></span>
            <span class="kpi-card__unit">/ 4.00</span>
        </div>
        <div class="kpi-card__footer">
            <div class="progress-bar">
                <div class="progress-bar__fill progress-bar__fill--green"
                     style="width:<?= $semGpa !== null ? round($semGpa/4*100,1) : 0 ?>%"></div>
            </div>
            <div class="kpi-card__meta"><?= h($kpiSemName) ?></div>
        </div>
    </div>
    <div class="card kpi-card">
        <div class="kpi-card__header">
            <span class="kpi-card__label">Tín chỉ tích lũy</span>
            <span class="kpi-card__badge kpi-card__badge--blue"><?= $earnedCredits ?> / 130 TC</span>
        </div>
        <div class="kpi-card__value">
            <span class="kpi-card__num"><?= $earnedCredits ?></span>
            <span class="kpi-card__unit">tín chỉ</span>
        </div>
        <div class="kpi-card__footer">
            <div class="progress-bar">
                <div class="progress-bar__fill progress-bar__fill--navy"
                     style="width:<?= round($earnedCredits/130*100,1) ?>%"></div>
            </div>
            <div class="kpi-card__meta">Còn <?= 130 - $earnedCredits ?> TC để tốt nghiệp</div>
        </div>
    </div>
</div>

<!-- Filter ribbon -->
<div class="filter-ribbon">
    <div class="filter-ribbon__search">
        <img class="si" src="<?= $imgBase ?>icon-search.svg" alt="" />
        <input type="search" id="gradeSearch" placeholder="Tìm môn học..." />
    </div>
    <div class="filter-ribbon__right">
        <span style="font-size:13px;font-weight:600;color:#44474e;">Học kỳ:</span>
        <form method="GET" style="display:flex;align-items:center;gap:8px;">
            <select name="sem" onchange="this.form.submit()">
                <option value="0" <?= $semFilter === 0 ? 'selected' : '' ?>>Tất cả học kỳ</option>
                <?php foreach ($semesterOptions as $s): ?>
                <option value="<?= h($s['semester_id']) ?>"
                    <?= (int)$s['semester_id'] === $semFilter ? 'selected' : '' ?>>
                    <?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Academic results per semester, newest first -->
<?php if (empty($semesterResults)): ?>
<div class="card" style="padding:40px;text-align:center;color:#94a3b8;font-style:italic;">
    Chưa có dữ liệu kết quả học tập.
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:16px;">
    <?php foreach ($semesterResults as $result):
        $semRow      = $result['semester'];
        $isCurrent   = (int)$semRow['semester_id'] === $semId;
        $semGrades   = $result['grades'];
    ?>
    <div class="card grades-section" data-semester="<?= (int)$semRow['semester_id'] ?>" style="padding:0;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid #eef2f7;background:#f8fafc;">
            <div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:15px;font-weight:700;color:#001634;">
                    <?= h($semRow['semester_name']) ?>
                    <span style="font-size:12px;font-weight:600;color:#64748b;"><?= h($semRow['academic_year']) ?></span>
                    <?php if ($isCurrent): ?>
                    <span class="status-pill status-pill--enrolled" style="font-size:10px;">Học kỳ hiện tại</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">
                    <?= count($semGrades) ?> môn học · <?= (int)$result['credits'] ?> tín chỉ
                </div>
            </div>
            <span style="font-size:12px;font-weight:700;color:#102b50;background:#eff6ff;padding:5px 10px;border-radius:14px;">
                GPA học kỳ: <?= $result['gpa'] !== null ? number_format((float)$result['gpa'], 2) . ' / 4.00' : '—' ?>
            </span>
        </div>
        <div class="data-table-wrap">
            <table class="data-table grades-table">
                <thead>
                    <tr>
                        <th>Mã môn</th>
                        <th>Tên môn học</th>
                        <th>TC</th>
                        <th>Điểm tổng kết (10)</th>
                        <th>Điểm chữ</th>
                        <th>Điểm hệ 4</th>
                        <th>Giảng viên</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($semGrades)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                        Không có dữ liệu điểm cho học kỳ này.
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($semGrades as $g): ?>
                    <tr data-grade-row>
                        <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= h($g['course_code']) ?></span></td>
                        <td style="font-weight:600;color:#001634;max-width:220px;"><?= h($g['course_name']) ?></td>
                        <td style="text-align:center;"><?= h($g['credits']) ?></td>
                        <td style="text-align:center;font-weight:600;">
                            <?= $g['weighted_score'] !== null ? number_format((float)$g['weighted_score'],1) : '<span style="color:#94a3b8;">—</span>' ?>
                        </td>
                        <td style="text-align:center;">
                            <?php $ltr = $g['letter_grade'] ?? null; ?>
                            <?php if ($ltr): ?>
                            <span class="grade-badge <?= gradeBadgeClass($ltr) ?>"><?= h($ltr) ?></span>
                            <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;font-weight:600;"><?= $g['grade_point'] !== null ? number_format((float)$g['grade_point'],1) : '<span style="color:#94a3b8;">—</span>' ?></td>
                        <td style="font-size:12px;color:#44474e;"><?= h($g['lecturer_name']) ?></td>
                        <td>
                            <?php $st = strtolower($g['enroll_status'] ?? ''); ?>
                            <span class="status-pill status-pill--<?= $st === 'completed' ? 'complete' : ($st === 'enrolled' ? 'enrolled' : 'absent') ?>">
                                <?= $g['enroll_status'] === 'Completed' ? 'Hoàn thành' : ($g['enroll_status'] === 'Enrolled' ? 'Đang học' : h($g['enroll_status'])) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div></main>

<script src="../../assets/js/dashboard.js"></script>
<script>
// Client-side course search across every semester table
(function () {
    var input = document.getElementById('gradeSearch');
    if (!input) return;
    input.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        document.querySelectorAll('.grades-section').forEach(function (section) {
            var visible = 0;
            section.querySelectorAll('tr[data-grade-row]').forEach(function (tr) {
                var show = q === '' || tr.textContent.toLowerCase().includes(q);
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            section.style.display = (q === '' || visible > 0) ? '' : 'none';
        });
    });
})();
</script>
</body></html>
