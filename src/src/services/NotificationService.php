<?php
/**
 * UniPortal – Notification Service
 * Creates, retrieves and marks notifications for all roles.
 */
require_once __DIR__ . '/../config/database.php';

class NotificationService {

    // Send a notification to one account
    public static function send(
        int    $account_id,
        string $type,
        string $title,
        string $body  = '',
        string $link  = ''
    ): bool {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'INSERT INTO Notification (account_id, type, title, body, link)
                 VALUES (:aid, :type, :title, :body, :link)'
            );
            return $stmt->execute([
                ':aid'   => $account_id,
                ':type'  => $type,
                ':title' => $title,
                ':body'  => $body,
                ':link'  => $link,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    // Send to all accounts with a given role
    public static function broadcast(
        string $role,
        string $type,
        string $title,
        string $body = '',
        string $link = ''
    ): int {
        try {
            $pdo   = get_db();
            $stmt  = $pdo->prepare('SELECT account_id FROM Account WHERE role=:r AND is_active=TRUE');
            $stmt->execute([':r' => $role]);
            $count = 0;
            foreach ($stmt->fetchAll() as $row) {
                if (self::send((int)$row['account_id'], $type, $title, $body, $link)) $count++;
            }
            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    // Unread count for an account
    public static function unreadCount(int $account_id): int {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM Notification WHERE account_id=:aid AND is_read=FALSE');
            $stmt->execute([':aid' => $account_id]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    // Latest N notifications for an account
    public static function getRecent(int $account_id, int $limit = 8): array {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'SELECT notification_id, type, title, body, link, is_read,
                        created_at
                 FROM Notification
                 WHERE account_id = :aid
                 ORDER BY created_at DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':aid', $account_id, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit,      PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    // Mark a single notification as read (must belong to account)
    public static function markRead(int $notification_id, int $account_id): bool {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'UPDATE Notification SET is_read=TRUE
                 WHERE notification_id=:nid AND account_id=:aid'
            );
            return $stmt->execute([':nid' => $notification_id, ':aid' => $account_id]);
        } catch (Throwable) {
            return false;
        }
    }

    // Fetch one notification that belongs to the account
    public static function getForAccount(int $notification_id, int $account_id): array|false {
        if ($notification_id <= 0 || $account_id <= 0) return false;
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'SELECT notification_id, type, title, body, link, is_read, created_at
                 FROM Notification
                 WHERE notification_id = :nid AND account_id = :aid
                 LIMIT 1'
            );
            $stmt->execute([':nid' => $notification_id, ':aid' => $account_id]);
            return $stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    // Insert a notification only when no notification of this type/link exists yet.
    private static function sendToAccountOnce(
        PDO $pdo, int $account_id, string $type, string $title, string $body, string $link
    ): bool {
        $exists = $pdo->prepare(
            'SELECT 1 FROM Notification
             WHERE account_id = :aid AND type = :type AND link = :link LIMIT 1'
        );
        $exists->execute([':aid' => $account_id, ':type' => $type, ':link' => $link]);
        if ($exists->fetchColumn()) return false;

        $ins = $pdo->prepare(
            'INSERT INTO Notification (account_id, type, title, body, link)
             VALUES (:aid, :type, :title, :body, :link)'
        );
        return $ins->execute([
            ':aid' => $account_id, ':type' => $type, ':title' => $title,
            ':body' => $body, ':link' => $link,
        ]);
    }

    /**
     * Notify students currently enrolled in the section that an assignment was published.
     * Runs on the caller's PDO so it participates in the assignment transaction.
     * Returns the number of notifications created.
     */
    public static function notifyAssignmentPublished(
        PDO $pdo, int $assignment_id, int $section_id, string $title, string $deadline
    ): int {
        if ($assignment_id <= 0 || $section_id <= 0) return 0;

        $meta = $pdo->prepare(
            'SELECT c.course_code, c.course_name, l.full_name AS lecturer_name
             FROM ClassSection cs
             JOIN Course c   ON c.course_id  = cs.course_id
             JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
             WHERE cs.section_id = :sid LIMIT 1'
        );
        $meta->execute([':sid' => $section_id]);
        $info = $meta->fetch();
        if (!$info) return 0;

        $recipients = $pdo->prepare(
            "SELECT a.account_id
             FROM Enrollment e
             JOIN Account a ON a.student_id = e.student_id AND a.is_active = TRUE
             WHERE e.section_id = :sid AND e.status = 'Enrolled'"
        );
        $recipients->execute([':sid' => $section_id]);

        $link   = app_url('assets/student/assignment_detail.php?id=') . $assignment_id;
        $nTitle = mb_strimwidth('Bài tập mới: ' . $title, 0, 197, '…');
        $nBody  = 'Môn ' . $info['course_name'] . ' (' . $info['course_code'] . ')'
                . ' · GV ' . $info['lecturer_name']
                . ' · Công bố: ' . date('d/m/Y H:i')
                . ' · Hạn nộp: ' . date('d/m/Y H:i', strtotime($deadline)) . '.';

        $sent = 0;
        foreach ($recipients->fetchAll() as $row) {
            if (self::sendToAccountOnce($pdo, (int)$row['account_id'], 'Assignment', $nTitle, $nBody, $link)) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * Notify the student who owns a submission that the grade was published
     * or changed. Each real change produces its own notification (history is
     * preserved); repeating an unchanged grade sends nothing because the
     * caller only invokes this when the grade actually changed.
     */
    public static function notifyAssignmentGraded(PDO $pdo, int $submission_id, bool $is_update): bool {
        if ($submission_id <= 0) return false;
        $q = $pdo->prepare(
            "SELECT a.assignment_id, a.title, a.max_score, sub.score, sub.feedback,
                    c.course_code, c.course_name, l.full_name AS lecturer_name,
                    acc.account_id
             FROM Submission sub
             JOIN Assignment a     ON a.assignment_id = sub.assignment_id AND a.is_deleted = 0
             JOIN ClassSection cs  ON cs.section_id = a.section_id
             JOIN Course c         ON c.course_id = cs.course_id
             JOIN Lecturer l       ON l.lecturer_id = cs.lecturer_id
             JOIN Enrollment e     ON e.enrollment_id = sub.enrollment_id
             JOIN Account acc      ON acc.student_id = e.student_id
                                  AND acc.role = 'Student' AND acc.is_active = TRUE
             WHERE sub.submission_id = :sid
             LIMIT 1"
        );
        $q->execute([':sid' => $submission_id]);
        $row = $q->fetch();
        if (!$row || $row['score'] === null) return false;

        $feedback = trim((string)$row['feedback']);
        if (mb_strlen($feedback, 'UTF-8') > 160) {
            $feedback = mb_strimwidth($feedback, 0, 161, '…');
        }
        $body = 'Bài "' . $row['title'] . '"'
              . ' · Môn ' . $row['course_name'] . ' (' . $row['course_code'] . ')'
              . ' · GV ' . $row['lecturer_name']
              . ' · Điểm: ' . number_format((float)$row['score'], 2, '.', '')
              . '/' . number_format((float)$row['max_score'], 2, '.', '')
              . ' · Chấm: ' . date('d/m/Y H:i')
              . ' · Nhận xét: ' . ($feedback !== '' ? $feedback : 'Không có');

        return self::send(
            (int)$row['account_id'],
            'Grade',
            $is_update ? 'Điểm bài tập đã được cập nhật' : 'Bài tập đã được chấm điểm',
            $body,
            app_url('assets/student/assignment_detail.php?id=') . (int)$row['assignment_id']
        );
    }

    // Mark all as read for an account
    public static function markAllRead(int $account_id): bool {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare('UPDATE Notification SET is_read=TRUE WHERE account_id=:aid');
            return $stmt->execute([':aid' => $account_id]);
        } catch (Throwable) {
            return false;
        }
    }
}
