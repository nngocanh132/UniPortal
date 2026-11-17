<?php
/**
 * UniPortal – Shared layout partial
 * Usage: require_once __DIR__ . '/../partials/layout.php';
 * Before including, define: $pageTitle, $activePage, $imgBase, $assetBase
 * After including, close </div></main></div></body></html> with layout_end()
 *
 * $activePage values: 'dashboard','gpa','attendance','gpa-forecast','alerts','schedule','profile'
 */

// Ensure required vars have defaults
$pageTitle  = $pageTitle  ?? 'UniPortal';
$activePage = $activePage ?? '';
$imgBase    = $imgBase    ?? '../img/';     // relative to the page file
$assetBase  = $assetBase  ?? '../';        // relative to the page file

$studentName = $_SESSION['full_name']  ?? 'Sinh viên';
$studentId   = $_SESSION['student_id'] ?? '';
$warningCnt  = $warningCnt ?? 0; // pages that need this can set it before including

// Nav items: [id, label, icon-file, href]
$navItems = [
    ['dashboard',     'Tổng quan',                    'db-nav-dashboard.svg',   'dashboard.php'],
    ['gpa',           'Kết quả học tập',              'db-nav-grades.svg',      'gpa.php'],
    ['attendance',    'Điểm danh - Chuyên cần',       'db-nav-attendance.svg',  'attendance.php'],
    ['registration',  'Đăng ký môn học',              'db-nav-grades.svg',      'registration.php'],
    ['assignments',   'Bài tập & Nộp bài',            'db-nav-attendance.svg',  'assignments.php'],
    ['tuition',       'Học phí & Thanh toán',         'db-icon-credits.svg',    'tuition.php'],
    ['gpa-forecast',  'Kế hoạch & Dự báo GPA (AI)',   'db-nav-gpa-plan.svg',    'gpa_forecast.php'],
    ['alerts',        'Cảnh báo học tập',             'db-nav-alerts.svg',      'alerts.php'],
    ['schedule',      'Thời khóa biểu',               'db-nav-schedule.svg',    'schedule.php'],
    ['profile',       'Hồ sơ cá nhân',                'db-nav-profile.svg',     'profile.php'],
];

// Handle mark-all-notifications-read before any output so the redirect works
if (isset($_GET['mark_all_notif']) && !empty($_SESSION['account_id'])) {
    require_once ($root ?? dirname(__DIR__, 3)) . '/src/src/services/NotificationService.php';
    NotificationService::markAllRead((int)$_SESSION['account_id']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Open one notification: mark it read, then continue to its own destination.
if (isset($_GET['read_notif']) && !empty($_SESSION['account_id'])) {
    require_once ($root ?? dirname(__DIR__, 3)) . '/src/src/services/NotificationService.php';
    $accountId = (int)$_SESSION['account_id'];
    $notifId   = filter_var($_GET['read_notif'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $note      = $notifId === false ? false : NotificationService::getForAccount($notifId, $accountId);
    if ($note) {
        NotificationService::markRead($notifId, $accountId);
        $target = (string)$note['link'];
        // Only same-site links are followed; anything else falls back to the current page.
        if (preg_match('~\A/UniPortal/[A-Za-z0-9._/?=&%#-]*\z~', $target) === 1) {
            header('Location: ' . $target, true, 303);
            exit;
        }
    }
    $back = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . ($back ?: 'dashboard.php'), true, 303);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> – UniPortal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="<?= $assetBase ?>css/dashboard.css?v=<?= @filemtime(__DIR__ . '/../css/dashboard.css') ?: 0 ?>" />
    <?php if (!empty($extraCss)): ?>
    <link rel="stylesheet" href="<?= $assetBase ?>css/<?= htmlspecialchars($extraCss) ?>?v=<?= @filemtime(__DIR__ . '/../css/' . $extraCss) ?: 0 ?>" />
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $assetBase ?>css/system.css?v=<?= @filemtime(__DIR__ . '/../css/system.css') ?: 0 ?>" />
</head>
<body>
<div class="db-layout">

<!-- SIDEBAR -->
<aside class="db-sidebar" id="sidebar" aria-label="Navigation">
    <div>
        <div class="db-sidebar__logo">
            <img src="<?= $imgBase ?>db-logo-vnuis-sidebar.png" alt="VNU-IS" />
        </div>
        <nav class="db-sidebar__nav" aria-label="Student menu">
            <?php foreach ($navItems as [$id, $label, $icon, $href]): ?>
            <a href="<?= $href ?>"
               class="<?= $activePage === $id ? 'active' : '' ?>"
               <?= $activePage === $id ? 'aria-current="page"' : '' ?>>
                <img src="<?= $imgBase ?><?= $icon ?>" alt="" />
                <?= htmlspecialchars($label) ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="db-sidebar__footer">
        <div class="db-sidebar__profile-card">
            <div class="profile-info">
                <img class="avatar" src="<?= $imgBase ?>db-profile.png"
                     alt="<?= htmlspecialchars($studentName) ?>" />
                <div>
                    <div class="name"><?= htmlspecialchars($studentName) ?></div>
                    <div class="student-id">MSSV: <?= htmlspecialchars($studentId) ?></div>
                </div>
            </div>
            <a href="dashboard.php?logout=1" class="logout-btn" title="Đăng xuất" aria-label="Đăng xuất">
                <img src="<?= $imgBase ?>db-icon-logout.svg" alt="Đăng xuất" />
            </a>
        </div>
    </div>
</aside>

<!-- HEADER -->
<header class="db-header" role="banner">
    <div style="display:flex;align-items:center;gap:16px;min-width:0;">
        <button class="db-hamburger" id="hamburger" aria-label="Mở menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <div class="db-header__context">
            <div class="db-header__context-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="db-header__context-sub">Cổng Sinh viên</div>
        </div>
        <div class="db-header__search">
            <img class="search-icon" src="<?= $imgBase ?>icon-search.svg" alt="" aria-hidden="true" />
            <input type="search" placeholder="Tìm kiếm môn học, thông báo..." aria-label="Tìm kiếm" />
        </div>
    </div>
    <div class="db-header__right">
        <?php
        // Notification bell with real unread count
        $notifCount = 0;
        $notifItems = [];
        if (!empty($_SESSION['account_id'])) {
            require_once $GLOBALS['_root'] ?? ($root ?? dirname(__DIR__, 3)) . '/src/src/services/NotificationService.php';
            $notifCount = NotificationService::unreadCount((int)$_SESSION['account_id']);
            $notifItems = NotificationService::getRecent((int)$_SESSION['account_id'], 5);
        }
        $bellCount = max($warningCnt, $notifCount);
        ?>
        <div id="notifDropContainer">
        <button class="db-header__bell" id="notifBell" aria-label="Thông báo" aria-expanded="false"
                onclick="this.setAttribute('aria-expanded', document.getElementById('notifDrop').classList.toggle('show'));">
            <img src="<?= $imgBase ?>db-icon-bell.svg" alt="" />
            <?php if ($bellCount > 0): ?>
            <span class="badge" aria-hidden="true"><?= min($bellCount, 9) ?><?= $bellCount > 9 ? '+' : '' ?></span>
            <?php endif; ?>
        </button>
        <!-- Notification dropdown -->
        <div id="notifDrop" class="notif-panel">
            <div class="notif-panel__head">
                <span class="notif-panel__title">Thông báo</span>
                <?php if ($notifCount > 0): ?>
                <a href="?mark_all_notif=1" class="notif-panel__action">Đánh dấu tất cả đã đọc</a>
                <?php endif; ?>
            </div>
            <?php if (empty($notifItems) && $warningCnt === 0): ?>
            <div class="notif-panel__empty">Không có thông báo mới.</div>
            <?php else: ?>
            <div class="notif-panel__list">
                <?php if ($warningCnt > 0): ?>
                <a class="notif-item notif-item--unread" href="alerts.php">
                    <div class="notif-item__title"><?= (int)$warningCnt ?> cảnh báo học tập đang hoạt động</div>
                    <div class="notif-item__time">Xem chi tiết</div>
                </a>
                <?php endif; ?>
                <?php foreach ($notifItems as $n): ?>
                <a class="notif-item<?= !$n['is_read'] ? ' notif-item--unread' : '' ?>"
                   href="?read_notif=<?= (int)$n['notification_id'] ?>">
                    <div class="notif-item__title"><?= htmlspecialchars($n['title']) ?></div>
                    <?php if ($n['body']): ?>
                    <div class="notif-item__body">
                        <?= htmlspecialchars(mb_strimwidth($n['body'], 0, 60, '…')) ?>
                    </div>
                    <?php endif; ?>
                    <div class="notif-item__time"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="notif-panel__footer">
                <a href="alerts.php">Xem cảnh báo học tập →</a>
            </div>
        </div>
        </div>
        <button class="db-header__profile" id="profileToggle">
            <img class="avatar" src="<?= $imgBase ?>db-profile.png" alt="<?= htmlspecialchars($studentName) ?>" />
            <div>
                <div class="name"><?= htmlspecialchars($studentName) ?></div>
                <div class="student-id">MSSV: <?= htmlspecialchars($studentId) ?></div>
            </div>
            <img class="caret" src="<?= $imgBase ?>db-icon-caret.svg" alt="" aria-hidden="true" />
        </button>
    </div>
</header>
<script>
(function () {
    document.addEventListener('click', function (e) {
        var drop = document.getElementById('notifDrop');
        var box  = document.getElementById('notifDropContainer');
        if (!drop || !box || !drop.classList.contains('show')) return;
        if (!box.contains(e.target)) {
            drop.classList.remove('show');
            var bell = document.getElementById('notifBell');
            if (bell) bell.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var drop = document.getElementById('notifDrop');
        if (!drop || !drop.classList.contains('show')) return;
        drop.classList.remove('show');
        var bell = document.getElementById('notifBell');
        if (bell) { bell.setAttribute('aria-expanded', 'false'); bell.focus(); }
    });
})();
</script>

<!-- MAIN CONTENT WRAPPER -->
<main class="db-main" id="main-content">
<div class="db-content">
