<?php
/**
 * UniPortal – Cài đặt hệ thống (Admin Settings)
 * Views/edits: GradeScale, GradingConfig, Semesters, system info.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: ' . app_url('assets/student/dashboard.php')); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: ' . app_url('assets/lecturer/dashboard.php')); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo = get_db();

// ── POST handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_policy') {
        require_once $root . '/src/src/services/SystemConfig.php';
        $numeric = [
            'gpa_warning_threshold', 'gpa_suspension_threshold',
            'attendance_warn_pct', 'attendance_ban_pct',
            'grad_excellent_gpa', 'grad_good_gpa',
            'grad_average_gpa', 'grad_pass_gpa',
            'required_credits', 'tuition_per_credit_vnd',
            'retake_allowed_max_gpa', 'plan_credits_per_semester',
            'late_submission_allowed',
            'registration_min_credits', 'registration_max_credits',
            'attendance_eligibility_warn_margin',
        ];
        $gpaKeys = ['gpa_warning_threshold', 'gpa_suspension_threshold', 'grad_excellent_gpa',
                    'grad_good_gpa', 'grad_average_gpa', 'grad_pass_gpa', 'retake_allowed_max_gpa'];
        $saved = 0;
        foreach ($numeric as $k) {
            if (!isset($_POST[$k]) || $_POST[$k] === '' || !is_numeric($_POST[$k])) continue;
            $v = (float)$_POST[$k];
            if (in_array($k, $gpaKeys, true))       $v = max(0, min(4, $v));
            if ($k === 'plan_credits_per_semester') $v = max(3, min(30, (int)$v));
            if ($k === 'required_credits')           $v = max(1, min(300, (int)$v));
            if ($k === 'attendance_warn_pct' || $k === 'attendance_ban_pct') $v = max(0, min(100, (int)$v));
            if ($k === 'registration_min_credits') $v = max(0, min(60, (int)$v));
            if ($k === 'registration_max_credits') $v = max(1, min(60, (int)$v));
            if ($k === 'attendance_eligibility_warn_margin') $v = max(0, min(50, (int)$v));
            SystemConfig::set($k, (string)$v);
            $saved++;
        }
        // Keep the credit range coherent if both values were posted.
        $cMin = SystemConfig::getInt('registration_min_credits', 14);
        $cMax = SystemConfig::getInt('registration_max_credits', 24);
        if ($cMax < $cMin) SystemConfig::set('registration_max_credits', (string)$cMin);

        foreach (['attendance_late_absent', 'attendance_excused_absent'] as $k) {
            SystemConfig::set($k, ($_POST[$k] ?? '0') === '1' ? '1' : '0');
            $saved++;
        }

        $policy = (string)($_POST['retake_policy'] ?? '');
        if (in_array($policy, ['count_all', 'replace', 'best'], true)) {
            SystemConfig::set('retake_policy', $policy);
            $saved++;
        }
        $excl = strtoupper(trim((string)($_POST['gpa_excluded_codes'] ?? '')));
        $excl = implode(',', array_values(array_filter(array_map('trim', explode(',', $excl)), 'strlen')));
        SystemConfig::set('gpa_excluded_codes', $excl);
        $saved++;

        SystemConfig::set('ai_enabled', ($_POST['ai_enabled'] ?? '0') === '1' ? '1' : '0');
        $saved++;
        $aiUrl = trim((string)($_POST['ai_api_url'] ?? ''));
        if ($aiUrl !== '' && !preg_match('#^https?://#i', $aiUrl)) {
            $_SESSION['_flash'] = ['type' => 'error', 'message' => 'AI API URL phải bắt đầu bằng http:// hoặc https://.'];
            header('Location: config.php?tab=policy'); exit;
        }
        SystemConfig::set('ai_api_url', $aiUrl);
        SystemConfig::set('ai_model', trim((string)($_POST['ai_model'] ?? '')));
        $aiKey = trim((string)($_POST['ai_api_key'] ?? ''));
        if ($aiKey !== '') SystemConfig::set('ai_api_key', $aiKey);

        $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Đã lưu ' . $saved . ' cấu hình chính sách học vụ và AI.'];
        header('Location: config.php?tab=policy'); exit;
    }

    if ($action === 'save_grade_scale') {
        $pdo->beginTransaction();
        try {
            foreach (($_POST['scales'] ?? []) as $id => $row) {
                $id  = (int)$id;
                $min = (float)$row['min_score'];
                $max = (float)$row['max_score'];
                $gp  = (float)$row['grade_point'];
                if ($id > 0 && $max >= $min && $gp >= 0 && $gp <= 4) {
                    $pdo->prepare(
                        'UPDATE GradeScale SET min_score=:mn,max_score=:mx,grade_point=:gp WHERE grade_scale_id=:id'
                    )->execute([':mn'=>$min,':mx'=>$max,':gp'=>$gp,':id'=>$id]);
                }
            }
            $pdo->commit();
            $_SESSION['_flash'] = ['type'=>'success','message'=>'Đã lưu thang điểm.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Lỗi: '.$e->getMessage()];
        }
        header('Location: config.php?tab=gradescale'); exit;
    }

    if ($action === 'add_semester') {
        $name  = trim($_POST['semester_name']  ?? '');
        $year  = trim($_POST['academic_year']  ?? '');
        $start = trim($_POST['start_date']     ?? '');
        $end   = trim($_POST['end_date']       ?? '');
        $status= in_array($_POST['status']??'',['Upcoming','Active','Completed']) ? $_POST['status'] : 'Upcoming';
        if ($name===''||$year===''||$start===''||$end==='') {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Vui lòng điền đầy đủ thông tin học kỳ.'];
        } elseif ($end <= $start) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Ngày kết thúc phải sau ngày bắt đầu.'];
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO Semester (semester_name,academic_year,start_date,end_date,status)
                     VALUES (:name,:year,:start,:end,:status)'
                )->execute([':name'=>$name,':year'=>$year,':start'=>$start,':end'=>$end,':status'=>$status]);
                $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã thêm học kỳ: $name $year"];
            } catch (Exception $e) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Lỗi: '.$e->getMessage()];
            }
        }
        header('Location: config.php?tab=semesters'); exit;
    }

    if ($action === 'update_semester_status') {
        $sid    = (int)($_POST['semester_id'] ?? 0);
        $status = in_array($_POST['status']??'',['Upcoming','Active','Completed']) ? $_POST['status'] : '';
        if ($sid>0 && $status!=='') {
            // Only one Active semester at a time
            if ($status==='Active') {
                $pdo->query("UPDATE Semester SET status='Completed' WHERE status='Active'");
            }
            $pdo->prepare('UPDATE Semester SET status=:s WHERE semester_id=:id')
                ->execute([':s'=>$status,':id'=>$sid]);
            $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã cập nhật trạng thái học kỳ #$sid."];
        }
        header('Location: config.php?tab=semesters'); exit;
    }
}

// ── Flash ─────────────────────────────────────────────────────
$flash = '';
if (isset($_SESSION['_flash'])) { $flash=$_SESSION['_flash']['type'].':'.$_SESSION['_flash']['message']; unset($_SESSION['_flash']); }

$tab = $_GET['tab'] ?? 'gradescale';

function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

// ── Data ──────────────────────────────────────────────────────
$gradeScales = $pdo->query('SELECT * FROM GradeScale ORDER BY min_score DESC')->fetchAll();
$semesters   = $pdo->query('SELECT * FROM Semester ORDER BY start_date DESC')->fetchAll();

// System info
$dbVersion  = $pdo->query('SELECT VERSION() AS v')->fetchColumn();
$tableCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='uniportal'")->fetchColumn();

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Cài đặt hệ thống';
$activePage = 'settings';
$breadcrumb = 'Quản trị › Cài đặt';
require __DIR__ . '/_layout.php';
?>

<!-- Flash -->
<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:4px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success'?'✅':'⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="adm-tabs" style="background:#fff;border-radius:12px;padding:0 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <a href="config.php?tab=gradescale"  class="adm-tab <?= $tab==='gradescale' ?'active':'' ?>">⚖️ Thang điểm chữ</a>
    <a href="config.php?tab=semesters"   class="adm-tab <?= $tab==='semesters'  ?'active':'' ?>">📅 Quản lý học kỳ</a>
    <a href="config.php?tab=system"      class="adm-tab <?= $tab==='system'     ?'active':'' ?>">🖥️ Thông tin hệ thống</a>
    <a href="config.php?tab=policy"      class="adm-tab <?= $tab==='policy'     ?'active':'' ?>">⚙️ Chính sách &amp; AI</a>
</div>

<?php if ($tab === 'gradescale'): ?>
<!-- ── GRADE SCALE ──────────────────────────────────────────── -->
<div class="acard" style="padding:20px 24px;">
    <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:4px;">Thang điểm chữ (GPA)</div>
    <div style="font-size:13px;color:#64748b;margin-bottom:16px;">Chỉnh sửa dải điểm, điểm hệ 4 tương ứng với từng mức xếp loại.</div>
    <form method="POST">
                    <?= csrf_input() ?>
        <input type="hidden" name="_action" value="save_grade_scale" />
        <div class="adm-table-wrap">
            <table class="adm-table">
                <thead><tr><th>Điểm chữ</th><th>Xếp loại</th><th>Điểm tối thiểu (10)</th><th>Điểm tối đa (10)</th><th>Hệ 4</th></tr></thead>
                <tbody>
                    <?php foreach ($gradeScales as $gs): ?>
                    <tr>
                        <td style="font-weight:800;font-size:16px;color:#001634;"><?= h($gs['letter_grade']) ?></td>
                        <td style="font-size:13px;color:#64748b;"><?= h($gs['description'] ?? '') ?></td>
                        <td>
                            <input type="number" name="scales[<?= (int)$gs['grade_scale_id'] ?>][min_score]"
                                   value="<?= number_format((float)$gs['min_score'],2) ?>"
                                   min="0" max="10" step="0.01"
                                   style="width:80px;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:13px;outline:none;" />
                        </td>
                        <td>
                            <input type="number" name="scales[<?= (int)$gs['grade_scale_id'] ?>][max_score]"
                                   value="<?= number_format((float)$gs['max_score'],2) ?>"
                                   min="0" max="10" step="0.01"
                                   style="width:80px;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:13px;outline:none;" />
                        </td>
                        <td>
                            <input type="number" name="scales[<?= (int)$gs['grade_scale_id'] ?>][grade_point]"
                                   value="<?= number_format((float)$gs['grade_point'],1) ?>"
                                   min="0" max="4" step="0.1"
                                   style="width:72px;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:13px;font-weight:700;outline:none;" />
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:14px;">
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">💾 Lưu thang điểm</button>
        </div>
    </form>
</div>

<!-- GPA Classification reference -->
<div class="acard" style="padding:20px 24px;">
    <div style="font-size:15px;font-weight:700;color:#001634;margin-bottom:12px;">Xếp loại tốt nghiệp (tham khảo)</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
        <?php
        $classes = [
            ['Xuất sắc','≥ 3.60','#22c55e'],
            ['Giỏi','3.20 – 3.59','#3b82f6'],
            ['Khá','2.50 – 3.19','#f59e0b'],
            ['Trung bình','2.00 – 2.49','#f97316'],
            ['Yếu','< 2.00','#ef4444'],
        ];
        foreach ($classes as [$label, $range, $color]):
        ?>
        <div style="padding:14px;background:#f8fafc;border-radius:10px;border-left:4px solid <?= $color ?>;">
            <div style="font-weight:700;color:#001634;"><?= $label ?></div>
            <div style="font-size:13px;color:<?= $color ?>;font-weight:600;">GPA <?= $range ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($tab === 'semesters'): ?>
<!-- ── SEMESTERS ─────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <h2 style="font-size:16px;font-weight:700;color:#0d1b2e;">Danh sách học kỳ</h2>
    <button onclick="showModal('modalAddSem')" class="abtn abtn--primary" style="background:#0d1b2e;">➕ Thêm học kỳ</button>
</div>
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>Tên học kỳ</th><th>Năm học</th><th>Ngày bắt đầu</th><th>Ngày kết thúc</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
            <tbody>
                <?php foreach ($semesters as $s): ?>
                <tr>
                    <td style="font-weight:700;color:#0d1b2e;"><?= h($s['semester_name']) ?></td>
                    <td><?= h($s['academic_year']) ?></td>
                    <td><?= date('d/m/Y',strtotime($s['start_date'])) ?></td>
                    <td><?= date('d/m/Y',strtotime($s['end_date'])) ?></td>
                    <td>
                        <span class="apill <?= $s['status']==='Active'?'apill--green':($s['status']==='Upcoming'?'apill--blue':'apill--gray') ?>">
                            <?= $s['status']==='Active'?'Đang diễn ra':($s['status']==='Upcoming'?'Sắp tới':'Đã kết thúc') ?>
                        </span>
                    </td>
                    <td>
                        <form method="POST" style="display:inline-flex;gap:4px;">
                    <?= csrf_input() ?>
                            <input type="hidden" name="_action"     value="update_semester_status" />
                            <input type="hidden" name="semester_id" value="<?= (int)$s['semester_id'] ?>" />
                            <?php if ($s['status']!=='Active'): ?>
                            <button type="submit" name="status" value="Active" class="abtn abtn--green abtn--sm"
                                    onclick="return confirm('Đặt học kỳ này làm Active? Học kỳ Active hiện tại sẽ chuyển sang Completed.')">
                                ✅ Kích hoạt
                            </button>
                            <?php endif; ?>
                            <?php if ($s['status']==='Active'): ?>
                            <button type="submit" name="status" value="Completed" class="abtn abtn--outline abtn--sm"
                                    onclick="return confirm('Kết thúc học kỳ này?')">
                                🏁 Kết thúc
                            </button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add semester -->
<div id="modalAddSem" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:460px;padding:28px;position:relative;">
    <button onclick="closeModal('modalAddSem')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">➕ Thêm học kỳ mới</h2>
    <form method="POST">
                    <?= csrf_input() ?>
        <input type="hidden" name="_action" value="add_semester" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên học kỳ *</label>
                <input type="text" name="semester_name" placeholder="VD: HK1" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Năm học *</label>
                <input type="text" name="academic_year" placeholder="VD: 2026-2027" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày bắt đầu *</label>
                <input type="date" name="start_date" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày kết thúc *</label>
                <input type="date" name="end_date" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái</label>
                <select name="status" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="Upcoming">Sắp tới</option>
                    <option value="Active">Đang diễn ra</option>
                    <option value="Completed">Đã kết thúc</option>
                </select></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalAddSem')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Thêm học kỳ</button>
        </div>
    </form>
</div></div>

<?php elseif ($tab === 'system'): ?>
<!-- ── SYSTEM INFO ────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="acard" style="padding:20px 24px;">
        <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:14px;">🖥️ Thông tin máy chủ</div>
        <?php
        $sysRows = [
            ['PHP Version', phpversion()],
            ['Database',   'MariaDB / MySQL '.$dbVersion],
            ['Tables',     $tableCount.' bảng trong uniportal'],
            ['Timezone',   date_default_timezone_get()],
            ['Server Time', date('d/m/Y H:i:s')],
        ];
        ?>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <?php foreach ($sysRows as [$k,$v]): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:8px 4px;font-weight:600;color:#334155;width:44%;"><?= h($k) ?></td>
                <td style="padding:8px 4px;color:#0d1b2e;font-family:monospace;"><?= h($v) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="acard" style="padding:20px 24px;">
        <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:14px;">📊 Thống kê cơ sở dữ liệu</div>
        <?php
        $dbStats = [
            ['Sinh viên', $pdo->query("SELECT COUNT(*) FROM Student")->fetchColumn()],
            ['Tài khoản', $pdo->query("SELECT COUNT(*) FROM Account")->fetchColumn()],
            ['Giảng viên', $pdo->query("SELECT COUNT(*) FROM Lecturer")->fetchColumn()],
            ['Môn học', $pdo->query("SELECT COUNT(*) FROM Course")->fetchColumn()],
            ['Lớp học phần', $pdo->query("SELECT COUNT(*) FROM ClassSection")->fetchColumn()],
            ['Đăng ký môn', $pdo->query("SELECT COUNT(*) FROM Enrollment")->fetchColumn()],
            ['Bản ghi điểm', $pdo->query("SELECT COUNT(*) FROM Grade")->fetchColumn()],
            ['Điểm danh', $pdo->query("SELECT COUNT(*) FROM Attendance")->fetchColumn()],
            ['Cảnh báo học vụ', $pdo->query("SELECT COUNT(*) FROM AcademicWarning")->fetchColumn()],
        ];
        ?>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <?php foreach ($dbStats as [$k,$v]): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:7px 4px;font-weight:600;color:#334155;width:60%;"><?= h($k) ?></td>
                <td style="padding:7px 4px;font-weight:800;color:#001634;font-size:16px;"><?= number_format((int)$v) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- Account credentials reminder -->
<div class="acard" style="padding:20px 24px;border-left:4px solid #f59e0b;">
    <div style="font-size:15px;font-weight:700;color:#001634;margin-bottom:8px;">🔑 Tài khoản mặc định</div>
    <table style="font-size:13px;border-collapse:collapse;">
        <tr><td style="padding:5px 12px 5px 0;font-weight:600;color:#44474e;">Admin:</td><td style="font-family:monospace;background:#f8fafc;padding:3px 8px;border-radius:4px;">academic_admin / password</td></tr>
        <tr><td style="padding:5px 12px 5px 0;font-weight:600;color:#44474e;">Giảng viên:</td><td style="font-family:monospace;background:#f8fafc;padding:3px 8px;border-radius:4px;">lecturer_mis / password</td></tr>
        <tr><td style="padding:5px 12px 5px 0;font-weight:600;color:#44474e;">Sinh viên:</td><td style="font-family:monospace;background:#f8fafc;padding:3px 8px;border-radius:4px;">23070001 / password</td></tr>
    </table>
    <div style="font-size:12px;color:#f59e0b;margin-top:8px;">⚠️ Đổi mật khẩu mặc định sau khi triển khai thực tế.</div>
</div>

<?php elseif ($tab === 'policy'): ?>
<?php
require_once $root . '/src/src/services/SystemConfig.php';
SystemConfig::load();
$currentPolicy = SystemConfig::get('retake_policy', 'count_all');
$aiKeyStored   = SystemConfig::get('ai_api_key', '') !== '';
$policyOptions = [
    'count_all' => 'Tính tất cả các lần học (mặc định hiện hành)',
    'replace'   => 'Lần học gần nhất thay thế điểm cũ',
    'best'      => 'Chỉ tính điểm cao nhất của môn',
];
$policyFields = [
    ['gpa_warning_threshold',    'Ngưỡng cảnh báo GPA (LowGPA)', '2.00',  '0.01'],
    ['gpa_suspension_threshold', 'Ngưỡng nguy cơ đình chỉ',      '1.50',  '0.01'],
    ['attendance_warn_pct',      'Vắng % tối thiểu (cảnh báo)',  '15',    '1'],
    ['attendance_ban_pct',       'Vắng % cấm thi',               '20',    '1'],
    ['attendance_eligibility_warn_margin', 'Biên cảnh báo cận ngưỡng cấm thi (%)', '5', '1'],
    ['registration_min_credits', 'Tín chỉ tối thiểu / học kỳ',    '14',    '1'],
    ['registration_max_credits', 'Tín chỉ tối đa / học kỳ',       '24',    '1'],
    ['grad_excellent_gpa',       'GPA Xuất sắc (≥)',              '3.60',  '0.01'],
    ['grad_good_gpa',            'GPA Giỏi (≥)',                  '3.20',  '0.01'],
    ['grad_average_gpa',         'GPA Khá (≥)',                   '2.50',  '0.01'],
    ['grad_pass_gpa',            'GPA Trung bình (≥)',            '2.00',  '0.01'],
    ['required_credits',         'Tổng TC tốt nghiệp',            '130',   '1'],
    ['tuition_per_credit_vnd',   'Học phí/TC (VNĐ)',              '500000','1000'],
    ['retake_allowed_max_gpa',   'GPA tối đa được phép cải thiện', '2.00', '0.01'],
    ['plan_credits_per_semester','Tín chỉ kế hoạch mỗi học kỳ',    '15',   '1'],
];
?>
<div class="acard" style="padding:22px 24px;">
    <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:4px;">⚙️ Chính sách GPA &amp; cải thiện điểm</div>
    <div style="font-size:12.5px;color:#64748b;margin-bottom:16px;">
        Các thay đổi ảnh hưởng trực tiếp đến GPA tích lũy và phân tích lộ trình của sinh viên.
    </div>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="save_policy" />

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <?php foreach ($policyFields as [$key, $label, $default, $step]): ?>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;"><?= h($label) ?></label>
                <input type="number" name="<?= $key ?>" value="<?= h(SystemConfig::get($key, $default)) ?>"
                       step="<?= $step ?>" min="0"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #eef2f7;">
            <div style="font-size:14px;font-weight:700;color:#001634;margin-bottom:4px;">🧮 Chính sách tính vắng &amp; điều kiện dự thi</div>
            <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
                Tỷ lệ vắng luôn tính trên <strong>tổng số buổi học chính thức</strong> của lớp (đã loại buổi hủy).
                Buổi học tương lai và buổi chưa điểm danh không bị coi là vắng. Chỉ khi vắng <strong>vượt quá</strong>
                ngưỡng cấm thi sinh viên mới mất điều kiện dự thi; vắng đúng bằng ngưỡng vẫn đủ điều kiện.
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#334155;margin-bottom:8px;">
                <input type="checkbox" name="attendance_late_absent" value="1"
                       <?= SystemConfig::get('attendance_late_absent', '0') === '1' ? 'checked' : '' ?> />
                Tính lượt <strong>đi muộn</strong> là vắng khi xét điều kiện dự thi
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#334155;">
                <input type="checkbox" name="attendance_excused_absent" value="1"
                       <?= SystemConfig::get('attendance_excused_absent', '0') === '1' ? 'checked' : '' ?> />
                Tính lượt <strong>vắng có phép</strong> là vắng khi xét điều kiện dự thi
            </label>
        </div>

        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #eef2f7;">
            <div style="font-size:14px;font-weight:700;color:#001634;margin-bottom:4px;">🔄 Chính sách học lại</div>
            <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
                Hệ thống hiện không có quy định học lại cố định trong CSDL, vì vậy chính sách này được cấu hình bởi Admin.
                Thay đổi sẽ làm thay đổi GPA tích lũy được tính toán (không sửa bảng điểm gốc).
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div>
                    <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Cách tính khi học lại</label>
                    <select name="retake_policy" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 8px;font-size:13px;background:#fff;">
                        <?php foreach ($policyOptions as $val => $lab): ?>
                        <option value="<?= h($val) ?>" <?= $currentPolicy === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">
                        Mã môn loại trừ khỏi GPA (cách nhau bởi dấu phẩy)
                    </label>
                    <input type="text" name="gpa_excluded_codes"
                           value="<?= h(SystemConfig::get('gpa_excluded_codes', '')) ?>"
                           placeholder="VD: PE1001,PE1002"
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                </div>
            </div>
            <div style="font-size:11.5px;color:#b45309;margin-top:8px;">
                ⚠️ “Tính tất cả các lần học” là hành vi đang áp dụng của hệ thống trước thay đổi này. Chỉ chọn chính sách khác khi nhà trường có quy định chính thức.
            </div>
        </div>

        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #eef2f7;">
            <div style="font-size:14px;font-weight:700;color:#001634;margin-bottom:4px;">🤖 Trợ lý AI (tùy chọn)</div>
            <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
                Khi chưa cấu hình, GPA Planner dùng bộ phân tích dựa trên quy tắc và hiển thị rõ nguồn.
                Chỉ các dữ liệu học vụ tổng hợp được gửi đi (không gửi họ tên, mã sinh viên, email, mật khẩu).
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#334155;margin-bottom:10px;">
                <input type="checkbox" name="ai_enabled" value="1" <?= SystemConfig::get('ai_enabled', '0') === '1' ? 'checked' : '' ?> />
                Bật diễn giải bằng AI
            </label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div>
                    <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">API URL (OpenAI-compatible)</label>
                    <input type="url" name="ai_api_url" value="<?= h(SystemConfig::get('ai_api_url', '')) ?>"
                           placeholder="https://api.openai.com/v1/chat/completions"
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Model</label>
                    <input type="text" name="ai_model" value="<?= h(SystemConfig::get('ai_model', '')) ?>"
                           placeholder="VD: gpt-4o-mini"
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                </div>
                <div style="grid-column:1/-1;">
                    <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">API Key</label>
                    <input type="password" name="ai_api_key" autocomplete="new-password"
                           placeholder="<?= $aiKeyStored ? 'Đã lưu khóa – để trống để giữ nguyên' : 'Chưa cấu hình' ?>"
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Khóa được lưu trong SystemConfig và không hiển thị lại. Kết nối tới localhost/mạng nội bộ bị chặn.</div>
                </div>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:16px;">
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">💾 Lưu chính sách</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
function showModal(id){document.getElementById(id).style.display='flex';document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).style.display='none';document.body.style.overflow='';}
var m=document.getElementById('modalAddSem');
if(m)m.addEventListener('click',function(e){if(e.target===this)closeModal('modalAddSem');});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal('modalAddSem');});
</script>
