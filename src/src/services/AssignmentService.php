<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/UploadService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/AcademicAlertService.php';

class AssignmentService {
    public static function submit(int $assignmentId, int $enrollmentId, string $studentId, int $accountId,
        string $content, array $uploadedFiles, int $expectedVersion): array {
        $pdo = get_db();
        $saved = [];
        $ownTx = !$pdo->inTransaction();
        try {
            if ($ownTx) $pdo->beginTransaction();
            else $pdo->exec('SAVEPOINT assignment_submit');
            $q = $pdo->prepare(
                "SELECT a.* FROM Assignment a
                 JOIN Enrollment e ON e.section_id = a.section_id
                 WHERE a.assignment_id = ? AND e.enrollment_id = ? AND e.student_id = ?
                   AND e.status IN ('Enrolled','Completed') AND a.is_deleted = 0 FOR UPDATE"
            );
            $q->execute([$assignmentId, $enrollmentId, $studentId]);
            $assignment = $q->fetch();
            if (!$assignment) throw new RuntimeException('Bài tập không tồn tại hoặc không thuộc lớp học của bạn.');
            if ($assignment['status'] !== 'Published'
                || ($assignment['publication_date'] && strtotime($assignment['publication_date']) > time())) {
                throw new RuntimeException('Bài tập chưa được công bố hoặc đã đóng.');
            }
            $allowLate = (int)$pdo->query("SELECT config_value FROM SystemConfig WHERE config_key = 'late_submission_allowed'")->fetchColumn() === 1;
            if (strtotime($assignment['due_date']) < time() && !$allowLate) throw new RuntimeException('Đã quá hạn nộp bài.');
            $q = $pdo->prepare('SELECT * FROM Submission WHERE assignment_id = ? AND enrollment_id = ? FOR UPDATE');
            $q->execute([$assignmentId, $enrollmentId]);
            $old = $q->fetch();
            if ($expectedVersion !== (int)($old['version'] ?? 0)) {
                throw new RuntimeException('Bài nộp đã thay đổi. Hãy tải lại trang trước khi nộp.');
            }
            // A row without submitted_at is an unsent draft, not a real submission.
            $isDraft = $old && $old['submitted_at'] === null;
            if ($old && !$isDraft && (!$assignment['allow_resubmission'] || $old['grade_status'] === 'Published')) {
                throw new RuntimeException('Bài tập không cho nộp lại hoặc điểm đã được công bố.');
            }
            $content = trim($content);
            if (strlen($content) > 60000) throw new RuntimeException('Nội dung bài nộp quá dài (tối đa 60 KB).');
            $saved = UploadService::store($uploadedFiles, 'submissions/' . $assignmentId, $content === '');
            $primary = $saved[0] ?? ['path' => null, 'name' => null, 'size' => null, 'mime' => null];
            $version = $old ? (int)$old['version'] + 1 : 1;
            if ($old) {
                $submissionId = (int)$old['submission_id'];
                if ($isDraft) {
                    // Drafts were never submitted: promote the files instead of archiving an attempt.
                    if ($saved) {
                        $pdo->prepare('DELETE FROM SubmissionAttachment WHERE submission_id = ? AND version = 0')
                            ->execute([$submissionId]);
                    } else {
                        $pdo->prepare('UPDATE SubmissionAttachment SET version = ? WHERE submission_id = ? AND version = 0')
                            ->execute([$version, $submissionId]);
                        $primary = ['path' => $old['file_path'], 'name' => $old['file_name'],
                            'size' => $old['file_size'], 'mime' => $old['file_mime']];
                    }
                } else {
                    $pdo->prepare(
                        'INSERT INTO SubmissionAttempt
                            (submission_id, version, content, file_path, file_name, file_size, file_mime, submitted_at, score, feedback, grade_status)
                         SELECT submission_id, version, content, file_path, file_name, file_size, file_mime, submitted_at, score, feedback, grade_status
                         FROM Submission WHERE submission_id = ?'
                    )->execute([$submissionId]);
                    if ($old['score'] !== null || $old['feedback'] !== null) {
                        $pdo->prepare(
                            "INSERT INTO SubmissionGradeHistory
                                (submission_id, old_score, new_score, old_feedback, new_feedback, old_grade_status, new_grade_status, changed_by, submission_version)
                             VALUES (?, ?, NULL, ?, NULL, ?, 'Draft', ?, ?)"
                        )->execute([$submissionId, $old['score'], $old['feedback'], $old['grade_status'], $accountId, $old['version']]);
                    }
                }
                $pdo->prepare(
                    "UPDATE Submission SET file_path = ?, file_name = ?, file_size = ?, file_mime = ?, content = ?,
                        submitted_at = NOW(), version = ?, revision = revision + 1, score = NULL, feedback = NULL, grade_status = 'Draft'
                     WHERE submission_id = ?"
                )->execute([$primary['path'], $primary['name'], $primary['size'], $primary['mime'],
                    $content ?: null, $version, $submissionId]);
            } else {
                $pdo->prepare(
                    "INSERT INTO Submission
                        (assignment_id, enrollment_id, file_path, file_name, file_size, file_mime, content, submitted_at, grade_status, version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Draft', 1)"
                )->execute([$assignmentId, $enrollmentId, $primary['path'], $primary['name'], $primary['size'], $primary['mime'], $content ?: null]);
                $submissionId = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare(
                'INSERT INTO SubmissionAttachment (submission_id, version, file_path, file_name, file_size, file_mime) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($saved as $file) {
                $ins->execute([$submissionId, $version, $file['path'], $file['name'], $file['size'], $file['mime']]);
            }
            AcademicAlertService::writeAuditLog($pdo, $accountId, 'SUBMIT_ASSIGNMENT', 'Submission', (string)$submissionId,
                null, json_encode(['assignment_id' => $assignmentId, 'version' => $version]));
            if ($ownTx) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT assignment_submit');
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            elseif ($pdo->inTransaction()) $pdo->exec('ROLLBACK TO SAVEPOINT assignment_submit');
            UploadService::cleanup($saved);
            if ($e instanceof PDOException) error_log('Assignment submit: ' . $e->getMessage());
            return ['ok' => false, 'message' => $e instanceof PDOException ? 'Không thể lưu bài nộp. Vui lòng thử lại.' : $e->getMessage()];
        }
        NotificationService::send($accountId, 'Assignment', 'Nộp bài thành công',
            $assignment['title'] . ' – phiên bản ' . $version,
            app_url('assets/student/assignment_detail.php?id=') . $assignmentId);
        return ['ok' => true, 'message' => 'Nộp bài thành công! Phiên bản ' . $version . ' – ' . count($saved) . ' file.',
            'submission_id' => $submissionId, 'version' => $version];
    }

    /**
     * Saves an unsent draft (version 0, submitted_at NULL). Drafts never count
     * as submissions and can still be promoted by submit().
     */
    public static function saveDraft(int $assignmentId, int $enrollmentId, string $studentId,
        string $content, array $uploadedFiles): array {
        $pdo = get_db();
        $saved = [];
        $ownTx = !$pdo->inTransaction();
        try {
            if ($ownTx) $pdo->beginTransaction();
            else $pdo->exec('SAVEPOINT assignment_draft');
            $q = $pdo->prepare(
                "SELECT a.* FROM Assignment a
                 JOIN Enrollment e ON e.section_id = a.section_id
                 WHERE a.assignment_id = ? AND e.enrollment_id = ? AND e.student_id = ?
                   AND e.status IN ('Enrolled','Completed') AND a.is_deleted = 0 AND a.status = 'Published' FOR UPDATE"
            );
            $q->execute([$assignmentId, $enrollmentId, $studentId]);
            $assignment = $q->fetch();
            if (!$assignment) throw new RuntimeException('Bài tập không tồn tại hoặc không thuộc lớp học của bạn.');
            if ($assignment['publication_date'] && strtotime($assignment['publication_date']) > time()) {
                throw new RuntimeException('Bài tập chưa được công bố.');
            }
            $allowLate = (int)$pdo->query("SELECT config_value FROM SystemConfig WHERE config_key = 'late_submission_allowed'")->fetchColumn() === 1;
            if (strtotime($assignment['due_date']) < time() && !$allowLate) throw new RuntimeException('Đã quá hạn nộp bài.');
            $content = trim($content);
            if (strlen($content) > 60000) throw new RuntimeException('Nội dung bài nộp quá dài (tối đa 60 KB).');
            $q = $pdo->prepare('SELECT * FROM Submission WHERE assignment_id = ? AND enrollment_id = ? FOR UPDATE');
            $q->execute([$assignmentId, $enrollmentId]);
            $old = $q->fetch();
            if ($old && $old['submitted_at'] !== null) throw new RuntimeException('Bài đã nộp, không thể lưu nháp.');
            $saved = UploadService::store($uploadedFiles, 'submissions/' . $assignmentId, $content === '');
            $primary = $saved[0] ?? ['path' => null, 'name' => null, 'size' => null, 'mime' => null];
            if ($old) {
                $submissionId = (int)$old['submission_id'];
                if ($saved) {
                    $pdo->prepare('DELETE FROM SubmissionAttachment WHERE submission_id = ? AND version = 0')
                        ->execute([$submissionId]);
                } else {
                    $primary = ['path' => $old['file_path'], 'name' => $old['file_name'],
                        'size' => $old['file_size'], 'mime' => $old['file_mime']];
                }
                $pdo->prepare(
                    "UPDATE Submission SET file_path = ?, file_name = ?, file_size = ?, file_mime = ?, content = ?
                     WHERE submission_id = ? AND submitted_at IS NULL"
                )->execute([$primary['path'], $primary['name'], $primary['size'], $primary['mime'],
                    $content ?: null, $submissionId]);
            } else {
                $pdo->prepare(
                    "INSERT INTO Submission
                        (assignment_id, enrollment_id, file_path, file_name, file_size, file_mime, content, submitted_at, grade_status, version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'Draft', 0)"
                )->execute([$assignmentId, $enrollmentId, $primary['path'], $primary['name'],
                    $primary['size'], $primary['mime'], $content ?: null]);
                $submissionId = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare(
                'INSERT INTO SubmissionAttachment (submission_id, version, file_path, file_name, file_size, file_mime) VALUES (?, 0, ?, ?, ?, ?)'
            );
            foreach ($saved as $file) {
                $ins->execute([$submissionId, $file['path'], $file['name'], $file['size'], $file['mime']]);
            }
            AcademicAlertService::writeAuditLog($pdo, (int)($_SESSION['account_id'] ?? 0), 'SAVE_ASSIGNMENT_DRAFT',
                'Submission', (string)$submissionId, null, json_encode(['assignment_id' => $assignmentId, 'files' => count($saved)]));
            if ($ownTx) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT assignment_draft');
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            elseif ($pdo->inTransaction()) $pdo->exec('ROLLBACK TO SAVEPOINT assignment_draft');
            UploadService::cleanup($saved);
            if ($e instanceof PDOException) error_log('Assignment draft: ' . $e->getMessage());
            return ['ok' => false, 'message' => $e instanceof PDOException ? 'Không thể lưu nháp. Vui lòng thử lại.' : $e->getMessage()];
        }
        return ['ok' => true, 'message' => 'Đã lưu nháp. Bài chưa được nộp cho giảng viên.',
            'submission_id' => $submissionId];
    }

    public static function grade(int $submissionId, int $lecturerId, int $accountId, string $score,
        string $feedback, bool $publish, int $expectedVersion, int $expectedRevision): array {
        $pdo = get_db();
        $ownTx = !$pdo->inTransaction();
        try {
            if ($ownTx) $pdo->beginTransaction();
            else $pdo->exec('SAVEPOINT assignment_grade');
            $q = $pdo->prepare(
                'SELECT sub.*, a.title, a.max_score, e.student_id FROM Submission sub
                 JOIN Assignment a ON a.assignment_id = sub.assignment_id AND a.is_deleted = 0
                 JOIN ClassSection cs ON cs.section_id = a.section_id
                 JOIN Enrollment e ON e.enrollment_id = sub.enrollment_id AND e.section_id = a.section_id
                 WHERE sub.submission_id = ? AND cs.lecturer_id = ? FOR UPDATE'
            );
            $q->execute([$submissionId, $lecturerId]);
            $old = $q->fetch();
            if (!$old || !$old['submitted_at']) throw new RuntimeException('Không tìm thấy bài nộp hoặc không có quyền chấm.');
            if ((int)$old['version'] !== $expectedVersion || (int)$old['revision'] !== $expectedRevision) {
                throw new RuntimeException('Bài nộp hoặc điểm đã thay đổi. Hãy tải lại trang và kiểm tra phiên bản mới.');
            }
            $score = trim($score);
            $feedback = trim($feedback);
            if (($score !== '' && (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $score) || (float)$score > (float)$old['max_score']))
                || ($publish && $score === '')) {
                throw new RuntimeException('Nhập điểm từ 0 đến ' . $old['max_score'] . ' (tối đa 2 số lẻ); cần có điểm trước khi công bố.');
            }
            if (strlen($feedback) > 60000) throw new RuntimeException('Nhận xét quá dài (tối đa 60 KB).');
            $newScore = $score === '' ? null : (float)$score;
            $status = $publish ? 'Published' : 'Draft';
            $oldScore = $old['score'] === null ? null : (float)$old['score'];
            $changed = $oldScore !== $newScore || (string)$old['feedback'] !== $feedback || $old['grade_status'] !== $status;
            if ($changed) {
                $pdo->prepare('UPDATE Submission SET score = ?, feedback = ?, grade_status = ?, revision = revision + 1 WHERE submission_id = ?')
                    ->execute([$newScore, $feedback ?: null, $status, $submissionId]);
                $pdo->prepare(
                    'INSERT INTO SubmissionGradeHistory
                        (submission_id, old_score, new_score, old_feedback, new_feedback, old_grade_status, new_grade_status, changed_by, submission_version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$submissionId, $old['score'], $newScore, $old['feedback'], $feedback ?: null,
                    $old['grade_status'], $status, $accountId, $old['version']]);
                AcademicAlertService::writeAuditLog($pdo, $accountId, 'GRADE_SUBMISSION', 'Submission', (string)$submissionId,
                    json_encode(['score' => $oldScore, 'grade_status' => $old['grade_status']]),
                    json_encode(['score' => $newScore, 'grade_status' => $status, 'version' => $old['version']]));
            }
            if ($ownTx) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT assignment_grade');
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            elseif ($pdo->inTransaction()) $pdo->exec('ROLLBACK TO SAVEPOINT assignment_grade');
            if ($e instanceof PDOException) error_log('Assignment grade: ' . $e->getMessage());
            return ['ok' => false, 'message' => $e instanceof PDOException ? 'Không thể lưu điểm. Vui lòng thử lại.' : $e->getMessage()];
        }
        if ($changed && $publish) {
            try {
                NotificationService::notifyAssignmentGraded($pdo, $submissionId, ($old['grade_status'] ?? '') === 'Published');
            } catch (Throwable $e) {
                error_log('Assignment grade notification: ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'message' => 'Đã lưu điểm' . ($publish ? ' và công bố cho sinh viên.' : ' (bản nháp).')];
    }
}
