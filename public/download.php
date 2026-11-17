<?php
$root = dirname(__DIR__);
require_once $root . '/src/src/helpers/auth.php';
require_login();

$pdo = get_db();
$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['Student', 'Lecturer', 'AcademicAffairs'], true)) abort(403, 'Access denied');
if ($id < 1) abort(404, 'File not found');

$material = in_array($type, ['assignment', 'assignment_attachment'], true);
switch ($type) {
    case 'assignment_attachment':
        $query = 'SELECT f.file_path, f.file_name, f.file_mime, a.section_id, a.status, a.publication_date, a.is_deleted
                  FROM AssignmentAttachment f JOIN Assignment a ON a.assignment_id = f.assignment_id
                  WHERE f.attachment_id = ?';
        break;
    case 'assignment':
        $query = 'SELECT a.attachment_path AS file_path, a.attachment_name AS file_name, a.attachment_mime AS file_mime,
                         a.section_id, a.status, a.publication_date, a.is_deleted FROM Assignment a WHERE a.assignment_id = ?';
        break;
    case 'submission':
        $query = 'SELECT f.file_path, f.file_name, f.file_mime, a.section_id, s.enrollment_id
                  FROM Submission f JOIN Submission s ON s.submission_id = f.submission_id
                  JOIN Assignment a ON a.assignment_id = s.assignment_id
                  JOIN Enrollment e ON e.enrollment_id = s.enrollment_id AND e.section_id = a.section_id
                  WHERE f.submission_id = ? AND s.submitted_at IS NOT NULL';
        break;
    case 'submission_attachment':
        $query = 'SELECT f.file_path, f.file_name, f.file_mime, a.section_id, s.enrollment_id
                  FROM SubmissionAttachment f JOIN Submission s ON s.submission_id = f.submission_id
                  JOIN Assignment a ON a.assignment_id = s.assignment_id
                  JOIN Enrollment e ON e.enrollment_id = s.enrollment_id AND e.section_id = a.section_id
                  WHERE f.sub_attachment_id = ? AND s.submitted_at IS NOT NULL';
        break;
    case 'submission_attempt':
        $query = 'SELECT f.file_path, f.file_name, f.file_mime, a.section_id, s.enrollment_id
                  FROM SubmissionAttempt f JOIN Submission s ON s.submission_id = f.submission_id
                  JOIN Assignment a ON a.assignment_id = s.assignment_id
                  JOIN Enrollment e ON e.enrollment_id = s.enrollment_id AND e.section_id = a.section_id
                  WHERE f.attempt_id = ? AND s.submitted_at IS NOT NULL';
        break;
    default:
        abort(404, 'File not found');
}
$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file || !$file['file_path']) abort(404, 'File not found');
if ($role === 'Student') {
    if ($material && ($file['is_deleted'] || !in_array($file['status'], ['Published', 'Closed'], true)
        || ($file['publication_date'] && strtotime($file['publication_date']) > time()))) {
        abort(403, 'Assignment is not available');
    }
    $q = $pdo->prepare("SELECT enrollment_id FROM Enrollment WHERE student_id = ? AND section_id = ? AND status IN ('Enrolled','Completed')");
    $q->execute([(string)($_SESSION['student_id'] ?? ''), (int)$file['section_id']]);
    $enrollmentId = (int)$q->fetchColumn();
    if (!$enrollmentId || (!$material && $enrollmentId !== (int)$file['enrollment_id'])) abort(403, 'Access denied');
} elseif ($role === 'Lecturer') {
    $q = $pdo->prepare('SELECT 1 FROM ClassSection WHERE section_id = ? AND lecturer_id = ?');
    $q->execute([(int)$file['section_id'], (int)($_SESSION['lecturer_id'] ?? 0)]);
    if (!$q->fetchColumn()) abort(403, 'Not your class');
}

$storage = realpath($root . '/storage/uploads');
$path = realpath($root . '/storage/uploads/' . $file['file_path']);
if (!$storage || !$path || !is_file($path) || !is_readable($path)) abort(404, 'File not found');
$prefix = rtrim(str_replace('\\', '/', $storage), '/') . '/';
$normalizedPath = str_replace('\\', '/', $path);
if (!str_starts_with($normalizedPath, $prefix)) abort(403, 'Access denied');

$name = basename(str_replace('\\', '/', (string)($file['file_name'] ?: 'download')));
$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$preview = (string)($_GET['preview'] ?? '') === '1';
$inline = ($extension === 'pdf' && $detected === 'application/pdf')
    || (in_array($extension, ['jpg', 'jpeg'], true) && $detected === 'image/jpeg')
    || ($extension === 'png' && $detected === 'image/png');
$inline = $preview && $inline;
$mime = $inline ? $detected : 'application/octet-stream';
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

session_write_close();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Length: ' . filesize($path));
while (ob_get_level() > 0) ob_end_clean();
readfile($path);
exit;
