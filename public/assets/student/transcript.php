<?php
/**
 * UniPortal – Thời khóa biểu & Lịch học (Schedule / TKB)
 * Figma frame: tkb (5:2766)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$student_id = $_SESSION['student_id'] ?? '';
$full_name  = $_SESSION['full_name']  ?? '';

$pdo = get_db();
$currentSem = StudentModel::getCurrentSemester();
$semId = (int)($currentSem['semester_id'] ?? 0);

// All semesters
$semStmt = $pdo->prepare('SELECT semester_id, semester_name, academic_year FROM Semester ORDER BY start_date DESC');
$semStmt->execute();
$semesters = $semStmt->fetchAll();
$selSemId = isset($_GET['sem']) ? (int)$_GET['sem'] : $semId;

// Current enrollments with schedule info
$enrStmt = $pdo->prepare(
    'SELECT
        c.course_code, c.course_name, c.credits,
        cs.section_code, cs.schedule, cs.room,
        l.full_name AS lecturer_name,
        e.status AS enroll_status
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Course c        ON c.course_id   = cs.course_id
     JOIN Lecturer l      ON l.lecturer_id = cs.lecturer_id
     WHERE e.student_id = :sid AND cs.semester_id = :sem
     ORDER BY c.course_code ASC'
);
$enrStmt->execute([':sid' => $student_id, ':sem' => $selSemId ?: $semId]);
$enrollments = $enrStmt->fetchAll();

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$pageTitle  = 'Thời khóa biểu';
$activePage = 'schedule';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Semester filter -->
<div class="filter-ribbon">
    <span style="font-size:14px;font-weight:700;color:#001634;">Danh sách môn học</span>
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <label style="font-size:13px;font-weight:600;color:#44474e;">Học kỳ:</label>
        <select name="sem" onchange="this.form.submit()">
            <?php foreach ($semesters as $s): ?>
            <option value="<?= h($s['semester_id']) ?>"
                <?= (int)$s['semester_id'] === ($selSemId ?: $semId) ? 'selected' : '' ?>>
                <?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- Enrollments table -->
<div class="card">
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Mã môn</th>
                    <th>Tên môn học</th>
                    <th>TC</th>
                    <th>Mã lớp học phần</th>
                    <th>Lịch học</th>
                    <th>Phòng</th>
                    <th>Giảng viên</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($enrollments)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có môn học nào trong học kỳ này.
                </td></tr>
                <?php else: ?>
                <?php foreach ($enrollments as $e): ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= h($e['course_code']) ?></span></td>
                    <td style="font-weight:600;color:#001634;"><?= h($e['course_name']) ?></td>
                    <td style="text-align:center;"><?= h($e['credits']) ?></td>
                    <td style="font-size:12px;"><?= h($e['section_code']) ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= h($e['schedule'] ?? '—') ?></td>
                    <td><?= h($e['room'] ?? '—') ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= h($e['lecturer_name']) ?></td>
                    <td>
                        <?php $st = strtolower($e['enroll_status'] ?? ''); ?>
                        <span class="status-pill status-pill--<?= $st === 'completed' ? 'complete' : ($st === 'enrolled' ? 'enrolled' : 'absent') ?>">
                            <?= $e['enroll_status'] === 'Completed' ? 'Hoàn thành' : ($e['enroll_status'] === 'Enrolled' ? 'Đang học' : h($e['enroll_status'])) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
