<?php
/**
 * UniPortal – Cảnh báo học tập (Academic Alerts)
 * Figma frame: warning (3:2296)
 *
 * Shows only warnings that belong to the signed-in student. Severity comes
 * from AcademicWarning.warning_level (1 = Nhắc nhở, 2 = Cần chú ý,
 * 3 = Nguy cơ cao). Messages are produced by AcademicAlertService with a
 * headline, factual details and a recommended action line.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$student_id = $_SESSION['student_id'] ?? '';
$full_name  = $_SESSION['full_name']  ?? '';

// Handle mark-as-read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'], $_POST['warning_id'])) {
    csrf_verify();
    $wid = (int)$_POST['warning_id'];
    $pdo = get_db();
    $stmt = $pdo->prepare('UPDATE AcademicWarning SET is_read = TRUE WHERE warning_id = :wid AND student_id = :sid');
    $stmt->execute([':wid' => $wid, ':sid' => $student_id]);
    header('Location: alerts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    csrf_verify();
    $pdo = get_db();
    $pdo->prepare('UPDATE AcademicWarning SET is_read = TRUE WHERE student_id = :sid')
        ->execute([':sid' => $student_id]);
    header('Location: alerts.php');
    exit;
}

$pdo = get_db();

$stmt = $pdo->prepare(
    'SELECT aw.warning_id, aw.warning_type, aw.warning_level, aw.message,
            aw.created_at, aw.is_read, aw.status, aw.section_id,
            c.course_code, c.course_name, cs.section_code
     FROM AcademicWarning aw
     LEFT JOIN ClassSection cs ON cs.section_id = aw.section_id
     LEFT JOIN Course c ON c.course_id = cs.course_id
     WHERE aw.student_id = :sid
     ORDER BY aw.is_read ASC, aw.warning_level DESC, aw.created_at DESC'
);
$stmt->execute([':sid' => $student_id]);
$warnings = $stmt->fetchAll();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function splitWarning(string $message, ?string $courseTitle): array {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $message)), fn($l) => $l !== ''));
    $action = '';
    $rest = [];
    foreach ($lines as $line) {
        if (mb_stripos($line, 'Đề xuất:') === 0) { $action = trim(mb_substr($line, 8)); continue; }
        $rest[] = $line;
    }
    if ($courseTitle !== null) {
        return [$courseTitle, $rest, $action];
    }
    $title = array_shift($rest) ?? 'Cảnh báo học tập';
    return [$title, $rest, $action];
}

$typeLabels = [
    'Attendance'     => 'Chuyên cần',
    'LowGrade'       => 'Kết quả học tập',
    'LowGPA'         => 'GPA thấp',
    'GraduationRisk' => 'Nguy cơ tốt nghiệp',
];

$typeIcon = [
    'Attendance'     => 'db-icon-attendance.svg',
    'LowGrade'       => 'db-icon-alert-warn.svg',
    'LowGPA'         => 'db-icon-alert-warn.svg',
    'GraduationRisk' => 'db-icon-alert-warn.svg',
];

$severityMeta = [
    1 => ['label' => 'Nhắc nhở',   'bg' => '#eff6ff', 'fg' => '#1d4ed8', 'row' => 'info'],
    2 => ['label' => 'Cần chú ý',  'bg' => '#fffbeb', 'fg' => '#b45309', 'row' => 'warn'],
    3 => ['label' => 'Nguy cơ cao','bg' => '#fee2e2', 'fg' => '#b91c1c', 'row' => 'critical'],
];

// Active filter
$filter = $_GET['filter'] ?? 'all';
$filteredWarnings = match($filter) {
    'unread'   => array_values(array_filter($warnings, fn($w) => !$w['is_read'])),
    'active'   => array_values(array_filter($warnings, fn($w) => $w['status'] === 'Active')),
    'resolved' => array_values(array_filter($warnings, fn($w) => $w['status'] === 'Resolved')),
    default    => $warnings,
};

$filterCounts = [
    'all'      => count($warnings),
    'unread'   => count(array_filter($warnings, fn($w) => !$w['is_read'])),
    'active'   => count(array_filter($warnings, fn($w) => $w['status'] === 'Active')),
    'resolved' => count(array_filter($warnings, fn($w) => $w['status'] === 'Resolved')),
];
$hasUnread = $filterCounts['unread'] > 0;

$severityCounts = [1 => 0, 2 => 0, 3 => 0];
foreach ($warnings as $w) {
    if ($w['status'] !== 'Active') continue;
    $lvl = max(1, min(3, (int)$w['warning_level']));
    $severityCounts[$lvl]++;
}

$pageTitle  = 'Cảnh báo học tập';
$activePage = 'alerts';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = count(array_filter($warnings, fn($w) => !$w['is_read'] && $w['status'] === 'Active'));
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Alert filters -->
<div class="alerts-toolbar">
    <nav class="alerts-tabs" aria-label="Lọc cảnh báo">
        <?php foreach (['all'=>'Tất cả','unread'=>'Chưa đọc','active'=>'Đang hoạt động','resolved'=>'Đã giải quyết'] as $k=>$v): ?>
        <a href="?filter=<?= $k ?>"
           class="alerts-tab<?= $filter === $k ? ' is-active' : '' ?>"
           <?= $filter === $k ? 'aria-current="page"' : '' ?>><?= $v ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($hasUnread): ?>
    <form method="POST" class="alerts-toolbar__action">
        <?= csrf_input() ?>
        <input type="hidden" name="mark_all_read" value="1" />
        <button type="submit" class="btn-secondary">Đánh dấu tất cả đã đọc</button>
    </form>
    <?php endif; ?>
</div>

<!-- Warnings list -->
<div class="alerts-list">
    <div class="alerts-list__head">
        <h2 class="alerts-list__title">Danh sách cảnh báo</h2>
        <span class="alerts-list__count"><?= count($filteredWarnings) ?> cảnh báo</span>
    </div>
    <?php if ($severityCounts[1] + $severityCounts[2] + $severityCounts[3] > 0): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;padding:0 0 12px;">
        <?php foreach ($severityMeta as $lvl => $m): ?>
        <span style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:12px;background:<?= $m['bg'] ?>;color:<?= $m['fg'] ?>;">
            <?= h($m['label']) ?>: <?= (int)$severityCounts[$lvl] ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (empty($filteredWarnings)): ?>
    <div class="alerts-empty">
        <div class="alerts-empty__icon" aria-hidden="true">✓</div>
        <p>
            <?= $filter === 'unread' ? 'Không có cảnh báo chưa đọc.' :
                ($filter === 'resolved' ? 'Chưa có cảnh báo nào được giải quyết.' :
                ($filter === 'active' ? 'Không có cảnh báo đang hoạt động.' : 'Không có cảnh báo học tập nào. Tiếp tục phát huy!')) ?>
        </p>
    </div>
    <?php else: ?>
    <?php foreach ($filteredWarnings as $w):
        $lvl     = max(1, min(3, (int)$w['warning_level']));
        $sev     = $severityMeta[$lvl];
        $rowCls  = $w['status'] === 'Resolved' ? 'ok' : $sev['row'];
        $icon    = $typeIcon[$w['warning_type']] ?? 'db-icon-alert-info.svg';
        $label   = $typeLabels[$w['warning_type']] ?? $w['warning_type'];
        $isOpen  = $w['status'] !== 'Resolved';
        $courseTitle = $w['course_code'] ? ($w['course_code'] . ' – ' . $w['course_name']) : null;
        [$title, $details, $action] = splitWarning((string)$w['message'], $courseTitle);
        $courseLink = $w['course_code']
            ? ($w['warning_type'] === 'Attendance' ? 'attendance.php' : 'gpa.php')
            : 'gpa.php';
    ?>
    <article class="alert-row alert-row--<?= $rowCls ?><?= !$w['is_read'] ? ' alert-row--unread' : '' ?>">
        <span class="alert-row__icon" aria-hidden="true">
            <img src="<?= $imgBase ?><?= h($icon) ?>" alt="" />
        </span>
        <div class="alert-row__main">
            <div class="alert-row__head" style="flex-wrap:wrap;">
                <h3 class="alert-row__title"><?= h($title) ?></h3>
                <?php if (!$w['is_read']): ?>
                <span class="alert-row__dot" title="Chưa đọc" aria-label="Chưa đọc"></span>
                <?php endif; ?>
                <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:<?= $sev['bg'] ?>;color:<?= $sev['fg'] ?>;">
                    <?= h($sev['label']) ?>
                </span>
                <span class="status-pill status-pill--<?= $isOpen ? 'absent' : 'resolved' ?>" style="font-size:10px;">
                    <?= $isOpen ? 'Đang hoạt động' : 'Đã giải quyết' ?>
                </span>
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px;">
                <?= h($label) ?><?= $w['section_code'] ? ' · Lớp ' . h($w['section_code']) : '' ?>
            </div>
            <?php if ($details): ?>
            <div class="alert-row__msg" style="font-size:12.5px;color:#475569;line-height:1.55;margin-top:6px;">
                <?php foreach ($details as $line): ?>
                <div><?= h($line) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($action !== ''): ?>
            <div style="margin-top:7px;padding:7px 10px;border-radius:8px;background:#f8fafc;border-left:3px solid <?= $sev['fg'] ?>;font-size:12px;color:#334155;">
                ➜ <?= h($action) ?>
            </div>
            <?php endif; ?>
            <div class="alert-row__meta" style="margin-top:6px;">
                <?= date('d/m/Y H:i', strtotime($w['created_at'])) ?>
                · <a href="<?= $courseLink ?>" style="color:#1e40af;font-weight:600;text-decoration:none;">Xem môn học →</a>
            </div>
        </div>
        <?php if (!$w['is_read'] && $w['status'] === 'Active'): ?>
        <form method="POST" class="alert-row__action">
            <?= csrf_input() ?>
            <input type="hidden" name="mark_read" value="1" />
            <input type="hidden" name="warning_id" value="<?= (int)$w['warning_id'] ?>" />
            <button type="submit" class="btn-secondary">Đánh dấu đã đọc</button>
        </form>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
