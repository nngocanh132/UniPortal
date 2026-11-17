<?php
/**
 * UniPortal – Seed v15: give the last unassigned lecturer a real section.
 *
 * The v14 catalog seed created one section per new course. Because the ICE
 * department received more lecturers than new courses, lecturer
 * gv.ice06@uniportal.edu.vn ended up without any ClassSection. This additive,
 * idempotent seed opens ICE2101-01 (Database Systems, 3 credits → 15 sessions)
 * in the active semester, with a weekly TeachingSchedule and the exact number
 * of sessions configured on the course.
 *
 * It never overwrites existing sections, schedules, sessions, enrollments or
 * lecturer assignments.
 *
 * Run:
 *   C:/xampp/php/php.exe database/seed_v15_lecturer_assignment.php
 */

require_once __DIR__ . '/../src/src/config/database.php';

$pdo = get_db();

$lecturerEmail = 'gv.ice06@uniportal.edu.vn';
$courseCode    = 'ICE2101';
$sectionCode   = 'ICE2101-01';
$semesterId    = 7;               // Active semester in this installation
$dayOfWeek     = 2;               // Tuesday (ISO-8601)
$startTime     = '15:00:00';
$endTime       = '16:30:00';
$room          = 'D1.301';
$adminId       = 25;              // academic_admin (created_by)

$stats = ['section_created' => 0, 'schedule_created' => 0,
          'sessions_created' => 0, 'period_links' => 0, 'errors' => []];

$lec = $pdo->prepare('SELECT lecturer_id, full_name FROM Lecturer WHERE email = :e LIMIT 1');
$lec->execute([':e' => $lecturerEmail]);
$lecturer = $lec->fetch();
if (!$lecturer) {
    fwrite(STDERR, "Lecturer $lecturerEmail not found.\n");
    exit(1);
}
$lecturerId = (int)$lecturer['lecturer_id'];

$courseQ = $pdo->prepare('SELECT course_id, course_name, credits, default_sessions
                          FROM Course WHERE course_code = :c LIMIT 1');
$courseQ->execute([':c' => $courseCode]);
$course = $courseQ->fetch();
if (!$course) {
    fwrite(STDERR, "Course $courseCode not found.\n");
    exit(1);
}
$courseId = (int)$course['course_id'];
$expected = (int)($course['default_sessions'] ?? 0);
if ($expected <= 0) {
    $expected = ((int)$course['credits'] === 2) ? 10 : 15;
}

$semQ = $pdo->prepare('SELECT start_date, end_date FROM Semester WHERE semester_id = :s LIMIT 1');
$semQ->execute([':s' => $semesterId]);
$sem = $semQ->fetch();
if (!$sem) {
    fwrite(STDERR, "Semester $semesterId not found.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    // 1. Section (idempotent by section_code + semester)
    $find = $pdo->prepare('SELECT section_id, lecturer_id FROM ClassSection
                           WHERE section_code = :code AND semester_id = :sem LIMIT 1');
    $find->execute([':code' => $sectionCode, ':sem' => $semesterId]);
    $section = $find->fetch();

    if ($section) {
        $sectionId = (int)$section['section_id'];
        echo "Section $sectionCode already exists (#$sectionId); left untouched.\n";
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO ClassSection
                (course_id, semester_id, lecturer_id, section_code, schedule, room,
                 capacity, min_enrollment, max_enrollment, section_status)
             VALUES (:course, :sem, :lec, :code, :sched, :room, 60, 48, 64, \'Pending\')'
        );
        $ins->execute([
            ':course' => $courseId, ':sem' => $semesterId, ':lec' => $lecturerId,
            ':code' => $sectionCode, ':sched' => 'Thứ 3, 15:00–16:30', ':room' => $room,
        ]);
        $sectionId = (int)$pdo->lastInsertId();
        $stats['section_created'] = 1;
        echo "Created section $sectionCode (#$sectionId) for {$lecturer['full_name']}.\n";
    }

    // 2. Weekly teaching schedule (one per section)
    $tsQ = $pdo->prepare('SELECT schedule_id FROM TeachingSchedule WHERE section_id = :sid LIMIT 1');
    $tsQ->execute([':sid' => $sectionId]);
    $tsId = (int)$tsQ->fetchColumn();
    if (!$tsId) {
        $insTs = $pdo->prepare(
            'INSERT INTO TeachingSchedule
                (section_id, semester_id, lecturer_id, day_of_week, start_time, end_time,
                 room, effective_start, effective_end, expected_sessions, status, created_by)
             VALUES (:sid, :sem, :lid, :dow, :st, :et, :room, :start, :end, :exp, \'Active\', :by)'
        );
        $insTs->execute([
            ':sid' => $sectionId, ':sem' => $semesterId, ':lid' => $lecturerId,
            ':dow' => $dayOfWeek, ':st' => $startTime, ':et' => $endTime, ':room' => $room,
            ':start' => $sem['start_date'], ':end' => $sem['end_date'],
            ':exp' => $expected, ':by' => $adminId,
        ]);
        $tsId = (int)$pdo->lastInsertId();
        $stats['schedule_created'] = 1;
    }

    // 3. Exactly $expected weekly sessions, never fewer or more
    $cntQ = $pdo->prepare('SELECT COUNT(*) FROM AttendanceSession
                           WHERE section_id = :sid AND status <> \'Cancelled\'');
    $cntQ->execute([':sid' => $sectionId]);
    $existing = (int)$cntQ->fetchColumn();
    if ($existing < $expected) {
        $maxQ = $pdo->prepare('SELECT COALESCE(MAX(session_no),0) FROM AttendanceSession WHERE section_id = :sid');
        $maxQ->execute([':sid' => $sectionId]);
        $no = (int)$maxQ->fetchColumn();

        // First occurrence of the weekday on/after the semester start, then weekly.
        $date = new DateTime($sem['start_date']);
        $iso = (int)$date->format('N');
        if ($iso !== $dayOfWeek) $date->modify('+' . (($dayOfWeek - $iso + 7) % 7) . ' days');

        $dupQ = $pdo->prepare('SELECT 1 FROM AttendanceSession WHERE section_id = :sid
                               AND session_date = :d AND start_time = :st LIMIT 1');
        $insS = $pdo->prepare(
            'INSERT INTO AttendanceSession
                (section_id, schedule_id, lecturer_id, session_no, session_date,
                 start_time, end_time, room, topic, session_type, status)
             VALUES (:sid, :ts, :lid, :no, :date, :st, :et, :room, :topic, \'Regular\', \'Scheduled\')'
        );
        while ($existing < $expected) {
            $d = $date->format('Y-m-d');
            $dupQ->execute([':sid' => $sectionId, ':d' => $d, ':st' => $startTime]);
            if (!$dupQ->fetchColumn()) {
                $no++;
                $insS->execute([
                    ':sid' => $sectionId, ':ts' => $tsId ?: null, ':lid' => $lecturerId,
                    ':no' => $no, ':date' => $d, ':st' => $startTime, ':et' => $endTime,
                    ':room' => $room, ':topic' => 'Buổi ' . $no . ' – ' . $course['course_name'],
                ]);
                $existing++;
                $stats['sessions_created']++;
            }
            $date->modify('+7 days');
        }
    }

    // 4. Open the section in every open registration period of the semester
    $rpQ = $pdo->prepare('SELECT period_id FROM RegistrationPeriod
                          WHERE semester_id = :sem AND status = \'Open\'');
    $rpQ->execute([':sem' => $semesterId]);
    $link = $pdo->prepare('INSERT IGNORE INTO RegistrationPeriodSection (period_id, section_id)
                           VALUES (:p, :s)');
    foreach ($rpQ->fetchAll(PDO::FETCH_COLUMN) as $periodId) {
        $link->execute([':p' => (int)$periodId, ':s' => $sectionId]);
        $stats['period_links'] += $link->rowCount();
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "section created : {$stats['section_created']}\n";
echo "schedule created: {$stats['schedule_created']}\n";
echo "sessions created: {$stats['sessions_created']} (target $expected)\n";
echo "period links    : {$stats['period_links']}\n";
