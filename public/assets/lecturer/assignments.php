<?php
/**
 * UniPortal – Lecturer Assignment Management
 * Tabs: (1) Manage Assignments (create/edit/delete)
 *       (2) Grade Submissions (view uploads, enter score/feedback, publish)
 *       (3) Assessment Grading (existing Assessment-based grading)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/UploadService.php';
require_once $root . '/src/src/services/AssignmentService.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo       = get_db();
$lecId     = (int)($_SESSION['lecturer_id'] ?? 0);
$accountId = (int)($_SESSION['account_id']  ?? 0);

$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);
$sections   = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount  = LecturerModel::getUnreadWarningCount($lecId);

$flash = '';

// Keep form validation identical for creation and editing, including stale forms.
function assignmentInput(string $key, string $default = ''): string {
    if (!array_key_exists($key, $_POST)) return $default;
    if (!is_string($_POST[$key])) throw new InvalidArgumentException('Dữ liệu biểu mẫu không hợp lệ.');
    return trim($_POST[$key]);
}

function assignmentDate(string $value, string $label, bool $optional = false): ?string {
    if ($value === '' && $optional) return null;
    if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?\z/', $value, $parts)
        || (int)$parts[1] < 1000
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
        || (int)$parts[4] > 23 || (int)$parts[5] > 59 || (int)($parts[6] ?? 0) > 59) {
        throw new InvalidArgumentException($label . ' không hợp lệ.');
    }
    return $parts[1] . '-' . $parts[2] . '-' . $parts[3] . ' ' . $parts[4] . ':' . $parts[5] . ':' . ($parts[6] ?? '00');
}

function assignmentMaxScore(string $value): string {
    if (!preg_match('/\A(?:\d+(?:\.\d{1,2})?|\.\d{1,2})\z/', $value)
        || !is_numeric($value) || !is_finite((float)$value)
        || (float)$value < 0.01 || (float)$value > 999.99) {
        throw new InvalidArgumentException('Điểm tối đa phải từ 0.01 đến 999.99, với tối đa 2 chữ số thập phân.');
    }
    return number_format((float)$value, 2, '.', '');
}

// ── Helper: verify lecturer owns the section ──────────────────
function lecOwnsSection(int $lecId, int $sectionId, array $sections): bool {
    foreach ($sections as $s) {
        if ((int)$s['section_id'] === $sectionId) return true;
    }
    return false;
}

// ── POST: create assignment ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if (in_array($action, ['create_assignment', 'edit_assignment'], true)) {
        $creating = $action === 'create_assignment';
        $savedAttachments = [];
        try {
            $pdo->beginTransaction();
            $current = [];
            if ($creating) {
                $sectionId = (int)assignmentInput('section_id');
                if (!lecOwnsSection($lecId, $sectionId, $sections)) {
                    throw new InvalidArgumentException('Không có quyền tạo bài tập cho lớp này.');
                }
                // Recheck ownership under a lock in case the section was reassigned.
                $ownerCheck = $pdo->prepare(
                    'SELECT section_id FROM ClassSection
                     WHERE section_id=:sid AND lecturer_id=:lid LIMIT 1 FOR UPDATE'
                );
                $ownerCheck->execute([':sid' => $sectionId, ':lid' => $lecId]);
                if (!$ownerCheck->fetch()) {
                    throw new InvalidArgumentException('Không có quyền tạo bài tập cho lớp này.');
                }
            } else {
                $asgId = (int)assignmentInput('assignment_id');
                $ownerCheck = $pdo->prepare(
                    'SELECT a.* FROM Assignment a
                     JOIN ClassSection cs ON cs.section_id=a.section_id
                     WHERE a.assignment_id=:aid AND cs.lecturer_id=:lid AND a.is_deleted=0 LIMIT 1 FOR UPDATE'
                );
                $ownerCheck->execute([':aid' => $asgId, ':lid' => $lecId]);
                $current = $ownerCheck->fetch();
                if (!$current) {
                    throw new InvalidArgumentException('Không có quyền chỉnh sửa bài tập này.');
                }
                $sectionId = (int)$current['section_id'];
            }

            $title = assignmentInput('title');
            if ($title === '' || mb_strlen($title, 'UTF-8') > 200) {
                throw new InvalidArgumentException('Tiêu đề bài tập phải từ 1 đến 200 ký tự.');
            }
            $desc = assignmentInput('description', (string)($current['description'] ?? ''));
            $instruct = assignmentInput('instructions', (string)($current['instructions'] ?? ''));
            $pubDate = assignmentDate(assignmentInput('publication_date', (string)($current['publication_date'] ?? '')), 'Ngày công bố', true);
            $due = assignmentDate(assignmentInput('due_date'), 'Hạn nộp');
            $maxScore = assignmentMaxScore(assignmentInput('max_score'));
            if ($pubDate !== null && $pubDate > $due) {
                throw new InvalidArgumentException('Ngày công bố không được sau hạn nộp.');
            }
            $allowResubmission = assignmentInput('allow_resubmission', (string)($current['allow_resubmission'] ?? 1));
            if (!in_array($allowResubmission, ['0', '1'], true)) {
                throw new InvalidArgumentException('Chính sách nộp lại không hợp lệ.');
            }
            $status = $creating ? assignmentInput('status', 'Published') : $current['status'];
            if ($creating && !in_array($status, ['Draft', 'Published'], true)) {
                throw new InvalidArgumentException('Bài tập mới phải ở trạng thái Nháp hoặc Công bố.');
            }
            if (!$creating) {
                // Lock existing scores as well as the assignment while changing its scale.
                $scores = $pdo->prepare('SELECT score FROM Submission WHERE assignment_id=:aid AND score IS NOT NULL FOR UPDATE');
                $scores->execute([':aid' => $asgId]);
                foreach ($scores->fetchAll(PDO::FETCH_COLUMN) as $score) {
                    if ((float)$score > (float)$maxScore) {
                        throw new InvalidArgumentException('Điểm tối đa không được thấp hơn điểm bài nộp đã chấm (' . $score . ').');
                    }
                }
            }

            try {
                $savedAttachments = UploadService::store($_FILES['attachments'] ?? [], 'assignments/' . $sectionId, false);
            } catch (RuntimeException $uploadError) {
                throw new InvalidArgumentException($uploadError->getMessage(), 0, $uploadError);
            }
            $params = [
                ':title' => $title, ':desc' => $desc !== '' ? $desc : null,
                ':inst' => $instruct !== '' ? $instruct : null, ':pub' => $pubDate,
                ':due' => $due, ':ms' => $maxScore, ':resub' => (int)$allowResubmission,
            ];
            if ($creating) {
                $pdo->prepare(
                    'INSERT INTO Assignment
                        (section_id, title, description, instructions, publication_date,
                         due_date, max_score, allow_resubmission, status, is_deleted, created_by)
                     VALUES (:sec, :title, :desc, :inst, :pub, :due, :ms, :resub, :st, 0, :by)'
                )->execute($params + [':sec' => $sectionId, ':st' => $status, ':by' => $accountId]);
                $asgId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare(
                    'UPDATE Assignment SET title=:title, description=:desc, instructions=:inst,
                            publication_date=:pub, due_date=:due, max_score=:ms,
                            allow_resubmission=:resub, updated_at=NOW()
                     WHERE assignment_id=:aid'
                )->execute($params + [':aid' => $asgId]);
            }
            foreach ($savedAttachments as $attachment) {
                $pdo->prepare(
                    'INSERT INTO AssignmentAttachment (assignment_id, file_path, file_name, file_size, file_mime)
                     VALUES (:aid, :fp, :fn, :fs, :fm)'
                )->execute([
                    ':aid' => $asgId, ':fp' => $attachment['path'], ':fn' => $attachment['name'],
                    ':fs' => $attachment['size'], ':fm' => $attachment['mime'],
                ]);
            }

            // Notifications share the transaction, so a rollback cannot announce a failed create.
            if ($creating && $status === 'Published' && ($pubDate === null || $pubDate <= date('Y-m-d H:i:s'))) {
                NotificationService::notifyAssignmentPublished($pdo, $asgId, $sectionId, $title, $due);
            }
            AcademicAlertService::writeAuditLog(
                $pdo, $accountId, $creating ? 'CREATE_ASSIGNMENT' : 'EDIT_ASSIGNMENT', 'Assignment', (string)$asgId,
                null, json_encode(['title' => $title, 'section_id' => $sectionId, 'status' => $status, 'allow_resubmission' => (int)$allowResubmission])
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            UploadService::cleanup($savedAttachments);
            error_log('Assignment save failed: ' . $e->getMessage());
            $flash = 'error:' . ($e instanceof InvalidArgumentException
                ? $e->getMessage() : 'Không thể lưu bài tập hoặc tài liệu đính kèm. Vui lòng kiểm tra file và thử lại.');
        }
        if ($flash === '') {
            $flash = $creating ? 'success:Đã tạo bài tập "' . $title . '".' : 'success:Đã cập nhật bài tập.';
            if ($creating) {
                $_SESSION['_flash'] = $flash;
                header('Location: assignment_detail.php?id=' . $asgId);
                exit;
            }
        }
    }

    if ($action === 'delete_assignment') {
        $asgId = (int)($_POST['assignment_id'] ?? 0);
        $ownerCheck = $pdo->prepare(
            'SELECT a.assignment_id FROM Assignment a
             JOIN ClassSection cs ON cs.section_id=a.section_id
             WHERE a.assignment_id=:aid AND cs.lecturer_id=:lid AND a.is_deleted=0 LIMIT 1'
        );
        $ownerCheck->execute([':aid' => $asgId, ':lid' => $lecId]);
        if (!$ownerCheck->fetch()) {
            $flash = 'error:Không có quyền xóa bài tập này.';
        } else {
            // Check for graded submissions
            $graded = (int)$pdo->prepare('SELECT COUNT(*) FROM Submission WHERE assignment_id=:aid AND score IS NOT NULL')
                ->execute([':aid' => $asgId]) ? (function() use ($pdo,$asgId) {
                    $s=$pdo->prepare('SELECT COUNT(*) FROM Submission WHERE assignment_id=:aid AND score IS NOT NULL');
                    $s->execute([':aid'=>$asgId]); return (int)$s->fetchColumn();
                })() : 0;
            if ($graded > 0) {
                $flash = 'error:Không thể xóa – đã có ' . $graded . ' bài nộp đã chấm điểm.';
            } else {
                $pdo->prepare('UPDATE Assignment SET is_deleted=1, updated_at=NOW() WHERE assignment_id=:aid')
                    ->execute([':aid' => $asgId]);
                $flash = 'success:Đã xóa bài tập.';
            }
        }
    }

    if ($action === 'set_status') {
        $asgId     = (int)($_POST['assignment_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if (!in_array($newStatus, ['Draft','Published','Closed'], true)) {
            $flash = 'error:Trạng thái không hợp lệ.';
        } else {
            $own = $pdo->prepare(
                'SELECT a.assignment_id, a.section_id, a.status, a.title, a.due_date, a.publication_date,
                        (SELECT COUNT(*) FROM Submission sub WHERE sub.assignment_id=a.assignment_id AND sub.submitted_at IS NOT NULL) AS sub_count
                 FROM Assignment a
                 JOIN ClassSection cs ON cs.section_id=a.section_id
                 WHERE a.assignment_id=:aid AND cs.lecturer_id=:lid AND a.is_deleted=0 LIMIT 1'
            );
            $own->execute([':aid' => $asgId, ':lid' => $lecId]);
            $asgRow = $own->fetch();
            if (!$asgRow) {
                $flash = 'error:Không có quyền thay đổi bài tập này.';
            } elseif ($newStatus === 'Draft' && (int)$asgRow['sub_count'] > 0) {
                $flash = 'error:Không thể chuyển về nháp vì đã có bài nộp.';
            } else {
                // Status change and announcements must succeed or fail together.
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        'UPDATE Assignment SET status=:st,
                            publication_date = IF(:st2=\'Published\' AND publication_date IS NULL, NOW(), publication_date),
                            updated_at=NOW()
                         WHERE assignment_id=:aid'
                    )->execute([':st' => $newStatus, ':st2' => $newStatus, ':aid' => $asgId]);
                    // Skip future-dated publications: students cannot open the assignment yet.
                    if ($newStatus === 'Published'
                        && $asgRow['status'] !== 'Published'
                        && ($asgRow['publication_date'] === null || $asgRow['publication_date'] <= date('Y-m-d H:i:s'))) {
                        NotificationService::notifyAssignmentPublished(
                            $pdo, $asgId, (int)$asgRow['section_id'], (string)$asgRow['title'], (string)$asgRow['due_date']
                        );
                    }
                    AcademicAlertService::writeAuditLog(
                        $pdo, $accountId, 'ASSIGNMENT_STATUS', 'Assignment', (string)$asgId,
                        json_encode(['status' => $asgRow['status']]), json_encode(['status' => $newStatus])
                    );
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Assignment status change failed: ' . $e->getMessage());
                    $flash = 'error:Không thể cập nhật trạng thái bài tập. Vui lòng thử lại.';
                }
                if ($flash === '') {
                    $labels = ['Draft'=>'nháp','Published'=>'công bố','Closed'=>'đóng'];
                    $flash = 'success:Đã chuyển bài tập "'.$asgRow['title'].'" sang trạng thái '.$labels[$newStatus].'.';
                }
            }
        }
    }

    if ($action === 'grade_submission') {
        // Old bookmarked forms must not bypass the shared, version-aware grading path.
        try {
            $submissionId = filter_var(assignmentInput('submission_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $version = filter_var(assignmentInput('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $revision = filter_var(assignmentInput('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($submissionId === false || $version === false || $revision === false) {
                throw new InvalidArgumentException('Biểu mẫu chấm điểm đã cũ. Vui lòng mở lại trang chi tiết bài nộp.');
            }
            $result = AssignmentService::grade(
                $submissionId, $lecId, $accountId, assignmentInput('score'), assignmentInput('feedback'),
                isset($_POST['publish']), $version, $revision
            );
            $flash = ($result['ok'] ? 'success:' : 'error:') . $result['message'];
        } catch (Throwable $e) {
            $flash = 'error:' . ($e instanceof InvalidArgumentException
                ? $e->getMessage() : 'Không thể lưu điểm. Vui lòng mở lại trang chi tiết bài nộp.');
        }
    }

    // Redirect to avoid re-POST on refresh
    if ($flash) {
        $_SESSION['_flash'] = $flash;
        $redirect = 'assignments.php?tab=' . ($_GET['tab'] ?? 'manage');
        if (isset($_GET['section'])) $redirect .= '&section=' . (int)$_GET['section'];
        if (isset($_GET['asg']))     $redirect .= '&asg='     . (int)$_GET['asg'];
        header('Location: ' . $redirect);
        exit;
    }
}

// Pick up flash from session redirect
if (isset($_SESSION['_flash'])) {
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
}

// ── Tab & selection ───────────────────────────────────────────
$tab        = $_GET['tab']     ?? 'manage';
$selSection = (int)($_GET['section'] ?? ($sections[0]['section_id'] ?? 0));
if (!lecOwnsSection($lecId, $selSection, $sections)) {
    $selSection = (int)($sections[0]['section_id'] ?? 0);
}
$selAsg     = (int)($_GET['asg']     ?? 0);
$stF        = in_array($_GET['st'] ?? '', ['Draft','Published','Closed'], true) ? $_GET['st'] : '';
$sf         = in_array($_GET['sf'] ?? '', ['submitted','missing','late','graded','ungraded'], true) ? $_GET['sf'] : '';

$selSectionInfo = null;
foreach ($sections as $s) {
    if ((int)$s['section_id'] === $selSection) { $selSectionInfo = $s; break; }
}

// ── Load assignments for selected section ─────────────────────
$assignments = [];
if ($selSection) {
    $asgSt = $pdo->prepare(
        'SELECT a.*,
                (SELECT COUNT(*) FROM Submission sub WHERE sub.assignment_id=a.assignment_id AND sub.submitted_at IS NOT NULL) AS sub_count,
                (SELECT COUNT(*) FROM Submission sub WHERE sub.assignment_id=a.assignment_id AND sub.submitted_at IS NOT NULL AND sub.score IS NOT NULL) AS graded_count,
                (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=a.section_id AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count
         FROM Assignment a
         WHERE a.section_id=:sid AND a.is_deleted=0'
        . ($stF !== '' ? ' AND a.status=:stf' : '') .
        ' ORDER BY a.due_date ASC'
    );
    $paramsAsg = [':sid' => $selSection];
    if ($stF !== '') $paramsAsg[':stf'] = $stF;
    $asgSt->execute($paramsAsg);
    $assignments = $asgSt->fetchAll();

    // Fetch attachments for each assignment
    $asgAttachments = [];
    if (!empty($assignments)) {
        $asgIds = array_unique(array_column($assignments, 'assignment_id'));
        if (!empty($asgIds)) {
            $placeholders = implode(',', array_fill(0, count($asgIds), '?'));
            $attStmt = $pdo->prepare(
                "SELECT assignment_id, attachment_id, file_name, file_size FROM AssignmentAttachment WHERE assignment_id IN ($placeholders)"
            );
            $attStmt->execute(array_values($asgIds));
            foreach ($attStmt->fetchAll() as $att) {
                $asgAttachments[$att['assignment_id']][] = $att;
            }
        }
    }
}

// ── Load submissions for selected assignment ──────────────────
$submissions  = [];
$submissionAttachments = [];
$gradeHistory = [];
$selAsgInfo   = null;
if ($selAsg && $tab === 'submissions') {
    // Verify ownership
    $asgOwn = $pdo->prepare(
        'SELECT a.* FROM Assignment a
         JOIN ClassSection cs ON cs.section_id=a.section_id
         WHERE a.assignment_id=:aid AND cs.lecturer_id=:lid AND a.is_deleted=0 LIMIT 1'
    );
    $asgOwn->execute([':aid' => $selAsg, ':lid' => $lecId]);
    $selAsgInfo = $asgOwn->fetch();

    if ($selAsgInfo) {
        $subSt = $pdo->prepare(
            'SELECT sub.*, s.student_id, s.full_name, s.class_code,
                    e.enrollment_id AS enr_id
             FROM Enrollment e
             JOIN Student s ON s.student_id=e.student_id
             LEFT JOIN Submission sub ON sub.assignment_id=:aid AND sub.enrollment_id=e.enrollment_id
             WHERE e.section_id=:sid AND e.status IN (\'Enrolled\',\'Completed\')
             ORDER BY s.student_id ASC'
        );
        $subSt->execute([':aid' => $selAsg, ':sid' => $selAsgInfo['section_id']]);
        $submissions = $subSt->fetchAll();
        $subAtt = $pdo->prepare(
            'SELECT sa.submission_id, sa.sub_attachment_id, sa.file_name, sa.file_size
             FROM SubmissionAttachment sa
             JOIN Submission sub ON sub.submission_id=sa.submission_id AND sa.version=sub.version
             WHERE sub.assignment_id=:aid ORDER BY sa.sub_attachment_id'
        );
        $subAtt->execute([':aid' => $selAsg]);
        foreach ($subAtt->fetchAll() as $attachment) {
            $submissionAttachments[(int)$attachment['submission_id']][] = $attachment;
        }

        $subCounters = ['submitted'=>0,'missing'=>0,'late'=>0,'graded'=>0,'ungraded'=>0];
        foreach ($submissions as $sRow) {
            $hasF = !empty($sRow['submission_id']) && !empty($sRow['submitted_at']);
            $late = $hasF && strtotime($sRow['submitted_at']) > strtotime($selAsgInfo['due_date']);
            if ($hasF) $subCounters['submitted']++; else $subCounters['missing']++;
            if ($late) $subCounters['late']++;
            if ($hasF && $sRow['score'] !== null) $subCounters['graded']++;
            if ($hasF && $sRow['score'] === null) $subCounters['ungraded']++;
        }
        if ($sf !== '') {
            $submissions = array_values(array_filter($submissions, function ($sRow) use ($sf, $selAsgInfo) {
                $hasF = !empty($sRow['submission_id']) && !empty($sRow['submitted_at']);
                $late = $hasF && strtotime($sRow['submitted_at']) > strtotime($selAsgInfo['due_date']);
                return match ($sf) {
                    'submitted' => $hasF,
                    'missing'   => !$hasF,
                    'late'      => $late,
                    'graded'    => $hasF && $sRow['score'] !== null,
                    'ungraded'  => $hasF && $sRow['score'] === null,
                    default     => true,
                };
            }));
        }

        // Grade history for every submission of this assignment
        $hSt = $pdo->prepare(
            'SELECT h.*, acc.username
             FROM SubmissionGradeHistory h
             JOIN Submission sub ON sub.submission_id=h.submission_id
             LEFT JOIN Account acc ON acc.account_id=h.changed_by
             WHERE sub.assignment_id=:aid
             ORDER BY h.changed_at DESC, h.history_id DESC'
        );
        $hSt->execute([':aid' => $selAsg]);
        foreach ($hSt->fetchAll() as $hRow) {
            $gradeHistory[(int)$hRow['submission_id']][] = $hRow;
        }
    }
}

// ── Assessment grading (existing feature, wired to AcademicAlertService) ────
// Handled in separate grade-save POST below

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    $assessmentId = (int)($_POST['assessment_id'] ?? 0);
    $scores       = $_POST['scores'] ?? [];
    $maxScore     = (float)($_POST['max_score'] ?? 100);
    $saved = 0;
    foreach ($scores as $enrollmentId => $score) {
        $score = trim((string)$score);
        if ($score === '' || $score === '—') continue;
        $scoreF = (float)$score;
        if ($scoreF < 0 || $scoreF > $maxScore) continue;
        $ok = LecturerModel::saveGrade((int)$enrollmentId, $assessmentId, $scoreF, $lecId, (string)($_POST['feedback'][$enrollmentId] ?? ''));
        if ($ok) {
            $saved++;
            AcademicAlertService::afterGradeSave((int)$enrollmentId);
        }
    }
    $flash = $saved > 0
        ? 'success:Đã lưu ' . $saved . ' điểm và cập nhật cảnh báo học vụ.'
        : 'error:Không có điểm nào được lưu. Kiểm tra phạm vi điểm.';
    // Store in session for redirect
    $_SESSION['_flash_assess'] = $flash;
}

// ── Assessment grading data (tab=assess) ─────────────────────
$selAssessment    = (int)($_GET['assessment'] ?? 0);
$assessments      = $selSection ? LecturerModel::getAssessments($selSection) : [];
if (!$selAssessment && !empty($assessments)) $selAssessment = (int)$assessments[0]['assessment_id'];
$selAssessmentInfo = null;
$gradeRows        = [];
if ($selAssessment && $selSection) {
    foreach ($assessments as $a) {
        if ((int)$a['assessment_id'] === $selAssessment) { $selAssessmentInfo = $a; break; }
    }
    $gradeRows = LecturerModel::getGradesForAssessment($selAssessment, $selSection);
}
$pendingStats = LecturerModel::getPendingGradingStats($lecId);

if (isset($_SESSION['_flash_assess'])) {
    $flash = $_SESSION['_flash_assess'];
    unset($_SESSION['_flash_assess']);
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
function fmt_size(?int $b): string {
    if (!$b) return '—';
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b/1024, 1) . ' KB';
    return round($b/1048576, 1) . ' MB';
}
function initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1)); return substr($r,-2);
}

$pageTitle  = 'Bài tập & Chấm bài';
$activePage = 'assignments';
require __DIR__ . '/_layout.php';
?>

<!-- ══ FLASH BANNER ════════════════════════════════════════════ -->
<?php if ($flash): [$ft,$fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- ══ ACTIONS ══════════════════════════════════════════════════ -->
<div style="display:flex;justify-content:flex-end;flex-wrap:wrap;gap:12px;">
    <?php if ($tab === 'manage'): ?>
    <button onclick="document.getElementById('modalCreate').style.display='flex'"
            class="lbtn lbtn--primary">+ Tạo Bài Tập Mới</button>
    <?php endif; ?>
</div>

<!-- ══ TABS ════════════════════════════════════════════════════ -->
<div style="display:flex;gap:4px;background:#fff;border-radius:12px;padding:6px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <?php
    $tabs = [
        ['manage',      '📋 Quản lý bài tập'],
        ['submissions', '📤 Bài nộp & Chấm điểm'],
        ['assess',      '📊 Chấm điểm đánh giá (Assessment)'],
    ];
    foreach ($tabs as [$tid, $tlabel]):
        $params = 'tab=' . $tid . ($selSection ? '&section=' . $selSection : '');
        if ($tid === 'submissions' && $selAsg) $params .= '&asg=' . $selAsg;
    ?>
    <a href="assignments.php?<?= $params ?>"
       style="padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;
              background:<?= $tab===$tid?'#0d1b2e':'transparent' ?>;
              color:<?= $tab===$tid?'#fff':'#64748b' ?>;">
        <?= $tlabel ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ══ SECTION SELECTOR ════════════════════════════════════════ -->
<?php if (!empty($sections)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Lớp:</span>
    <?php foreach ($sections as $sec): ?>
    <a href="assignments.php?tab=<?= h($tab) ?>&section=<?= (int)$sec['section_id'] ?>"
       class="lpill <?= (int)$sec['section_id']===$selSection?'lpill--blue':'lpill--gray' ?>">
        <?= h($sec['section_code']) ?> (<?= (int)$sec['enrolled_count'] ?> SV)
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'manage' && !empty($sections)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Trạng thái:</span>
    <?php foreach ([''=>'Tất cả','Draft'=>'📝 Nháp','Published'=>'✅ Đã công bố','Closed'=>'🔒 Đã đóng'] as $stVal => $stLabel): ?>
    <a href="assignments.php?tab=manage&section=<?= (int)$selSection ?><?= $stVal ? '&st='.$stVal : '' ?>"
       class="lpill <?= $stF===$stVal ? 'lpill--blue' : 'lpill--gray' ?>"><?= $stLabel ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
// TAB 1: MANAGE ASSIGNMENTS
// ════════════════════════════════════════════════════════════
if ($tab === 'manage'):
?>

<?php if (empty($assignments)): ?>
<div class="lcard" style="padding:40px;text-align:center;color:#94a3b8;">
    <div style="font-size:32px;margin-bottom:8px;">📋</div>
    <p><?= $stF ? 'Không có bài tập ở trạng thái này.' : 'Chưa có bài tập nào cho lớp này. Nhấn "Tạo Bài Tập Mới" để bắt đầu.' ?></p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($assignments as $asg):
        $isOverdue = strtotime($asg['due_date']) < time();
        $pct = $asg['enrolled_count'] > 0 ? round($asg['sub_count'] / $asg['enrolled_count'] * 100) : 0;
        $isDone = $asg['graded_count'] >= $asg['enrolled_count'] && $asg['enrolled_count'] > 0;
        $asgStatus = $asg['status'] ?? 'Published';
        [$stLbl,$stCls] = match($asgStatus) {
            'Draft' => ['📝 Nháp','lpill--gray'],
            'Closed'=> ['🔒 Đã đóng','lpill--red'],
            default => ['✅ Đã công bố','lpill--green'],
        };
    ?>
    <div class="lcard" style="padding:18px 22px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;">
                <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:4px;">
                    <a href="assignment_detail.php?asg=<?= (int)$asg['assignment_id'] ?>"
                       style="color:inherit;text-decoration:none;"><?= h($asg['title']) ?></a>
                    <span class="lpill <?= $stCls ?>" style="font-size:10px;margin-left:6px;"><?= $stLbl ?></span>
                </div>
                <?php if ($asg['description']): ?>
                <div style="font-size:13px;color:#44474e;margin-bottom:8px;line-height:1.5;"><?= nl2br(h(mb_strimwidth($asg['description'], 0, 200, '…'))) ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:16px;font-size:12px;color:#64748b;flex-wrap:wrap;">
                    <span>📅 Hạn: <strong style="color:<?= $isOverdue?'#ef4444':'#334155' ?>"><?= fmt_dt($asg['due_date']) ?></strong></span>
                    <span>📊 Max: <?= number_format((float)$asg['max_score'], 2) ?> điểm</span>
                    <?php if ($asg['attachment_name']): ?>
                    <span>📎 <?= h($asg['attachment_name']) ?> (<?= fmt_size((int)$asg['attachment_size']) ?>)</span>
                    <?php endif; ?>
                    <?php
                    $atts = $asgAttachments[$asg['assignment_id']] ?? [];
                    foreach ($atts as $att):
                    ?>
                    <span>📎 <a href="../../download.php?type=assignment_attachment&id=<?= (int)$att['attachment_id'] ?>" download style="color:#3b82f6;">
                        <?= h($att['file_name']) ?> (<?= fmt_size((int)$att['file_size']) ?>)
                    </a></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                <div style="text-align:center;">
                    <div style="font-size:20px;font-weight:800;color:<?= $isDone?'#059669':'#3b82f6' ?>;"><?= (int)$asg['sub_count'] ?>/<?= (int)$asg['enrolled_count'] ?></div>
                    <div style="font-size:10px;color:#94a3b8;">Đã nộp</div>
                </div>
                <div style="height:4px;width:80px;background:#e5eeff;border-radius:9999px;overflow:hidden;">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $isDone?'#059669':'#3b82f6' ?>;border-radius:9999px;"></div>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
            <a href="assignment_detail.php?asg=<?= (int)$asg['assignment_id'] ?>"
               class="lbtn lbtn--outline lbtn--sm">🔎 Chi tiết &amp; thống kê</a>
            <a href="assignments.php?tab=submissions&section=<?= (int)$asg['section_id'] ?>&asg=<?= (int)$asg['assignment_id'] ?>"
               class="lbtn lbtn--primary lbtn--sm">📤 Xem bài nộp & chấm điểm</a>
            <button onclick="openEdit(<?= htmlspecialchars(json_encode($asg), ENT_QUOTES) ?>)"
                    class="lbtn lbtn--outline lbtn--sm">✏ Sửa</button>
            <?php if ($asgStatus === 'Draft'): ?>
            <form method="POST" action="assignments.php?tab=manage&section=<?= (int)$selSection ?>&st=<?= h($stF) ?>" style="display:inline;margin:0;">
                <?= csrf_input() ?>
                <input type="hidden" name="_action"      value="set_status" />
                <input type="hidden" name="assignment_id" value="<?= (int)$asg['assignment_id'] ?>" />
                <input type="hidden" name="new_status"    value="Published" />
                <button type="submit" class="lbtn lbtn--green lbtn--sm">🚀 Công bố cho sinh viên</button>
            </form>
            <?php elseif ($asgStatus === 'Published'): ?>
            <form method="POST" action="assignments.php?tab=manage&section=<?= (int)$selSection ?>&st=<?= h($stF) ?>" style="display:inline;margin:0;" onsubmit="return confirm('Đóng bài tập này? Sinh viên sẽ không thể nộp thêm.')">
                <?= csrf_input() ?>
                <input type="hidden" name="_action"      value="set_status" />
                <input type="hidden" name="assignment_id" value="<?= (int)$asg['assignment_id'] ?>" />
                <input type="hidden" name="new_status"    value="Closed" />
                <button type="submit" class="lbtn lbtn--outline lbtn--sm" style="color:#b91c1c;">🔒 Đóng bài tập</button>
            </form>
            <?php else: ?>
            <form method="POST" action="assignments.php?tab=manage&section=<?= (int)$selSection ?>&st=<?= h($stF) ?>" style="display:inline;margin:0;">
                <?= csrf_input() ?>
                <input type="hidden" name="_action"      value="set_status" />
                <input type="hidden" name="assignment_id" value="<?= (int)$asg['assignment_id'] ?>" />
                <input type="hidden" name="new_status"    value="Published" />
                <button type="submit" class="lbtn lbtn--outline lbtn--sm">🔓 Mở lại</button>
            </form>
            <?php endif; ?>
            <?php if ((int)$asg['graded_count'] === 0): ?>
            <form method="POST" style="display:inline;"
                  onsubmit="return confirm(<?= h(json_encode('Xóa bài tập "' . $asg['title'] . '"?', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)">
                <?= csrf_input() ?>
                <input type="hidden" name="_action"      value="delete_assignment" />
                <input type="hidden" name="assignment_id" value="<?= (int)$asg['assignment_id'] ?>" />
                <button type="submit" class="lbtn lbtn--outline lbtn--sm" style="color:#ef4444;">🗑 Xóa</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
// TAB 2: SUBMISSIONS & GRADING
// ════════════════════════════════════════════════════════════
elseif ($tab === 'submissions'):
?>

<!-- Assignment selector for this section -->
<?php if (!empty($assignments)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Bài tập:</span>
    <?php foreach ($assignments as $asg): ?>
    <a href="assignments.php?tab=submissions&section=<?= $selSection ?>&asg=<?= (int)$asg['assignment_id'] ?>"
       class="lpill <?= (int)$asg['assignment_id']===$selAsg?'lpill--blue':'lpill--gray' ?>"
       style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
        <?= h(mb_strimwidth($asg['title'], 0, 30, '…')) ?>
        <span style="margin-left:4px;opacity:.6;">(<?= (int)$asg['sub_count'] ?>/<?= (int)$asg['enrolled_count'] ?>)</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$selAsgInfo): ?>
<div class="lcard" style="padding:32px;text-align:center;color:#94a3b8;">
    <p>Chọn bài tập ở trên để xem danh sách bài nộp.</p>
</div>
<?php else: ?>

<!-- Submission status filter -->
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Bài nộp:</span>
    <?php
    $sfLinks = [
        ''          => 'Tất cả ('.count($submissions).($sf?'':'') .')',
        'submitted' => '✅ Đã nộp ('.$subCounters['submitted'].')',
        'missing'   => '⏳ Chưa nộp ('.$subCounters['missing'].')',
        'late'      => '🕐 Nộp muộn ('.$subCounters['late'].')',
        'graded'    => '📊 Đã chấm ('.$subCounters['graded'].')',
        'ungraded'  => '📝 Chưa chấm ('.$subCounters['ungraded'].')',
    ];
    // "Tất cả" count should be the full roster, not the filtered list
    $sfLinks[''] = 'Tất cả ('.($subCounters['submitted']+$subCounters['missing']).')';
    foreach ($sfLinks as $sfKey => $sfLabel):
    ?>
    <a href="assignments.php?tab=submissions&section=<?= $selSection ?>&asg=<?= (int)$selAsg ?><?= $sfKey ? '&sf='.$sfKey : '' ?>"
       class="lpill <?= $sf===$sfKey ? 'lpill--blue' : 'lpill--gray' ?>"><?= $sfLabel ?></a>
    <?php endforeach; ?>
</div>

<!-- Assignment info bar -->
<div class="lcard" style="padding:14px 20px;background:#eff4ff;border-left:4px solid #3b82f6;">
    <div style="font-size:15px;font-weight:700;color:#1e40af;">
        <a href="assignment_detail.php?asg=<?= (int)$selAsgInfo['assignment_id'] ?>" style="color:inherit;text-decoration:none;">
            <?= h($selAsgInfo['title']) ?> →
        </a>
    </div>
    <div style="font-size:12px;color:#3b82f6;margin-top:3px;">
        Hạn nộp: <?= fmt_dt($selAsgInfo['due_date']) ?> •
        Điểm tối đa: <?= number_format((float)$selAsgInfo['max_score'], 2) ?>
    </div>
</div>

<!-- Submissions table -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Sinh viên</th>
                    <th>Trạng thái nộp</th>
                    <th>Thời gian nộp</th>
                    <th>File đính kèm</th>
                    <th>Điểm (/ <?= number_format((float)$selAsgInfo['max_score'], 2) ?>)</th>
                    <th>Nhận xét</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">
                    Chưa có sinh viên nào trong lớp.
                </td></tr>
                <?php else: ?>
                <?php foreach ($submissions as $sub):
                    $hasFile = !empty($sub['submission_id']) && !empty($sub['submitted_at']);
                    $currentAttachments = $submissionAttachments[(int)($sub['submission_id'] ?? 0)] ?? [];
                    $isLate  = $hasFile && strtotime($sub['submitted_at']) > strtotime($selAsgInfo['due_date']);
                    $ini     = initials($sub['full_name']);
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span class="lec-av" style="width:32px;height:32px;"><?= h($ini) ?></span>
                            <div>
                                <div style="font-weight:700;font-size:13px;color:#001634;">
                                    <?php if ($hasFile): ?>
                                    <a href="submission_detail.php?sub=<?= (int)$sub['submission_id'] ?>" style="color:inherit;text-decoration:none;">
                                        <?= h($sub['full_name']) ?>
                                    </a>
                                    <?php else: ?>
                                    <?= h($sub['full_name']) ?>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:11px;color:#64748b;"><?= h($sub['student_id']) ?> – <?= h($sub['class_code']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($hasFile): ?>
                        <span class="lpill lpill--green" style="font-size:10px;">✅ Đã nộp<?= $isLate?' (trễ)':'' ?></span>
                        <?php else: ?>
                        <span class="lpill lpill--gray" style="font-size:10px;">Chưa nộp</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#64748b;"><?= $sub['submitted_at'] ? fmt_dt($sub['submitted_at']) : '—' ?></td>
                    <td style="font-size:12px;">
                        <?php if ($currentAttachments): ?>
                        <?php foreach ($currentAttachments as $attachment): ?>
                        <a href="../../download.php?type=submission_attachment&id=<?= (int)$attachment['sub_attachment_id'] ?>" download
                           style="color:#3b82f6;display:flex;align-items:center;gap:4px;">
                            <?= h(mb_strimwidth($attachment['file_name'], 0, 25, '…')) ?>
                            <span style="font-size:10px;color:#94a3b8;">(<?= fmt_size((int)$attachment['file_size']) ?>)</span>
                        </a>
                        <?php endforeach; ?>
                        <?php elseif (!empty($sub['file_path'])): ?>
                        <a href="../../download.php?type=submission&id=<?= (int)$sub['submission_id'] ?>" download
                           style="color:#3b82f6;display:flex;align-items:center;gap:4px;">
                            📎 <?= h(mb_strimwidth($sub['file_name'] ?? '', 0, 25, '…')) ?>
                            <span style="font-size:10px;color:#94a3b8;">(<?= fmt_size((int)($sub['file_size'] ?? 0)) ?>)</span>
                        </a>
                        <?php else: ?>
                        <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($sub['score'] !== null): ?>
                        <span style="font-size:18px;font-weight:800;color:<?= (float)$sub['score']/$selAsgInfo['max_score']>=0.5?'#059669':'#ef4444' ?>;">
                            <?= number_format((float)$sub['score'], 2) ?>
                        </span>
                        <?php if ($sub['grade_status'] === 'Published'): ?>
                        <span style="font-size:10px;color:#059669;display:block;">✅ Đã công bố</span>
                        <?php else: ?>
                        <span style="font-size:10px;color:#f59e0b;display:block;">📝 Nháp</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">Chưa chấm</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#44474e;max-width:150px;">
                        <?= $sub['feedback'] ? h(mb_strimwidth($sub['feedback'], 0, 50, '…')) : '—' ?>
                    </td>
                    <td>
                        <?php if ($hasFile): ?>
                        <a href="submission_detail.php?sub=<?= (int)$sub['submission_id'] ?>"
                           class="lbtn lbtn--yellow lbtn--sm">
                            <?= $sub['score'] !== null ? 'Sửa điểm' : 'Chấm bài' ?>
                        </a>
                        <?php
                        $histSubId = (int)($sub['submission_id'] ?? 0);
                        if ($histSubId && ($sub['score'] !== null || !empty($gradeHistory[$histSubId]))):
                        ?>
                        <button type="button"
                                onclick="openHistory(<?= $histSubId ?>, <?= h(json_encode($sub['full_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)"
                                class="lbtn lbtn--outline lbtn--sm" style="margin-top:4px;">🕘 Lịch sử</button>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="font-size:11px;color:#94a3b8;">Chờ nộp</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
window.GRADE_HISTORY = <?= json_encode($gradeHistory, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}' ?>;
</script>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
// TAB 3: ASSESSMENT-BASED GRADING
// ════════════════════════════════════════════════════════════
elseif ($tab === 'assess'):
?>

<!-- Assessment selector -->
<?php if (!empty($assessments)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Bài đánh giá:</span>
    <?php foreach ($assessments as $a): ?>
    <a href="assignments.php?tab=assess&section=<?= $selSection ?>&assessment=<?= (int)$a['assessment_id'] ?>"
       class="lpill <?= (int)$a['assessment_id']===$selAssessment?'lpill--blue':'lpill--gray' ?>">
        <?= h(mb_strimwidth($a['assessment_name'], 0, 30, '…')) ?>
        <span style="opacity:.6;margin-left:4px;"><?= number_format((float)$a['weight_percent'],0) ?>%</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($selAssessmentInfo && !empty($gradeRows)): ?>
<div class="lcard" style="padding:14px 20px;background:#eff4ff;border-left:4px solid #3b82f6;">
    <div style="font-size:14px;font-weight:700;color:#1e40af;"><?= h($selAssessmentInfo['assessment_name']) ?></div>
    <div style="font-size:12px;color:#3b82f6;margin-top:2px;">
        Trọng số: <?= number_format((float)$selAssessmentInfo['weight_percent'],0) ?>% •
        Điểm tối đa: <?= number_format((float)$selAssessmentInfo['max_score'],0) ?> •
        Hạn: <?= fmt_dt($selAssessmentInfo['due_date']) ?>
    </div>
</div>

<form method="POST">
                    <?= csrf_input() ?>
    <input type="hidden" name="save_grades"   value="1" />
    <input type="hidden" name="assessment_id" value="<?= (int)$selAssessment ?>" />
    <input type="hidden" name="max_score"     value="<?= h($selAssessmentInfo['max_score']) ?>" />
    <div class="lcard" style="padding:0;overflow:hidden;">
        <div class="lec-table-wrap">
            <table class="lec-table" id="gradeTable">
                <thead>
                    <tr>
                        <th>Sinh viên</th>
                        <th style="width:120px;text-align:center;">Điểm (0–100)</th>
                        <th>Nhận xét</th>
                        <th>Ngày chấm</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gradeRows as $i => $gr):
                        $hasGrade  = $gr['score'] !== null;
                        $scoreDisp = $hasGrade ? number_format((float)$gr['score'], 1) : '';
                        $ini       = initials($gr['full_name']);
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="lec-av" style="width:32px;height:32px;"><?= h($ini) ?></span>
                                <div>
                                    <div style="font-weight:700;font-size:13px;color:#001634;"><?= h($gr['full_name']) ?></div>
                                    <div style="font-size:11px;color:#64748b;"><?= h($gr['student_id']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <input type="number"
                                   class="lec-score-input"
                                   name="scores[<?= (int)$gr['enrollment_id'] ?>]"
                                   value="<?= h($scoreDisp) ?>"
                                   min="0" max="100"
                                   step="0.5"
                                   placeholder="—"
                                   style="width:80px;text-align:center;border:1px solid #e2e8f0;border-radius:8px;padding:6px;font-size:13px;font-weight:700;outline:none;" />
                        </td>
                        <td>
                            <input type="text"
                                   name="feedback[<?= (int)$gr['enrollment_id'] ?>]"
                                   value="<?= h($gr['feedback'] ?? '') ?>"
                                   placeholder="Nhận xét (tùy chọn)"
                                   style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;font-size:12px;outline:none;" />
                        </td>
                        <td style="font-size:11px;color:#94a3b8;">
                            <?= $gr['graded_at'] ? date('d/m H:i', strtotime($gr['graded_at'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f1f5f9;background:#f8fafc;flex-wrap:wrap;gap:10px;">
            <span style="font-size:12px;color:#64748b;">
                Điểm nhập theo thang 0–100. GPA và cảnh báo học vụ sẽ tự động cập nhật sau khi lưu.
            </span>
            <div style="display:flex;gap:8px;">
                <button type="reset"  class="lbtn lbtn--outline">Hủy thay đổi</button>
                <button type="submit" class="lbtn lbtn--primary">💾 Lưu điểm &amp; Cập nhật GPA</button>
            </div>
        </div>
    </div>
</form>
<?php elseif ($selSection): ?>
<div class="lcard" style="padding:32px;text-align:center;color:#94a3b8;">
    <p>Chọn bài đánh giá ở trên để nhập điểm.</p>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- ══ MODAL: CREATE ASSIGNMENT ════════════════════════════════ -->
<div id="modalCreate" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
    <button onclick="this.closest('#modalCreate').style.display='none'"
            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:20px;">+ Tạo Bài Tập Mới</h2>
    <form method="POST" enctype="multipart/form-data">
                    <?= csrf_input() ?>
        <input type="hidden" name="_action" value="create_assignment" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Lớp học phần *</label>
                <select name="section_id" required style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= (int)$sec['section_id'] ?>" <?= (int)$sec['section_id']===$selSection?'selected':'' ?>>
                        <?= h($sec['section_code']) ?> – <?= h($sec['course_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tiêu đề bài tập *</label>
                <input type="text" name="title" required maxlength="200"
                       placeholder="VD: Đồ án cuối kỳ – Thiết kế hệ thống"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px;font-size:13px;outline:none;" />
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mô tả / Đề bài</label>
                <textarea name="description" rows="4" placeholder="Mô tả yêu cầu bài tập..."
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:13px;outline:none;resize:vertical;"></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hướng dẫn chi tiết (Instructions)</label>
                <textarea name="instructions" rows="3" placeholder="Hướng dẫn định dạng, tiêu chí chấm điểm, cách nộp bài..."
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:13px;outline:none;resize:vertical;"></textarea>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày công bố (tùy chọn)</label>
                <input type="datetime-local" name="publication_date"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hạn nộp *</label>
                <input type="datetime-local" name="due_date" required
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Điểm tối đa *</label>
                <input type="number" name="max_score" required min="0.01" max="999.99" step="0.01" value="10"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái *</label>
                <select name="status" style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="Published" selected>Published – Công bố ngay</option>
                    <option value="Draft">Draft – Bản nháp (ẩn với SV)</option>
                </select>
            </div>
            <label style="grid-column:1/-1;display:flex;align-items:center;gap:8px;font-size:13px;color:#334155;cursor:pointer;">
                <input type="hidden" name="allow_resubmission" value="0" />
                <input type="checkbox" name="allow_resubmission" value="1" checked />
                <span>Cho phép sinh viên nộp lại khi bài tập còn mở</span>
            </label>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">File đề bài đính kèm (PDF, Word, ZIP… tối đa 20 MB/file, có thể chọn nhiều file)</label>
                <input type="file" name="attachments[]" multiple
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.jpg,.jpeg,.png,.txt"
                       style="font-size:13px;width:100%;" />
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="document.getElementById('modalCreate').style.display='none'"
                    class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">Tạo bài tập</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: EDIT ASSIGNMENT ══════════════════════════════════ -->
<div id="modalEdit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;padding:28px;position:relative;">
    <button onclick="this.closest('#modalEdit').style.display='none'"
            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:20px;">✏ Chỉnh sửa Bài tập</h2>
    <form method="POST" enctype="multipart/form-data">
                    <?= csrf_input() ?>
        <input type="hidden" name="_action"      value="edit_assignment" />
        <input type="hidden" name="assignment_id" id="editAsgId" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tiêu đề *</label>
                <input type="text" name="title" id="editTitle" required maxlength="200"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px;font-size:13px;outline:none;" />
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mô tả</label>
                <textarea name="description" id="editDesc" rows="3"
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:13px;outline:none;resize:vertical;"></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label for="editInstructions" style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hướng dẫn chi tiết (Instructions)</label>
                <textarea name="instructions" id="editInstructions" rows="3"
                          style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:13px;outline:none;resize:vertical;"></textarea>
            </div>
            <div>
                <label for="editPublication" style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày công bố (tùy chọn)</label>
                <input type="datetime-local" name="publication_date" id="editPublication" step="1"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hạn nộp *</label>
                <input type="datetime-local" name="due_date" id="editDue" required step="1"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Điểm tối đa *</label>
                <input type="number" name="max_score" id="editMax" required min="0.01" max="999.99" step="0.01"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
            </div>
            <label style="grid-column:1/-1;display:flex;align-items:center;gap:8px;font-size:13px;color:#334155;cursor:pointer;">
                <input type="hidden" name="allow_resubmission" value="0" />
                <input type="checkbox" name="allow_resubmission" id="editResubmission" value="1" />
                <span>Cho phép sinh viên nộp lại khi bài tập còn mở</span>
            </label>
            <div style="grid-column:1/-1;">
                <label for="editAttachments" style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Thêm tài liệu (tối đa 20 MB/file, có thể chọn nhiều file)</label>
                <input type="file" name="attachments[]" id="editAttachments" multiple
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.jpg,.jpeg,.png,.txt"
                       style="font-size:13px;width:100%;" />
                <div style="font-size:12px;color:#64748b;margin-top:6px;">Các tài liệu đã có được giữ nguyên.</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="document.getElementById('modalEdit').style.display='none'"
                    class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">Lưu thay đổi</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: GRADE HISTORY ════════════════════════════════════ -->
<div id="modalHistory" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:520px;max-height:88vh;overflow-y:auto;padding:28px;position:relative;">
    <button onclick="document.getElementById('modalHistory').style.display='none'"
            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:4px;">🕘 Lịch sử chấm điểm</h2>
    <p id="historyStudentName" style="font-size:13px;color:#64748b;margin-bottom:18px;"></p>
    <div id="historyBody"></div>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
// Create modal
document.querySelectorAll('[id^="modal"]').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('[id^="modal"]').forEach(m => m.style.display = 'none');
});

function openEdit(asg) {
    document.getElementById('editAsgId').value  = asg.assignment_id;
    document.getElementById('editTitle').value  = asg.title;
    document.getElementById('editDesc').value   = asg.description || '';
    document.getElementById('editInstructions').value = asg.instructions || '';
    document.getElementById('editPublication').value = (asg.publication_date || '').replace(' ', 'T');
    document.getElementById('editMax').value    = asg.max_score;
    document.getElementById('editResubmission').checked = Number(asg.allow_resubmission == null ? 1 : asg.allow_resubmission) === 1;
    document.getElementById('editAttachments').value = '';
    document.getElementById('editDue').value = (asg.due_date || '').replace(' ', 'T');
    document.getElementById('modalEdit').style.display = 'flex';
}

function escapeHistoryHtml(value) {
    return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function fmtHistoryTime(value) {
    if (!value) return '—';
    var m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : value;
}

function openHistory(subId, studentName) {
    var rows = (window.GRADE_HISTORY && window.GRADE_HISTORY[subId]) || [];
    document.getElementById('historyStudentName').textContent = studentName;
    var body = document.getElementById('historyBody');
    if (!rows.length) {
        body.innerHTML = '<p style="font-size:13px;color:#94a3b8;">Chưa có lịch sử chấm điểm cho bài nộp này.</p>';
    } else {
        body.innerHTML = rows.map(function(r) {
            var oldScore = (r.old_score === null || r.old_score === '') ? '—' : Number(r.old_score).toFixed(2);
            var newScore = (r.new_score === null || r.new_score === '') ? '—' : Number(r.new_score).toFixed(2);
            var st = r.new_grade_status === 'Published' ? '✅ Đã công bố' : '📝 Nháp';
            var by = r.username ? r.username : 'Giảng viên';
            var fb = r.new_feedback
                ? '<div style="font-size:12px;color:#334155;margin-top:6px;padding-top:6px;border-top:1px dashed #e2e8f0;">'
                    + escapeHistoryHtml(r.new_feedback) + '</div>'
                : '';
            return '<div style="padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;background:#f8fafc;">'
                + '<div style="font-size:13px;font-weight:700;color:#001634;">'
                + escapeHistoryHtml(oldScore) + ' → ' + escapeHistoryHtml(newScore)
                + ' <span style="font-weight:600;font-size:11px;color:#64748b;">• ' + escapeHistoryHtml(st) + '</span></div>'
                + '<div style="font-size:11px;color:#64748b;margin-top:3px;">'
                + escapeHistoryHtml(fmtHistoryTime(r.changed_at)) + ' • ' + escapeHistoryHtml(by) + '</div>'
                + fb
                + '</div>';
        }).join('');
    }
    document.getElementById('modalHistory').style.display = 'flex';
}

// Score input validation
document.querySelectorAll('.lec-score-input').forEach(function(inp) {
    inp.addEventListener('input', function() {
        var max = parseFloat(this.max) || 100;
        this.style.color = parseFloat(this.value) > max ? '#ef4444' : '#001634';
    });
});
</script>
