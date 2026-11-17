<?php
/**
 * UniPortal – AttendanceService
 *
 * Single source of truth for attendance / exam-eligibility calculations:
 *   * denominator  = all non-cancelled official sessions of the section
 *                    (Regular + Makeup), future sessions included
 *   * numerator    = counted absences recorded on completed official sessions
 *                    (Absent, plus Late/Excused only when policy enables it)
 *   * future sessions, cancelled sessions and unrecorded ("pending")
 *     sessions never count as absences
 *   * rule: absence > ban% ⇒ ineligible; exactly at the threshold stays eligible
 *   * every value is driven by the attendance policy in SystemConfig
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SystemConfig.php';

class AttendanceService {

    /** Effective policy from SystemConfig. */
    public static function policy(): array {
        $ban   = SystemConfig::getFloat('attendance_ban_pct', 20.0);
        $warn  = SystemConfig::getFloat('attendance_warn_pct', 15.0);
        $margin = max(0, SystemConfig::getInt('attendance_eligibility_warn_margin', 5));
        // "Approaching" starts at the earlier of the configured warning value
        // and the margin buffer below the ban threshold.
        $warnFrom = max(0.0, min($warn, $ban - $margin));
        return [
            'ban_pct'       => $ban,
            'warn_pct'      => $warn,
            'warn_from'     => $warnFrom,
            'warn_margin'   => $margin,
            'late_absent'   => SystemConfig::get('attendance_late_absent', '0') === '1',
            'excused_absent'=> SystemConfig::get('attendance_excused_absent', '0') === '1',
        ];
    }

    /**
     * Official-session counters for a section.
     * @return array{total:int,cancelled:int,official:int,completed:int}
     */
    public static function sessionCounts(PDO $pdo, int $sectionId): array {
        $q = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled,
                    COALESCE(SUM(status <> 'Cancelled'), 0) AS official,
                    COALESCE(SUM(status <> 'Cancelled'
                             AND TIMESTAMP(session_date, start_time) <= NOW()), 0) AS completed
             FROM AttendanceSession
             WHERE section_id = :sec"
        );
        $q->execute([':sec' => $sectionId]);
        $row = $q->fetch() ?: [];
        return [
            'total'     => (int)($row['total'] ?? 0),
            'cancelled' => (int)($row['cancelled'] ?? 0),
            'official'  => (int)($row['official'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
        ];
    }

    /** Counters of recorded attendance for one enrollment in a section. */
    public static function attendanceCounts(PDO $pdo, int $enrollmentId, int $sectionId): array {
        $q = $pdo->prepare(
            "SELECT COALESCE(SUM(a.status = 'Present'), 0) AS present,
                    COALESCE(SUM(a.status = 'Late'), 0)    AS late,
                    COALESCE(SUM(a.status = 'Excused'), 0) AS excused,
                    COALESCE(SUM(a.status = 'Absent'), 0)  AS absent,
                    COUNT(a.attendance_id)                 AS recorded
             FROM Attendance a
             JOIN AttendanceSession ats ON ats.session_id = a.session_id
             WHERE a.enrollment_id = :eid
               AND ats.section_id = :sec
               AND ats.status <> 'Cancelled'
               AND TIMESTAMP(ats.session_date, ats.start_time) <= NOW()"
        );
        $q->execute([':eid' => $enrollmentId, ':sec' => $sectionId]);
        $row = $q->fetch() ?: [];
        return [
            'present'  => (int)($row['present'] ?? 0),
            'late'     => (int)($row['late'] ?? 0),
            'excused'  => (int)($row['excused'] ?? 0),
            'absent'   => (int)($row['absent'] ?? 0),
            'recorded' => (int)($row['recorded'] ?? 0),
        ];
    }

    /** Per-enrollment stats for one section. */
    public static function studentSectionStats(PDO $pdo, int $enrollmentId, int $sectionId, ?array $policy = null): array {
        $policy = $policy ?? self::policy();
        $counts = self::sessionCounts($pdo, $sectionId);
        $att    = self::attendanceCounts($pdo, $enrollmentId, $sectionId);
        return self::buildStats($counts, $att, $policy);
    }

    /** Pure calculation used by every caller (no DB access). */
    public static function buildStats(array $counts, array $att, array $policy): array {
        $official  = (int)($counts['official'] ?? 0);
        $completed = (int)($counts['completed'] ?? 0);

        $counted = (int)$att['absent'];
        if (!empty($policy['late_absent']))    $counted += (int)$att['late'];
        if (!empty($policy['excused_absent'])) $counted += (int)$att['excused'];

        $ban  = (float)$policy['ban_pct'];
        $warnFrom = (float)$policy['warn_from'];
        $banThreshold = $official > 0 ? (int)floor($official * $ban / 100) : 0;
        $remaining = max(0, $banThreshold - $counted);
        $pct = $official > 0 ? round($counted / $official * 100, 1) : 0.0;

        // Exactly at ban% is still ELIGIBLE (spec: ineligible only when strictly above).
        $atThreshold = $official > 0 && abs($counted * 100 - $official * $ban) < 0.001;

        $status = 'no_data';
        $level  = 0;
        if ($official > 0) {
            if ($counted * 100 > $official * $ban) {
                $status = 'ineligible'; $level = 3;
            } elseif ($atThreshold) {
                $status = 'eligible'; $level = 2;   // eligible, but critically at the threshold
            } elseif ($counted * 100 >= $official * $warnFrom) {
                $status = 'at_risk'; $level = 1;
            } else {
                $status = 'eligible';
            }
        }

        $statusLabel = match ($status) {
            'ineligible' => 'Không đủ điều kiện dự thi',
            'at_risk'    => 'Nguy cơ mất điều kiện dự thi',
            'eligible'   => $atThreshold ? 'Đủ điều kiện dự thi (chạm ngưỡng)' : 'Đủ điều kiện dự thi',
            default      => 'Chưa có buổi học chính thức',
        };

        $explanation = match (true) {
            $official === 0
                => 'Lớp chưa có buổi học chính thức nào nên chưa thể xét điều kiện dự thi.',
            $status === 'ineligible'
                => sprintf(
                    'Vắng %d/%d buổi chính thức (%.1f%%), vượt ngưỡng %s%%. Theo quy chế, sinh viên không đủ điều kiện dự thi môn này.',
                    $counted, $official, $pct, self::fmtPct($ban)
                ),
            $atThreshold
                => sprintf(
                    'Vắng %d/%d buổi (%.1f%%), đang chạm đúng ngưỡng %s%%. Vẫn đủ điều kiện dự thi, nhưng vắng thêm 1 buổi sẽ bị cấm thi.',
                    $counted, $official, $pct, self::fmtPct($ban)
                ),
            $status === 'at_risk'
                => sprintf(
                    'Vắng %d/%d buổi (%.1f%%). Còn được vắng %d buổi trước ngưỡng %s%%.',
                    $counted, $official, $pct, $remaining, self::fmtPct($ban)
                ),
            default
                => sprintf(
                    'Vắng %d/%d buổi chính thức (%.1f%%), còn được vắng %d buổi trước ngưỡng %s%%.',
                    $counted, $official, $pct, $remaining, self::fmtPct($ban)
                ),
        };

        if ($official > 0 && $completed === 0) {
            $explanation = 'Chưa có buổi học nào hoàn thành; tỷ lệ vắng sẽ được cập nhật khi giảng viên điểm danh.';
        }

        return [
            'official_total'  => $official,
            'completed_total' => $completed,
            'cancelled_total' => (int)($counts['cancelled'] ?? 0),
            'present'         => (int)$att['present'],
            'late'            => (int)$att['late'],
            'excused'         => (int)$att['excused'],
            'absent'          => (int)$att['absent'],
            'recorded'        => (int)$att['recorded'],
            'absent_counted'  => $counted,
            'absent_pct'      => $pct,
            'remaining_allowed' => $remaining,
            'status'          => $status,
            'status_label'    => $statusLabel,
            'at_threshold'    => $atThreshold,
            'risk_level'      => $level,
            'warn_from_pct'   => $warnFrom,
            'ban_pct'         => $ban,
            'ban_threshold'   => $banThreshold,
            'late_counts'     => !empty($policy['late_absent']),
            'excused_counts'  => !empty($policy['excused_absent']),
            'explanation'     => $explanation,
        ];
    }

    /** Roster with eligibility stats for a section (ordered by student id). */
    public static function studentsForSection(PDO $pdo, int $sectionId, ?array $policy = null): array {
        $policy = $policy ?? self::policy();
        $counts = self::sessionCounts($pdo, $sectionId);

        $q = $pdo->prepare(
            "SELECT e.enrollment_id, e.student_id, e.status AS enroll_status,
                    s.full_name, s.class_code,
                    COALESCE(SUM(ats.session_id IS NOT NULL AND a.status = 'Present'), 0) AS present,
                    COALESCE(SUM(ats.session_id IS NOT NULL AND a.status = 'Late'), 0)    AS late,
                    COALESCE(SUM(ats.session_id IS NOT NULL AND a.status = 'Excused'), 0) AS excused,
                    COALESCE(SUM(ats.session_id IS NOT NULL AND a.status = 'Absent'), 0)  AS absent,
                    COUNT(ats.session_id) AS recorded
             FROM Enrollment e
             JOIN Student s ON s.student_id = e.student_id
             LEFT JOIN Attendance a ON a.enrollment_id = e.enrollment_id
             LEFT JOIN AttendanceSession ats ON ats.session_id = a.session_id
                  AND ats.status <> 'Cancelled'
                  AND TIMESTAMP(ats.session_date, ats.start_time) <= NOW()
             WHERE e.section_id = :sec
               AND e.status IN ('Enrolled','Completed')
             GROUP BY e.enrollment_id, e.student_id, e.status, s.full_name, s.class_code
             ORDER BY s.student_id ASC"
        );
        $q->execute([':sec' => $sectionId]);

        $rows = [];
        foreach ($q->fetchAll() as $row) {
            $att = [
                'present'  => (int)$row['present'],
                'late'     => (int)$row['late'],
                'excused'  => (int)$row['excused'],
                'absent'   => (int)$row['absent'],
                'recorded' => (int)$row['recorded'],
            ];
            $stats = self::buildStats($counts, $att, $policy);
            $rows[] = array_merge($row, $stats);
        }
        return $rows;
    }

    /** Section-level totals plus eligibility distribution. */
    public static function sectionSummary(PDO $pdo, int $sectionId, ?array $policy = null): array {
        $policy = $policy ?? self::policy();
        $counts = self::sessionCounts($pdo, $sectionId);
        $students = self::studentsForSection($pdo, $sectionId, $policy);

        $enrolled = count($students);
        $atRisk = 0; $ineligible = 0; $atThreshold = 0; $absentTotal = 0; $attended = 0;
        foreach ($students as $s) {
            if ($s['status'] === 'ineligible') $ineligible++;
            elseif ($s['status'] === 'at_risk') $atRisk++;
            elseif (!empty($s['at_threshold'])) $atThreshold++;
            $absentTotal += (int)$s['absent_counted'];
            $attended    += (int)$s['present'] + (int)$s['late'] + (int)$s['excused'];
        }
        $completed = $counts['completed'];
        $rate = $completed > 0 ? round($attended / max(1, $completed * max(1, $enrolled)) * 100, 1) : null;

        return array_merge($counts, [
            'enrolled'   => $enrolled,
            'students'   => $students,
            'at_risk'    => $atRisk,
            'at_threshold' => $atThreshold,
            'ineligible' => $ineligible,
            'absent_total' => $absentTotal,
            'attendance_rate' => $rate,
        ]);
    }

    /**
     * Whole-semester monitoring snapshot used by the admin attendance page.
     * @return array{sections:array,students:array,stats:array}
     */
    public static function semesterOverview(PDO $pdo, int $semesterId, ?array $policy = null): array {
        $policy = $policy ?? self::policy();

        $secQ = $pdo->prepare(
            "SELECT cs.section_id, cs.section_code, cs.room, c.course_code, c.course_name,
                    c.credits, l.full_name AS lecturer_name,
                    COALESCE(x.official, 0)  AS official,
                    COALESCE(x.completed, 0) AS completed,
                    COALESCE(x.cancelled, 0) AS cancelled,
                    (SELECT COUNT(*) FROM Enrollment e0
                     WHERE e0.section_id = cs.section_id
                       AND e0.status IN ('Enrolled','Completed')) AS enrolled
             FROM ClassSection cs
             JOIN Course c ON c.course_id = cs.course_id
             LEFT JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
             LEFT JOIN (
                 SELECT section_id,
                        SUM(status <> 'Cancelled') AS official,
                        SUM(status <> 'Cancelled'
                            AND TIMESTAMP(session_date, start_time) <= NOW()) AS completed,
                        SUM(status = 'Cancelled') AS cancelled
                 FROM AttendanceSession
                 GROUP BY section_id
             ) x ON x.section_id = cs.section_id
             WHERE cs.semester_id = :sem
             ORDER BY cs.section_code ASC"
        );
        $secQ->execute([':sem' => $semesterId]);
        $sections = [];
        foreach ($secQ->fetchAll() as $row) {
            $row['official']  = (int)$row['official'];
            $row['completed'] = (int)$row['completed'];
            $row['cancelled'] = (int)$row['cancelled'];
            $row['enrolled']  = (int)$row['enrolled'];
            $row['at_risk'] = 0;
            $row['at_threshold'] = 0;
            $row['ineligible'] = 0;
            $row['absent_total'] = 0;
            $row['attended_total'] = 0;
            $sections[(int)$row['section_id']] = $row;
        }

        $stQ = $pdo->prepare(
            "SELECT e.section_id, e.enrollment_id, e.student_id, e.status AS enroll_status,
                    s.full_name, s.class_code,
                    COALESCE(SUM(ats.session_id IS NOT NULL AND a.status = 'Present'), 0) AS present,
                    COALESCE(SUM(ats.session_id IS NOT NULL AND a.status = 'Late'), 0)    AS late,
                    COALESCE(SUM(ats.session_id IS NOT NULL AND a.status = 'Excused'), 0) AS excused,
                    COALESCE(SUM(ats.session_id IS NOT NULL AND a.status = 'Absent'), 0)  AS absent,
                    COUNT(ats.session_id) AS recorded
             FROM Enrollment e
             JOIN Student s ON s.student_id = e.student_id
             JOIN ClassSection cs ON cs.section_id = e.section_id AND cs.semester_id = :sem
             LEFT JOIN Attendance a ON a.enrollment_id = e.enrollment_id
             LEFT JOIN AttendanceSession ats ON ats.session_id = a.session_id
                  AND ats.status <> 'Cancelled'
                  AND TIMESTAMP(ats.session_date, ats.start_time) <= NOW()
             WHERE e.status IN ('Enrolled','Completed')
             GROUP BY e.section_id, e.enrollment_id, e.student_id, e.status, s.full_name, s.class_code
             ORDER BY s.student_id ASC"
        );
        $stQ->execute([':sem' => $semesterId]);

        $students = [];
        $stats = [
            'sections' => count($sections), 'official' => 0, 'completed' => 0,
            'students' => 0, 'at_risk' => 0, 'at_threshold' => 0, 'ineligible' => 0,
            'absent_total' => 0,
        ];
        foreach ($stQ->fetchAll() as $row) {
            $secId = (int)$row['section_id'];
            if (!isset($sections[$secId])) continue;
            $sec = $sections[$secId];
            $att = [
                'present'  => (int)$row['present'],
                'late'     => (int)$row['late'],
                'excused'  => (int)$row['excused'],
                'absent'   => (int)$row['absent'],
                'recorded' => (int)$row['recorded'],
            ];
            $student = array_merge($row, self::buildStats([
                'official'  => $sec['official'],
                'completed' => $sec['completed'],
                'cancelled' => $sec['cancelled'],
            ], $att, $policy));
            $student['section_code'] = $sec['section_code'];
            $student['course_code']  = $sec['course_code'];
            $student['course_name']  = $sec['course_name'];
            $student['lecturer_name']= $sec['lecturer_name'];
            $students[] = $student;

            if ($student['status'] === 'ineligible') {
                $sections[$secId]['ineligible']++;
                $stats['ineligible']++;
            } elseif ($student['status'] === 'at_risk') {
                $sections[$secId]['at_risk']++;
                $stats['at_risk']++;
            } elseif (!empty($student['at_threshold'])) {
                $sections[$secId]['at_threshold']++;
                $stats['at_threshold']++;
            }
            $sections[$secId]['absent_total'] += $student['absent_counted'];
            $sections[$secId]['attended_total'] += (int)$student['present'] + (int)$student['late'] + (int)$student['excused'];
            $stats['absent_total'] += $student['absent_counted'];
        }

        foreach ($sections as $sec) {
            $stats['official']  += $sec['official'];
            $stats['completed'] += $sec['completed'];
            $stats['students']  += $sec['enrolled'];
        }

        $sections = array_values($sections);
        return ['sections' => $sections, 'students' => $students, 'stats' => $stats];
    }

    /** Students at risk above a minimum percentage (admin/lecturer monitoring). */
    public static function flaggedStudents(array $students, ?float $minPct = null): array {
        $min = $minPct ?? self::policy()['warn_from'];
        $out = array_values(array_filter($students, fn($s) =>
            $s['official_total'] > 0 && (float)$s['absent_pct'] >= $min
        ));
        usort($out, fn($a, $b) => $b['absent_pct'] <=> $a['absent_pct']);
        return $out;
    }

    private static function fmtPct(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}
