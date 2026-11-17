<?php
/**
 * UniPortal – Lecturer Dashboard (Tổng quan)
 * Figma: dashboard screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId    = (int)($_SESSION['lecturer_id'] ?? 0);
$lecName  = $_SESSION['full_name'] ?? 'Giảng viên';

// ── Data ──────────────────────────────────────────────────────
$pdo        = get_db();
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);

$sections     = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$todaySess    = LecturerModel::getTodaySessions($lecId);
$warnCount    = LecturerModel::getUnreadWarningCount($lecId);
$pendingStats = LecturerModel::getPendingGradingStats($lecId);

// KPI counts
$totalSections  = count($sections);
$pendingGrades  = (int)($pendingStats['pending_grades'] ?? 0);
$pendingSubmits = $pdo->prepare(
    'SELECT COUNT(*) FROM Assessment a JOIN ClassSection cs ON cs.section_id=a.section_id
     WHERE cs.lecturer_id=:lid AND a.due_date > NOW()'
);
$pendingSubmits->execute([':lid' => $lecId]);
$submitsOpen = (int)$pendingSubmits->fetchColumn();

$pageTitle  = 'Tổng quan';
$activePage = 'dashboard';
require __DIR__ . '/_layout.php';

function fmt_time(string $t): string { return substr($t, 0, 5); }
?>

<!-- ═══════════ QUICK ACTION BUTTONS (top) ═══════════════════ -->
<div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="assignments.php" class="lbtn lbtn--primary">
        ✏️ Tạo bài tập mới
    </a>
    <a href="materials.php" class="lbtn lbtn--outline">
        📁 Tải tài liệu lên
    </a>
    <a href="grading.php" class="lbtn lbtn--yellow">
        ✅ Điểm danh ca hôm nay
    </a>
    <?php if ($warnCount > 0): ?>
    <a href="warnings.php" class="lbtn lbtn--outline" style="color:#ef4444;border-color:#fca5a5;">
        ⚠️ Xem cảnh báo học tập
    </a>
    <?php endif; ?>
</div>

<!-- ═══════════ 4 KPI CARDS ═══════════════════════════════════ -->
<div class="lec-kpi-row">

    <!-- Buổi dạy hôm nay -->
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Buổi dạy hôm nay</span>
            <span class="lec-kpi__icon lec-kpi__icon--yellow">
                <img src="../img/db-icon-schedule-time.svg" alt="" />
            </span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num"><?= count($todaySess) ?></span>
            <span class="lec-kpi__unit">ca giảng</span>
        </div>
        <div class="lec-kpi__sub">
            <?php if (!empty($todaySess)):
                $first = $todaySess[0];
                $last  = end($todaySess);
                echo 'Sáng ' . fmt_time($first['start_time']) . ' • Chiều ' . fmt_time($last['start_time']);
            else: ?>
            Không có buổi dạy hôm nay
            <?php endif; ?>
        </div>
        <?php if (!empty($todaySess)): ?>
        <div class="lec-kpi__sub" style="margin-top:2px;">
            <a href="schedule.php">Xem lịch giảng dạy</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bài tập đang mở -->
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Bài tập đang mở</span>
            <span class="lec-kpi__icon lec-kpi__icon--blue">
                <img src="../img/db-nav-grades.svg" alt="" />
            </span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num"><?= $submitsOpen ?></span>
            <span class="lec-kpi__unit">chủ đề</span>
        </div>
        <div class="lec-kpi__sub">
            <?= $totalSections ?> lớp học phần
        </div>
        <div class="lec-kpi__sub">
            <a href="assignments.php">Xem bài tập &amp; hạn nộp</a>
        </div>
    </div>

    <!-- Bài nộp cho chấm -->
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Bài nộp cho chấm</span>
            <span class="lec-kpi__icon lec-kpi__icon--green">
                <img src="../img/db-icon-gpa-card.svg" alt="" />
            </span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num"><?= $pendingGrades ?></span>
            <span class="lec-kpi__unit">bài nộp</span>
        </div>
        <div class="lec-kpi__sub">
            <a href="assignments.php">Mở danh sách chấm bài</a>
        </div>
    </div>

    <!-- Cần can thiệp -->
    <div class="lcard lec-kpi" style="<?= $warnCount > 0 ? 'border-left:4px solid #ef4444;' : '' ?>">
        <div class="lec-kpi__top">
            <span class="lec-kpi__label">Cần can thiệp</span>
            <span class="lec-kpi__icon lec-kpi__icon--red">
                <img src="../img/db-nav-alerts.svg" alt="" />
            </span>
        </div>
        <div class="lec-kpi__value">
            <span class="lec-kpi__num" style="color:#ef4444;"><?= $warnCount ?></span>
            <span class="lec-kpi__unit">cảnh báo</span>
        </div>
        <div class="lec-kpi__sub">
            <?= $warnCount > 0 ? $warnCount . ' cảnh báo chưa xử lý' : 'Không có cảnh báo mới' ?>
        </div>
        <?php if ($warnCount > 0): ?>
        <div class="lec-kpi__sub" style="color:#ef4444;font-weight:700;">
            <a href="warnings.php" style="color:#ef4444;">Cần can thiệp sớm →</a>
        </div>
        <?php endif; ?>
    </div>

</div>


<?php require __DIR__ . '/_layout_end.php'; ?>
