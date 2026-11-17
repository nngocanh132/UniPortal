<?php
/**
 * UniPortal – Admin layout partial
 * Include AFTER setting: $pageTitle, $activePage, $breadcrumb, $warnCount (int)
 * $activePage: 'dashboard','students','attendance','reports','auditlog','settings'
 */
if (!isset($pageTitle))   $pageTitle   = 'UniPortal Admin';
if (!isset($activePage))  $activePage  = '';
if (!isset($breadcrumb))  $breadcrumb  = 'Quản trị › Học vụ & Sinh viên';
if (!isset($warnCount))   $warnCount   = 0;

$IMG = '../img/';
$CSS = '../css/';
$JS  = '../js/';

$adminName = $_SESSION['username'] ?? 'Quản trị viên';
$adminIni  = strtoupper(substr($adminName, 0, 2));

$nav = [
    ['dashboard',      'Tổng quan',                     'db-nav-dashboard.svg',      'dashboard.php'],
    ['students',       'Quản lý sinh viên',              'db-nav-profile.svg',        'students.php'],
    ['lecturers',      'Quản lý giảng viên',             'db-icon-schedule-user.svg', 'lecturers.php'],
    ['catalog',        'Ngành & Bộ môn',                 'db-icon-schedule-book.svg', 'catalog.php'],
    ['courses',        'Môn học & Lớp học phần',         'db-nav-grades.svg',         'courses.php'],
    ['schedule',       'Lịch giảng dạy',                 'db-nav-schedule.svg',       'schedule.php'],
    ['schedule_requests', 'Đề xuất thay đổi lịch học',   'db-nav-attendance.svg',     'schedule_requests.php'],
    ['curriculum',     'Chương trình đào tạo',           'db-icon-gpa-card.svg',      'curriculum.php'],
    ['enrollment',     'Đăng ký học phần',               'db-nav-attendance.svg',     'enrollment.php'],
    ['registration_periods', 'Đợt đăng ký môn học',       'db-nav-schedule.svg',       'registration_periods.php'],
    ['attendance',     'Quản lý điểm danh',              'db-nav-attendance.svg',     'attendance.php'],
    ['reports',        'Báo cáo & Thống kê',             'db-nav-grades.svg',         'reports.php'],
    ['tuition',        'Thanh toán học phí',             'db-icon-credits.svg',       'tuition.php'],
    ['import_export',  'Import & Export',                'db-icon-credits.svg',       'import_export.php'],
    ['auditlog',       'Nhật ký hệ thống',               'db-nav-alerts.svg',         'auditlog.php'],
    ['settings',       'Cài đặt hệ thống',               'db-icon-ai.svg',            'config.php'],
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
    <title><?= h($pageTitle) ?> – UniPortal Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="<?= $CSS ?>admin.css?v=<?= @filemtime(__DIR__ . '/../css/admin.css') ?: 0 ?>" />
    <?php if (!empty($extraCss)): ?>
    <link rel="stylesheet" href="<?= $CSS ?><?= h($extraCss) ?>?v=<?= @filemtime(__DIR__ . '/../css/' . $extraCss) ?: 0 ?>" />
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $CSS ?>system.css?v=<?= @filemtime(__DIR__ . '/../css/system.css') ?: 0 ?>" />
</head>
<body>
<div class="adm-layout">

<!-- ═══════════ SIDEBAR ═══════════════════════════════════ -->
<aside class="adm-sidebar" id="admSidebar">
    <div class="adm-sidebar__logo">
        <img src="<?= $IMG ?>db-logo-vnuis-sidebar.png" alt="IIS" />
    </div>
    <nav class="adm-sidebar__nav">
        <?php foreach ($nav as [$id, $label, $icon, $href]): ?>
        <a href="<?= h($href) ?>"
           class="<?= $activePage === $id ? 'active' : '' ?>"
           <?= $activePage === $id ? 'aria-current="page"' : '' ?>>
            <img src="<?= $IMG ?><?= h($icon) ?>" alt="" />
            <?= h($label) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="adm-sidebar__footer">
        <div class="sidebar-profile">
            <span class="sidebar-profile__avatar"><?= h($adminIni) ?></span>
            <div class="sidebar-profile__text">
                <div class="sidebar-profile__name">Quản trị viên</div>
                <div class="sidebar-profile__role">Phòng Đào tạo</div>
            </div>
            <a href="?logout=1" class="sidebar-profile__logout" title="Đăng xuất" aria-label="Đăng xuất">
                <img src="<?= $IMG ?>db-icon-logout.svg" alt="" />
            </a>
        </div>
    </div>
</aside>

<!-- ═══════════ HEADER ════════════════════════════════════ -->
<header class="adm-header">
    <div style="display:flex;align-items:center;gap:16px;min-width:0;">
        <button class="adm-hamburger" id="admHam"><span></span><span></span><span></span></button>
        <div class="adm-header__left">
            <div class="adm-header__title"><?= h($pageTitle) ?></div>
            <div class="adm-header__breadcrumb"><?= h($breadcrumb) ?></div>
        </div>
    </div>
    <div class="adm-header__right">
        <div class="adm-header__search">
            <img class="s-ico" src="<?= $IMG ?>icon-search.svg" alt="" />
            <input type="search" placeholder="Tìm kiếm sinh viên, tài khoản, học vụ…" />
        </div>
        <?php
        require_once dirname(__DIR__, 3) . '/src/src/services/NotificationService.php';
        $notifCount = NotificationService::unreadCount((int)($_SESSION['account_id'] ?? 0));
        $notifItems = NotificationService::getRecent((int)($_SESSION['account_id'] ?? 0), 5);
        $bellBadge  = max((int)($warnCount ?? 0), $notifCount);
        ?>
        <div id="admNotifWrap">
        <button class="adm-header__bell" id="admBell" aria-label="Thông báo" aria-expanded="false"
                onclick="this.setAttribute('aria-expanded', document.getElementById('admNotifDrop').classList.toggle('show'));">
            <img src="<?= $IMG ?>db-icon-bell.svg" alt="" />
            <?php if ($bellBadge > 0): ?>
            <span class="hbadge"><?= min($bellBadge, 9) ?></span>
            <?php endif; ?>
        </button>
        <div id="admNotifDrop" class="notif-panel">
            <div class="notif-panel__head">
                <span class="notif-panel__title">Thông báo</span>
                <?php if ($notifCount > 0): ?>
                <a href="?mark_all_notif=1" class="notif-panel__action">Đánh dấu đã đọc</a>
                <?php endif; ?>
            </div>
            <?php if (empty($notifItems) && !$warnCount): ?>
            <div class="notif-panel__empty">Không có thông báo.</div>
            <?php else: ?>
            <div class="notif-panel__list">
                <?php if ($warnCount): ?>
                <a class="notif-item notif-item--unread" href="alerts.php">
                    <div class="notif-item__title"><?= (int)$warnCount ?> cảnh báo học vụ đang hoạt động</div>
                    <div class="notif-item__time">Cần xem xét</div>
                </a>
                <?php endif; ?>
                <?php foreach ($notifItems as $n): ?>
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
                <a href="alerts.php">Xem cảnh báo học vụ →</a>
            </div>
        </div>
        </div>
        <button class="adm-header__profile">
            <div class="hav"><?= h($adminIni) ?></div>
            <div>
                <div class="hname">Quản trị viên Hệ thống</div>
                <div class="hrole">Phòng Đào tạo</div>
            </div>
            <img src="<?= $IMG ?>db-icon-caret.svg" alt="" class="hcaret" />
        </button>
    </div>
</header>
<script>
(function () {
    document.addEventListener('click', function (e) {
        var drop = document.getElementById('admNotifDrop');
        var wrap = document.getElementById('admNotifWrap');
        if (!drop || !wrap || !drop.classList.contains('show')) return;
        if (!wrap.contains(e.target)) {
            drop.classList.remove('show');
            var bell = document.getElementById('admBell');
            if (bell) bell.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var drop = document.getElementById('admNotifDrop');
        if (!drop || !drop.classList.contains('show')) return;
        drop.classList.remove('show');
        var bell = document.getElementById('admBell');
        if (bell) { bell.setAttribute('aria-expanded', 'false'); bell.focus(); }
    });
})();
</script>

<!-- ═══════════ MAIN CONTENT ══════════════════════════════ -->
<main class="adm-main">
<div class="adm-content">
