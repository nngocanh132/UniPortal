<?php
/**
 * UniPortal – Admin: Academic Catalog (Departments & Majors)
 * Departments and majors are distinct from courses: a department groups
 * majors; each major owns one or more curricula that list its courses.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/AcademicAlertService.php';

require_login();
if ($_SESSION['role'] !== 'AcademicAffairs') { header('Location: /UniPortal/public/index.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();
$adminAccId = (int)($_SESSION['account_id'] ?? 0);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_department') {
        $id     = (int)($_POST['department_id'] ?? 0);
        $code   = strtoupper(trim($_POST['department_code'] ?? ''));
        $name   = trim($_POST['department_name'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($code === '' || $name === '') {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Vui lòng nhập mã và tên bộ môn/ngành.'];
        } elseif (mb_strlen($code) > 20 || mb_strlen($name) > 100) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Mã tối đa 20 ký tự, tên tối đa 100 ký tự.'];
        } else {
            $dup = $pdo->prepare(
                'SELECT department_id FROM Department
                 WHERE (department_code=:c OR department_name=:n)
                   AND (:id=0 OR department_id<>:id2) LIMIT 1'
            );
            $dup->execute([':c' => $code, ':n' => $name, ':id' => $id, ':id2' => $id]);
            if ($dup->fetch()) {
                $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Mã hoặc tên bộ môn/ngành đã tồn tại.'];
            } elseif ($id > 0) {
                $old = $pdo->prepare('SELECT * FROM Department WHERE department_id=:id LIMIT 1');
                $old->execute([':id' => $id]);
                $oldRow = $old->fetch();
                $pdo->prepare('UPDATE Department SET department_code=:c, department_name=:n, is_active=:a WHERE department_id=:id')
                    ->execute([':c' => $code, ':n' => $name, ':a' => $active, ':id' => $id]);
                AcademicAlertService::writeAuditLog($pdo, $adminAccId, 'UPDATE_DEPARTMENT', 'Department', (string)$id,
                    json_encode($oldRow, JSON_UNESCAPED_UNICODE), json_encode(['department_code' => $code, 'department_name' => $name, 'is_active' => $active], JSON_UNESCAPED_UNICODE));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Đã cập nhật bộ môn/ngành ' . $code . '.'];
            } else {
                $pdo->prepare('INSERT INTO Department (department_code, department_name, is_active) VALUES (:c, :n, :a)')
                    ->execute([':c' => $code, ':n' => $name, ':a' => $active]);
                $newId = (int)$pdo->lastInsertId();
                AcademicAlertService::writeAuditLog($pdo, $adminAccId, 'CREATE_DEPARTMENT', 'Department', (string)$newId,
                    null, json_encode(['department_code' => $code, 'department_name' => $name], JSON_UNESCAPED_UNICODE));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Đã thêm bộ môn/ngành ' . $code . ' – ' . $name . '.'];
            }
        }
        header('Location: catalog.php'); exit;
    }

    if ($action === 'save_major') {
        $id     = (int)($_POST['major_id'] ?? 0);
        $code   = strtoupper(trim($_POST['major_code'] ?? ''));
        $name   = trim($_POST['major_name'] ?? '');
        $deptId = (int)($_POST['department_id'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;

        $deptOk = $pdo->prepare('SELECT 1 FROM Department WHERE department_id=:id LIMIT 1');
        $deptOk->execute([':id' => $deptId]);

        if ($code === '' || $name === '' || $deptId < 1) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Vui lòng nhập mã ngành, tên ngành và chọn bộ môn quản lý.'];
        } elseif (mb_strlen($code) > 20 || mb_strlen($name) > 150) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Mã ngành tối đa 20 ký tự, tên tối đa 150 ký tự.'];
        } elseif (!$deptOk->fetch()) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Bộ môn quản lý không hợp lệ.'];
        } else {
            $dup = $pdo->prepare('SELECT major_id FROM Major WHERE major_code=:c AND (:id=0 OR major_id<>:id2) LIMIT 1');
            $dup->execute([':c' => $code, ':id' => $id, ':id2' => $id]);
            if ($dup->fetch()) {
                $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Mã ngành ' . $code . ' đã tồn tại.'];
            } elseif ($id > 0) {
                $old = $pdo->prepare('SELECT * FROM Major WHERE major_id=:id LIMIT 1');
                $old->execute([':id' => $id]);
                $oldRow = $old->fetch();
                $pdo->prepare('UPDATE Major SET major_code=:c, major_name=:n, department_id=:d, is_active=:a WHERE major_id=:id')
                    ->execute([':c' => $code, ':n' => $name, ':d' => $deptId, ':a' => $active, ':id' => $id]);
                // Keep curricula code in sync so existing joins stay valid.
                $pdo->prepare('UPDATE Curriculum SET major_code=:c WHERE major_id=:id')
                    ->execute([':c' => $code, ':id' => $id]);
                AcademicAlertService::writeAuditLog($pdo, $adminAccId, 'UPDATE_MAJOR', 'Major', (string)$id,
                    json_encode($oldRow, JSON_UNESCAPED_UNICODE), json_encode(['major_code' => $code, 'major_name' => $name, 'department_id' => $deptId, 'is_active' => $active], JSON_UNESCAPED_UNICODE));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Đã cập nhật ngành ' . $code . '.'];
            } else {
                $pdo->prepare('INSERT INTO Major (major_code, major_name, department_id, is_active) VALUES (:c, :n, :d, :a)')
                    ->execute([':c' => $code, ':n' => $name, ':d' => $deptId, ':a' => $active]);
                $newId = (int)$pdo->lastInsertId();
                AcademicAlertService::writeAuditLog($pdo, $adminAccId, 'CREATE_MAJOR', 'Major', (string)$newId,
                    null, json_encode(['major_code' => $code, 'major_name' => $name, 'department_id' => $deptId], JSON_UNESCAPED_UNICODE));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Đã thêm ngành ' . $code . ' – ' . $name . '.'];
            }
        }
        header('Location: catalog.php?tab=majors'); exit;
    }
}

$flash = $_SESSION['_flash'] ?? null;
unset($_SESSION['_flash']);

$tab = ($_GET['tab'] ?? '') === 'majors' ? 'majors' : 'departments';

$departments = $pdo->query(
    "SELECT d.*,
            (SELECT COUNT(*) FROM Major m WHERE m.department_id=d.department_id) AS major_count,
            (SELECT COUNT(*) FROM Course c WHERE c.department_id=d.department_id) AS course_count,
            (SELECT COUNT(*) FROM Student s JOIN Major m ON m.major_id=s.major_id
              WHERE m.department_id=d.department_id AND s.status='Active') AS student_count
     FROM Department d ORDER BY d.department_code"
)->fetchAll();

$majors = $pdo->query(
    "SELECT m.*, d.department_code, d.department_name,
            (SELECT COUNT(*) FROM Curriculum cur WHERE cur.major_id=m.major_id) AS curriculum_count,
            (SELECT COUNT(DISTINCT cc.course_id) FROM CurriculumCourse cc
              JOIN Curriculum cur ON cur.curriculum_id=cc.curriculum_id
              WHERE cur.major_id=m.major_id) AS course_count,
            (SELECT COUNT(*) FROM Student s WHERE s.major_id=m.major_id AND s.status='Active') AS student_count
     FROM Major m
     JOIN Department d ON d.department_id=m.department_id
     ORDER BY d.department_code, m.major_code"
)->fetchAll();

$courseTotal = (int)$pdo->query('SELECT COUNT(*) FROM Course WHERE is_active=TRUE')->fetchColumn();
$unmapped    = (int)$pdo->query('SELECT COUNT(*) FROM Student WHERE major_id IS NULL AND status=\'Active\'')->fetchColumn();

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Ngành & Bộ môn';
$activePage = 'catalog';
$breadcrumb = 'Quản trị › Danh mục đào tạo';
require __DIR__ . '/_layout.php';
?>

<?php if ($flash): ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:2px;
            background:<?= $flash['type']==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $flash['type']==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $flash['type']==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $flash['type']==='success' ? '✅' : '⚠️' ?> <?= h($flash['message']) ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px;">
    <a href="courses.php?tab=courses" class="abtn abtn--outline">📚 Danh mục môn học</a>
    <button onclick="showModal('<?= $tab==='majors'?'modalAddMajor':'modalAddDept' ?>')"
            class="abtn abtn--primary" style="background:#0d1b2e;">
        ➕ <?= $tab==='majors'?'Thêm ngành':'Thêm bộ môn' ?>
    </button>
</div>

<div style="padding:10px 14px;border-radius:10px;background:#eff6ff;border:1px solid #bfdbfe;font-size:12px;color:#1e40af;line-height:1.55;">
    <b>Lưu ý:</b> Bộ môn là đơn vị quản lý; mỗi bộ môn có thể có nhiều ngành. Môn học thuộc bộ môn và được phân bổ cho từng ngành thông qua chương trình đào tạo (không gắn cứng một ngành duy nhất). Dữ liệu đang hiển thị là <b>dữ liệu mẫu phục vụ minh họa</b>, không phải chương trình chính thức của trường.
</div>

<div class="adm-kpi-row">
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Bộ môn</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-icon-credits.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= count($departments) ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Ngành đào tạo</span><span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-nav-profile.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= count($majors) ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Môn học đang mở</span><span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-nav-grades.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $courseTotal ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">SV chưa gán ngành</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-alerts.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $unmapped ?></span></div></div>
</div>

<div class="adm-tabs" style="background:#fff;border-radius:12px;padding:0 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <a href="catalog.php?tab=departments" class="adm-tab <?= $tab==='departments'?'active':'' ?>">🏛 Bộ môn (<?= count($departments) ?>)</a>
    <a href="catalog.php?tab=majors" class="adm-tab <?= $tab==='majors'?'active':'' ?>">🎓 Ngành đào tạo (<?= count($majors) ?>)</a>
</div>

<?php if ($tab === 'departments'): ?>
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr>
                <th>MÃ</th><th>TÊN BỘ MÔN / NGÀNH</th>
                <th style="text-align:center;">NGÀNH</th><th style="text-align:center;">MÔN HỌC</th>
                <th style="text-align:center;">SV ĐANG HỌC</th><th>TRẠNG THÁI</th><th>THAO TÁC</th>
            </tr></thead>
            <tbody>
                <?php if (empty($departments)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Chưa có bộ môn nào.</td></tr>
                <?php else: foreach ($departments as $d): ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($d['department_code']) ?></span></td>
                    <td style="font-weight:600;color:#0d1b2e;"><?= h($d['department_name']) ?></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$d['major_count'] ?></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$d['course_count'] ?></td>
                    <td style="text-align:center;"><?= (int)$d['student_count'] ?></td>
                    <td><span class="apill <?= $d['is_active']?'apill--green':'apill--gray' ?>"><?= $d['is_active']?'Hoạt động':'Tạm dừng' ?></span></td>
                    <td style="white-space:nowrap;">
                        <a href="courses.php?tab=courses&amp;dept=<?= h(urlencode($d['department_code'])) ?>" class="abtn abtn--outline abtn--sm" title="Xem môn học">📚</a>
                        <button onclick='openDeptModal(<?= htmlspecialchars(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>)'
                                class="abtn abtn--outline abtn--sm" title="Chỉnh sửa">✏</button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr>
                <th>MÃ NGÀNH</th><th>TÊN NGÀNH</th><th>BỘ MÔN QUẢN LÝ</th>
                <th style="text-align:center;">CTĐT</th><th style="text-align:center;">MÔN HỌC</th>
                <th style="text-align:center;">SV</th><th>TRẠNG THÁI</th><th>THAO TÁC</th>
            </tr></thead>
            <tbody>
                <?php if (empty($majors)): ?>
                <tr><td colspan="8" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Chưa có ngành nào.</td></tr>
                <?php else: foreach ($majors as $m): ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($m['major_code']) ?></span></td>
                    <td style="font-weight:600;color:#0d1b2e;"><?= h($m['major_name']) ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= h($m['department_code']) ?> – <?= h($m['department_name']) ?></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$m['curriculum_count'] ?></td>
                    <td style="text-align:center;"><?= (int)$m['course_count'] ?></td>
                    <td style="text-align:center;"><?= (int)$m['student_count'] ?></td>
                    <td><span class="apill <?= $m['is_active']?'apill--green':'apill--gray' ?>"><?= $m['is_active']?'Hoạt động':'Tạm dừng' ?></span></td>
                    <td style="white-space:nowrap;">
                        <a href="curriculum.php?major_id=<?= (int)$m['major_id'] ?>" class="abtn abtn--outline abtn--sm" title="Chương trình đào tạo">🗂</a>
                        <button onclick='openMajorModal(<?= htmlspecialchars(json_encode($m, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>)'
                                class="abtn abtn--outline abtn--sm" title="Chỉnh sửa">✏</button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: ADD/EDIT DEPARTMENT -->
<div id="modalAddDept" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:480px;padding:28px;position:relative;">
    <button onclick="closeModal('modalAddDept')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 id="deptModalTitle" style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">➕ Thêm bộ môn / ngành</h2>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="save_department" />
        <input type="hidden" name="department_id" id="dept_id" value="0" />
        <div style="display:grid;gap:12px;">
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã bộ môn *</label>
                <input type="text" name="department_code" id="dept_code" required maxlength="20" placeholder="VD: ECO"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên bộ môn *</label>
                <input type="text" name="department_name" id="dept_name" required maxlength="100" placeholder="VD: Bộ môn Kinh tế"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="is_active" id="dept_active" checked /> Đang hoạt động
            </label>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="closeModal('modalAddDept')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu</button>
        </div>
    </form>
</div></div>

<!-- MODAL: ADD/EDIT MAJOR -->
<div id="modalAddMajor" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;padding:28px;position:relative;">
    <button onclick="closeModal('modalAddMajor')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 id="majorModalTitle" style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">➕ Thêm ngành đào tạo</h2>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="save_major" />
        <input type="hidden" name="major_id" id="major_id" value="0" />
        <div style="display:grid;gap:12px;">
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã ngành *</label>
                <input type="text" name="major_code" id="major_code" required maxlength="20" placeholder="VD: MKT"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên ngành *</label>
                <input type="text" name="major_name" id="major_name" required maxlength="150" placeholder="VD: Marketing"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bộ môn quản lý *</label>
                <select name="department_id" id="major_dept" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn bộ môn —</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['department_id'] ?>"><?= h($d['department_code'] . ' – ' . $d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="is_active" id="major_active" checked /> Đang hoạt động
            </label>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="closeModal('modalAddMajor')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu</button>
        </div>
    </form>
</div></div>

<script>
function showModal(id){var el=document.getElementById(id);if(el){el.style.display='flex';document.body.style.overflow='hidden';}}
function closeModal(id){var el=document.getElementById(id);if(el){el.style.display='none';document.body.style.overflow='';}}
function setVal(id,v){var el=document.getElementById(id);if(el)el.value=v==null?'':v;}
function openDeptModal(d){
    document.getElementById('deptModalTitle').textContent='✏ Chỉnh sửa bộ môn';
    setVal('dept_id',d.department_id); setVal('dept_code',d.department_code); setVal('dept_name',d.department_name);
    document.getElementById('dept_active').checked = d.is_active==1;
    showModal('modalAddDept');
}
function openMajorModal(m){
    document.getElementById('majorModalTitle').textContent='✏ Chỉnh sửa ngành';
    setVal('major_id',m.major_id); setVal('major_code',m.major_code); setVal('major_name',m.major_name);
    setVal('major_dept',m.department_id);
    document.getElementById('major_active').checked = m.is_active==1;
    showModal('modalAddMajor');
}
['modalAddDept','modalAddMajor'].forEach(function(id){
    var el=document.getElementById(id);
    if(el)el.addEventListener('click',function(e){if(e.target===this)closeModal(id);});
});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeModal('modalAddDept');closeModal('modalAddMajor');}});
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
