<?php
/**
 * UniPortal – Admin: Cảnh báo học vụ
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student') { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

// Filter
$filter = $_GET['filter'] ?? 'active';
$type   = $_GET['type']   ?? '';
$search = trim($_GET['q'] ?? '');

$where  = "WHERE aw.warning_id > 0";
$params = [];
if ($filter === 'active')   { $where .= " AND aw.status = 'Active'";   }
if ($filter === 'resolved') { $where .= " AND aw.status = 'Resolved'"; }
if ($filter === 'unread')   { $where .= " AND aw.is_read = FALSE";     }
if ($type !== '')           { $where .= " AND aw.warning_type = :type"; $params[':type'] = $type; }
if ($search !== '')         { $where .= " AND (s.full_name LIKE :q OR s.student_id LIKE :q)"; $params[':q'] = '%'.$search.'%'; }

$stmt = $pdo->prepare(
    "SELECT aw.warning_id, aw.warning_type, aw.message, aw.created_at, aw.is_read, aw.status,
            s.student_id, s.full_name, s.class_code
     FROM AcademicWarning aw
     JOIN Student s ON s.student_id = aw.student_id
     $where
     ORDER BY aw.is_read ASC, aw.created_at DESC
     LIMIT 200"
);
$stmt->execute($params);
$warnings = $stmt->fetchAll();

$totalActive   = $pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active'")->fetchColumn();
$totalUnread   = $pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE is_read=FALSE AND status='Active'")->fetchColumn();
$totalResolved = $pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Resolved'")->fetchColumn();

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$typeLabels = ['Attendance'=>'Chuyên cần','LowGrade'=>'Điểm thấp','LowGPA'=>'GPA thấp','GraduationRisk'=>'Tốt nghiệp'];
$IMG = '../img/';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" /><meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <title>Cảnh báo học vụ – UniPortal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" /><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="../css/dashboard.css" />
</head>
<body><div class="db-layout">
<aside class="db-sidebar" style="background:#001634;" id="sidebar">
    <div>
        <div class="db-sidebar__logo" style="background:rgba(0,0,0,.3);">
            <img src="<?= $IMG ?>db-logo-vnuis-sidebar.png" alt="VNU-IS" />
        </div>
        <nav class="db-sidebar__nav">
            <a href="dashboard.php"><img src="<?= $IMG ?>db-nav-dashboard.svg" alt="" /> Tổng quan</a>
            <a href="students.php"><img src="<?= $IMG ?>db-nav-profile.svg" alt="" /> Quản lý sinh viên</a>
            <a href="alerts.php" class="active"><img src="<?= $IMG ?>db-nav-alerts.svg" alt="" /> Cảnh báo học vụ</a>
            <a href="config.php"><img src="<?= $IMG ?>db-icon-ai.svg" alt="" /> Cấu hình hệ thống</a>
        </nav>
    </div>
    <div class="db-sidebar__footer">
        <div class="db-sidebar__profile-card">
            <div class="profile-info"><img class="avatar" src="<?= $IMG ?>db-profile.png" alt="" />
            <div><div class="name"><?= h($_SESSION['username'] ?? 'Admin') ?></div><div class="student-id"><?= h($_SESSION['role'] ?? '') ?></div></div></div>
            <a href="?logout=1" class="logout-btn"><img src="<?= $IMG ?>db-icon-logout.svg" alt="" /></a>
        </div>
    </div>
</aside>
<header class="db-header">
    <div style="display:flex;align-items:center;gap:12px;">
        <button class="db-hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <div style="font-size:18px;font-weight:700;color:#001634;">Cảnh báo học vụ</div>
    </div>
    <div class="db-header__right">
        <button class="db-header__bell">
            <img src="<?= $IMG ?>db-icon-bell.svg" alt="" />
            <?php if ($totalUnread > 0): ?><span class="badge"><?= min((int)$totalUnread,9) ?></span><?php endif; ?>
        </button>
    </div>
</header>
<main class="db-main"><div class="db-content">

<div class="stat-row" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-mini">
        <div class="stat-mini__label">Đang hoạt động</div>
        <div class="stat-mini__value" style="color:#ef4444;"><?= h($totalActive) ?></div>
        <div class="stat-mini__sub">cảnh báo</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Chưa đọc</div>
        <div class="stat-mini__value" style="color:#f59e0b;"><?= h($totalUnread) ?></div>
        <div class="stat-mini__sub">cảnh báo</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Đã giải quyết</div>
        <div class="stat-mini__value" style="color:#059669;"><?= h($totalResolved) ?></div>
        <div class="stat-mini__sub">cảnh báo</div>
    </div>
</div>

<div class="filter-ribbon">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;width:100%;">
        <div class="filter-ribbon__search" style="flex:1;min-width:180px;">
            <img class="si" src="<?= $IMG ?>kqht-icon-search.svg" alt="" />
            <input type="search" name="q" value="<?= h($search) ?>" placeholder="Tìm theo tên, mã sinh viên..." />
        </div>
        <select name="filter">
            <option value="all"      <?= $filter==='all'      ? 'selected' : '' ?>>Tất cả</option>
            <option value="active"   <?= $filter==='active'   ? 'selected' : '' ?>>Đang hoạt động</option>
            <option value="unread"   <?= $filter==='unread'   ? 'selected' : '' ?>>Chưa đọc</option>
            <option value="resolved" <?= $filter==='resolved' ? 'selected' : '' ?>>Đã giải quyết</option>
        </select>
        <select name="type">
            <option value="">Tất cả loại</option>
            <?php foreach ($typeLabels as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= $type===$k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary">Lọc</button>
        <?php if ($search||$filter!=='active'||$type): ?><a href="alerts.php" class="btn-secondary">Xoá bộ lọc</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="data-table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Sinh viên</th><th>Lớp</th><th>Loại cảnh báo</th>
                <th>Nội dung</th><th>Ngày tạo</th><th>Trạng thái</th>
            </tr></thead>
            <tbody>
                <?php if (empty($warnings)): ?>
                <tr><td colspan="6" style="text-align:center;padding:48px;color:#94a3b8;font-style:italic;">Không có cảnh báo nào phù hợp với bộ lọc.</td></tr>
                <?php else: ?>
                <?php foreach ($warnings as $w): ?>
                <?php $isCrit = in_array($w['warning_type'],['LowGPA','GraduationRisk']); ?>
                <tr style="<?= !$w['is_read'] ? 'background:#fff9f9;' : '' ?>">
                    <td>
                        <strong><?= h($w['full_name']) ?></strong>
                        <?php if (!$w['is_read'] && $w['status']==='Active'): ?>
                        <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#ef4444;margin-left:4px;vertical-align:middle;" title="Chưa đọc"></span>
                        <?php endif; ?>
                        <br/><span style="font-size:11px;color:#64748b;"><?= h($w['student_id']) ?></span>
                    </td>
                    <td><?= h($w['class_code']) ?></td>
                    <td>
                        <span class="status-pill <?= $isCrit ? 'status-pill--absent' : 'status-pill--late' ?>">
                            <?= h($typeLabels[$w['warning_type']] ?? $w['warning_type']) ?>
                        </span>
                    </td>
                    <td style="font-size:12px;max-width:280px;"><?= h(mb_strimwidth($w['message'],0,80,'…')) ?></td>
                    <td style="font-size:12px;white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($w['created_at'])) ?></td>
                    <td>
                        <span class="status-pill status-pill--<?= $w['status']==='Resolved' ? 'complete' : 'absent' ?>">
                            <?= $w['status']==='Resolved' ? 'Đã giải quyết' : 'Hoạt động' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></main>
<script src="../js/dashboard.js"></script>
</body></html>
