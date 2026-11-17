<?php
/**
 * UniPortal – Lecturer submission detail
 * Opens one student's submission, previews/downloads the file,
 * enters or edits a grade, publishes it and shows the change history.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/AssignmentService.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo       = get_db();
$lecId     = (int)($_SESSION['lecturer_id'] ?? 0);
$accountId = (int)($_SESSION['account_id'] ?? 0);
$warnCount = LecturerModel::getUnreadWarningCount($lecId);

$submissionId = (int)($_GET['sub'] ?? ($_GET['id'] ?? 0));
if ($submissionId < 1) abort(404, 'Không tìm thấy bài nộp');

$loadStmt = $pdo->prepare(
    'SELECT sub.*, a.assignment_id, a.title AS assignment_title, a.due_date, a.max_score,
            a.section_id, a.status AS assignment_status,
            cs.section_code, cs.lecturer_id,
            c.course_code, c.course_name,
            s.student_id, s.full_name, s.class_code,
            e.enrollment_id
     FROM Submission sub
     JOIN Assignment a   ON a.assignment_id = sub.assignment_id AND a.is_deleted = 0
     JOIN ClassSection cs ON cs.section_id = a.section_id
     JOIN Course c        ON c.course_id = cs.course_id
     JOIN Enrollment e    ON e.enrollment_id = sub.enrollment_id AND e.section_id = a.section_id
     JOIN Student s       ON s.student_id = e.student_id
     WHERE sub.submission_id = :sid AND cs.lecturer_id = :lid
     LIMIT 1'
);
$loadStmt->execute([':sid' => $submissionId, ':lid' => $lecId]);
$sub = $loadStmt->fetch();
if (!$sub) abort(404, 'Bài nộp không tồn tại hoặc bạn không phụ trách lớp này.');

// ── POST: save grade ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission'])) {
    csrf_verify();
    $result = AssignmentService::grade($submissionId, $lecId, $accountId,
        (string)($_POST['score'] ?? ''), (string)($_POST['feedback'] ?? ''), isset($_POST['publish']),
        (int)($_POST['version'] ?? -1), (int)($_POST['revision'] ?? -1));
    $flash = ($result['ok'] ? 'success:' : 'error:') . $result['message'];

    $_SESSION['lec_submission_flash'] = $flash;
    header('Location: submission_detail.php?sub=' . $submissionId);
    exit;
}

$flash = '';
if (isset($_SESSION['lec_submission_flash'])) {
    $flash = (string)$_SESSION['lec_submission_flash'];
    unset($_SESSION['lec_submission_flash']);
}

// ── Files ────────────────────────────────────────────────────
$attStmt = $pdo->prepare(
    'SELECT sub_attachment_id, file_name, file_size, file_mime, file_path
     FROM SubmissionAttachment WHERE submission_id = :sid AND version = :version ORDER BY sub_attachment_id'
);
$attStmt->execute([':sid' => $submissionId, ':version' => $sub['version']]);
$attemptStmt = $pdo->prepare('SELECT * FROM SubmissionAttempt WHERE submission_id = ? ORDER BY version DESC');
$attemptStmt->execute([$submissionId]);
$attempts = $attemptStmt->fetchAll();
$oldFilesStmt = $pdo->prepare('SELECT sub_attachment_id, version, file_name FROM SubmissionAttachment WHERE submission_id = ? AND version < ? ORDER BY version DESC, sub_attachment_id');
$oldFilesStmt->execute([$submissionId, $sub['version']]);
$attemptFiles = [];
foreach ($oldFilesStmt->fetchAll() as $file) $attemptFiles[$file['version']][] = $file;
$subFiles = $attStmt->fetchAll();

$historyStmt = $pdo->prepare(
    'SELECT h.*, acc.username
     FROM SubmissionGradeHistory h
     LEFT JOIN Account acc ON acc.account_id = h.changed_by
     WHERE h.submission_id = :sid
     ORDER BY h.changed_at DESC, h.history_id DESC'
);
$historyStmt->execute([':sid' => $submissionId]);
$history = $historyStmt->fetchAll();

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

$isLate = !empty($sub['submitted_at']) && strtotime($sub['submitted_at']) > strtotime($sub['due_date']);

$pageTitle  = 'Chấm bài nộp';
$activePage = 'assignments';
require __DIR__ . '/_layout.php';
?>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div class="lec-flash lec-flash--<?= $ft === 'success' ? 'success' : 'error' ?>">
    <?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
    <a href="assignment_detail.php?asg=<?= (int)$sub['assignment_id'] ?>" style="font-size:13px;color:#3b82f6;text-decoration:none;font-weight:600;">
        ← <?= h(mb_strimwidth($sub['assignment_title'], 0, 50, '…')) ?>
    </a>
    <?php if ($sub['is_demo']): ?><span class="lpill lpill--orange" style="font-size:10px;">Dữ liệu minh họa</span><?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(300px,1fr);gap:16px;align-items:start;">
    <!-- Left: submission -->
    <div style="display:flex;flex-direction:column;gap:14px;min-width:0;">
        <div class="lcard" style="padding:20px 22px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <span class="lec-av" style="width:44px;height:44px;font-size:15px;"><?= h(initials($sub['full_name'])) ?></span>
                <div>
                    <div style="font-size:16px;font-weight:800;color:#001634;"><?= h($sub['full_name']) ?></div>
                    <div style="font-size:12px;color:#64748b;"><?= h($sub['student_id']) ?> • <?= h($sub['class_code']) ?></div>
                </div>
                <div style="margin-left:auto;text-align:right;">
                    <div style="font-size:11px;color:#94a3b8;">Phiên bản</div>
                    <div style="font-size:18px;font-weight:800;color:#001634;">v<?= (int)$sub['version'] ?></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;">
                <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
                    <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Học phần</div>
                    <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;"><?= h($sub['course_code'] . ' • ' . $sub['section_code']) ?></div>
                </div>
                <div style="background:<?= $isLate ? '#fff7ed' : '#f0fdf4' ?>;border-radius:8px;padding:10px 14px;">
                    <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Thời gian nộp</div>
                    <div style="font-size:13px;font-weight:700;color:<?= $isLate ? '#9a3412' : '#166534' ?>;margin-top:3px;">
                        <?= fmt_dt($sub['submitted_at']) ?><?= $isLate ? ' (muộn)' : '' ?>
                    </div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;">
                    <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Hạn nộp</div>
                    <div style="font-size:13px;font-weight:600;color:#001634;margin-top:3px;"><?= fmt_dt($sub['due_date']) ?></div>
                </div>
            </div>

            <?php if (!empty($sub['content'])): ?>
            <h3 style="font-size:14px;">Nội dung bài nộp</h3>
            <div style="white-space:pre-wrap;margin:12px 0;"><?= h($sub['content']) ?></div>
            <?php endif; ?>
            <div style="font-size:13px;font-weight:700;color:#102b50;margin-bottom:8px;">File bài nộp</div>
            <?php if (empty($subFiles) && empty($sub['file_path'])): ?>
            <div style="font-size:12.5px;color:#94a3b8;font-style:italic;">Bài nộp không có file đính kèm.</div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($subFiles as $f): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;flex-wrap:wrap;">
                    <div style="min-width:0;">
                        <div style="font-size:13px;font-weight:600;color:#001634;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">📄 <?= h($f['file_name']) ?></div>
                        <div style="font-size:11px;color:#94a3b8;"><?= h(fmt_size($f['file_size'])) ?></div>
                    </div>
                    <div style="display:flex;gap:6px;flex-shrink:0;">
                        <?php if (previewable_ext($f['file_name'])): ?>
                        <a href="../../download.php?type=submission_attachment&id=<?= (int)$f['sub_attachment_id'] ?>&preview=1"
                           target="_blank" rel="noopener" class="lbtn lbtn--outline lbtn--sm">🔍 Xem trước</a>
                        <?php endif; ?>
                        <a href="../../download.php?type=submission_attachment&id=<?= (int)$f['sub_attachment_id'] ?>" class="lbtn lbtn--outline lbtn--sm">⬇ Tải</a>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($subFiles) && $sub['file_path']): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;flex-wrap:wrap;">
                    <div style="font-size:13px;font-weight:600;color:#001634;">📄 <?= h($sub['file_name']) ?></div>
                    <div style="display:flex;gap:6px;">
                        <?php if (previewable_ext((string)$sub['file_name'])): ?>
                        <a href="../../download.php?type=submission&id=<?= (int)$sub['submission_id'] ?>&preview=1"
                           target="_blank" rel="noopener" class="lbtn lbtn--outline lbtn--sm">🔍 Xem trước</a>
                        <?php endif; ?>
                        <a href="../../download.php?type=submission&id=<?= (int)$sub['submission_id'] ?>" class="lbtn lbtn--outline lbtn--sm">⬇ Tải</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($attempts): ?>
        <div class="lcard" style="padding:20px 22px;">
            <h2 style="font-size:16px;">Lịch sử nộp bài</h2>
            <?php foreach ($attempts as $attempt): ?>
            <details style="margin-top:12px;">
                <summary>Phiên bản <?= (int)$attempt['version'] ?> – <?= fmt_dt($attempt['submitted_at']) ?></summary>
                <div style="white-space:pre-wrap;margin:10px 0;"><?= h($attempt['content'] ?? '') ?></div>
                <?php foreach ($attemptFiles[$attempt['version']] ?? [] as $file): ?>
                    <a href="../../download.php?type=submission_attachment&id=<?= (int)$file['sub_attachment_id'] ?>"><?= h($file['file_name']) ?></a>
                    <?php if (previewable_ext($file['file_name'])): ?>
                    <a href="../../download.php?type=submission_attachment&id=<?= (int)$file['sub_attachment_id'] ?>&preview=1" target="_blank" rel="noopener">Xem trước</a>
                    <?php endif; ?><br>
                <?php endforeach; ?>
                <?php if (empty($attemptFiles[$attempt['version']]) && $attempt['file_path']): ?>
                    <a href="../../download.php?type=submission_attempt&id=<?= (int)$attempt['attempt_id'] ?>"><?= h($attempt['file_name']) ?></a>
                <?php endif; ?>
                <div>Điểm trước khi nộp lại: <?= h($attempt['score'] ?? '—') ?></div>
                <div style="white-space:pre-wrap;"><?= h($attempt['feedback'] ?? '') ?></div>
            </details>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Grade history -->
        <div class="lcard" style="padding:20px 22px;">
            <div style="font-size:14px;font-weight:800;color:#001634;margin-bottom:10px;">🕘 Lịch sử chấm điểm</div>
            <?php if (empty($history)): ?>
            <div style="font-size:12.5px;color:#94a3b8;font-style:italic;">Chưa có thay đổi điểm nào.</div>
            <?php else: ?>
            <div class="lec-table-wrap">
                <table class="lec-table">
                    <thead>
                        <tr><th>Thời điểm</th><th>Người chấm / Nhận xét</th><th>Điểm cũ → mới</th><th>Trạng thái</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $hRow): ?>
                        <tr>
                            <td style="font-size:12px;color:#64748b;"><?= fmt_dt($hRow['changed_at']) ?></td>
                            <td style="font-size:12px;"><?= h($hRow['username'] ?? '—') ?><br>
                                <span style="white-space:pre-wrap;"><?= h($hRow['new_feedback'] ?? '') ?></span>
                                <?php if ($hRow['submission_version']): ?><br>Phiên bản <?= (int)$hRow['submission_version'] ?><?php endif; ?>
                            </td>
                            <td style="font-size:12px;">
                                <?= $hRow['old_score'] !== null ? number_format((float)$hRow['old_score'], 2) : '—' ?>
                                →
                                <strong><?= $hRow['new_score'] !== null ? number_format((float)$hRow['new_score'], 2) : '—' ?></strong>
                            </td>
                            <td style="font-size:11px;">
                                <?= $hRow['new_grade_status'] === 'Published' ? '🚀 Công bố' : '📝 Nháp' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: grade form -->
    <div class="lcard" style="padding:20px 22px;">
        <div style="font-size:14px;font-weight:800;color:#001634;margin-bottom:4px;">📝 Chấm điểm</div>
        <div style="font-size:12px;color:#94a3b8;margin-bottom:14px;">Thang điểm 0 – <?= number_format((float)$sub['max_score'], 2) ?></div>

        <form method="POST" onsubmit="return confirm('Lưu điểm cho bài nộp này?');">
            <?= csrf_input() ?>
            <input type="hidden" name="grade_submission" value="1" />
            <input type="hidden" name="version" value="<?= (int)$sub['version'] ?>" />
            <input type="hidden" name="revision" value="<?= (int)$sub['revision'] ?>" />

            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">Điểm</label>
            <input type="number" name="score" step="0.01" min="0" max="<?= (float)$sub['max_score'] ?>"
                   value="<?= $sub['score'] !== null ? h(number_format((float)$sub['score'], 2, '.', '')) : '' ?>"
                   placeholder="0 – <?= number_format((float)$sub['max_score'], 2) ?>"
                   style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px;font-size:14px;margin-bottom:12px;" />

            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">Nhận xét</label>
            <textarea name="feedback" rows="7" placeholder="Nhận xét chi tiết cho sinh viên…"
                      style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:13px;resize:vertical;margin-bottom:12px;font-family:inherit;"><?= h($sub['feedback'] ?? '') ?></textarea>

            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#334155;margin-bottom:14px;cursor:pointer;">
                <input type="checkbox" name="publish" value="1" <?= $sub['grade_status'] === 'Published' ? 'checked' : '' ?> />
                Công bố điểm cho sinh viên xem
            </label>

            <button type="submit" style="width:100%;height:40px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                💾 Lưu điểm
            </button>
            <div style="font-size:11px;color:#94a3b8;margin-top:8px;">
                Bỏ chọn "Công bố" để lưu bản nháp. Mọi thay đổi đều được ghi vào lịch sử chấm điểm.
            </div>
        </form>

        <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9;font-size:12px;color:#64748b;">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                <span>Trạng thái hiện tại</span>
                <strong style="color:<?= $sub['grade_status'] === 'Published' ? '#166534' : '#92400e' ?>;">
                    <?= $sub['grade_status'] === 'Published' ? 'Đã công bố' : 'Bản nháp' ?>
                </strong>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span>Điểm đã lưu</span>
                <strong><?= $sub['score'] !== null ? number_format((float)$sub['score'], 2) : '—' ?></strong>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
