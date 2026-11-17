<?php
/**
 * UniPortal – Student Dashboard
 * Figma source: node 1:2 (file 54fjXxHFXv2NH83qL9KXly)
 *
 * Path from web root: /UniPortal/public/assets/student/dashboard.php
 * This file is 3 levels deep from public/, so relative asset paths use ../../
 */

// ── Bootstrap ────────────────────────────────────────────────
$root = dirname(__DIR__, 3); // → /UniPortal
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/Gpa.php';

require_student(); // redirects to login if not a Student

// ── Pull session values ───────────────────────────────────────
$student_id      = $_SESSION['student_id']      ?? '';
$full_name       = $_SESSION['full_name']        ?? 'Sinh viên';

// ── Handle logout ─────────────────────────────────────────────
if (isset($_GET['logout'])) {
    auth_logout();
    header('Location: ' . app_url('index.php'));
    exit;
}

// ── Load data from DB ─────────────────────────────────────────
$currentSemester   = StudentModel::getCurrentSemester();
$semId             = $currentSemester['semester_id'] ?? 0;

$cumData           = GpaModel::getCumulative($student_id);
$cumulativeGpa     = $cumData['cumulative_gpa'];
$earnedCredits     = $cumData['earned_credits'];

$semesterGpa       = $semId ? GpaModel::getSemesterGpa($student_id, $semId) : null;

$gpaHistory        = GpaModel::getGpaHistory($student_id);

$warningCount      = StudentModel::getUnreadWarningCount($student_id);

// ── Derived display values ────────────────────────────────────
$totalCredits      = 130; // from Curriculum; seed shows 130 for all programs
$creditPct         = $totalCredits > 0 ? round($earnedCredits / $totalCredits * 100, 1) : 0;
$remainingCredits  = $totalCredits - $earnedCredits;

$classification    = GpaModel::getClassification($cumulativeGpa);

// GPA progress toward "Giỏi" target (3.20 / 4.00 scale = 80%)
$cumGpaPct         = $cumulativeGpa !== null ? round(($cumulativeGpa / 4.0) * 100, 1) : 0;
$semGpaPct         = $semesterGpa   !== null ? round(($semesterGpa   / 4.0) * 100, 1) : 0;

// Build chart data for JS  (per-semester GPA history)
$chartLabels       = [];
$chartSemGpa       = [];
$chartCumGpa       = [];
$runningCredits    = 0;
$runningPoints     = 0;
foreach ($gpaHistory as $row) {
    // Short label e.g. "HK1 23-24"
    $yr    = $row['academic_year'] ?? '';
    $yrLbl = preg_match('/^\d{4}-\d{4}$/', $yr) ? substr($yr, 2, 2) . '-' . substr($yr, -2) : $yr;
    $short = preg_replace('/^Học kỳ\s*/i', 'HK', $row['semester_name'] ?? '') . ' ' . $yrLbl;
    $chartLabels[]  = $short;
    $chartSemGpa[]  = (float)($row['semester_gpa'] ?? 0);
    $runningCredits += (int)($row['credits_this_sem'] ?? 0);
    $runningPoints  += (float)($row['semester_gpa'] ?? 0) * (int)($row['credits_this_sem'] ?? 1);
    $cumAtPoint      = $runningCredits > 0 ? round($runningPoints / $runningCredits, 2) : 0;
    $chartCumGpa[]   = $cumAtPoint;
}

// Escape for JS
$jsLabels   = json_encode($chartLabels,  JSON_UNESCAPED_UNICODE);
$jsSemGpa   = json_encode($chartSemGpa);
$jsCumGpa   = json_encode($chartCumGpa);

// Helpers for safe HTML output
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function gpa_fmt(mixed $v): string { return $v !== null ? number_format((float)$v, 2) : '—'; }

// Asset base path (relative from this file's location → ../../assets/)
$IMG = '../../assets/img/';

// Reuse the shared student shell so the dashboard stays in sync with every other page
$pageTitle  = 'Tổng quan';
$activePage = 'dashboard';
$imgBase    = $IMG;
$assetBase  = '../../assets/';
$warningCnt = $warningCount;
require __DIR__ . '/../partials/layout.php';
?>

    <!-- ═══════════════════════════════
         A. ACADEMIC KPI CARDS
         ═══════════════════════════════ -->
    <div class="kpi-row" role="region" aria-label="Chỉ số học tập"
         style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">

        <!-- Card 1: Cumulative GPA -->
        <div class="card kpi-card">
            <div class="kpi-card__header">
                <span class="kpi-card__label">GPA Tích lũy</span>
                <span class="kpi-card__badge kpi-card__badge--green">
                    <?= h($classification) ?>
                </span>
            </div>
            <div class="kpi-card__value">
                <span class="kpi-card__num"><?= gpa_fmt($cumulativeGpa) ?></span>
                <span class="kpi-card__unit">/ 4.00</span>
            </div>
            <div class="kpi-card__footer">
                <div class="progress-bar" role="progressbar"
                     aria-valuenow="<?= $cumGpaPct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar__fill progress-bar__fill--green"
                         style="width:<?= $cumGpaPct ?>%"></div>
                </div>
                <div class="kpi-card__meta">
                    <img src="<?= $IMG ?>db-icon-gpa-card.svg" alt="" />
                    Mục tiêu Giỏi: 3.20 / 4.00
                </div>
            </div>
        </div>

        <!-- Card 2: Current semester GPA -->
        <div class="card kpi-card">
            <div class="kpi-card__header">
                <span class="kpi-card__label">GPA Kỳ hiện tại</span>
                <span class="kpi-card__badge <?= $semesterGpa !== null ? 'kpi-card__badge--green' : 'kpi-card__badge--muted' ?>">
                    <?= $semesterGpa !== null ? GpaModel::getClassification($semesterGpa) : '—' ?>
                </span>
            </div>
            <div class="kpi-card__value">
                <span class="kpi-card__num"><?= gpa_fmt($semesterGpa) ?></span>
                <span class="kpi-card__unit">/ 4.00</span>
            </div>
            <div class="kpi-card__footer">
                <div class="progress-bar" role="progressbar"
                     aria-valuenow="<?= $semGpaPct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar__fill progress-bar__fill--green"
                         style="width:<?= $semGpaPct ?>%"></div>
                </div>
                <div class="kpi-card__meta">
                    <img src="<?= $IMG ?>db-icon-semester-gpa.svg" alt="" />
                    <?= $currentSemester ? h($currentSemester['semester_name']) : 'Học kỳ hiện tại' ?>
                </div>
            </div>
        </div>

        <!-- Card 3: Accumulated credits -->
        <div class="card kpi-card">
            <div class="kpi-card__header">
                <span class="kpi-card__label">Tín chỉ tích lũy</span>
                <span class="kpi-card__badge kpi-card__badge--blue">
                    <?= $remainingCredits > 0 ? 'Còn ' . $remainingCredits . ' TC' : 'Đủ điều kiện' ?>
                </span>
            </div>
            <div class="kpi-card__value">
                <span class="kpi-card__num"><?= h($earnedCredits) ?></span>
                <span class="kpi-card__unit">/ <?= $totalCredits ?> tín chỉ</span>
            </div>
            <div class="kpi-card__footer">
                <div class="progress-bar" role="progressbar"
                     aria-valuenow="<?= $creditPct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar__fill progress-bar__fill--navy"
                         style="width:<?= $creditPct ?>%"></div>
                </div>
            </div>
        </div>

    </div><!-- /.kpi-row -->

    <!-- ═══════════════════════════════
         C. GPA CHART + DEGREE PROGRESS
         ═══════════════════════════════ -->
    <div class="row-2" role="region" aria-label="Biểu đồ GPA và tiến độ học tập">

        <!-- GPA Trend Chart -->
        <div class="card gpa-chart-card">
            <div class="gpa-chart-card__header">
                <div class="gpa-chart-card__title">Diễn biến GPA qua các học kỳ</div>
                <div class="chart-tabs" role="group" aria-label="Chọn loại GPA">
                    <button class="chart-tab active" data-chart="semester" id="tabSemGpa">
                        GPA học kỳ
                    </button>
                    <button class="chart-tab" data-chart="cumulative" id="tabCumGpa">
                        GPA tích lũy
                    </button>
                </div>
            </div>
            <div class="chart-canvas-wrap">
                <?php if (empty($gpaHistory)): ?>
                <p class="no-data" style="padding:48px 0;text-align:center;">
                    Chưa có dữ liệu GPA từ các học kỳ trước.
                </p>
                <?php else: ?>
                <canvas id="gpaChartCanvas" role="img"
                        aria-label="Biểu đồ GPA qua các học kỳ"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Degree Progress -->
        <div class="card degree-card" role="region" aria-label="Tiến độ tốt nghiệp">
            <div>
                <div class="degree-card__title">Tiến độ học tập</div>
            </div>
            <div class="degree-card__donut">
                <div class="donut-wrap">
                    <canvas id="degreeDonutCanvas" role="img"
                            aria-label="Biểu đồ tiến độ tín chỉ"></canvas>
                    <div class="donut-center">
                        <div class="donut-center__num"><?= $earnedCredits ?></div>
                        <div class="donut-center__unit">/ <?= $totalCredits ?> TC</div>
                    </div>
                </div>
                <div class="degree-legend">
                    <div class="degree-legend__item">
                        <span class="degree-legend__left">
                            <span class="degree-legend__dot" style="background:#102b50;"></span>
                            Đã tích lũy
                        </span>
                        <span class="degree-legend__right"><?= $earnedCredits ?> TC</span>
                    </div>
                    <div class="degree-legend__item">
                        <span class="degree-legend__left">
                            <span class="degree-legend__dot" style="background:#e5eeff;"></span>
                            Còn lại
                        </span>
                        <span class="degree-legend__right"><?= $remainingCredits ?> TC</span>
                    </div>
                    <div class="degree-legend__item" style="margin-top:4px;border-top:1px solid #f1f5f9;padding-top:8px;">
                        <span class="degree-legend__left">Phân loại dự kiến</span>
                        <span class="degree-legend__right"><?= h($classification) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.row-2 -->

</div><!-- /.db-content -->
</main>

<!-- ═══════════════════════════════════════════════════════════
     SCRIPTS
     ═══════════════════════════════════════════════════════════ -->
<!-- Chart.js from CDN (no install required) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"
        integrity="sha256-CN+kcwVxsjgQw0/DnFEBRh7K/KVsP5LK9IUFCcsVjzA="
        crossorigin="anonymous"></script>

<!-- PHP → JS data bridge -->
<script>
window.UNIPORTAL = {
    chartLabels : <?= $jsLabels ?>,
    chartSemGpa : <?= $jsSemGpa ?>,
    chartCumGpa : <?= $jsCumGpa ?>,
    earnedCredits : <?= (int)$earnedCredits ?>,
    totalCredits  : <?= (int)$totalCredits  ?>,
    imgBase       : '../../assets/img/'
};
</script>

<script src="../../assets/js/dashboard.js"></script>
</body>
</html>
