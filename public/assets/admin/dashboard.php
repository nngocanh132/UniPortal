<?php
/**
 * UniPortal – Admin Dashboard (Tổng quan)
 * Figma: dashboard screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';

require_login();
if ($_SESSION['role'] === 'Student') { header('Location: ' . app_url('assets/student/dashboard.php')); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: ' . app_url('assets/lecturer/dashboard.php')); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo = get_db();

// ── Real DB stats ─────────────────────────────────────────────
$totalStudents  = (int)$pdo->query("SELECT COUNT(*) FROM Student WHERE status='Active'")->fetchColumn();
$totalAccounts  = (int)$pdo->query("SELECT COUNT(*) FROM Account WHERE is_active=TRUE")->fetchColumn();
$activeWarnings = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active'")->fetchColumn();
$unreadWarnings = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$currentSem     = StudentModel::getCurrentSemester();

// GPA distribution from real grade data
$gpaSt = $pdo->prepare(
    'SELECT
        ROUND(SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits),0), 2) AS cum_gpa,
        s.student_id
     FROM Student s
     JOIN Enrollment e  ON e.student_id  = s.student_id AND e.status IN (\'Enrolled\',\'Completed\')
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Course c      ON c.course_id    = cs.course_id
     JOIN Assessment a  ON a.section_id   = cs.section_id
     JOIN Grade g       ON g.enrollment_id = e.enrollment_id AND g.assessment_id = a.assessment_id
     JOIN GradeScale gs ON (g.score/10) BETWEEN gs.min_score AND gs.max_score
     GROUP BY s.student_id'
);
$gpaSt->execute();
$gpaRows = $gpaSt->fetchAll();

$totalGpa = 0; $gpaCount = 0;
foreach ($gpaRows as $r) {
    $totalGpa += (float)$r['cum_gpa'];
    $gpaCount++;
}
$avgGpa = $gpaCount > 0 ? round($totalGpa / $gpaCount, 2) : null;

// GPA trend – real semester GPAs
$trendSt = $pdo->prepare(
    'SELECT sem.semester_name, sem.academic_year,
            ROUND(SUM(gs.grade_point*c.credits)/NULLIF(SUM(c.credits),0),2) AS gpa
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Semester sem ON sem.semester_id = cs.semester_id
     JOIN Course c ON c.course_id = cs.course_id
     JOIN Assessment a ON a.section_id = cs.section_id
     JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
     JOIN GradeScale gs ON (g.score/10) BETWEEN gs.min_score AND gs.max_score
     WHERE e.status IN (\'Enrolled\',\'Completed\')
     GROUP BY sem.semester_id ORDER BY sem.start_date ASC LIMIT 5'
);
$trendSt->execute();
$trendRows = $trendSt->fetchAll();
$trendLabels = [];
$trendVals = [];
foreach ($trendRows as $t) {
    $ay = (string)($t['academic_year'] ?? '');
    $ayShort = preg_match('/^\d{4}-\d{4}$/', $ay) ? substr($ay, 2, 2) . '-' . substr($ay, -2) : $ay;
    $trendLabels[] = preg_replace('/^Học kỳ\s*/i','HK',$t['semester_name']??'').' '.$ayShort;
    $trendVals[]   = (float)$t['gpa'];
}

$jsLabels = json_encode($trendLabels, JSON_UNESCAPED_UNICODE);
$jsVals   = json_encode($trendVals);

// Keep the y-axis snug around the actual data
$yMin = 0.0;
$yMax = 4.0;
if (!empty($trendVals)) {
    $yMin = max(0, floor((min($trendVals) - 0.15) * 10) / 10);
    $yMax = min(4, ceil((max($trendVals) + 0.15) * 10) / 10);
    if ($yMax - $yMin < 0.6) { $yMax = min(4, $yMin + 0.6); }
}

$warnCount = $unreadWarnings;

$pageTitle   = 'Tổng quan';
$activePage  = 'dashboard';
$breadcrumb  = 'Quản trị › Học vụ & Sinh viên';
require __DIR__ . '/_layout.php';
?>

<!-- ═══ 3 KPI TOP CARDS ══════════════════════════════════════ -->
<div class="adm-dash-kpis">

    <!-- Tổng số sinh viên -->
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tổng số sinh viên</span>
            <span class="adm-kpi__icon adm-kpi__icon--blue">
                <img src="../img/db-nav-profile.svg" alt="" />
            </span>
        </div>
        <div class="adm-kpi__value">
            <span class="adm-kpi__num"><?= number_format($totalStudents) ?></span>
            <span class="adm-kpi__unit">SV</span>
        </div>
        <div class="adm-kpi__sub"><?= number_format($totalAccounts) ?> tài khoản đang hoạt động</div>
    </div>

    <!-- GPA trung bình trường -->
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">GPA trung bình trường</span>
            <span class="adm-kpi__icon adm-kpi__icon--green">
                <img src="../img/db-nav-grades.svg" alt="" />
            </span>
        </div>
        <div class="adm-kpi__value">
            <span class="adm-kpi__num"><?= $avgGpa !== null ? number_format($avgGpa,2) : '—' ?></span>
            <span class="adm-kpi__unit">/ 4.0</span>
        </div>
        <div class="adm-kpi__sub"><?= number_format($gpaCount) ?> sinh viên đã có điểm</div>
    </div>

    <!-- Cảnh báo học tập -->
    <div class="acard adm-kpi" style="border-left: 4px solid #ef4444;">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Cảnh báo học tập</span>
            <span class="adm-kpi__icon adm-kpi__icon--red">
                <img src="../img/db-nav-alerts.svg" alt="" />
            </span>
        </div>
        <div class="adm-kpi__value">
            <span class="adm-kpi__num adm-kpi__num--red"><?= $activeWarnings ?></span>
            <span class="adm-kpi__unit">hồ sơ</span>
        </div>
        <div class="adm-kpi__sub-row">
            <span class="adm-kpi__sub-item" style="color:#f59e0b;"><?= $unreadWarnings ?> chưa đọc</span>
            <span class="adm-kpi__sub-item"><?= $activeWarnings - $unreadWarnings ?> đã đọc</span>
        </div>
    </div>
</div>

<!-- ═══ GPA TREND ════════════════════════════════════════════ -->
<div class="adm-dash-lower" style="grid-template-columns:1fr;">

    <!-- Diễn biến GPA theo học kỳ -->
    <div class="acard" style="padding:18px 20px;">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
            <span style="width:10px;height:10px;border-radius:50%;background:#fdcc35;display:inline-block;"></span>
            <span class="adm-section-title">Diễn biến GPA theo học kỳ</span>
        </div>
        <?php if (!empty($trendRows)): ?>
        <div class="adm-section-sub" style="margin-bottom:14px;">
            Giai đoạn từ <?= h($trendLabels[0]) ?> đến <?= h(end($trendLabels)) ?>
        </div>
        <div style="position:relative;height:170px;">
            <canvas id="trendChart"></canvas>
        </div>
        <?php else: ?>
        <div class="adm-empty">
            <div class="adm-empty__icon">📈</div>
            <p>Chưa có dữ liệu điểm để dựng biểu đồ GPA.</p>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php if (!empty($trendRows)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
Chart.defaults.font.family = "'Inter', 'Be Vietnam Pro', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#64748b';
// GPA Trend
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= $jsLabels ?>,
        datasets: [{
            data: <?= $jsVals ?>,
            borderColor: '#fdcc35',
            backgroundColor: 'rgba(253,204,53,0.12)',
            pointBackgroundColor: '#102b50',
            pointRadius: 5,
            pointHoverRadius: 7,
            borderWidth: 2.5,
            tension: 0.35,
            fill: true,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        layout: { padding: { top: 6, right: 4 } },
        plugins: { legend: { display: false },
            tooltip: { callbacks: { label: ctx => ' GPA: ' + ctx.parsed.y.toFixed(2) } }
        },
        scales: {
            y: { min: <?= $yMin ?>, max: <?= $yMax ?>,
                 ticks: { font: { size: 10 }, color: '#94a3b8', stepSize: 0.2 },
                 grid: { color: 'rgba(0,0,0,.04)' } },
            x: { ticks: { font: { size: 10 }, color: '#94a3b8' }, grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
