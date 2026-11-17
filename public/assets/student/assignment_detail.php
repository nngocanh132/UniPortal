<?php
/**
 * UniPortal – Student assignment detail
 * Read the full brief, preview/download materials, submit or resubmit,
 * and see the published grade + feedback.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/AssignmentService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo        = get_db();
$student_id = (string)($_SESSION['student_id'] ?? '');
$account_id = (int)($_SESSION['account_id'] ?? 0);

$assignmentId = (int)($_GET['id'] ?? 0);
if ($assignmentId < 1) abort(404, 'Không tìm thấy bài tập');

// Submission policy (same source of truth as the assignments list page)
$allowLate = false;
try {
    $lateCfg   = $pdo->query("SELECT config_value FROM SystemConfig WHERE config_key='late_submission_allowed'")->fetchColumn();
    $allowLate = (int)$lateCfg === 1;
} catch (Throwable) {
    $allowLate = false;
}

// All submission validation, uploads and version writes belong to the service.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['submit_assignment']) || isset($_POST['save_draft']))) {
    csrf_verify();
    try {
        if (isset($_POST['save_draft'])) {
            $result = AssignmentService::saveDraft(
                $assignmentId,
                (int)($_POST['enrollment_id'] ?? 0),
                $student_id,
                (string)($_POST['content'] ?? ''),
                $_FILES['submission_files'] ?? []
            );
        } else {
            $result = AssignmentService::submit(
                $assignmentId,
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
    $_SESSION['student_asg_detail_flash'] = $flash;
    header('Location: assignment_detail.php?id=' . $assignmentId, true, 303);
    exit;
}

$flash = '';
if (isset($_SESSION['student_asg_detail_flash'])) {
    $flash = (string)$_SESSION['student_asg_detail_flash'];
    unset($_SESSION['student_asg_detail_flash']);
}

// Enrolled/completed sections remain accessible even in past semesters.
$stmt = $pdo->prepare(
    'SELECT a.assignment_id, a.title, a.description, a.instructions, a.due_date,
            a.max_score, a.attachment_name, a.attachment_path, a.status, a.allow_resubmission,
            a.publication_date, a.created_at, cs.section_id, cs.section_code, cs.semester_id,
            c.course_id, c.course_code, c.course_name,
            COALESCE(creator.full_name, l.full_name) AS lecturer_name,
            e.enrollment_id,
            sub.submission_id, sub.file_path AS sub_path, sub.file_name AS sub_file,
            sub.file_size AS sub_size, sub.file_mime AS sub_mime,
            sub.submitted_at, sub.score AS sub_score, sub.feedback AS sub_feedback,
            sub.grade_status, sub.version AS sub_version,
            sub.content AS sub_content, sub.revision AS sub_revision
     FROM Assignment a
     JOIN ClassSection cs ON cs.section_id = a.section_id
     JOIN Course c        ON c.course_id = cs.course_id
     JOIN Enrollment e    ON e.section_id = cs.section_id
     LEFT JOIN Account author ON author.account_id = a.created_by
     LEFT JOIN Lecturer creator ON creator.lecturer_id = author.lecturer_id
     LEFT JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
     LEFT JOIN Submission sub ON sub.assignment_id = a.assignment_id
                              AND sub.enrollment_id = e.enrollment_id
     WHERE a.assignment_id = :aid
       AND a.is_deleted = 0
       AND a.status IN (\'Published\',\'Closed\')
       AND (a.publication_date IS NULL OR a.publication_date <= NOW())
       AND e.student_id = :sid
       AND e.status IN (\'Enrolled\',\'Completed\')
     LIMIT 1'
);
$stmt->execute([':aid' => $assignmentId, ':sid' => $student_id]);
$asg = $stmt->fetch();
if (!$asg) abort(404, 'Bài tập không tồn tại hoặc bạn không có quyền truy cập.');

// Attachments
$attStmt = $pdo->prepare(
    'SELECT attachment_id, file_path, file_name, file_size, file_mime
     FROM AssignmentAttachment WHERE assignment_id = :aid ORDER BY attachment_id'
);
$attStmt->execute([':aid' => $assignmentId]);
$attachments = $attStmt->fetchAll();

$subAttachments = [];
$attempts = [];
$attemptAttachments = [];
if ($asg['submission_id']) {
    $saStmt = $pdo->prepare(
        'SELECT sub_attachment_id, file_name, file_size, file_mime, file_path
         FROM SubmissionAttachment
         WHERE submission_id = :sid AND version = :version ORDER BY sub_attachment_id'
    );
    $saStmt->execute([':sid' => $asg['submission_id'], ':version' => (int)$asg['sub_version']]);
    $subAttachments = $saStmt->fetchAll();

    // History is scoped to the submission loaded through this student's enrollment.
    $attemptStmt = $pdo->prepare(
        'SELECT attempt_id, submission_id, version, content, file_path, file_name,
                file_size, file_mime, submitted_at, score, feedback, grade_status
         FROM SubmissionAttempt
         WHERE submission_id = :sid AND version < :version ORDER BY version DESC, attempt_id DESC'
    );
    $attemptStmt->execute([':sid' => $asg['submission_id'], ':version' => (int)$asg['sub_version']]);
    $attempts = $attemptStmt->fetchAll();

    if ($attempts) {
        $historyFilesStmt = $pdo->prepare(
            'SELECT sa.sub_attachment_id, sa.version, sa.file_name, sa.file_size, sa.file_mime
             FROM SubmissionAttachment sa
             JOIN SubmissionAttempt attempt ON attempt.submission_id = sa.submission_id
                                           AND attempt.version = sa.version
             WHERE attempt.submission_id = :sid AND attempt.version < :version
             ORDER BY sa.version DESC, sa.sub_attachment_id'
        );
        $historyFilesStmt->execute([':sid' => $asg['submission_id'], ':version' => (int)$asg['sub_version']]);
        foreach ($historyFilesStmt->fetchAll() as $file) {
            $attemptAttachments[(int)$file['version']][] = $file;
        }
    }
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
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
function lecturer_label(array $a): string {
    return $a['lecturer_name'] ?? '—';
}

$now        = time();
$dueTs      = strtotime($asg['due_date']);
$isOverdue  = $dueTs < $now;
$isClosed   = $asg['status'] === 'Closed';
$hasSubmit  = $asg['submission_id'] !== null && $asg['submitted_at'] !== null;
$hasDraft   = $asg['submission_id'] !== null && $asg['submitted_at'] === null;
$isLate     = $hasSubmit && strtotime($asg['submitted_at']) > $dueTs;
$isGraded   = $hasSubmit && $asg['sub_score'] !== null && $asg['grade_status'] === 'Published';
$disabledReason = '';
if ($asg['status'] !== 'Published') {
    $disabledReason = 'Bài tập đã đóng hoặc chưa được công bố, không thể nộp bài.';
} elseif ($hasSubmit && $asg['grade_status'] === 'Published') {
    $disabledReason = 'Bài đã công bố điểm, không thể nộp lại.';
} elseif ($hasSubmit && !(bool)$asg['allow_resubmission']) {
    $disabledReason = 'Giảng viên không cho phép nộp lại bài tập này.';
} elseif ($isOverdue && !$allowLate) {
    $disabledReason = 'Đã quá hạn nộp và hệ thống không cho phép nộp muộn.';
}
$canSubmit = $disabledReason === '';

$pageTitle  = 'Chi tiết bài tập';
$activePage = 'assignments';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft === 'success' ? '#d1fae5' : '#fee2e2' ?>;
            border:1px solid <?= $ft === 'success' ? '#6ee7b7' : '#fca5a5' ?>;
            color:<?= $ft === 'success' ? '#065f46' : '#b91c1c' ?>;">
    <?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
    <a href="assignments.php" style="font-size:13px;color:#3b82f6;text-decoration:none;font-weight:600;">← Danh sách bài tập</a>
    <?php if ($isGraded): ?>
    <span class="status-pill status-pill--complete">✅ Đã công bố điểm</span>
    <?php elseif ($isClosed): ?>
    <span class="status-pill status-pill--absent">🔒 Bài tập đã đóng</span>
    <?php elseif ($hasSubmit): ?>
    <span class="status-pill status-pill--enrolled">📤 Đã nộp</span>
    <?php elseif ($hasDraft): ?>
    <span class="status-pill" style="background:#fef3c7;color:#92400e;">✏️ Đang làm (đã lưu nháp)</span>
    <?php elseif ($isOverdue): ?>
    <span class="status-pill status-pill--absent">⏰ Quá hạn</span>
    <?php else: ?>
    <span class="status-pill" style="background:#fef9c3;color:#854d0e;">Chờ nộp</span>
    <?php endif; ?>
</div>

<!-- Assignment brief -->
<div class="card" style="padding:24px 26px;">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
        <span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($asg['course_code']) ?></span>
        <span style="font-size:11px;color:#64748b;"><?= h($asg['section_code']) ?></span>
        <span style="font-size:11px;color:#94a3b8;">•</span>
        <span style="font-size:11px;color:#64748b;"><?= h($asg['course_name']) ?></span>
    </div>
    <h1 style="font-size:22px;font-weight:800;color:#001634;margin-bottom:12px;line-height:1.35;"><?= h($asg['title']) ?></h1>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-bottom:18px;">
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.3px;">Giảng viên phụ trách</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;">👨‍🏫 <?= h(lecturer_label($asg)) ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.3px;">Ngày tạo / công bố</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;">📅 <?= fmt_dt($asg['publication_date'] ?: $asg['created_at']) ?></div>
        </div>
        <div style="background:<?= $isOverdue ? '#fef2f2' : '#f8fafc' ?>;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.3px;">Hạn nộp</div>
            <div style="font-size:13px;font-weight:700;color:<?= $isOverdue ? '#b91c1c' : '#001634' ?>;margin-top:3px;">⏰ <?= fmt_dt($asg['due_date']) ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.3px;">Điểm tối đa</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;">📊 <?= number_format((float)$asg['max_score'], 2) ?> điểm</div>
        </div>
    </div>

    <?php if (!empty($asg['description'])): ?>
    <div style="margin-bottom:16px;">
        <div style="font-size:13px;font-weight:700;color:#102b50;margin-bottom:6px;">📋 Mô tả bài tập</div>
        <div style="font-size:13.5px;color:#334155;line-height:1.65;"><?= nl2br(h($asg['description'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($asg['instructions'])): ?>
    <div style="margin-bottom:16px;padding:14px 16px;background:#f8fafc;border-left:4px solid #102b50;border-radius:8px;">
        <div style="font-size:13px;font-weight:700;color:#102b50;margin-bottom:6px;">📝 Yêu cầu &amp; hướng dẫn thực hiện</div>
        <div style="font-size:13px;color:#334155;line-height:1.7;"><?= nl2br(h($asg['instructions'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Materials -->
    <div>
        <div style="font-size:13px;font-weight:700;color:#102b50;margin-bottom:8px;">📎 Tài liệu đính kèm</div>
        <?php if (empty($attachments) && empty($asg['attachment_name'])): ?>
        <div style="font-size:12.5px;color:#94a3b8;font-style:italic;">Bài tập không có tài liệu đính kèm.</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($attachments as $att): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                    <span style="font-size:18px;">📄</span>
                    <div style="min-width:0;">
                        <div style="font-size:13px;font-weight:600;color:#001634;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($att['file_name']) ?></div>
                        <div style="font-size:11px;color:#94a3b8;"><?= h(fmt_size($att['file_size'])) ?></div>
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <?php if (previewable_ext($att['file_name'])): ?>
                    <a href="../../download.php?type=assignment_attachment&id=<?= (int)$att['attachment_id'] ?>&preview=1"
                       target="_blank" rel="noopener"
                       style="padding:5px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;color:#334155;text-decoration:none;">🔍 Xem trước</a>
                    <?php endif; ?>
                    <a href="../../download.php?type=assignment_attachment&id=<?= (int)$att['attachment_id'] ?>"
                       style="padding:5px 10px;background:#102b50;color:#fff;border-radius:6px;font-size:12px;text-decoration:none;">⬇ Tải xuống</a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($attachments) && $asg['attachment_name']): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <div style="font-size:13px;font-weight:600;color:#001634;">📄 <?= h($asg['attachment_name']) ?></div>
                <a href="../../download.php?type=assignment&id=<?= (int)$asg['assignment_id'] ?>"
                   style="padding:5px 10px;background:#102b50;color:#fff;border-radius:6px;font-size:12px;text-decoration:none;">⬇ Tải xuống</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Submission status -->
<div class="card" style="padding:22px 24px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <h2 style="font-size:16px;font-weight:800;color:#001634;margin:0;">📤 Bài nộp của bạn</h2>
        <?php if ($hasSubmit): ?>
        <span style="font-size:12px;color:#64748b;">Phiên bản <?= (int)$asg['sub_version'] ?> • <?= fmt_dt($asg['submitted_at']) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($hasSubmit): ?>
        <div style="padding:12px 14px;border-radius:8px;background:<?= $isLate ? '#fff7ed' : '#f0fdf4' ?>;border:1px solid <?= $isLate ? '#fed7aa' : '#bbf7d0' ?>;">
            <div style="font-size:13px;font-weight:600;color:<?= $isLate ? '#9a3412' : '#166534' ?>;">
                <?= $isLate ? '⏱️ Đã nộp muộn' : '✅ Đã nộp' ?> lúc <?= fmt_dt($asg['submitted_at']) ?>
            </div>
            <?php if ((string)($asg['sub_content'] ?? '') !== ''): ?>
            <div style="margin-top:10px;font-size:13px;color:#334155;line-height:1.6;overflow-wrap:anywhere;">
                <strong>Nội dung bài nộp:</strong>
                <div><?= nl2br(h($asg['sub_content'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($subAttachments)): ?>
            <div style="display:flex;flex-direction:column;gap:6px;margin-top:8px;">
                <?php foreach ($subAttachments as $sa): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:12.5px;color:#334155;">📄 <?= h($sa['file_name']) ?> <span style="color:#94a3b8;">(<?= h(fmt_size($sa['file_size'])) ?>)</span></span>
                    <span style="display:flex;gap:6px;">
                        <?php if (previewable_ext($sa['file_name'])): ?>
                        <a href="../../download.php?type=submission_attachment&id=<?= (int)$sa['sub_attachment_id'] ?>&preview=1"
                           target="_blank" rel="noopener"
                           style="font-size:12px;color:#2563eb;text-decoration:none;">🔍 Xem trước</a>
                        <?php endif; ?>
                        <a href="../../download.php?type=submission_attachment&id=<?= (int)$sa['sub_attachment_id'] ?>"
                           style="font-size:12px;color:#2563eb;text-decoration:none;">⬇ Tải xuống</a>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($asg['sub_path']): ?>
            <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap;">
                <span style="font-size:12.5px;color:#334155;">📄 <?= h($asg['sub_file']) ?></span>
                <?php if (previewable_ext((string)$asg['sub_file'])): ?>
                <a href="../../download.php?type=submission&id=<?= (int)$asg['submission_id'] ?>&preview=1"
                   target="_blank" rel="noopener" style="font-size:12px;color:#2563eb;">🔍 Xem trước</a>
                <?php endif; ?>
                <a href="../../download.php?type=submission&id=<?= (int)$asg['submission_id'] ?>"
                   style="font-size:12px;color:#2563eb;">⬇ Tải xuống</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($isGraded): ?>
        <div style="margin-top:14px;display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
            <div style="text-align:center;padding:14px 20px;background:#f0fdf4;border-radius:10px;min-width:110px;">
                <div style="font-size:28px;font-weight:800;color:#059669;"><?= number_format((float)$asg['sub_score'], 2) ?></div>
                <div style="font-size:11px;color:#64748b;">/ <?= number_format((float)$asg['max_score'], 2) ?> điểm</div>
            </div>
            <div style="flex:1;min-width:220px;padding:12px 14px;background:#f8fafc;border-radius:8px;border-left:3px solid #3b82f6;">
                <div style="font-size:12px;font-weight:700;color:#1e40af;margin-bottom:4px;">💬 Nhận xét của giảng viên</div>
                <div style="font-size:13px;color:#334155;line-height:1.6;"><?= $asg['sub_feedback'] ? nl2br(h($asg['sub_feedback'])) : '—' ?></div>
            </div>
        </div>
        <?php elseif ($asg['sub_score'] !== null): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#eff6ff;border-radius:8px;font-size:12.5px;color:#1e40af;">
            📝 Bài đã được chấm nhưng điểm chưa được công bố. Vui lòng chờ giảng viên.
        </div>
        <?php else: ?>
        <div style="margin-top:12px;padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:12.5px;color:#64748b;">
            ⏳ Bài đã nộp và đang chờ giảng viên chấm điểm.
        </div>
        <?php endif; ?>
    <?php elseif ($hasDraft): ?>
        <div style="padding:12px 14px;border-radius:8px;background:#fffbeb;border:1px solid #fcd34d;font-size:13px;color:#92400e;">
            <strong>✏️ Bản nháp đã lưu.</strong> Bài <strong>chưa được nộp</strong> cho giảng viên – bạn có thể tiếp tục chỉnh sửa và bấm “Nộp bài” khi sẵn sàng.
            <?php if ((string)($asg['sub_content'] ?? '') !== ''): ?>
            <div style="margin-top:8px;color:#334155;line-height:1.6;overflow-wrap:anywhere;"><?= nl2br(h($asg['sub_content'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($subAttachments)): ?>
            <div style="margin-top:6px;color:#64748b;"><?= count($subAttachments) ?> tệp nháp đã lưu (sẽ được gửi kèm khi nộp).</div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div style="padding:12px 14px;border-radius:8px;background:#f8fafc;border:1px dashed #cbd5e1;font-size:13px;color:#64748b;">
            Bạn chưa nộp bài cho bài tập này.
        </div>
    <?php endif; ?>

    <?php if ($canSubmit): ?>
    <form method="POST" enctype="multipart/form-data" style="margin-top:16px;padding:16px;background:#f8fafc;border-radius:10px;border:1px dashed #cbd5e1;">
        <?= csrf_input() ?>
        <input type="hidden" name="submit_assignment" value="1" />
        <input type="hidden" name="enrollment_id" value="<?= (int)$asg['enrollment_id'] ?>" />
        <input type="hidden" name="version" value="<?= (int)($asg['sub_version'] ?? 0) ?>" />
        <div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:10px;">
            <?= $hasSubmit ? '🔄 Nộp lại bài' : '📤 Nộp bài' ?>
            <?php if ($isOverdue): ?><span style="color:#ef4444;font-size:11px;margin-left:6px;">(Quá hạn – có thể bị trừ điểm)</span><?php endif; ?>
        </div>
        <label style="display:block;font-size:12px;color:#334155;margin-bottom:12px;">
            Nội dung bài nộp (có thể nộp chỉ nội dung, không cần file)
            <textarea name="content" rows="6" style="display:block;box-sizing:border-box;width:100%;margin-top:6px;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;resize:vertical;"><?= h($asg['sub_content'] ?? '') ?></textarea>
        </label>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="file" name="submission_files[]" multiple aria-label="File bài nộp"
                   accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar,.jpg,.jpeg,.png,.txt,.py,.java,.cpp,.c"
                   style="font-size:13px;" />
            <button type="submit"
                    onclick="return confirm('Xác nhận <?= $hasSubmit ? 'nộp lại' : 'nộp' ?> bài tập này?');"
                    style="padding:9px 20px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                <?= $hasSubmit ? 'Nộp lại' : 'Nộp bài' ?>
            </button>
            <?php if (!$hasSubmit): ?>
            <button type="submit" name="save_draft" value="1" formnovalidate
                    style="padding:9px 20px;background:#fff;color:#92400e;border:1px solid #fcd34d;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                💾 Lưu nháp
            </button>
            <?php endif; ?>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:8px;">
            Tối đa 10 file, 20 MB/file, 30 MB tổng cộng. Nhập nội dung hoặc chọn ít nhất một file.
            PDF, Word, Excel, PowerPoint, ZIP, ảnh, mã nguồn…
            <?php if ($hasSubmit): ?><br>Nộp lại tạo phiên bản mới; hãy chọn lại các file muốn nộp. File và nội dung cũ được lưu trong lịch sử; điểm phiên bản mới sẽ chờ chấm lại.<?php endif; ?>
        </div>
    </form>
    <?php else: ?>
    <div style="margin-top:14px;padding:10px 14px;background:#fef2f2;border-radius:8px;font-size:12.5px;color:#b91c1c;">
        <?= h($disabledReason) ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($hasSubmit): ?>
<!-- Archived attempts never replace the current submission above. -->
<div class="card" style="padding:22px 24px;">
    <h2 style="font-size:16px;font-weight:800;color:#001634;margin:0 0 12px;">Lịch sử nộp bài</h2>
    <?php if (!$attempts): ?>
    <div style="font-size:13px;color:#64748b;">Chưa có phiên bản cũ. Bài nộp hiện tại được hiển thị ở trên.</div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($attempts as $attempt):
            $historyFiles = $attemptAttachments[(int)$attempt['version']] ?? [];
            if (!$historyFiles && !empty($attempt['file_path'])) {
                $historyFiles = [$attempt];
            }
        ?>
        <details style="padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
            <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#334155;">
                Phiên bản <?= (int)$attempt['version'] ?> — <?= h(fmt_dt($attempt['submitted_at'])) ?>
            </summary>
            <?php if ((string)($attempt['content'] ?? '') !== ''): ?>
            <div style="margin-top:12px;font-size:13px;color:#334155;line-height:1.6;overflow-wrap:anywhere;">
                <strong>Nội dung bài nộp:</strong>
                <div><?= nl2br(h($attempt['content'])) ?></div>
            </div>
            <?php endif; ?>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:12px;">
                <?php foreach ($historyFiles as $file):
                    $historyUrl = isset($file['sub_attachment_id'])
                        ? '../../download.php?type=submission_attachment&id=' . (int)$file['sub_attachment_id']
                        : '../../download.php?type=submission_attempt&id=' . (int)$attempt['attempt_id'];
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:12.5px;color:#334155;overflow-wrap:anywhere;">
                        <?= h($file['file_name'] ?? 'File bài nộp') ?>
                        <span style="color:#64748b;">(<?= h(fmt_size($file['file_size'])) ?>)</span>
                    </span>
                    <span style="display:flex;gap:10px;">
                        <?php if (previewable_ext((string)($file['file_name'] ?? ''))): ?>
                        <a href="<?= h($historyUrl . '&preview=1') ?>" target="_blank" rel="noopener"
                           style="font-size:12px;color:#2563eb;text-decoration:none;">Xem trước</a>
                        <?php endif; ?>
                        <a href="<?= h($historyUrl) ?>" style="font-size:12px;color:#2563eb;text-decoration:none;">Tải xuống</a>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (!$historyFiles): ?>
                <div style="font-size:12px;color:#64748b;">Phiên bản này không có file đính kèm.</div>
                <?php endif; ?>
            </div>
            <?php if ($attempt['grade_status'] === 'Published'): ?>
            <div style="margin-top:12px;padding:10px 14px;background:#f0fdf4;border-radius:8px;color:#166534;font-size:13px;">
                <strong>Điểm đã công bố:</strong>
                <?= $attempt['score'] !== null ? number_format((float)$attempt['score'], 1) : '—' ?>
                <?php if ((string)($attempt['feedback'] ?? '') !== ''): ?>
                <div style="margin-top:6px;line-height:1.6;overflow-wrap:anywhere;">
                    <strong>Nhận xét của giảng viên:</strong><br><?= nl2br(h($attempt['feedback'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="margin-top:12px;font-size:12px;color:#64748b;">Điểm và nhận xét của phiên bản này chưa được công bố.</div>
            <?php endif; ?>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
