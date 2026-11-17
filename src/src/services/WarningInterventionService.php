<?php
/**
 * UniPortal – Warning Intervention Service
 *
 * Every read and write is scoped to ClassSection rows whose lecturer_id is the
 * signed-in lecturer. Section-level warnings must belong to one of those
 * sections; course-level warnings (section_id IS NULL) are only visible when
 * the warned student is enrolled in one of the lecturer's sections.
 */

require_once __DIR__ . '/../config/database.php';

class WarningInterventionService {

    public const ACTION_TYPES = [
        'contact'    => 'Liên hệ sinh viên',
        'email'      => 'Gửi email nhắc nhở',
        'meeting'    => 'Họp trao đổi',
        'plan'       => 'Kế hoạch cải thiện',
        'assignment' => 'Giao bài bổ sung',
        'other'      => 'Khác',
    ];

    public const STATUSES = [
        'In Progress' => 'Đang xử lý',
        'Resolved'    => 'Đã giải quyết',
    ];

    // ── Assigned classes ─────────────────────────────────────
    /** All sections the lecturer is assigned to, newest semester first. */
    public static function getAssignedSections(int $lecturerId): array {
        if ($lecturerId <= 0) return [];
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT cs.section_id, cs.section_code, cs.room,
                    c.course_id, c.course_code, c.course_name, c.credits,
                    sem.semester_id, sem.semester_name, sem.academic_year, sem.start_date
             FROM ClassSection cs
             JOIN Course c    ON c.course_id    = cs.course_id
             JOIN Semester sem ON sem.semester_id = cs.semester_id
             WHERE cs.lecturer_id = :lid
             ORDER BY sem.start_date DESC, cs.section_code ASC'
        );
        $stmt->execute([':lid' => $lecturerId]);
        return $stmt->fetchAll();
    }

    /** True when the section is taught by the lecturer. */
    public static function ownsSection(int $lecturerId, int $sectionId): bool {
        if ($lecturerId <= 0 || $sectionId <= 0) return false;
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM ClassSection WHERE section_id = :sec AND lecturer_id = :lid LIMIT 1'
        );
        $stmt->execute([':sec' => $sectionId, ':lid' => $lecturerId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Validate a requested class filter.
     * Returns the section id to use (0 = all classes), or -1 when the request
     * names a section the lecturer does not own.
     */
    public static function resolveSectionFilter(int $lecturerId, ?int $requested): int {
        $owned = array_map('intval', array_column(self::getAssignedSections($lecturerId), 'section_id'));
        if (!$owned) return 0;
        if (count($owned) === 1) {
            // A lecturer with a single assigned class is scoped to it automatically.
            if ($requested !== null && $requested > 0 && !in_array($requested, $owned, true)) return -1;
            return $owned[0];
        }
        if ($requested !== null && $requested > 0) {
            return in_array($requested, $owned, true) ? $requested : -1;
        }
        return 0;
    }

    // ── Scope SQL ────────────────────────────────────────────
    /**
     * WHERE fragment for the warning scope. Callers must join
     * `LEFT JOIN ClassSection cs ON cs.section_id = aw.section_id`.
     * Placeholders used: scope_lid1, scope_lid2 (+ scope_sec, scope_sec2).
     */
    private static function scopeSql(int $sectionId): string {
        $ownSection = $sectionId > 0 ? 'aw.section_id = :scope_sec' : 'aw.section_id IS NOT NULL';
        $enrollSec  = $sectionId > 0 ? 'se.section_id = :scope_sec2' : '1 = 1';
        return "(($ownSection AND cs.lecturer_id = :scope_lid1)
              OR (aw.section_id IS NULL AND EXISTS (
                    SELECT 1 FROM Enrollment se
                    JOIN ClassSection scs ON scs.section_id = se.section_id
                    WHERE se.student_id = aw.student_id
                      AND $enrollSec
                      AND se.status IN ('Enrolled','Completed')
                      AND scs.lecturer_id = :scope_lid2
              )))";
    }

    private static function scopeParams(int $lecturerId, int $sectionId): array {
        $params = [':scope_lid1' => $lecturerId, ':scope_lid2' => $lecturerId];
        if ($sectionId > 0) {
            $params[':scope_sec']  = $sectionId;
            $params[':scope_sec2'] = $sectionId;
        }
        return $params;
    }

    /**
     * Section used to display/record an intervention for a warning.
     * Section-level warnings use their own section; course-level warnings use
     * $sectionId when supplied, otherwise the most recent owned section the
     * student is enrolled in.
     */
    private static function displaySectionExpr(int $sectionId): string {
        if ($sectionId > 0) return ':disp_sec';
        return 'COALESCE(aw.section_id, (
                    SELECT cs2.section_id
                    FROM Enrollment e2
                    JOIN ClassSection cs2 ON cs2.section_id = e2.section_id
                    JOIN Semester sem2    ON sem2.semester_id = cs2.semester_id
                    WHERE e2.student_id = aw.student_id
                      AND cs2.lecturer_id = :disp_lid
                      AND e2.status IN (\'Enrolled\',\'Completed\')
                    ORDER BY sem2.start_date DESC, cs2.section_id DESC
                    LIMIT 1
                ))';
    }

    // ── Warning list ─────────────────────────────────────────
    /** @return array{rows: array, total: int} */
    public static function getWarnings(
        int $lecturerId,
        int $sectionId,
        array $filters,
        int $limit,
        int $offset
    ): array {
        $pdo    = get_db();
        $where  = 'WHERE ' . self::scopeSql($sectionId);
        $params = self::scopeParams($lecturerId, $sectionId);

        if (!empty($filters['type'])) {
            $where .= ' AND aw.warning_type = :f_type';
            $params[':f_type'] = $filters['type'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (s.full_name LIKE :f_q1 OR s.student_id LIKE :f_q2)';
            $params[':f_q1'] = '%' . $filters['search'] . '%';
            $params[':f_q2'] = '%' . $filters['search'] . '%';
        }
        switch ($filters['status'] ?? 'all') {
            case 'unread':   $where .= ' AND aw.is_read = FALSE AND aw.status = \'Active\''; break;
            case 'active':   $where .= ' AND aw.status = \'Active\''; break;
            case 'resolved': $where .= ' AND aw.status = \'Resolved\''; break;
            case 'intervened':
                $where .= ' AND EXISTS (SELECT 1 FROM WarningIntervention ew
                                        WHERE ew.warning_id = aw.warning_id
                                          AND ew.lecturer_id = :f_wlid)';
                $params[':f_wlid'] = $lecturerId;
                break;
        }

        $countSql = "SELECT COUNT(*)
                     FROM AcademicWarning aw
                     JOIN Student s ON s.student_id = aw.student_id
                     LEFT JOIN ClassSection cs ON cs.section_id = aw.section_id
                     $where";
        $countSt = $pdo->prepare($countSql);
        $countSt->execute($params);
        $total = (int)$countSt->fetchColumn();

        $dispExpr = self::displaySectionExpr($sectionId);
        $sql = "SELECT aw.warning_id, aw.warning_type, aw.warning_level, aw.message,
                       aw.created_at, aw.is_read, aw.status AS warn_status,
                       aw.section_id AS warning_section_id,
                       s.student_id, s.full_name, s.class_code, s.email, s.phone,
                       $dispExpr AS display_section_id,
                       (SELECT COUNT(*) FROM WarningIntervention wi
                        WHERE wi.warning_id = aw.warning_id
                          AND wi.lecturer_id = :wi_lid) AS my_intervention_count,
                       (SELECT wi2.status FROM WarningIntervention wi2
                        WHERE wi2.warning_id = aw.warning_id
                          AND wi2.lecturer_id = :wi_lid2
                        ORDER BY wi2.updated_at DESC, wi2.intervention_id DESC
                        LIMIT 1) AS latest_intervention_status
                FROM AcademicWarning aw
                JOIN Student s ON s.student_id = aw.student_id
                LEFT JOIN ClassSection cs ON cs.section_id = aw.section_id
                $where
                ORDER BY aw.is_read ASC, aw.warning_level DESC, aw.created_at DESC
                LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $params[':wi_lid']  = $lecturerId;
        $params[':wi_lid2'] = $lecturerId;
        if ($sectionId > 0) $params[':disp_sec'] = $sectionId;
        else                $params[':disp_lid'] = $lecturerId;
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    // ── Statistics ───────────────────────────────────────────
    public static function getStats(int $lecturerId, int $sectionId): array {
        $pdo    = get_db();
        $where  = 'WHERE ' . self::scopeSql($sectionId);
        $params = self::scopeParams($lecturerId, $sectionId);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total_warnings,
                    COUNT(CASE WHEN aw.status = 'Active' THEN 1 END) AS active_warnings,
                    COUNT(CASE WHEN aw.status = 'Active' AND aw.warning_level = 3 THEN 1 END) AS high_risk_warnings,
                    COUNT(DISTINCT CASE WHEN aw.status = 'Active' AND aw.warning_level = 3
                                        THEN aw.student_id END) AS high_risk_students,
                    COUNT(DISTINCT CASE WHEN aw.status = 'Active' AND aw.warning_type = 'LowGrade'
                                        THEN aw.student_id END) AS low_grade_students,
                    COUNT(CASE WHEN aw.status = 'Resolved' THEN 1 END) AS resolved_warnings,
                    COUNT(CASE WHEN aw.status = 'Active' AND aw.is_read = FALSE THEN 1 END) AS unread_warnings
             FROM AcademicWarning aw
             LEFT JOIN ClassSection cs ON cs.section_id = aw.section_id
             $where"
        );
        $stmt->execute($params);
        $stats = $stmt->fetch() ?: [];

        $intWhere  = 'WHERE wi.lecturer_id = :wi_lid';
        $intParams = [':wi_lid' => $lecturerId];
        if ($sectionId > 0) {
            $intWhere .= ' AND wi.section_id = :wi_sec';
            $intParams[':wi_sec'] = $sectionId;
        }
        $intSt = $pdo->prepare(
            "SELECT COUNT(*) FROM WarningIntervention wi
             JOIN ClassSection cs ON cs.section_id = wi.section_id
                AND cs.lecturer_id = :wi_owner
             $intWhere"
        );
        $intParams[':wi_owner'] = $lecturerId;
        $intSt->execute($intParams);
        $totalInterventions = (int)$intSt->fetchColumn();

        $progParams = $intParams;
        $progSt = $pdo->prepare(
            "SELECT COUNT(*) FROM WarningIntervention wi
             JOIN ClassSection cs ON cs.section_id = wi.section_id
                AND cs.lecturer_id = :wi_owner
             $intWhere AND wi.status = 'In Progress'"
        );
        $progSt->execute($progParams);

        return [
            'total_warnings'       => (int)($stats['total_warnings'] ?? 0),
            'active_warnings'      => (int)($stats['active_warnings'] ?? 0),
            'unread_warnings'      => (int)($stats['unread_warnings'] ?? 0),
            'high_risk_warnings'   => (int)($stats['high_risk_warnings'] ?? 0),
            'high_risk_students'   => (int)($stats['high_risk_students'] ?? 0),
            'low_grade_students'   => (int)($stats['low_grade_students'] ?? 0),
            'resolved_warnings'    => (int)($stats['resolved_warnings'] ?? 0),
            'interventions_total'  => $totalInterventions,
            'interventions_open'   => (int)$progSt->fetchColumn(),
        ];
    }

    // ── Single warning (authorized) ──────────────────────────
    public static function getWarning(int $lecturerId, int $warningId, int $sectionId): array|false {
        if ($warningId <= 0) return false;
        $pdo    = get_db();
        $where  = 'WHERE aw.warning_id = :wid AND ' . self::scopeSql($sectionId);
        $params = self::scopeParams($lecturerId, $sectionId);
        $params[':wid'] = $warningId;

        $dispExpr = self::displaySectionExpr($sectionId);
        $stmt = $pdo->prepare(
            "SELECT aw.warning_id, aw.warning_type, aw.warning_level, aw.message,
                    aw.created_at, aw.is_read, aw.status AS warn_status,
                    aw.section_id AS warning_section_id,
                    s.student_id, s.full_name, s.class_code, s.email, s.phone,
                    $dispExpr AS display_section_id
             FROM AcademicWarning aw
             JOIN Student s ON s.student_id = aw.student_id
             LEFT JOIN ClassSection cs ON cs.section_id = aw.section_id
             $where LIMIT 1"
        );
        if ($sectionId > 0) $params[':disp_sec'] = $sectionId;
        else                $params[':disp_lid'] = $lecturerId;
        $stmt->execute($params);
        return $stmt->fetch();
    }

    // ── Interventions ────────────────────────────────────────
    /** @return array{ok: bool, message: string, intervention_id?: int} */
    public static function record(int $lecturerId, int $accountId, array $input): array {
        $warningId  = (int)($input['warning_id'] ?? 0);
        $sectionId  = (int)($input['section_id'] ?? 0);
        $actionType = (string)($input['action_type'] ?? '');
        $status     = (string)($input['status'] ?? 'In Progress');
        $note       = trim((string)($input['note'] ?? ''));

        if ($warningId <= 0 || $sectionId <= 0) {
            return ['ok' => false, 'message' => 'Thiếu thông tin cảnh báo hoặc lớp học phần.'];
        }
        if (!isset(self::ACTION_TYPES[$actionType])) {
            return ['ok' => false, 'message' => 'Hình thức can thiệp không hợp lệ.'];
        }
        if (!isset(self::STATUSES[$status])) {
            return ['ok' => false, 'message' => 'Trạng thái can thiệp không hợp lệ.'];
        }
        if (mb_strlen($note) > 1000) {
            $note = mb_substr($note, 0, 1000);
        }
        if (!self::ownsSection($lecturerId, $sectionId)) {
            return ['ok' => false, 'message' => 'Bạn không phụ trách lớp học phần này.'];
        }

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $wSt = $pdo->prepare(
                'SELECT aw.warning_id, aw.student_id, aw.section_id
                 FROM AcademicWarning aw WHERE aw.warning_id = :wid FOR UPDATE'
            );
            $wSt->execute([':wid' => $warningId]);
            $warning = $wSt->fetch();
            if (!$warning) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Cảnh báo không tồn tại.'];
            }

            if ($warning['section_id'] !== null && (int)$warning['section_id'] !== $sectionId) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Cảnh báo không thuộc lớp học phần đã chọn.'];
            }
            if ($warning['section_id'] === null) {
                $mSt = $pdo->prepare(
                    "SELECT 1 FROM Enrollment
                     WHERE student_id = :sid AND section_id = :sec
                       AND status IN ('Enrolled','Completed') LIMIT 1"
                );
                $mSt->execute([':sid' => $warning['student_id'], ':sec' => $sectionId]);
                if (!$mSt->fetchColumn()) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'Sinh viên không thuộc lớp học phần đã chọn.'];
                }
            }

            $secSt = $pdo->prepare(
                'SELECT course_id FROM ClassSection WHERE section_id = :sec AND lecturer_id = :lid LIMIT 1'
            );
            $secSt->execute([':sec' => $sectionId, ':lid' => $lecturerId]);
            $courseId = $secSt->fetchColumn();
            if (!$courseId) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Bạn không phụ trách lớp học phần này.'];
            }

            $ins = $pdo->prepare(
                'INSERT INTO WarningIntervention
                    (warning_id, student_id, section_id, course_id, lecturer_id, account_id,
                     action_type, note, status)
                 VALUES (:wid, :sid, :sec, :cid, :lid, :acc, :action, :note, :status)'
            );
            $ins->execute([
                ':wid'    => $warningId,
                ':sid'    => $warning['student_id'],
                ':sec'    => $sectionId,
                ':cid'    => (int)$courseId,
                ':lid'    => $lecturerId,
                ':acc'    => $accountId > 0 ? $accountId : null,
                ':action' => $actionType,
                ':note'   => $note !== '' ? $note : null,
                ':status' => $status,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $pdo->prepare('UPDATE AcademicWarning SET is_read = TRUE WHERE warning_id = :wid AND is_read = FALSE')
                ->execute([':wid' => $warningId]);

            $pdo->commit();
            return [
                'ok'              => true,
                'message'         => 'Đã ghi nhận can thiệp.',
                'intervention_id' => $newId,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Update status of one of the lecturer's own intervention records. */
    public static function updateStatus(int $lecturerId, int $interventionId, string $status): array {
        if ($interventionId <= 0) {
            return ['ok' => false, 'message' => 'Hồ sơ can thiệp không hợp lệ.'];
        }
        if (!isset(self::STATUSES[$status])) {
            return ['ok' => false, 'message' => 'Trạng thái can thiệp không hợp lệ.'];
        }
        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT wi.intervention_id, wi.status
                 FROM WarningIntervention wi
                 JOIN ClassSection cs ON cs.section_id = wi.section_id
                 WHERE wi.intervention_id = :iid
                   AND wi.lecturer_id = :lid
                   AND cs.lecturer_id = :lid2
                 FOR UPDATE'
            );
            $st->execute([':iid' => $interventionId, ':lid' => $lecturerId, ':lid2' => $lecturerId]);
            if (!$st->fetch()) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Bạn không có quyền cập nhật hồ sơ can thiệp này.'];
            }
            $pdo->prepare('UPDATE WarningIntervention SET status = :st WHERE intervention_id = :iid')
                ->execute([':st' => $status, ':iid' => $interventionId]);
            $pdo->commit();
            return ['ok' => true, 'message' => 'Đã cập nhật trạng thái can thiệp.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Interventions recorded by this lecturer (class-scoped when requested). */
    public static function getInterventions(int $lecturerId, int $sectionId, int $limit = 10): array {
        $pdo    = get_db();
        $where  = 'WHERE wi.lecturer_id = :wi_lid AND cs.lecturer_id = :wi_owner';
        $params = [':wi_lid' => $lecturerId, ':wi_owner' => $lecturerId];
        if ($sectionId > 0) {
            $where .= ' AND wi.section_id = :wi_sec';
            $params[':wi_sec'] = $sectionId;
        }
        $stmt = $pdo->prepare(
            "SELECT wi.intervention_id, wi.warning_id, wi.action_type, wi.note, wi.status,
                    wi.created_at, wi.updated_at,
                    s.student_id, s.full_name,
                    cs.section_code, c.course_code, c.course_name,
                    aw.warning_type, aw.warning_level
             FROM WarningIntervention wi
             JOIN ClassSection cs   ON cs.section_id = wi.section_id
             JOIN Course c          ON c.course_id  = wi.course_id
             JOIN Student s         ON s.student_id = wi.student_id
             JOIN AcademicWarning aw ON aw.warning_id = wi.warning_id
             $where
             ORDER BY wi.updated_at DESC, wi.intervention_id DESC
             LIMIT $limit"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getInterventionsForWarning(int $lecturerId, int $warningId): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT wi.intervention_id, wi.action_type, wi.note, wi.status, wi.created_at, wi.updated_at
             FROM WarningIntervention wi
             JOIN ClassSection cs ON cs.section_id = wi.section_id
             WHERE wi.warning_id = :wid AND wi.lecturer_id = :lid AND cs.lecturer_id = :lid2
             ORDER BY wi.created_at DESC, wi.intervention_id DESC'
        );
        $stmt->execute([':wid' => $warningId, ':lid' => $lecturerId, ':lid2' => $lecturerId]);
        return $stmt->fetchAll();
    }
}
