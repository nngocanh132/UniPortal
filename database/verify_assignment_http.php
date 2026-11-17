<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/src/config/database.php';

$pdo = get_db();
$base = 'http://localhost/UniPortal/public/';
$root = dirname(__DIR__);
$passed = 0;
$temporary = [];
function check_http(bool $ok, string $label): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $passed++;
    print "PASS: $label\n";
}
function db_http(string $sql, array $args = []): PDOStatement {
    $q = get_db()->prepare($sql);
    $q->execute($args);
    return $q;
}
function request_http(string $cookie, string $path, ?array $post = null): array {
    global $base;
    $headers = '';
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 45,
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) { $headers .= $line; return strlen($line); },
    ]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException(curl_error($curl));
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL), 'body' => $body, 'headers' => $headers];
    curl_close($curl);
    if (preg_match('/<b>(?:Fatal error|Warning|Notice)<\/b>/', $body)) throw new RuntimeException('PHP error on ' . $path);
    return $result;
}
function input_http(string $body, string $name): string {
    $doc = new DOMDocument();
    @$doc->loadHTML($body);
    foreach ($doc->getElementsByTagName('input') as $input) {
        if ($input->getAttribute('name') === $name) return $input->getAttribute('value');
    }
    throw new RuntimeException('Missing form input ' . $name);
}
function login_http(string $user): string {
    global $temporary;
    $cookie = tempnam(sys_get_temp_dir(), 'uni_http_');
    $temporary[] = $cookie;
    $page = request_http($cookie, 'index.php');
    $post = ['username' => $user, 'password' => 'password'];
    if (str_contains($page['body'], 'name="_csrf"')) $post['_csrf'] = input_http($page['body'], '_csrf');
    $page = request_http($cookie, 'index.php', $post);
    check_http(!str_ends_with($page['url'], '/index.php'), 'Real login: ' . $user);
    return $cookie;
}

$before = [];
foreach (['Student', 'Enrollment', 'Attendance', 'Grade'] as $table) {
    $before[$table] = db_http('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
}
try {
    $title = '[DEMO HTTP] Bao cao kiem thu nop bai va cham diem';
    if (db_http('SELECT 1 FROM Assignment WHERE title = ?', [$title])->fetchColumn()) {
        throw new RuntimeException('HTTP demo already exists; it is intentionally preserved. Use the transactional verifier for repeat runs.');
    }
    $section = db_http("SELECT cs.section_id, e.enrollment_id FROM ClassSection cs JOIN Course c USING(course_id) JOIN Semester sem USING(semester_id) JOIN Enrollment e USING(section_id) WHERE c.course_code='INS3056' AND cs.lecturer_id=1 AND sem.status='Active' AND e.student_id='23070001' AND e.status='Enrolled' ORDER BY cs.section_id LIMIT 1")->fetch();
    check_http((bool)$section, 'Real enrolled student and assigned lecturer fixtures');
    $fixtures = [];
    foreach (db_http('SELECT aa.file_path, aa.file_name FROM AssignmentAttachment aa JOIN Assignment a USING(assignment_id) WHERE a.is_demo=1 ORDER BY aa.attachment_id')->fetchAll() as $file) {
        $ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
        if (!isset($fixtures[$ext]) && is_file($root . '/storage/uploads/' . $file['file_path'])) $fixtures[$ext] = $root . '/storage/uploads/' . $file['file_path'];
    }
    foreach (['pdf','docx','doc','xlsx','pptx','jpg','png'] as $ext) check_http(isset($fixtures[$ext]), 'Valid ' . $ext . ' fixture available');
    $lecturer = login_http('lecturer_mis');
    $student = login_http('23070001');
    $otherStudent = login_http('23070002');
    $otherLecturer = login_http('lecturer_bel');
    $manage = 'assets/lecturer/assignments.php?section=' . $section['section_id'];
    $page = request_http($lecturer, $manage);
    $create = [
        '_csrf' => input_http($page['body'], '_csrf'), '_action' => 'create_assignment',
        'section_id' => $section['section_id'], 'title' => $title,
        'description' => 'Demonstration: evaluate the course registration workflow using actual file submission and grading.',
        'instructions' => "Analyze actors, constraints and recommendations.\nInclude evidence and a clear conclusion.",
        'publication_date' => date('Y-m-d\TH:i', time() - 3600), 'due_date' => date('Y-m-d\TH:i', time() + 7 * 86400),
        'max_score' => '10.00', 'allow_resubmission' => '1', 'status' => 'Published',
    ];
    $bad = tempnam(sys_get_temp_dir(), 'uni_invalid_');
    $temporary[] = $bad;
    file_put_contents($bad, '<html>This is not a PDF document.</html>');
    $invalid = $create;
    $invalid['title'] = '[DEMO HTTP] rejected upload';
    $invalid['attachments[0]'] = new CURLFile($fixtures['pdf'], 'application/pdf', 'valid.pdf');
    $invalid['attachments[1]'] = new CURLFile($bad, 'application/pdf', 'disguised.pdf');
    $countBefore = (int)db_http('SELECT COUNT(*) FROM Assignment')->fetchColumn();
    $page = request_http($lecturer, $manage, $invalid);
    check_http((int)db_http('SELECT COUNT(*) FROM Assignment')->fetchColumn() === $countBefore && str_contains($page['body'], 'không khớp'), 'Mixed valid/disguised attachment batch rejected atomically');
    foreach (['pdf','docx','doc','xlsx','pptx','jpg','png'] as $i => $ext) {
        $create['attachments[' . $i . ']'] = new CURLFile($fixtures[$ext], 'application/octet-stream', 'Demo-material.' . $ext);
    }
    $page = request_http($lecturer, $manage, $create);
    $assignment = db_http('SELECT * FROM Assignment WHERE title = ?', [$title])->fetch();
    check_http((bool)$assignment && str_contains($page['url'], 'assignment_detail.php'), 'Lecturer creates assignment and opens detail');
    $id = (int)$assignment['assignment_id'];
    db_http('UPDATE Assignment SET is_demo=1 WHERE assignment_id=?', [$id]);
    $materials = db_http('SELECT * FROM AssignmentAttachment WHERE assignment_id=? ORDER BY attachment_id', [$id])->fetchAll();
    check_http(count($materials) === 7, 'All seven real formats uploaded successfully');
    $pdf = $materials[0];
    $studentPath = 'assets/student/assignment_detail.php?id=' . $id;
    $page = request_http($student, $studentPath);
    check_http($page['status'] === 200 && str_contains($page['body'], 'Include evidence'), 'Student opens full assignment brief');
    $download = 'download.php?type=assignment_attachment&id=' . $pdf['attachment_id'];
    $response = request_http($student, $download . '&preview=1');
    check_http($response['status'] === 200 && str_starts_with($response['body'], '%PDF-') && stripos($response['headers'], 'Content-Disposition: inline') !== false, 'Authorized PDF inline preview');
    $response = request_http($student, $download);
    check_http(hash('sha256', $response['body']) === hash_file('sha256', $fixtures['pdf']) && stripos($response['headers'], 'Content-Disposition: attachment') !== false, 'Authorized download bytes and headers');
    check_http(request_http($otherStudent, $studentPath)['status'] === 404 && request_http($otherStudent, $download)['status'] === 403, 'Non-enrolled student denied assignment and material');
    check_http(request_http($otherLecturer, 'assets/lecturer/assignment_detail.php?id=' . $id)['status'] === 404 && request_http($otherLecturer, $download)['status'] === 403, 'Unassigned lecturer denied assignment and material');
    check_http(request_http($student, '../storage/uploads/' . $pdf['file_path'])['status'] === 403, 'Raw upload URL blocked by Apache');
    $submit = ['_csrf' => input_http($page['body'], '_csrf'), 'submit_assignment' => '1', 'enrollment_id' => $section['enrollment_id'], 'version' => '0', 'content' => 'Initial analysis: preserve enrollment integrity and review exceptions.'];
    $oversize = tempnam(sys_get_temp_dir(), 'uni_large_');
    $temporary[] = $oversize;
    $stream = fopen($oversize, 'wb');
    ftruncate($stream, 21 * 1024 * 1024);
    fclose($stream);
    $response = request_http($student, $studentPath, $submit + ['submission_files[0]' => new CURLFile($oversize, 'application/pdf', 'oversized.pdf')]);
    check_http(!db_http('SELECT 1 FROM Submission WHERE assignment_id=?', [$id])->fetchColumn() && str_contains($response['body'], '20 MB'), 'Oversized upload rejected without creating submission');
    $response = request_http($student, $studentPath, $submit + ['submission_files[0]' => new CURLFile($fixtures['pdf'], 'application/pdf', 'Analysis.pdf'), 'submission_files[1]' => new CURLFile($fixtures['docx'], 'application/octet-stream', 'Evidence.docx')]);
    $sub = db_http('SELECT * FROM Submission WHERE assignment_id=?', [$id])->fetch();
    check_http((bool)$sub && (int)$sub['version'] === 1, 'Student submits content and two files through HTTP');
    $sid = (int)$sub['submission_id'];
    db_http('UPDATE Submission SET is_demo=1 WHERE submission_id=?', [$sid]);
    $oldFile = db_http('SELECT * FROM SubmissionAttachment WHERE submission_id=? ORDER BY sub_attachment_id LIMIT 1', [$sid])->fetch();
    $gradePath = 'assets/lecturer/submission_detail.php?sub=' . $sid;
    $gradePage = request_http($lecturer, $gradePath);
    $grade = ['_csrf' => input_http($gradePage['body'], '_csrf'), 'grade_submission' => '1', 'version' => '1', 'revision' => '1', 'score' => '8.75', 'feedback' => "Draft feedback line one\nImprove the evidence section."];
    request_http($lecturer, $gradePath, $grade);
    $sub = db_http('SELECT * FROM Submission WHERE submission_id=?', [$sid])->fetch();
    check_http($sub['score'] === '8.75' && $sub['grade_status'] === 'Draft', 'Lecturer saves draft grade and multiline feedback');
    $page = request_http($student, $studentPath);
    check_http(!str_contains($page['body'], 'Draft feedback line one') && !str_contains($page['body'], '>8.75<'), 'Draft grade and feedback hidden from student');
    request_http($lecturer, $gradePath, array_merge($grade, ['score' => '9.00']));
    check_http(db_http('SELECT score FROM Submission WHERE submission_id=?', [$sid])->fetchColumn() === '8.75', 'Stale grade revision rejected through HTTP');
    $second = array_merge($submit, ['version' => '1', 'content' => 'Revised analysis: add evidence, constraints and recommendations.']);
    $page = request_http($student, $studentPath, $second + ['submission_files[0]' => new CURLFile($fixtures['xlsx'], 'application/octet-stream', 'Supporting-data.xlsx')]);
    $sub = db_http('SELECT * FROM Submission WHERE submission_id=?', [$sid])->fetch();
    check_http((int)$sub['version'] === 2 && $sub['score'] === null && str_contains($page['body'], 'Initial analysis'), 'Resubmission resets current grade and shows archived content');
    check_http((int)db_http('SELECT COUNT(*) FROM SubmissionAttachment WHERE submission_id=?', [$sid])->fetchColumn() === 3 && (int)db_http('SELECT COUNT(*) FROM SubmissionAttempt WHERE submission_id=?', [$sid])->fetchColumn() === 1, 'Both file versions preserved in database');
    $oldUrl = 'download.php?type=submission_attachment&id=' . $oldFile['sub_attachment_id'];
    check_http(hash('sha256', request_http($student, $oldUrl)['body']) === hash_file('sha256', $fixtures['pdf']), 'Archived file remains downloadable by owner');
    check_http(request_http($otherStudent, $oldUrl)['status'] === 403 && request_http($otherLecturer, $gradePath)['status'] === 404, 'Other users denied submission history and grading');
    $gradePage = request_http($lecturer, $gradePath);
    $publish = array_merge($grade, ['version' => '2', 'revision' => (string)$sub['revision'], 'score' => '9.25', 'feedback' => "Published evaluation\nEvidence and recommendations are complete.", 'publish' => '1']);
    request_http($lecturer, $gradePath, $publish);
    $page = request_http($student, $studentPath);
    check_http(str_contains($page['body'], 'Published evaluation') && !str_contains($page['body'], 'name="submit_assignment"'), 'Published feedback visible and resubmission locked');
    $sub = db_http('SELECT * FROM Submission WHERE submission_id=?', [$sid])->fetch();
    request_http($lecturer, $gradePath, array_merge($publish, ['revision' => (string)$sub['revision'], 'score' => '9.50']));
    check_http(db_http('SELECT score FROM Submission WHERE submission_id=?', [$sid])->fetchColumn() === '9.50' && (int)db_http('SELECT COUNT(*) FROM SubmissionGradeHistory WHERE submission_id=?', [$sid])->fetchColumn() === 4, 'Published grade editable with complete audit history');
    $page = request_http($lecturer, 'assets/lecturer/assignment_detail.php?id=' . $id . '&sf=submitted');
    check_http(str_contains($page['body'], 'submission_detail.php?sub=' . $sid), 'Filtered roster links to actual submission detail');
    db_http("UPDATE Assignment SET status='Draft' WHERE assignment_id=?", [$id]);
    check_http(request_http($student, $download)['status'] === 403, 'Draft material download denied');
    db_http("UPDATE Assignment SET status='Published', publication_date=DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE assignment_id=?", [$id]);
    check_http(request_http($student, $download)['status'] === 403 && request_http($student, $studentPath)['status'] === 404, 'Future material and assignment hidden');
    db_http('UPDATE Assignment SET publication_date=? WHERE assignment_id=?', [$assignment['publication_date'], $id]);
    foreach ($before as $table => $rows) check_http(db_http('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll() === $rows, $table . ' baseline unchanged');
    print "\n$passed HTTP checks passed. Retained demo assignment $id, submission $sid, seven materials and two submission versions.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    foreach ($temporary as $path) if (is_file($path)) unlink($path);
}
