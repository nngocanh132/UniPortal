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
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= h($pageTitle) ?> – UniPortal Giảng viên</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="<?= $CSS ?>lecturer.css" />
    <?php if (!empty($extraCss)): ?>
    <link rel="stylesheet" href="<?= $CSS ?><?= h($extraCss) ?>" />
    <?php endif; ?>
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
        <div class="lec-sidebar__system-info">
            <span class="lec-sidebar__system-dot"></span>
            <div class="lec-sidebar__system-text">
                <strong>Hệ thống UIS ĐHQGHN</strong><br/>
                Máy chủ: Đầy sáng
            </div>
        </div>
        <a href="?logout=1" class="lec-sidebar__logout">
            <img src="<?= $IMG ?>db-icon-logout.svg" alt="" />
            Đăng xuất tài khoản
        </a>
    </div>
</aside>

<!-- ═══════════════════ HEADER ════════════════════════════ -->
<header class="lec-header">
    <div class="lec-header__left">
        <button class="lec-hamburger" id="lecHamburger" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
        <img class="lec-header__logo"
             src="<?= $IMG ?>db-logo-vnuis-sidebar.png"
             alt="IIS" />
        <div class="lec-header__divider"></div>
        <div class="lec-header__breadcrumb">
            <span class="lec-header__breadcrumb-top">Cổng Giảng viên</span>
            <span class="lec-header__breadcrumb-bottom">Trường Quốc tế – ĐHQGHN</span>
        </div>
        <div class="lec-header__divider"></div>
        <div class="lec-header__search">
            <img class="s-icon" src="<?= $IMG ?>kqht-icon-search.svg" alt="" />
            <input type="search" placeholder="Tìm sinh viên, MSSV, mã lớp..." aria-label="Tìm kiếm" />
        </div>
    </div>

    <div class="lec-header__right">
        <button class="lec-header__bell" aria-label="Thông báo">
            <img src="<?= $IMG ?>db-icon-bell.svg" alt="" />
            <?php if ($warnCount > 0): ?>
            <span class="hbadge"><?= min($warnCount, 9) ?></span>
            <?php endif; ?>
        </button>
        <button class="lec-header__profile" aria-haspopup="true">
            <span class="hav"><?= h($initials) ?></span>
            <div>
                <div class="hname"><?= h($lecName) ?></div>
                <div class="hrole">Khoa <?= h($lecCode) ?> – Bộ môn</div>
            </div>
            <img class="hcaret" src="<?= $IMG ?>db-icon-caret.svg" alt="" />
        </button>
    </div>
</header>

<!-- ═══════════════════ MAIN ══════════════════════════════ -->
<main class="lec-main" id="lecMain">
<div class="lec-content">
