<?php
/**
 * UniPortal – GPA Advisor
 *
 * Hybrid GPA planning engine:
 *   1. Reproduces the university GPA rules exactly (Grade.score 0–100,
 *      GradeScale 0–10 → 4-point, weighted by credits, Completed only).
 *   2. Applies the configurable retake policy for simulations.
 *   3. Calculates feasibility, roadmap, retake priorities and what-if
 *      scenarios. Never writes official Grade/Enrollment records.
 *
 * Official cumulative GPA continues to come from GpaModel. All projection
 * numbers here are explicitly estimates.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Gpa.php';
require_once __DIR__ . '/GpaPlanner.php';
require_once __DIR__ . '/../services/SystemConfig.php';

class GpaAdvisor {

    public const POLICY_COUNT_ALL = 'count_all';
    public const POLICY_REPLACE   = 'replace';
    public const POLICY_BEST      = 'best';

    // Lowest passing grade point on the 4-point scale (D); F is 0.
    private const PASS_GRADE_POINT = 1.0;

    // ─────────────────────────────────────────────────────────
    // Configuration
    // ─────────────────────────────────────────────────────────

    public static function policy(): string {
        $p = SystemConfig::get('retake_policy', self::POLICY_COUNT_ALL);
        return in_array($p, [self::POLICY_COUNT_ALL, self::POLICY_REPLACE, self::POLICY_BEST], true)
            ? $p : self::POLICY_COUNT_ALL;
    }

    public static function policyLabel(?string $policy = null): string {
        return match ($policy ?? self::policy()) {
            self::POLICY_REPLACE => 'Điểm lần thi lại thay thế điểm cũ',
            self::POLICY_BEST    => 'Chỉ tính điểm cao nhất của môn',
            default              => 'Tính tất cả các lần học (hiện hành)',
        };
    }

    public static function requiredCredits(): int {
        return max(1, SystemConfig::getInt('required_credits', 130));
    }

    public static function passGpa(): float {
        return SystemConfig::getFloat('grad_pass_gpa', 2.00);
    }

    public static function retakeAllowedMaxGpa(): float {
        return max(0.0, min(4.0, SystemConfig::getFloat('retake_allowed_max_gpa', 2.0)));
    }

    public static function planSemesterCredits(): int {
        return max(3, min(30, SystemConfig::getInt('plan_credits_per_semester', 15)));
    }

    public static function excludedCodes(): array {
        $raw   = SystemConfig::get('gpa_excluded_codes', '');
        $parts = array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', $raw)));
        return array_values(array_unique($parts));
    }

    // ─────────────────────────────────────────────────────────
    // Grade scale helpers (exact same boundary rule as GpaModel)
    // ─────────────────────────────────────────────────────────

    public static function scoreToGradeExact(float $score10, array $scale): array {
        foreach ($scale as $row) {
            $min = (float)$row['min_score'];
            $max = (float)$row['max_score'];
            if ($score10 >= $min && ($score10 < $max || $max >= 10.0)) {
                return $row;
            }
        }
        return ['letter_grade' => 'F', 'grade_point' => 0.0, 'min_score' => 0.0, 'max_score' => 4.0];
    }

    public static function gradePointForLetter(string $letter, array $scale): ?float {
        foreach ($scale as $row) {
            if ($row['letter_grade'] === $letter) {
                return (float)$row['grade_point'];
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────
    // Academic record
    // ─────────────────────────────────────────────────────────

    /**
     * Completed attempts with the exact GPA-relevant final score.
     * Courses configured as excluded stay in the list but carry
     * counts_gpa = false so they still count toward graduation credits.
     */
    public static function getCompletedAttempts(string $studentId): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT e.enrollment_id, e.section_id,
                    c.course_id, c.course_code, c.course_name, c.credits, c.is_active,
                    cs.section_code,
                    sem.semester_id, sem.semester_name, sem.academic_year, sem.start_date,
                    ROUND(SUM(g.score * a.weight_percent / 100) / 10, 1) AS final_score_10
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c        ON c.course_id   = cs.course_id
             JOIN Semester sem    ON sem.semester_id = cs.semester_id
             JOIN Assessment a    ON a.section_id  = e.section_id
             JOIN Grade g         ON g.enrollment_id = e.enrollment_id
                                  AND g.assessment_id = a.assessment_id
             WHERE e.student_id = :sid
               AND e.status     = \'Completed\'
               AND g.score IS NOT NULL
             GROUP BY e.enrollment_id, e.section_id,
                      c.course_id, c.course_code, c.course_name, c.credits, c.is_active,
                      cs.section_code, sem.semester_id, sem.semester_name,
                      sem.academic_year, sem.start_date
             ORDER BY sem.start_date ASC, e.enrollment_id ASC'
        );
        $stmt->execute([':sid' => $studentId]);

        $scale    = GpaPlanner::getGradeScale();
        $excluded = self::excludedCodes();
        $attempts = [];

        foreach ($stmt->fetchAll() as $row) {
            $score = (float)$row['final_score_10'];
            $grade = self::scoreToGradeExact($score, $scale);
            $code  = strtoupper($row['course_code']);

            $attempts[] = [
                'enrollment_id' => (int)$row['enrollment_id'],
                'section_id'    => (int)$row['section_id'],
                'course_id'     => (int)$row['course_id'],
                'course_code'   => $row['course_code'],
                'course_name'   => $row['course_name'],
                'credits'       => (int)$row['credits'],
                'course_active' => (bool)$row['is_active'],
                'section_code'  => $row['section_code'],
                'semester_id'   => (int)$row['semester_id'],
                'semester_name' => $row['semester_name'],
                'academic_year' => $row['academic_year'],
                'start_date'    => $row['start_date'],
                'final_score_10'=> $score,
                'letter_grade'  => $grade['letter_grade'],
                'grade_point'   => (float)$grade['grade_point'],
                'counts_gpa'    => !in_array($code, $excluded, true),
            ];
        }

        return $attempts;
    }

    /** Best attempt per course (used for graduation credits / eligibility). */
    public static function groupByCourse(array $attempts): array {
        $groups = [];
        foreach ($attempts as $a) {
            $code = strtoupper($a['course_code']);
            if (!isset($groups[$code])) {
                $groups[$code] = ['course_code' => $a['course_code'], 'attempts' => []];
            }
            $groups[$code]['attempts'][] = $a;
        }
        foreach ($groups as &$g) {
            usort($g['attempts'], static fn($x, $y) => strcmp($x['start_date'], $y['start_date'])
                ?: ($x['enrollment_id'] <=> $y['enrollment_id']));
            $best = $g['attempts'][0];
            foreach ($g['attempts'] as $a) {
                if ($a['grade_point'] > $best['grade_point']) $best = $a;
            }
            $g['best']          = $best;
            $g['last']          = end($g['attempts']);
            $g['course_name']   = $best['course_name'];
            $g['credits']       = (int)$best['credits'];
            $g['course_active'] = (bool)$best['course_active'];
            $g['counts_gpa']    = (bool)$best['counts_gpa'];
            $g['best_gpa']      = (float)$best['grade_point'];
            $g['best_letter']   = $best['letter_grade'];
        }
        return $groups;
    }

    /**
     * Apply a retake policy to the completed attempts.
     * Returns raw points/credits so projections stay exact.
     */
    public static function policyBase(array $attempts, string $policy): array {
        $credits = 0;
        $points  = 0.0;

        if ($policy === self::POLICY_COUNT_ALL) {
            foreach ($attempts as $a) {
                if (!$a['counts_gpa']) continue;
                $credits += (int)$a['credits'];
                $points  += (float)$a['grade_point'] * (int)$a['credits'];
            }
        } else {
            foreach (self::groupByCourse($attempts) as $g) {
                if (!$g['counts_gpa']) continue;
                $chosen = $policy === self::POLICY_BEST ? $g['best'] : $g['last'];
                $credits += (int)$chosen['credits'];
                $points  += (float)$chosen['grade_point'] * (int)$chosen['credits'];
            }
        }

        return [
            'credits' => (int)$credits,
            'points'  => round($points, 4),
            'gpa'     => $credits > 0 ? round($points / $credits, 2) : null,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Profile
    // ─────────────────────────────────────────────────────────

    public static function getProfile(string $studentId): array {
        $attempts = self::getCompletedAttempts($studentId);
        $groups   = self::groupByCourse($attempts);
        $policy   = self::policy();
        $base     = self::policyBase($attempts, $policy);

        $official   = GpaModel::getCumulative($studentId);
        $required   = self::requiredCredits();
        $passGpa    = self::passGpa();

        // Graduation credits come straight from the transcript: every completed,
        // passed enrollment counts once. Grouping by course code here collapsed
        // retaken courses and under-reported credits already earned.
        $graduationEarned = 0;
        foreach ($attempts as $a) {
            if ((float)$a['grade_point'] >= self::PASS_GRADE_POINT) {
                $graduationEarned += (int)$a['credits'];
            }
        }
        $remaining = max(0, $required - $graduationEarned);

        $maxPossible = null;
        if ($base['credits'] + $remaining > 0) {
            $maxPossible = round(($base['points'] + 4.0 * $remaining)
                                 / ($base['credits'] + $remaining), 2);
        }

        $repeated = array_values(array_filter($groups, static fn($g) => count($g['attempts']) > 1));

        return [
            'student_id'        => $studentId,
            'attempts'          => $attempts,
            'groups'            => $groups,
            'policy'            => $policy,
            'policy_label'      => self::policyLabel($policy),
            'base_credits'      => $base['credits'],
            'base_points'       => $base['points'],
            'current_gpa'       => $base['gpa'],
            'official_gpa'      => $official['cumulative_gpa'] !== null ? (float)$official['cumulative_gpa'] : null,
            'official_credits'  => (int)$official['earned_credits'],
            'required_credits'  => $required,
            'graduation_earned' => $graduationEarned,
            'remaining_credits' => $remaining,
            'max_possible_gpa'  => $maxPossible,
            'pass_gpa'          => $passGpa,
            'retake_max_gpa'    => self::retakeAllowedMaxGpa(),
            'excluded_codes'    => self::excludedCodes(),
            'has_attempts'      => !empty($attempts),
            'repeated_courses'  => $repeated,
            'current_enrolled'  => self::getCurrentEnrollments($studentId),
        ];
    }

    public static function getCurrentEnrollments(string $studentId): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT e.enrollment_id, e.section_id,
                    c.course_id, c.course_code, c.course_name, c.credits,
                    cs.section_code,
                    sem.semester_id, sem.semester_name, sem.academic_year, sem.status AS semester_status
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c        ON c.course_id   = cs.course_id
             JOIN Semester sem    ON sem.semester_id = cs.semester_id
             WHERE e.student_id = :sid AND e.status = \'Enrolled\'
             ORDER BY sem.start_date DESC, c.course_code ASC'
        );
        $stmt->execute([':sid' => $studentId]);
        return $stmt->fetchAll();
    }

    // ─────────────────────────────────────────────────────────
    // Feasibility / required GPA
    // ─────────────────────────────────────────────────────────

    public static function analyze(array $profile, float $target): array {
        $target   = max(0.0, min(4.0, $target));
        $credits  = (int)$profile['base_credits'];
        $points   = (float)$profile['base_points'];
        $remaining= (int)$profile['remaining_credits'];
        $current  = $profile['current_gpa'];

        if ($credits === 0 && $remaining === 0) {
            return [
                'target' => $target, 'current_gpa' => null, 'required_future_gpa' => null,
                'raw_required_future_gpa' => null, 'max_possible_gpa' => null,
                'feasibility' => 'no_data', 'is_achieved' => false,
                'is_achievable' => false, 'gap' => null,
                'projected_cum_gpa' => null,
                'formula_numerator' => 0, 'formula_denominator' => 0,
            ];
        }

        if ($remaining <= 0) {
            $achieved = $current !== null && $current >= $target - 0.0001;
            return [
                'target' => $target, 'current_gpa' => $current, 'required_future_gpa' => null,
                'raw_required_future_gpa' => null, 'max_possible_gpa' => $current,
                'feasibility' => $achieved ? 'achieved' : 'no_future',
                'is_achieved' => $achieved, 'is_achievable' => $achieved,
                'gap' => $current !== null ? round($target - $current, 2) : null,
                'projected_cum_gpa' => $current,
                'formula_numerator' => 0, 'formula_denominator' => 0,
            ];
        }

        if ($current !== null && $current >= $target - 0.0001) {
            return [
                'target' => $target, 'current_gpa' => $current, 'required_future_gpa' => 0.0,
                'raw_required_future_gpa' => 0.0, 'max_possible_gpa' => $profile['max_possible_gpa'],
                'feasibility' => 'achieved', 'is_achieved' => true, 'is_achievable' => true,
                'gap' => round($target - $current, 2),
                'projected_cum_gpa' => $current,
                'formula_numerator' => 0, 'formula_denominator' => $remaining,
            ];
        }

        $denominator = $credits + $remaining;
        $numerator   = $target * $denominator - $points;
        $rawRequired = $denominator > 0 ? $numerator / $remaining : null;
        $required    = $rawRequired !== null ? min(4.0, max(0.0, $rawRequired)) : null;

        $max = $profile['max_possible_gpa'];
        if ($current === null) {
            $feasibility = $target <= 4.0 ? 'achievable' : 'unachievable';
        } elseif ($rawRequired !== null && $rawRequired > 4.0) {
            $feasibility = 'unachievable';
        } elseif ($rawRequired !== null && $rawRequired >= 3.70) {
            $feasibility = 'very_difficult';
        } elseif ($rawRequired !== null && $rawRequired >= 3.20) {
            $feasibility = 'difficult';
        } else {
            $feasibility = 'achievable';
        }

        $projected = ($points + ($required ?? 0) * $remaining) / max(1, $denominator);

        return [
            'target'                 => $target,
            'current_gpa'            => $current,
            'required_future_gpa'    => $required,
            'raw_required_future_gpa'=> $rawRequired !== null ? round($rawRequired, 4) : null,
            'max_possible_gpa'       => $max,
            'feasibility'            => $feasibility,
            'is_achieved'            => false,
            'is_achievable'          => $feasibility !== 'unachievable',
            'gap'                    => $current !== null ? round($target - $current, 2) : null,
            'projected_cum_gpa'      => round($projected, 2),
            'formula_numerator'      => round($numerator, 4),
            'formula_denominator'    => $remaining,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Roadmap
    // ─────────────────────────────────────────────────────────

    public static function buildRoadmap(array $profile, array $analysis, ?int $semCredits = null, int $currentSemId = 0, int $currentSemCredits = 0, string $currentSemLabel = ''): array {
        $remaining = (int)$profile['remaining_credits'];
        if ($remaining <= 0) return [];

        $semCredits = $semCredits ?: self::planSemesterCredits();
        $semCredits = max(3, min(30, $semCredits));

        $count = max(1, (int)ceil($remaining / $semCredits));
        $count = min(10, $count);
        $base  = intdiv($remaining, $count);
        $extra = $remaining % $count;
        $chunks = [];
        for ($i = 0; $i < $count; $i++) {
            $chunks[] = $base + ($i < $extra ? 1 : 0);
        }
        // Avoid a last 1-credit semester when possible.
        if (count($chunks) > 1 && $chunks[count($chunks) - 1] < 3) {
            $need = 3 - $chunks[count($chunks) - 1];
            for ($i = count($chunks) - 2; $i >= 0 && $need > 0; $i--) {
                $take = min($need, $chunks[$i] - 3);
                $chunks[$i] -= $take;
                $chunks[count($chunks) - 1] += $take;
                $need -= $take;
            }
        }

        $required = (float)($analysis['required_future_gpa'] ?? 0);
        if (($analysis['feasibility'] ?? '') === 'achieved') {
            $required = (float)($profile['current_gpa'] ?? $analysis['target']);
        }
        $required = max(0.0, min(4.0, $required));

        $targets = self::allocateTargets($required, $chunks);

        $rows       = [];
        $runPoints  = (float)$profile['base_points'];
        $runCredits = (int)$profile['base_credits'];

        foreach ($chunks as $i => $credits) {
            $cum = ($runPoints + $targets[$i] * $credits) / max(1, $runCredits + $credits);
            $label = $i === 0 && $currentSemCredits > 0
                ? 'Học kỳ hiện tại' . ($currentSemLabel !== '' ? ' · ' . $currentSemLabel : '')
                : 'Kế hoạch kỳ ' . ($i + 1);
            $rows[] = [
                'index'         => $i + 1,
                'label'         => $label,
                'credits'       => $credits,
                'target_gpa'    => round($targets[$i], 2),
                'projected_cum' => round($cum, 2),
                'reaches_target'=> $cum >= $analysis['target'] - 0.005,
            ];
            $runPoints  += $targets[$i] * $credits;
            $runCredits += $credits;
        }

        return $rows;
    }

    /**
     * Distribute the required weighted points across semesters with a slight
     * ramp (lighter earlier, stronger later), then correct the total exactly.
     */
    public static function allocateTargets(float $required, array $creditsBySemester): array {
        $n = count($creditsBySemester);
        if ($n === 0) return [];
        $required = max(0.0, min(4.0, $required));

        $factors = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $n > 1 ? $i / ($n - 1) : 0.0;
            $factors[$i] = 0.97 + 0.06 * $t;
        }

        $targets = [];
        foreach ($creditsBySemester as $i => $crd) {
            $targets[$i] = max(0.0, min(4.0, $required * $factors[$i]));
        }

        $totalCredits = array_sum($creditsBySemester);
        $neededPoints = $required * $totalCredits;

        for ($iter = 0; $iter < 12; $iter++) {
            $points = 0.0;
            foreach ($targets as $i => $t) {
                $points += $t * $creditsBySemester[$i];
            }
            $diff = $neededPoints - $points;
            if (abs($diff) < 0.001) break;

            $adjustable = [];
            foreach ($targets as $i => $t) {
                if ($t > 0.005 && $t < 3.995) $adjustable[] = $i;
            }
            if (empty($adjustable)) break;

            $adjustableCredits = 0;
            foreach ($adjustable as $i) $adjustableCredits += $creditsBySemester[$i];
            if ($adjustableCredits <= 0) break;

            $deltaPerCredit = $diff / $adjustableCredits;
            foreach ($adjustable as $i) {
                $targets[$i] = max(0.0, min(4.0, $targets[$i] + $deltaPerCredit));
            }
        }

        return $targets;
    }

    // ─────────────────────────────────────────────────────────
    // Upcoming courses and recommended grades
    // ─────────────────────────────────────────────────────────

    public static function getPlanningCourses(string $studentId): array {
        $pdo   = get_db();
        $scale = GpaPlanner::getGradeScale();
        $excl  = self::excludedCodes();

        $current = [];
        $stmt = $pdo->prepare(
            'SELECT e.enrollment_id, c.course_id, c.course_code, c.course_name, c.credits,
                    cs.section_code, sem.semester_name, sem.academic_year, sem.status AS semester_status,
                    sem.semester_id
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c        ON c.course_id   = cs.course_id
             JOIN Semester sem    ON sem.semester_id = cs.semester_id
             WHERE e.student_id = :sid AND e.status = \'Enrolled\'
             ORDER BY sem.start_date DESC, c.course_code ASC'
        );
        $stmt->execute([':sid' => $studentId]);
        foreach ($stmt->fetchAll() as $row) {
            if (in_array(strtoupper($row['course_code']), $excl, true)) continue;
            $row['is_enrolled'] = true;
            $current[strtoupper($row['course_code'])] = $row;
        }

        $future = [];
        $stmt = $pdo->prepare(
            'SELECT c.course_id, c.course_code, c.course_name, c.credits,
                    cc.semester_recommended, cc.course_type
             FROM Student s
             JOIN Department d ON d.department_id = s.department_id
             JOIN Curriculum cur ON cur.major_code = d.department_code
                 AND cur.admission_year = s.admission_year
             JOIN CurriculumCourse cc ON cc.curriculum_id = cur.curriculum_id
             JOIN Course c ON c.course_id = cc.course_id
             WHERE s.student_id = :sid
               AND c.is_active = TRUE
               AND c.course_id NOT IN (
                   SELECT cs2.course_id
                   FROM Enrollment e2
                   JOIN ClassSection cs2 ON cs2.section_id = e2.section_id
                   WHERE e2.student_id = :sid2
                     AND e2.status IN (\'Completed\', \'Enrolled\')
               )
             ORDER BY cc.semester_recommended ASC, c.course_code ASC'
        );
        $stmt->execute([':sid' => $studentId, ':sid2' => $studentId]);
        foreach ($stmt->fetchAll() as $row) {
            if (in_array(strtoupper($row['course_code']), $excl, true)) continue;
            $row['is_enrolled'] = false;
            $future[strtoupper($row['course_code'])] = $row;
        }

        return array_merge(array_values($current), array_values($future));
    }

    public static function recommendCourseGrades(array $profile, array $analysis, array $courses): array {
        $scale    = GpaPlanner::getGradeScale();
        $needed   = ($analysis['feasibility'] ?? '') === 'achieved'
            ? (float)($profile['current_gpa'] ?? 0)
            : (float)($analysis['required_future_gpa'] ?? 0);
        if (($analysis['feasibility'] ?? '') === 'unachievable') $needed = 4.0;
        $needed = max(0.0, min(4.0, $needed));

        $rows = [];
        foreach ($courses as $c) {
            $tier = GpaPlanner::gpaToMinGrade($scale, $needed);
            $priority = ($c['is_enrolled'] || $needed >= 3.5) ? 'Cao' : 'Trung bình';
            $creditPoints = $tier['grade_point'] * (int)$c['credits'];

            if ($c['is_enrolled']) {
                $reason = 'Môn đang theo học: cần tối thiểu ' . $tier['letter_grade']
                    . ' (' . number_format($tier['grade_point'], 1) . ') để góp '
                    . number_format($creditPoints, 1) . ' điểm GPA.';
            } else {
                $reason = 'Môn chưa hoàn thành trong chương trình: mục tiêu tối thiểu '
                    . $tier['letter_grade'] . ' (' . number_format($tier['grade_point'], 1)
                    . ') giúp duy trì GPA trung bình ' . number_format($needed, 2) . '.';
            }

            $rows[] = [
                'course_code'   => $c['course_code'],
                'course_name'   => $c['course_name'],
                'credits'       => (int)$c['credits'],
                'is_enrolled'   => (bool)$c['is_enrolled'],
                'semester'      => trim(($c['semester_name'] ?? '') . ' ' . ($c['academic_year'] ?? '')),
                'target_letter' => $tier['letter_grade'],
                'target_gpa'    => (float)$tier['grade_point'],
                'min_score_100' => (float)($tier['min_score_100'] ?? $tier['min_score_10'] * 10),
                'priority'      => $priority,
                'reason'        => $reason,
            ];
        }

        usort($rows, static fn($a, $b) => ($b['is_enrolled'] <=> $a['is_enrolled'])
            ?: strcmp($a['course_code'], $b['course_code']));
        return $rows;
    }

    // ─────────────────────────────────────────────────────────
    // Retake simulation
    // ─────────────────────────────────────────────────────────

    /**
     * Apply one improvement to a base state.
     */
    public static function applyImprovement(array $profile, array &$state, string $courseCode, float $newGp, string $policy): array {
        $code   = strtoupper($courseCode);
        $groups = $profile['groups'];
        if (!isset($groups[$code])) {
            return ['ok' => false, 'message' => 'Môn học không có trong hồ sơ.'];
        }
        $g       = $groups[$code];
        $credits = (int)$g['credits'];

        if ($policy === self::POLICY_COUNT_ALL) {
            $state['points']  += $newGp * $credits;
            $state['credits'] += $credits;
            $state['extra_credits'] += $credits;
            return ['ok' => true, 'message' => 'Thêm một lần học mới (' . $credits . ' TC).'];
        }

        if ($policy === self::POLICY_REPLACE) {
            $old = (float)$g['last']['grade_point'];
            $state['points'] += ($newGp - $old) * $credits;
            return ['ok' => true, 'message' => 'Thay điểm lần học gần nhất (' . number_format($old, 1) . ' → ' . number_format($newGp, 1) . ').'];
        }

        // best
        $old = (float)$g['best_gpa'];
        if ($newGp > $old) {
            $state['points'] += ($newGp - $old) * $credits;
            return ['ok' => true, 'message' => 'Cải thiện điểm cao nhất (' . number_format($old, 1) . ' → ' . number_format($newGp, 1) . ').'];
        }
        return ['ok' => true, 'message' => 'Điểm mới không cao hơn điểm tốt nhất hiện tại; GPA không đổi.'];
    }

    public static function simulateImprovement(array $profile, string $courseCode, float $newGp, ?float $futureGpa = null, ?float $target = null): array {
        $policy = $profile['policy'];
        $state  = [
            'credits' => (int)$profile['base_credits'],
            'points'  => (float)$profile['base_points'],
            'extra_credits' => 0,
        ];
        $applied = self::applyImprovement($profile, $state, $courseCode, $newGp, $policy);
        if (!$applied['ok']) return $applied + ['new_gpa' => $profile['current_gpa'], 'delta' => 0.0];

        $remaining = (int)$profile['remaining_credits'];
        $newGpa    = $state['credits'] > 0 ? round($state['points'] / $state['credits'], 2) : null;
        $delta     = ($newGpa !== null && $profile['current_gpa'] !== null)
            ? round($newGpa - $profile['current_gpa'], 2) : 0.0;

        $maxAfter = ($state['credits'] + $remaining) > 0
            ? round(($state['points'] + 4.0 * $remaining) / ($state['credits'] + $remaining), 2) : null;

        $future = $futureGpa ?? ($profile['current_gpa'] ?? 0.0);
        $expected = ($state['credits'] + $remaining) > 0
            ? round(($state['points'] + $future * $remaining) / ($state['credits'] + $remaining), 2) : null;

        $requiredAfter = null;
        if ($target !== null && $remaining > 0) {
            $requiredAfter = round(($target * ($state['credits'] + $remaining) - $state['points']) / $remaining, 3);
        }

        return [
            'ok'            => true,
            'message'       => $applied['message'],
            'new_gpa'       => $newGpa,
            'delta'         => $delta,
            'new_credits'   => $state['credits'],
            'extra_credits' => $state['extra_credits'],
            'max_after'     => $maxAfter,
            'expected_gpa'  => $expected,
            'required_after'=> $requiredAfter,
        ];
    }

    /**
     * Apply a set of improvements and evaluate the expected graduation GPA.
     */
    public static function simulateScenario(array $profile, array $improvements, float $futureGpa, float $target): array {
        $policy = $profile['policy'];
        $state  = [
            'credits' => (int)$profile['base_credits'],
            'points'  => (float)$profile['base_points'],
            'extra_credits' => 0,
        ];
        $remaining = (int)$profile['remaining_credits'];
        $seen = [];
        $applied = [];

        foreach ($improvements as $imp) {
            $code = strtoupper((string)($imp['course_code'] ?? ''));
            $gp   = (float)($imp['new_gpa'] ?? 0);
            if ($code === '' || isset($seen[$code])) continue;
            if ($gp < 0 || $gp > 4) continue;
            if (!isset($profile['groups'][$code])) continue;
            $seen[$code] = true;
            $res = self::applyImprovement($profile, $state, $code, $gp, $policy);
            if ($res['ok']) {
                $applied[] = [
                    'course_code' => $profile['groups'][$code]['course_code'],
                    'course_name' => $profile['groups'][$code]['course_name'],
                    'old_letter'  => $profile['groups'][$code]['best_letter'],
                    'new_gpa'     => round($gp, 1),
                ];
            }
        }

        $totalCredits = $state['credits'] + $remaining;
        $expected     = $totalCredits > 0
            ? round(($state['points'] + $futureGpa * $remaining) / $totalCredits, 2)
            : null;
        $requiredFuture = $remaining > 0
            ? round(($target * $totalCredits - $state['points']) / $remaining, 3)
            : null;
        $maxAfter = $totalCredits > 0
            ? round(($state['points'] + 4.0 * $remaining) / $totalCredits, 2)
            : null;

        $feasibility = 'achievable';
        if ($target <= ($profile['current_gpa'] ?? 0) - 0.0001) {
            $feasibility = 'achieved';
        } elseif ($requiredFuture === null) {
            $feasibility = 'no_future';
        } elseif ($requiredFuture > 4.0) {
            $feasibility = 'unachievable';
        } elseif ($requiredFuture >= 3.70) {
            $feasibility = 'very_difficult';
        } elseif ($requiredFuture >= 3.20) {
            $feasibility = 'difficult';
        }

        return [
            'target'              => $target,
            'future_gpa'          => round($futureGpa, 2),
            'improvements'        => $applied,
            'improvement_count'   => count($applied),
            'improvement_credits' => array_sum(array_map(
                static fn($i) => (int)($profile['groups'][strtoupper($i['course_code'])]['credits'] ?? 0),
                $applied
            )),
            'extra_credits'       => $state['extra_credits'],
            'total_future_credits'=> $remaining,
            'current_gpa'         => $profile['current_gpa'],
            'post_retake_gpa'     => $state['credits'] > 0 ? round($state['points'] / $state['credits'], 2) : null,
            'expected_gpa'        => $expected,
            'required_future_gpa' => $requiredFuture,
            'max_possible_gpa'    => $maxAfter,
            'feasibility'         => $feasibility,
            'is_achievable'       => $feasibility !== 'unachievable',
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Retake candidates / priority
    // ─────────────────────────────────────────────────────────

    public static function getRetakeCandidates(array $profile, array $analysis): array {
        $scale   = GpaPlanner::getGradeScale();
        $passGpa = (float)$profile['pass_gpa'];
        $allowed = (float)$profile['retake_max_gpa'];
        $policy  = $profile['policy'];

        $optionLetters = ['C', 'B', 'B+', 'A', 'A+'];
        $optionTiers   = [];
        foreach ($optionLetters as $letter) {
            $gp = self::gradePointForLetter($letter, $scale);
            if ($gp !== null && $gp >= $passGpa) {
                $row = null;
                foreach ($scale as $s) if ($s['letter_grade'] === $letter) { $row = $s; break; }
                $optionTiers[] = [
                    'letter'    => $letter,
                    'gpa_point' => $gp,
                    'min_score' => (float)($row['min_score'] ?? 0) * 10,
                ];
            }
        }

        $futureAssumption = ($analysis['feasibility'] ?? '') === 'achieved'
            ? (float)($profile['current_gpa'] ?? 0)
            : min(4.0, max(0.0, (float)($analysis['required_future_gpa'] ?? 4.0)));

        $candidates = [];
        foreach ($profile['groups'] as $code => $g) {
            if (!$g['counts_gpa']) continue;
            if ($g['best_gpa'] >= $passGpa) continue;

            $eligible = $g['course_active'] && $g['best_gpa'] < $allowed;
            $options  = [];
            $deltaA   = 0.0;
            $deltaMax = 0.0;
            foreach ($optionTiers as $tier) {
                if ($tier['gpa_point'] <= $g['best_gpa'] + 0.001) continue;
                $sim = self::simulateImprovement($profile, $code, $tier['gpa_point'], $futureAssumption, $analysis['target']);
                $options[] = [
                    'letter'       => $tier['letter'],
                    'gpa_point'    => $tier['gpa_point'],
                    'min_score'    => $tier['min_score'],
                    'delta'        => $sim['delta'],
                    'new_gpa'      => $sim['new_gpa'],
                    'expected_gpa' => $sim['expected_gpa'],
                    'required_after' => $sim['required_after'],
                    'max_after'    => $sim['max_after'],
                ];
                if ($tier['letter'] === 'A')   $deltaA   = (float)$sim['delta'];
                if ($tier['letter'] === 'A+')  $deltaMax = (float)$sim['delta'];
            }

            $candidates[] = [
                'course_code'   => $g['course_code'],
                'course_name'   => $g['course_name'],
                'credits'       => (int)$g['credits'],
                'best_letter'   => $g['best_letter'],
                'best_gpa'      => (float)$g['best_gpa'],
                'attempts'      => $g['attempts'],
                'attempt_count' => count($g['attempts']),
                'eligible'      => $eligible,
                'options'       => $options,
                'delta_a'       => $deltaA,
                'delta_max'     => $deltaMax,
                'required_for_target' => false,
                'priority'      => 'Không đủ điều kiện',
                'reason'        => $eligible
                    ? 'Điểm hiện tại thấp hơn ngưỡng cải thiện.'
                    : 'Điểm hiện tại không thuộc diện được cải thiện theo cấu hình hiện hành.',
            ];
        }

        // Greedy: which retakes are actually needed to make the target possible?
        $maxNoRetake = $profile['max_possible_gpa'];
        $target      = (float)$analysis['target'];
        $feasibleByFuture = $maxNoRetake !== null && $maxNoRetake >= $target - 0.001;
        $already     = ($analysis['feasibility'] ?? '') === 'achieved';

        $reachableWithAll = $already || $feasibleByFuture;

        if (!$already && !$feasibleByFuture) {
            $eligible = array_values(array_filter($candidates, static fn($c) => $c['eligible']));
            usort($eligible, static fn($a, $b) => $b['delta_max'] <=> $a['delta_max']);

            $state = [
                'credits' => (int)$profile['base_credits'],
                'points'  => (float)$profile['base_points'],
                'extra_credits' => 0,
            ];
            $neededCodes = [];
            foreach ($eligible as $c) {
                self::applyImprovement($profile, $state, $c['course_code'], 4.0, $policy);
                $neededCodes[strtoupper($c['course_code'])] = true;
                $maxAfter = ($state['credits'] + $profile['remaining_credits']) > 0
                    ? ($state['points'] + 4.0 * $profile['remaining_credits']) / ($state['credits'] + $profile['remaining_credits'])
                    : 0;
                if ($maxAfter >= $target - 0.001) { $reachableWithAll = true; break; }
            }
            foreach ($candidates as &$c) {
                if (isset($neededCodes[strtoupper($c['course_code'])])) {
                    $c['required_for_target'] = true;
                }
            }
            unset($c);
        }

        // Priority labels and reasons.
        foreach ($candidates as &$c) {
            if (!$c['eligible']) {
                $c['priority'] = 'Không đủ điều kiện';
                $c['reason']   = 'Điểm hiện tại không thuộc diện được cải thiện theo cấu hình hiện hành.';
            } elseif ($c['required_for_target'] && $reachableWithAll) {
                $c['priority'] = 'Rất nên cải thiện';
                $c['reason']   = 'Cần cải thiện môn này để mục tiêu GPA trở nên khả thi về mặt toán học.';
            } elseif ($c['required_for_target']) {
                $c['priority'] = 'Cần cải thiện (chưa đủ)';
                $c['reason']   = 'Môn này nằm trong nhóm cải thiện cần thiết, nhưng ngay cả khi cải thiện tất cả '
                    . 'môn đủ điều kiện lên A+, GPA tối đa vẫn có thể chưa đạt mục tiêu. Cần điều chỉnh mục tiêu hoặc tăng tín chỉ.';
            } elseif ($already) {
                $c['priority'] = 'Không cần ưu tiên';
                $c['reason']   = 'Mục tiêu đã đạt; cải thiện môn này chỉ giúp tăng GPA đệm thêm '
                    . number_format($c['delta_a'], 2) . '.';
            } elseif ($feasibleByFuture) {
                $c['priority'] = $c['delta_a'] >= 0.05 ? 'Nên cải thiện' : 'Không cần ưu tiên';
                $c['reason']   = 'Mục tiêu đã khả thi nếu các tín chỉ còn lại đạt GPA yêu cầu. '
                    . 'Cải thiện môn này làm GPA tăng thêm ' . number_format($c['delta_a'], 2) . '.';
            } else {
                $c['priority'] = 'Không cần ưu tiên';
                $c['reason']   = 'Không nằm trong nhóm cải thiện tối thiểu cần thiết; lợi ích GPA dự kiến '
                    . number_format($c['delta_a'], 2) . '.';
            }
        }
        unset($c);

        // Sort: required first, then eligible with highest benefit, then ineligible.
        usort($candidates, static function ($a, $b) {
            if ($a['required_for_target'] !== $b['required_for_target']) {
                return $b['required_for_target'] <=> $a['required_for_target'];
            }
            if ($a['eligible'] !== $b['eligible']) return $b['eligible'] <=> $a['eligible'];
            return $b['delta_max'] <=> $a['delta_max'];
        });

        return $candidates;
    }

    // ─────────────────────────────────────────────────────────
    // Preset what-if scenarios
    // ─────────────────────────────────────────────────────────

    public static function presetScenarios(array $profile, array $analysis, array $candidates): array {
        $target    = (float)$analysis['target'];
        $futureBase = ($analysis['feasibility'] ?? '') === 'achieved'
            ? (float)($profile['current_gpa'] ?? 0)
            : min(4.0, max(0.0, (float)($analysis['required_future_gpa'] ?? 4.0)));

        $required = array_values(array_filter($candidates, static fn($c) => $c['required_for_target']));
        $eligible = array_values(array_filter($candidates, static fn($c) => $c['eligible']));
        usort($eligible, static fn($a, $b) => $b['delta_max'] <=> $a['delta_max']);

        $scenarios = [];

        $scenarios[] = self::simulateScenario($profile, [], $futureBase, $target)
            + ['name' => 'Không cải thiện', 'key' => 'none'];

        $priorityImprovements = [];
        if (!empty($required)) {
            foreach ($required as $c) {
                $priorityImprovements[] = ['course_code' => $c['course_code'], 'new_gpa' => 3.7];
            }
        } elseif (!empty($eligible)) {
            $top = $eligible[0];
            $priorityImprovements[] = ['course_code' => $top['course_code'], 'new_gpa' => 3.5];
        }
        $scenarios[] = self::simulateScenario($profile, $priorityImprovements, $futureBase, $target)
            + ['name' => 'Cải thiện môn ưu tiên', 'key' => 'priority'];

        $allImprovements = [];
        foreach ($eligible as $c) {
            $allImprovements[] = ['course_code' => $c['course_code'], 'new_gpa' => 3.7];
        }
        $scenarios[] = self::simulateScenario($profile, $allImprovements, $futureBase, $target)
            + ['name' => 'Cải thiện tối đa', 'key' => 'max'];

        return $scenarios;
    }

    // ─────────────────────────────────────────────────────────
    // Rule-based question answering
    // ─────────────────────────────────────────────────────────

    public static function normalizeQuestion(string $q): string {
        $q = mb_strtolower(trim($q), 'UTF-8');
        $map = [
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
            'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
            'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
            'đ'=>'d',
        ];
        return strtr($q, $map);
    }

    public static function extractTarget(string $question, float $fallback): float {
        $norm = self::normalizeQuestion($question);
        if (preg_match('/(?:gpa|muc tieu|dat|duoc|khoang)\s*(?:la|khoang|tam)?\s*([0-4](?:[.,]\d{1,2})?)/u', $norm, $m)) {
            $val = (float)str_replace(',', '.', $m[1]);
            if ($val >= 0 && $val <= 4) return $val;
        }
        if (preg_match_all('/\b([0-3][.,]\d{1,2}|4[.,]0{1,2})\b/u', $norm, $m)) {
            foreach ($m[1] as $raw) {
                $val = (float)str_replace(',', '.', $raw);
                if ($val >= 0 && $val <= 4) return $val;
            }
        }
        return $fallback;
    }

    public static function answerQuestion(string $question, array $profile, array $analysis, array $roadmap, array $candidates, array $courseRecs, float $currentTarget): array {
        $norm = self::normalizeQuestion($question);
        $target = self::extractTarget($question, $currentTarget);

        $isRetake  = (bool)preg_match('/hoc lai|thi lai|cai thien|mon nao|diem d|d\+|f\b|retake|which subject|improve my|d grade/u', $norm);
        $isAllA    = (bool)preg_match('/toan a|tat ca a|toan bo a|a het|toan a\+|all a|straight a|get a in all|all remaining/u', $norm);
        $isNext    = (bool)preg_match('/ky toi|hoc ky toi|ky sau|next semester|muc tieu ky/u', $norm);
        $isCompare = (bool)preg_match('/hay tap trung|tap trung hay|diem d hay|hay cai thien|so sanh|compare|or focus|focus on|which is better/u', $norm);
        $isFeas    = (bool)preg_match('/kha thi|co the|duoc khong|dat duoc|muc tieu|gpa|can i|possible|graduate/u', $norm);

        $fmt = static fn($v) => $v === null ? '—' : number_format((float)$v, 2);
        $feasLabel = static fn(?string $f) => [
            'achieved' => 'đã đạt mục tiêu', 'achievable' => 'khả thi',
            'difficult' => 'khó – cần nỗ lực cao', 'very_difficult' => 'rất khó',
            'unachievable' => 'không khả thi nếu không cải thiện điểm cũ hoặc điều chỉnh mục tiêu',
            'no_future' => 'không còn tín chỉ kế hoạch', 'no_data' => 'chưa có dữ liệu',
        ][$f] ?? (string)$f;

        $lines = [];
        $lines[] = '📊 Dữ liệu thực tế: GPA hiện tại ' . $fmt($profile['current_gpa']) . '/4,00'
            . ' · ' . $profile['graduation_earned'] . '/' . $profile['required_credits'] . ' TC đã hoàn thành'
            . ' · còn ' . $profile['remaining_credits'] . ' TC.';

        if ($isCompare) {
            $eligible = array_values(array_filter($candidates, static fn($c) => $c['eligible']));
            $bestDelta = 0.0;
            $bestCode  = null;
            foreach ($eligible as $c) {
                $aOpt = null;
                foreach ($c['options'] as $opt) if ($opt['letter'] === 'A') $aOpt = $opt;
                if ($aOpt && $aOpt['delta'] > $bestDelta) { $bestDelta = $aOpt['delta']; $bestCode = $c['course_code']; }
            }
            $remaining = max(1, (int)$profile['remaining_credits']);
            $futureGain = round(0.10 * $remaining / max(1, $profile['base_credits'] + $remaining), 2);
            $lines[] = '🧮 Tính toán: cải thiện một môn lên A giúp GPA tăng tối đa ~' . $fmt($bestDelta)
                . '; tăng GPA các kỳ còn lại thêm 0,10 chỉ giúp GPA cuối tăng ~' . $fmt($futureGain) . '.';
            $lines[] = '💡 Khuyến nghị: ' . ($bestCode
                ? 'ưu tiên cải thiện ' . $bestCode . ' nếu mục tiêu cần độ dự phòng; sau đó tập trung các môn còn lại.'
                : 'tập trung vào các môn còn lại vì không có môn cũ nào đủ điều kiện cải thiện.');
        } elseif ($isRetake) {
            $low = array_values(array_filter($candidates, static fn($c) => $c['eligible']));
            if (empty($low)) {
                $lines[] = '🧮 Tính toán: không có môn nào dưới ngưỡng ' . number_format($profile['pass_gpa'], 2) . ' cần cải thiện.';
                $lines[] = '💡 Khuyến nghị: tập trung vào các môn sắp học; GPA hiện tại ' . $fmt($profile['current_gpa']) . '.';
            } else {
                $lines[] = '🧮 Tính toán: có ' . count($low) . ' môn đủ điều kiện cải thiện theo chính sách "' . $profile['policy_label'] . '".';
                $top = array_slice($low, 0, 4);
                foreach ($top as $c) {
                    $bestOpt = null;
                    foreach ($c['options'] as $opt) {
                        if ($opt['letter'] === 'A' || $opt['letter'] === 'A+') { $bestOpt = $opt; }
                    }
                    $lines[] = '• ' . $c['course_code'] . ' (' . $c['credits'] . ' TC, hiện ' . $c['best_letter']
                        . '): ' . $c['priority']
                        . ($bestOpt ? ' – cải thiện lên ' . $bestOpt['letter'] . ' làm GPA tăng ~' . $fmt($bestOpt['delta']) : '') . '.';
                }
                $required = array_values(array_filter($candidates, static fn($c) => $c['required_for_target']));
                if (!empty($required)) {
                    $lines[] = '💡 Khuyến nghị: cải thiện trước ' . implode(', ', array_map(static fn($c) => $c['course_code'], $required))
                        . ' – đây là các môn cần thiết để mục tiêu ' . $fmt($target) . ' trở nên khả thi.';
                } else {
                    $lines[] = '💡 Khuyến nghị: mục tiêu ' . $fmt($target) . ' vẫn khả thi nhờ các tín chỉ còn lại; '
                        . 'chỉ cải thiện thêm nếu bạn muốn tăng GPA đệm.';
                }
            }
        } elseif ($isAllA) {
            $allA  = self::simulateScenario($profile, [], 3.7, $target);
            $allAP = self::simulateScenario($profile, [], 4.0, $target);
            $lines[] = '🧮 Tính toán: nếu tất cả tín chỉ còn lại đạt A (3,7): GPA tốt nghiệp dự kiến '
                . $fmt($allA['expected_gpa']) . '. Nếu đạt A+ (4,0): ' . $fmt($allAP['expected_gpa']) . '.';
            $lines[] = '💡 Khuyến nghị: mục tiêu ' . $fmt($target) . ' '
                . ($allA['expected_gpa'] !== null && $allA['expected_gpa'] >= $target - 0.005 ? 'có thể đạt được' : 'không đạt được')
                . ' ngay cả khi toàn bộ môn còn lại đạt A.';
        } elseif ($isNext) {
            if (!empty($roadmap)) {
                $first = $roadmap[0];
                $lines[] = '🧮 Tính toán: kỳ gần nhất cần khoảng ' . $first['credits'] . ' TC với GPA mục tiêu '
                    . $fmt($first['target_gpa']) . '; GPA tích lũy dự kiến sau kỳ: ' . $fmt($first['projected_cum']) . '.';
            } else {
                $lines[] = '🧮 Tính toán: không còn tín chỉ kế hoạch.';
            }
            if (!empty($courseRecs)) {
                $firstCourse = $courseRecs[0];
                $lines[] = '💡 Khuyến nghị: môn ' . $firstCourse['course_code'] . ' nên đạt tối thiểu '
                    . $firstCourse['target_letter'] . ' (' . $fmt($firstCourse['target_gpa']) . ').';
            }
        } elseif ($isFeas) {
            $alt = self::analyze($profile, $target);
            $reqDisp = ($alt['raw_required_future_gpa'] !== null && $alt['raw_required_future_gpa'] > 4.0)
                ? '> 4,00' : $fmt($alt['required_future_gpa']);
            $lines[] = '🧮 Tính toán với mục tiêu ' . $fmt($target) . ': cần GPA trung bình '
                . $reqDisp . ' trên ' . $profile['remaining_credits']
                . ' TC còn lại. GPA tối đa có thể đạt: ' . $fmt($alt['max_possible_gpa']) . ' → '
                . $feasLabel($alt['feasibility']) . '.';
            if ($alt['feasibility'] === 'achieved') {
                $lines[] = '💡 Khuyến nghị: duy trì phong độ hiện tại; không cần cải thiện điểm cũ để giữ mục tiêu này.';
            } elseif ($alt['feasibility'] === 'unachievable') {
                $lines[] = '💡 Khuyến nghị: cần cải thiện điểm cũ hoặc chọn mục tiêu thấp hơn; dự báo hiện tại không đạt.';
            } else {
                $lines[] = '💡 Khuyến nghị: lập kế hoạch đạt ít nhất ' . $fmt($alt['required_future_gpa']) . ' mỗi kỳ còn lại.';
            }
        } else {
            $reqDisp = ($analysis['raw_required_future_gpa'] !== null && $analysis['raw_required_future_gpa'] > 4.0)
                ? '> 4,00' : $fmt($analysis['required_future_gpa']);
            $lines[] = '🧮 Tính toán: mục tiêu ' . $fmt($target) . ' → cần GPA '
                . $reqDisp . ' trên các tín chỉ còn lại; tối đa có thể đạt '
                . $fmt($analysis['max_possible_gpa']) . '.';
            $lines[] = '💡 Khuyến nghị: ' . ($analysis['feasibility'] === 'achieved'
                ? 'duy trì kết quả hiện tại.'
                : ($analysis['feasibility'] === 'unachievable'
                    ? 'điều chỉnh mục tiêu hoặc cải thiện điểm các môn cũ.'
                    : 'ưu tiên các môn có thể cải thiện và giữ GPA các kỳ còn lại ở mức yêu cầu.'));
        }

        $lines[] = '⚠️ Lưu ý: các con số dự báo được tính từ dữ liệu thực tế trong CSDL và chính sách '
            . $profile['policy_label'] . '; đây là ước tính, không phải kết quả chính thức.';

        return [
            'answer' => implode("\n", $lines),
            'intent' => $isRetake ? 'retake' : ($isAllA ? 'all_a' : ($isNext ? 'next' : ($isCompare ? 'compare' : ($isFeas ? 'feasibility' : 'summary')))),
            'target' => $target,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Facts for narrative / AI
    // ─────────────────────────────────────────────────────────

    public static function buildFacts(array $profile, array $analysis, array $roadmap, array $candidates, array $courseRecs): array {
        $sorted = $profile['attempts'];
        usort($sorted, static fn($a, $b) => $b['grade_point'] <=> $a['grade_point']);
        $strengths = [];
        $seen = [];
        foreach ($sorted as $a) {
            $code = strtoupper($a['course_code']);
            if (isset($seen[$code])) continue;
            $seen[$code] = true;
            $strengths[] = ['code' => $a['course_code'], 'letter' => $a['letter_grade'], 'point' => (float)$a['grade_point']];
            if (count($strengths) >= 3) break;
        }

        $weaknesses = [];
        foreach ($profile['groups'] as $g) {
            if ($g['best_gpa'] < $profile['pass_gpa']) {
                $weaknesses[] = ['code' => $g['course_code'], 'letter' => $g['best_letter'], 'point' => (float)$g['best_gpa']];
            }
        }
        usort($weaknesses, static fn($a, $b) => $a['point'] <=> $b['point']);
        $weaknesses = array_slice($weaknesses, 0, 5);

        return [
            'student_scope'        => 'authenticated student only',
            'current_gpa'          => $profile['current_gpa'],
            'official_gpa'         => $profile['official_gpa'],
            'classification'       => SystemConfig::getClassification($profile['current_gpa']),
            'gpa_credits'          => $profile['base_credits'],
            'graduation_credits'   => $profile['graduation_earned'],
            'required_credits'     => $profile['required_credits'],
            'remaining_credits'    => $profile['remaining_credits'],
            'target_gpa'           => $analysis['target'],
            'required_future_gpa'  => $analysis['required_future_gpa'],
            'required_future_raw'  => $analysis['raw_required_future_gpa'],
            'max_possible_gpa'     => $analysis['max_possible_gpa'],
            'feasibility'          => $analysis['feasibility'],
            'retake_policy'        => $profile['policy_label'],
            'retake_allowed_max'   => $profile['retake_max_gpa'],
            'excluded_codes'       => $profile['excluded_codes'],
            'strengths'            => $strengths,
            'weaknesses'           => $weaknesses,
            'retakes'              => array_map(static fn($c) => [
                'code' => $c['course_code'], 'credits' => $c['credits'],
                'current' => $c['best_letter'], 'priority' => $c['priority'],
                'required' => $c['required_for_target'], 'reason' => $c['reason'],
            ], array_slice($candidates, 0, 8)),
            'roadmap'              => array_map(static fn($r) => [
                'label' => $r['label'], 'credits' => $r['credits'], 'target_gpa' => $r['target_gpa'],
            ], $roadmap),
            'upcoming_courses'     => array_map(static fn($r) => [
                'code' => $r['course_code'], 'credits' => $r['credits'],
                'target_letter' => $r['target_letter'], 'priority' => $r['priority'],
            ], array_slice($courseRecs, 0, 6)),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Saved simulations
    // ─────────────────────────────────────────────────────────

    public static function saveSimulation(string $studentId, array $data): int {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'INSERT INTO GPAPlanSimulation
                (student_id, scenario_name, target_gpa, future_gpa, total_future_credits,
                 improvement_credits, retakes_json, expected_gpa, required_future_gpa, feasibility)
             VALUES (:sid, :name, :target, :future, :future_crd, :imp_crd, :retakes, :expected, :required, :feas)'
        );
        $stmt->execute([
            ':sid'        => $studentId,
            ':name'       => $data['scenario_name'],
            ':target'     => $data['target_gpa'],
            ':future'     => $data['future_gpa'],
            ':future_crd' => $data['total_future_credits'],
            ':imp_crd'    => $data['improvement_credits'],
            ':retakes'    => json_encode($data['improvements'], JSON_UNESCAPED_UNICODE),
            ':expected'   => $data['expected_gpa'],
            ':required'   => $data['required_future_gpa'],
            ':feas'       => $data['feasibility'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function getSavedSimulations(string $studentId, int $limit = 10): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT * FROM GPAPlanSimulation WHERE student_id = :sid ORDER BY created_at DESC, simulation_id DESC LIMIT :lim'
        );
        $stmt->bindValue(':sid', $studentId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['improvements'] = $r['retakes_json'] ? (json_decode($r['retakes_json'], true) ?: []) : [];
        }
        return $rows;
    }

    public static function deleteSimulation(string $studentId, int $simulationId): bool {
        $pdo  = get_db();
        $stmt = $pdo->prepare('DELETE FROM GPAPlanSimulation WHERE simulation_id = :id AND student_id = :sid');
        return $stmt->execute([':id' => $simulationId, ':sid' => $studentId]);
    }
}
