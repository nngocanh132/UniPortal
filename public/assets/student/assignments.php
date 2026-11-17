<?php
/**
 * UniPortal – Bài tập & Nộp bài (Student Assignments)
 * Master–detail: assignment list on the left, selected assignment on the right.
 * Every submission goes through AssignmentService / UploadService.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/AssignmentService.php';
require_once $root . '/src/src/services/UploadService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo        = get_db();
$student_id = (string)($_SESSION['student_id'] ?? '');
$account_id = (int)($_SESSION['account_id'] ?? 0);

// Submission policy for the UI only; AssignmentService rechecks it on submit.
$allowLate = false;
try {
    $lateCfg   = $pdo->query("SELECT config_value FROM SystemConfig WHERE config_key='late_submission_allowed'")->fetchColumn();
    $allowLate = (int)$lateCfg === 1;
} catch (Throwable) {
    $allowLate = false;
}

$courseF = (int)($_GET['course'] ?? 0);
$dlF     = in_array($_GET['dl'] ?? '', ['week', 'month', 'past'], true) ? $_GET['dl'] : '';
$stF     = in_array($_GET['st'] ?? '', ['pending', 'draft', 'submitted', 'late', 'graded', 'overdue', 'closed'], true) ? $_GET['st'] : '';
$filters = array_filter(['course' => $courseF, 'dl' => $dlF, 'st' => $stF]);

// All submission validation, uploads and version writes belong to the service.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['submit_assignment']) || isset($_POST['save_draft']))) {
    csrf_verify();
    $postedId = (int)($_POST['assignment_id'] ?? 0);
    try {
        if (isset($_POST['save_draft'])) {
            $result = AssignmentService::saveDraft(
                $postedId,
                (int)($_POST['enrollment_id'] ?? 0),
                $student_id,
                (string)($_POST['content'] ?? ''),
                $_FILES['submission_files'] ?? []
            );
        } else {
            $result = AssignmentService::submit(
                $postedId,
                (int)($_POST['enrollment_id'] ?? 0),
                $student_id,
                $account_id,
                (string)($_POST['content'] ?? ''),
                $_FILES['submission_files'] ?? [],
                (int)($_POST['version'] ?? -1)
            );
        }
        $flash = ($result['ok'] ? 'success:' : 'error:') . $result['message'];
    } catch (Throwable $e) {
        $flash = 'error:Không thể lưu bài. Vui lòng thử lại.';
    }
    $_SESSION['student_assignments_flash'] = $flash;
    $query = http_build_query($filters + ($postedId > 0 ? ['id' => $postedId] : []));
    header('Location: assignments.php' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

$flash = (string)($_SESSION['student_assignments_flash'] ?? '');
unset($_SESSION['student_assignments_flash']);

// ── Load assignments ──────────────────────────────────────────
$semRow = $pdo->query("SELECT * FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1")->fetch();
$semId  = $semRow ? (int)$semRow['semester_id'] : 0;

$asgStmt = $pdo->prepare(
    'SELECT
        a.assignment_id, a.title, a.description, a.instructions, a.due_date,
        a.max_score, a.attachment_name, a.attachment_path, a.status, a.allow_resubmission,
        cs.section_code, cs.section_id,
        COALESCE(creator.full_name, l.full_name) AS lecturer_name,
        c.course_id, c.course_code, c.course_name,
        e.enrollment_id,
        sub.submission_id, sub.file_path AS sub_path, sub.file_name AS sub_file,
        sub.file_size AS sub_file_size, sub.submitted_at,
        sub.score AS sub_score, sub.feedback AS sub_feedback, sub.grade_status, sub.version AS sub_version,
        sub.content AS sub_content, sub.revision AS sub_revision
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Course c        ON c.course_id   = cs.course_id
     JOIN Assignment a    ON a.section_id  = cs.section_id AND a.is_deleted = 0
                          AND a.status IN (\'Published\',\'Closed\')
                          AND (a.publication_date IS NULL OR a.publication_date <= NOW())
     LEFT JOIN Account author ON author.account_id = a.created_by
     LEFT JOIN Lecturer creator ON creator.lecturer_id = author.lecturer_id
     LEFT JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
     LEFT JOIN Submission sub ON sub.assignment_id = a.assignment_id
                              AND sub.enrollment_id = e.enrollment_id
     WHERE e.student_id    = :sid
       AND cs.semester_id  = :sem
       AND e.status        IN (\'Enrolled\',\'Completed\')
     ORDER BY a.due_date ASC, a.assignment_id ASC'
);
$asgStmt->execute([':sid' => $student_id, ':sem' => $semId]);
$allAssignments = $asgStmt->fetchAll();

$isPublished = fn(array $a): bool => $a['submission_id'] !== null
    && $a['sub_score'] !== null
    && ($a['grade_status'] ?? '') === 'Published';

// ── Filters: course / deadline / status ──────────────────────
$courseOptions = [];
foreach ($allAssignments as $a) {
    $courseOptions[$a['course_id']] = $a['course_code'] . ' – ' . $a['course_name'];
}
ksort($courseOptions);

$assignments = array_values(array_filter($allAssignments, function (array $a) use ($courseF, $dlF, $stF, $isPublished): bool {
    if ($courseF && (int)$a['course_id'] !== $courseF) return false;

    $now = time();
    $due = strtotime($a['due_date']);
    if ($dlF === 'week'  && ($due < $now || $due > $now + 7 * 86400))  return false;
    if ($dlF === 'month' && ($due < $now || $due > $now + 30 * 86400)) return false;
    if ($dlF === 'past'  && $due >= $now) return false;

    if ($stF === '') return true;
    $hasSubmit = $a['submission_id'] !== null && $a['submitted_at'] !== null;
    $hasDraft  = $a['submission_id'] !== null && $a['submitted_at'] === null;
    $late      = $hasSubmit && strtotime($a['submitted_at']) > $due;
    return match ($stF) {
        'pending'   => !$hasSubmit && !$hasDraft && $due >= $now && $a['status'] === 'Published',
        'draft'     => $hasDraft,
        'submitted' => $hasSubmit,
        'late'      => $late,
        'graded'    => $isPublished($a),
        'overdue'   => !$hasSubmit && $due < $now,
        'closed'    => $a['status'] === 'Closed',
        default     => true,
    };
}));

// ── Selection ────────────────────────────────────────────────
$selected = null;
$requestedId = (int)($_GET['id'] ?? 0);
foreach ($assignments as $a) {
    if ($requestedId > 0 && (int)$a['assignment_id'] === $requestedId) {
        $selected = $a;
        break;
    }
}
if ($selected === null && !empty($assignments)) {
    $selected = $assignments[0];
}

// ── Detail data for the selected assignment ──────────────────
$asgAttachments = [];
$subAttachments = [];
if ($selected) {
    $attStmt = $pdo->prepare(
        'SELECT attachment_id, file_name, file_size
         FROM AssignmentAttachment WHERE assignment_id = ? ORDER BY attachment_id'
    );
    $attStmt->execute([$selected['assignment_id']]);
    $asgAttachments = $attStmt->fetchAll();

    if ($selected['submission_id']) {
        $saStmt = $pdo->prepare(
            'SELECT sub_attachment_id, file_name, file_size FROM SubmissionAttachment
             WHERE submission_id = ? AND version = ? ORDER BY sub_attachment_id'
        );
        $saStmt->execute([$selected['submission_id'], (int)$selected['sub_version']]);
        $subAttachments = $saStmt->fetchAll();
    }
}

// ── Helpers ──────────────────────────────────────────────────
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $d): string { return $d ? date('d/m/Y • H:i', strtotime($d)) : '—'; }
function fmt_size($b): string {
    $b = (int)$b;
    if ($b <= 0) return '';
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}
function previewable_ext(string $name): bool {
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true);
}
function asg_icon(string $name, int $size = 14): string {
    $paths = [
        'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'doc'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'clip'   => '<path d="M21.4 11.6 12.6 20.4a5 5 0 0 1-7-7l8.8-8.8a3.3 3.3 0 0 1 4.7 4.7l-8.9 8.8a1.7 1.7 0 0 1-2.3-2.3l8.2-8.2"/>',
        'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
        'upload' => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'check'  => '<path d="m5 13 4 4 10-11"/>',
        'chev'   => '<path d="m9 18 6-6-6-6"/>',
    ];
    $path = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}
function asg_state(array $a, bool $allowLate): array {
    $due       = strtotime($a['due_date']);
    $hasSubmit = $a['submission_id'] !== null && $a['submitted_at'] !== null;
    $hasDraft  = $a['submission_id'] !== null && $a['submitted_at'] === null;
    $graded    = $hasSubmit && $a['sub_score'] !== null && ($a['grade_status'] ?? '') === 'Published';
    $late      = $hasSubmit && strtotime($a['submitted_at']) > $due;
    return match (true) {
        $graded    => ['graded',    'Đã chấm điểm', 'asg-badge--graded'],
        $late      => ['late',      'Nộp muộn',    'asg-badge--late'],
        $hasSubmit => ['submitted', 'Đã nộp',      'asg-badge--submitted'],
        $hasDraft  => ['draft',     'Đang làm',    'asg-badge--draft'],
        $a['status'] === 'Closed' => ['closed', 'Đã đóng', 'asg-badge--closed'],
        $due < time() => ['overdue', 'Quá hạn',    'asg-badge--overdue'],
        default    => ['pending',   'Chờ nộp',     'asg-badge--pending'],
    };
}
function asg_list_url(array $filters, int $id): string {
    $q = http_build_query($filters + ['id' => $id]);
    return 'assignments.php?' . $q;
}

$totalAsg  = count($allAssignments);
$acceptAttr = implode(',', array_map(static fn($e) => '.' . $e, UploadService::allowedExtensions()));

// ── Selected-assignment state ────────────────────────────────
if ($selected) {
    $dueTs     = strtotime($selected['due_date']);
    $isOverdue = $dueTs < time();
    $isClosed  = $selected['status'] === 'Closed';
    $hasSubmit = $selected['submission_id'] !== null && $selected['submitted_at'] !== null;
    $hasDraft  = $selected['submission_id'] !== null && $selected['submitted_at'] === null;
    $isLate    = $hasSubmit && strtotime($selected['submitted_at']) > $dueTs;
    $isGraded  = $isPublished($selected);
    [$stateKey, $stateLabel, $stateClass] = asg_state($selected, $allowLate);

    $disabledReason = '';
    if ($selected['status'] !== 'Published') {
        $disabledReason = 'Bài tập đã đóng hoặc chưa được công bố, không thể nộp bài.';
    } elseif ($hasSubmit && $selected['grade_status'] === 'Published') {
        $disabledReason = 'Bài đã công bố điểm, không thể nộp lại.';
    } elseif ($hasSubmit && !(bool)$selected['allow_resubmission']) {
        $disabledReason = 'Giảng viên không cho phép nộp lại bài tập này.';
    } elseif ($isOverdue && !$allowLate) {
        $disabledReason = 'Đã quá hạn nộp và hệ thống không cho phép nộp muộn.';
    }
    $canSubmit     = $disabledReason === '';
    $canSaveDraft  = !$hasSubmit && $canSubmit;
}

$pageTitle  = 'Bài tập & Nộp bài';
$activePage = 'assignments';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$extraCss   = 'assignments.css';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div class="asg-flash <?= $ft === 'success' ? 'asg-flash--ok' : 'asg-flash--err' ?>">
    <?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Filter toolbar -->
<form class="asg-toolbar" method="GET">
    <select class="asg-select" name="course" aria-label="Học phần">
        <option value="">Tất cả học phần</option>
        <?php foreach ($courseOptions as $cid => $clabel): ?>
        <option value="<?= (int)$cid ?>" <?= $courseF === (int)$cid ? 'selected' : '' ?>><?= h($clabel) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="asg-select" name="dl" aria-label="Hạn nộp">
        <option value="">Mọi hạn nộp</option>
        <option value="week"  <?= $dlF === 'week'  ? 'selected' : '' ?>>Trong 7 ngày tới</option>
        <option value="month" <?= $dlF === 'month' ? 'selected' : '' ?>>Trong 30 ngày tới</option>
        <option value="past"  <?= $dlF === 'past'  ? 'selected' : '' ?>>Đã qua hạn</option>
    </select>
    <select class="asg-select" name="st" aria-label="Trạng thái">
        <option value="">Mọi trạng thái</option>
        <option value="pending"   <?= $stF === 'pending'   ? 'selected' : '' ?>>Chờ nộp</option>
        <option value="draft"     <?= $stF === 'draft'     ? 'selected' : '' ?>>Đang làm (nháp)</option>
        <option value="submitted" <?= $stF === 'submitted' ? 'selected' : '' ?>>Đã nộp</option>
        <option value="late"      <?= $stF === 'late'      ? 'selected' : '' ?>>Nộp muộn</option>
        <option value="graded"    <?= $stF === 'graded'    ? 'selected' : '' ?>>Đã có điểm</option>
        <option value="overdue"   <?= $stF === 'overdue'   ? 'selected' : '' ?>>Quá hạn</option>
        <option value="closed"    <?= $stF === 'closed'    ? 'selected' : '' ?>>Đã đóng</option>
    </select>
    <button type="submit" class="asg-btn-filter">Lọc</button>
    <?php if ($filters): ?>
    <a href="assignments.php" class="asg-btn-reset">✕ Xóa lọc</a>
    <?php endif; ?>
    <span class="asg-toolbar__count">Hiển thị <?= count($assignments) ?> / <?= $totalAsg ?> bài tập</span>
</form>

<?php if (empty($assignments)): ?>
<div class="card">
    <div class="asg-empty">
        <div class="asg-empty__icon">📋</div>
        <p><?= $totalAsg > 0 ? 'Không có bài tập nào khớp bộ lọc.' : 'Chưa có bài tập nào trong học kỳ này.' ?></p>
        <?php if ($totalAsg > 0): ?>
        <a href="assignments.php" style="font-size:13px;color:#2563eb;font-weight:600;">Xóa bộ lọc</a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="asg-layout">

    <!-- ── Left: assignment list ─────────────────────────── -->
    <aside class="card asg-list" aria-label="Danh sách bài tập">
        <div class="asg-list__head">
            <div class="asg-list__title">Danh sách bài tập</div>
            <div class="asg-list__count"><?= count($assignments) ?> bài tập <?= $filters ? '(đã lọc)' : 'trong học kỳ' ?></div>
        </div>
        <div class="asg-list__scroll">
            <?php foreach ($assignments as $a):
                [$k, $lbl, $badgeClass] = asg_state($a, $allowLate);
                $isSel  = $selected && (int)$selected['assignment_id'] === (int)$a['assignment_id'];
                $isPast = strtotime($a['due_date']) < time();
            ?>
            <a class="asg-item<?= $isSel ? ' is-selected' : '' ?>"
               href="<?= h(asg_list_url($filters, (int)$a['assignment_id'])) ?>"
               <?= $isSel ? 'aria-current="true"' : '' ?>>
                <div class="asg-item__top">
                    <span class="asg-item__course"><?= h($a['course_code']) ?></span>
                    <span class="asg-badge <?= $badgeClass ?>"><?= h($lbl) ?></span>
                </div>
                <div class="asg-item__title"><?= h($a['title']) ?></div>
                <div class="asg-item__due<?= $isPast ? ' is-past' : '' ?>">
                    <span style="color:#f59e0b;display:inline-flex;"><?= asg_icon('clock', 12) ?></span>
                    <span><?= fmt_dt($a['due_date']) ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- ── Right: selected assignment detail ─────────────── -->
    <section class="card asg-detail" aria-label="Chi tiết bài tập">
        <div class="asg-detail__kicker">
            <span class="asg-chip"><?= h($selected['course_code']) ?></span>
            <span class="asg-chip asg-chip--soft"><?= h($selected['section_code']) ?></span>
            <span class="asg-badge <?= $stateClass ?>"><?= h($stateLabel) ?></span>
        </div>
        <div class="asg-detail__course"><?= h($selected['course_name']) ?></div>
        <h1 class="asg-detail__title"><?= h($selected['title']) ?></h1>

        <div class="asg-meta">
            <div class="asg-meta__cell">
                <div class="asg-meta__label">Giảng viên</div>
                <div class="asg-meta__value"><?= h($selected['lecturer_name'] ?? '—') ?></div>
            </div>
            <div class="asg-meta__cell asg-meta__cell--deadline<?= $isOverdue ? ' is-past' : '' ?>">
                <div class="asg-meta__label">Hạn nộp</div>
                <div class="asg-meta__value"><?= fmt_dt($selected['due_date']) ?></div>
            </div>
            <div class="asg-meta__cell">
                <div class="asg-meta__label">Điểm tối đa</div>
                <div class="asg-meta__value"><?= number_format((float)$selected['max_score'], 2) ?> điểm</div>
            </div>
            <div class="asg-meta__cell">
                <div class="asg-meta__label">Trạng thái</div>
                <div class="asg-meta__value"><?= h($stateLabel) ?></div>
            </div>
        </div>

        <?php if (!empty($selected['description'])): ?>
        <div class="asg-section">
            <div class="asg-section__title"><?= asg_icon('doc') ?> Mô tả bài tập</div>
            <div class="asg-section__body"><?= nl2br(h($selected['description'])) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($selected['instructions'])): ?>
        <div class="asg-section">
            <div class="asg-section__title"><?= asg_icon('info') ?> Yêu cầu &amp; hướng dẫn</div>
            <div class="asg-callout"><?= nl2br(h($selected['instructions'])) ?></div>
        </div>
        <?php endif; ?>

        <div class="asg-section">
            <div class="asg-section__title"><?= asg_icon('clip') ?> Tài liệu đính kèm</div>
            <?php if (empty($asgAttachments) && empty($selected['attachment_name'])): ?>
            <div class="asg-muted">Bài tập không có tài liệu đính kèm.</div>
            <?php else: ?>
            <div class="asg-attach-list">
                <?php foreach ($asgAttachments as $att): ?>
                <div class="asg-attach">
                    <div class="asg-attach__name">
                        <?= asg_icon('doc', 15) ?>
                        <span><?= h($att['file_name']) ?></span>
                        <span class="asg-attach__size"><?= h(fmt_size($att['file_size'])) ?></span>
                    </div>
                    <span style="display:flex;gap:6px;flex-shrink:0;">
                        <?php if (previewable_ext($att['file_name'])): ?>
                        <a class="asg-link-btn"
                           href="../../download.php?type=assignment_attachment&id=<?= (int)$att['attachment_id'] ?>&preview=1"
                           target="_blank" rel="noopener">Xem trước</a>
                        <?php endif; ?>
                        <a class="asg-link-btn asg-link-btn--navy"
                           href="../../download.php?type=assignment_attachment&id=<?= (int)$att['attachment_id'] ?>">Tải xuống</a>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($asgAttachments) && $selected['attachment_name']): ?>
                <div class="asg-attach">
                    <div class="asg-attach__name">
                        <?= asg_icon('doc', 15) ?>
                        <span><?= h($selected['attachment_name']) ?></span>
                    </div>
                    <a class="asg-link-btn asg-link-btn--navy"
                       href="../../download.php?type=assignment&id=<?= (int)$selected['assignment_id'] ?>">Tải xuống</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="asg-section">
            <div class="asg-section__title"><?= asg_icon('check') ?> Quy định nộp bài</div>
            <div class="asg-rules">
                <div class="asg-rule">
                    <div>
                        <div class="asg-rule__label">Nộp lại</div>
                        <div class="asg-rule__value <?= (bool)$selected['allow_resubmission'] ? 'is-yes' : 'is-no' ?>">
                            <?= (bool)$selected['allow_resubmission'] ? 'Được phép' : 'Không được phép' ?>
                        </div>
                    </div>
                </div>
                <div class="asg-rule">
                    <div>
                        <div class="asg-rule__label">Nộp muộn</div>
                        <div class="asg-rule__value <?= $allowLate ? 'is-yes' : 'is-no' ?>">
                            <?= $allowLate ? 'Được phép' : 'Không được phép' ?>
                        </div>
                    </div>
                </div>
                <div class="asg-rule">
                    <div>
                        <div class="asg-rule__label">Trạng thái bài tập</div>
                        <div class="asg-rule__value"><?= $isClosed ? 'Đã đóng' : 'Đang mở' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current submission -->
        <div class="asg-section">
            <div class="asg-section__title"><?= asg_icon('upload') ?> Bài nộp của bạn</div>

            <?php if ($hasSubmit): ?>
            <div class="asg-state <?= $isLate ? 'asg-state--warning' : 'asg-state--success' ?>">
                <div class="asg-state__head">
                    <?= $isLate ? 'Đã nộp muộn' : 'Đã nộp bài' ?> – phiên bản <?= (int)$selected['sub_version'] ?>
                </div>
                <div>Thời điểm nộp: <strong><?= fmt_dt($selected['submitted_at']) ?></strong></div>

                <?php if ((string)($selected['sub_content'] ?? '') !== ''): ?>
                <div class="asg-state__note">
                    <strong>Nội dung bài nộp:</strong>
                    <div><?= nl2br(h($selected['sub_content'])) ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($subAttachments)): ?>
                <div class="asg-state__note">
                    <strong>Tệp đã nộp (<?= count($subAttachments) ?>):</strong>
                    <?php foreach ($subAttachments as $sa): ?>
                    <div class="asg-file-row">
                        <span><?= asg_icon('doc', 13) ?> <?= h($sa['file_name']) ?></span>
                        <span style="color:#94a3b8;font-size:11px;"><?= h(fmt_size($sa['file_size'])) ?></span>
                        <?php if (previewable_ext($sa['file_name'])): ?>
                        <a href="../../download.php?type=submission_attachment&id=<?= (int)$sa['sub_attachment_id'] ?>&preview=1"
                           target="_blank" rel="noopener">Xem trước</a>
                        <?php endif; ?>
                        <a href="../../download.php?type=submission_attachment&id=<?= (int)$sa['sub_attachment_id'] ?>">Tải xuống</a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($selected['sub_path']): ?>
                <div class="asg-file-row">
                    <span><?= asg_icon('doc', 13) ?> <?= h($selected['sub_file']) ?></span>
                    <?php if (previewable_ext((string)$selected['sub_file'])): ?>
                    <a href="../../download.php?type=submission&id=<?= (int)$selected['submission_id'] ?>&preview=1"
                       target="_blank" rel="noopener">Xem trước</a>
                    <?php endif; ?>
                    <a href="../../download.php?type=submission&id=<?= (int)$selected['submission_id'] ?>">Tải xuống</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($isGraded): ?>
            <div class="asg-grade">
                <div class="asg-grade__score">
                    <div class="asg-grade__num"><?= number_format((float)$selected['sub_score'], 2) ?></div>
                    <div class="asg-grade__max">/ <?= number_format((float)$selected['max_score'], 2) ?> điểm</div>
                </div>
                <div class="asg-grade__feedback">
                    <div style="font-size:11px;font-weight:800;color:#1e40af;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Nhận xét của giảng viên</div>
                    <?= $selected['sub_feedback'] ? nl2br(h($selected['sub_feedback'])) : '—' ?>
                </div>
            </div>
            <?php elseif ($selected['sub_score'] !== null): ?>
            <div class="asg-state asg-state--info" style="margin-top:12px;">
                Bài đã được chấm nhưng điểm chưa được công bố.
            </div>
            <?php endif; ?>

            <?php elseif ($hasDraft): ?>
            <div class="asg-state asg-state--warning">
                <div class="asg-state__head">Bản nháp đã lưu</div>
                <div>Bài <strong>chưa được nộp</strong> cho giảng viên<?= $isOverdue ? ' và đã quá hạn nộp' : '' ?>.</div>
                <?php if ((string)($selected['sub_content'] ?? '') !== ''): ?>
                <div class="asg-state__note"><?= nl2br(h($selected['sub_content'])) ?></div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="asg-state asg-state--muted">
                <div class="asg-state__head">Chưa nộp bài</div>
                <div>Bạn chưa nộp bài tập này. Hãy đính kèm tệp hoặc nhập nội dung rồi bấm “Nộp bài”.</div>
            </div>
            <?php endif; ?>

            <!-- Submission form -->
            <?php if ($canSubmit): ?>
            <form id="asgForm" class="asg-submit" method="POST" enctype="multipart/form-data">
                <?= csrf_input() ?>
                <input type="hidden" name="submit_assignment" value="1" />
                <input type="hidden" name="assignment_id" value="<?= (int)$selected['assignment_id'] ?>" />
                <input type="hidden" name="enrollment_id" value="<?= (int)$selected['enrollment_id'] ?>" />
                <input type="hidden" name="version" value="<?= (int)($selected['sub_version'] ?? 0) ?>" />

                <div class="asg-submit__title"><?= asg_icon('upload') ?> <?= $hasSubmit ? 'Nộp lại bài' : 'Nộp bài' ?></div>

                <label class="asg-field">
                    <span class="asg-field__label">Ghi chú cho giảng viên (không bắt buộc)</span>
                    <textarea class="asg-textarea" name="content" rows="3"
                              placeholder="Ghi chú hoặc nội dung bài làm..."><?= h($hasSubmit || $hasDraft ? ($selected['sub_content'] ?? '') : '') ?></textarea>
                </label>

                <div class="asg-field">
                    <span class="asg-field__label">Tệp bài nộp</span>
                    <div class="asg-upload">
                        <input class="asg-file-input" type="file" id="asgFileInput" name="submission_files[]"
                               multiple accept="<?= h($acceptAttr) ?>" />
                        <label class="asg-choose" for="asgFileInput"><?= asg_icon('upload') ?> Chọn tệp</label>
                        <div class="asg-upload__hint">PDF, DOCX, ZIP, PNG, JPG… – kéo thả không hỗ trợ, vui lòng bấm chọn.</div>
                    </div>
                    <div id="asgFileList" class="asg-file-list"></div>
                    <div id="asgFileError" class="asg-file-error" style="display:none;"></div>
                    <div class="asg-limits">
                        Tối đa <?= (int)UploadService::maxFiles() ?> tệp ·
                        <?= h(fmt_size(UploadService::fileLimitBytes())) ?>/tệp ·
                        <?= h(fmt_size(UploadService::totalLimitBytes())) ?> tổng cộng.
                        Định dạng: <?= h(implode(', ', UploadService::allowedExtensions())) ?>.
                    </div>
                </div>

                <div class="asg-actions">
                    <button type="submit" class="asg-submit-btn"><?= asg_icon('upload') ?> <?= $hasSubmit ? 'Nộp lại bài' : 'Nộp bài' ?></button>
                    <?php if ($canSaveDraft): ?>
                    <button type="submit" name="save_draft" value="1" formnovalidate class="asg-draft-btn">Lưu nháp</button>
                    <?php endif; ?>
                    <a href="assignment_detail.php?id=<?= (int)$selected['assignment_id'] ?>"
                       style="margin-left:auto;font-size:12px;font-weight:700;color:#2563eb;text-decoration:none;">
                        Xem lịch sử nộp bài <?= asg_icon('chev', 12) ?>
                    </a>
                </div>
                <?php if ($hasSubmit): ?>
                <div class="asg-limits">Nộp lại sẽ tạo phiên bản mới và điểm phiên bản mới sẽ chờ chấm lại. Hãy chọn lại các tệp muốn nộp.</div>
                <?php endif; ?>
            </form>
            <?php else: ?>
            <div class="asg-blocked"><?= h($disabledReason) ?></div>
            <div style="margin-top:12px;">
                <a href="assignment_detail.php?id=<?= (int)$selected['assignment_id'] ?>"
                   style="font-size:12.5px;font-weight:700;color:#2563eb;text-decoration:none;">
                    Xem chi tiết &amp; lịch sử nộp bài <?= asg_icon('chev', 12) ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php endif; ?>

</div></main>
<script>
(function () {
    var input = document.getElementById('asgFileInput');
    if (!input) return;
    var list  = document.getElementById('asgFileList');
    var error = document.getElementById('asgFileError');
    var form  = document.getElementById('asgForm');
    var MAX_FILES = <?= (int)UploadService::maxFiles() ?>;
    var MAX_FILE  = <?= (int)UploadService::fileLimitBytes() ?>;
    var MAX_TOTAL = <?= (int)UploadService::totalLimitBytes() ?>;

    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }
    function showError(msg) {
        if (!error) return;
        error.textContent = msg || '';
        error.style.display = msg ? 'block' : 'none';
    }
    function validate() {
        var files = input.files;
        if (files.length > MAX_FILES) {
            showError('Chỉ được tải lên tối đa ' + MAX_FILES + ' tệp.');
            return false;
        }
        var total = 0;
        for (var i = 0; i < files.length; i++) {
            if (files[i].size > MAX_FILE) {
                showError('Mỗi tệp không được vượt quá ' + fmtSize(MAX_FILE) + '.');
                return false;
            }
            total += files[i].size;
        }
        if (total > MAX_TOTAL) {
            showError('Tổng dung lượng không được vượt quá ' + fmtSize(MAX_TOTAL) + '.');
            return false;
        }
        showError('');
        return true;
    }
    function render() {
        if (!list) return;
        list.innerHTML = '';
        var files = input.files;
        for (var i = 0; i < files.length; i++) {
            var row = document.createElement('div');
            row.className = 'asg-file-list__item';
            var name = document.createElement('span');
            name.textContent = files[i].name;
            var size = document.createElement('span');
            size.className = 'asg-file-list__size';
            size.textContent = '(' + fmtSize(files[i].size) + ')';
            row.appendChild(name);
            row.appendChild(size);
            list.appendChild(row);
        }
    }
    input.addEventListener('change', function () { render(); validate(); });

    if (form) {
        form.addEventListener('submit', function (e) {
            var isDraft = e.submitter && e.submitter.name === 'save_draft';
            if (!validate()) { e.preventDefault(); return; }
            var notes = form.querySelector('textarea[name="content"]');
            var empty = (!notes || notes.value.trim() === '') && input.files.length === 0;
            if (!isDraft && empty) {
                e.preventDefault();
                showError('Vui lòng nhập nội dung hoặc chọn ít nhất một tệp trước khi nộp.');
            }
        });
    }
})();
</script>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
