<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/src/models/TeachingSchedule.php';
require_once __DIR__ . '/../src/src/models/Lecturer.php';
require_once __DIR__ . '/../src/src/services/AssignmentService.php';

$pdo = get_db();
$passed = 0;
function verify(bool $condition, string $label): void {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $passed++;
    print "PASS: $label\n";
}
function query(string $sql, array $args = []): PDOStatement {
    $q = get_db()->prepare($sql);
    $q->execute($args);
    return $q;
}
function digest(string $table): string {
    return hash('sha256', json_encode(query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll()));
}
$tables = ['Student','Enrollment','AttendanceSession','Attendance','Assignment','Submission','Grade','TeachingSchedule',
    'Semester','ClassSection','ScheduleChangeRequest','AuditLog','Notification','SubmissionAttempt','SubmissionGradeHistory'];
$before = [];
foreach ($tables as $table) $before[$table] = digest($table);
$pdo->beginTransaction();
try {
    $admin = (int)query("SELECT account_id FROM Account WHERE role = 'AcademicAffairs' LIMIT 1")->fetchColumn();
    $lecturer = query("SELECT lecturer_id, account_id FROM Account WHERE role = 'Lecturer' AND is_active = 1 ORDER BY account_id LIMIT 1")->fetch();
    $otherLecturer = (int)query('SELECT lecturer_id FROM Lecturer WHERE lecturer_id <> ? LIMIT 1', [$lecturer['lecturer_id']])->fetchColumn();
    $student = query("SELECT student_id, account_id FROM Account WHERE role = 'Student' AND is_active = 1 ORDER BY account_id LIMIT 1")->fetch();
    $course3 = (int)query('SELECT course_id FROM Course WHERE credits = 3 LIMIT 1')->fetchColumn();
    $course2 = (int)query('SELECT course_id FROM Course WHERE credits = 2 LIMIT 1')->fetchColumn();
    verify($admin > 0 && $course3 > 0 && $course2 > 0 && (bool)$student, 'Existing course/student/lecturer fixtures available');
    $start = new DateTimeImmutable('first monday of January 2035');
    $end = $start->modify('+24 weeks');
    query("INSERT INTO Semester (semester_name, academic_year, start_date, end_date, status) VALUES ('[VERIFY] workflows', '2035-2036', ?, ?, 'Upcoming')", [$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $semester = (int)$pdo->lastInsertId();
    $sections = [];
    foreach ([3 => $course3, 2 => $course2] as $credits => $course) {
        query('INSERT INTO ClassSection (course_id, semester_id, lecturer_id, section_code, room, capacity) VALUES (?, ?, ?, ?, ?, 64)',
            [$course, $semester, $lecturer['lecturer_id'], 'VERIFY-' . $credits . '-' . bin2hex(random_bytes(3)), 'VERIFY-' . $credits]);
        $sections[$credits] = (int)$pdo->lastInsertId();
    }
    $base = ['lecturer_id' => $lecturer['lecturer_id'], 'start_time' => '07:30', 'end_time' => '09:00',
        'effective_start' => $start->format('Y-m-d'), 'effective_end' => $end->format('Y-m-d')];
    foreach ([3 => 1, 2 => 3] as $credits => $day) {
        $input = $base + ['section_id' => $sections[$credits], 'day_of_week' => $day, 'room' => 'VERIFY-' . $credits];
        $result = TeachingSchedule::saveSchedule($input, $admin);
        verify($result['ok'], 'Admin saves ' . $credits . '-credit timetable: ' . $result['message']);
        $rows = query('SELECT * FROM AttendanceSession WHERE section_id = ? ORDER BY session_no', [$sections[$credits]])->fetchAll();
        verify(count($rows) === $credits * 5, 'Exactly ' . ($credits * 5) . ' sessions generated');
        verify(array_column($rows, 'session_no') == range(1, $credits * 5), 'Unique sequential session numbers');
        verify(count(array_filter($rows, fn($s) => (int)date('N', strtotime($s['session_date'])) === $day)) === count($rows), 'All dates match approved weekday');
        verify(TeachingSchedule::saveSchedule($input, $admin)['ok'], 'Repeated schedule save succeeds');
        verify((int)query('SELECT COUNT(*) FROM AttendanceSession WHERE section_id = ?', [$sections[$credits]])->fetchColumn() === count($rows), 'Repeated save creates no duplicate sessions');
    }
    $rows = TeachingSchedule::getLecturerSessions((int)$lecturer['lecturer_id'], $semester);
    verify(count($rows) === 25, 'Lecturer sees all 25 generated sessions');
    foreach ($sections as $credits => $section) {
        query("INSERT INTO Enrollment (student_id, section_id, status) VALUES (?, ?, 'Enrolled')", [$student['student_id'], $section]);
        $enrollments[$credits] = (int)$pdo->lastInsertId();
    }
    verify(count(TeachingSchedule::getStudentTimetable($student['student_id'], $semester)) === 25, 'Student timetable uses same sessions');
    $session = query('SELECT * FROM AttendanceSession WHERE section_id = ? ORDER BY session_no LIMIT 1', [$sections[3]])->fetch();
    $saved = LecturerModel::saveAttendance((int)$session['session_id'], [$enrollments[3] => ['status' => 'Present', 'note' => 'verification']], (int)$lecturer['account_id'], (int)$lecturer['lecturer_id']);
    verify($saved === 1, 'Lecturer records attendance for scheduled session');
    verify(LecturerModel::saveAttendance((int)$session['session_id'], [$enrollments[2] => ['status' => 'Absent']], (int)$lecturer['account_id'], (int)$lecturer['lecturer_id']) === 0, 'Reject cross-section attendance');
    verify(LecturerModel::saveAttendance((int)$session['session_id'], [$enrollments[3] => ['status' => 'Absent']], (int)$lecturer['account_id'], $otherLecturer) === 0, 'Reject unauthorized lecturer attendance');
    $attendance = query('SELECT * FROM Attendance WHERE session_id = ?', [$session['session_id']])->fetchAll();
    $changed = array_merge($base, ['section_id' => $sections[3], 'day_of_week' => 4, 'room' => 'VERIFY-NEW', 'start_time' => '10:00', 'end_time' => '11:30']);
    verify(TeachingSchedule::saveSchedule($changed, $admin)['ok'], 'Admin changes future timetable');
    verify(query('SELECT * FROM AttendanceSession WHERE session_id = ?', [$session['session_id']])->fetch() === $session, 'Completed session metadata and number preserved');
    verify(query('SELECT * FROM Attendance WHERE session_id = ?', [$session['session_id']])->fetchAll() === $attendance, 'Historical attendance preserved byte-for-byte');
    verify((int)query('SELECT COUNT(*) FROM AttendanceSession WHERE section_id = ?', [$sections[3]])->fetchColumn() === 15, 'History counts toward expected total after change');
    $scheduleBefore = query('SELECT * FROM TeachingSchedule WHERE section_id = ?', [$sections[3]])->fetch();
    $tooShort = array_merge($changed, ['effective_end' => $start->modify('+1 week')->format('Y-m-d')]);
    verify(!TeachingSchedule::saveSchedule($tooShort, $admin)['ok'], 'Insufficient calendar is rejected');
    verify(query('SELECT * FROM TeachingSchedule WHERE section_id = ?', [$sections[3]])->fetch() === $scheduleBefore, 'Failed schedule save leaves official schedule unchanged');
    verify(!TeachingSchedule::saveSchedule(array_merge($changed, ['expected_sessions' => -1]), $admin)['ok'], 'Invalid session count is rejected');
    verify(!TeachingSchedule::saveSchedule(array_merge($changed, ['effective_start' => '2035-02-30']), $admin)['ok'], 'Invalid calendar date is rejected');
    verify(TeachingSchedule::saveSchedule(array_merge($changed, ['expected_sessions' => 12]), $admin)['ok'], 'Admin can override expected sessions');
    verify((int)query('SELECT COUNT(*) FROM AttendanceSession WHERE section_id = ?', [$sections[3]])->fetchColumn() === 12, 'Override generates exactly 12 while preserving recorded session');

    // Request workflows use only the generated sections, inside the same outer rollback.
    $requestFor = fn(int $sessionId, string $type) => query(
        'SELECT * FROM ScheduleChangeRequest WHERE session_id = ? AND request_type = ? ORDER BY request_id DESC LIMIT 1',
        [$sessionId, $type]
    )->fetch();
    $officialState = fn() => [
        query('SELECT * FROM TeachingSchedule WHERE section_id IN (?, ?) ORDER BY schedule_id', array_values($sections))->fetchAll(),
        query('SELECT * FROM AttendanceSession WHERE section_id IN (?, ?) ORDER BY session_id', array_values($sections))->fetchAll(),
    ];
    $review = function(int $requestId, string $decision, string $note = '', ?int $reviewer = null) use ($pdo, $admin): array {
        $result = TeachingSchedule::reviewRequest($requestId, $reviewer ?? $admin, $decision, $note);
        verify($pdo->inTransaction(), 'Outer transaction survives ' . $decision . ' review #' . $requestId);
        return $result;
    };
    $noticeCount = fn(int $accountId, string $title) => (int)query(
        "SELECT COUNT(*) FROM Notification WHERE account_id = ? AND type = 'Schedule' AND title = ?", [$accountId, $title]
    )->fetchColumn();
    $requestSession = query(
        "SELECT ats.* FROM AttendanceSession ats WHERE section_id = ? AND status = 'Scheduled'
         AND NOT EXISTS (SELECT 1 FROM Attendance a WHERE a.session_id = ats.session_id) ORDER BY session_no LIMIT 1",
        [$sections[3]]
    )->fetch();
    verify((bool)$requestSession, 'Generated future session available for request workflow');
    $requestSessionId = (int)$requestSession['session_id'];
    $pendingState = $officialState();
    verify(!TeachingSchedule::createCancellationRequest($requestSessionId, $otherLecturer, (int)$lecturer['account_id'], '[VERIFY] unauthorized cancellation')['ok'], 'Wrong lecturer cannot request cancellation');
    verify($requestFor($requestSessionId, 'Cancellation') === false, 'Unauthorized cancellation creates no request');
    $result = TeachingSchedule::createCancellationRequest($requestSessionId, (int)$lecturer['lecturer_id'], (int)$lecturer['account_id'], '[VERIFY] planned absence');
    verify($result['ok'], 'Lecturer requests cancellation: ' . $result['message']);
    $cancelRequest = $requestFor($requestSessionId, 'Cancellation');
    verify($cancelRequest && $cancelRequest['status'] === 'Pending', 'Cancellation request saved pending');
    $cancelRequestId = (int)$cancelRequest['request_id'];
    verify($officialState() === $pendingState, 'Cancellation request does not change official schedule or sessions');
    verify(!TeachingSchedule::createCancellationRequest($requestSessionId, (int)$lecturer['lecturer_id'], (int)$lecturer['account_id'], '[VERIFY] duplicate absence')['ok'], 'Duplicate pending cancellation rejected');
    verify((int)query("SELECT COUNT(*) FROM ScheduleChangeRequest WHERE session_id = ? AND request_type = 'Cancellation'", [$requestSessionId])->fetchColumn() === 1, 'Only one cancellation request exists');

    $makeupDate = $start->modify('+1 day')->format('Y-m-d');
    $requestMakeup = fn(int $who) => TeachingSchedule::createMakeupRequest(
        $requestSessionId, $who, (int)$lecturer['account_id'], $makeupDate,
        '13:00', '14:30', 'VERIFY-MAKEUP', '[VERIFY] replace cancelled lesson'
    );
    verify(!$requestMakeup($otherLecturer)['ok'], 'Wrong lecturer cannot request makeup');
    verify($requestFor($requestSessionId, 'Makeup') === false, 'Unauthorized makeup creates no request');
    $result = $requestMakeup((int)$lecturer['lecturer_id']);
    verify($result['ok'], 'Makeup can be requested while cancellation is pending: ' . $result['message']);
    $makeupRequest = $requestFor($requestSessionId, 'Makeup');
    verify($makeupRequest && $makeupRequest['status'] === 'Pending', 'Makeup request saved pending');
    $makeupRequestId = (int)$makeupRequest['request_id'];
    verify(!$requestMakeup((int)$lecturer['lecturer_id'])['ok'], 'Duplicate pending makeup rejected');
    verify($officialState() === $pendingState, 'Pending makeup creates no session or schedule changes');
    $notificationBefore = digest('Notification');
    $auditBefore = digest('AuditLog');
    $result = $review($makeupRequestId, 'Approved');
    verify(!$result['ok'] && $result['message'] === 'Phải duyệt hủy buổi gốc trước khi duyệt học bù.', 'Makeup approval blocked until original cancellation approved');
    verify($requestFor($requestSessionId, 'Makeup') === $makeupRequest && $officialState() === $pendingState, 'Blocked approval preserves pending request and official schedule');
    verify(digest('Notification') === $notificationBefore && digest('AuditLog') === $auditBefore, 'Blocked approval sends no outcome notifications or audit entries');

    // A foreign-key failure after the session update must roll back only the review.
    $result = $review($cancelRequestId, 'Approved', '[VERIFY] invalid reviewer', -1);
    verify(!$result['ok'], 'Invalid reviewer rejects approval after attempted session update');
    verify($officialState() === $pendingState && $requestFor($requestSessionId, 'Cancellation') === $cancelRequest, 'Failed review rolls back its writes without losing earlier fixtures or requests');
    verify(digest('Notification') === $notificationBefore && digest('AuditLog') === $auditBefore, 'Failed review leaves audit and notifications unchanged');

    $lecturerNotices = $noticeCount((int)$lecturer['account_id'], 'Buổi học đã được hủy');
    $studentNotices = $noticeCount((int)$student['account_id'], 'Lịch học thay đổi: buổi học bị hủy');
    $result = $review($cancelRequestId, 'Approved', '[VERIFY] cancellation approved');
    verify($result['ok'] && $result['created_session_id'] === null, 'Admin approves cancellation without creating another session');
    $cancelledSession = query('SELECT * FROM AttendanceSession WHERE session_id = ?', [$requestSessionId])->fetch();
    verify($cancelledSession && $cancelledSession['cancelled_at'] !== null
        && $cancelledSession === array_replace($requestSession, ['status' => 'Cancelled', 'cancelled_at' => $cancelledSession['cancelled_at']]), 'Approval changes original status and timestamp without deleting or renumbering it');
    $cancelledState = $pendingState;
    foreach ($cancelledState[1] as &$row) {
        if ((int)$row['session_id'] === $requestSessionId) $row = $cancelledSession;
    }
    unset($row);
    verify($officialState() === $cancelledState, 'Cancellation preserves every other session and weekly schedule');
    $approvedCancellation = $requestFor($requestSessionId, 'Cancellation');
    verify($approvedCancellation['status'] === 'Approved' && (int)$approvedCancellation['reviewed_by'] === $admin
        && $approvedCancellation['reviewed_at'] !== null && $approvedCancellation['admin_note'] === '[VERIFY] cancellation approved', 'Cancellation approval retains review history');
    verify($noticeCount((int)$lecturer['account_id'], 'Buổi học đã được hủy') === $lecturerNotices + 1
        && $noticeCount((int)$student['account_id'], 'Lịch học thay đổi: buổi học bị hủy') === $studentNotices + 1, 'Cancellation approval notifies lecturer and enrolled student');
    $notificationBefore = digest('Notification');
    $result = $review($cancelRequestId, 'Approved');
    verify(!$result['ok'] && $result['message'] === 'Đề xuất này đã được xử lý (Approved).', 'Repeat cancellation approval blocked');
    verify($requestFor($requestSessionId, 'Cancellation') === $approvedCancellation && $officialState() === $cancelledState
        && digest('Notification') === $notificationBefore, 'Repeated approval preserves history and sends no duplicate notification');

    $nextSessionNo = (int)query('SELECT MAX(session_no) + 1 FROM AttendanceSession WHERE section_id = ?', [$sections[3]])->fetchColumn();
    $lecturerNotices = $noticeCount((int)$lecturer['account_id'], 'Buổi học bù đã được duyệt');
    $studentNotices = $noticeCount((int)$student['account_id'], 'Lịch học thay đổi: buổi học bù mới');
    $result = $review($makeupRequestId, 'Approved', '[VERIFY] makeup approved');
    verify($result['ok'] && (int)$result['created_session_id'] > 0, 'Makeup approval creates an official session');
    $makeupSessionId = (int)$result['created_session_id'];
    $makeupSession = query('SELECT * FROM AttendanceSession WHERE session_id = ?', [$makeupSessionId])->fetch();
    verify($makeupSession && $makeupSession['session_type'] === 'Makeup' && $makeupSession['status'] === 'Scheduled'
        && (int)$makeupSession['section_id'] === $sections[3] && (int)$makeupSession['lecturer_id'] === (int)$lecturer['lecturer_id']
        && (int)$makeupSession['original_session_id'] === $requestSessionId && $makeupSession['schedule_id'] === $requestSession['schedule_id'], 'Makeup session linked to original, section, lecturer and schedule');
    verify((int)$makeupSession['session_no'] === $nextSessionNo && $makeupSession['session_date'] === $makeupDate
        && $makeupSession['start_time'] === '13:00:00' && $makeupSession['end_time'] === '14:30:00'
        && $makeupSession['room'] === 'VERIFY-MAKEUP', 'Makeup uses next session number and requested date, time and room');
    $numbers = query('SELECT COUNT(*) AS total, COUNT(DISTINCT session_no) AS unique_numbers FROM AttendanceSession WHERE section_id = ?', [$sections[3]])->fetch();
    verify((int)$numbers['total'] === 13 && $numbers['total'] === $numbers['unique_numbers'], 'Makeup adds exactly one uniquely numbered session after count override');
    $approvedMakeup = $requestFor($requestSessionId, 'Makeup');
    verify($approvedMakeup['status'] === 'Approved' && (int)$approvedMakeup['created_session_id'] === $makeupSessionId
        && (int)$approvedMakeup['reviewed_by'] === $admin && $approvedMakeup['reviewed_at'] !== null, 'Approved makeup request retains created-session and reviewer history');
    foreach (['Student' => TeachingSchedule::getStudentTimetable($student['student_id'], $semester),
        'Lecturer' => TeachingSchedule::getLecturerSessions((int)$lecturer['lecturer_id'], $semester)] as $viewer => $visible) {
        $visible = array_column($visible, null, 'session_id');
        verify(isset($visible[$makeupSessionId], $visible[$requestSessionId])
            && $visible[$makeupSessionId]['session_type'] === 'Makeup' && $visible[$makeupSessionId]['status'] === 'Scheduled'
            && (int)$visible[$makeupSessionId]['original_session_id'] === $requestSessionId
            && $visible[$requestSessionId]['status'] === 'Cancelled', $viewer . ' sees makeup and retained cancelled original');
    }
    verify($noticeCount((int)$lecturer['account_id'], 'Buổi học bù đã được duyệt') === $lecturerNotices + 1
        && $noticeCount((int)$student['account_id'], 'Lịch học thay đổi: buổi học bù mới') === $studentNotices + 1, 'Makeup approval notifies lecturer and enrolled student');
    $makeupState = $officialState();
    $result = $review($makeupRequestId, 'Approved');
    verify(!$result['ok'] && $result['message'] === 'Đề xuất này đã được xử lý (Approved).', 'Repeat makeup approval blocked');
    verify(!$requestMakeup((int)$lecturer['lecturer_id'])['ok'], 'Duplicate makeup request blocked after approval');
    verify($requestFor($requestSessionId, 'Makeup') === $approvedMakeup && $officialState() === $makeupState
        && (int)query("SELECT COUNT(*) FROM ScheduleChangeRequest WHERE session_id = ? AND request_type = 'Makeup'", [$requestSessionId])->fetchColumn() === 1
        && (int)query('SELECT COUNT(*) FROM AttendanceSession WHERE original_session_id = ?', [$requestSessionId])->fetchColumn() === 1, 'Duplicate attempts preserve one request and one makeup session');

    $rejectSessionId = (int)query('SELECT session_id FROM AttendanceSession WHERE section_id = ? ORDER BY session_no LIMIT 1', [$sections[2]])->fetchColumn();
    $result = TeachingSchedule::createCancellationRequest($rejectSessionId, (int)$lecturer['lecturer_id'], (int)$lecturer['account_id'], '[VERIFY] absence for rejection');
    verify($result['ok'], 'Lecturer creates separate cancellation for rejection');
    $rejectRequest = $requestFor($rejectSessionId, 'Cancellation');
    $result = $review((int)$rejectRequest['request_id'], 'Rejected', '   ');
    verify(!$result['ok'] && $result['message'] === 'Vui lòng nhập lý do từ chối để lưu vào hồ sơ.', 'Rejection requires a nonblank note');
    verify($requestFor($rejectSessionId, 'Cancellation') === $rejectRequest && $officialState() === $makeupState, 'Missing rejection note preserves pending request and schedule');
    $result = $review((int)$rejectRequest['request_id'], 'Rejected', '  [VERIFY] keep scheduled lesson  ');
    verify($result['ok'], 'Admin rejects request with a note');
    $rejected = $requestFor($rejectSessionId, 'Cancellation');
    verify($rejected['status'] === 'Rejected' && $rejected['admin_note'] === '[VERIFY] keep scheduled lesson'
        && (int)$rejected['reviewed_by'] === $admin && $rejected['reviewed_at'] !== null && $rejected['created_session_id'] === null, 'Rejection stores trimmed note and reviewer history');
    verify($officialState() === $makeupState, 'Rejection preserves all official schedule and session records');

    $result = TeachingSchedule::createCancellationRequest((int)$session['session_id'], (int)$lecturer['lecturer_id'], (int)$lecturer['account_id'], '[VERIFY] attendance-bearing cancellation');
    verify($result['ok'], 'Attendance-bearing future session cancellation can be submitted for review');
    $attendanceRequest = $requestFor((int)$session['session_id'], 'Cancellation');
    $result = $review((int)$attendanceRequest['request_id'], 'Approved');
    verify(!$result['ok'] && $result['message'] === 'Không thể hủy buổi đã diễn ra hoặc đã có điểm danh.', 'Attendance-bearing session cancellation approval rejected');
    verify($requestFor((int)$session['session_id'], 'Cancellation') === $attendanceRequest && $officialState() === $makeupState
        && query('SELECT * FROM Attendance WHERE session_id = ?', [$session['session_id']])->fetchAll() === $attendance, 'Rejected cancellation preserves attendance, session and pending request');

    query("INSERT INTO Assignment (section_id, title, description, instructions, due_date, max_score, status, created_by, is_demo)
           VALUES (?, '[VERIFY] report', 'Analysis report', 'Explain assumptions and conclusions.', DATE_ADD(NOW(), INTERVAL 7 DAY), 10, 'Published', ?, 1)", [$sections[3], $lecturer['account_id']]);
    $assignment = (int)$pdo->lastInsertId();
    $submit = fn($version, $content = 'A complete analysis with recommendations.') => AssignmentService::submit($assignment, $enrollments[3], $student['student_id'], (int)$student['account_id'], $content, [], $version);
    verify(!AssignmentService::submit($assignment, $enrollments[2], $student['student_id'], (int)$student['account_id'], 'Invalid enrollment', [], 0)['ok'], 'Reject cross-section submission');
    query("UPDATE Enrollment SET status = 'Withdrawn' WHERE enrollment_id = ?", [$enrollments[3]]);
    verify(!$submit(0)['ok'], 'Reject withdrawn enrollment');
    query("UPDATE Enrollment SET status = 'Enrolled' WHERE enrollment_id = ?", [$enrollments[3]]);
    verify(!$submit(0, '')['ok'], 'Reject empty submission');
    $result = $submit(0);
    verify($result['ok'], 'Enrolled student submits assignment content');
    $submission = $result['submission_id'];
    verify(!$submit(0)['ok'], 'Duplicate/stale submission rejected');
    $current = fn() => query('SELECT * FROM Submission WHERE submission_id = ?', [$submission])->fetch();
    $grade = function($score, $publish = false, $who = null, $version = null, $revision = null) use ($current, $submission, $lecturer) {
        $s = $current();
        return AssignmentService::grade($submission, $who ?? (int)$lecturer['lecturer_id'], (int)$lecturer['account_id'], $score, "Detailed feedback\nSecond line", $publish, $version ?? (int)$s['version'], $revision ?? (int)$s['revision']);
    };
    verify(!$grade('8', false, $otherLecturer)['ok'], 'Reject unauthorized lecturer grading');
    verify(!$grade('not-a-score')['ok'], 'Reject nonnumeric grade');
    verify(!$grade('10.01')['ok'], 'Reject grade above assignment maximum');
    verify(!$grade('', true)['ok'], 'Reject publication without a grade');
    verify($grade('8.75')['ok'], 'Save precise draft grade and multiline feedback');
    $old = $current();
    verify(!$grade('9', false, null, 1, 1)['ok'], 'Reject stale grade revision');
    verify($submit(1, 'Revised analysis with supporting evidence.')['ok'], 'Resubmission creates a new version');
    $archived = query('SELECT * FROM SubmissionAttempt WHERE submission_id = ?', [$submission])->fetch();
    verify($archived && $archived['content'] === $old['content'] && $archived['score'] === $old['score'], 'Previous content and draft grade preserved in immutable attempt');
    verify($current()['score'] === null && (int)$current()['version'] === 2, 'New attempt resets only current grade');
    verify(!$grade('9', true, null, 1, (int)$current()['revision'])['ok'], 'Cannot grade an obsolete attempt');
    query('UPDATE Assignment SET allow_resubmission = 0 WHERE assignment_id = ?', [$assignment]);
    verify(!$submit(2)['ok'], 'Assignment resubmission policy enforced');
    query('UPDATE Assignment SET allow_resubmission = 1 WHERE assignment_id = ?', [$assignment]);
    verify($grade('9.25', true)['ok'], 'Publish grade and feedback for current attempt');
    verify($current()['grade_status'] === 'Published' && $current()['score'] === '9.25', 'Published grade saved to real database');
    verify(!$submit(2)['ok'], 'Published grade locks student resubmission');
    verify($grade('9.50', true)['ok'], 'Lecturer can revise a published grade');
    verify((int)query('SELECT COUNT(*) FROM SubmissionGradeHistory WHERE submission_id = ?', [$submission])->fetchColumn() === 4, 'All grade changes and resubmission reset audited');
    verify((int)query('SELECT COUNT(*) FROM Submission WHERE assignment_id = ? AND enrollment_id = ?', [$assignment, $enrollments[3]])->fetchColumn() === 1, 'Exactly one current submission per student and assignment');
    query("UPDATE Assignment SET status = 'Closed' WHERE assignment_id = ?", [$assignment]);
    verify(!$submit(2)['ok'], 'Closed assignment rejects submissions');
    $pdo->rollBack();
    foreach ($tables as $table) verify(digest($table) === $before[$table], $table . ' baseline unchanged after test rollback');
    print "\n$passed checks passed. All test records rolled back.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
