<?php
/**
 * UniPortal – Lịch giảng dạy chính thức (Admin)
 * Admin defines the weekly teaching schedule; sessions are generated
 * automatically for the whole semester.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/TeachingSchedule.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: ' . app_url('assets/student/dashboard.php')); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: ' . app_url('assets/lecturer/dashboard.php')); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo = get_db();
$accountId = (int)($_SESSION['account_id'] ?? 0);

if (isset($_SESSION['admin_schedule_flash'])) {
    $flash = $_SESSION['admin_schedule_flash'];
    unset($_SESSION['admin_schedule_flash']);
}

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_schedule') {
        $res = TeachingSchedule::saveSchedule($_POST, $accountId);
        $_SESSION['admin_schedule_flash'] = ($res['ok'] ? 'success' : 'error') . ':' . $res['message'];
        $qs = 'sem=' . (int)($_POST['semester_id'] ?? 0);
        if (!empty($_POST['course_id'])) $qs .= '&course=' . (int)$_POST['course_id'];
        header('Location: schedule.php?' . $qs);
        exit;
    }

    if ($action === 'sync_schedule') {
        $sid = (int)($_POST['schedule_id'] ?? 0);
        $sch = $pdo->prepare(
            'SELECT ts.*, cs.semester_id FROM TeachingSchedule ts
             JOIN ClassSection cs ON cs.section_id = ts.section_id
             WHERE ts.schedule_id = :id LIMIT 1'
        );
        $sch->execute([':id' => $sid]);
        $schedule = $sch->fetch();
        if ($schedule) {
            try {
                $r = TeachingSchedule::syncSessions($schedule);
                $_SESSION['admin_schedule_flash'] = 'success:Đã đồng bộ: tạo mới ' . $r['inserted'] .
                    ' buổi, cập nhật ' . $r['updated'] . ', gỡ ' . $r['removed'] .
                    ' buổi chưa diễn ra. Tổng số buổi chính thức: ' . $r['total'] . '/' . $r['expected'] . '.';
            } catch (Throwable $e) {
                $_SESSION['admin_schedule_flash'] = 'error:' . $e->getMessage();
            }
        } else {
            $_SESSION['admin_schedule_flash'] = 'error:Không tìm thấy lịch cần đồng bộ.';
        }
        header('Location: schedule.php?sem=' . (int)($_POST['semester_id'] ?? 0));
        exit;
    }
}

// ── Data ─────────────────────────────────────────────────────
$semesters = TeachingSchedule::getSemesters();
$activeSem = null;
foreach ($semesters as $sem) { if ($sem['status'] === 'Active') { $activeSem = $sem; break; } }
if (!$activeSem && !empty($semesters)) $activeSem = $semesters[0];

$semId = (int)($_GET['sem'] ?? ($activeSem['semester_id'] ?? 0));
$semInfo = null;
foreach ($semesters as $sem) { if ((int)$sem['semester_id'] === $semId) { $semInfo = $sem; break; } }

$filterCourse   = (int)($_GET['course'] ?? 0);
$filterLecturer = (int)($_GET['lecturer'] ?? 0);

$courses   = TeachingSchedule::getCourses($semId);
$sections  = TeachingSchedule::getSections($semId);
$lecturers = TeachingSchedule::getLecturers();
$schedules = TeachingSchedule::getSchedules($semId, $filterCourse ?: null, $filterLecturer ?: null);

$pendingCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM ScheduleChangeRequest WHERE status='Pending'"
)->fetchColumn();
$cancelledCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM AttendanceSession WHERE status='Cancelled'"
)->fetchColumn();
$makeupCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM AttendanceSession WHERE session_type='Makeup'"
)->fetchColumn();

$pageTitle  = 'Lịch giảng dạy';
$activePage = 'schedule';
$breadcrumb = 'Quản trị › Đào tạo & Lịch học';
$warnCount  = 0;
require __DIR__ . '/_layout.php';

function ts_time(string $t): string { return substr($t, 0, 5); }
?>

<?php if (isset($flash)): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft === 'success' ? '#d1fae5' : '#fee2e2' ?>;
            border:1px solid <?= $ft === 'success' ? '#6ee7b7' : '#fca5a5' ?>;
            color:<?= $ft === 'success' ? '#065f46' : '#b91c1c' ?>;">
    <?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px;">
    <a href="schedule_requests.php" class="abtn abtn--outline">
        📨 Đề xuất thay đổi lịch học
        <?php if ($pendingCount): ?><span class="apill apill--orange" style="margin-left:4px;"><?= $pendingCount ?> chờ</span><?php endif; ?>
    </a>
</div>

<!-- KPI -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Lịch tuần đang áp dụng</span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= count($schedules) ?></span></div>
        <div class="adm-kpi__sub">Theo học kỳ đang chọn</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Đề xuất chờ duyệt</span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $pendingCount ?></span></div>
        <div class="adm-kpi__sub">Toàn hệ thống</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Buổi đã hủy</span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $cancelledCount ?></span></div>
        <div class="adm-kpi__sub">Lịch sử được giữ nguyên</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header"><span class="adm-kpi__label">Buổi học bù</span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $makeupCount ?></span></div>
        <div class="adm-kpi__sub">Đã được duyệt</div>
    </div>
</div>

<!-- FILTERS -->
<div class="acard" style="padding:16px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Học kỳ</label>
            <select name="sem" onchange="this.form.submit()" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:190px;">
                <?php foreach ($semesters as $sem): ?>
                <option value="<?= (int)$sem['semester_id'] ?>" <?= (int)$sem['semester_id'] === $semId ? 'selected' : '' ?>>
                    <?= h($sem['semester_name'] . ' – ' . $sem['academic_year']) ?><?= $sem['status'] === 'Active' ? ' (hiện tại)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Môn học</label>
            <select name="course" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:220px;">
                <option value="">Tất cả môn học</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?= (int)$c['course_id'] ?>" <?= $filterCourse === (int)$c['course_id'] ? 'selected' : '' ?>>
                    <?= h($c['course_code'] . ' – ' . $c['course_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giảng viên</label>
            <select name="lecturer" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:200px;">
                <option value="">Tất cả giảng viên</option>
                <?php foreach ($lecturers as $l): ?>
                <option value="<?= (int)$l['lecturer_id'] ?>" <?= $filterLecturer === (int)$l['lecturer_id'] ? 'selected' : '' ?>>
                    <?= h($l['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lọc</button>
        <a href="schedule.php?sem=<?= $semId ?>" class="abtn abtn--outline">Đặt lại</a>
    </form>
</div>

<!-- FORM -->
<div class="acard" style="padding:20px 24px;">
    <div class="adm-section-title" style="margin-bottom:2px;">Thiết lập lịch tuần &amp; phân công giảng viên</div>
    <div class="adm-section-sub" style="margin-bottom:14px;">
        Lưu lịch sẽ tự động tạo các buổi học đúng thứ trong tuần từ ngày bắt đầu đến ngày kết thúc.
        Buổi đã diễn ra và dữ liệu điểm danh không bị thay đổi khi lịch được cập nhật.
    </div>
    <form method="POST" id="scheduleForm">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="save_schedule" />
        <input type="hidden" name="semester_id" value="<?= $semId ?>" />
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Môn học *</label>
                <select name="course_id" id="sfCourse" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;">
                    <option value="">— Chọn môn —</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= (int)$c['course_id'] ?>"><?= h($c['course_code'] . ' – ' . $c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Lớp học phần *</label>
                <select name="section_id" id="sfSection" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;">
                    <option value="">— Chọn lớp —</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giảng viên phụ trách *</label>
                <select name="lecturer_id" id="sfLecturer" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;">
                    <option value="">— Chọn giảng viên —</option>
                    <?php foreach ($lecturers as $l): ?>
                    <option value="<?= (int)$l['lecturer_id'] ?>"><?= h($l['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày dạy trong tuần *</label>
                <select name="day_of_week" id="sfDay" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;">
                    <?php foreach (TeachingSchedule::DAYS as $d => $label): ?>
                    <option value="<?= $d ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giờ bắt đầu *</label>
                <input type="time" name="start_time" id="sfStart" value="09:15" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giờ kết thúc *</label>
                <input type="time" name="end_time" id="sfEnd" value="10:45" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Phòng học</label>
                <input type="text" name="room" id="sfRoom" maxlength="30" required placeholder="VD: B1.401" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày bắt đầu áp dụng *</label>
                <input type="date" name="effective_start" id="sfStartDate" value="<?= h($semInfo['start_date'] ?? '') ?>" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày kết thúc áp dụng *</label>
                <input type="date" name="effective_end" id="sfEndDate" value="<?= h($semInfo['end_date'] ?? '') ?>" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Số buổi dự kiến *</label>
                <input type="number" name="expected_sessions" id="sfExpected" min="1" max="60" required
                       title="Mặc định = số tín chỉ × 5 (2 tín chỉ = 10 buổi, 3 tín chỉ = 15 buổi)"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;" />
                <div id="sfExpectedHint" style="font-size:11px;color:#94a3b8;margin-top:3px;">Mặc định: tín chỉ × 5</div>
            </div>
            <div style="display:flex;align-items:flex-end;gap:8px;">
                <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">💾 Lưu lịch &amp; sinh buổi học</button>
                <button type="reset" class="abtn abtn--outline" onclick="resetScheduleForm()">Làm mới</button>
            </div>
        </div>
        <div id="sfSectionInfo" style="margin-top:12px;font-size:12px;color:#64748b;"></div>
    </form>
</div>

<!-- TABLE -->
<div class="acard" style="padding:0;overflow:hidden;">
    <div style="padding:14px 16px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div style="font-size:14px;font-weight:700;color:#0d1b2e;">Lịch tuần đã thiết lập</div>
        <span style="font-size:12px;color:#64748b;"><?= count($schedules) ?> lịch • <?= h($semInfo['semester_name'] ?? '') ?> <?= h($semInfo['academic_year'] ?? '') ?></span>
    </div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>MÔN HỌC</th>
                    <th>LỚP</th>
                    <th>GIẢNG VIÊN</th>
                    <th>THỨ</th>
                    <th>THỜI GIAN</th>
                    <th>PHÒNG</th>
                    <th>ÁP DỤNG</th>
                    <th style="text-align:center;">BUỔI (TẠO / DỰ KIẾN)</th>
                    <th>THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schedules)): ?>
                <tr><td colspan="9" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Chưa có lịch giảng dạy cho học kỳ này.</td></tr>
                <?php else: ?>
                <?php foreach ($schedules as $sc): ?>
                <tr>
                    <td>
                        <div style="font-weight:700;color:#0d1b2e;"><?= h($sc['course_code']) ?></div>
                        <div style="font-size:11px;color:#64748b;"><?= h($sc['course_name']) ?></div>
                    </td>
                    <td style="font-weight:600;"><?= h($sc['section_code']) ?></td>
                    <td style="font-size:12px;"><?= h($sc['lecturer_name']) ?></td>
                    <td><span class="apill apill--blue"><?= h(TeachingSchedule::DAYS[(int)$sc['day_of_week']] ?? '') ?></span></td>
                    <td style="font-size:12px;font-weight:600;white-space:nowrap;"><?= ts_time($sc['start_time']) ?>–<?= ts_time($sc['end_time']) ?></td>
                    <td style="font-size:12px;"><?= h($sc['room'] ?: '—') ?></td>
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;">
                        <?= date('d/m/Y', strtotime($sc['effective_start'])) ?><br/>→ <?= date('d/m/Y', strtotime($sc['effective_end'])) ?>
                    </td>
                    <?php
                    $expCount = (int)($sc['expected_sessions'] ?? 0);
                    if ($expCount < 1) $expCount = TeachingSchedule::expectedSessionsForCredits((int)$sc['credits']);
                    $genCount = (int)($sc['generated_count'] ?? 0);
                    ?>
                    <td style="text-align:center;">
                        <div style="font-weight:800;color:<?= $genCount === $expCount ? '#0d1b2e' : '#b45309' ?>;">
                            <?= $genCount ?> / <?= $expCount ?>
                        </div>
                        <div style="font-size:10px;color:#64748b;"><?= (int)$sc['upcoming_count'] ?> sắp tới</div>
                        <?php if ($genCount !== $expCount): ?>
                        <div style="font-size:10px;color:#b45309;font-weight:600;">⚠ Cần đồng bộ</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <button type="button" class="abtn abtn--outline abtn--sm"
                                    onclick='editSchedule(<?= json_encode($sc, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏ Sửa</button>
                            <form method="POST" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="_action" value="sync_schedule" />
                                <input type="hidden" name="schedule_id" value="<?= (int)$sc['schedule_id'] ?>" />
                                <input type="hidden" name="semester_id" value="<?= $semId ?>" />
                                <button type="submit" class="abtn abtn--outline abtn--sm">🔄 Đồng bộ</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.SCHEDULE_SECTIONS = <?= json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.SECTION_LECTURERS = <?= json_encode(array_column($lecturers, 'full_name', 'lecturer_id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.SEM_INFO = <?= json_encode($semInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

(function(){
    var sections = window.SCHEDULE_SECTIONS || [];
    var courseSel = document.getElementById('sfCourse');
    var secSel = document.getElementById('sfSection');
    var lecSel = document.getElementById('sfLecturer');
    var info = document.getElementById('sfSectionInfo');
    var roomInput = document.getElementById('sfRoom');
    var startDate = document.getElementById('sfStartDate');
    var endDate = document.getElementById('sfEndDate');
    var expInput = document.getElementById('sfExpected');
    var expHint = document.getElementById('sfExpectedHint');

    expInput.addEventListener('input', function(){ this.dataset.auto = '0'; });

    function byId(id){ return sections.filter(function(s){ return String(s.section_id) === String(id); })[0]; }

    function fillSections(keep){
        var cid = courseSel.value;
        var current = keep || secSel.value;
        secSel.innerHTML = '<option value="">— Chọn lớp —</option>';
        sections.filter(function(s){ return !cid || String(s.course_id) === String(cid); })
            .forEach(function(s){
                var o = document.createElement('option');
                o.value = s.section_id;
                o.textContent = s.section_code + ' (' + s.enrolled_count + ' SV)';
                if (String(s.section_id) === String(current)) o.selected = true;
                secSel.appendChild(o);
            });
        updateInfo();
    }

    function updateInfo(){
        var s = byId(secSel.value);
        if (!s) { info.textContent = ''; return; }
        info.textContent = s.course_code + ' – ' + s.course_name +
            ' • ' + s.enrolled_count + ' sinh viên • ' + (s.room || 'chưa có phòng') +
            ' • Học kỳ ' + s.start_date + ' → ' + s.end_date;
        if (lecSel.value === '' || !secSel.dataset.loaded) {
            lecSel.value = s.lecturer_id;
        }
        if (!roomInput.value) roomInput.value = s.room || '';
        if (!startDate.value) startDate.value = s.start_date;
        if (!endDate.value) endDate.value = s.end_date;

        var creditDefault = Math.max(1, (parseInt(s.credits, 10) || 0) * 5);
        if (expInput.dataset.auto !== '0' || !expInput.value) {
            expInput.value = creditDefault;
        }
        expHint.textContent = 'Tín chỉ: ' + (s.credits || '?') + ' → mặc định ' + creditDefault +
            ' buổi (2 tín chỉ = 10, 3 tín chỉ = 15). Có thể điều chỉnh khi cần.';
    }

    courseSel.addEventListener('change', function(){ expInput.dataset.auto='1'; secSel.dataset.loaded=''; fillSections(''); });
    secSel.addEventListener('change', function(){ expInput.dataset.auto='1'; secSel.dataset.loaded=''; roomInput.value=''; updateInfo(); });

    fillSections('');

    window.editSchedule = function(s){
        courseSel.value = s.course_id;
        fillSections(s.section_id);
        secSel.value = s.section_id;
        secSel.dataset.loaded = '1';
        lecSel.value = s.lecturer_id;
        document.getElementById('sfDay').value = s.day_of_week;
        document.getElementById('sfStart').value = s.start_time.substring(0,5);
        document.getElementById('sfEnd').value = s.end_time.substring(0,5);
        roomInput.value = s.room || '';
        startDate.value = s.effective_start;
        endDate.value = s.effective_end;
        expInput.dataset.auto = '0';
        updateInfo();
        expInput.value = s.expected_sessions || Math.max(1, (parseInt(s.credits, 10) || 0) * 5);
        document.getElementById('scheduleForm').scrollIntoView({behavior:'smooth', block:'center'});
    };

    window.resetScheduleForm = function(){
        document.getElementById('scheduleForm').reset();
        secSel.innerHTML = '<option value="">— Chọn lớp —</option>';
        secSel.dataset.loaded = '';
        info.textContent = '';
        expInput.dataset.auto = '1';
        expInput.value = '';
        expHint.textContent = 'Mặc định: tín chỉ × 5';
        if (window.SEM_INFO) {
            startDate.value = window.SEM_INFO.start_date || '';
            endDate.value = window.SEM_INFO.end_date || '';
        }
        fillSections('');
    };
    var selected = byId(<?= (int)($_GET['section'] ?? 0) ?>);
    if (selected) {
        courseSel.value = selected.course_id;
        fillSections(selected.section_id);
        if (selected.schedule_id) {
            editSchedule(Object.assign({}, selected, {
                start_time: selected.schedule_start, end_time: selected.schedule_end,
                room: selected.schedule_room
            }));
        } else {
            document.getElementById('sfDay').value = (selected.study_days || '1').split(',')[0];
            if (selected.start_time) document.getElementById('sfStart').value = selected.start_time.substring(0,5);
            if (selected.end_time) document.getElementById('sfEnd').value = selected.end_time.substring(0,5);
        }
    }
})();
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
