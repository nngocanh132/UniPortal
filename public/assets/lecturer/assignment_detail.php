<?php
/**
 * UniPortal – Lecturer assignment detail
 * Full brief, attachments, roster/submission statistics and a filtered
 * list of every enrolled student with a link to each submission.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo       = get_db();
$lecId     = (int)($_SESSION['lecturer_id'] ?? 0);
$warnCount = LecturerModel::getUnreadWarningCount($lecId);

$assignmentId = (int)($_GET['asg'] ?? ($_GET['id'] ?? 0));
if ($assignmentId < 1) abort(404, 'Không tìm thấy bài tập');

$stmt = $pdo->prepare(
    'SELECT a.*, cs.section_id, cs.section_code, cs.semester_id,
            c.course_id, c.course_code, c.course_name,
            sem.semester_name, sem.academic_year,
            COALESCE(NULLIF(creator.full_name, \'\'), section_lecturer.full_name) AS creator_name
     FROM Assignment a
     JOIN ClassSection cs ON cs.section_id = a.section_id
     JOIN Course c        ON c.course_id = cs.course_id
     JOIN Semester sem    ON sem.semester_id = cs.semester_id
     LEFT JOIN Account creator_account ON creator_account.account_id = a.created_by
     LEFT JOIN Lecturer creator ON creator.lecturer_id = creator_account.lecturer_id
     LEFT JOIN Lecturer section_lecturer ON section_lecturer.lecturer_id = cs.lecturer_id
     WHERE a.assignment_id = :aid AND cs.lecturer_id = :lid AND a.is_deleted = 0
     LIMIT 1'
);
$stmt->execute([':aid' => $assignmentId, ':lid' => $lecId]);
$asg = $stmt->fetch();
if (!$asg) abort(404, 'Bài tập không tồn tại hoặc bạn không phụ trách lớp này.');

$sectionId = (int)$asg['section_id'];

$attStmt = $pdo->prepare(
    'SELECT attachment_id, file_path, file_name, file_size, file_mime
     FROM AssignmentAttachment WHERE assignment_id = :aid ORDER BY attachment_id'
);
$attStmt->execute([':aid' => $assignmentId]);
$attachments = $attStmt->fetchAll();
// Keep legacy materials accessible even after new attachments are added on edit.
if (!empty($asg['attachment_path']) && !in_array($asg['attachment_path'], array_column($attachments, 'file_path'), true)) {
    $attachments[] = [
        'attachment_id' => null, 'file_path' => $asg['attachment_path'],
        'file_name' => $asg['attachment_name'] ?: basename($asg['attachment_path']),
        'file_size' => $asg['attachment_size'], 'file_mime' => $asg['attachment_mime'],
    ];
}

$submissionAttachments = [];
$subAttStmt = $pdo->prepare(
    'SELECT sa.submission_id, sa.sub_attachment_id, sa.file_name, sa.file_size
     FROM SubmissionAttachment sa
     JOIN Submission sub ON sub.submission_id = sa.submission_id AND sa.version = sub.version
     WHERE sub.assignment_id = :aid ORDER BY sa.sub_attachment_id'
);
$subAttStmt->execute([':aid' => $assignmentId]);
foreach ($subAttStmt->fetchAll() as $attachment) {
    $submissionAttachments[(int)$attachment['submission_id']][] = $attachment;
}

$rosterStmt = $pdo->prepare(
    'SELECT e.enrollment_id, s.student_id, s.full_name, s.class_code,
            sub.submission_id, sub.submitted_at, sub.score, sub.grade_status,
            sub.file_path, sub.file_name, sub.file_size, sub.version, sub.revision, sub.is_demo
     FROM Enrollment e
     JOIN Student s ON s.student_id = e.student_id
     LEFT JOIN Submission sub ON sub.assignment_id = :aid AND sub.enrollment_id = e.enrollment_id
     WHERE e.section_id = :sid AND e.status IN (\'Enrolled\',\'Completed\')
     ORDER BY s.student_id ASC'
);
$rosterStmt->execute([':aid' => $assignmentId, ':sid' => $sectionId]);
$roster = $rosterStmt->fetchAll();

$dueTs = strtotime($asg['due_date']);
$counters = ['enrolled' => 0, 'submitted' => 0, 'missing' => 0, 'graded' => 0, 'published' => 0, 'ungraded' => 0, 'late' => 0];
$scoreSum = 0.0;
foreach ($roster as $row) {
    $counters['enrolled']++;
    $hasFile = !empty($row['submission_id']) && !empty($row['submitted_at']);
    if ($hasFile) {
        $counters['submitted']++;
        if (!empty($row['submitted_at']) && strtotime($row['submitted_at']) > $dueTs) $counters['late']++;
        if ($row['score'] !== null) { $counters['graded']++; $scoreSum += (float)$row['score']; }
        else { $counters['ungraded']++; }
        if ($row['grade_status'] === 'Published' && $row['score'] !== null) $counters['published']++;
    } else {
        $counters['missing']++;
    }
}
$avgScore = $counters['graded'] > 0 ? round($scoreSum / $counters['graded'], 1) : null;

$sf = in_array($_GET['sf'] ?? '', ['submitted', 'missing', 'late', 'graded', 'ungraded', 'published'], true) ? $_GET['sf'] : '';
$rows = $roster;
if ($sf !== '') {
    $rows = array_values(array_filter($roster, function (array $r) use ($sf, $dueTs): bool {
        $hasFile = !empty($r['submission_id']) && !empty($r['submitted_at']);
        $late    = $hasFile && strtotime($r['submitted_at']) > $dueTs;
        return match ($sf) {
            'submitted' => $hasFile,
            'missing'   => !$hasFile,
            'late'      => $late,
            'graded'    => $hasFile && $r['score'] !== null,
            'ungraded'  => $hasFile && $r['score'] === null,
            'published' => $hasFile && $r['score'] !== null && $r['grade_status'] === 'Published',
            default     => true,
        };
    }));
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
function fmt_size($b): string {
    $b = (int)$b;
    if ($b <= 0) return '—';
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}
function previewable_ext(string $name): bool {
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true);
}
function initials(string $n): string {
    $r = ''; foreach (explode(' ', trim($n)) as $w) $r .= strtoupper(substr($w, 0, 1)); return substr($r, -2);
}

$flash = $_SESSION['_flash'] ?? '';
unset($_SESSION['_flash']);

$pageTitle  = 'Chi tiết bài tập';
$activePage = 'assignments';
require __DIR__ . '/_layout.php';
?>

<?php if ($flash): [$flashType, $flashMessage] = explode(':', $flash, 2); ?>
<div role="status" style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;background:<?= $flashType === 'success' ? '#d1fae5' : '#fee2e2' ?>;color:<?= $flashType === 'success' ? '#065f46' : '#b91c1c' ?>;">
    <?= h($flashMessage) ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
    <a href="assignments.php?tab=manage&section=<?= $sectionId ?>" style="font-size:13px;color:#3b82f6;text-decoration:none;font-weight:600;">← Quản lý bài tập</a>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="assignments.php?tab=submissions&section=<?= $sectionId ?>&asg=<?= $assignmentId ?>" class="lbtn lbtn--outline lbtn--sm">📋 Bảng chấm bài</a>
        <?php if ($asg['is_demo']): ?><span class="lpill lpill--orange" style="font-size:10px;">Dữ liệu minh họa</span><?php endif; ?>
        <?php
        $stLbl = ['Draft' => '📝 Bản nháp', 'Published' => '🚀 Đã công bố', 'Closed' => '🔒 Đã đóng'][$asg['status']] ?? $asg['status'];
        ?>
        <span class="lpill lpill--gray" style="font-size:10px;"><?= h($stLbl) ?></span>
    </div>
</div>

<!-- Brief -->
<div class="lcard" style="padding:22px 24px;">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
        <span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($asg['course_code']) ?></span>
        <span style="font-size:11px;color:#64748b;"><?= h($asg['section_code']) ?></span>
        <span style="font-size:11px;color:#94a3b8;">•</span>
        <span style="font-size:11px;color:#64748b;"><?= h($asg['course_name']) ?></span>
        <span style="font-size:11px;color:#94a3b8;">•</span>
        <span style="font-size:11px;color:#64748b;"><?= h($asg['semester_name'] . ' ' . $asg['academic_year']) ?></span>
    </div>
    <h1 style="font-size:21px;font-weight:800;color:#001634;margin-bottom:12px;line-height:1.35;"><?= h($asg['title']) ?></h1>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:16px;">
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Giảng viên tạo bài tập</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;"><?= h($asg['creator_name'] ?? '—') ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Chính sách nộp lại</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;"><?= (int)($asg['allow_resubmission'] ?? 1) === 1 ? 'Cho phép nộp lại khi bài tập còn mở' : 'Chỉ được nộp một lần' ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Ngày tạo / công bố</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;">📅 <?= fmt_dt($asg['publication_date'] ?: $asg['created_at']) ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Hạn nộp</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;">⏰ <?= fmt_dt($asg['due_date']) ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Điểm tối đa</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;">📊 <?= number_format((float)$asg['max_score'], 2) ?> điểm</div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
            <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Sĩ số</div>
            <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;">👥 <?= (int)$counters['enrolled'] ?> sinh viên</div>
        </div>
    </div>

    <?php if (!empty($asg['description'])): ?>
    <div style="margin-bottom:14px;">
        <div style="font-size:13px;font-weight:700;color:#102b50;margin-bottom:5px;">📋 Mô tả</div>
        <div style="font-size:13px;color:#334155;line-height:1.65;"><?= nl2br(h($asg['description'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($asg['instructions'])): ?>
    <div style="margin-bottom:14px;padding:12px 16px;background:#f8fafc;border-left:4px solid #102b50;border-radius:8px;">
        <div style="font-size:13px;font-weight:700;color:#102b50;margin-bottom:5px;">📝 Yêu cầu &amp; hướng dẫn</div>
        <div style="font-size:13px;color:#334155;line-height:1.7;"><?= nl2br(h($asg['instructions'])) ?></div>
    </div>
    <?php endif; ?>

    <div>
        <div style="font-size:13px;font-weight:700;color:#102b50;margin-bottom:8px;">📎 Tài liệu đính kèm</div>
        <?php if (empty($attachments)): ?>
        <div style="font-size:12.5px;color:#94a3b8;font-style:italic;">Không có tài liệu đính kèm.</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($attachments as $att):
                $attachmentUrl = '../../download.php?type=' . (!empty($att['attachment_id'])
                    ? 'assignment_attachment&id=' . (int)$att['attachment_id']
                    : 'assignment&id=' . $assignmentId);
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <div style="font-size:13px;font-weight:600;color:#001634;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    📄 <?= h($att['file_name']) ?>
                    <span style="font-size:11px;color:#94a3b8;font-weight:400;">(<?= h(fmt_size($att['file_size'])) ?>)</span>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <?php if (previewable_ext($att['file_name'])): ?>
                    <a href="<?= h($attachmentUrl) ?>&preview=1"
                       target="_blank" rel="noopener" class="lbtn lbtn--outline lbtn--sm">🔍 Xem trước</a>
                    <?php endif; ?>
                    <a href="<?= h($attachmentUrl) ?>" class="lbtn lbtn--outline lbtn--sm">⬇ Tải</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Statistics -->
<div class="lec-grade-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;">
    <div class="lcard" style="padding:14px;border-left:4px solid #102b50;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Sĩ số</div>
        <div style="font-size:26px;font-weight:800;color:#001634;"><?= (int)$counters['enrolled'] ?></div>
        <div style="font-size:11px;color:#94a3b8;">sinh viên</div>
    </div>
    <div class="lcard" style="padding:14px;border-left:4px solid #22c55e;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Đã nộp</div>
        <div style="font-size:26px;font-weight:800;color:#166534;"><?= (int)$counters['submitted'] ?></div>
        <div style="font-size:11px;color:#94a3b8;"><?= (int)$counters['enrolled'] > 0 ? round($counters['submitted'] / $counters['enrolled'] * 100) : 0 ?>%</div>
    </div>
    <div class="lcard" style="padding:14px;border-left:4px solid #f59e0b;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Chưa nộp</div>
        <div style="font-size:26px;font-weight:800;color:#92400e;"><?= (int)$counters['missing'] ?></div>
        <div style="font-size:11px;color:#94a3b8;">cần nhắc nhở</div>
    </div>
    <div class="lcard" style="padding:14px;border-left:4px solid #6366f1;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Đã chấm</div>
        <div style="font-size:26px;font-weight:800;color:#4338ca;"><?= (int)$counters['graded'] ?></div>
        <div style="font-size:11px;color:#94a3b8;"><?= (int)$counters['published'] ?> đã công bố</div>
    </div>
    <div class="lcard" style="padding:14px;border-left:4px solid #ef4444;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Nộp muộn</div>
        <div style="font-size:26px;font-weight:800;color:#b91c1c;"><?= (int)$counters['late'] ?></div>
        <div style="font-size:11px;color:#94a3b8;">sau hạn</div>
    </div>
    <div class="lcard" style="padding:14px;border-left:4px solid #0ea5e9;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Điểm trung bình</div>
        <div style="font-size:26px;font-weight:800;color:#0369a1;"><?= $avgScore !== null ? number_format($avgScore, 1) : '—' ?></div>
        <div style="font-size:11px;color:#94a3b8;">/ <?= number_format((float)$asg['max_score'], 2) ?></div>
    </div>
</div>

<!-- Filters -->
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Bài nộp:</span>
    <?php
    $sfLinks = [
        ''          => 'Tất cả (' . $counters['enrolled'] . ')',
        'submitted' => '✅ Đã nộp (' . $counters['submitted'] . ')',
        'missing'   => '⏳ Chưa nộp (' . $counters['missing'] . ')',
        'late'      => '🕐 Nộp muộn (' . $counters['late'] . ')',
        'graded'    => '📊 Đã chấm (' . $counters['graded'] . ')',
        'ungraded'  => '📝 Chưa chấm (' . $counters['ungraded'] . ')',
    ];
    foreach ($sfLinks as $key => $label):
    ?>
    <a href="assignment_detail.php?asg=<?= $assignmentId ?><?= $key !== '' ? '&sf=' . $key : '' ?>"
       class="lpill <?= $sf === $key ? 'lpill--blue' : 'lpill--gray' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<!-- Roster -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>STT</th>
                    <th>Sinh viên</th>
                    <th>Trạng thái</th>
                    <th>Thời gian nộp</th>
                    <th>File</th>
                    <th>Điểm</th>
                    <th>Công bố</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="8" style="text-align:center;padding:36px;color:#94a3b8;font-style:italic;">
                    Không có bản ghi phù hợp bộ lọc.
                </td></tr>
                <?php else: ?>
                <?php foreach ($rows as $i => $row):
                    $hasSub = !empty($row['submission_id']) && !empty($row['submitted_at']);
                    $hasDraft = !empty($row['submission_id']) && empty($row['submitted_at']);
                    $currentAttachments = $hasSub ? ($submissionAttachments[(int)($row['submission_id'] ?? 0)] ?? []) : [];
                    $late   = $hasSub && strtotime($row['submitted_at']) > strtotime($asg['due_date']);
                ?>
                <tr>
                    <td style="color:#94a3b8;font-size:12px;"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span class="lec-av" style="width:32px;height:32px;"><?= h(initials($row['full_name'])) ?></span>
                            <div>
                                <div style="font-weight:700;font-size:13px;color:#001634;"><?= h($row['full_name']) ?></div>
                                <div style="font-size:11px;color:#64748b;"><?= h($row['student_id']) ?> – <?= h($row['class_code']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($hasSub): ?>
                        <span class="lpill lpill--green" style="font-size:10px;">✅ Đã nộp<?= $late ? ' (trễ)' : '' ?></span>
                        <?php elseif ($hasDraft): ?>
                        <span class="lpill lpill--orange" style="font-size:10px;">✏️ Đang làm (nháp)</span>
                        <?php else: ?>
                        <span class="lpill lpill--gray" style="font-size:10px;">Chưa nộp</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#64748b;"><?= $hasSub ? fmt_dt($row['submitted_at']) : '—' ?></td>
                    <td style="font-size:12px;">
                        <?php if ($currentAttachments): ?>
                        <?php foreach ($currentAttachments as $attachment): ?>
                        <a href="../../download.php?type=submission_attachment&id=<?= (int)$attachment['sub_attachment_id'] ?>"
                           style="display:block;color:#3b82f6;" title="<?= h($attachment['file_name']) ?>">
                            <?= h(mb_strimwidth($attachment['file_name'], 0, 22, '…')) ?>
                        </a>
                        <?php endforeach; ?>
                        <?php elseif (!empty($row['file_path'])): ?>
                        <a href="../../download.php?type=submission&id=<?= (int)$row['submission_id'] ?>" style="color:#3b82f6;">
                            <?= h(mb_strimwidth((string)$row['file_name'], 0, 22, '…')) ?>
                        </a>
                        <?php else: ?>
                        —
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px;font-weight:700;color:<?= $row['score'] !== null ? '#001634' : '#94a3b8' ?>;">
                        <?= $row['score'] !== null ? number_format((float)$row['score'], 2) : '—' ?>
                    </td>
                    <td>
                        <?php if ($row['score'] === null): ?>
                        <span style="font-size:11px;color:#94a3b8;">—</span>
                        <?php elseif ($row['grade_status'] === 'Published'): ?>
                        <span class="lpill lpill--green" style="font-size:10px;">Đã công bố</span>
                        <?php else: ?>
                        <span class="lpill lpill--orange" style="font-size:10px;">Bản nháp</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($hasSub): ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <a href="submission_detail.php?sub=<?= (int)$row['submission_id'] ?>" class="lbtn lbtn--outline lbtn--sm">📝 Chấm / xem</a>
                            <?php if (!$currentAttachments && !empty($row['file_path'])): ?>
                            <a href="../../download.php?type=submission&id=<?= (int)$row['submission_id'] ?>" class="lbtn lbtn--outline lbtn--sm">Tải</a>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span style="font-size:11px;color:#cbd5e1;">Chưa có bài</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
