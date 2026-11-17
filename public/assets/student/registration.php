<?php
/**
 * UniPortal – Đăng ký môn học (theo môn)
 *
 * Students browse unique courses from their curriculum. Completed (passed)
 * courses and courses already registered in the semester are hidden by
 * RegistrationService; each course opens a modal with its class sections and
 * an explicit confirmation step before the registration is submitted.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/RegistrationService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo        = get_db();
$student_id = $_SESSION['student_id']   ?? '';
$account_id = (int)($_SESSION['account_id'] ?? 0);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$student = RegistrationService::studentMajor($pdo, $student_id);

// ── Flash (post/redirect/get) ─────────────────────────────────
$flash = $_SESSION['_reg_flash'] ?? null;
unset($_SESSION['_reg_flash']);

// ── Handle POST: register / cancel ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action     = $_POST['action']     ?? '';
    $section_id = (int)($_POST['section_id'] ?? 0);
    $period_id  = (int)($_POST['period_id']  ?? 0);

    if ($action === 'enroll') {
        if ($period_id < 1) {
            $flash = ['type' => 'error', 'message' => 'Vui lòng chọn đợt đăng ký đang mở.'];
        } else {
            $res   = RegistrationService::register($pdo, $student_id, $account_id, $section_id, $period_id);
            $flash = ['type' => $res['ok'] ? 'success' : 'error', 'message' => $res['message']];
        }
    } elseif ($action === 'drop') {
        $res   = RegistrationService::drop($pdo, $student_id, $account_id, $section_id, $period_id ?: null);
        $flash = ['type' => $res['ok'] ? 'success' : 'error', 'message' => $res['message']];
    } else {
        $flash = ['type' => 'error', 'message' => 'Thao tác không hợp lệ.'];
    }

    $_SESSION['_reg_flash'] = $flash;
    $qs = $period_id > 0 ? '?period_id=' . $period_id : '';
    header('Location: registration.php' . $qs);
    exit;
}

// ── Periods available to this student's major ─────────────────
$periods = $student ? RegistrationService::eligiblePeriodsForStudent($pdo, $student_id) : [];

$requestedPeriodId = (int)($_GET['period_id'] ?? 0);
$selectedPeriod    = null;
foreach ($periods as $p) {
    if ((int)$p['period_id'] === $requestedPeriodId) { $selectedPeriod = $p; break; }
}
if (!$selectedPeriod && $periods) {
    // Prefer the open period, otherwise the nearest upcoming one.
    foreach ($periods as $p) {
        if (RegistrationService::periodIsOpen($p)) { $selectedPeriod = $p; break; }
    }
    $selectedPeriod = $selectedPeriod ?: $periods[0];
}

$isOpen   = $selectedPeriod ? RegistrationService::periodIsOpen($selectedPeriod) : false;
$semId    = $selectedPeriod ? (int)$selectedPeriod['semester_id'] : 0;
$periodId = $selectedPeriod ? (int)$selectedPeriod['period_id'] : 0;

// ── Filters (course catalogue) ────────────────────────────────
$searchQ = trim($_GET['q']    ?? '');
$deptF   = trim($_GET['dept'] ?? '');

// ── Course catalogue + active registrations ───────────────────
$catalog = ['courses' => [], 'active' => [], 'results' => [], 'credit' => []];
if ($semId && $student && !empty($student['major_id'])) {
    $catalog = RegistrationService::availableCourses(
        $pdo, $student_id, $semId, $periodId, $isOpen, $searchQ, $deptF !== '' ? (int)$deptF : 0
    );
}
$courses       = $catalog['courses'];
$mySectionRows = $semId ? RegistrationService::activeEnrollments($pdo, $student_id, $semId) : [];
$creditSummary = $semId
    ? RegistrationService::registrationSummary($pdo, $student_id, $semId)
    : ['min' => 14, 'max' => 24, 'registered' => 0, 'remaining' => 24, 'below_min' => false,
       'below_min_gap' => 14, 'over_max' => false, 'has_exception' => false, 'exception_reason' => null];

$registeredCredits = array_sum(array_map(static fn($r) => (int)$r['credits'], $mySectionRows));
$confirmedCount    = 0;
$myCourseIds       = [];
foreach ($mySectionRows as $row) {
    if ($row['section_status'] === 'Confirmed') $confirmedCount++;
    $myCourseIds[(int)$row['course_id']] = true;
}

$hiddenCourseIds = [];
foreach ($catalog['results'] as $cid => $result) {
    if ($result['passed'] || ($result['completed'] > 0 && !$result['retake'])) {
        $hiddenCourseIds[(int)$cid] = true;
    }
}
foreach ($myCourseIds as $cid => $_) $hiddenCourseIds[(int)$cid] = true;
$hiddenCount = count($hiddenCourseIds);

$openCourseCount = 0;
$totalSections   = 0;
foreach ($courses as $course) {
    if ($course['status'] === 'available') $openCourseCount++;
    $totalSections += (int)$course['section_count'];
}

$depts = [];
if ($semId) {
    $depts = $pdo->prepare(
        'SELECT DISTINCT d.department_id, d.department_name
         FROM Department d
         JOIN Course c ON c.department_id = d.department_id
         JOIN ClassSection cs ON cs.course_id = c.course_id
         WHERE cs.semester_id = :sem ORDER BY d.department_name'
    );
    $depts->execute([':sem' => $semId]);
    $depts = $depts->fetchAll();
}

$regJson = json_encode(
    ['csrf' => csrf_token(), 'periodId' => $periodId, 'courses' => $courses],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$pageTitle  = 'Đăng ký môn học';
$activePage = 'registration';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<style>
.reg-course-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;}
.reg-course{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);}
.reg-course__top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}
.reg-course__name{font-size:14px;font-weight:700;color:#001634;line-height:1.35;}
.reg-course__meta{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:5px;font-size:11.5px;color:#64748b;}
.reg-code{font-family:monospace;font-size:11px;font-weight:700;background:#f1f5f9;color:#334155;border-radius:4px;padding:2px 6px;}
.reg-course__desc{margin:0;font-size:12px;line-height:1.55;color:#475569;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.reg-course__tags{display:flex;gap:6px;flex-wrap:wrap;}
.reg-tag{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:20px;background:#f1f5f9;color:#475569;}
.reg-tag--ok{background:#ecfdf5;color:#047857;}
.reg-tag--warn{background:#fffbeb;color:#b45309;}
.reg-course__foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:auto;padding-top:10px;border-top:1px solid #f1f5f9;}
.reg-course__count{font-size:11.5px;color:#64748b;}
.reg-course__count b{color:#059669;}
.reg-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:32px;padding:0 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;white-space:nowrap;}
.reg-btn--primary{background:#102b50;color:#fff;}
.reg-btn--primary:hover{background:#1e40af;}
.reg-btn--ghost{background:#fff;color:#334155;border-color:#e2e8f0;}
.reg-btn--ghost:hover{background:#f8fafc;}
.reg-btn[disabled]{opacity:.5;cursor:not-allowed;}
.reg-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid #f1f5f9;}
.reg-section-head__title{font-size:14px;font-weight:700;color:#001634;}
.reg-section-head__sub{font-size:11.5px;color:#64748b;margin-top:3px;}
.reg-section-body{padding:14px 18px 18px;}
.reg-empty{padding:36px 20px;text-align:center;color:#64748b;}
.reg-empty__icon{font-size:28px;margin-bottom:6px;}
.reg-empty__title{font-weight:700;color:#334155;font-size:13.5px;}
.reg-empty p{margin:6px 0 0;font-size:12px;}
.reg-mine-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:10px;}
.reg-mine{border:1px solid #e2e8f0;border-left:3px solid #64748b;border-radius:10px;background:#fff;padding:12px 14px;}
.reg-mine__top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;}
.reg-mine__name{font-weight:700;font-size:13px;color:#001634;line-height:1.3;}
.reg-mine__meta{font-size:11px;color:#64748b;margin-top:3px;}
.reg-mine__info{font-size:11px;color:#64748b;margin-top:7px;line-height:1.55;}
.reg-modal{position:fixed;inset:0;z-index:140;display:flex;align-items:center;justify-content:center;padding:24px;}
.reg-modal[hidden]{display:none;}
.reg-modal__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.48);}
.reg-modal__dialog{position:relative;background:#fff;border-radius:14px;width:min(780px,100%);max-height:min(88vh,920px);display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(15,23,42,.3);overflow:hidden;}
.reg-modal__head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px 20px 13px;border-bottom:1px solid #e2e8f0;background:#f8fafc;}
.reg-modal__eyebrow{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#64748b;}
.reg-modal__title{font-size:17px;font-weight:700;color:#001634;margin:3px 0 0;line-height:1.3;}
.reg-modal__sub{font-size:12px;color:#64748b;margin-top:3px;}
.reg-modal__close{flex-shrink:0;width:30px;height:30px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;font-size:14px;cursor:pointer;}
.reg-modal__close:hover{background:#f1f5f9;color:#001634;}
.reg-modal__body{overflow:auto;padding:16px 20px 20px;}
.reg-modal__intro{margin:0 0 12px;font-size:12px;color:#64748b;}
.reg-classes{display:flex;flex-direction:column;gap:10px;}
.reg-class{border:1px solid #e2e8f0;border-radius:11px;padding:12px 14px;background:#fff;}
.reg-class.is-blocked{background:#fbfcfd;}
.reg-class__head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.reg-class__code{font-family:monospace;font-size:13px;font-weight:700;color:#001634;}
.reg-class__pills{display:flex;gap:6px;flex-wrap:wrap;}
.reg-mini-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:700;background:#f1f5f9;color:#475569;}
.reg-class__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 14px;margin-top:10px;}
.reg-class__item{display:flex;flex-direction:column;gap:1px;min-width:0;}
.reg-class__label{font-size:10px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:#94a3b8;}
.reg-class__value{font-size:12px;color:#334155;overflow:hidden;text-overflow:ellipsis;}
.reg-cap{margin-top:9px;height:5px;background:#f1f5f9;border-radius:4px;overflow:hidden;}
.reg-cap__fill{height:100%;background:#f59e0b;border-radius:4px;}
.reg-cap__fill[data-state="open"]{background:#10b981;}
.reg-cap__fill[data-state="full"]{background:#ef4444;}
.reg-class__foot{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:10px;}
.reg-class__reason{display:inline-block;font-size:11.5px;color:#b45309;background:#fffbeb;border-radius:7px;padding:4px 9px;}
.reg-confirm{border:1px solid #e2e8f0;border-radius:11px;overflow:hidden;}
.reg-confirm__row{display:flex;justify-content:space-between;gap:14px;padding:8px 14px;font-size:12.5px;border-bottom:1px solid #f1f5f9;}
.reg-confirm__row:last-child{border-bottom:none;}
.reg-confirm__label{color:#64748b;}
.reg-confirm__value{font-weight:600;color:#001634;text-align:right;}
.reg-confirm__note{margin:12px 0 0;padding:9px 12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:11.5px;line-height:1.5;}
.reg-confirm__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px;}
body.reg-modal-open{overflow:hidden;}
@media (max-width:640px){
  .reg-modal{padding:0;}
  .reg-modal__dialog{width:100%;max-height:100vh;height:100vh;border-radius:0;}
  .reg-class__grid{grid-template-columns:1fr;}
  .reg-section-head{padding:12px 14px;}
  .reg-section-body{padding:12px 14px 16px;}
}
</style>

<?php if ($flash): ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:12px;
            background:<?= $flash['type'] === 'success' ? '#d1fae5' : '#fee2e2' ?>;
            border:1px solid <?= $flash['type'] === 'success' ? '#6ee7b7' : '#fca5a5' ?>;
            color:<?= $flash['type'] === 'success' ? '#065f46' : '#b91c1c' ?>;">
    <?= $flash['type'] === 'success' ? '✅' : '⚠️' ?> <?= h($flash['message']) ?>
</div>
<?php endif; ?>

<?php if (!$student): ?>
<div class="card" style="padding:32px;text-align:center;color:#64748b;">
    Không tìm thấy thông tin sinh viên.
</div>
<?php elseif (empty($student['major_id'])): ?>
<div class="card" style="padding:32px;text-align:center;color:#854d0e;background:#fef9c3;border:1px solid #fde047;">
    Tài khoản chưa được gán ngành học. Vui lòng liên hệ Phòng Đào tạo để được cập nhật trước khi đăng ký môn.
</div>
<?php elseif (!$periods): ?>
<div class="card" style="padding:32px;text-align:center;color:#64748b;">
    <div style="font-size:28px;margin-bottom:8px;">📭</div>
    Hiện chưa có đợt đăng ký môn học nào dành cho ngành của bạn.
</div>
<?php else: ?>

<!-- Period selector -->
<div class="card" style="padding:14px 18px;margin-bottom:12px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Đợt đăng ký</span>
        <?php foreach ($periods as $p):
            $pOpen = RegistrationService::periodIsOpen($p);
            $pSel  = (int)$p['period_id'] === $periodId;
        ?>
        <a href="registration.php?period_id=<?= (int)$p['period_id'] ?>"
           style="display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:20px;font-size:12px;
                  font-weight:600;text-decoration:none;border:1px solid <?= $pSel ? '#1e40af' : '#e2e8f0' ?>;
                  background:<?= $pSel ? '#1e40af' : '#fff' ?>;color:<?= $pSel ? '#fff' : '#334155' ?>;">
            <span style="width:7px;height:7px;border-radius:50%;background:<?= $pOpen ? ($pSel ? '#86efac' : '#10b981') : ($pSel ? '#fde68a' : '#f59e0b') ?>;"></span>
            <?= h($p['period_name']) ?>
            <span style="font-weight:400;opacity:.8;">· <?= $pOpen ? 'Đang mở' : 'Sắp mở' ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Period detail + registration window -->
<?php if ($selectedPeriod):
    $pStart = strtotime($selectedPeriod['start_at']);
    $pEnd   = strtotime($selectedPeriod['end_at']);
?>
<div style="display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;padding:13px 16px;border-radius:10px;font-size:13px;margin-bottom:12px;
            background:<?= $isOpen ? '#dbeafe' : '#fef9c3' ?>;
            border:1px solid <?= $isOpen ? '#93c5fd' : '#fde047' ?>;
            color:<?= $isOpen ? '#1e40af' : '#854d0e' ?>;">
    <div style="display:flex;gap:10px;align-items:flex-start;">
        <span style="font-size:16px;line-height:1.2;"><?= $isOpen ? '📝' : '⏰' ?></span>
        <div>
            <div style="font-weight:700;"><?= h($selectedPeriod['semester_name'] . ' · ' . $selectedPeriod['academic_year']) ?></div>
            <div style="margin-top:2px;">
                <?= $isOpen ? 'Đang trong thời gian đăng ký.' : 'Chưa đến thời gian đăng ký. Đợt sẽ mở tự động theo lịch.' ?>
                Thời gian: <?= date('d/m/Y H:i', $pStart) ?> – <?= date('d/m/Y H:i', $pEnd) ?>.
            </div>
        </div>
    </div>
    <a href="schedule.php" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;
       background:#fff;border:1px solid rgba(30,64,175,.25);color:#1e40af;font-size:12px;font-weight:600;text-decoration:none;white-space:nowrap;">
        🗓 Xem thời khóa biểu
    </a>
</div>
<?php endif; ?>

<!-- Semester credit policy -->
<?php if ($semId): ?>
<div class="card" style="padding:14px 18px;margin-bottom:12px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div style="font-size:13px;font-weight:700;color:#001634;">🎯 Tín chỉ học kỳ</div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;">
            <span style="padding:4px 10px;border-radius:14px;background:#eff6ff;color:#1d4ed8;font-weight:600;">
                Tối thiểu <?= (int)$creditSummary['min'] ?> TC
            </span>
            <span style="padding:4px 10px;border-radius:14px;background:#ecfdf5;color:#047857;font-weight:600;">
                Tối đa <?= (int)$creditSummary['max'] ?> TC
            </span>
            <span style="padding:4px 10px;border-radius:14px;background:#102b50;color:#fff;font-weight:700;">
                Đã đăng ký <?= (int)$creditSummary['registered'] ?> TC
            </span>
            <span style="padding:4px 10px;border-radius:14px;background:#f8fafc;color:#334155;font-weight:600;">
                Còn lại <?= (int)$creditSummary['remaining'] ?> TC
            </span>
        </div>
    </div>
    <div style="height:8px;background:#eef2f7;border-radius:5px;margin-top:10px;overflow:hidden;">
        <?php $creditPct = $creditSummary['max'] > 0 ? min(100, round($creditSummary['registered'] / $creditSummary['max'] * 100)) : 0; ?>
        <div style="height:100%;width:<?= $creditPct ?>%;background:<?= $creditSummary['over_max'] ? '#ef4444' : ($creditSummary['below_min'] ? '#f59e0b' : '#10b981') ?>;"></div>
    </div>
    <?php if ($creditSummary['below_min']): ?>
    <div style="margin-top:10px;padding:9px 12px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12px;">
        ⚠️ Bạn đang đăng ký <b><?= (int)$creditSummary['registered'] ?> TC</b>, thấp hơn mức tối thiểu
        <b><?= (int)$creditSummary['min'] ?> TC</b> của học kỳ. Cần đăng ký thêm
        <b><?= (int)$creditSummary['below_min_gap'] ?> TC</b> (tham khảo ý kiến cố vấn học tập).
    </div>
    <?php endif; ?>
    <?php if ($creditSummary['has_exception']): ?>
    <div style="margin-top:10px;padding:9px 12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:12px;">
        ℹ️ Học kỳ này có ngoại lệ tín chỉ do Phòng Đào tạo phê duyệt<?= $creditSummary['exception_reason'] ? ': ' . h($creditSummary['exception_reason']) : '.' ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="filter-ribbon" style="margin-bottom:12px;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="period_id" value="<?= $periodId ?>" />
        <div style="position:relative;">
            <img src="<?= $imgBase ?>icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="q" value="<?= h($searchQ) ?>"
                   placeholder="Tìm mã môn hoặc tên môn..."
                   style="width:280px;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="dept" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả bộ môn</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= (int)$d['department_id'] ?>" <?= $deptF == $d['department_id'] ? 'selected' : '' ?>>
                <?= h($d['department_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" style="height:36px;padding:0 16px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Tìm</button>
        <?php if ($searchQ || $deptF): ?>
        <a href="registration.php?period_id=<?= $periodId ?>" style="height:36px;padding:0 12px;display:flex;align-items:center;background:#f1f5f9;border-radius:8px;font-size:13px;text-decoration:none;color:#44474e;">✕ Xóa lọc</a>
        <?php endif; ?>
    </form>
</div>

<!-- Available courses (one card per course) -->
<div class="card" style="padding:0;overflow:hidden;">
    <div class="reg-section-head">
        <div>
            <div class="reg-section-head__title">Môn học có thể đăng ký (<?= count($courses) ?>)</div>
            <div class="reg-section-head__sub">
                <?= $openCourseCount ?> môn có lớp đang mở · <?= $totalSections ?> lớp học phần
                <?php if ($hiddenCount > 0): ?>
                · Đã ẩn <?= $hiddenCount ?> môn đã hoàn thành hoặc đang học trong học kỳ này
                <?php endif; ?>
            </div>
        </div>
        <div style="font-size:11.5px;color:#94a3b8;">Mỗi môn chỉ hiển thị một lần</div>
    </div>
    <div class="reg-section-body">
        <?php if (empty($courses)): ?>
        <div class="reg-empty">
            <div class="reg-empty__icon">📚</div>
            <?php if ($searchQ !== '' || $deptF !== ''): ?>
            <div class="reg-empty__title">Không tìm thấy môn phù hợp với bộ lọc</div>
            <p>Hãy thử từ khóa khác hoặc <a href="registration.php?period_id=<?= $periodId ?>" style="color:#1e40af;">xóa bộ lọc</a>.</p>
            <?php elseif (!$isOpen): ?>
            <div class="reg-empty__title">Đợt đăng ký chưa mở</div>
            <p>Danh sách môn sẽ hiển thị khi đợt đăng ký bắt đầu.</p>
            <?php else: ?>
            <div class="reg-empty__title">Không còn môn nào có thể đăng ký</div>
            <p>Bạn đã hoàn thành hoặc đang học tất cả môn thuộc chương trình trong học kỳ này.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="reg-course-grid">
            <?php foreach ($courses as $course):
                $desc = trim((string)$course['description']);
                if ($desc !== '' && mb_strlen($desc) > 150) $desc = mb_substr($desc, 0, 147) . '…';
            ?>
            <article class="reg-course">
                <div class="reg-course__top">
                    <div>
                        <div class="reg-course__name"><?= h($course['course_name']) ?></div>
                        <div class="reg-course__meta">
                            <span class="reg-code"><?= h($course['course_code']) ?></span>
                            <span><?= (int)$course['credits'] ?> tín chỉ</span>
                            <?php if ($course['department_name']): ?>
                            <span>· <?= h($course['department_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="apill apill--<?= h($course['status_class']) ?>"><?= h($course['status_label']) ?></span>
                </div>
                <?php if ($desc !== ''): ?>
                <p class="reg-course__desc"><?= h($desc) ?></p>
                <?php endif; ?>
                <div class="reg-course__tags">
                    <?php if ($course['category']): ?>
                    <span class="reg-tag"><?= h($course['category']) ?></span>
                    <?php endif; ?>
                    <?php if ($course['prereq_code']): ?>
                    <span class="reg-tag <?= $course['prereq_passed'] ? 'reg-tag--ok' : 'reg-tag--warn' ?>">
                        Tiên quyết: <?= h($course['prereq_code']) ?><?= $course['prereq_passed'] ? ' · đã đạt' : ' · chưa đạt' ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="reg-course__foot">
                    <span class="reg-course__count">
                        <b><?= (int)$course['open_sections'] ?></b>/<?= (int)$course['section_count'] ?> lớp có thể chọn
                    </span>
                    <button type="button" class="reg-btn reg-btn--primary" data-course="<?= (int)$course['course_id'] ?>">
                        Xem lớp mở
                    </button>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- My registrations -->
<?php if ($semId): ?>
<div class="card" style="padding:18px 20px;margin-top:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <div style="font-size:15px;font-weight:700;color:#001634;">📚 Môn đã đăng ký (<?= count($mySectionRows) ?>)</div>
        <div style="font-size:12px;color:#64748b;">
            <strong style="color:#001634;"><?= $registeredCredits ?></strong> tín chỉ ·
            <strong style="color:#059669;"><?= $confirmedCount ?></strong> lớp đã xác nhận mở
        </div>
    </div>
    <?php if (empty($mySectionRows)): ?>
    <div style="padding:18px;text-align:center;color:#94a3b8;font-size:13px;background:#f8fafc;border-radius:8px;">
        Bạn chưa đăng ký môn nào trong học kỳ này.
    </div>
    <?php else: ?>
    <div class="reg-mine-grid">
        <?php foreach ($mySectionRows as $my):
            $myMeta  = RegistrationService::statusMeta($my['section_status']);
            $mySlots = RegistrationService::sectionSlots($my);
            $mySched = $my['schedule'] ?: RegistrationService::scheduleText(
                array_column($mySlots, 'day'),
                $mySlots[0]['start'] ?? null,
                $mySlots[0]['end'] ?? null
            );
        ?>
        <div class="reg-mine" style="border-left-color:<?= h($myMeta['fg']) ?>;">
            <div class="reg-mine__top">
                <div style="min-width:0;">
                    <div class="reg-mine__name"><?= h($my['course_name']) ?></div>
                    <div class="reg-mine__meta">
                        <span style="font-family:monospace;"><?= h($my['course_code']) ?></span>
                        · <?= h($my['section_code']) ?> · <?= (int)$my['credits'] ?> TC
                    </div>
                </div>
                <span style="flex-shrink:0;font-size:10px;font-weight:700;padding:3px 8px;border-radius:12px;
                             background:<?= h($myMeta['bg']) ?>;color:<?= h($myMeta['fg']) ?>;">
                    <?= h($myMeta['label']) ?>
                </span>
            </div>
            <div class="reg-mine__info">
                👨‍🏫 <?= h($my['lecturer_name']) ?><br/>
                🗓 <?= h($mySched ?: 'Chưa xếp lịch') ?><?= $my['room'] ? ' · 📍 ' . h($my['room']) : '' ?>
            </div>
            <?php if ($isOpen): ?>
            <form method="POST" style="margin:8px 0 0;text-align:right;"
                  onsubmit="return confirm('Hủy đăng ký <?= h(addslashes($my['course_code'])) ?>? Môn sẽ xuất hiện lại trong danh sách đăng ký.')">
                <?= csrf_input() ?>
                <input type="hidden" name="action"     value="drop" />
                <input type="hidden" name="section_id" value="<?= (int)$my['section_id'] ?>" />
                <input type="hidden" name="period_id"  value="<?= $periodId ?>" />
                <button type="submit" style="background:#fee2e2;border:none;color:#b91c1c;border-radius:7px;
                        padding:4px 10px;font-size:11px;cursor:pointer;font-weight:600;">Hủy đăng ký</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Course → class → confirm modal -->
<div class="reg-modal" id="regModal" hidden>
    <div class="reg-modal__backdrop" data-close></div>
    <div class="reg-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="regModalTitle">
        <div class="reg-modal__head">
            <div style="min-width:0;">
                <div class="reg-modal__eyebrow" id="regModalEyebrow">Lớp học phần</div>
                <h2 class="reg-modal__title" id="regModalTitle"></h2>
                <div class="reg-modal__sub" id="regModalSub"></div>
            </div>
            <button type="button" class="reg-modal__close" data-close aria-label="Đóng">✕</button>
        </div>
        <div class="reg-modal__body" id="regModalBody"></div>
    </div>
</div>

<script type="application/json" id="regData"><?= $regJson ?></script>
<script>
(function () {
    var dataNode = document.getElementById('regData');
    var modal = document.getElementById('regModal');
    if (!dataNode || !modal) return;

    var data = JSON.parse(dataNode.textContent || '{}');
    var courseMap = {};
    (data.courses || []).forEach(function (course) { courseMap[String(course.course_id)] = course; });

    var body = document.getElementById('regModalBody');
    var title = document.getElementById('regModalTitle');
    var sub = document.getElementById('regModalSub');
    var eyebrow = document.getElementById('regModalEyebrow');
    var lastFocus = null;

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function openModal() {
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('reg-modal-open');
        var closeBtn = modal.querySelector('.reg-modal__close');
        if (closeBtn) closeBtn.focus();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('reg-modal-open');
        body.replaceChildren();
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function capacityBar(section) {
        var wrap = el('div', 'reg-cap');
        var fill = el('div', 'reg-cap__fill');
        var pct = Math.max(0, Math.min(100, Number(section.capacity_pct) || 0));
        fill.style.width = pct + '%';
        fill.dataset.state = section.eligibility_state === 'full'
            ? 'full' : (section.enrolled_count >= section.threshold ? 'open' : 'pending');
        wrap.appendChild(fill);
        return wrap;
    }

    function showSections(course) {
        eyebrow.textContent = 'Lớp học phần';
        title.textContent = course.course_name;
        sub.textContent = course.course_code + ' · ' + course.credits + ' tín chỉ'
            + (course.department_name ? ' · ' + course.department_name : '');
        body.replaceChildren();
        body.appendChild(el('p', 'reg-modal__intro',
            'Chọn lớp học phần phù hợp. Hệ thống kiểm tra trùng lịch, sĩ số, tín chỉ và điều kiện tiên quyết ở bước xác nhận.'));

        var list = el('div', 'reg-classes');
        course.sections.forEach(function (section) {
            var row = el('article', 'reg-class' + (section.eligible ? '' : ' is-blocked'));

            var head = el('div', 'reg-class__head');
            head.appendChild(el('div', 'reg-class__code', section.section_code));
            var pills = el('div', 'reg-class__pills');
            var statusPill = el('span', 'reg-mini-pill', section.status_label);
            statusPill.style.background = section.status_bg;
            statusPill.style.color = section.status_fg;
            pills.appendChild(statusPill);
            pills.appendChild(el('span', 'reg-mini-pill', section.enrolled_count + '/' + section.max_enrollment + ' SV'));
            head.appendChild(pills);
            row.appendChild(head);

            var grid = el('div', 'reg-class__grid');
            function item(label, value) {
                var box = el('div', 'reg-class__item');
                box.appendChild(el('span', 'reg-class__label', label));
                box.appendChild(el('span', 'reg-class__value', value));
                grid.appendChild(box);
            }
            item('Giảng viên', section.lecturer_name);
            item('Lịch học', section.day_text + (section.time_text ? ' · ' + section.time_text : ''));
            item('Phòng học', section.room || 'Chưa xếp');
            item('Sĩ số', section.enrolled_count + '/' + section.max_enrollment + ' · tối thiểu ' + section.min_enrollment);
            row.appendChild(grid);
            row.appendChild(capacityBar(section));

            var foot = el('div', 'reg-class__foot');
            if (section.eligible) {
                var choose = el('button', 'reg-btn reg-btn--primary', 'Chọn lớp');
                choose.type = 'button';
                choose.addEventListener('click', function () { showConfirm(course, section); });
                foot.appendChild(choose);
            } else {
                foot.appendChild(el('span', 'reg-class__reason', 'Không thể chọn: ' + section.reason));
            }
            row.appendChild(foot);
            list.appendChild(row);
        });
        body.appendChild(list);
    }

    function showConfirm(course, section) {
        eyebrow.textContent = 'Xác nhận đăng ký';
        title.textContent = course.course_name;
        sub.textContent = course.course_code + ' · ' + section.section_code;
        body.replaceChildren();

        var box = el('div', 'reg-confirm');
        [
            ['Môn học', course.course_name],
            ['Mã môn', course.course_code],
            ['Tín chỉ', String(course.credits)],
            ['Lớp học phần', section.section_code],
            ['Giảng viên', section.lecturer_name],
            ['Lịch học', section.day_text + (section.time_text ? ' · ' + section.time_text : '')],
            ['Phòng học', section.room || 'Chưa xếp'],
            ['Sĩ số hiện tại', section.enrolled_count + '/' + section.max_enrollment],
            ['Trạng thái lớp', section.status_label]
        ].forEach(function (pair) {
            var row = el('div', 'reg-confirm__row');
            row.appendChild(el('span', 'reg-confirm__label', pair[0]));
            row.appendChild(el('span', 'reg-confirm__value', pair[1]));
            box.appendChild(row);
        });
        body.appendChild(box);
        body.appendChild(el('p', 'reg-confirm__note',
            'Khi xác nhận, hệ thống kiểm tra lại trùng lịch với các môn đã đăng ký, sĩ số tối đa, giới hạn tín chỉ và môn tiên quyết. Đăng ký chỉ được lưu khi tất cả điều kiện đạt yêu cầu.'));

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'registration.php';
        form.className = 'reg-confirm__actions';
        function hidden(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = String(value);
            form.appendChild(input);
        }
        hidden('_csrf', data.csrf);
        hidden('action', 'enroll');
        hidden('section_id', section.section_id);
        hidden('period_id', data.periodId);

        var back = el('button', 'reg-btn reg-btn--ghost', 'Quay lại');
        back.type = 'button';
        back.addEventListener('click', function () { showSections(course); });

        var submit = el('button', 'reg-btn reg-btn--primary', 'Xác nhận đăng ký');
        submit.type = 'submit';
        form.addEventListener('submit', function () {
            submit.disabled = true;
            submit.textContent = 'Đang xử lý…';
        });
        form.appendChild(back);
        form.appendChild(submit);
        body.appendChild(form);
    }

    document.querySelectorAll('[data-course]').forEach(function (button) {
        button.addEventListener('click', function () {
            var course = courseMap[String(this.getAttribute('data-course'))];
            if (!course) return;
            showSections(course);
            openModal();
        });
    });

    modal.addEventListener('click', function (event) {
        if (event.target.closest('[data-close]')) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });
})();
</script>

<?php endif; ?>

</div></main>
<script>
document.addEventListener('click', function(e) {
    var drop = document.getElementById('notifDrop');
    var bell = document.getElementById('notifBell');
    if (drop && bell && !bell.contains(e.target) && !drop.contains(e.target)) {
        drop.style.display = 'none';
    }
});
document.getElementById('notifBell')?.addEventListener('click', function(e) {
    var d = document.getElementById('notifDrop');
    if (d) d.style.display = d.style.display === 'none' ? 'block' : 'none';
    e.stopPropagation();
});
</script>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
