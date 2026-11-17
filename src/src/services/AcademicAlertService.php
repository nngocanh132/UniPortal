<?php
/**
 * UniPortal – Academic Alert Service
 *
 * Core Feature 2: Automatic Academic Warning & Attendance Monitoring
 *
 * Key rules:
 *   - Attendance is checked PER COURSE (per section), not aggregated.
 *   - Prohibition rule: absent/total > 20% (strictly greater than, not >=).
 *   - Three warning levels:
 *       Level 1 – Attendance Attention (absent% >= warn_pct, default 15%)
 *       Level 2 – Critical Attendance Warning (absent% >= ban_pct, default 20%)
 *       Level 3 – Examination Prohibition Risk (absent% > ban_pct, i.e. >20%)
 *   - Warnings are auto-created and auto-resolved per course.
 *   - Duplicate warnings are prevented (one active warning per student+section).
 *   - Students receive notifications immediately.
 *   - All actions are audit-logged.
 *
 * recalcGpa($student_id) → GPA + per-course attendance check + warnings.
 * checkAttendanceForSection($student_id, $section_id) → per-course check.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SystemConfig.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/AttendanceService.php';

class AcademicAlertService {

    // ── Main entry: recalc GPA + check all attendance ─────────
    public static function recalcGpa(string $student_id): array {
        $pdo = get_db();

        // 1. Compute cumulative GPA
        $stmt = $pdo->prepare(
            'SELECT
                ROUND(SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits), 0), 2) AS cum_gpa,
                SUM(c.credits) AS earned_credits
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id  = e.section_id
             JOIN Course        c  ON c.course_id    = cs.course_id
             JOIN Assessment    a  ON a.section_id   = cs.section_id
             JOIN Grade         g  ON g.enrollment_id = e.enrollment_id
                                  AND g.assessment_id  = a.assessment_id
             JOIN GradeScale    gs ON (g.score / 10) BETWEEN gs.min_score AND gs.max_score
             WHERE e.student_id = :sid
               AND e.status     = \'Completed\''
        );
        $stmt->execute([':sid' => $student_id]);
        $row   = $stmt->fetch();
        $gpa   = $row['cum_gpa']      !== null ? (float)$row['cum_gpa']  : null;
        $cred  = (int)($row['earned_credits'] ?? 0);

        $warningsCreated = 0;

        // 2. GPA-based warnings (university-wide, not per-course)
        if ($gpa !== null) {
            $suspensionGpa = SystemConfig::getFloat('gpa_suspension_threshold', 1.50);
            $warningsCreated += self::checkAndCreateWarning(
                $pdo, $student_id, 'LowGPA',
                SystemConfig::isLowGpa($gpa),
                sprintf(
                    "GPA tích lũy hiện tại là %.2f – dưới ngưỡng cảnh báo %.2f. Cần cải thiện kết quả học tập.\n" .
                    "Đề xuất: Gặp cố vấn học tập để xây dựng kế hoạch cải thiện GPA.",
                    $gpa,
                    SystemConfig::getFloat('gpa_warning_threshold', 2.00)
                ),
                $gpa < $suspensionGpa ? 3 : 2
            );

            $warningsCreated += self::checkAndCreateWarning(
                $pdo, $student_id, 'GraduationRisk',
                $gpa < $suspensionGpa,
                sprintf(
                    "GPA tích lũy %.2f thấp nguy hiểm. Có nguy cơ đình chỉ học hoặc không đủ điều kiện tốt nghiệp.\n" .
                    "Đề xuất: Liên hệ ngay cố vấn học tập và Phòng Đào tạo để được hỗ trợ.",
                    $gpa
                ),
                3
            );
        }

        // 3. Per-course attendance check (Active semester)
        $warningsCreated += self::checkAllAttendance($student_id);

        // 4. Per-course performance check (grades, missing work, trend)
        $warningsCreated += self::checkAllPerformance($student_id);

        return [
            'gpa'              => $gpa,
            'earned_credits'   => $cred,
            'warnings_created' => $warningsCreated,
        ];
    }

    // ── Check attendance for ALL enrolled sections ─────────────
    // Checks ALL semesters where the student has Enrolled/Completed status
    // AND has actual attendance records — not just the Active semester.
    public static function checkAllAttendance(string $student_id): int {
        $pdo = get_db();

        // Get all sections where student is enrolled AND official sessions exist
        $stmt = $pdo->prepare(
            'SELECT DISTINCT e.enrollment_id, e.section_id,
                    c.course_code, c.course_name, c.credits
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c        ON c.course_id   = cs.course_id
             WHERE e.student_id = :sid
               AND e.status IN (\'Enrolled\', \'Completed\')
               AND EXISTS (
                   SELECT 1 FROM AttendanceSession ats
                   WHERE ats.section_id = e.section_id
                     AND ats.status <> \'Cancelled\'
               )'
        );
        $stmt->execute([':sid' => $student_id]);
        $sections = $stmt->fetchAll();

        $created = 0;
        foreach ($sections as $sec) {
            $created += self::checkAttendanceForSection(
                $student_id,
                (int)$sec['section_id'],
                (int)$sec['enrollment_id'],
                $sec['course_code'],
                $sec['course_name'],
                (int)$sec['credits']
            );
        }
        return $created;
    }

    // ── Check attendance for a single course/section ─────────
    // Implements the strict >20% rule per course.
    public static function checkAttendanceForSection(
        string $student_id,
        int    $section_id,
        int    $enrollment_id,
        string $course_code,
        string $course_name,
        int    $credits = 3
    ): int {
        $pdo = get_db();

        // Official-session based statistics (cancelled/future/pending excluded).
        $policy = AttendanceService::policy();
        $stats  = AttendanceService::studentSectionStats($pdo, $enrollment_id, $section_id, $policy);

        if ($stats['official_total'] === 0 || $stats['completed_total'] === 0) return 0;

        $absent  = (int)$stats['absent_counted'];
        $total   = (int)$stats['official_total'];
        $absentPct = (float)$stats['absent_pct'];
        $banPct  = (float)$policy['ban_pct'];
        $remainingAllowed = (int)$stats['remaining_allowed'];

        $level = 0;
        $condition = false;
        $msg = '';

        if ($stats['status'] === 'ineligible') {
            // Strictly above the ban threshold – examination prohibition.
            $level = 3;
            $condition = true;
            $msg = sprintf(
                "🚫 CẤM THI RỦI RO: Môn %s (%s) — Tỷ lệ vắng %d/%d buổi chính thức (%.1f%%) > %d%%.\n" .
                "Bạn có thể bị CẤM THI môn này theo quy chế đào tạo.\n" .
                "Đề xuất: Liên hệ ngay giảng viên và Phòng Đào tạo để được hướng dẫn xử lý.",
                $course_name, $course_code, $absent, $total, $absentPct, (int)$banPct
            );
        } elseif ($stats['status'] === 'eligible' && (int)$stats['risk_level'] === 2) {
            // Exactly at the threshold – still eligible, but critical.
            $level = 2;
            $condition = true;
            $msg = sprintf(
                "⚠️ CẢNH BÁO NGHIÊM TRỌNG: Môn %s (%s) — Tỷ lệ vắng %d/%d buổi = %.1f%%.\n" .
                "Bạn đang ở ngưỡng cấm thi (%d%%). Vắng thêm 1 buổi sẽ bị cấm thi!\n" .
                "Đề xuất: Tham dự đầy đủ 100%% các buổi còn lại, xin phép hợp lệ nếu bắt buộc vắng.",
                $course_name, $course_code, $absent, $total, $absentPct, (int)$banPct
            );
        } elseif ($stats['status'] === 'at_risk') {
            // Approaching the threshold.
            $level = 1;
            $condition = true;
            $msg = sprintf(
                "📋 Chú ý chuyên cần: Môn %s (%s) — Tỷ lệ vắng %d/%d buổi (%.1f%%).\n" .
                "Còn %d buổi vắng được phép trước khi bị cấm thi.\n" .
                "Đề xuất: Theo dõi chuyên cần và không vắng thêm buổi nào.",
                $course_name, $course_code, $absent, $total, $absentPct,
                $remainingAllowed
            );
        } elseif ($stats['status'] === 'eligible' && $remainingAllowed === 1 && $absent > 0) {
            // One more absence reaches the ban threshold – gentle reminder.
            $level = 1;
            $condition = true;
            $msg = sprintf(
                "📋 Nhắc nhở chuyên cần: Môn %s (%s) — Đã vắng %d/%d buổi (%.1f%%).\n" .
                "Chỉ còn %d buổi vắng được phép trước khi bị cấm thi.\n" .
                "Đề xuất: Tham dự đầy đủ các buổi còn lại và theo dõi chuyên cần.",
                $course_name, $course_code, $absent, $total, $absentPct,
                $remainingAllowed
            );
        }

        // Find existing per-course Attendance warning for this section
        $find = $pdo->prepare(
            'SELECT warning_id, warning_level, message FROM AcademicWarning
             WHERE student_id = :sid
               AND warning_type = \'Attendance\'
               AND section_id = :sec
               AND status = \'Active\'
             LIMIT 1'
        );
        $find->execute([':sid' => $student_id, ':sec' => $section_id]);
        $existing = $find->fetch();

        if ($condition && !$existing) {
            // Create new per-course warning
            $ins = $pdo->prepare(
                'INSERT INTO AcademicWarning
                    (student_id, warning_type, message, status, is_read, section_id, warning_level)
                 VALUES (:sid, \'Attendance\', :msg, \'Active\', FALSE, :sec, :lvl)'
            );
            $ins->execute([
                ':sid'  => $student_id,
                ':msg'  => $msg,
                ':sec'  => $section_id,
                ':lvl'  => $level,
            ]);

            // Notify student
            self::notifyStudent($student_id, 'Cảnh báo chuyên cần', $msg);
            self::writeAuditLog($pdo, 25, 'CREATE_ATTENDANCE_WARNING',
                'AcademicWarning', $student_id, null,
                json_encode(['section_id' => $section_id, 'level' => $level, 'course' => $course_code]));
            return 1;
        }

        if ($condition && $existing) {
            // Keep the stored message in sync with the current numbers; only
            // jump back to unread when the severity level changes.
            $levelChanged = (int)$existing['warning_level'] !== $level;
            if ($levelChanged || $existing['message'] !== $msg) {
                $upd = $pdo->prepare(
                    'UPDATE AcademicWarning SET message = :msg, warning_level = :lvl'
                    . ($levelChanged ? ', is_read = FALSE' : '')
                    . ' WHERE warning_id = :wid'
                );
                $upd->execute([':msg' => $msg, ':lvl' => $level, ':wid' => $existing['warning_id']]);
                if ($levelChanged) {
                    self::notifyStudent($student_id, 'Cập nhật cảnh báo chuyên cần', $msg);
                }
            }
            return 0;
        }

        if (!$condition && $existing) {
            // Auto-resolve when attendance improves
            $res = $pdo->prepare(
                'UPDATE AcademicWarning SET status = \'Resolved\', is_read = TRUE
                 WHERE warning_id = :wid'
            );
            $res->execute([':wid' => $existing['warning_id']]);
            self::writeAuditLog($pdo, 25, 'RESOLVE_ATTENDANCE_WARNING',
                'AcademicWarning', $student_id,
                json_encode(['section_id' => $section_id]), null);
            return 0;
        }

        return 0;
    }

    // ── Performance check: all currently enrolled sections ────
    public static function checkAllPerformance(string $student_id): int {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            "SELECT e.enrollment_id, e.section_id, c.course_code, c.course_name
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c        ON c.course_id   = cs.course_id
             WHERE e.student_id = :sid
               AND e.status = 'Enrolled'
               AND cs.section_status <> 'Cancelled'"
        );
        $stmt->execute([':sid' => $student_id]);

        $created = 0;
        foreach ($stmt->fetchAll() as $sec) {
            $created += self::checkPerformanceForSection(
                $student_id,
                (int)$sec['section_id'],
                (int)$sec['enrollment_id'],
                $sec['course_code'],
                $sec['course_name']
            );
        }
        return $created;
    }

    // ── Performance check for one course/section ──────────────
    // Uses the real assessment weights and recorded Grade rows; never
    // invents scores. Thresholds come from SystemConfig.
    public static function checkPerformanceForSection(
        string $student_id,
        int    $section_id,
        int    $enrollment_id,
        string $course_code,
        string $course_name
    ): int {
        $pdo = get_db();

        $threshold     = SystemConfig::getFloat('grade_warning_threshold', 5.00);
        $compThreshold = SystemConfig::getFloat('grade_component_threshold', 5.00);
        $declineDelta  = SystemConfig::getFloat('grade_decline_delta', 1.00);
        $target        = SystemConfig::getFloat('grade_target_score', 5.50);
        $missingLimit  = max(1, SystemConfig::getInt('assignment_missing_warning', 2));

        $aStmt = $pdo->prepare(
            'SELECT a.assessment_id, a.assessment_name, a.weight_percent, a.due_date, g.score
             FROM Assessment a
             LEFT JOIN Grade g ON g.assessment_id = a.assessment_id
                              AND g.enrollment_id = :eid
             WHERE a.section_id = :sec
             ORDER BY a.due_date IS NULL, a.due_date, a.assessment_id'
        );
        $aStmt->execute([':eid' => $enrollment_id, ':sec' => $section_id]);
        $assessments = $aStmt->fetchAll();
        if (!$assessments) return 0;

        $graded = [];
        $weightedSum = 0.0;
        $gradedWeight = 0.0;
        $remainingWeight = 0.0;
        $totalWeight = 0.0;
        $lowComponents = [];
        $lowMidterm = null;
        $preByDate = null;

        foreach ($assessments as $a) {
            $w = (float)$a['weight_percent'];
            $totalWeight += $w;
            if ($a['score'] === null) {
                $remainingWeight += $w;
                continue;
            }
            $s10 = round((float)$a['score'] / 10, 2);
            $name = (string)$a['assessment_name'];
            $weightedSum += $s10 * $w;
            $gradedWeight += $w;
            $graded[] = ['name' => $name, 'score' => $s10];

            $isMidterm = mb_stripos($name, 'giữa kỳ') !== false;
            $isCoursework = mb_stripos($name, 'quá trình') !== false
                         || mb_stripos($name, 'thường xuyên') !== false
                         || mb_stripos($name, 'chuyên cần') !== false;
            if ($isMidterm && $s10 < $compThreshold) {
                if ($lowMidterm === null || $s10 < $lowMidterm['score']) {
                    $lowMidterm = ['name' => $name, 'score' => $s10];
                }
            } elseif ($isCoursework && $s10 < $compThreshold) {
                $lowComponents[] = ['name' => $name, 'score' => $s10];
            }

            // Declining trend between consecutive graded assessments.
            if ($preByDate !== null && ($preByDate - $s10) >= $declineDelta) {
                $trend = ['from' => $preByDate, 'to' => $s10];
            }
            $preByDate = $s10;
        }
        $trend = $trend ?? null;

        $avg10 = $gradedWeight > 0 ? round($weightedSum / $gradedWeight, 2) : null;
        $requiredFinal = ($remainingWeight > 0 && $avg10 !== null)
            ? round((($target * $totalWeight) - $weightedSum) / $remainingWeight, 2)
            : null;

        // Missing (overdue, unsubmitted) assignments for this enrollment.
        $mStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM Assignment a
             LEFT JOIN Submission s ON s.assignment_id = a.assignment_id
                                   AND s.enrollment_id = :eid
             WHERE a.section_id = :sec
               AND a.is_deleted = 0
               AND a.status = 'Published'
               AND a.due_date < NOW()
               AND s.submission_id IS NULL"
        );
        $mStmt->execute([':eid' => $enrollment_id, ':sec' => $section_id]);
        $missing = (int)$mStmt->fetchColumn();

        // ── Decide severity ──────────────────────────────────
        $belowThreshold = $avg10 !== null && $avg10 < $threshold;
        $impossibleTarget = $requiredFinal !== null && $requiredFinal > 10 && $gradedWeight >= 30;
        $hasIssues = $belowThreshold || $lowMidterm !== null || $lowComponents
                  || $trend !== null || $missing >= $missingLimit || $impossibleTarget;

        $find = $pdo->prepare(
            "SELECT warning_id, warning_level, message FROM AcademicWarning
             WHERE student_id = :sid
               AND warning_type = 'LowGrade'
               AND section_id = :sec
               AND status = 'Active'
             LIMIT 1"
        );
        $find->execute([':sid' => $student_id, ':sec' => $section_id]);
        $existing = $find->fetch();

        if (!$hasIssues) {
            if ($existing) {
                $pdo->prepare("UPDATE AcademicWarning SET status='Resolved', is_read=TRUE WHERE warning_id=:wid")
                    ->execute([':wid' => $existing['warning_id']]);
                self::writeAuditLog($pdo, 25, 'RESOLVE_GRADE_WARNING', 'AcademicWarning', $student_id,
                    json_encode(['section_id' => $section_id]), null);
            }
            return 0;
        }

        $level = 1;
        if ($impossibleTarget || ($belowThreshold && $avg10 !== null && $avg10 < $threshold - 1.0)) {
            $level = 3;
        } elseif ($belowThreshold || $lowMidterm !== null || $missing >= $missingLimit) {
            $level = 2;
        }
        $levelLabel = [1 => 'Nhắc nhở', 2 => 'Cần chú ý', 3 => 'Nguy cơ cao'][$level];

        $details = [];
        if ($avg10 !== null) {
            $details[] = sprintf('Điểm trung bình hiện tại: %.2f/10 (đã chấm %d%% trọng số).',
                $avg10, (int)round($gradedWeight));
        }
        if ($lowComponents) {
            $c = $lowComponents[0];
            $details[] = sprintf('%s chỉ đạt %.2f/10 (ngưỡng %.2f/10).', $c['name'], $c['score'], $compThreshold);
        }
        if ($lowMidterm !== null) {
            $details[] = sprintf('%s đạt %.2f/10, dưới ngưỡng %.2f/10.',
                $lowMidterm['name'], $lowMidterm['score'], $compThreshold);
        }
        if ($trend !== null) {
            $details[] = sprintf('Điểm có xu hướng giảm: từ %.2f xuống %.2f/10.', $trend['from'], $trend['to']);
        }
        if ($missing >= $missingLimit) {
            $details[] = sprintf('Có %d bài tập quá hạn chưa nộp.', $missing);
        }
        if ($requiredFinal !== null) {
            $details[] = $requiredFinal > 10
                ? sprintf('Mục tiêu %.2f/10 không thể đạt dù phần còn lại đạt tối đa.', $target)
                : sprintf('Để đạt mục tiêu %.2f/10, phần còn lại cần %.2f/10.', $target, max(0, $requiredFinal));
        }

        $mainIssue = $level === 3
            ? ($impossibleTarget ? 'khó đạt điểm mục tiêu' : 'kết quả học tập rất thấp')
            : ($level === 2
                ? ($belowThreshold ? 'điểm trung bình dưới ngưỡng' : ($lowMidterm !== null ? 'điểm giữa kỳ thấp' : 'nhiều bài tập chưa nộp'))
                : ($lowComponents ? 'điểm quá trình cần cải thiện' : 'kết quả học tập cần theo dõi'));
        $action = $level === 3
            ? 'Liên hệ giảng viên để được hỗ trợ, hoàn thành ngay các bài còn thiếu và xây dựng kế hoạch học tập trước khi thi cuối kỳ.'
            : ($level === 2
                ? 'Ôn lại các phần kiến thức bị điểm thấp, nộp bổ sung bài tập còn thiếu và trao đổi với giảng viên.'
                : 'Theo dõi điểm số thường xuyên và cải thiện ở các bài đánh giá tiếp theo.');

        $msg = sprintf("Môn %s (%s) – %s: %s.\n", $course_name, $course_code, $levelLabel, $mainIssue)
             . implode("\n", $details)
             . "\nĐề xuất: " . $action;

        if (!$existing) {
            $ins = $pdo->prepare(
                "INSERT INTO AcademicWarning
                    (student_id, warning_type, warning_level, message, status, is_read, section_id)
                 VALUES (:sid, 'LowGrade', :lvl, :msg, 'Active', FALSE, :sec)"
            );
            $ins->execute([':sid' => $student_id, ':lvl' => $level, ':msg' => $msg, ':sec' => $section_id]);

            self::notifyStudent($student_id, 'Cảnh báo kết quả học tập', $msg);
            self::writeAuditLog($pdo, 25, 'CREATE_GRADE_WARNING', 'AcademicWarning', $student_id, null,
                json_encode(['section_id' => $section_id, 'level' => $level, 'course' => $course_code]));
            return 1;
        }

        $levelChanged = (int)$existing['warning_level'] !== $level;
        if ($levelChanged || $existing['message'] !== $msg) {
            $upd = $pdo->prepare(
                'UPDATE AcademicWarning SET message = :msg, warning_level = :lvl'
                . ($levelChanged ? ', is_read = FALSE' : '')
                . ' WHERE warning_id = :wid'
            );
            $upd->execute([':msg' => $msg, ':lvl' => $level, ':wid' => $existing['warning_id']]);
            if ($levelChanged) {
                self::notifyStudent($student_id, 'Cập nhật cảnh báo kết quả học tập', $msg);
            }
        }
        return 0;
    }

    // ── Check condition → create or auto-resolve (non-attendance) ─
    private static function checkAndCreateWarning(
        PDO    $pdo,
        string $student_id,
        string $warning_type,
        bool   $condition,
        string $message,
        int    $level = 1
    ): int {
        $find = $pdo->prepare(
            'SELECT warning_id, warning_level, message FROM AcademicWarning
             WHERE student_id=:sid AND warning_type=:type AND status=\'Active\'
               AND section_id IS NULL
             LIMIT 1'
        );
        $find->execute([':sid' => $student_id, ':type' => $warning_type]);
        $existing = $find->fetch();

        if ($condition && !$existing) {
            $ins = $pdo->prepare(
                'INSERT INTO AcademicWarning (student_id, warning_type, message, status, is_read, warning_level)
                 VALUES (:sid, :type, :msg, \'Active\', FALSE, :lvl)'
            );
            $ins->execute([':sid' => $student_id, ':type' => $warning_type, ':msg' => $message, ':lvl' => $level]);

            self::notifyStudent($student_id,
                $warning_type === 'LowGPA' ? 'GPA thấp' : 'Nguy cơ tốt nghiệp',
                $message);
            self::writeAuditLog($pdo, 25, 'CREATE_WARNING', 'AcademicWarning', $student_id,
                null, json_encode(['type' => $warning_type, 'level' => $level, 'msg' => $message]));
            return 1;
        }

        if ($condition && $existing) {
            $levelChanged = (int)$existing['warning_level'] !== $level;
            if ($levelChanged || $existing['message'] !== $message) {
                $upd = $pdo->prepare(
                    'UPDATE AcademicWarning SET message = :msg, warning_level = :lvl'
                    . ($levelChanged ? ', is_read = FALSE' : '')
                    . ' WHERE warning_id = :wid'
                );
                $upd->execute([':msg' => $message, ':lvl' => $level, ':wid' => $existing['warning_id']]);
                if ($levelChanged) {
                    self::notifyStudent($student_id,
                        $warning_type === 'LowGPA' ? 'Cập nhật cảnh báo GPA' : 'Cập nhật nguy cơ tốt nghiệp',
                        $message);
                }
            }
            return 0;
        }

        if (!$condition && $existing) {
            $res = $pdo->prepare(
                'UPDATE AcademicWarning SET status=\'Resolved\', is_read=TRUE WHERE warning_id=:wid'
            );
            $res->execute([':wid' => $existing['warning_id']]);
        }

        return 0;
    }

    // ── Notify student helper ──────────────────────────────────
    private static function notifyStudent(string $student_id, string $title, string $body): void {
        try {
            $pdo = get_db();
            $accStmt = $pdo->prepare('SELECT account_id FROM Account WHERE student_id=:sid LIMIT 1');
            $accStmt->execute([':sid' => $student_id]);
            $accId = (int)$accStmt->fetchColumn();
            if ($accId) {
                NotificationService::send($accId, 'Warning',
                    '⚠️ ' . $title, $body,
                    '/UniPortal/public/assets/student/alerts.php');
            }
        } catch (Throwable) {}
    }

    // ── Trigger after grade save ──────────────────────────────
    public static function afterGradeSave(int $enrollment_id): void {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'SELECT e.student_id, e.section_id, c.course_code, c.course_name, c.credits
                 FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id = e.section_id
                 JOIN Course c ON c.course_id = cs.course_id
                 WHERE e.enrollment_id=:eid LIMIT 1'
            );
            $stmt->execute([':eid' => $enrollment_id]);
            $row = $stmt->fetch();
            if ($row) {
                self::recalcGpa($row['student_id']);
            }
        } catch (Throwable) {}
    }

    // ── Trigger after attendance save ─────────────────────────
    // Checks attendance for the specific section that was updated.
    public static function afterAttendanceSave(int $enrollment_id): void {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'SELECT e.student_id, e.section_id, c.course_code, c.course_name, c.credits
                 FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id = e.section_id
                 JOIN Course c ON c.course_id = cs.course_id
                 WHERE e.enrollment_id=:eid LIMIT 1'
            );
            $stmt->execute([':eid' => $enrollment_id]);
            $row = $stmt->fetch();
            if ($row) {
                self::checkAttendanceForSection(
                    $row['student_id'],
                    (int)$row['section_id'],
                    $enrollment_id,
                    $row['course_code'],
                    $row['course_name'],
                    (int)$row['credits']
                );
            }
        } catch (Throwable) {}
    }

    // ── Get attendance risk summary for a student in a section ─
    // Official-session based; works for any semester, not just Active.
    public static function getAttendanceRisk(string $student_id, int $section_id): array {
        $pdo = get_db();
        $q = $pdo->prepare(
            "SELECT enrollment_id FROM Enrollment
             WHERE student_id = :sid AND section_id = :sec
               AND status IN ('Enrolled','Completed')
             ORDER BY enrollment_id DESC LIMIT 1"
        );
        $q->execute([':sid' => $student_id, ':sec' => $section_id]);
        $enrollmentId = (int)$q->fetchColumn();
        if (!$enrollmentId) {
            $policy = AttendanceService::policy();
            return [
                'level' => 0, 'status' => 'no_data', 'total' => 0, 'official_total' => 0,
                'completed_total' => 0, 'absent' => 0, 'late' => 0, 'present' => 0,
                'excused' => 0, 'pct' => 0, 'remaining' => 0,
                'ban_pct' => $policy['ban_pct'], 'ban_threshold' => 0,
                'status_label' => 'Chưa có dữ liệu',
            ];
        }

        $s = AttendanceService::studentSectionStats($pdo, $enrollmentId, $section_id);
        return [
            'level'          => (int)$s['risk_level'],
            'status'         => $s['status'] === 'ineligible' ? 'prohibition'
                                : (($s['status'] === 'eligible' && (int)$s['risk_level'] === 2) ? 'critical'
                                : ($s['status'] === 'at_risk' ? 'attention'
                                : ($s['status'] === 'no_data' ? 'no_data' : 'ok'))),
            'total'          => (int)$s['completed_total'],
            'official_total' => (int)$s['official_total'],
            'completed_total'=> (int)$s['completed_total'],
            'absent'         => (int)$s['absent_counted'],
            'late'           => (int)$s['late'],
            'present'        => (int)$s['present'],
            'excused'        => (int)$s['excused'],
            'pct'            => (float)$s['absent_pct'],
            'ban_pct'        => (float)$s['ban_pct'],
            'ban_threshold'  => (int)$s['ban_threshold'],
            'remaining'      => (int)$s['remaining_allowed'],
            'status_label'   => $s['status_label'],
            'explanation'    => $s['explanation'],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────
    private static function getActiveSemesterId(PDO $pdo): ?int {
        $row = $pdo->query(
            "SELECT semester_id FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1"
        )->fetch();
        return $row ? (int)$row['semester_id'] : null;
    }

    public static function writeAuditLog(
        PDO    $pdo,
        int    $actor_id,
        string $action,
        string $target_entity,
        string $target_id,
        ?string $old_json = null,
        ?string $new_json = null
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO AuditLog
                    (actor_id, action, target_entity, target_id,
                     old_value_json, new_value_json, ip_address)
                 VALUES (:actor, :action, :entity, :target,
                         :old, :new, :ip)'
            );
            $stmt->execute([
                ':actor'  => $actor_id,
                ':action' => $action,
                ':entity' => $target_entity,
                ':target' => $target_id,
                ':old'    => $old_json,
                ':new'    => $new_json,
                ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (Throwable) {}
    }
}
