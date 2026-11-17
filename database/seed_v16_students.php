<?php
/**
 * UniPortal – Seed v16: expand the student database to 4 cohorts × 1,000 new
 * students (4,000 generated records, total > 5,000) with fully connected
 * academic data.
 *
 * What is created (all idempotent):
 *   * Student profile + login account (shared demo password: "password")
 *   * Major / department / cohort / class assignment
 *   * Two completed courses in past semesters (sem 5 and sem 6)
 *   * Five current-semester courses (14–18 credits, sem 7)
 *   * Class sections, teaching schedules, official attendance sessions,
 *     assessments, assignments and submissions for the generated sections
 *   * Grades, attendance records, tuition invoices and academic warnings
 *
 * Demo data is marked Student.is_demo = 1 and uses section codes prefixed
 * "D-". Existing records are never modified or deleted.
 *
 * Run:
 *   C:/xampp/php/php.exe database/seed_v16_students.php
 *   C:/xampp/php/php.exe database/seed_v16_students.php --skip-warnings
 */

require_once __DIR__ . '/../src/src/config/database.php';

ini_set('memory_limit', '1024M');
set_time_limit(0);

$pdo = get_db();
$skipWarnings = in_array('--skip-warnings', $argv ?? [], true);

const COHORT_SIZE = 1000;                 // new students per admission cohort
const COHORTS = [2022, 2023, 2024, 2025];
const PAST_SEM_1 = 5;                     // 2025-2026 HK1
const PAST_SEM_2 = 6;                     // 2025-2026 HK2
const CURRENT_SEM = 7;                    // 2026-2027 HK1 (Active)
const CURRENT_PERIOD = 1;                 // open registration period
// Generated demo sections are shared by ~80–400 students, so the legacy
// capacity of 110 would make every section look full. Keep realistic headroom.
const SECTION_CAPACITY = 460;
const DEMO_PASSWORD = 'password';

/** Fixed major weights (sum = 100) → 1,000 students per cohort. */
$majorWeights = [
    'MIS' => 10, 'AIT' => 10, 'ICE' => 10, 'AC' => 10,
    'IB' => 9, 'BDA' => 9, 'AAI' => 9, 'MBA' => 9,
    'BEL' => 8, 'ECO' => 8, 'MKT' => 8,
];

/** @return int inserted rows */
function batch_insert(PDO $pdo, string $table, array $columns, array $rows, int $chunk = 400): int {
    if (!$rows) return 0;
    $colList = implode(',', array_map(fn($c) => "`$c`", $columns));
    $rowPh = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $inserted = 0;
    foreach (array_chunk($rows, $chunk) as $batch) {
        $sql = "INSERT IGNORE INTO `$table` ($colList) VALUES " . implode(',', array_fill(0, count($batch), $rowPh));
        $flat = [];
        foreach ($batch as $r) {
            foreach ($columns as $c) $flat[] = $r[$c] ?? null;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($flat);
        $inserted += $stmt->rowCount();
    }
    return $inserted;
}

function first_weekday(string $startDate, int $isoDay): string {
    $d = new DateTimeImmutable($startDate);
    $cur = (int)$d->format('N');
    $add = ($isoDay - $cur + 7) % 7;
    return $d->modify("+$add day")->format('Y-m-d');
}

function score_for_profile(string $profile, int $spread = 0): float {
    [$min, $max] = match ($profile) {
        'excellent' => [85, 98],
        'good'      => [72, 88],
        'average'   => [58, 75],
        'weak'      => [42, 60],
        default     => [25, 45],
    };
    $v = mt_rand($min, $max) + $spread;
    return round(max(0, min(100, $v)), 1);
}

function profile_for_roll(int $roll): string {
    if ($roll <= 14) return 'excellent';
    if ($roll <= 44) return 'good';
    if ($roll <= 69) return 'average';
    if ($roll <= 84) return 'weak';
    return 'very_weak';
}

echo "=== UniPortal seed v16 – student scale ===\n";

// ───────────────────────────────────────────────────────────────
// 1. Reference data
// ───────────────────────────────────────────────────────────────
$majors = $pdo->query(
    'SELECT major_id, major_code, major_name, department_id
     FROM Major WHERE is_active = 1 ORDER BY major_id'
)->fetchAll();
if (count($majors) < 1) { fwrite(STDERR, "No majors found.\n"); exit(1); }

$majorById = [];
foreach ($majors as $m) $majorById[(int)$m['major_id']] = $m;

$semesters = [];
foreach ($pdo->query('SELECT semester_id, start_date, end_date FROM Semester')->fetchAll() as $s) {
    $semesters[(int)$s['semester_id']] = $s;
}
foreach ([PAST_SEM_1, PAST_SEM_2, CURRENT_SEM] as $need) {
    if (!isset($semesters[$need])) { fwrite(STDERR, "Semester $need not found.\n"); exit(1); }
}

$adminId = (int)($pdo->query(
    "SELECT account_id FROM Account WHERE role='AcademicAffairs' ORDER BY account_id LIMIT 1"
)->fetchColumn() ?: 25);

$lecturersByDept = [];
$lecturerAccount = [];
foreach ($pdo->query(
    "SELECT l.lecturer_id, l.department_id, a.account_id
     FROM Lecturer l
     LEFT JOIN Account a ON a.lecturer_id = l.lecturer_id
     WHERE l.status = 'Active'
     ORDER BY l.lecturer_id"
)->fetchAll() as $l) {
    $lecturersByDept[(int)$l['department_id']][] = (int)$l['lecturer_id'];
    if (!empty($l['account_id'])) $lecturerAccount[(int)$l['lecturer_id']] = (int)$l['account_id'];
}
$anyLecturer = (int)$pdo->query("SELECT lecturer_id FROM Lecturer WHERE status='Active' ORDER BY lecturer_id LIMIT 1")->fetchColumn();
$anyLecturerAccount = $lecturerAccount[$anyLecturer] ?? $adminId;

// Shared bcrypt hash for the documented demo password (never plaintext).
$hash = (string)$pdo->query("SELECT password_hash FROM Account WHERE role='Student' LIMIT 1")->fetchColumn();
if ($hash === '' || !password_verify(DEMO_PASSWORD, $hash)) {
    $hash = password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT);
}

// ───────────────────────────────────────────────────────────────
// 2. Curricula – create the missing 2022 curricula for majors 9–11
// ───────────────────────────────────────────────────────────────
$curricula = [];
foreach ($pdo->query('SELECT curriculum_id, major_id, admission_year, curriculum_name, major_code FROM Curriculum')->fetchAll() as $c) {
    $curricula[(int)$c['major_id'] . '|' . (int)$c['admission_year']] = $c;
}

$cloneMissing = 0;
foreach ($majors as $m) {
    $mid = (int)$m['major_id'];
    foreach (COHORTS as $year) {
        if (isset($curricula[$mid . '|' . $year])) continue;
        $source = null;
        foreach ($curricula as $key => $c) {
            if ((int)$c['major_id'] !== $mid) continue;
            if ($source === null || abs((int)$c['admission_year'] - $year) < abs((int)$source['admission_year'] - $year)) {
                $source = $c;
            }
        }
        if (!$source) continue;
        $ins = $pdo->prepare(
            'INSERT INTO Curriculum (curriculum_name, major_id, major_code, admission_year, required_credits)
             VALUES (:name, :mid, :code, :year, 130)'
        );
        $ins->execute([
            ':name' => 'Chương trình Cử nhân ' . $m['major_name'] . ' – K' . $year,
            ':mid'  => $mid,
            ':code' => $m['major_code'],
            ':year' => $year,
        ]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO CurriculumCourse (curriculum_id, course_id, course_type, semester_recommended, is_required)
             SELECT :new, course_id, course_type, semester_recommended, is_required
             FROM CurriculumCourse WHERE curriculum_id = :old'
        )->execute([':new' => $newId, ':old' => (int)$source['curriculum_id']]);
        $curricula[$mid . '|' . $year] = [
            'curriculum_id' => $newId,
            'major_id' => $mid,
            'admission_year' => $year,
            'curriculum_name' => 'Chương trình Cử nhân ' . $m['major_name'] . ' – K' . $year,
            'major_code' => $m['major_code'],
        ];
        $cloneMissing++;
    }
}
echo "Curricula cloned: $cloneMissing\n";

$coursesByCurriculum = [];
foreach ($pdo->query(
    'SELECT cc.curriculum_id, cc.is_required, cc.semester_recommended,
            c.course_id, c.course_code, c.course_name, c.credits
     FROM CurriculumCourse cc
     JOIN Course c ON c.course_id = cc.course_id
     WHERE c.is_active = 1
     ORDER BY cc.curriculum_id, (cc.semester_recommended IS NULL), cc.semester_recommended, c.course_code'
)->fetchAll() as $row) {
    $cid = (int)$row['curriculum_id'];
    if (!isset($coursesByCurriculum[$cid])) $coursesByCurriculum[$cid] = [];
    $coursesByCurriculum[$cid][(int)$row['course_id']] = $row;
}

/** Pick two completed courses + five current courses from a curriculum. */
function pick_group_courses(array $courses): array {
    $list = array_values($courses);
    usort($list, fn($a, $b) => strcmp($a['course_code'], $b['course_code']));

    $pick3 = function(array $pool) {
        foreach ($pool as $c) if ((int)$c['credits'] === 3) return $c;
        return $pool[0] ?? null;
    };

    $pastA = $pick3($list);
    $pastB = null;
    foreach ($list as $c) {
        if ($c['course_id'] === ($pastA['course_id'] ?? -1)) continue;
        if ((int)$c['credits'] === 3) { $pastB = $c; break; }
        if ($pastB === null) $pastB = $c;
    }

    $used = [$pastA['course_id'] ?? 0 => true, $pastB['course_id'] ?? 0 => true];
    $remaining = array_values(array_filter($list, fn($c) => !isset($used[$c['course_id']])));

    // Prefer 3-credit courses until the semester reaches 14 credits.
    $current = [];
    $credits = 0;
    $pool = array_merge(
        array_values(array_filter($remaining, fn($c) => (int)$c['credits'] === 3)),
        array_values(array_filter($remaining, fn($c) => (int)$c['credits'] !== 3))
    );
    foreach ($pool as $c) {
        if ($credits >= 14 && count($current) >= 5) break;
        if ($credits + (int)$c['credits'] > 24) continue;
        $current[] = $c;
        $credits += (int)$c['credits'];
        if (count($current) >= 8) break;
    }
    if ($credits < 14) {
        foreach ($remaining as $c) {
            if (in_array($c['course_id'], array_column($current, 'course_id'), true)) continue;
            $current[] = $c;
            $credits += (int)$c['credits'];
            if ($credits >= 14) break;
        }
    }

    return ['pastA' => $pastA, 'pastB' => $pastB, 'current' => $current, 'current_credits' => $credits];
}

// ───────────────────────────────────────────────────────────────
// 3. Existing state (idempotency)
// ───────────────────────────────────────────────────────────────
$existingStudents = [];
foreach ($pdo->query("SELECT student_id FROM Student WHERE student_id LIKE '2290%' OR student_id LIKE '2390%' OR student_id LIKE '2490%' OR student_id LIKE '2590%'")->fetchAll() as $r) {
    $existingStudents[$r['student_id']] = true;
}

$sectionByCode = [];
foreach ($pdo->query("SELECT * FROM ClassSection WHERE section_code LIKE 'D-%'")->fetchAll() as $r) {
    $sectionByCode[$r['section_code']] = $r;
}

$assessmentKey = [];
foreach ($pdo->query('SELECT assessment_id, section_id, assessment_name FROM Assessment')->fetchAll() as $r) {
    $assessmentKey[(int)$r['section_id'] . '|' . $r['assessment_name']] = (int)$r['assessment_id'];
}

$assignmentKey = [];
foreach ($pdo->query("SELECT assignment_id, section_id, title FROM Assignment WHERE is_deleted = 0")->fetchAll() as $r) {
    $assignmentKey[(int)$r['section_id'] . '|' . $r['title']] = (int)$r['assignment_id'];
}

$sessionCount = [];
foreach ($pdo->query('SELECT section_id, COUNT(*) c FROM AttendanceSession GROUP BY section_id')->fetchAll() as $r) {
    $sessionCount[(int)$r['section_id']] = (int)$r['c'];
}

// ───────────────────────────────────────────────────────────────
// 4. Build the 44 (cohort × major) groups
// ───────────────────────────────────────────────────────────────
$groups = [];          // [year][major_id] => ['students'=>[], 'courses'=>...]
$studentRows = [];
$accountRows = [];
$cohortCounts = [];

$family = ['Nguyen','Tran','Le','Pham','Hoang','Phan','Vu','Dang','Bui','Do','Ho','Ngo','Duong','Ly'];
$middleMale = ['Van','Minh','Quang','Duc','Huu','Tuan','Anh','Bao','Thanh','Gia'];
$middleFemale = ['Thi','Ngoc','Thu','Thanh','Minh','Lan','Mai','Kim','Bao','Gia'];
$givenMale = ['An','Binh','Cuong','Dung','Duc','Hai','Hieu','Hoang','Hung','Khanh','Long','Minh','Nam','Phuc','Quan','Son','Thanh','Trung','Tuan','Vinh'];
$givenFemale = ['Anh','Chi','Dao','Giang','Ha','Hanh','Hoa','Huyen','Lan','Linh','Mai','Ngoc','Nhung','Phuong','Quynh','Thao','Thu','Trang','Van','Yen'];

$totalWeight = array_sum($majorWeights);
foreach (COHORTS as $year) {
    $cohortCounts[$year] = 0;
    $seq = 0;
    foreach ($majors as $m) {
        $mid = (int)$m['major_id'];
        $code = $m['major_code'];
        $count = (int)round(COHORT_SIZE * ($majorWeights[$code] ?? 0) / $totalWeight);
        if ($count < 1) $count = 1;

        $cur = $curricula[$mid . '|' . $year] ?? null;
        $courses = $cur ? ($coursesByCurriculum[(int)$cur['curriculum_id']] ?? []) : [];
        if (!$courses) {
            fwrite(STDERR, "No curriculum courses for major $code / $year\n");
            continue;
        }
        $picked = pick_group_courses($courses);
        $groups[$year][$mid] = [
            'major' => $m,
            'curriculum' => $cur,
            'picked' => $picked,
            'students' => [],
        ];

        $classCode = $code . $year . 'B';
        for ($i = 0; $i < $count; $i++) {
            $seq++;
            $sid = substr((string)$year, 2) . '90' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            $groups[$year][$mid]['students'][] = $sid;
            $cohortCounts[$year]++;

            if (isset($existingStudents[$sid])) continue;

            mt_srand(crc32($sid));
            $gender = mt_rand(0, 1) ? 'Male' : 'Female';
            $fam = $family[mt_rand(0, count($family) - 1)];
            $midName = $gender === 'Male'
                ? $middleMale[mt_rand(0, count($middleMale) - 1)]
                : $middleFemale[mt_rand(0, count($middleFemale) - 1)];
            $given = $gender === 'Male'
                ? $givenMale[mt_rand(0, count($givenMale) - 1)]
                : $givenFemale[mt_rand(0, count($givenFemale) - 1)];
            $fullName = "$fam $midName $given";
            $dobYear = $year - mt_rand(18, 20);
            $dob = sprintf('%04d-%02d-%02d', $dobYear, mt_rand(1, 12), mt_rand(1, 28));

            $roll = mt_rand(1, 100);
            $status = $roll <= 94 ? 'Active' : ($roll <= 96 ? 'Suspended' : ($roll <= 99 ? 'Graduated' : 'Withdrawn'));

            $studentRows[] = [
                'student_id' => $sid,
                'full_name' => $fullName,
                'class_code' => $classCode,
                'major_id' => $mid,
                'phone' => '09' . $sid,
                'email' => $sid . '@student.uniportal.edu',
                'gender' => $gender,
                'date_of_birth' => $dob,
                'department_id' => (int)$m['department_id'],
                'admission_year' => $year,
                'status' => $status,
                'is_demo' => 1,
            ];
            $accountRows[] = [
                'username' => $sid,
                'password_hash' => $hash,
                'role' => 'Student',
                'student_id' => $sid,
                'is_active' => 1,
            ];
        }
    }
}
echo "Prepared student rows: " . count($studentRows) . " (existing skipped)\n";
batch_insert($pdo, 'Student', [
    'student_id','full_name','class_code','major_id','phone','email','gender',
    'date_of_birth','department_id','admission_year','status','is_demo',
], $studentRows, 500);
batch_insert($pdo, 'Account', ['username','password_hash','role','student_id','is_active'], $accountRows, 500);
echo "Students inserted: " . count($studentRows) . ", accounts: " . count($accountRows) . "\n";

// ───────────────────────────────────────────────────────────────
// 5. Sections, schedules, sessions, assessments, assignments
// ───────────────────────────────────────────────────────────────
function ensure_section(PDO $pdo, array &$sectionByCode, array $major, array $course, int $semesterId,
                        array $semester, string $suffix, int $slotIndex, int $adminId,
                        array $lecturersByDept, int $anyLecturer): array {
    global $assessmentKey, $assignmentKey;
    $code = 'D-' . $major['major_code'] . $course['course_id'] . '-' . $semesterId . $suffix;

    $day = 2 + ($slotIndex % 5);
    $start = '07:30:00';
    $end = '09:00:00';
    $room = 'D' . $semesterId . '.' . str_pad((string)$major['major_id'], 2, '0', STR_PAD_LEFT)
          . '.' . str_pad((string)($slotIndex + 1), 2, '0', STR_PAD_LEFT);
    $deptLecturers = $lecturersByDept[(int)$major['department_id']] ?? [];
    $lecturerId = $deptLecturers
        ? $deptLecturers[($major['major_id'] + $semesterId + $slotIndex) % count($deptLecturers)]
        : $anyLecturer;

    $scheduleText = 'Thứ ' . ($day === 7 ? 'CN' : $day) . ' ' . substr($start, 0, 5) . '-' . substr($end, 0, 5);

    if (isset($sectionByCode[$code])) {
        // Section may exist from an earlier (possibly interrupted) run; keep
        // filling in the child records below so a re-run converges.
        $section = $sectionByCode[$code];
    } else {
        $pdo->prepare(
            'INSERT INTO ClassSection
                (course_id, semester_id, lecturer_id, section_code, schedule, room, capacity,
                 min_enrollment, max_enrollment, study_days, start_time, end_time,
                 section_status, status_changed_at, status_note)
             VALUES (:course, :sem, :lec, :code, :sched, :room, :cap, 30, :cap2,
                     :days, :start, :end, \'Confirmed\', NOW(), \'Lớp demo phục vụ dữ liệu mở rộng\')'
        )->execute([
            ':course' => (int)$course['course_id'],
            ':sem' => $semesterId, ':lec' => $lecturerId, ':code' => $code,
            ':sched' => $scheduleText, ':room' => $room, ':cap' => SECTION_CAPACITY,
            ':cap2' => SECTION_CAPACITY, ':days' => (string)$day,
            ':start' => $start, ':end' => $end,
        ]);
        $section = $pdo->query('SELECT * FROM ClassSection WHERE section_id=' . (int)$pdo->lastInsertId())->fetch();
        $sectionByCode[$code] = $section;
    }
    $sectionId = (int)$section['section_id'];

    $expected = (int)$course['credits'] === 2 ? 10 : 15;
    $pdo->prepare(
        'INSERT IGNORE INTO TeachingSchedule
            (section_id, semester_id, lecturer_id, day_of_week, start_time, end_time, room,
             effective_start, effective_end, expected_sessions, status, created_by)
         VALUES (:sec, :sem, :lec, :day, :start, :end, :room, :eff1, :eff2, :exp, \'Active\', :by)'
    )->execute([
        ':sec' => $sectionId, ':sem' => $semesterId, ':lec' => $lecturerId,
        ':day' => $day, ':start' => $start, ':end' => $end, ':room' => $room,
        ':eff1' => $semester['start_date'], ':eff2' => $semester['end_date'],
        ':exp' => $expected, ':by' => $adminId,
    ]);

    // Official sessions – only when the section has none yet.
    $has = (int)$pdo->query('SELECT COUNT(*) FROM AttendanceSession WHERE section_id=' . $sectionId)->fetchColumn();
    if ($has === 0) {
        $first = first_weekday($semester['start_date'], $day);
        $rows = [];
        for ($n = 1; $n <= $expected; $n++) {
            $date = (new DateTimeImmutable($first))->modify('+' . (($n - 1) * 7) . ' day')->format('Y-m-d');
            $rows[] = [
                'section_id' => $sectionId,
                'schedule_id' => null,
                'session_no' => $n,
                'lecturer_id' => $lecturerId,
                'session_date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'room' => $room,
                'topic' => 'Buổi học chính thức',
                'session_type' => 'Regular',
                'status' => 'Scheduled',
            ];
        }
        batch_insert($pdo, 'AttendanceSession', array_keys($rows[0]), $rows, 200);

        // A deterministic subset gets one cancelled session plus an approved make-up.
        if (($major['major_id'] + $semesterId + $slotIndex) % 6 === 0) {
            $sessions = $pdo->query('SELECT session_id, session_date, start_time, end_time, room
                                     FROM AttendanceSession WHERE section_id=' . $sectionId . ' ORDER BY session_no')->fetchAll();
            if (count($sessions) >= 7) {
                $cancelled = $sessions[6];
                $pdo->prepare("UPDATE AttendanceSession SET status='Cancelled', cancelled_at=NOW() WHERE session_id=:id")
                    ->execute([':id' => (int)$cancelled['session_id']]);
                $pdo->prepare(
                    "INSERT INTO AttendanceSession
                        (section_id, schedule_id, session_no, lecturer_id, session_date, start_time, end_time,
                         room, topic, session_type, status, original_session_id)
                     VALUES (:sec, NULL, :no, :lec, :date, :st, :en, :room, 'Buổi học bù đã được duyệt', 'Makeup', 'Scheduled', :orig)"
                )->execute([
                    ':sec' => $sectionId, ':no' => $expected + 1, ':lec' => $lecturerId,
                    ':date' => (new DateTimeImmutable($cancelled['session_date']))->modify('+3 day')->format('Y-m-d'),
                    ':st' => $cancelled['start_time'], ':en' => $cancelled['end_time'],
                    ':room' => $cancelled['room'], ':orig' => (int)$cancelled['session_id'],
                ]);
            }
        }
    }

    // Current-semester sections are part of the open registration period.
    if ($semesterId === CURRENT_SEM) {
        $pdo->prepare('INSERT IGNORE INTO RegistrationPeriodSection (period_id, section_id) VALUES (:p, :s)')
            ->execute([':p' => CURRENT_PERIOD, ':s' => $sectionId]);
    }

    $section = $pdo->query('SELECT * FROM ClassSection WHERE section_id=' . $sectionId)->fetch();
    $sectionByCode[$code] = $section;

    // Assessments (two for completed courses, three for the current course).
    $defs = $semesterId === CURRENT_SEM
        ? [['Điểm quá trình', 30, 7], ['Kiểm tra giữa kỳ', 30, 10], ['Thi cuối kỳ', 40, null]]
        : [['Điểm quá trình', 40, 7], ['Thi cuối kỳ', 60, null]];
    foreach ($defs as [$name, $weight, $week]) {
        $key = $sectionId . '|' . $name;
        if (isset($assessmentKey[$key])) continue;
        $due = $week === null
            ? (new DateTimeImmutable($semester['end_date']))->modify('-7 day')->format('Y-m-d 23:59:00')
            : (new DateTimeImmutable($semester['start_date']))->modify('+' . ($week * 7) . ' day')->format('Y-m-d 23:59:00');
        $pdo->prepare(
            'INSERT INTO Assessment (section_id, assessment_name, weight_percent, max_score, due_date)
             VALUES (:sec, :name, :w, 10, :due)'
        )->execute([':sec' => $sectionId, ':name' => $name, ':w' => $weight, ':due' => $due]);
        $assessmentKey[$key] = (int)$pdo->lastInsertId();
    }

    // Two demo assignments on the first current-semester section of each group.
    if ($semesterId === CURRENT_SEM && $suffix === 'C1') {
        $assignDefs = [
            ['[DEMO] Bài tập quá trình 1', 2026 . '-09-21 08:00:00', 2026 . '-10-12 23:59:00'],
            ['[DEMO] Bài tập quá trình 2', 2026 . '-10-05 08:00:00', 2026 . '-10-30 23:59:00'],
        ];
        foreach ($assignDefs as [$title, $pub, $due]) {
            $key = $sectionId . '|' . $title;
            if (isset($assignmentKey[$key])) continue;
            $pdo->prepare(
                'INSERT INTO Assignment
                    (section_id, title, description, instructions, publication_date, due_date,
                     max_score, status, is_deleted, is_demo, created_by)
                 VALUES (:sec, :title, :descr, :instr, :pub, :due, 10, \'Published\', 0, 1, :by)'
            )->execute([
                ':sec' => $sectionId, ':title' => $title,
                ':descr' => 'Bài tập demo được tạo tự động bởi seed_v16_students.php.',
                ':instr' => 'Nộp báo cáo PDF. Dữ liệu minh họa, không phải bài tập chính thức.',
                ':pub' => $pub, ':due' => $due, ':by' => $adminId,
            ]);
            $assignmentKey[$key] = (int)$pdo->lastInsertId();
        }
    }

    return $section;
}

$createdSections = 0;
foreach (COHORTS as $year) {
    foreach ($majors as $m) {
        $mid = (int)$m['major_id'];
        $g = $groups[$year][$mid] ?? null;
        if (!$g) continue;
        $picked = $g['picked'];
        $majorRow = $g['major'];

        $g['sections'] = [];
        // Past semester 5
        $g['sections'][] = [
            'role' => 'past1', 'section' => ensure_section($pdo, $sectionByCode, $majorRow, $picked['pastA'], PAST_SEM_1, $semesters[PAST_SEM_1], 'P5', 0, $adminId, $lecturersByDept, $anyLecturer),
            'course' => $picked['pastA'],
        ];
        // Past semester 6
        $g['sections'][] = [
            'role' => 'past2', 'section' => ensure_section($pdo, $sectionByCode, $majorRow, $picked['pastB'], PAST_SEM_2, $semesters[PAST_SEM_2], 'P6', 1, $adminId, $lecturersByDept, $anyLecturer),
            'course' => $picked['pastB'],
        ];
        // Current semester 7 – five courses
        foreach ($picked['current'] as $idx => $course) {
            $g['sections'][] = [
                'role' => 'current' . ($idx + 1),
                'section' => ensure_section($pdo, $sectionByCode, $majorRow, $course, CURRENT_SEM, $semesters[CURRENT_SEM], 'C' . ($idx + 1), $idx + 2, $adminId, $lecturersByDept, $anyLecturer),
                'course' => $course,
            ];
        }
        $groups[$year][$mid] = $g;
    }
}
$createdSections = count($sectionByCode);
echo "Demo sections available: $createdSections\n";

// ───────────────────────────────────────────────────────────────
// 6. Enrollments
// ───────────────────────────────────────────────────────────────
$enrollmentRows = [];
foreach (COHORTS as $year) {
    foreach ($majors as $m) {
        $mid = (int)$m['major_id'];
        $g = $groups[$year][$mid] ?? null;
        if (!$g) continue;
        foreach ($g['students'] as $sid) {
            foreach ($g['sections'] as $entry) {
                $isPast = str_starts_with($entry['role'], 'past');
                $semId = $isPast ? ($entry['role'] === 'past1' ? PAST_SEM_1 : PAST_SEM_2) : CURRENT_SEM;
                $enrollmentRows[] = [
                    'student_id' => $sid,
                    'section_id' => (int)$entry['section']['section_id'],
                    'enrollment_date' => $semesters[$semId]['start_date'] . ' 08:00:00',
                    'status' => $isPast ? 'Completed' : 'Enrolled',
                ];
            }
        }
    }
}
batch_insert($pdo, 'Enrollment', ['student_id','section_id','enrollment_date','status'], $enrollmentRows, 500);
echo "Enrollments prepared: " . count($enrollmentRows) . "\n";

$enrollmentMap = [];   // "sid|section" => enrollment_id
$enrollQ = $pdo->query(
    "SELECT e.enrollment_id, e.student_id, e.section_id, e.status
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     WHERE cs.section_code LIKE 'D-%'"
);
foreach ($enrollQ->fetchAll() as $r) {
    $enrollmentMap[$r['student_id'] . '|' . (int)$r['section_id']] = (int)$r['enrollment_id'];
}
echo "Enrollment rows available: " . count($enrollmentMap) . "\n";

// ───────────────────────────────────────────────────────────────
// 7. Grades
// ───────────────────────────────────────────────────────────────
$gradeRows = [];
$gradedPairs = [];
$currentEnrollments = [];   // [sid][section_id] = enrollment_id

foreach (COHORTS as $year) {
    foreach ($majors as $m) {
        $mid = (int)$m['major_id'];
        $g = $groups[$year][$mid] ?? null;
        if (!$g) continue;
        $secIds = array_map(fn($e) => (int)$e['section']['section_id'], $g['sections']);
        $assessments = [];
        $q = $pdo->prepare(
            'SELECT assessment_id, section_id, assessment_name, due_date
             FROM Assessment WHERE section_id IN (' . implode(',', array_map('intval', $secIds)) . ')
             ORDER BY section_id, due_date'
        );
        $q->execute();
        foreach ($q->fetchAll() as $a) $assessments[(int)$a['section_id']][] = $a;

        foreach ($g['students'] as $sid) {
            mt_srand(crc32($sid));
            $profile = profile_for_roll(mt_rand(1, 100));
            $studentAcc = null;

            foreach ($g['sections'] as $entry) {
                $sectionId = (int)$entry['section']['section_id'];
                $enrollmentId = $enrollmentMap[$sid . '|' . $sectionId] ?? 0;
                if (!$enrollmentId) continue;
                $isCurrent = str_starts_with($entry['role'], 'current');
                if ($isCurrent) $currentEnrollments[$sid][$sectionId] = $enrollmentId;

                $gradedBy = (int)$entry['section']['lecturer_id'] ?: $anyLecturer;
                $list = $assessments[$sectionId] ?? [];
                $count = count($list);
                foreach ($list as $idx => $a) {
                    // Missing-work variation for current courses.
                    if ($isCurrent) {
                        $missRoll = mt_rand(1, 100);
                        if ($missRoll <= 6) continue;                                  // missing both
                        if ($missRoll <= 12 && $idx === $count - 1) continue;          // missing midterm
                    }
                    $score = score_for_profile($profile, mt_rand(-6, 6));
                    if ($profile === 'weak' && $idx === 0) $score = min($score, (float)mt_rand(35, 50));
                    if ($profile === 'very_weak' && $idx === 0) $score = min($score, (float)mt_rand(25, 42));
                    $gradedAt = date('Y-m-d H:i:s', min(strtotime($a['due_date']) + 172800, time() - 86400));
                    $gradeRows[] = [
                        'enrollment_id' => $enrollmentId,
                        'assessment_id' => (int)$a['assessment_id'],
                        'score' => $score,
                        'feedback' => null,
                        'graded_by' => $gradedBy,
                        'graded_at' => $gradedAt,
                    ];
                }
            }
        }
    }
}
batch_insert($pdo, 'Grade', ['enrollment_id','assessment_id','score','feedback','graded_by','graded_at'], $gradeRows, 500);
echo "Grades prepared: " . count($gradeRows) . "\n";

// ───────────────────────────────────────────────────────────────
// 8. Attendance (official completed sessions only)
// ───────────────────────────────────────────────────────────────
$sessionsBySection = [];
$q = $pdo->query(
    "SELECT ats.session_id, ats.section_id, ats.session_date, ats.start_time
     FROM AttendanceSession ats
     JOIN ClassSection cs ON cs.section_id = ats.section_id
     WHERE cs.section_code LIKE 'D-%'
       AND ats.status = 'Scheduled'
       AND TIMESTAMP(ats.session_date, ats.start_time) <= NOW()
     ORDER BY ats.section_id, ats.session_date, ats.start_time"
);
foreach ($q->fetchAll() as $r) $sessionsBySection[(int)$r['section_id']][] = (int)$r['session_id'];

$attendanceRows = [];
$attendanceInserted = 0;
$attendanceTotal = 0;
foreach (COHORTS as $year) {
    foreach ($majors as $m) {
        $mid = (int)$m['major_id'];
        $g = $groups[$year][$mid] ?? null;
        if (!$g) continue;
        $officialTotal = [];
        foreach ($g['sections'] as $entry) {
            $officialTotal[(int)$entry['section']['section_id']] = (int)$entry['course']['credits'] === 2 ? 10 : 15;
        }

        foreach ($g['students'] as $sid) {
            foreach ($g['sections'] as $entry) {
                $sectionId = (int)$entry['section']['section_id'];
                $enrollmentId = $enrollmentMap[$sid . '|' . $sectionId] ?? 0;
                if (!$enrollmentId) continue;
                $sessions = $sessionsBySection[$sectionId] ?? [];
                if (!$sessions) continue;

                mt_srand(crc32($sid . 'att' . $sectionId));
                $roll = mt_rand(1, 100);
                $official = $officialTotal[$sectionId] ?? 15;
                $threshold = (int)floor($official * 0.2);
                if ($roll <= 65) {
                    $absences = mt_rand(0, 1);
                    $late = mt_rand(0, 1) === 1 ? 1 : 0;
                    $excused = 0;
                } elseif ($roll <= 80) {
                    $absences = mt_rand(1, 2);
                    $late = mt_rand(0, 1);
                    $excused = 0;
                } elseif ($roll <= 88) {
                    $absences = $threshold;                 // exactly at the 20% limit
                    $late = 0;
                    $excused = 0;
                } elseif ($roll <= 95) {
                    $absences = $threshold + mt_rand(1, 3); // over the limit
                    $late = 0;
                    $excused = 0;
                } else {
                    $absences = mt_rand(1, 2);
                    $late = 1;
                    $excused = mt_rand(1, 2);               // excused, not counted by policy
                }
                $absences = min($absences, count($sessions));
                $absentIdx = [];
                if ($absences > 0) {
                    $keys = array_rand(array_fill(0, count($sessions), true), $absences);
                    $absentIdx = array_flip(is_array($keys) ? $keys : [$keys]);
                }

                foreach ($sessions as $i => $sessionId) {
                    $status = 'Present';
                    if (isset($absentIdx[$i])) $status = 'Absent';
                    elseif ($late > 0) { $status = 'Late'; $late--; }
                    elseif ($excused > 0) { $status = 'Excused'; $excused--; }
                    $attendanceRows[] = [
                        'session_id' => $sessionId,
                        'enrollment_id' => $enrollmentId,
                        'status' => $status,
                        'note' => null,
                        'recorded_by' => $lecturerAccount[(int)$entry['section']['lecturer_id']] ?? $anyLecturerAccount,
                        'recorded_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
                    ];
                }
            }
        }

        if (count($attendanceRows) >= 2000) {
            $attendanceInserted += batch_insert($pdo, 'Attendance',
                ['session_id','enrollment_id','status','note','recorded_by','recorded_at'],
                $attendanceRows, 500);
            $attendanceTotal += count($attendanceRows);
            $attendanceRows = [];
            echo "  attendance batch inserted: $attendanceInserted\n";
        }
    }
}
if ($attendanceRows) {
    $attendanceInserted += batch_insert($pdo, 'Attendance',
        ['session_id','enrollment_id','status','note','recorded_by','recorded_at'],
        $attendanceRows, 500);
    $attendanceTotal += count($attendanceRows);
}
echo "Attendance prepared: $attendanceTotal, inserted: $attendanceInserted\n";

// ───────────────────────────────────────────────────────────────
// 9. Assignment submissions (only the C1 current section has assignments)
// ───────────────────────────────────────────────────────────────
$submissionRows = [];
foreach (COHORTS as $year) {
    foreach ($majors as $m) {
        $mid = (int)$m['major_id'];
        $g = $groups[$year][$mid] ?? null;
        if (!$g) continue;
        $c1 = null;
        foreach ($g['sections'] as $entry) if ($entry['role'] === 'current1') $c1 = $entry;
        if (!$c1) continue;
        $sectionId = (int)$c1['section']['section_id'];
        $q = $pdo->prepare("SELECT assignment_id, title, due_date FROM Assignment WHERE section_id=:s AND is_deleted=0 ORDER BY assignment_id");
        $q->execute([':s' => $sectionId]);
        $assignments = $q->fetchAll();
        if (!$assignments) continue;

        foreach ($g['students'] as $sid) {
            $enrollmentId = $enrollmentMap[$sid . '|' . $sectionId] ?? 0;
            if (!$enrollmentId) continue;
            mt_srand(crc32($sid . 'sub'));
            $roll = mt_rand(1, 100);
            foreach ($assignments as $idx => $a) {
                if ($roll <= 70) { /* submit both */ }
                elseif ($roll <= 85 && $idx === 0) { /* submit first only */ }
                else continue;
                $score = round(mt_rand(35, 95) / 10, 1);
                $submissionRows[] = [
                    'assignment_id' => (int)$a['assignment_id'],
                    'enrollment_id' => $enrollmentId,
                    'file_path' => null,
                    'file_name' => 'bai-nop-demo-' . $sid . '.pdf',
                    'file_size' => null,
                    'file_mime' => 'application/pdf',
                    'submitted_at' => date('Y-m-d H:i:s', strtotime($a['due_date']) - mt_rand(3600, 259200)),
                    'score' => $score,
                    'feedback' => 'Bài nộp demo phục vụ kiểm thử hệ thống.',
                    'grade_status' => 'Published',
                    'is_demo' => 1,
                    'version' => 1,
                    'content' => 'Nội dung bài nộp demo được tạo tự động.',
                    'revision' => 1,
                ];
            }
        }
    }
}
batch_insert($pdo, 'Submission', [
    'assignment_id','enrollment_id','file_path','file_name','file_size','file_mime',
    'submitted_at','score','feedback','grade_status','is_demo','version','content','revision',
], $submissionRows, 500);
echo "Submissions prepared: " . count($submissionRows) . "\n";

// ───────────────────────────────────────────────────────────────
// 10. Tuition invoices
// ───────────────────────────────────────────────────────────────
$tuitionRows = [];
foreach (COHORTS as $year) {
    foreach ($majors as $m) {
        $mid = (int)$m['major_id'];
        $g = $groups[$year][$mid] ?? null;
        if (!$g) continue;
        $currentCredits = 0;
        foreach ($g['sections'] as $entry) {
            if (str_starts_with($entry['role'], 'current')) $currentCredits += (int)$entry['course']['credits'];
        }
        foreach ($g['students'] as $sid) {
            mt_srand(crc32($sid . 'fee'));
            $roll = mt_rand(1, 100);
            $pastCredits1 = (int)$g['picked']['pastA']['credits'];
            $pastCredits2 = (int)$g['picked']['pastB']['credits'];
            $tuitionRows[] = [
                'student_id' => $sid, 'semester_id' => PAST_SEM_1,
                'credits' => $pastCredits1, 'amount_vnd' => $pastCredits1 * 800000,
                'due_date' => (new DateTimeImmutable($semesters[PAST_SEM_1]['start_date']))->modify('+30 day')->format('Y-m-d'),
                'status' => 'Paid', 'notes' => 'Học phí demo (seed v16)',
            ];
            $tuitionRows[] = [
                'student_id' => $sid, 'semester_id' => PAST_SEM_2,
                'credits' => $pastCredits2, 'amount_vnd' => $pastCredits2 * 800000,
                'due_date' => (new DateTimeImmutable($semesters[PAST_SEM_2]['start_date']))->modify('+30 day')->format('Y-m-d'),
                'status' => $roll <= 90 ? 'Paid' : 'Unpaid', 'notes' => 'Học phí demo (seed v16)',
            ];
            $currentStatus = $roll <= 55 ? 'Paid' : ($roll <= 80 ? 'Partial' : ($roll <= 95 ? 'Unpaid' : 'Waived'));
            $tuitionRows[] = [
                'student_id' => $sid, 'semester_id' => CURRENT_SEM,
                'credits' => $currentCredits, 'amount_vnd' => $currentCredits * 800000,
                'due_date' => (new DateTimeImmutable($semesters[CURRENT_SEM]['start_date']))->modify('+30 day')->format('Y-m-d'),
                'status' => $currentStatus, 'notes' => 'Học phí demo (seed v16)',
            ];
        }
    }
}
batch_insert($pdo, 'Tuition', ['student_id','semester_id','credits','amount_vnd','due_date','status','notes'], $tuitionRows, 500);
echo "Tuition invoices prepared: " . count($tuitionRows) . "\n";

// ───────────────────────────────────────────────────────────────
// 11. Academic warnings from the generated records
// ───────────────────────────────────────────────────────────────
if (!$skipWarnings) {
    require_once __DIR__ . '/../src/src/services/AcademicAlertService.php';
    $allIds = [];
    foreach (COHORTS as $year) foreach ($majors as $m) {
        $mid = (int)$m['major_id'];
        foreach (($groups[$year][$mid]['students'] ?? []) as $sid) $allIds[] = $sid;
    }
    $done = 0;
    foreach ($allIds as $sid) {
        try {
            AcademicAlertService::recalcGpa($sid);
        } catch (Throwable $e) {
            fwrite(STDERR, "Warning recalculation failed for $sid: " . $e->getMessage() . "\n");
        }
        $done++;
        if ($done % 250 === 0) echo "  warnings recalculated: $done / " . count($allIds) . "\n";
    }
    echo "Warnings recalculated for $done students\n";
} else {
    echo "Warnings skipped (--skip-warnings)\n";
}

// ───────────────────────────────────────────────────────────────
// 12. Report
// ───────────────────────────────────────────────────────────────
echo "\n=== Per-cohort totals ===\n";
foreach ($pdo->query(
    "SELECT admission_year, COUNT(*) students,
            SUM(is_demo=1) demo_students
     FROM Student GROUP BY admission_year ORDER BY admission_year"
)->fetchAll() as $r) {
    echo "  K{$r['admission_year']}: {$r['students']} students ({$r['demo_students']} demo)\n";
} 
echo "\n=== Students per major (all cohorts) ===\n";
foreach ($pdo->query(
    "SELECT m.major_code, m.major_name, COUNT(s.student_id) students, SUM(s.is_demo=1) demo
     FROM Major m LEFT JOIN Student s ON s.major_id = m.major_id
     GROUP BY m.major_id, m.major_code, m.major_name ORDER BY m.major_id"
)->fetchAll() as $r) {
    echo "  {$r['major_code']}: {$r['students']} ({$r['demo']} demo) – {$r['major_name']}\n";
}
$totalStudents = (int)$pdo->query('SELECT COUNT(*) FROM Student')->fetchColumn();
$studentAccounts = (int)$pdo->query("SELECT COUNT(*) FROM Account WHERE role='Student'")->fetchColumn();
$demoEnroll = (int)$pdo->query("SELECT COUNT(*) FROM Enrollment e JOIN ClassSection cs ON cs.section_id=e.section_id WHERE cs.section_code LIKE 'D-%'")->fetchColumn();
$demoGrades = (int)$pdo->query("SELECT COUNT(*) FROM Grade g JOIN Enrollment e ON e.enrollment_id=g.enrollment_id JOIN ClassSection cs ON cs.section_id=e.section_id WHERE cs.section_code LIKE 'D-%'")->fetchColumn();
$demoAtt = (int)$pdo->query("SELECT COUNT(*) FROM Attendance a JOIN Enrollment e ON e.enrollment_id=a.enrollment_id JOIN ClassSection cs ON cs.section_id=e.section_id WHERE cs.section_code LIKE 'D-%'")->fetchColumn();
$demoWarn = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning aw JOIN Student s ON s.student_id=aw.student_id WHERE s.is_demo=1")->fetchColumn();
echo "\nTotal students: $totalStudents | student accounts: $studentAccounts\n";
echo "Demo enrollments: $demoEnroll | grades: $demoGrades | attendance: $demoAtt\n";
echo "Demo warnings: $demoWarn\n";
echo "Demo password: " . DEMO_PASSWORD . " (bcrypt hash reused from existing accounts)\n";
echo "Done.\n";
