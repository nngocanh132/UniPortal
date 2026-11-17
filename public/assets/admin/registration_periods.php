<?php
/**
 * UniPortal – Admin: Registration Periods & Class Opening Monitor
 * Controls which majors/sections may register in each period and finalises
 * class opening based on the dynamic threshold floor(min/2)+1.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/RegistrationService.php';

require_login();
if ($_SESSION['role'] !== 'AcademicAffairs') { header('Location: ' . app_url('index.php')); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo = get_db();
$adminAccId = (int)($_SESSION['account_id'] ?? 0);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

RegistrationService::refreshPeriodStatuses($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_period') {
        $pid    = (int)($_POST['period_id'] ?? 0);
        $semId  = (int)($_POST['semester_id'] ?? 0);
        $name   = trim($_POST['period_name'] ?? '');
        $start  = trim($_POST['start_at'] ?? '');
        $end    = trim($_POST['end_at'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['Upcoming','Open','Closed'], true) ? $_POST['status'] : 'Upcoming';
        $majorIds   = array_values(array_unique(array_map('intval', $_POST['major_ids'] ?? [])));
        $sectionIds = array_values(array_unique(array_map('intval', $_POST['section_ids'] ?? [])));

        $startTs = $start !== '' ? strtotime($start) : false;
        $endTs   = $end   !== '' ? strtotime($end)   : false;
        $semOk   = $pdo->prepare('SELECT 1 FROM Semester WHERE semester_id=:id LIMIT 1');
        $semOk->execute([':id' => $semId]);

        if ($name === '' || $semId < 1 || !$startTs || !$endTs) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Vui lòng nhập tên đợt, học kỳ và thời gian bắt đầu/kết thúc hợp lệ.'];
        } elseif ($endTs <= $startTs) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Thời gian kết thúc phải sau thời gian bắt đầu.'];
        } elseif (!$semOk->fetch()) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Học kỳ không hợp lệ.'];
        } else {
            $startSql = date('Y-m-d H:i:s', $startTs);
            $endSql   = date('Y-m-d H:i:s', $endTs);

            try {
                $pdo->beginTransaction();
                if ($pid > 0) {
                    $old = $pdo->prepare('SELECT * FROM registrationperiod WHERE period_id=:id LIMIT 1');
                    $old->execute([':id' => $pid]);
                    $oldRow = $old->fetch();
                    $pdo->prepare(
                        'UPDATE registrationperiod SET semester_id=:sem, period_name=:name,
                                start_at=:s, end_at=:e, status=:st WHERE period_id=:id'
                    )->execute([':sem' => $semId, ':name' => $name, ':s' => $startSql, ':e' => $endSql, ':st' => $status, ':id' => $pid]);
                    AcademicAlertService::writeAuditLog($pdo, $adminAccId, 'UPDATE_REGISTRATION_PERIOD', 'RegistrationPeriod', (string)$pid,
                        json_encode($oldRow, JSON_UNESCAPED_UNICODE),
                        json_encode(['semester_id' => $semId, 'period_name' => $name, 'start_at' => $startSql, 'end_at' => $endSql, 'status' => $status], JSON_UNESCAPED_UNICODE));
                    $msg = 'Đã cập nhật đợt đăng ký "' . $name . '".';
                } else {
                    $pdo->prepare(
                        'INSERT INTO registrationperiod (semester_id, period_name, start_at, end_at, status, created_by)
                         VALUES (:sem, :name, :s, :e, :st, :by)'
                    )->execute([':sem' => $semId, ':name' => $name, ':s' => $startSql, ':e' => $endSql, ':st' => $status, ':by' => $adminAccId]);
                    $pid = (int)$pdo->lastInsertId();
                    AcademicAlertService::writeAuditLog($pdo, $adminAccId, 'CREATE_REGISTRATION_PERIOD', 'RegistrationPeriod', (string)$pid,
                        null, json_encode(['semester_id' => $semId, 'period_name' => $name, 'start_at' => $startSql, 'end_at' => $endSql, 'status' => $status], JSON_UNESCAPED_UNICODE));
                    $msg = 'Đã tạo đợt đăng ký "' . $name . '".';
                }

                $replaceMappings = !isset($_POST['keep_mappings']);

                if ($replaceMappings) {
                    $pdo->prepare('DELETE FROM registrationperiodmajor WHERE period_id=:id')->execute([':id' => $pid]);
                    if ($majorIds) {
                        $ins = $pdo->prepare('INSERT INTO registrationperiodmajor (period_id, major_id) VALUES (:pid, :mid)');
                        foreach ($majorIds as $mid) {
                            if ($mid > 0) $ins->execute([':pid' => $pid, ':mid' => $mid]);
                        }
                    }

                    $pdo->prepare('DELETE FROM registrationperiodsection WHERE period_id=:id')->execute([':id' => $pid]);
                    if ($sectionIds) {
                        $insSec = $pdo->prepare('INSERT INTO registrationperiodsection (period_id, section_id) VALUES (:pid, :sid)');
                        foreach ($sectionIds as $sid) {
                            if ($sid > 0) $insSec->execute([':pid' => $pid, ':sid' => $sid]);
                        }
                    }
                }

                $pdo->commit();
                $_SESSION['_flash'] = ['type' => 'success', 'message' => $msg];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['_flash'] = ['type' => 'error', 'message' => 'Không thể lưu đợt đăng ký: ' . $e->getMessage()];
            }
        }
        header('Location: registration_periods.php?period=' . $pid); exit;
    }

    if ($action === 'finalize_period') {
        $pid = (int)($_POST['period_id'] ?? 0);
        $res = RegistrationService::finalizePeriod($pdo, $pid, $adminAccId);
        $_SESSION['_flash'] = ['type' => $res['ok'] ? 'success' : 'error', 'message' => $res['message']];
        header('Location: registration_periods.php?period=' . $pid . '&view=monitor'); exit;
    }

    if ($action === 'override_section') {
        $pid   = (int)($_POST['period_id'] ?? 0);
        $sid   = (int)($_POST['section_id'] ?? 0);
        $new   = $_POST['new_status'] ?? '';
        $note  = trim($_POST['note'] ?? '');
        $res   = RegistrationService::overrideSectionStatus($pdo, $sid, $new, $note, $adminAccId);
        $_SESSION['_flash'] = ['type' => $res['ok'] ? 'success' : 'error', 'message' => $res['message']];
        header('Location: registration_periods.php?period=' . $pid . '&view=monitor'); exit;
    }
}

$flash = $_SESSION['_flash'] ?? null;
unset($_SESSION['_flash']);

$periods   = $pdo->query(
    "SELECT rp.*, sem.semester_name, sem.academic_year, sem.status AS semester_status,
            (SELECT COUNT(*) FROM registrationperiodmajor rpm WHERE rpm.period_id=rp.period_id) AS major_count,
            (SELECT COUNT(*) FROM registrationperiodsection rps WHERE rps.period_id=rp.period_id) AS section_count
     FROM registrationperiod rp JOIN Semester sem ON sem.semester_id=rp.semester_id
     ORDER BY rp.start_at DESC"
)->fetchAll();
$semesters = $pdo->query('SELECT semester_id, semester_name, academic_year, status FROM Semester ORDER BY start_date DESC')->fetchAll();
$majors    = $pdo->query(
    "SELECT m.major_id, m.major_code, m.major_name, d.department_code
     FROM Major m JOIN Department d ON d.department_id=m.department_id
     WHERE m.is_active=1 ORDER BY d.department_code, m.major_code"
)->fetchAll();

$selPid = (int)($_GET['period'] ?? 0);
if (!$selPid && $periods) $selPid = (int)$periods[0]['period_id'];
$selPeriod = null;
foreach ($periods as $p) { if ((int)$p['period_id'] === $selPid) { $selPeriod = $p; break; } }

$view = ($_GET['view'] ?? '') === 'monitor' ? 'monitor' : 'config';

$mappedMajorIds   = [];
$mappedSectionIds = [];
$periodSections   = [];
$periodStats      = ['pending' => 0, 'confirmed' => 0, 'insufficient' => 0, 'cancelled' => 0, 'students' => 0];
if ($selPeriod) {
    $q = $pdo->prepare('SELECT major_id FROM registrationperiodmajor WHERE period_id=:id');
    $q->execute([':id' => $selPid]);
    $mappedMajorIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

    $q = $pdo->prepare('SELECT section_id FROM registrationperiodsection WHERE period_id=:id');
    $q->execute([':id' => $selPid]);
    $mappedSectionIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

    $q = $pdo->prepare(
        "SELECT cs.*, c.course_code, c.course_name, c.credits, l.full_name AS lecturer_name,
                (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=cs.section_id
                  AND e.status IN ('Enrolled','Completed')) AS enrolled
         FROM ClassSection cs
         JOIN Course c ON c.course_id=cs.course_id
         JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
         WHERE cs.semester_id=:sem
         ORDER BY c.course_code, cs.section_code"
    );
    $q->execute([':sem' => (int)$selPeriod['semester_id']]);
    $periodSections = $q->fetchAll();

    foreach ($periodSections as $s) {
        $st = $s['section_status'];
        if (isset($periodStats[strtolower($st)])) $periodStats[strtolower($st)]++;
        $periodStats['students'] += (int)$s['enrolled'];
    }
}

$allCount = (int)$pdo->query('SELECT COUNT(*) FROM registrationperiod')->fetchColumn();
$openCount = 0;
foreach ($periods as $p) { if ($p['status'] === 'Open') $openCount++; }
$sectionTotal = (int)$pdo->query('SELECT COUNT(*) FROM ClassSection')->fetchColumn();

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Đợt đăng ký môn học';
$activePage = 'registration_periods';
$breadcrumb = 'Quản trị › Đợt đăng ký & mở lớp';
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
    <a href="enrollment.php?tab=semesters" class="abtn abtn--outline">📅 Cấu hình học kỳ</a>
    <a href="courses.php?tab=sections" class="abtn abtn--outline">🏫 Lớp học phần</a>
    <button onclick="openPeriodModal(null)" class="abtn abtn--primary" style="background:#0d1b2e;">➕ Tạo đợt đăng ký</button>
</div>

<div style="padding:10px 14px;border-radius:10px;background:#eff6ff;border:1px solid #bfdbfe;font-size:12px;color:#1e40af;line-height:1.55;">
    Lớp tự mở khi số đăng ký đạt <b>floor(sĩ số tối thiểu / 2) + 1</b> (min 48 → 25; min 30 → 16). Lớp đã mở vẫn giữ trạng thái mở nếu sinh viên hủy bớt. Khi chốt đợt, lớp chưa đạt ngưỡng chuyển <b>Không đủ sĩ số</b> và sinh viên được thông báo.
</div>

<div class="adm-kpi-row">
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Đợt đăng ký</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-schedule.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $allCount ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Đang mở</span><span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-icon-credits.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $openCount ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Lớp đã mở</span><span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-icon-degree-check.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $periodStats['confirmed'] ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Tổng lớp học phần</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-grades.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $sectionTotal ?></span></div></div>
</div>

<div style="display:grid;grid-template-columns:320px minmax(0,1fr);gap:16px;align-items:start;">
    <!-- Period list -->
    <div class="acard" style="padding:0;overflow:hidden;">
        <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;font-weight:700;color:#334155;">
            Danh sách đợt (<?= count($periods) ?>)
        </div>
        <?php if (empty($periods)): ?>
        <div style="padding:24px;text-align:center;color:#94a3b8;font-size:13px;">Chưa có đợt đăng ký nào.</div>
        <?php else: foreach ($periods as $p):
            $pMeta = ['Open' => ['Đang mở','#d1fae5','#065f46'], 'Upcoming' => ['Sắp mở','#fef9c3','#854d0e'], 'Closed' => ['Đã đóng','#f1f5f9','#64748b']][$p['status']] ?? ['—','#f1f5f9','#64748b'];
        ?>
        <a href="registration_periods.php?period=<?= (int)$p['period_id'] ?>&view=<?= $view ?>"
           style="display:block;padding:12px 16px;border-bottom:1px solid #f8fafc;text-decoration:none;
                  background:<?= (int)$p['period_id']===$selPid?'#eff4ff':'#fff' ?>;
                  border-left:<?= (int)$p['period_id']===$selPid?'3px solid #3b82f6':'3px solid transparent' ?>;">
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
                <div style="font-weight:700;font-size:13px;color:#0d1b2e;"><?= h($p['period_name']) ?></div>
                <span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:9999px;background:<?= $pMeta[1] ?>;color:<?= $pMeta[2] ?>;white-space:nowrap;"><?= $pMeta[0] ?></span>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:3px;"><?= h($p['semester_name']) ?> <?= h($p['academic_year']) ?></div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                <?= date('d/m/Y H:i', strtotime($p['start_at'])) ?> → <?= date('d/m/Y H:i', strtotime($p['end_at'])) ?>
            </div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                <?= (int)$p['major_count'] ? (int)$p['major_count'].' ngành' : 'Tất cả ngành' ?> •
                <?= (int)$p['section_count'] ? (int)$p['section_count'].' lớp' : 'Tất cả lớp' ?>
            </div>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <!-- Detail -->
    <div>
        <?php if (!$selPeriod): ?>
        <div class="acard" style="padding:40px;text-align:center;color:#94a3b8;">Chọn hoặc tạo một đợt đăng ký để cấu hình.</div>
        <?php else:
            $spMeta = ['Open' => ['Đang mở','#d1fae5','#065f46'], 'Upcoming' => ['Sắp mở','#fef9c3','#854d0e'], 'Closed' => ['Đã đóng','#f1f5f9','#64748b']][$selPeriod['status']] ?? ['—','#f1f5f9','#64748b'];
        ?>
        <div class="acard" style="padding:16px 20px;background:#eff4ff;border-left:4px solid #3b82f6;margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
                <div>
                    <div style="font-size:16px;font-weight:800;color:#1e40af;"><?= h($selPeriod['period_name']) ?></div>
                    <div style="font-size:12px;color:#3b82f6;margin-top:3px;">
                        <?= h($selPeriod['semester_name']) ?> <?= h($selPeriod['academic_year']) ?> •
                        <?= date('d/m/Y H:i', strtotime($selPeriod['start_at'])) ?> → <?= date('d/m/Y H:i', strtotime($selPeriod['end_at'])) ?>
                        • <span style="font-weight:700;color:<?= $spMeta[2] ?>;"><?= $spMeta[0] ?></span>
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="registration_periods.php?period=<?= $selPid ?>&view=<?= $view==='monitor'?'config':'monitor' ?>"
                       class="abtn abtn--outline abtn--sm"><?= $view==='monitor' ? '⚙ Cấu hình' : '📊 Giám sát lớp' ?></a>
                    <button onclick='openPeriodModal(<?= htmlspecialchars(json_encode($selPeriod, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>)'
                            class="abtn abtn--outline abtn--sm">✏ Sửa đợt</button>
                    <?php if ($selPeriod['status'] !== 'Closed'): ?>
                    <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Chốt đợt đăng ký? Lớp chưa đạt ngưỡng sẽ chuyển Không đủ sĩ số.')">
                        <?= csrf_input() ?>
                        <input type="hidden" name="_action" value="finalize_period" />
                        <input type="hidden" name="period_id" value="<?= $selPid ?>" />
                        <button type="submit" class="abtn abtn--primary abtn--sm" style="background:#0d1b2e;">🔒 Chốt đợt</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($view === 'config'): ?>
        <form method="POST" class="acard" style="padding:20px;">
            <?= csrf_input() ?>
            <input type="hidden" name="_action" value="save_period" />
            <input type="hidden" name="period_id" value="<?= $selPid ?>" />
            <div style="font-size:14px;font-weight:700;color:#0d1b2e;margin-bottom:12px;">⚙ Cấu hình đợt đăng ký</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên đợt *</label>
                    <input type="text" name="period_name" required maxlength="150" value="<?= h($selPeriod['period_name']) ?>"
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
                <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Học kỳ *</label>
                    <select name="semester_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                        <?php foreach ($semesters as $s): ?>
                        <option value="<?= (int)$s['semester_id'] ?>" <?= (int)$s['semester_id']===(int)$selPeriod['semester_id']?'selected':'' ?>>
                            <?= h($s['semester_name'].' '.$s['academic_year']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái</label>
                    <select name="status" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                        <?php foreach (['Upcoming'=>'Sắp mở','Open'=>'Đang mở','Closed'=>'Đã đóng'] as $sv=>$sl): ?>
                        <option value="<?= $sv ?>" <?= $selPeriod['status']===$sv?'selected':'' ?>><?= $sl ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bắt đầu *</label>
                    <input type="datetime-local" name="start_at" required value="<?= date('Y-m-d\TH:i', strtotime($selPeriod['start_at'])) ?>"
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
                <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Kết thúc *</label>
                    <input type="datetime-local" name="end_at" required value="<?= date('Y-m-d\TH:i', strtotime($selPeriod['end_at'])) ?>"
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            </div>

            <div style="margin-top:16px;">
                <div style="font-size:13px;font-weight:700;color:#0d1b2e;margin-bottom:4px;">Ngành được tham gia</div>
                <div style="font-size:11px;color:#64748b;margin-bottom:8px;">Không chọn ngành nào = mở cho tất cả ngành.</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;max-height:180px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:10px;">
                    <?php foreach ($majors as $m): ?>
                    <label style="display:flex;align-items:center;gap:5px;font-size:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;cursor:pointer;">
                        <input type="checkbox" name="major_ids[]" value="<?= (int)$m['major_id'] ?>" <?= in_array((int)$m['major_id'], $mappedMajorIds, true)?'checked':'' ?> />
                        <?= h($m['major_code'].' – '.$m['major_name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-top:16px;">
                <div style="font-size:13px;font-weight:700;color:#0d1b2e;margin-bottom:4px;">Lớp học phần được mở đăng ký</div>
                <div style="font-size:11px;color:#64748b;margin-bottom:8px;">Không chọn lớp nào = áp dụng cho tất cả lớp trong học kỳ.</div>
                <div style="max-height:260px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;">
                    <table class="adm-table" style="width:100%;">
                        <thead><tr><th style="width:36px;"></th><th>LỚP</th><th>MÔN HỌC</th><th>GIẢNG VIÊN</th><th style="text-align:center;">ĐK/MAX</th><th>TRẠNG THÁI</th></tr></thead>
                        <tbody>
                        <?php if (empty($periodSections)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">Học kỳ chưa có lớp học phần.</td></tr>
                        <?php else: foreach ($periodSections as $s):
                            $c = RegistrationService::capacityState($s, (int)$s['enrolled']);
                            $mt = RegistrationService::statusMeta($s['section_status']);
                        ?>
                        <tr>
                            <td style="text-align:center;"><input type="checkbox" name="section_ids[]" value="<?= (int)$s['section_id'] ?>" <?= in_array((int)$s['section_id'], $mappedSectionIds, true)?'checked':'' ?> /></td>
                            <td><span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:1px 6px;border-radius:4px;"><?= h($s['section_code']) ?></span></td>
                            <td style="font-size:12px;"><?= h($s['course_code'].' – '.$s['course_name']) ?></td>
                            <td style="font-size:12px;color:#64748b;"><?= h($s['lecturer_name']) ?></td>
                            <td style="text-align:center;font-weight:700;font-size:12px;"><?= (int)$s['enrolled'] ?>/<?= (int)$s['max_enrollment'] ?>
                                <div style="font-size:10px;font-weight:400;color:#94a3b8;">ngưỡng <?= (int)$c['threshold'] ?></div></td>
                            <td><span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:9999px;background:<?= $mt['bg'] ?>;color:<?= $mt['fg'] ?>;"><?= h($mt['label']) ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">💾 Lưu cấu hình đợt</button>
            </div>
        </form>

        <?php else: ?>
        <div class="acard" style="padding:0;overflow:hidden;">
            <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div style="font-size:13px;font-weight:700;color:#334155;">📊 Giám sát mở lớp (<?= count($periodSections) ?> lớp)</div>
                <div style="display:flex;gap:8px;font-size:11px;color:#64748b;flex-wrap:wrap;">
                    <span>Chờ mở: <b><?= $periodStats['pending'] ?></b></span>
                    <span>Đã mở: <b><?= $periodStats['confirmed'] ?></b></span>
                    <span>Không đủ: <b><?= $periodStats['insufficient'] ?></b></span>
                    <span>SV đăng ký: <b><?= $periodStats['students'] ?></b></span>
                </div>
            </div>
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead><tr><th>LỚP</th><th>MÔN HỌC</th><th>LỊCH HỌC</th><th style="text-align:center;">ĐĂNG KÝ</th><th style="text-align:center;">NGƯỠNG</th><th>TRẠNG THÁI</th><th>THAO TÁC QTV</th></tr></thead>
                    <tbody>
                    <?php if (empty($periodSections)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;">Không có lớp.</td></tr>
                    <?php else: foreach ($periodSections as $s):
                        $count = (int)$s['enrolled'];
                        $c     = RegistrationService::capacityState($s, $count);
                        $mt    = RegistrationService::statusMeta($s['section_status']);
                        $inPeriod = empty($mappedSectionIds) || in_array((int)$s['section_id'], $mappedSectionIds, true);
                    ?>
                    <tr style="<?= $inPeriod?'':'opacity:.45;' ?>">
                        <td><span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= h($s['section_code']) ?></span>
                            <?php if (!$inPeriod): ?><div style="font-size:9px;color:#94a3b8;">ngoài đợt</div><?php endif; ?></td>
                        <td style="font-size:12px;color:#0d1b2e;"><?= h($s['course_code']) ?><div style="font-size:11px;color:#64748b;"><?= h($s['course_name']) ?></div></td>
                        <td style="font-size:11px;color:#64748b;"><?= h($s['schedule'] ?: '—') ?><?= $s['room']?' • '.h($s['room']):'' ?></td>
                        <td style="text-align:center;font-weight:700;color:<?= $c['state']==='full'?'#ef4444':($c['state']==='open'?'#059669':'#b45309') ?>;">
                            <?= $count ?>/<?= (int)$s['max_enrollment'] ?>
                            <div style="height:5px;background:#e2e8f0;border-radius:9999px;margin-top:4px;overflow:hidden;">
                                <div style="height:100%;width:<?= (int)$c['pct'] ?>%;background:<?= $c['state']==='full'?'#ef4444':($c['state']==='open'?'#22c55e':'#f59e0b') ?>;"></div>
                            </div>
                        </td>
                        <td style="text-align:center;font-weight:700;"><?= (int)$c['threshold'] ?><div style="font-size:10px;font-weight:400;color:#94a3b8;">min <?= (int)$s['min_enrollment'] ?></div></td>
                        <td><span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:9999px;background:<?= $mt['bg'] ?>;color:<?= $mt['fg'] ?>;white-space:nowrap;"><?= h($mt['label']) ?></span>
                            <?php if ($s['status_note']): ?><div style="font-size:10px;color:#94a3b8;max-width:220px;"><?= h($s['status_note']) ?></div><?php endif; ?></td>
                        <td>
                            <form method="POST" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;margin:0;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="_action" value="override_section" />
                                <input type="hidden" name="period_id" value="<?= $selPid ?>" />
                                <input type="hidden" name="section_id" value="<?= (int)$s['section_id'] ?>" />
                                <select name="new_status" style="height:28px;border:1px solid #e2e8f0;border-radius:6px;font-size:11px;background:#fff;">
                                    <?php foreach (['Confirmed'=>'Mở lớp','Pending'=>'Chờ','Insufficient'=>'Không đủ','Cancelled'=>'Hủy'] as $sv=>$sl): ?>
                                    <option value="<?= $sv ?>" <?= $s['section_status']===$sv?'selected':'' ?>><?= $sl ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="note" maxlength="200" placeholder="Lý do"
                                       style="height:28px;width:90px;border:1px solid #e2e8f0;border-radius:6px;font-size:11px;padding:0 6px;outline:none;" />
                                <button type="submit" class="abtn abtn--outline abtn--sm" style="font-size:10px;padding:2px 6px;"
                                        onclick="return confirm('Xác nhận đổi trạng thái lớp <?= h($s['section_code']) ?>? Thao tác được ghi nhật ký.')">⚡</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL: CREATE/EDIT PERIOD -->
<div id="modalPeriod" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:520px;padding:28px;position:relative;max-height:92vh;overflow-y:auto;">
    <button onclick="closeModal('modalPeriod')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 id="periodModalTitle" style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:16px;">➕ Tạo đợt đăng ký</h2>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="save_period" />
        <input type="hidden" name="period_id" id="pm_id" value="0" />
        <input type="hidden" name="keep_mappings" value="1" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên đợt *</label>
                <input type="text" name="period_name" id="pm_name" required maxlength="150" placeholder="VD: Đợt đăng ký Học kỳ 2 2026-2027"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Học kỳ *</label>
                <select name="semester_id" id="pm_sem" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($semesters as $s): ?>
                    <option value="<?= (int)$s['semester_id'] ?>"><?= h($s['semester_name'].' '.$s['academic_year']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái</label>
                <select name="status" id="pm_status" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="Upcoming">Sắp mở</option>
                    <option value="Open">Đang mở</option>
                    <option value="Closed">Đã đóng</option>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bắt đầu *</label>
                <input type="datetime-local" name="start_at" id="pm_start" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Kết thúc *</label>
                <input type="datetime-local" name="end_at" id="pm_end" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
        </div>
        <div style="font-size:11px;color:#64748b;margin-top:10px;background:#f8fafc;border-radius:8px;padding:8px 10px;">
            Đợt mới mặc định mở cho tất cả ngành và tất cả lớp của học kỳ. Cấu hình chi tiết ngành/lớp sau khi tạo.
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalPeriod')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu đợt</button>
        </div>
    </form>
</div></div>

<script>
function showModal(id){var el=document.getElementById(id);if(el){el.style.display='flex';document.body.style.overflow='hidden';}}
function closeModal(id){var el=document.getElementById(id);if(el){el.style.display='none';document.body.style.overflow='';}}
function toLocalInput(v){return v?String(v).replace(' ','T').slice(0,16):'';}
function openPeriodModal(p){
    if(!p){
        document.getElementById('periodModalTitle').textContent='➕ Tạo đợt đăng ký';
        document.getElementById('pm_id').value=0;
        document.getElementById('pm_name').value='';
        document.getElementById('pm_status').value='Upcoming';
        document.getElementById('pm_start').value='';
        document.getElementById('pm_end').value='';
        var sem=document.getElementById('pm_sem');
        if(sem && sem.options.length) sem.selectedIndex=0;
    } else {
        document.getElementById('periodModalTitle').textContent='✏ Chỉnh sửa đợt đăng ký';
        document.getElementById('pm_id').value=p.period_id;
        document.getElementById('pm_name').value=p.period_name||'';
        document.getElementById('pm_sem').value=p.semester_id;
        document.getElementById('pm_status').value=p.status||'Upcoming';
        document.getElementById('pm_start').value=toLocalInput(p.start_at);
        document.getElementById('pm_end').value=toLocalInput(p.end_at);
    }
    showModal('modalPeriod');
}
document.getElementById('modalPeriod').addEventListener('click',function(e){if(e.target===this)closeModal('modalPeriod');});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal('modalPeriod');});
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
