<?php
/**
 * UniPortal – RegistrationService
 *
 * Central rules for course offerings and period-based registration:
 *   * structured weekly schedule + conflict detection
 *   * dynamic opening threshold = floor(min_enrollment / 2) + 1
 *   * atomic registration with row locking (no over-enrollment / races)
 *   * automatic confirmation, capacity closure and period finalisation
 *   * audit logging and student notifications
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/AcademicAlertService.php';
require_once __DIR__ . '/SystemConfig.php';

class RegistrationService {

    // ─────────────────────────────────────────────────────────
    // Schedule helpers
    // ─────────────────────────────────────────────────────────

    public static function openingThreshold(int $minEnrollment): int {
        $min = max(1, $minEnrollment);
        return (int)floor($min / 2) + 1;
    }

    /** @return int[] ISO weekday numbers (1=Mon … 7=Sun), unique + sorted */
    public static function normalizeDays($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($raw)) return [];
        $days = [];
        foreach ($raw as $d) {
            $n = (int)$d;
            if ($n >= 1 && $n <= 7) $days[$n] = true;
        }
        $out = array_keys($days);
        sort($out);
        return $out;
    }

    public static function daysToString(array $days): string {
        $days = self::normalizeDays($days);
        return implode(',', $days);
    }

    public static function normalizeTime(?string $time): ?string {
        $time = trim((string)$time);
        if ($time === '') return null;
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) return null;
        $h = (int)$m[1];
        $i = (int)$m[2];
        $s = isset($m[3]) ? (int)$m[3] : 0;
        if ($h > 23 || $i > 59 || $s > 59) return null;
        return sprintf('%02d:%02d:%02d', $h, $i, $s);
    }

    public static function scheduleText(array $days, ?string $start, ?string $end): string {
        $days = self::normalizeDays($days);
        $start = self::normalizeTime($start);
        $end   = self::normalizeTime($end);
        if (empty($days) || !$start || !$end) return '';
        $label = implode(' & ', array_map(static fn($d) => self::dayLabel($d), $days));
        return $label . ' ' . substr($start, 0, 5) . '-' . substr($end, 0, 5);
    }

    public static function dayLabel(int $day): string {
        return match ($day) {
            1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5',
            5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'CN',
            default => '',
        };
    }

    /**
     * Parse a legacy schedule string such as "Thứ 2 & 4 07:30-09:00".
     * @return array{days:int[],start:?string,end:?string}
     */
    public static function parseScheduleText(?string $text): array {
        $text = trim((string)$text);
        $out  = ['days' => [], 'start' => null, 'end' => null];
        if ($text === '') return $out;

        if (preg_match('/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/u', $text, $m)) {
            $out['start'] = self::normalizeTime($m[1]);
            $out['end']   = self::normalizeTime($m[2]);
        }

        if (preg_match('/(?:Thứ|Thu)\s*([2-7](?:\s*(?:&|,|và|\+)\s*[2-7])*)/iu', $text, $m)) {
            preg_match_all('/[2-7]/', $m[1], $dm);
            // Vietnamese "Thứ 2" is Monday (ISO 1), "Thứ 7" is Saturday (ISO 6).
            $out['days'] = self::normalizeDays(array_map(static fn($n) => (int)$n - 1, $dm[0] ?? []));
        } elseif (preg_match('/\bCN\b/u', $text)) {
            $out['days'] = [7];
        }

        return $out;
    }

    /**
     * Weekly slots for a class section, using structured columns first and
     * falling back to the legacy free-text schedule.
     * @return array<int, array{day:int,start:string,end:string}>
     */
    public static function sectionSlots(array $section): array {
        $days  = self::normalizeDays($section['study_days'] ?? '');
        $start = self::normalizeTime($section['start_time'] ?? null);
        $end   = self::normalizeTime($section['end_time'] ?? null);

        if (empty($days) || !$start || !$end) {
            $parsed = self::parseScheduleText($section['schedule'] ?? '');
            $days   = $parsed['days'];
            $start  = $parsed['start'];
            $end    = $parsed['end'];
        }
        if (empty($days) || !$start || !$end || $start >= $end) return [];

        $slots = [];
        foreach ($days as $d) {
            $slots[] = ['day' => $d, 'start' => $start, 'end' => $end];
        }
        return $slots;
    }

    public static function slotsOverlap(array $a, array $b): bool {
        return (int)$a['day'] === (int)$b['day']
            && $a['start'] < $b['end']
            && $a['end'] > $b['start'];
    }

    public static function sectionsOverlap(array $a, array $b): bool {
        foreach (self::sectionSlots($a) as $sa) {
            foreach (self::sectionSlots($b) as $sb) {
                if (self::slotsOverlap($sa, $sb)) return true;
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────
    // Offering validation (admin)
    // ─────────────────────────────────────────────────────────

    /**
     * Validate an offering/section payload and detect lecturer, room and
     * duplicate-section conflicts.
     *
     * @return array{ok:bool,errors:string[],data:array}
     */
    public static function validateSection(PDO $pdo, array $in, ?int $excludeSectionId = null): array {
        $errors = [];
        $data   = [
            'course_id'    => (int)($in['course_id'] ?? 0),
            'semester_id'  => (int)($in['semester_id'] ?? 0),
            'lecturer_id'  => (int)($in['lecturer_id'] ?? 0),
            'section_code' => strtoupper(trim((string)($in['section_code'] ?? ''))),
            'room'         => trim((string)($in['room'] ?? '')),
            'min_enrollment' => (int)($in['min_enrollment'] ?? 48),
            'max_enrollment' => (int)($in['max_enrollment'] ?? 64),
            'study_days'   => self::normalizeDays($in['study_days'] ?? []),
            'start_time'   => self::normalizeTime($in['start_time'] ?? null),
            'end_time'     => self::normalizeTime($in['end_time'] ?? null),
            'reg_open'     => trim((string)($in['reg_open'] ?? '')),
            'reg_close'    => trim((string)($in['reg_close'] ?? '')),
        ];

        if ($data['course_id'] < 1)   $errors[] = 'Vui lòng chọn môn học.';
        if ($data['semester_id'] < 1) $errors[] = 'Vui lòng chọn học kỳ.';
        if ($data['lecturer_id'] < 1) $errors[] = 'Vui lòng chọn giảng viên.';
        if ($data['section_code'] === '') {
            $errors[] = 'Vui lòng nhập mã lớp học phần.';
        } elseif (mb_strlen($data['section_code']) > 20) {
            $errors[] = 'Mã lớp tối đa 20 ký tự.';
        }
        if ($data['min_enrollment'] < 1 || $data['min_enrollment'] > 200) {
            $errors[] = 'Sĩ số tối thiểu phải từ 1 đến 200.';
        }
        if ($data['max_enrollment'] < 1 || $data['max_enrollment'] > 200) {
            $errors[] = 'Sĩ số tối đa phải từ 1 đến 200.';
        }
        if ($data['max_enrollment'] < $data['min_enrollment']) {
            $errors[] = 'Sĩ số tối đa phải lớn hơn hoặc bằng sĩ số tối thiểu.';
        }
        if (empty($data['study_days'])) {
            $errors[] = 'Vui lòng chọn ít nhất một ngày học.';
        }
        if (!$data['start_time'] || !$data['end_time']) {
            $errors[] = 'Giờ bắt đầu và giờ kết thúc không hợp lệ.';
        } elseif ($data['start_time'] >= $data['end_time']) {
            $errors[] = 'Giờ kết thúc phải sau giờ bắt đầu.';
        }

        if ($data['reg_open'] !== '') {
            $ts = strtotime($data['reg_open']);
            if ($ts === false) $errors[] = 'Thời gian mở đăng ký không hợp lệ.';
            else $data['reg_open'] = date('Y-m-d H:i:s', $ts);
        } else {
            $data['reg_open'] = null;
        }
        if ($data['reg_close'] !== '') {
            $ts = strtotime($data['reg_close']);
            if ($ts === false) $errors[] = 'Thời gian đóng đăng ký không hợp lệ.';
            else $data['reg_close'] = date('Y-m-d H:i:s', $ts);
        } else {
            $data['reg_close'] = null;
        }
        if ($data['reg_open'] && $data['reg_close'] && $data['reg_close'] <= $data['reg_open']) {
            $errors[] = 'Thời gian đóng đăng ký phải sau thời gian mở đăng ký.';
        }

        if ($errors) return ['ok' => false, 'errors' => $errors, 'data' => $data];

        $course = $pdo->prepare('SELECT course_id, course_code, course_name FROM Course WHERE course_id=:id AND is_active=TRUE LIMIT 1');
        $course->execute([':id' => $data['course_id']]);
        $courseRow = $course->fetch();
        if (!$courseRow) $errors[] = 'Môn học không tồn tại hoặc đã ngừng mở.';

        $sem = $pdo->prepare('SELECT semester_id, semester_name, academic_year FROM Semester WHERE semester_id=:id LIMIT 1');
        $sem->execute([':id' => $data['semester_id']]);
        if (!$sem->fetch()) $errors[] = 'Học kỳ không hợp lệ.';

        $lec = $pdo->prepare(
            "SELECT l.lecturer_id, l.full_name FROM Lecturer l
             JOIN Account a ON a.lecturer_id = l.lecturer_id
             WHERE l.lecturer_id=:id AND l.status='Active' AND a.role='Lecturer' AND a.is_active=1
             LIMIT 1"
        );
        $lec->execute([':id' => $data['lecturer_id']]);
        $lecRow = $lec->fetch();
        if (!$lecRow) $errors[] = 'Giảng viên không hợp lệ hoặc đã ngừng hoạt động.';

        if ($errors) return ['ok' => false, 'errors' => $errors, 'data' => $data];

        $dup = $pdo->prepare(
            'SELECT section_id FROM ClassSection
             WHERE section_code=:code AND semester_id=:sem
               AND (:exclude = 0 OR section_id <> :exclude2) LIMIT 1'
        );
        $dup->execute([':code' => $data['section_code'], ':sem' => $data['semester_id'],
                       ':exclude' => (int)($excludeSectionId ?? 0), ':exclude2' => (int)($excludeSectionId ?? 0)]);
        if ($dup->fetch()) $errors[] = 'Mã lớp ' . $data['section_code'] . ' đã tồn tại trong học kỳ này.';

        $newSection = [
            'section_id'  => (int)($excludeSectionId ?? 0),
            'course_id'   => $data['course_id'],
            'semester_id' => $data['semester_id'],
            'lecturer_id' => $data['lecturer_id'],
            'room'        => $data['room'],
            'study_days'  => self::daysToString($data['study_days']),
            'start_time'  => $data['start_time'],
            'end_time'    => $data['end_time'],
            'section_code'=> $data['section_code'],
            'schedule'    => self::scheduleText($data['study_days'], $data['start_time'], $data['end_time']),
        ];

        $q = $pdo->prepare(
            "SELECT cs.section_id, cs.section_code, cs.course_id, cs.lecturer_id, cs.room,
                    cs.schedule, cs.study_days, cs.start_time, cs.end_time,
                    c.course_code, c.course_name, l.full_name AS lecturer_name
             FROM ClassSection cs
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
             WHERE cs.semester_id = :sem
               AND cs.section_status <> 'Cancelled'
               AND (:exclude = 0 OR cs.section_id <> :exclude2)"
        );
        $q->execute([':sem' => $data['semester_id'],
                     ':exclude' => (int)($excludeSectionId ?? 0), ':exclude2' => (int)($excludeSectionId ?? 0)]);
        $others = $q->fetchAll();

        foreach ($others as $other) {
            if (!self::sectionsOverlap($newSection, $other)) continue;

            if ((int)$other['lecturer_id'] === $data['lecturer_id']) {
                $errors[] = 'Trùng lịch giảng viên ' . $lecRow['full_name']
                    . ': lớp ' . $other['section_code'] . ' (' . $other['course_code'] . ') đã dạy cùng khung giờ.';
            }
            if ($data['room'] !== '' && strcasecmp(trim((string)$other['room']), $data['room']) === 0) {
                $errors[] = 'Trùng phòng ' . $data['room'] . ': lớp ' . $other['section_code']
                    . ' (' . $other['course_code'] . ') đã dùng phòng này cùng khung giờ.';
            }
            if ((int)$other['course_id'] === $data['course_id']
                && (int)$other['lecturer_id'] === $data['lecturer_id']) {
                $errors[] = 'Lớp ' . $other['section_code'] . ' của cùng môn và giảng viên đã trùng lịch.';
            }
        }

        return ['ok' => empty($errors), 'errors' => array_values(array_unique($errors)), 'data' => $data];
    }

    // ─────────────────────────────────────────────────────────
    // Counts and statuses
    // ─────────────────────────────────────────────────────────

    public static function enrolledCount(PDO $pdo, int $sectionId): int {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM Enrollment WHERE section_id=:sid AND status IN ('Enrolled','Completed')"
        );
        $s->execute([':sid' => $sectionId]);
        return (int)$s->fetchColumn();
    }

    /** @return array{label:string,bg:string,fg:string} */
    public static function statusMeta(string $status): array {
        return match ($status) {
            'Confirmed'    => ['label' => 'Đã mở lớp',       'bg' => '#d1fae5', 'fg' => '#065f46'],
            'Insufficient' => ['label' => 'Không đủ sĩ số',  'bg' => '#fee2e2', 'fg' => '#b91c1c'],
            'Cancelled'    => ['label' => 'Đã hủy',          'bg' => '#f1f5f9', 'fg' => '#64748b'],
            default        => ['label' => 'Chờ mở lớp',      'bg' => '#fef9c3', 'fg' => '#854d0e'],
        };
    }

    /**
     * Human readable capacity state, e.g.
     * "25/64 sinh viên đăng ký – Lớp đủ điều kiện mở."
     * @return array{text:string,short:string,state:string,threshold:int,pct:int}
     */
    public static function capacityState(array $section, int $count): array {
        $min  = max(1, (int)($section['min_enrollment'] ?? 48));
        $max  = max($min, (int)($section['max_enrollment'] ?? ($section['capacity'] ?? 64)));
        $thr  = self::openingThreshold($min);
        $pct  = $max > 0 ? (int)min(100, round($count / $max * 100)) : 0;

        if ($count >= $max) {
            $state = 'full';
            $text  = $count . '/' . $max . ' sinh viên đăng ký – Đã đủ sĩ số tối đa, đóng đăng ký.';
            $short = 'Đã đầy';
        } elseif ($count >= $thr) {
            $state = 'open';
            $text  = $count . '/' . $max . ' sinh viên đăng ký – Lớp đủ điều kiện mở (ngưỡng ' . $thr . ').';
            $short = 'Đủ điều kiện mở';
        } else {
            $state = 'pending';
            $text  = $count . '/' . $max . ' sinh viên đăng ký – Chưa đủ ngưỡng mở (cần ' . $thr . ').';
            $short = 'Chờ mở lớp';
        }
        return ['text' => $text, 'short' => $short, 'state' => $state, 'threshold' => $thr, 'pct' => $pct];
    }

    public static function refreshPeriodStatuses(PDO $pdo): void {
        try {
            $pdo->exec("UPDATE registrationperiod SET status='Open'
                        WHERE status='Upcoming' AND NOW() BETWEEN start_at AND end_at");
            $pdo->exec("UPDATE registrationperiod SET status='Closed'
                        WHERE status='Open' AND NOW() > end_at");
        } catch (Throwable) {}
    }

    /** @return array<int,array> open/upcoming periods the student may join */
    public static function eligiblePeriodsForStudent(PDO $pdo, string $studentId): array {
        self::refreshPeriodStatuses($pdo);
        $stmt = $pdo->prepare(
            "SELECT rp.*, sem.semester_name, sem.academic_year, sem.status AS semester_status,
                    sem.start_date, sem.end_date
             FROM registrationperiod rp
             JOIN semester sem ON sem.semester_id = rp.semester_id
             WHERE rp.status IN ('Open','Upcoming')
               AND sem.status <> 'Completed'
               AND (
                    NOT EXISTS (SELECT 1 FROM registrationperiodmajor rpm WHERE rpm.period_id = rp.period_id)
                    OR EXISTS (
                        SELECT 1 FROM registrationperiodmajor rpm
                        JOIN student s ON s.major_id = rpm.major_id
                        WHERE rpm.period_id = rp.period_id AND s.student_id = :sid
                    )
               )
             ORDER BY (rp.status='Open') DESC, sem.start_date ASC, rp.start_at ASC"
        );
        $stmt->execute([':sid' => $studentId]);
        return $stmt->fetchAll();
    }

    public static function ensureSessionsForSection(PDO $pdo, int $sectionId): int {
        $q = $pdo->prepare("SELECT * FROM TeachingSchedule WHERE section_id = ? AND status = 'Active'");
        $q->execute([$sectionId]);
        $schedule = $q->fetch();
        if (!$schedule) return 0;
        require_once __DIR__ . '/../models/TeachingSchedule.php';
        return TeachingSchedule::syncSessions($schedule)['inserted'];
    }

    public static function periodIsOpen(array $period): bool {
        $now = time();
        return $period['status'] === 'Open'
            && $now >= strtotime($period['start_at'])
            && $now <= strtotime($period['end_at']);
    }

    public static function studentMajor(PDO $pdo, string $studentId): array|false {
        $stmt = $pdo->prepare(
            'SELECT s.student_id, s.major_id, s.admission_year, s.department_id,
                    m.major_code, m.major_name, d.department_code, d.department_name
             FROM student s
             LEFT JOIN major m ON m.major_id = s.major_id
             LEFT JOIN department d ON d.department_id = s.department_id
             WHERE s.student_id = :sid LIMIT 1'
        );
        $stmt->execute([':sid' => $studentId]);
        return $stmt->fetch();
    }

    // ─────────────────────────────────────────────────────────
    // Course-based registration catalogue
    // ─────────────────────────────────────────────────────────

    /** Lowest final score (0–10) that still earns credit, taken from GradeScale. */
    public static function passingScoreThreshold(PDO $pdo): float {
        $value = $pdo->query('SELECT MIN(min_score) FROM GradeScale WHERE grade_point > 0')->fetchColumn();
        return ($value === false || $value === null) ? 4.0 : (float)$value;
    }

    /**
     * Official academic results per course, built from completed enrollments
     * and weighted assessment grades.
     *
     * @return array<int,array{attempts:int,completed:int,best_final:?float,passed:bool,retake:bool}>
     */
    public static function courseAcademicResults(PDO $pdo, string $studentId): array {
        $passThreshold = self::passingScoreThreshold($pdo);
        $stmt = $pdo->prepare(
            "SELECT x.course_id, COUNT(*) AS attempts,
                    SUM(CASE WHEN x.status = 'Completed' THEN 1 ELSE 0 END) AS completed_attempts,
                    MAX(CASE WHEN x.status = 'Completed' THEN x.final_score END) AS best_final
             FROM (
                 SELECT e.enrollment_id, e.status, cs.course_id,
                        ROUND(SUM(g.score * a.weight_percent / 100) / 10, 1) AS final_score
                 FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id = e.section_id
                 LEFT JOIN Assessment a ON a.section_id = cs.section_id
                 LEFT JOIN Grade g ON g.enrollment_id = e.enrollment_id
                                  AND g.assessment_id = a.assessment_id
                 WHERE e.student_id = :sid
                 GROUP BY e.enrollment_id, e.status, cs.course_id
             ) x
             GROUP BY x.course_id"
        );
        $stmt->execute([':sid' => $studentId]);

        $retakeMaxPoint = max(0.0, SystemConfig::getFloat('retake_allowed_max_gpa', 2.0));
        $scale = $pdo->query(
            'SELECT min_score, max_score, grade_point FROM GradeScale ORDER BY min_score DESC'
        )->fetchAll();

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $best = $row['best_final'] !== null ? (float)$row['best_final'] : null;
            $passed = $best !== null && $best >= $passThreshold;
            $point = 0.0;
            if ($best !== null) {
                foreach ($scale as $band) {
                    $min = (float)$band['min_score'];
                    $max = (float)$band['max_score'];
                    if ($best >= $min && ($best < $max || $max >= 10.0)) {
                        $point = (float)$band['grade_point'];
                        break;
                    }
                }
            }
            $out[(int)$row['course_id']] = [
                'attempts'  => (int)$row['attempts'],
                'completed' => (int)$row['completed_attempts'],
                'best_final'=> $best,
                'passed'    => $passed,
                // Failed or ungraded records follow the university's retake rule.
                'retake'    => !$passed && $point < $retakeMaxPoint,
            ];
        }
        return $out;
    }

    /** Active (non-withdrawn) registrations for one semester. */
    public static function activeEnrollments(PDO $pdo, string $studentId, int $semesterId): array {
        if ($semesterId < 1) return [];
        $stmt = $pdo->prepare(
            "SELECT e.enrollment_id, e.section_id, cs.course_id, cs.section_code,
                    cs.section_status, cs.status_note,
                    cs.schedule, cs.study_days, cs.start_time, cs.end_time, cs.room,
                    cs.min_enrollment, cs.max_enrollment, cs.capacity,
                    c.course_code, c.course_name, c.credits,
                    l.full_name AS lecturer_name
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
             WHERE e.student_id = :sid AND cs.semester_id = :sem AND e.status = 'Enrolled'
             ORDER BY c.course_code"
        );
        $stmt->execute([':sid' => $studentId, ':sem' => $semesterId]);
        return $stmt->fetchAll();
    }

    /**
     * Whether one section can be chosen by the student right now. Mirrors the
     * checks enforced by register() so the UI never promises an invalid action.
     *
     * @return array{ok:bool,reason:string,state:string}
     */
    public static function sectionAvailability(
        array $section, array $activeSections, int $registeredCredits, int $maxCredits,
        bool $periodOpen, bool $prereqPassed
    ): array {
        if (!$periodOpen) {
            return ['ok' => false, 'reason' => 'Chưa mở đợt đăng ký', 'state' => 'closed'];
        }
        $status = (string)($section['section_status'] ?? '');
        if ($status === 'Cancelled' || $status === 'Insufficient') {
            return ['ok' => false, 'reason' => self::statusMeta($status)['label'], 'state' => 'blocked'];
        }
        if (!$prereqPassed) {
            return ['ok' => false, 'reason' => 'Chưa đạt môn tiên quyết', 'state' => 'prereq'];
        }
        foreach ($activeSections as $mine) {
            if ((int)$mine['section_id'] === (int)$section['section_id']) continue;
            if (self::sectionsOverlap($section, $mine)) {
                return [
                    'ok' => false,
                    'reason' => 'Trùng lịch với lớp ' . $mine['section_code'],
                    'state' => 'conflict',
                ];
            }
        }
        if ($registeredCredits + (int)$section['credits'] > $maxCredits) {
            return [
                'ok' => false,
                'reason' => 'Vượt giới hạn ' . $maxCredits . ' TC/học kỳ',
                'state' => 'credits',
            ];
        }
        $count = (int)($section['enrolled_count'] ?? 0);
        $max = max(1, (int)($section['max_enrollment'] ?: ($section['capacity'] ?: 64)));
        if ($count >= $max) {
            return ['ok' => false, 'reason' => 'Lớp đã đủ sĩ số tối đa ' . $max, 'state' => 'full'];
        }
        return ['ok' => true, 'reason' => '', 'state' => 'ok'];
    }

    /**
     * Unique courses the student can still register, each with its offered
     * sections. Passed courses and courses already actively registered in the
     * semester are removed; failed/incomplete courses stay when retake is
     * permitted by policy.
     *
     * @return array{courses:array,active:array,results:array,credit:array}
     */
    public static function availableCourses(
        PDO $pdo, string $studentId, int $semesterId, int $periodId, bool $periodOpen,
        string $search = '', int $departmentId = 0
    ): array {
        $results = self::courseAcademicResults($pdo, $studentId);
        $active  = self::activeEnrollments($pdo, $studentId, $semesterId);
        $credit  = self::registrationSummary($pdo, $studentId, $semesterId);

        $hidden = [];
        foreach ($active as $mine) $hidden[(int)$mine['course_id']] = true;
        foreach ($results as $courseId => $res) {
            if ($res['passed'] || ($res['completed'] > 0 && !$res['retake'])) {
                $hidden[(int)$courseId] = true;
            }
        }

        if ($semesterId < 1) {
            return ['courses' => [], 'active' => $active, 'results' => $results, 'credit' => $credit];
        }

        $hasMapping = false;
        if ($periodId > 0) {
            $qm = $pdo->prepare('SELECT COUNT(*) FROM RegistrationPeriodSection WHERE period_id = :pid');
            $qm->execute([':pid' => $periodId]);
            $hasMapping = (int)$qm->fetchColumn() > 0;
        }

        $sql = "SELECT cs.section_id, cs.course_id, cs.section_code, cs.section_status, cs.status_note,
                       cs.schedule, cs.study_days, cs.start_time, cs.end_time, cs.room,
                       cs.min_enrollment, cs.max_enrollment, cs.capacity,
                       c.course_code, c.course_name, c.credits, c.description, c.category,
                       c.department_id, c.prerequisite_course_id,
                       d.department_name, l.full_name AS lecturer_name,
                       (SELECT COUNT(*) FROM Enrollment e2
                         WHERE e2.section_id = cs.section_id
                           AND e2.status IN ('Enrolled','Completed')) AS enrolled_count
                FROM ClassSection cs
                JOIN Course c ON c.course_id = cs.course_id
                JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
                LEFT JOIN Department d ON d.department_id = c.department_id
                JOIN Student s ON s.student_id = :sid
                WHERE cs.semester_id = :sem
                  AND cs.section_status <> 'Cancelled'
                  AND c.is_active = 1
                  AND s.major_id IS NOT NULL
                  AND EXISTS (
                        SELECT 1 FROM Curriculum cur
                        JOIN CurriculumCourse cc ON cc.curriculum_id = cur.curriculum_id
                        WHERE cur.major_id = s.major_id AND cur.admission_year = s.admission_year
                          AND cc.course_id = cs.course_id
                  )";
        $params = [':sid' => $studentId, ':sem' => $semesterId];

        if ($hasMapping) {
            $sql .= ' AND EXISTS (
                        SELECT 1 FROM RegistrationPeriodSection rps
                        WHERE rps.period_id = :pid AND rps.section_id = cs.section_id
                      )';
            $params[':pid'] = $periodId;
        }
        $search = trim($search);
        if ($search !== '') {
            $sql .= ' AND (c.course_code LIKE :q1 OR c.course_name LIKE :q2 OR c.description LIKE :q3)';
            $like = '%' . $search . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        if ($departmentId > 0) {
            $sql .= ' AND c.department_id = :dept';
            $params[':dept'] = $departmentId;
        }
        $sql .= ' ORDER BY c.course_code ASC, cs.section_code ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $courses = [];
        foreach ($stmt->fetchAll() as $row) {
            $courseId = (int)$row['course_id'];
            if (isset($hidden[$courseId])) continue;

            $prereqId = (int)($row['prerequisite_course_id'] ?? 0);
            $prereqPassed = $prereqId < 1 || !empty($results[$prereqId]['passed']);

            if (!isset($courses[$courseId])) {
                $courses[$courseId] = [
                    'course_id'    => $courseId,
                    'course_code'  => $row['course_code'],
                    'course_name'  => $row['course_name'],
                    'credits'      => (int)$row['credits'],
                    'description'  => (string)($row['description'] ?? ''),
                    'category'     => (string)($row['category'] ?? ''),
                    'department_name' => (string)($row['department_name'] ?? ''),
                    'prereq_code'  => '',
                    'prereq_passed'=> true,
                    'sections'     => [],
                    'section_count'=> 0,
                    'open_sections'=> 0,
                    'status'       => 'available',
                    'status_label' => 'Có thể đăng ký',
                    'status_class' => 'green',
                ];
                if ($prereqId > 0) {
                    $pq = $pdo->prepare('SELECT course_code FROM Course WHERE course_id = :cid LIMIT 1');
                    $pq->execute([':cid' => $prereqId]);
                    $courses[$courseId]['prereq_code'] = (string)$pq->fetchColumn();
                }
            }
            $courses[$courseId]['prereq_passed'] = $courses[$courseId]['prereq_passed'] && $prereqPassed;

            $slots = self::sectionSlots($row);
            $dayText = implode(', ', array_unique(array_map(
                static fn($slot) => self::dayLabel((int)$slot['day']),
                $slots
            )));
            $timeText = $slots
                ? substr($slots[0]['start'], 0, 5) . '–' . substr($slots[0]['end'], 0, 5)
                : '';
            $count = (int)$row['enrolled_count'];
            $capacity = self::capacityState($row, $count);
            $meta = self::statusMeta((string)$row['section_status']);
            $availability = self::sectionAvailability(
                $row, $active, (int)$credit['registered'], (int)$credit['max'], $periodOpen, $prereqPassed
            );

            $courses[$courseId]['sections'][] = [
                'section_id'      => (int)$row['section_id'],
                'section_code'    => $row['section_code'],
                'lecturer_name'   => $row['lecturer_name'],
                'day_text'        => $dayText !== '' ? $dayText : 'Chưa xếp lịch',
                'time_text'       => $timeText,
                'room'            => (string)($row['room'] ?? ''),
                'enrolled_count'  => $count,
                'max_enrollment'  => max(1, (int)($row['max_enrollment'] ?: ($row['capacity'] ?: 64))),
                'min_enrollment'  => (int)$row['min_enrollment'],
                'threshold'       => $capacity['threshold'],
                'capacity_short'  => $capacity['short'],
                'capacity_pct'    => $capacity['pct'],
                'section_status'  => (string)$row['section_status'],
                'status_label'    => $meta['label'],
                'status_bg'       => $meta['bg'],
                'status_fg'       => $meta['fg'],
                'eligible'        => $availability['ok'],
                'reason'          => $availability['reason'],
                'eligibility_state' => $availability['state'],
            ];
            $courses[$courseId]['section_count']++;
            if ($availability['ok']) $courses[$courseId]['open_sections']++;
        }

        foreach ($courses as &$course) {
            $sections = $course['sections'];
            if (!$periodOpen) {
                $course['status'] = 'closed';
                $course['status_label'] = 'Chưa mở đợt';
                $course['status_class'] = 'gray';
                continue;
            }
            if ($course['open_sections'] > 0) {
                $course['status'] = 'available';
                $course['status_label'] = 'Có thể đăng ký';
                $course['status_class'] = 'green';
                continue;
            }
            if (!$course['prereq_passed']) {
                $course['status'] = 'prereq';
                $course['status_label'] = 'Thiếu tiên quyết';
                $course['status_class'] = 'gold';
                continue;
            }
            $allFull = true;
            foreach ($sections as $sectionRow) {
                if ($sectionRow['eligibility_state'] !== 'full') { $allFull = false; break; }
            }
            if ($sections && $allFull) {
                $course['status'] = 'full';
                $course['status_label'] = 'Đã đầy';
                $course['status_class'] = 'red';
            } else {
                $course['status'] = 'blocked';
                $course['status_label'] = 'Không khả dụng';
                $course['status_class'] = 'gray';
            }
        }
        unset($course);

        return [
            'courses' => array_values($courses),
            'active'  => $active,
            'results' => $results,
            'credit'  => $credit,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Semester credit policy
    // ─────────────────────────────────────────────────────────

    /**
     * Effective min/max registered credits for a student in a semester.
     * A per-student, per-semester exception overrides the global policy.
     */
    public static function creditPolicy(PDO $pdo, string $studentId, int $semesterId): array {
        $min = max(0, SystemConfig::getInt('registration_min_credits', 14));
        $max = max(1, SystemConfig::getInt('registration_max_credits', 24));
        if ($max < $min) $max = $min;

        $reason = null;
        $hasException = false;
        if ($semesterId > 0) {
            $q = $pdo->prepare(
                'SELECT min_credits, max_credits, reason FROM RegistrationCreditException
                 WHERE student_id = :sid AND semester_id = :sem LIMIT 1'
            );
            $q->execute([':sid' => $studentId, ':sem' => $semesterId]);
            if ($row = $q->fetch()) {
                $hasException = true;
                if ($row['min_credits'] !== null) $min = (int)$row['min_credits'];
                if ($row['max_credits'] !== null) $max = (int)$row['max_credits'];
                $reason = $row['reason'];
                if ($max < $min) $max = $min;
            }
        }
        return [
            'min' => $min, 'max' => $max,
            'has_exception' => $hasException, 'exception_reason' => $reason,
        ];
    }

    /** Credits currently registered by a student in a semester. */
    public static function registeredCredits(PDO $pdo, string $studentId, int $semesterId, ?int $excludeSectionId = null): int {
        if ($semesterId < 1) return 0;
        $sql = "SELECT COALESCE(SUM(c.credits), 0)
                FROM Enrollment e
                JOIN ClassSection cs ON cs.section_id = e.section_id
                JOIN Course c ON c.course_id = cs.course_id
                WHERE e.student_id = :sid AND cs.semester_id = :sem
                  AND e.status IN ('Enrolled','Completed')";
        $params = [':sid' => $studentId, ':sem' => $semesterId];
        if ($excludeSectionId !== null) {
            $sql .= ' AND cs.section_id <> :ex';
            $params[':ex'] = $excludeSectionId;
        }
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return (int)$q->fetchColumn();
    }

    /** Full credit picture used by student/admin UIs. */
    public static function registrationSummary(PDO $pdo, string $studentId, int $semesterId, ?int $excludeSectionId = null): array {
        $policy = self::creditPolicy($pdo, $studentId, $semesterId);
        $registered = self::registeredCredits($pdo, $studentId, $semesterId, $excludeSectionId);
        return [
            'min' => $policy['min'],
            'max' => $policy['max'],
            'registered' => $registered,
            'remaining' => max(0, $policy['max'] - $registered),
            'below_min' => $registered > 0 && $registered < $policy['min'],
            'below_min_gap' => max(0, $policy['min'] - $registered),
            'over_max' => $registered > $policy['max'],
            'has_exception' => $policy['has_exception'],
            'exception_reason' => $policy['exception_reason'],
        ];
    }

    /** Create/update an admin-authorized credit-limit exception. */
    public static function saveCreditException(
        PDO $pdo, string $studentId, int $semesterId, ?int $min, ?int $max,
        string $reason, int $accountId
    ): array {
        if ($studentId === '' || $semesterId < 1) {
            return ['ok' => false, 'message' => 'Vui lòng chọn sinh viên và học kỳ.'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập lý do ngoại lệ.'];
        }
        if ($min !== null && ($min < 0 || $min > 60)) {
            return ['ok' => false, 'message' => 'Tín chỉ tối thiểu phải từ 0 đến 60.'];
        }
        if ($max !== null && ($max < 1 || $max > 60)) {
            return ['ok' => false, 'message' => 'Tín chỉ tối đa phải từ 1 đến 60.'];
        }
        if ($min !== null && $max !== null && $max < $min) {
            return ['ok' => false, 'message' => 'Tín chỉ tối đa phải lớn hơn hoặc bằng tối thiểu.'];
        }
        $q = $pdo->prepare('SELECT 1 FROM Student WHERE student_id=:sid LIMIT 1');
        $q->execute([':sid' => $studentId]);
        if (!$q->fetch()) return ['ok' => false, 'message' => 'Sinh viên không tồn tại.'];
        $q = $pdo->prepare('SELECT 1 FROM Semester WHERE semester_id=:sem LIMIT 1');
        $q->execute([':sem' => $semesterId]);
        if (!$q->fetch()) return ['ok' => false, 'message' => 'Học kỳ không tồn tại.'];

        $old = $pdo->prepare('SELECT * FROM RegistrationCreditException WHERE student_id=:sid AND semester_id=:sem LIMIT 1');
        $old->execute([':sid' => $studentId, ':sem' => $semesterId]);
        $oldRow = $old->fetch();

        $pdo->prepare(
            'INSERT INTO RegistrationCreditException (student_id, semester_id, min_credits, max_credits, reason, approved_by)
             VALUES (:sid, :sem, :mn, :mx, :reason, :by)
             ON DUPLICATE KEY UPDATE min_credits=VALUES(min_credits), max_credits=VALUES(max_credits),
                                     reason=VALUES(reason), approved_by=VALUES(approved_by)'
        )->execute([
            ':sid' => $studentId, ':sem' => $semesterId,
            ':mn' => $min, ':mx' => $max, ':reason' => $reason, ':by' => $accountId,
        ]);
        AcademicAlertService::writeAuditLog(
            $pdo, $accountId, $oldRow ? 'UPDATE_CREDIT_EXCEPTION' : 'CREATE_CREDIT_EXCEPTION',
            'RegistrationCreditException', $studentId . ':' . $semesterId,
            $oldRow ? json_encode($oldRow, JSON_UNESCAPED_UNICODE) : null,
            json_encode(['min' => $min, 'max' => $max, 'reason' => $reason], JSON_UNESCAPED_UNICODE)
        );
        return ['ok' => true, 'message' => 'Đã lưu ngoại lệ tín chỉ cho sinh viên ' . $studentId . '.'];
    }

    public static function deleteCreditException(PDO $pdo, int $exceptionId, int $accountId): array {
        $q = $pdo->prepare('SELECT * FROM RegistrationCreditException WHERE exception_id=:id LIMIT 1');
        $q->execute([':id' => $exceptionId]);
        $row = $q->fetch();
        if (!$row) return ['ok' => false, 'message' => 'Không tìm thấy ngoại lệ.'];
        $pdo->prepare('DELETE FROM RegistrationCreditException WHERE exception_id=:id')->execute([':id' => $exceptionId]);
        AcademicAlertService::writeAuditLog(
            $pdo, $accountId, 'DELETE_CREDIT_EXCEPTION', 'RegistrationCreditException',
            $row['student_id'] . ':' . $row['semester_id'], json_encode($row, JSON_UNESCAPED_UNICODE), null
        );
        return ['ok' => true, 'message' => 'Đã xóa ngoại lệ tín chỉ.'];
    }

    // ─────────────────────────────────────────────────────────
    // Atomic registration
    // ─────────────────────────────────────────────────────────

    /**
     * @return array{ok:bool,message:string,section:?array,status_changed:bool}
     */
    public static function register(PDO $pdo, string $studentId, int $accountId, int $sectionId, ?int $periodId = null): array {
        if ($sectionId < 1) return ['ok' => false, 'message' => 'Lớp học phần không hợp lệ.', 'section' => null, 'status_changed' => false];

        $student = self::studentMajor($pdo, $studentId);
        if (!$student) return ['ok' => false, 'message' => 'Không tìm thấy sinh viên.', 'section' => null, 'status_changed' => false];
        if (!$student['major_id']) return ['ok' => false, 'message' => 'Tài khoản chưa được gán ngành học.', 'section' => null, 'status_changed' => false];

        $statusChanged = false;
        $section = null;
        $confirmedNow = false;

        try {
            $pdo->beginTransaction();

            // Period: explicit id or the student's currently open period.
            if ($periodId) {
                $qp = $pdo->prepare(
                    "SELECT rp.* FROM registrationperiod rp
                     WHERE rp.period_id = :pid FOR UPDATE"
                );
                $qp->execute([':pid' => $periodId]);
            } else {
                $qp = $pdo->prepare(
                    "SELECT rp.* FROM registrationperiod rp
                     WHERE rp.status='Open' AND NOW() BETWEEN rp.start_at AND rp.end_at
                       AND (NOT EXISTS (SELECT 1 FROM registrationperiodmajor rpm WHERE rpm.period_id=rp.period_id)
                            OR EXISTS (SELECT 1 FROM registrationperiodmajor rpm
                                       WHERE rpm.period_id=rp.period_id AND rpm.major_id=:mid))
                     ORDER BY rp.start_at DESC LIMIT 1 FOR UPDATE"
                );
                $qp->execute([':mid' => (int)$student['major_id']]);
            }
            $period = $qp->fetch();

            if (!$period) {
                throw new RuntimeException('Hiện không có đợt đăng ký nào đang mở.');
            }
            if ($period['status'] !== 'Open' || time() < strtotime($period['start_at']) || time() > strtotime($period['end_at'])) {
                throw new RuntimeException('Ngoài thời gian đăng ký của đợt này.');
            }

            $majorRestricted = (int)$pdo->query(
                'SELECT COUNT(*) FROM registrationperiodmajor WHERE period_id=' . (int)$period['period_id']
            )->fetchColumn() > 0;
            if ($majorRestricted) {
                $qm = $pdo->prepare(
                    'SELECT 1 FROM registrationperiodmajor WHERE period_id=:pid AND major_id=:mid LIMIT 1'
                );
                $qm->execute([':pid' => $period['period_id'], ':mid' => (int)$student['major_id']]);
                if (!$qm->fetch()) throw new RuntimeException('Ngành của bạn không thuộc đợt đăng ký này.');
            }

            // Serialise concurrent registrations for this student as well.
            $lockStudent = $pdo->prepare('SELECT student_id FROM Student WHERE student_id = :sid FOR UPDATE');
            $lockStudent->execute([':sid' => $studentId]);

            $hasSectionMapping = (int)$pdo->query(
                'SELECT COUNT(*) FROM registrationperiodsection WHERE period_id=' . (int)$period['period_id']
            )->fetchColumn() > 0;

            // Lock the section row: serialises concurrent registrations.
            $qs = $pdo->prepare(
                'SELECT cs.*, c.course_code, c.course_name, c.credits, c.prerequisite_course_id,
                        c.is_active AS course_active, l.full_name AS lecturer_name,
                        sem.semester_id, sem.semester_name, sem.academic_year
                 FROM ClassSection cs
                 JOIN Course c ON c.course_id = cs.course_id
                 JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
                 JOIN Semester sem ON sem.semester_id = cs.semester_id
                 WHERE cs.section_id = :sid FOR UPDATE'
            );
            $qs->execute([':sid' => $sectionId]);
            $section = $qs->fetch();

            if (!$section) throw new RuntimeException('Lớp học phần không tồn tại.');
            if ((int)$section['semester_id'] !== (int)$period['semester_id']) {
                throw new RuntimeException('Lớp học phần không thuộc học kỳ của đợt đăng ký.');
            }
            if ($hasSectionMapping) {
                $qps = $pdo->prepare(
                    'SELECT 1 FROM registrationperiodsection WHERE period_id=:pid AND section_id=:sid LIMIT 1'
                );
                $qps->execute([':pid' => $period['period_id'], ':sid' => $sectionId]);
                if (!$qps->fetch()) throw new RuntimeException('Lớp này không nằm trong danh sách mở đăng ký.');
            }
            if ($section['section_status'] === 'Cancelled') throw new RuntimeException('Lớp học phần đã bị hủy.');
            if ($section['section_status'] === 'Insufficient') throw new RuntimeException('Lớp không đủ sĩ số để mở.');
            if (!(int)$section['course_active']) throw new RuntimeException('Môn học đã ngừng mở đăng ký.');

            // Course must belong to the student's curriculum.
            $qc = $pdo->prepare(
                'SELECT 1 FROM student s
                 JOIN curriculum cur ON cur.major_id = s.major_id AND cur.admission_year = s.admission_year
                 JOIN curriculumcourse cc ON cc.curriculum_id = cur.curriculum_id
                 WHERE s.student_id = :sid AND cc.course_id = :cid LIMIT 1'
            );
            $qc->execute([':sid' => $studentId, ':cid' => (int)$section['course_id']]);
            if (!$qc->fetch()) throw new RuntimeException('Môn học không thuộc chương trình đào tạo của bạn.');

            // Passing an official result hides the course; a failed/incomplete
            // record stays retakeable when policy allows it.
            $results      = self::courseAcademicResults($pdo, $studentId);
            $courseResult = $results[(int)$section['course_id']] ?? null;
            if ($courseResult && $courseResult['passed']) {
                throw new RuntimeException('Bạn đã hoàn thành môn ' . $section['course_code'] . '.');
            }
            if ($courseResult && $courseResult['completed'] > 0 && !$courseResult['retake']) {
                throw new RuntimeException('Môn ' . $section['course_code'] . ' chưa đủ điều kiện học lại theo quy chế.');
            }

            // Already taking the course in this semester (another section)?
            $qsame = $pdo->prepare(
                "SELECT cs.section_code FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id=e.section_id
                 WHERE e.student_id=:sid AND cs.course_id=:cid AND cs.semester_id=:sem
                   AND e.status='Enrolled' LIMIT 1"
            );
            $qsame->execute([':sid' => $studentId, ':cid' => (int)$section['course_id'], ':sem' => (int)$section['semester_id']]);
            if ($same = $qsame->fetch()) {
                throw new RuntimeException('Bạn đã đăng ký môn ' . $section['course_code'] . ' ở lớp ' . $same['section_code'] . '.');
            }

            // Existing row for this exact section.
            $qexist = $pdo->prepare('SELECT enrollment_id, status FROM Enrollment WHERE student_id=:sid AND section_id=:sec LIMIT 1');
            $qexist->execute([':sid' => $studentId, ':sec' => $sectionId]);
            $existing = $qexist->fetch();
            if ($existing && $existing['status'] !== 'Withdrawn') {
                throw new RuntimeException('Bạn đã đăng ký lớp này rồi.');
            }

            // Prerequisite: must be completed with a passing final score.
            if (!empty($section['prerequisite_course_id'])) {
                $qpre = $pdo->prepare(
                    "SELECT e.enrollment_id
                     FROM Enrollment e
                     JOIN ClassSection cs ON cs.section_id=e.section_id
                     JOIN Assessment a ON a.section_id=cs.section_id
                     JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
                     WHERE e.student_id=:sid AND cs.course_id=:pre AND e.status='Completed' AND g.score IS NOT NULL
                     GROUP BY e.enrollment_id
                     HAVING ROUND(SUM(g.score * a.weight_percent / 100) / 10, 1) >= :thr
                     LIMIT 1"
                );
                $qpre->execute([
                    ':sid' => $studentId,
                    ':pre' => (int)$section['prerequisite_course_id'],
                    ':thr' => self::passingScoreThreshold($pdo),
                ]);
                if (!$qpre->fetch()) {
                    $qpn = $pdo->prepare('SELECT course_code FROM Course WHERE course_id=:id LIMIT 1');
                    $qpn->execute([':id' => (int)$section['prerequisite_course_id']]);
                    throw new RuntimeException('Chưa đạt môn tiên quyết ' . (string)$qpn->fetchColumn() . '.');
                }
            }

            // Schedule conflict with any other enrolled section in this semester.
            $qen = $pdo->prepare(
                "SELECT cs.section_id, cs.section_code, cs.schedule, cs.study_days, cs.start_time, cs.end_time,
                        c.course_code
                 FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id=e.section_id
                 JOIN Course c ON c.course_id=cs.course_id
                 WHERE e.student_id=:sid AND cs.semester_id=:sem AND e.status='Enrolled'
                   AND cs.section_id <> :sec"
            );
            $qen->execute([':sid' => $studentId, ':sem' => (int)$section['semester_id'], ':sec' => $sectionId]);
            foreach ($qen->fetchAll() as $other) {
                if (self::sectionsOverlap($section, $other)) {
                    throw new RuntimeException('Trùng lịch học với lớp ' . $other['section_code']
                        . ' (' . $other['course_code'] . ').');
                }
            }

            // Semester credit limit (per-student exception overrides the policy).
            $credit = self::registrationSummary($pdo, $studentId, (int)$section['semester_id'], $sectionId);
            $newTotal = $credit['registered'] + (int)$section['credits'];
            if ($newTotal > $credit['max']) {
                throw new RuntimeException(
                    'Vượt giới hạn tín chỉ: đã đăng ký ' . $credit['registered'] . ' TC, môn này '
                    . (int)$section['credits'] . ' TC, tối đa ' . $credit['max']
                    . ' TC/học kỳ. Hãy hủy bớt môn hoặc liên hệ Phòng Đào tạo.'
                );
            }

            // Capacity check while holding the section lock.
            $count = self::enrolledCount($pdo, $sectionId);
            $max   = max(1, (int)($section['max_enrollment'] ?: ($section['capacity'] ?: 64)));
            if ($count >= $max) {
                throw new RuntimeException('Lớp đã đủ sĩ số tối đa ' . $max . '.');
            }

            if ($existing) {
                $pdo->prepare("UPDATE Enrollment SET status='Enrolled', enrollment_date=NOW() WHERE enrollment_id=:eid")
                    ->execute([':eid' => $existing['enrollment_id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO Enrollment (student_id, section_id, status, enrollment_date)
                     VALUES (:sid, :sec, 'Enrolled', NOW())"
                )->execute([':sid' => $studentId, ':sec' => $sectionId]);
            }

            $count++;
            $threshold = self::openingThreshold((int)($section['min_enrollment'] ?: 48));

            if ($section['section_status'] === 'Pending' && $count >= $threshold) {
                $pdo->prepare(
                    "UPDATE ClassSection
                     SET section_status='Confirmed', status_changed_at=NOW(),
                         status_note=:note
                     WHERE section_id=:sid AND section_status='Pending'"
                )->execute([
                    ':note' => 'Tự động mở lớp khi đạt ngưỡng ' . $threshold . ' sinh viên.',
                    ':sid'  => $sectionId,
                ]);
                $statusChanged = true;
                $confirmedNow  = true;
            }

            AcademicAlertService::writeAuditLog(
                $pdo, $accountId, 'STUDENT_REGISTER', 'Enrollment', $studentId,
                null,
                json_encode([
                    'period_id'  => (int)$period['period_id'],
                    'section_id' => $sectionId,
                    'course'     => $section['course_code'],
                    'count_after'=> $count,
                ], JSON_UNESCAPED_UNICODE)
            );

            $pdo->commit();

            self::recalculateTuition($pdo, $studentId, (int)$section['semester_id']);

            NotificationService::send(
                $accountId, 'Registration',
                '✅ Đăng ký thành công: ' . $section['course_code'],
                'Bạn đã đăng ký lớp ' . $section['section_code'] . ' – ' . $section['course_name'],
                app_url('assets/student/registration.php')
            );

            if ($confirmedNow) {
                try { self::ensureSessionsForSection($pdo, $sectionId); } catch (Throwable) {}
                self::notifySectionStudents(
                    $pdo, $sectionId,
                    '🎉 Lớp đã đủ điều kiện mở: ' . $section['section_code'],
                    'Lớp ' . $section['section_code'] . ' đã đạt ngưỡng mở lớp. Lịch học chính thức đã có trong thời khóa biểu.',
                );
            }

            return [
                'ok'             => true,
                'message'        => 'Đã đăng ký lớp ' . $section['section_code'] . ' – ' . $section['course_name'] . '.',
                'section'        => $section,
                'status_changed' => $statusChanged,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage(), 'section' => $section, 'status_changed' => false];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function drop(PDO $pdo, string $studentId, int $accountId, int $sectionId, ?int $periodId = null): array {
        try {
            $pdo->beginTransaction();

            if ($periodId) {
                $qp = $pdo->prepare('SELECT * FROM registrationperiod WHERE period_id=:pid FOR UPDATE');
                $qp->execute([':pid' => $periodId]);
                $period = $qp->fetch();
                if (!$period) throw new RuntimeException('Đợt đăng ký không tồn tại.');
                if ($period['status'] !== 'Open'
                    || time() < strtotime($period['start_at'])
                    || time() > strtotime($period['end_at'])) {
                    throw new RuntimeException('Ngoài thời gian đăng ký của đợt này.');
                }
            }

            $qs = $pdo->prepare(
                'SELECT cs.section_id, cs.section_code, c.course_code, cs.semester_id, cs.reg_open, cs.reg_close,
                        sem.reg_open AS sem_reg_open, sem.reg_close AS sem_reg_close
                 FROM ClassSection cs
                 JOIN Course c ON c.course_id = cs.course_id
                 JOIN Semester sem ON sem.semester_id = cs.semester_id
                 WHERE cs.section_id = :sid FOR UPDATE'
            );
            $qs->execute([':sid' => $sectionId]);
            $section = $qs->fetch();
            if (!$section) throw new RuntimeException('Lớp học phần không tồn tại.');
            if ($periodId && (int)$section['semester_id'] !== (int)$period['semester_id']) {
                throw new RuntimeException('Lớp học phần không thuộc học kỳ của đợt đăng ký.');
            }

            $q = $pdo->prepare(
                "SELECT enrollment_id, status FROM Enrollment
                 WHERE student_id=:sid AND section_id=:sec FOR UPDATE"
            );
            $q->execute([':sid' => $studentId, ':sec' => $sectionId]);
            $enr = $q->fetch();
            if (!$enr || $enr['status'] !== 'Enrolled') {
                throw new RuntimeException('Bạn không có đăng ký đang học ở lớp này.');
            }

            $open  = $section['reg_open']  ?: $section['sem_reg_open'];
            $close = $section['reg_close'] ?: $section['sem_reg_close'];
            if ($open && $close && (time() < strtotime($open) || time() > strtotime($close))) {
                throw new RuntimeException('Ngoài thời gian được phép hủy đăng ký.');
            }

            $pdo->prepare("UPDATE Enrollment SET status='Withdrawn' WHERE enrollment_id=:eid")
                ->execute([':eid' => $enr['enrollment_id']]);

            AcademicAlertService::writeAuditLog(
                $pdo, $accountId, 'STUDENT_DROP', 'Enrollment', $studentId,
                json_encode(['section_id' => $sectionId], JSON_UNESCAPED_UNICODE), null
            );

            $pdo->commit();

            self::recalculateTuition($pdo, $studentId, (int)$section['semester_id']);

            NotificationService::send(
                $accountId, 'Registration',
                'Đã hủy đăng ký: ' . $section['section_code'],
                'Bạn đã hủy đăng ký lớp ' . $section['section_code'] . '. Trạng thái mở lớp (nếu đã xác nhận) được giữ nguyên.',
                app_url('assets/student/registration.php')
            );

            return ['ok' => true, 'message' => 'Đã hủy đăng ký lớp ' . $section['section_code'] . '.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────
    // Period finalisation and authorised overrides
    // ─────────────────────────────────────────────────────────

    /**
     * Mark pending sections below the opening threshold as insufficient,
     * confirm any section that already crossed the threshold, then close
     * the period. Confirmed sections never change back automatically.
     *
     * @return array{ok:bool,message:string,confirmed:int,insufficient:int}
     */
    public static function finalizePeriod(PDO $pdo, int $periodId, int $adminAccountId): array {
        $confirmed = 0;
        $insufficient = 0;
        try {
            $pdo->beginTransaction();

            $q = $pdo->prepare('SELECT * FROM registrationperiod WHERE period_id=:id FOR UPDATE');
            $q->execute([':id' => $periodId]);
            $period = $q->fetch();
            if (!$period) throw new RuntimeException('Đợt đăng ký không tồn tại.');

            $sections = $pdo->prepare(
                'SELECT cs.* FROM registrationperiodsection rps
                 JOIN ClassSection cs ON cs.section_id = rps.section_id
                 WHERE rps.period_id = :pid FOR UPDATE'
            );
            $sections->execute([':pid' => $periodId]);
            $rows = $sections->fetchAll();

            // No explicit section mapping means the period applies to the
            // whole semester, so finalisation must consider every section.
            if (empty($rows)) {
                $all = $pdo->prepare(
                    "SELECT * FROM ClassSection
                     WHERE semester_id=:sem AND section_status<>'Cancelled' FOR UPDATE"
                );
                $all->execute([':sem' => (int)$period['semester_id']]);
                $rows = $all->fetchAll();
            }

            foreach ($rows as $section) {
                if ($section['section_status'] !== 'Pending') continue;
                $count = self::enrolledCount($pdo, (int)$section['section_id']);
                $threshold = self::openingThreshold((int)($section['min_enrollment'] ?: 48));

                if ($count >= $threshold) {
                    $pdo->prepare(
                        "UPDATE ClassSection SET section_status='Confirmed', status_changed_at=NOW(), status_note=:n
                         WHERE section_id=:sid"
                    )->execute([
                        ':n'   => 'Xác nhận khi chốt đợt: đạt ngưỡng ' . $threshold . '.',
                        ':sid' => (int)$section['section_id'],
                    ]);
                    $confirmed++;
                    try { self::ensureSessionsForSection($pdo, (int)$section['section_id']); } catch (Throwable) {}
                } else {
                    $pdo->prepare(
                        "UPDATE ClassSection SET section_status='Insufficient', status_changed_at=NOW(), status_note=:n
                         WHERE section_id=:sid"
                    )->execute([
                        ':n'   => 'Không đạt ngưỡng mở lớp ' . $threshold . ' khi chốt đợt.',
                        ':sid' => (int)$section['section_id'],
                    ]);
                    $insufficient++;
                    self::notifySectionStudents(
                        $pdo, (int)$section['section_id'],
                        '⚠️ Lớp chưa đủ sĩ số: ' . $section['section_code'],
                        'Lớp ' . $section['section_code'] . ' chưa đạt ngưỡng ' . $threshold
                        . ' sinh viên. Nhà trường sẽ thông báo phương án tiếp theo.',
                    );
                }
            }

            $pdo->prepare("UPDATE registrationperiod SET status='Closed' WHERE period_id=:id")
                ->execute([':id' => $periodId]);

            AcademicAlertService::writeAuditLog(
                $pdo, $adminAccountId, 'FINALIZE_REGISTRATION_PERIOD', 'RegistrationPeriod', (string)$periodId,
                null,
                json_encode(['confirmed' => $confirmed, 'insufficient' => $insufficient], JSON_UNESCAPED_UNICODE)
            );

            $pdo->commit();
            return [
                'ok' => true,
                'message' => 'Đã chốt đợt đăng ký: ' . $confirmed . ' lớp được xác nhận, '
                    . $insufficient . ' lớp không đủ sĩ số.',
                'confirmed' => $confirmed,
                'insufficient' => $insufficient,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage(), 'confirmed' => 0, 'insufficient' => 0];
        }
    }

    /**
     * Authorised admin status override (audited). Confirmed/Pending may be
     * set manually; Cancelled/Insufficient notify the enrolled students.
     *
     * @return array{ok:bool,message:string}
     */
    public static function overrideSectionStatus(PDO $pdo, int $sectionId, string $newStatus, string $note, int $adminAccountId): array {
        $allowed = ['Pending','Confirmed','Insufficient','Cancelled'];
        if (!in_array($newStatus, $allowed, true)) return ['ok' => false, 'message' => 'Trạng thái không hợp lệ.'];

        $q = $pdo->prepare('SELECT * FROM ClassSection WHERE section_id=:sid LIMIT 1');
        $q->execute([':sid' => $sectionId]);
        $section = $q->fetch();
        if (!$section) return ['ok' => false, 'message' => 'Lớp học phần không tồn tại.'];

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE ClassSection
                 SET section_status=:st, status_changed_at=NOW(), status_note=:note
                 WHERE section_id=:sid'
            )->execute([':st' => $newStatus, ':note' => mb_substr($note, 0, 255), ':sid' => $sectionId]);

            AcademicAlertService::writeAuditLog(
                $pdo, $adminAccountId, 'OVERRIDE_SECTION_STATUS', 'ClassSection', (string)$sectionId,
                json_encode(['section_status' => $section['section_status']], JSON_UNESCAPED_UNICODE),
                json_encode(['section_status' => $newStatus, 'note' => $note], JSON_UNESCAPED_UNICODE)
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => 'Không thể đổi trạng thái: ' . $e->getMessage()];
        }

        if ($newStatus === 'Confirmed') {
            try { self::ensureSessionsForSection($pdo, $sectionId); } catch (Throwable) {}
        }
        if (in_array($newStatus, ['Cancelled','Insufficient'], true)) {
            $meta = self::statusMeta($newStatus);
            self::notifySectionStudents(
                $pdo, $sectionId,
                '⚠️ Lớp ' . $section['section_code'] . ': ' . $meta['label'],
                $note !== '' ? $note : 'Nhà trường đã cập nhật trạng thái lớp học phần.',
            );
        }
        return ['ok' => true, 'message' => 'Đã cập nhật trạng thái lớp ' . $section['section_code'] . ' thành "' . self::statusMeta($newStatus)['label'] . '".'];
    }

    // ─────────────────────────────────────────────────────────
    // Notifications + tuition
    // ─────────────────────────────────────────────────────────

    public static function notifySectionStudents(PDO $pdo, int $sectionId, string $title, string $body, string $link = ''): void {
        try {
            $q = $pdo->prepare(
                "SELECT a.account_id FROM Enrollment e
                 JOIN Account a ON a.student_id = e.student_id
                 WHERE e.section_id=:sid AND e.status='Enrolled' AND a.is_active=1"
            );
            $q->execute([':sid' => $sectionId]);
            foreach ($q->fetchAll() as $row) {
                NotificationService::send((int)$row['account_id'], 'Registration', $title, $body,
                    $link !== '' ? $link : app_url('assets/student/registration.php'));
            }
        } catch (Throwable) {}
    }

    public static function recalculateTuition(PDO $pdo, string $studentId, int $semesterId): void {
        try {
            $q = $pdo->prepare(
                "SELECT COALESCE(SUM(c.credits),0) FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id=e.section_id
                 JOIN Course c ON c.course_id=cs.course_id
                 WHERE e.student_id=:sid AND cs.semester_id=:sem AND e.status IN ('Enrolled','Completed')"
            );
            $q->execute([':sid' => $studentId, ':sem' => $semesterId]);
            $credits = (int)$q->fetchColumn();

            $rate = SystemConfig::getInt('tuition_per_credit_vnd', 500000);
            if ($rate <= 0) $rate = 500000;
            $amount = $credits * $rate;

            $pdo->prepare(
                'INSERT INTO Tuition (student_id, semester_id, credits, amount_vnd, due_date, status)
                 VALUES (:sid, :sem, :tc, :amount,
                         DATE_ADD((SELECT start_date FROM Semester WHERE semester_id=:sem2), INTERVAL 30 DAY),
                         \'Unpaid\')
                 ON DUPLICATE KEY UPDATE credits=VALUES(credits), amount_vnd=VALUES(amount_vnd)'
            )->execute([
                ':sid' => $studentId, ':sem' => $semesterId, ':sem2' => $semesterId,
                ':tc' => $credits, ':amount' => $amount,
            ]);
        } catch (Throwable) {}
    }
}
