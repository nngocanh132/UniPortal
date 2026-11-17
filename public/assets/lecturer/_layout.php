<?php
/**
 * UniPortal – Lecturer layout partial
 * Include AFTER setting: $pageTitle, $activePage, $warnCount (int)
 * $activePage values: 'dashboard','schedule','classes','materials','assignments','grading','warnings','profile'
 */
if (!isset($pageTitle))  $pageTitle  = 'UniPortal';
if (!isset($activePage)) $activePage = '';
if (!isset($warnCount))  $warnCount  = 0;

$IMG  = '../img/';           // relative to lecturer/ dir
$CSS  = '../css/';
$JS   = '../js/';

$lecName  = $_SESSION['full_name']       ?? 'Giảng viên';
$lecDept  = $_SESSION['department_name'] ?? '';
$lecCode  = $_SESSION['department_code'] ?? '';
$lecId    = $_SESSION['lecturer_id']     ?? 0;

// initials for avatar
$initials = '';
foreach (explode(' ', $lecName) as $w) $initials .= strtoupper(substr($w, 0, 1));
$initials = substr($initials, -2); // last 2 chars

$nav = [
    ['dashboard',   'Tổng quan',            'db-nav-dashboard.svg',  'dashboard.php'],
    ['schedule',    'Lịch giảng dạy',       'db-nav-schedule.svg',   'schedule.php'],
    ['classes',     'Lớp học & Sinh viên',  'db-nav-grades.svg',     'classes.php'],
    ['materials',   'Tài liệu học tập',     'db-icon-credits.svg',   'materials.php'],
    ['assignments', 'Bài tập & Bài nộp',    'db-nav-attendance.svg', 'assignments.php'],
    ['grading',     'Quản lý điểm danh',    'db-icon-attendance.svg','grading.php'],
    ['warnings',    'Cảnh báo & Can thiệp', 'db-nav-alerts.svg',     'warnings.php'],
    ['profile',     'Hồ sơ cá nhân',        'db-nav-profile.svg',    'profile.php'],
];

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (isset($_GET['mark_all_notif']) && !empty($_SESSION['account_id'])) {
    require_once dirname(__DIR__, 3) . '/src/src/services/NotificationService.php';
    NotificationService::markAllRead((int)$_SESSION['account_id']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= h($pageTitle) ?> – UniPortal Giảng viên</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="<?= $CSS ?>lecturer.css?v=<?= @filemtime(__DIR__ . '/../css/lecturer.css') ?: 0 ?>" />
    <?php if (!empty($extraCss)): ?>
    <link rel="stylesheet" href="<?= $CSS ?><?= h($extraCss) ?>?v=<?= @filemtime(__DIR__ . '/../css/' . $extraCss) ?: 0 ?>" />
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $CSS ?>system.css?v=<?= @filemtime(__DIR__ . '/../css/system.css') ?: 0 ?>" />
</head>
<body>
<div class="lec-layout">

<!-- ═══════════════════ SIDEBAR ═══════════════════════════ -->
<aside class="lec-sidebar" id="lecSidebar">
    <div class="lec-sidebar__logo">
        <img src="<?= $IMG ?>db-logo-vnuis-sidebar.png" alt="IIS" />
    </div>

    <nav class="lec-sidebar__nav">
        <?php foreach ($nav as [$id, $label, $icon, $href]): ?>
        <a href="<?= h($href) ?>"
           class="<?= $activePage === $id ? 'active' : '' ?>"
           <?= $activePage === $id ? 'aria-current="page"' : '' ?>>
            <img src="<?= $IMG ?><?= h($icon) ?>" alt="" />
            <?= h($label) ?>
            <?php if ($id === 'warnings' && $warnCount > 0): ?>
            <span class="nav-badge"><?= min($warnCount, 9) ?><?= $warnCount > 9 ? '+' : '' ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="lec-sidebar__footer">
        <div class="sidebar-profile">
            <span class="sidebar-profile__avatar"><?= h($initials) ?></span>
            <div class="sidebar-profile__text">
                <div class="sidebar-profile__name"><?= h($lecName) ?></div>
                <div class="sidebar-profile__role"><?= $lecCode !== '' ? 'Khoa ' . h($lecCode) : 'Giảng viên' ?></div>
            </div>
            <a href="?logout=1" class="sidebar-profile__logout" title="Đăng xuất" aria-label="Đăng xuất">
                <img src="<?= $IMG ?>db-icon-logout.svg" alt="" />
            </a>
        </div>
    </div>
</aside>

<!-- ═══════════════════ HEADER ════════════════════════════ -->
<header class="lec-header">
    <div class="lec-header__left" style="display:flex;align-items:center;gap:14px;min-width:0;">
        <button class="lec-hamburger" id="lecHamburger" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
        <img class="lec-header__logo"
             src="<?= $IMG ?>db-logo-vnuis-sidebar.png"
             alt="IIS" />
        <div class="lec-header__context">
            <div class="lec-header__context-title"><?= h($pageTitle) ?></div>
            <div class="lec-header__context-sub">Cổng Giảng viên</div>
        </div>
        <div class="lec-header__search">
            <img class="s-icon" src="<?= $IMG ?>icon-search.svg" alt="" />
            <input type="search" placeholder="Tìm sinh viên, MSSV, mã lớp..." aria-label="Tìm kiếm" />
        </div>
    </div>

    <div class="lec-header__right">
        <?php
        require_once dirname(__DIR__, 3) . '/src/src/services/NotificationService.php';
        $lecNotifCount = NotificationService::unreadCount((int)($_SESSION['account_id'] ?? 0));
        $lecNotifItems = NotificationService::getRecent((int)($_SESSION['account_id'] ?? 0), 5);
        $lecBellBadge  = max($warnCount, $lecNotifCount);
        ?>
        <div id="lecNotifWrap">
        <button class="lec-header__bell" aria-label="Thông báo" aria-expanded="false"
                onclick="this.setAttribute('aria-expanded', document.getElementById('lecNotifDrop').classList.toggle('show'));">
            <img src="<?= $IMG ?>db-icon-bell.svg" alt="" />
            <?php if ($lecBellBadge > 0): ?>
            <span class="hbadge"><?= min($lecBellBadge, 9) ?></span>
            <?php endif; ?>
        </button>
        <div id="lecNotifDrop" class="notif-panel">
            <div class="notif-panel__head">
                <span class="notif-panel__title">Thông báo</span>
                <?php if ($lecNotifCount > 0): ?>
                <a href="?mark_all_notif=1" class="notif-panel__action">Đánh dấu đã đọc</a>
                <?php endif; ?>
            </div>
            <?php if (empty($lecNotifItems) && !$warnCount): ?>
            <div class="notif-panel__empty">Không có thông báo mới.</div>
            <?php else: ?>
            <div class="notif-panel__list">
                <?php if ($warnCount > 0): ?>
                <a class="notif-item notif-item--unread" href="warnings.php">
                    <div class="notif-item__title"><?= (int)$warnCount ?> sinh viên cần can thiệp</div>
                    <div class="notif-item__time">Xem chi tiết</div>
                </a>
                <?php endif; ?>
                <?php foreach ($lecNotifItems as $n): ?>
                <div class="notif-item<?= !$n['is_read'] ? ' notif-item--unread' : '' ?>">
                    <div class="notif-item__title"><?= htmlspecialchars($n['title']) ?></div>
                    <?php if ($n['body']): ?>
                    <div class="notif-item__body"><?= htmlspecialchars(mb_strimwidth($n['body'], 0, 60, '…')) ?></div>
                    <?php endif; ?>
                    <div class="notif-item__time"><?= date('d/m H:i', strtotime($n['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="notif-panel__footer">
                <a href="warnings.php">Xem cảnh báo &amp; can thiệp →</a>
            </div>
        </div>
        </div>
        <button class="lec-header__profile" aria-haspopup="true">
            <span class="hav"><?= h($initials) ?></span>
            <div>
                <div class="hname"><?= h($lecName) ?></div>
                <div class="hrole"><?= $lecCode !== '' ? 'Khoa ' . h($lecCode) : 'Giảng viên' ?></div>
            </div>
            <img class="hcaret" src="<?= $IMG ?>db-icon-caret.svg" alt="" />
        </button>
    </div>
</header>
<script>
(function () {
    document.addEventListener('click', function (e) {
        var drop = document.getElementById('lecNotifDrop');
        var wrap = document.getElementById('lecNotifWrap');
        if (!drop || !wrap || !drop.classList.contains('show')) return;
        if (!wrap.contains(e.target)) {
            drop.classList.remove('show');
            var bell = wrap.querySelector('.lec-header__bell');
            if (bell) bell.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var drop = document.getElementById('lecNotifDrop');
        if (!drop || !drop.classList.contains('show')) return;
        drop.classList.remove('show');
        var wrap = document.getElementById('lecNotifWrap');
        var bell = wrap ? wrap.querySelector('.lec-header__bell') : null;
        if (bell) { bell.setAttribute('aria-expanded', 'false'); bell.focus(); }
    });
})();
</script>

<!-- ═══════════════════ MAIN ══════════════════════════════ -->
<main class="lec-main" id="lecMain">
<div class="lec-content">
