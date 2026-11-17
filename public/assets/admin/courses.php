<?php
/**
 * UniPortal – Quản lý Môn học & Lớp học phần
 * Courses CRUD + ClassSection view + lecturer assignment.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/RegistrationService.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: ' . app_url('assets/student/dashboard.php')); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: ' . app_url('assets/lecturer/dashboard.php')); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo = get_db();
$adminAccId = (int)($_SESSION['account_id'] ?? 0);

// ── Small helpers ─────────────────────────────────────────────
function section_enrolled(PDO $pdo, int $sectionId): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM Enrollment WHERE section_id=:sid AND status IN ('Enrolled','Completed')");
    $s->execute([':sid'=>$sectionId]);
    return (int)$s->fetchColumn();
}
function back_to_section(int $semId, int $sectionId): never {
    header('Location: courses.php?tab=sections&sem=' . $semId . '&section=' . $sectionId);
    exit;
}
function section_has_official_schedule(PDO $pdo, int $sectionId): bool {
    // Inactive schedules also own session history and must use the schedule workflow.
    $s = $pdo->prepare('SELECT 1 FROM TeachingSchedule WHERE section_id=:sid LIMIT 1');
    $s->execute([':sid'=>$sectionId]);
    return (bool)$s->fetchColumn();
}
function section_schedule_url(int $semId, int $courseId, int $sectionId): string {
    return 'schedule.php?sem=' . $semId . '&course=' . $courseId . '&section=' . $sectionId;
}

// ── POST handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add_course') {
        $code  = strtoupper(trim($_POST['course_code']  ?? ''));
        $name  = trim($_POST['course_name']             ?? '');
        $cred  = (int)($_POST['credits']                ?? 0);
        $dept  = (int)($_POST['department_id']          ?? 0);
        $desc  = trim($_POST['description']             ?? '');
        $active= ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;
        $prereq= (int)($_POST['prerequisite_course_id'] ?? 0) ?: null;

        if ($code==='' || $name==='' || $cred<1 || $dept<1) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Vui lòng điền đầy đủ: mã môn, tên môn, số tín chỉ (>0) và chọn bộ môn.'];
        } elseif (mb_strlen($code) > 10) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Mã môn học tối đa 10 ký tự.'];
        } elseif ($prereq !== null && $prereq === 0) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Môn tiên quyết không hợp lệ.'];
        } else {
            $ck = $pdo->prepare('SELECT 1 FROM Course WHERE course_code=:c LIMIT 1');
            $ck->execute([':c'=>$code]);
            $deptOk = $pdo->prepare('SELECT 1 FROM Department WHERE department_id=:id LIMIT 1');
            $deptOk->execute([':id'=>$dept]);
            $preOk = true;
            if ($prereq !== null) {
                $preSt = $pdo->prepare('SELECT 1 FROM Course WHERE course_id=:id AND is_active=TRUE LIMIT 1');
                $preSt->execute([':id'=>$prereq]);
                $preOk = (bool)$preSt->fetch();
            }
            if ($ck->fetch()) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>"Mã môn học $code đã tồn tại."];
            } elseif (!$deptOk->fetch()) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Bộ môn không hợp lệ.'];
            } elseif (!$preOk) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Môn tiên quyết không hợp lệ.'];
            } else {
                $pdo->prepare(
                    'INSERT INTO Course (course_code,course_name,credits,department_id,description,is_active,prerequisite_course_id)
                     VALUES (:code,:name,:cred,:dept,:desc,:active,:pre)'
                )->execute([':code'=>$code,':name'=>$name,':cred'=>$cred,':dept'=>$dept,':desc'=>$desc,':active'=>$active,':pre'=>$prereq]);
                $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã thêm môn học: $code – $name"];
            }
        }
        header('Location: courses.php'); exit;
    }

    if ($action === 'edit_course') {
        $cid   = (int)($_POST['course_id']  ?? 0);
        $name  = trim($_POST['course_name'] ?? '');
        $cred  = (int)($_POST['credits']    ?? 0);
        $dept  = (int)($_POST['department_id'] ?? 0);
        $desc  = trim($_POST['description'] ?? '');
        $active= isset($_POST['is_active']) ? 1 : 0;
        $prereq= (int)($_POST['prerequisite_course_id'] ?? 0) ?: null;

        $deptOk = $pdo->prepare('SELECT 1 FROM Department WHERE department_id=:id LIMIT 1');
        $deptOk->execute([':id'=>$dept]);
        $preOk = true;
        if ($prereq !== null) {
            $preSt = $pdo->prepare('SELECT 1 FROM Course WHERE course_id=:id AND course_id<>:cid LIMIT 1');
            $preSt->execute([':id'=>$prereq, ':cid'=>$cid]);
            $preOk = (bool)$preSt->fetch();
        }

        if ($name==='' || $cred<1 || $cid<1 || $dept<1) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Thông tin không hợp lệ: cần tên môn, số tín chỉ và bộ môn.'];
        } elseif (!$deptOk->fetch()) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Bộ môn không hợp lệ.'];
        } elseif (!$preOk) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Môn tiên quyết không hợp lệ.'];
        } else {
            $pdo->prepare(
                'UPDATE Course SET course_name=:name,credits=:cred,department_id=:dept,
                        description=:desc,is_active=:active,prerequisite_course_id=:pre WHERE course_id=:cid'
            )->execute([':name'=>$name,':cred'=>$cred,':dept'=>$dept,':desc'=>$desc,':active'=>$active,':pre'=>$prereq,':cid'=>$cid]);
            $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã cập nhật môn học #$cid."];
        }
        header('Location: courses.php'); exit;
    }

    if (in_array($action, ['add_section', 'edit_section'], true)) {
        $isEdit = $action === 'edit_section';
        $secId  = (int)($_POST['section_id'] ?? 0);
        $payload = [
            'course_id'       => (int)($_POST['course_id'] ?? 0),
            'semester_id'     => (int)($_POST['semester_id'] ?? 0),
            'lecturer_id'     => (int)($_POST['lecturer_id'] ?? 0),
            'section_code'    => $_POST['section_code'] ?? '',
            'room'            => $_POST['room'] ?? '',
            'min_enrollment'  => $_POST['min_enrollment'] ?? 48,
            'max_enrollment'  => $_POST['max_enrollment'] ?? 64,
            'study_days'      => $_POST['study_days'] ?? [],
            'start_time'      => $_POST['start_time'] ?? '',
            'end_time'        => $_POST['end_time'] ?? '',
            'reg_open'        => $_POST['reg_open'] ?? '',
            'reg_close'       => $_POST['reg_close'] ?? '',
        ];

        if ($isEdit && $secId < 1) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Lớp học phần không hợp lệ.'];
            header('Location: courses.php?tab=sections'); exit;
        }

        $hasOfficialSchedule = false;
        if ($isEdit) {
            $old = $pdo->prepare('SELECT * FROM ClassSection WHERE section_id=:id LIMIT 1');
            $old->execute([':id'=>$secId]);
            $oldRow = $old->fetch();
            if (!$oldRow) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Lớp học phần không tồn tại.'];
                header('Location: courses.php?tab=sections'); exit;
            }

            $identityChanged = $payload['course_id'] !== (int)$oldRow['course_id']
                || $payload['semester_id'] !== (int)$oldRow['semester_id'];
            $hasOfficialSchedule = section_has_official_schedule($pdo, $secId);
            if ($hasOfficialSchedule) {
                $scheduleChanged = $payload['lecturer_id'] !== (int)$oldRow['lecturer_id']
                    || trim((string)$payload['room']) !== trim((string)$oldRow['room'])
                    || RegistrationService::normalizeDays($payload['study_days']) !== RegistrationService::normalizeDays($oldRow['study_days'])
                    || RegistrationService::normalizeTime($payload['start_time']) !== RegistrationService::normalizeTime($oldRow['start_time'])
                    || RegistrationService::normalizeTime($payload['end_time']) !== RegistrationService::normalizeTime($oldRow['end_time']);
                if ($identityChanged || $scheduleChanged) {
                    $_SESSION['admin_schedule_flash'] = 'error:Chưa lưu thay đổi. Lớp đã có lịch giảng dạy chính thức nên không thể đổi môn học/học kỳ. '
                        . 'Vui lòng đổi giảng viên, phòng, ngày hoặc giờ học tại trang Lịch giảng dạy để đồng bộ các buổi học tương lai và giữ nguyên lịch sử. '
                        . 'Mã lớp, sĩ số và thời gian đăng ký vẫn được sửa tại trang Môn học & Lớp học phần.';
                    header('Location: ' . section_schedule_url((int)$oldRow['semester_id'], (int)$oldRow['course_id'], $secId)); exit;
                }
            } elseif ($identityChanged) {
                // Include withdrawn enrollments, legacy sessions and assessment/assignment history.
                $history = $pdo->prepare(
                    'SELECT EXISTS(SELECT 1 FROM Enrollment WHERE section_id=:enrollment)
                         OR EXISTS(SELECT 1 FROM AttendanceSession WHERE section_id=:session)
                         OR EXISTS(SELECT 1 FROM Assessment WHERE section_id=:assessment)
                         OR EXISTS(SELECT 1 FROM Assignment WHERE section_id=:assignment)'
                );
                $history->execute([':enrollment'=>$secId, ':session'=>$secId, ':assessment'=>$secId, ':assignment'=>$secId]);
                if ($history->fetchColumn()) {
                    $_SESSION['_flash'] = ['type'=>'error','message'=>'Không thể đổi môn học/học kỳ của lớp đã có đăng ký hoặc lịch sử học tập. Vui lòng tạo lớp mới; mã lớp, sĩ số và thời gian đăng ký vẫn có thể chỉnh sửa.'];
                    back_to_section((int)$oldRow['semester_id'], $secId);
                }
            }
        }

        $validation = RegistrationService::validateSection($pdo, $payload, $isEdit ? $secId : null);
        if (!$validation['ok']) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>implode(' ', $validation['errors'])];
            header('Location: courses.php?tab=sections' . ($isEdit ? '&sem=' . $payload['semester_id'] . '&section=' . $secId : '')); exit;
        }
        $d = $validation['data'];
        $scheduleText = RegistrationService::scheduleText($d['study_days'], $d['start_time'], $d['end_time']);
        $capacity     = $d['max_enrollment'];

        if ($isEdit) {
            if ($hasOfficialSchedule) {
                // Do not rewrite schedule-owned columns, even for equivalent form values.
                $pdo->prepare(
                    'UPDATE ClassSection
                     SET section_code=:code, capacity=:cap, min_enrollment=:min, max_enrollment=:max,
                         reg_open=:ro, reg_close=:rc WHERE section_id=:id'
                )->execute([
                    ':code'=>$d['section_code'], ':cap'=>$capacity,
                    ':min'=>$d['min_enrollment'], ':max'=>$d['max_enrollment'],
                    ':ro'=>$d['reg_open'], ':rc'=>$d['reg_close'], ':id'=>$secId,
                ]);
            } else {
                $pdo->prepare(
                    'UPDATE ClassSection
                     SET course_id=:cid, semester_id=:sem, lecturer_id=:lid, section_code=:code,
                         schedule=:sched, room=:room, capacity=:cap, min_enrollment=:min, max_enrollment=:max,
                         study_days=:days, start_time=:st, end_time=:en, reg_open=:ro, reg_close=:rc
                     WHERE section_id=:id'
                )->execute([
                    ':cid'=>$d['course_id'], ':sem'=>$d['semester_id'], ':lid'=>$d['lecturer_id'],
                    ':code'=>$d['section_code'], ':sched'=>$scheduleText, ':room'=>$d['room'],
                    ':cap'=>$capacity, ':min'=>$d['min_enrollment'], ':max'=>$d['max_enrollment'],
                    ':days'=>RegistrationService::daysToString($d['study_days']),
                    ':st'=>$d['start_time'], ':en'=>$d['end_time'],
                    ':ro'=>$d['reg_open'], ':rc'=>$d['reg_close'], ':id'=>$secId,
                ]);
            }
            AcademicAlertService::writeAuditLog($pdo, $adminAccId, 'UPDATE_SECTION', 'ClassSection', (string)$secId,
                json_encode($oldRow, JSON_UNESCAPED_UNICODE),
                json_encode(['course_id'=>$d['course_id'],'semester_id'=>$d['semester_id'],'lecturer_id'=>$d['lecturer_id'],
                             'section_code'=>$d['section_code'],'room'=>$d['room'],'min'=>$d['min_enrollment'],
                             'max'=>$d['max_enrollment'],'days'=>RegistrationService::daysToString($d['study_days']),
                             'start'=>$d['start_time'],'end'=>$d['end_time']], JSON_UNESCAPED_UNICODE));
            $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã cập nhật lớp {$d['section_code']}."];
            header('Location: courses.php?tab=sections&sem=' . $d['semester_id'] . '&section=' . $secId); exit;
        }

        $pdo->prepare(
            'INSERT INTO ClassSection (course_id,semester_id,lecturer_id,section_code,schedule,room,capacity,
                                       min_enrollment,max_enrollment,study_days,start_time,end_time,reg_open,reg_close,section_status)
             VALUES (:cid,:sem,:lid,:code,:sched,:room,:cap,:min,:max,:days,:st,:en,:ro,:rc,\'Pending\')'
        )->execute([
            ':cid'=>$d['course_id'], ':sem'=>$d['semester_id'], ':lid'=>$d['lecturer_id'],
            ':code'=>$d['section_code'], ':sched'=>$scheduleText, ':room'=>$d['room'], ':cap'=>$capacity,
            ':min'=>$d['min_enrollment'], ':max'=>$d['max_enrollment'],
            ':days'=>RegistrationService::daysToString($d['study_days']),
            ':st'=>$d['start_time'], ':en'=>$d['end_time'], ':ro'=>$d['reg_open'], ':rc'=>$d['reg_close'],
        ]);
        $newSecId = (int)$pdo->lastInsertId();
        AcademicAlertService::writeAuditLog($pdo, $adminAccId, 'CREATE_SECTION', 'ClassSection', (string)$newSecId,
            null, json_encode([
                'course_id'=>$d['course_id'], 'semester_id'=>$d['semester_id'], 'lecturer_id'=>$d['lecturer_id'],
                'section_code'=>$d['section_code'], 'room'=>$d['room'], 'min'=>$d['min_enrollment'],
                'max'=>$d['max_enrollment'], 'days'=>RegistrationService::daysToString($d['study_days']),
                'start'=>$d['start_time'], 'end'=>$d['end_time'],
            ], JSON_UNESCAPED_UNICODE));
        $_SESSION['admin_schedule_flash'] = "success:Đã tạo lớp học phần {$d['section_code']} (ngưỡng mở lớp "
            . RegistrationService::openingThreshold($d['min_enrollment']) . ' sinh viên). '
            . 'Vui lòng xác nhận và lưu lịch tuần chính thức để tạo các buổi học theo số tín chỉ. '
            . 'Lịch dự kiến của lớp có thể chọn nhiều ngày; lịch chính thức hiện hỗ trợ một ngày trong tuần, vui lòng chọn ngày áp dụng.';
        header('Location: ' . section_schedule_url($d['semester_id'], $d['course_id'], $newSecId)); exit;
    }

    if ($action === 'override_section_status') {
        $secId = (int)($_POST['section_id'] ?? 0);
        $new   = $_POST['new_status'] ?? '';
        $note  = trim($_POST['note'] ?? '');
        $res   = RegistrationService::overrideSectionStatus($pdo, $secId, $new, $note, $adminAccId);
        $_SESSION['_flash'] = ['type'=>$res['ok']?'success':'error','message'=>$res['message']];
        $sem = (int)($_POST['sem_return'] ?? 0);
        header('Location: courses.php?tab=sections&sem=' . $sem . '&section=' . $secId); exit;
    }

    if ($action === 'assign_lecturer') {
        $secid  = (int)($_POST['section_id']  ?? 0);
        $lecid  = (int)($_POST['lecturer_id'] ?? 0);
        $semid  = (int)($_POST['semester_id'] ?? 0);
        $cid    = (int)($_POST['course_id']   ?? 0);
        $retTab = ($_POST['return_tab'] ?? '') === 'sections' ? 'sections' : 'assign';
        $retSem = (int)($_POST['return_sem'] ?? 0);

        $secChk = $pdo->prepare(
            'SELECT cs.*, c.course_code
             FROM ClassSection cs JOIN Course c ON c.course_id=cs.course_id
             WHERE cs.section_id=:sid LIMIT 1'
        );
        $secChk->execute([':sid'=>$secid]);
        $secRow = $secChk->fetch();

        if ($secRow && section_has_official_schedule($pdo, $secid)) {
            $_SESSION['admin_schedule_flash'] = 'error:Chưa thay đổi giảng viên. Lớp đã có lịch giảng dạy chính thức. '
                . 'Vui lòng phân công giảng viên tại trang Lịch giảng dạy và lưu lịch để các buổi học tương lai được đồng bộ; lịch sử buổi học và điểm danh được giữ nguyên.';
            header('Location: ' . section_schedule_url((int)$secRow['semester_id'], (int)$secRow['course_id'], $secid)); exit;
        }

        $lecChk = $pdo->prepare(
            "SELECT l.full_name FROM Lecturer l
             JOIN Account a ON a.lecturer_id=l.lecturer_id
             WHERE l.lecturer_id=:id AND l.status='Active' AND a.role='Lecturer' AND a.is_active=1
             LIMIT 1"
        );
        $lecChk->execute([':id'=>$lecid]);
        $lecRow = $lecChk->fetch();

        if (!$secRow) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Lớp học phần không tồn tại.'];
        } elseif (!$lecRow) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Giảng viên không hợp lệ hoặc đã ngừng hoạt động.'];
        } elseif (($semid > 0 && $semid !== (int)$secRow['semester_id'])
               || ($cid > 0 && $cid !== (int)$secRow['course_id'])) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Lớp học phần không thuộc môn học hoặc học kỳ đã chọn.'];
        } elseif ((int)$secRow['lecturer_id'] === $lecid) {
            $_SESSION['_flash'] = ['type'=>'success','message'=>'Giảng viên này đã phụ trách lớp '.$secRow['section_code'].'.'];
        } else {
            $conflict = RegistrationService::validateSection($pdo, [
                'course_id'      => (int)$secRow['course_id'],
                'semester_id'    => (int)$secRow['semester_id'],
                'lecturer_id'    => $lecid,
                'section_code'   => $secRow['section_code'],
                'room'           => $secRow['room'],
                'min_enrollment' => (int)$secRow['min_enrollment'],
                'max_enrollment' => (int)$secRow['max_enrollment'],
                'study_days'     => $secRow['study_days'],
                'start_time'     => $secRow['start_time'],
                'end_time'       => $secRow['end_time'],
            ], (int)$secid);
            if (!$conflict['ok']) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>implode(' ', $conflict['errors'])];
            } else {
                $pdo->prepare('UPDATE ClassSection SET lecturer_id=:lid WHERE section_id=:sid')
                    ->execute([':lid'=>$lecid,':sid'=>$secid]);
                AcademicAlertService::writeAuditLog(
                    $pdo, $adminAccId, 'ASSIGN_LECTURER', 'ClassSection', (string)$secid,
                    json_encode(['lecturer_id' => (int)$secRow['lecturer_id']]),
                    json_encode(['lecturer_id' => $lecid, 'course_id' => (int)$secRow['course_id'],
                                 'semester_id' => (int)$secRow['semester_id']])
                );
                $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã giao lớp {$secRow['section_code']} cho {$lecRow['full_name']}."];
            }
        }
        if ($retTab === 'sections') {
            header('Location: courses.php?tab=sections'); exit;
        }
        header('Location: courses.php?tab=assign' . ($retSem > 0 ? '&tsem=' . $retSem : '')); exit;
    }

    // ── Section roster: add / remove / transfer ──────────────
    if (in_array($action, ['section_enroll','section_unenroll','section_transfer'], true)) {
        $semReturn = (int)($_POST['sem_return'] ?? 0);
        $secReturn = (int)($_POST['section_return'] ?? 0);

        if ($_SESSION['role'] !== 'AcademicAffairs') {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Chỉ quản trị viên học vụ được quản lý sĩ số lớp.'];
            back_to_section($semReturn, $secReturn);
        }

        if ($action === 'section_enroll') {
            $secId   = (int)($_POST['section_id'] ?? 0);
            $stuId   = trim($_POST['student_id'] ?? '');
            $override = isset($_POST['override']);

            $sec = $pdo->prepare(
                'SELECT cs.section_id, cs.section_code, cs.capacity, cs.semester_id, c.credits
                 FROM ClassSection cs JOIN Course c ON c.course_id=cs.course_id
                 WHERE cs.section_id=:sid LIMIT 1'
            );
            $sec->execute([':sid'=>$secId]);
            $secRow = $sec->fetch();
            $stu = $pdo->prepare("SELECT student_id, full_name FROM Student WHERE student_id=:sid AND status='Active' LIMIT 1");
            $stu->execute([':sid'=>$stuId]);
            $stuRow = $stu->fetch();

            if (!$secRow || !$stuRow) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Sinh viên hoặc lớp học phần không hợp lệ.'];
            } else {
                $existing = $pdo->prepare('SELECT enrollment_id, status FROM Enrollment WHERE student_id=:s AND section_id=:sec LIMIT 1');
                $existing->execute([':s'=>$stuId, ':sec'=>$secId]);
                $enr = $existing->fetch();
                $count = section_enrolled($pdo, $secId);
                $limitErr = enrollment_limit_error($count, $secRow['capacity'] !== null ? (int)$secRow['capacity'] : null, $override);
                $credit = RegistrationService::registrationSummary($pdo, $stuId, (int)$secRow['semester_id'], $secId);
                $creditErr = ($credit['registered'] + (int)$secRow['credits'] > $credit['max'])
                    ? 'Vượt giới hạn tín chỉ: sinh viên đã đăng ký ' . $credit['registered'] . ' TC, môn này '
                      . (int)$secRow['credits'] . ' TC, tối đa ' . $credit['max']
                      . ' TC/học kỳ. Tạo ngoại lệ tín chỉ nếu được phê duyệt.'
                    : null;

                if ($enr && $enr['status'] !== 'Withdrawn') {
                    $_SESSION['_flash'] = ['type'=>'error','message'=>'Sinh viên đã có trong lớp này.'];
                } elseif ($limitErr) {
                    $_SESSION['_flash'] = ['type'=>'error','message'=>$limitErr];
                } elseif ($creditErr) {
                    $_SESSION['_flash'] = ['type'=>'error','message'=>$creditErr];
                } elseif ($enr) {
                    $pdo->prepare("UPDATE Enrollment SET status='Enrolled', enrollment_date=NOW() WHERE enrollment_id=:eid")
                        ->execute([':eid'=>$enr['enrollment_id']]);
                    AcademicAlertService::writeAuditLog($pdo,$adminAccId,'ADMIN_REENROLL','Enrollment',$stuId,null,json_encode(['section_id'=>$secId]));
                    $_SESSION['_flash'] = ['type'=>'success','message'=>'Đã đăng ký lại sinh viên '.$stuId.' vào lớp '.$secRow['section_code'].'.'];
                } else {
                    $pdo->prepare("INSERT INTO Enrollment (student_id,section_id,status,enrollment_date) VALUES (:s,:sec,'Enrolled',NOW())")
                        ->execute([':s'=>$stuId,':sec'=>$secId]);
                    AcademicAlertService::writeAuditLog($pdo,$adminAccId,'ADMIN_ENROLL','Enrollment',$stuId,null,json_encode(['section_id'=>$secId]));
                    $_SESSION['_flash'] = ['type'=>'success','message'=>'Đã thêm sinh viên '.$stuId.' vào lớp '.$secRow['section_code'].'.'];
                }
            }
            back_to_section($secRow ? (int)$secRow['semester_id'] : $semReturn, $secId);
        }

        if ($action === 'section_unenroll') {
            $enrId = (int)($_POST['enrollment_id'] ?? 0);
            $row = $pdo->prepare(
                'SELECT e.enrollment_id, e.status, e.student_id, e.section_id, cs.section_code, cs.semester_id
                 FROM Enrollment e JOIN ClassSection cs ON cs.section_id=e.section_id
                 WHERE e.enrollment_id=:eid LIMIT 1'
            );
            $row->execute([':eid'=>$enrId]);
            $enr = $row->fetch();
            if (!$enr) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Không tìm thấy đăng ký.'];
            } elseif ($enr['status'] !== 'Enrolled') {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Chỉ có thể rút đăng ký đang ở trạng thái Đang học.'];
            } else {
                $pdo->prepare("UPDATE Enrollment SET status='Withdrawn' WHERE enrollment_id=:eid")
                    ->execute([':eid'=>$enrId]);
                AcademicAlertService::writeAuditLog($pdo,$adminAccId,'ADMIN_UNENROLL','Enrollment',$enr['student_id'],null,json_encode(['section_id'=>$enr['section_id']]));
                $_SESSION['_flash'] = ['type'=>'success','message'=>'Đã rút sinh viên '.$enr['student_id'].' khỏi lớp '.$enr['section_code'].'.'];
            }
            back_to_section($enr ? (int)$enr['semester_id'] : $semReturn, $enr ? (int)$enr['section_id'] : $secReturn);
        }

        if ($action === 'section_transfer') {
            $enrId  = (int)($_POST['enrollment_id'] ?? 0);
            $target = (int)($_POST['target_section_id'] ?? 0);
            $override = isset($_POST['override']);

            $row = $pdo->prepare(
                'SELECT e.enrollment_id, e.status, e.student_id, e.section_id,
                        cs.section_code AS from_code, cs.course_id, cs.semester_id
                 FROM Enrollment e JOIN ClassSection cs ON cs.section_id=e.section_id
                 WHERE e.enrollment_id=:eid LIMIT 1'
            );
            $row->execute([':eid'=>$enrId]);
            $src = $row->fetch();
            $tgt = $pdo->prepare(
                'SELECT cs.section_id, cs.section_code, cs.course_id, cs.semester_id, cs.capacity
                 FROM ClassSection cs WHERE cs.section_id=:sid LIMIT 1'
            );
            $tgt->execute([':sid'=>$target]);
            $dst = $tgt->fetch();

            if (!$src || !$dst) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Lớp nguồn hoặc lớp đích không hợp lệ.'];
            } elseif ($src['status'] !== 'Enrolled') {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Chỉ có thể chuyển sinh viên đang ở trạng thái Đang học.'];
            } elseif ((int)$src['section_id'] === $target) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Sinh viên đã ở trong lớp này.'];
            } elseif ((int)$src['course_id'] !== (int)$dst['course_id'] || (int)$src['semester_id'] !== (int)$dst['semester_id']) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>'Chỉ chuyển giữa các lớp cùng môn và cùng học kỳ.'];
            } else {
                $existing = $pdo->prepare('SELECT enrollment_id, status FROM Enrollment WHERE student_id=:s AND section_id=:sec LIMIT 1');
                $existing->execute([':s'=>$src['student_id'], ':sec'=>$target]);
                $enrTarget = $existing->fetch();
                $count = section_enrolled($pdo, $target);
                $limitErr = enrollment_limit_error($count, $dst['capacity'] !== null ? (int)$dst['capacity'] : null, $override);

                if ($enrTarget && $enrTarget['status'] !== 'Withdrawn') {
                    $_SESSION['_flash'] = ['type'=>'error','message'=>'Sinh viên đã có đăng ký ở lớp đích.'];
                } elseif ($limitErr) {
                    $_SESSION['_flash'] = ['type'=>'error','message'=>$limitErr];
                } else {
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare("UPDATE Enrollment SET status='Withdrawn' WHERE enrollment_id=:eid")
                            ->execute([':eid'=>$enrId]);
                        if ($enrTarget) {
                            $pdo->prepare("UPDATE Enrollment SET status='Enrolled', enrollment_date=NOW() WHERE enrollment_id=:eid")
                                ->execute([':eid'=>$enrTarget['enrollment_id']]);
                        } else {
                            $pdo->prepare("INSERT INTO Enrollment (student_id,section_id,status,enrollment_date) VALUES (:s,:sec,'Enrolled',NOW())")
                                ->execute([':s'=>$src['student_id'],':sec'=>$target]);
                        }
                        $pdo->commit();
                        AcademicAlertService::writeAuditLog($pdo,$adminAccId,'ADMIN_TRANSFER','Enrollment',$src['student_id'],
                            json_encode(['from'=>$src['section_id']]), json_encode(['to'=>$target]));
                        $_SESSION['_flash'] = ['type'=>'success','message'=>'Đã chuyển sinh viên '.$src['student_id'].' từ lớp '.$src['from_code'].' sang '.$dst['section_code'].'.'];
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $_SESSION['_flash'] = ['type'=>'error','message'=>'Không thể chuyển lớp: '.$e->getMessage()];
                    }
                }
            }
            back_to_section((int)($src['semester_id'] ?? 0) ?: $semReturn, $target ?: $secReturn);
        }
    }
}

// ── Flash ─────────────────────────────────────────────────────
$flash = '';
if (isset($_SESSION['_flash'])) { $flash=$_SESSION['_flash']['type'].':'.$_SESSION['_flash']['message']; unset($_SESSION['_flash']); }

// ── View course JSON ──────────────────────────────────────────
if (isset($_GET['view_course'])) {
    $cid = (int)$_GET['view_course'];
    $row = $pdo->prepare(
        'SELECT c.*, d.department_name, d.department_code,
                (SELECT COUNT(*) FROM ClassSection cs WHERE cs.course_id=c.course_id) AS section_count
         FROM Course c LEFT JOIN Department d ON d.department_id=c.department_id
         WHERE c.course_id=:cid LIMIT 1'
    );
    $row->execute([':cid'=>$cid]);
    $course = $row->fetch();
    if ($course) {
        $secSt = $pdo->prepare(
            'SELECT cs.section_id, cs.section_code, cs.schedule, cs.room, cs.capacity,
                    cs.min_enrollment, cs.max_enrollment, cs.section_status, cs.study_days,
                    cs.start_time, cs.end_time, cs.reg_open, cs.reg_close,
                    sem.semester_name, sem.academic_year, l.full_name AS lecturer_name,
                    (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=cs.section_id
                     AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled
             FROM ClassSection cs
             JOIN Semester sem ON sem.semester_id=cs.semester_id
             JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
             WHERE cs.course_id=:cid
             ORDER BY sem.start_date DESC, cs.section_code ASC'
        );
        $secSt->execute([':cid'=>$cid]);
        $course['sections'] = $secSt->fetchAll();
    }
    header('Content-Type: application/json');
    echo json_encode($course ?: []);
    exit;
}

$tab = in_array($_GET['tab'] ?? '', ['courses','sections','assign'], true) ? $_GET['tab'] : 'courses';

// ── Data: Courses ─────────────────────────────────────────────
$searchC = trim($_GET['qc'] ?? '');
$deptFC  = trim($_GET['dept'] ?? '');
$whereC  = "WHERE 1=1"; $paramsC = [];
if ($searchC!=='') { $whereC.=" AND (c.course_code LIKE :q OR c.course_name LIKE :q)"; $paramsC[':q']='%'.$searchC.'%'; }
if ($deptFC!=='')  { $whereC.=" AND d.department_code=:dept"; $paramsC[':dept']=$deptFC; }

$courses = $pdo->prepare(
    "SELECT c.course_id, c.course_code, c.course_name, c.credits, c.is_active, c.description,
            c.prerequisite_course_id,
            pc.course_code AS prereq_code, pc.course_name AS prereq_name,
            d.department_name, d.department_code, d.department_id,
            (SELECT COUNT(*) FROM ClassSection cs WHERE cs.course_id=c.course_id) AS section_count,
            (SELECT COUNT(DISTINCT cc.curriculum_id) FROM CurriculumCourse cc WHERE cc.course_id=c.course_id) AS curriculum_count
     FROM Course c
     LEFT JOIN Department d ON d.department_id=c.department_id
     LEFT JOIN Course pc ON pc.course_id=c.prerequisite_course_id
     $whereC ORDER BY c.course_code ASC LIMIT 200"
);
$courses->execute($paramsC);
$courseRows = $courses->fetchAll();

// ── Data: Sections ────────────────────────────────────────────
$searchS = trim($_GET['qs'] ?? '');
$semFS   = isset($_GET['sem']) ? (int)$_GET['sem'] : -1;
$lecFS   = (int)($_GET['lec'] ?? 0);
$deptS   = trim($_GET['dept'] ?? '');
$statFS  = in_array($_GET['stat'] ?? '', ['Pending','Confirmed','Insufficient','Cancelled'], true) ? $_GET['stat'] : '';

$currentSemRow = $pdo->query("SELECT semester_id FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1")->fetch();
$defaultSem    = $semFS >= 0 ? $semFS : (int)($currentSemRow['semester_id'] ?? 0);

$whereS = "WHERE 1=1"; $paramsS = [];
if ($defaultSem>0) { $whereS.=" AND cs.semester_id=:sem"; $paramsS[':sem']=$defaultSem; }
if ($lecFS>0)      { $whereS.=" AND cs.lecturer_id=:lec"; $paramsS[':lec']=$lecFS; }
if ($deptS!=='')   { $whereS.=" AND d.department_code=:deptS"; $paramsS[':deptS']=$deptS; }
if ($statFS!=='')  { $whereS.=" AND cs.section_status=:stat"; $paramsS[':stat']=$statFS; }
if ($searchS!=='') { $whereS.=" AND (cs.section_code LIKE :q OR c.course_code LIKE :q OR c.course_name LIKE :q OR l.full_name LIKE :q)"; $paramsS[':q']='%'.$searchS.'%'; }

$sections = $pdo->prepare(
    "SELECT cs.section_id, cs.section_code, cs.schedule, cs.room, cs.capacity,
            cs.min_enrollment, cs.max_enrollment, cs.study_days, cs.start_time, cs.end_time,
            cs.reg_open, cs.reg_close, cs.section_status, cs.status_note, cs.status_changed_at,
            cs.course_id, c.course_code, c.course_name, c.credits, c.department_id,
            sem.semester_id, sem.semester_name, sem.academic_year,
            l.full_name AS lecturer_name, l.lecturer_id,
            (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=cs.section_id AND e.status IN ('Enrolled','Completed')) AS enrolled
     FROM ClassSection cs
     JOIN Course c ON c.course_id=cs.course_id
     LEFT JOIN Department d ON d.department_id=c.department_id
     JOIN Semester sem ON sem.semester_id=cs.semester_id
     JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
     $whereS
     ORDER BY cs.section_code ASC LIMIT 200"
);
$sections->execute($paramsS);
$sectionRows = $sections->fetchAll();

// ── Supporting data ───────────────────────────────────────────
$depts     = $pdo->query('SELECT department_id,department_code,department_name FROM Department ORDER BY department_code')->fetchAll();
$semesters = $pdo->query('SELECT semester_id,semester_name,academic_year FROM Semester ORDER BY start_date DESC')->fetchAll();
$lecturers = $pdo->query('SELECT lecturer_id,full_name,department_id FROM Lecturer WHERE status=\'Active\' ORDER BY full_name ASC')->fetchAll();
$allStudents = $pdo->query(
    "SELECT student_id, full_name, class_code FROM Student WHERE status='Active' ORDER BY student_id"
)->fetchAll();

// ── Data: Lecturer teaching assignments (Phân công giảng viên) ─
$tSem    = (int)($_GET['tsem'] ?? 0);
$tLec    = (int)($_GET['tl'] ?? 0);
$tDept   = trim($_GET['tdept'] ?? '');
$tCourse = (int)($_GET['tcourse'] ?? 0);
$tSec    = trim($_GET['tsec'] ?? '');

$assignAll = $pdo->query(
    "SELECT cs.section_id, cs.section_code, cs.capacity, cs.lecturer_id,
            cs.course_id, c.course_code, c.course_name,
            c.department_id, d.department_code, d.department_name,
            sem.semester_id, sem.semester_name, sem.academic_year, sem.status AS sem_status,
            l.full_name AS lecturer_name,
            (SELECT COUNT(*) FROM Enrollment e
              WHERE e.section_id=cs.section_id AND e.status IN ('Enrolled','Completed')) AS enrolled
     FROM ClassSection cs
     JOIN Course c ON c.course_id=cs.course_id
     LEFT JOIN Department d ON d.department_id=c.department_id
     JOIN Semester sem ON sem.semester_id=cs.semester_id
     JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
     ORDER BY sem.start_date DESC, c.course_code ASC, cs.section_code ASC"
)->fetchAll();

$assignRows = array_values(array_filter($assignAll, function (array $r) use ($tSem,$tLec,$tDept,$tCourse,$tSec): bool {
    if ($tSem > 0 && (int)$r['semester_id'] !== $tSem) return false;
    if ($tLec > 0 && (int)$r['lecturer_id'] !== $tLec) return false;
    if ($tDept !== '' && $r['department_code'] !== $tDept) return false;
    if ($tCourse > 0 && (int)$r['course_id'] !== $tCourse) return false;
    if ($tSec !== '' && mb_stripos((string)$r['section_code'], $tSec) === false
                     && mb_stripos((string)$r['course_code'], $tSec) === false) return false;
    return true;
}));

$allCourses = $pdo->query(
    'SELECT course_id, course_code, course_name, department_id FROM Course
     WHERE is_active=TRUE ORDER BY course_code ASC'
)->fetchAll();

$assignSemDefault = $tSem > 0 ? $tSem : $defaultSem;

// ── Section detail (roster + transfer targets) ────────────────
$viewSectionId = (int)($_GET['section'] ?? 0);
$viewSection   = null;
$sectionStudents = [];
$transferTargets = [];
if ($viewSectionId > 0) {
    $vs = $pdo->prepare(
        'SELECT cs.section_id, cs.section_code, cs.schedule, cs.room, cs.capacity,
                cs.min_enrollment, cs.max_enrollment, cs.study_days, cs.start_time, cs.end_time,
                cs.reg_open, cs.reg_close, cs.section_status, cs.status_note, cs.status_changed_at,
                cs.course_id, cs.semester_id, cs.lecturer_id,
                c.course_code, c.course_name, c.credits,
                sem.semester_name, sem.academic_year,
                l.full_name AS lecturer_name,
                (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=cs.section_id
                 AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled
         FROM ClassSection cs
         JOIN Course c ON c.course_id=cs.course_id
         JOIN Semester sem ON sem.semester_id=cs.semester_id
         JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
         WHERE cs.section_id=:sid LIMIT 1'
    );
    $vs->execute([':sid'=>$viewSectionId]);
    $viewSection = $vs->fetch() ?: null;

    if ($viewSection) {
        $ro = $pdo->prepare(
            'SELECT e.enrollment_id, e.status, e.enrollment_date,
                    s.student_id, s.full_name, s.class_code, s.email
             FROM Enrollment e
             JOIN Student s ON s.student_id=e.student_id
             WHERE e.section_id=:sid AND e.status IN (\'Enrolled\',\'Completed\')
             ORDER BY s.student_id ASC'
        );
        $ro->execute([':sid'=>$viewSectionId]);
        $sectionStudents = $ro->fetchAll();

        $tt = $pdo->prepare(
            'SELECT cs.section_id, cs.section_code, cs.capacity,
                    (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=cs.section_id
                     AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled
             FROM ClassSection cs
             WHERE cs.course_id=:cid AND cs.semester_id=:sem AND cs.section_id<>:sid
             ORDER BY cs.section_code ASC'
        );
        $tt->execute([':cid'=>$viewSection['course_id'], ':sem'=>$viewSection['semester_id'], ':sid'=>$viewSectionId]);
        $transferTargets = $tt->fetchAll();
    }
}

// ── Stats ─────────────────────────────────────────────────────
$totalCourses  = (int)$pdo->query("SELECT COUNT(*) FROM Course WHERE is_active=TRUE")->fetchColumn();
$totalSections = (int)$pdo->query("SELECT COUNT(*) FROM ClassSection")->fetchColumn();
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM Student WHERE status='Active'")->fetchColumn();

function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Môn học & Lớp học phần';
$activePage = 'courses';
$breadcrumb = 'Quản trị › Môn học & Lớp học phần';
require __DIR__ . '/_layout.php';
?>

<!-- Flash -->
<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:4px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success'?'✅':'⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Action buttons -->
<div style="display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px;">
    <?php if ($tab==='courses'): ?>
    <button onclick="showModal('modalAddCourse')" class="abtn abtn--primary" style="background:#0d1b2e;">➕ Thêm môn học</button>
    <?php elseif ($tab==='sections'): ?>
    <button onclick="showModal('modalAddSection')" class="abtn abtn--primary" style="background:#0d1b2e;">➕ Tạo lớp học phần</button>
    <?php endif; ?>
</div>

<!-- KPI -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Tổng môn học</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-grades.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalCourses ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Lớp học phần</span><span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-icon-credits.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalSections ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Sinh viên đang học</span><span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-nav-profile.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalStudents ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Ngành</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-icon-ai.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= count($depts) ?></span></div></div>
</div>

<!-- Tabs -->
<div class="adm-tabs" style="background:#fff;border-radius:12px;padding:0 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <a href="courses.php?tab=courses" class="adm-tab <?= $tab==='courses'?'active':'' ?>">📚 Danh sách môn học (<?= $totalCourses ?>)</a>
    <a href="courses.php?tab=sections" class="adm-tab <?= $tab==='sections'?'active':'' ?>">🏫 Lớp học phần (<?= $totalSections ?>)</a>
    <a href="courses.php?tab=assign" class="adm-tab <?= $tab==='assign'?'active':'' ?>">👨‍🏫 Phân công giảng viên</a>
</div>

<?php if ($tab === 'courses'): ?>
<!-- ── COURSES TAB ──────────────────────────────────────────── -->
<div class="acard" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="courses" />
        <div style="position:relative;flex:0 0 280px;">
            <img src="../img/icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="qc" value="<?= h($searchC) ?>" placeholder="Tìm mã môn, tên môn..."
                   style="width:100%;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="dept" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả bộ môn</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= h($d['department_code']) ?>" <?= $deptFC===$d['department_code']?'selected':'' ?>><?= h($d['department_code']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="abtn abtn--primary abtn--sm">🔍 Lọc</button>
        <?php if ($searchC||$deptFC): ?><a href="courses.php?tab=courses" class="abtn abtn--outline abtn--sm">✕</a><?php endif; ?>
    </form>
</div>
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>MÃ MÔN</th><th>TÊN MÔN HỌC</th><th style="text-align:center;">TC</th><th>BỘ MÔN</th><th>TIÊN QUYẾT</th><th style="text-align:center;">NGÀNH SD</th><th style="text-align:center;">SỐ LỚP</th><th>TRẠNG THÁI</th><th>THAO TÁC</th></tr></thead>
            <tbody>
                <?php if (empty($courseRows)): ?>
                <tr><td colspan="9" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Không tìm thấy môn học nào.</td></tr>
                <?php else: ?>
                <?php foreach ($courseRows as $c): ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($c['course_code']) ?></span></td>
                    <td style="font-weight:600;color:#0d1b2e;"><?= h($c['course_name']) ?></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$c['credits'] ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= h($c['department_name'] ?? '—') ?></td>
                    <td style="font-size:12px;">
                        <?php if ($c['prereq_code']): ?>
                        <span style="font-family:monospace;background:#eff6ff;color:#1d4ed8;padding:1px 5px;border-radius:4px;"><?= h($c['prereq_code']) ?></span>
                        <?php else: ?><span style="color:#94a3b8;">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center;"><?= (int)$c['curriculum_count'] ?></td>
                    <td style="text-align:center;"><?= (int)$c['section_count'] ?></td>
                    <td><span class="apill <?= $c['is_active']?'apill--green':'apill--gray' ?>"><?= $c['is_active']?'Đang dạy':'Tạm dừng' ?></span></td>
                    <td style="white-space:nowrap;">
                        <button onclick="openEditCourseModal(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)" class="abtn abtn--outline abtn--sm">✏</button>
                        <button onclick="openViewCourseModal(<?= (int)$c['course_id'] ?>)" class="abtn abtn--outline abtn--sm">👁</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'sections'): ?>
<!-- ── SECTIONS TAB ─────────────────────────────────────────── -->
<div class="acard" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="sections" />
        <div style="position:relative;flex:0 0 240px;">
            <img src="../img/icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="qs" value="<?= h($searchS) ?>" placeholder="Tìm mã lớp, tên môn..."
                   style="width:100%;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="sem" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="0">Tất cả học kỳ</option>
            <?php foreach ($semesters as $s): ?>
            <option value="<?= (int)$s['semester_id'] ?>" <?= (int)$s['semester_id']===$defaultSem?'selected':'' ?>>
                <?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="lec" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="0">Tất cả giảng viên</option>
            <?php foreach ($lecturers as $l): ?>
            <option value="<?= (int)$l['lecturer_id'] ?>" <?= $lecFS===(int)$l['lecturer_id']?'selected':'' ?>><?= h($l['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="dept" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả bộ môn</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= h($d['department_code']) ?>" <?= $deptS===$d['department_code']?'selected':'' ?>><?= h($d['department_code']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="stat" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Mọi trạng thái lớp</option>
            <?php foreach (['Pending'=>'Chờ mở lớp','Confirmed'=>'Đã mở lớp','Insufficient'=>'Không đủ sĩ số','Cancelled'=>'Đã hủy'] as $sv=>$sl): ?>
            <option value="<?= $sv ?>" <?= $statFS===$sv?'selected':'' ?>><?= $sl ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="abtn abtn--primary abtn--sm">🔍 Lọc</button>
        <?php if ($searchS||$lecFS||$deptS||$semFS||$statFS): ?><a href="courses.php?tab=sections" class="abtn abtn--outline abtn--sm">✕</a><?php endif; ?>
    </form>
</div>
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>MÃ LỚP</th><th>MÔN HỌC</th><th style="text-align:center;">TC</th><th>GIẢNG VIÊN</th><th>LỊCH &amp; PHÒNG</th><th style="text-align:center;">ĐĂNG KÝ / SĨ SỐ</th><th>THAO TÁC</th></tr></thead>
            <tbody>
                <?php if (empty($sectionRows)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Không có lớp học phần nào.</td></tr>
                <?php else: ?>
                <?php foreach ($sectionRows as $s):
                    $enrolled = (int)$s['enrolled'];
                    $capState = RegistrationService::capacityState($s, $enrolled);
                    $meta     = RegistrationService::statusMeta($s['section_status']);
                ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($s['section_code']) ?></span>
                        <div style="font-size:10px;color:#94a3b8;"><?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?></div></td>
                    <td style="font-weight:600;color:#0d1b2e;font-size:13px;"><?= h($s['course_name']) ?>
                        <span style="font-size:11px;font-weight:400;color:#94a3b8;">(<?= h($s['course_code']) ?>)</span></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$s['credits'] ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= h($s['lecturer_name']) ?></td>
                    <td style="font-size:12px;color:#64748b;">
                        <?= h($s['schedule'] ?: RegistrationService::scheduleText(
                            RegistrationService::normalizeDays($s['study_days'] ?? ''),
                            $s['start_time'] ?? null, $s['end_time'] ?? null)) ?: '—' ?>
                        <?= $s['room']?' • '.h($s['room']):'' ?>
                        <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                            Ngưỡng mở: <?= (int)$capState['threshold'] ?> • Min <?= (int)$s['min_enrollment'] ?> / Max <?= (int)$s['max_enrollment'] ?>
                        </div>
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <span style="font-weight:700;color:<?= $capState['state']==='full'?'#ef4444':($capState['state']==='open'?'#059669':'#b45309') ?>;">
                            <?= $enrolled ?> / <?= (int)$s['max_enrollment'] ?>
                        </span>
                        <div><span style="display:inline-block;margin-top:2px;font-size:10px;font-weight:700;padding:1px 6px;border-radius:9999px;background:<?= $meta['bg'] ?>;color:<?= $meta['fg'] ?>;"><?= h($meta['label']) ?></span></div>
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="courses.php?tab=sections&amp;sem=<?= (int)$s['semester_id'] ?>&amp;section=<?= (int)$s['section_id'] ?>"
                           class="abtn abtn--outline abtn--sm" title="Xem sĩ số lớp">👁</a>
                        <button type="button" onclick='openEditSectionModal(<?= htmlspecialchars(json_encode($s, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>)'
                                class="abtn abtn--outline abtn--sm" title="Chỉnh sửa lớp">✏</button>
                        <button onclick="openAssignModal(<?= (int)$s['section_id'] ?>, '<?= h($s['section_code']) ?>', <?= (int)$s['lecturer_id'] ?>)"
                                class="abtn abtn--outline abtn--sm" title="Đổi giảng viên">👨‍🏫</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'assign'): ?>
<!-- ── ASSIGN TAB: PHÂN CÔNG GIẢNG VIÊN ─────────────────────── -->
<div style="display:grid;grid-template-columns:minmax(320px,400px) minmax(0,1fr);gap:16px;align-items:start;">
    <div class="acard" style="padding:20px;">
        <h2 style="font-size:16px;font-weight:800;color:#0d1b2e;margin-bottom:4px;">👨‍🏫 Phân công giảng viên</h2>
        <p style="font-size:12px;color:#64748b;margin-bottom:16px;line-height:1.5;">
            Chọn <b>học kỳ → môn học → lớp học phần → giảng viên</b> rồi xác nhận.
            Mỗi phân công gắn với một lớp học phần cụ thể, không gắn với ngành.
        </p>
        <form method="POST" id="assignForm" style="display:flex;flex-direction:column;gap:12px;">
            <?= csrf_input() ?>
            <input type="hidden" name="_action" value="assign_lecturer" />
            <input type="hidden" name="return_tab" value="assign" />
            <input type="hidden" name="return_sem" id="af_sem_hidden" value="<?= (int)$assignSemDefault ?>" />
            <input type="hidden" name="semester_id" id="af_sem_id" value="<?= (int)$assignSemDefault ?>" />
            <input type="hidden" name="course_id" id="af_course_id" value="" />
            <div>
                <label for="af_sem" style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">1. Học kỳ *</label>
                <select id="af_sem" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($semesters as $s): ?>
                    <option value="<?= (int)$s['semester_id'] ?>" <?= (int)$s['semester_id']===$assignSemDefault?'selected':'' ?>>
                        <?= h($s['semester_name'].' '.$s['academic_year']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="af_course" style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">2. Môn học *</label>
                <select id="af_course" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn môn học —</option>
                </select>
            </div>
            <div>
                <label for="af_section" style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">3. Lớp học phần *</label>
                <select name="section_id" id="af_section" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn lớp học phần —</option>
                </select>
            </div>
            <div id="af_info" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:12px;color:#334155;line-height:1.6;"></div>
            <div>
                <label for="af_lec" style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">4. Giảng viên *</label>
                <select name="lecturer_id" id="af_lec" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn giảng viên —</option>
                    <?php foreach ($lecturers as $l): ?>
                    <option value="<?= (int)$l['lecturer_id'] ?>"><?= h($l['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;height:38px;">✅ Xác nhận phân công</button>
            <p style="font-size:11px;color:#94a3b8;margin:0;">Mỗi lớp học phần có một giảng viên phụ trách. Lớp đã có lịch chính thức phải đổi giảng viên tại trang Lịch giảng dạy để đồng bộ buổi học tương lai và giữ nguyên lịch sử.</p>
        </form>
    </div>

    <div style="display:flex;flex-direction:column;gap:12px;min-width:0;">
        <div class="acard" style="padding:14px 16px;">
            <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="tab" value="assign" />
                <select name="tsem" style="height:34px;padding:0 8px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;">
                    <option value="0">Tất cả học kỳ</option>
                    <?php foreach ($semesters as $s): ?>
                    <option value="<?= (int)$s['semester_id'] ?>" <?= $tSem===(int)$s['semester_id']?'selected':'' ?>><?= h($s['semester_name'].' '.$s['academic_year']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="tdept" style="height:34px;padding:0 8px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;">
                    <option value="">Tất cả ngành</option>
                    <?php foreach ($depts as $d): ?>
                    <option value="<?= h($d['department_code']) ?>" <?= $tDept===$d['department_code']?'selected':'' ?>><?= h($d['department_code']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="tcourse" style="height:34px;padding:0 8px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;max-width:190px;">
                    <option value="0">Tất cả môn học</option>
                    <?php foreach ($allCourses as $c): ?>
                    <option value="<?= (int)$c['course_id'] ?>" <?= $tCourse===(int)$c['course_id']?'selected':'' ?>><?= h($c['course_code'].' – '.$c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="tsec" value="<?= h($tSec) ?>" placeholder="Mã lớp..."
                       style="height:34px;width:120px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;outline:none;" />
                <select name="tl" style="height:34px;padding:0 8px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;">
                    <option value="0">Tất cả giảng viên</option>
                    <?php foreach ($lecturers as $l): ?>
                    <option value="<?= (int)$l['lecturer_id'] ?>" <?= $tLec===(int)$l['lecturer_id']?'selected':'' ?>><?= h($l['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="abtn abtn--primary abtn--sm" style="height:34px;">🔍 Lọc</button>
                <?php if ($tSem||$tLec||$tDept!==''||$tCourse||$tSec!==''): ?>
                <a href="courses.php?tab=assign" class="abtn abtn--outline abtn--sm" style="height:34px;">✕</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="acard" style="padding:0;overflow:hidden;">
            <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#64748b;">
                Hiển thị <b style="color:#0d1b2e;"><?= count($assignRows) ?></b> / <?= count($assignAll) ?> phân công
            </div>
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead><tr>
                        <th>GIẢNG VIÊN</th><th>NGÀNH</th><th>MÔN HỌC</th>
                        <th>LỚP HỌC PHẦN</th><th>HỌC KỲ</th><th style="text-align:center;">SĨ SỐ</th><th>THAO TÁC</th>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($assignRows)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Không có phân công nào khớp bộ lọc.</td></tr>
                        <?php else: ?>
                        <?php foreach ($assignRows as $a):
                            $aEnr = (int)$a['enrolled'];
                            $aSt  = class_size_state($aEnr);
                            [$aTxt,$aBg,$aFg] = match($aSt) {
                                'under' => ['Thiếu', '#fef9c3', '#854d0e'],
                                'over'  => ['Vượt',  '#fee2e2', '#b91c1c'],
                                default => ['Đạt',   '#d1fae5', '#065f46'],
                            };
                        ?>
                        <tr>
                            <td style="font-weight:700;color:#0d1b2e;font-size:12px;white-space:nowrap;"><?= h($a['lecturer_name']) ?></td>
                            <td style="font-size:12px;color:#64748b;"><?= h($a['department_code'] ?? '—') ?></td>
                            <td style="font-size:12px;color:#0d1b2e;">
                                <b><?= h($a['course_code']) ?></b>
                                <div style="font-size:11px;color:#64748b;"><?= h($a['course_name']) ?></div>
                            </td>
                            <td><span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($a['section_code']) ?></span></td>
                            <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?= h($a['semester_name']) ?> <?= h($a['academic_year']) ?></td>
                            <td style="text-align:center;font-weight:700;white-space:nowrap;">
                                <?= $aEnr ?><?= $a['capacity'] ? ' / '.(int)$a['capacity'] : '' ?>
                                <div><span style="display:inline-block;margin-top:2px;font-size:10px;font-weight:700;padding:1px 6px;border-radius:9999px;background:<?= $aBg ?>;color:<?= $aFg ?>;"><?= $aTxt ?></span></div>
                            </td>
                            <td style="white-space:nowrap;">
                                <a href="courses.php?tab=sections&amp;sem=<?= (int)$a['semester_id'] ?>&amp;section=<?= (int)$a['section_id'] ?>"
                                   class="abtn abtn--outline abtn--sm" title="Xem sĩ số lớp">👁</a>
                                <button type="button" onclick="openAssignModal(<?= (int)$a['section_id'] ?>, '<?= h($a['section_code']) ?>', <?= (int)$a['lecturer_id'] ?>, 'assign')"
                                        class="abtn abtn--outline abtn--sm" title="Đổi giảng viên">👨‍🏫</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
window.ASSIGN_SECTIONS = <?= json_encode($assignAll, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?: '[]' ?>;
</script>
<?php endif; ?>

<!-- ══ MODAL: ADD COURSE ═══════════════════════════════════════ -->
<div id="modalAddCourse" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="closeModal('modalAddCourse')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">➕ Thêm môn học mới</h2>
    <form method="POST">
                    <?= csrf_input() ?>
        <input type="hidden" name="_action" value="add_course" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã môn *</label>
                <input type="text" name="course_code" placeholder="VD: INS3001" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Số tín chỉ *</label>
                <input type="number" name="credits" min="1" max="10" value="3" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên môn học *</label>
                <input type="text" name="course_name" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bộ môn quản lý *</label>
                <select name="department_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn bộ môn —</option>
                    <?php foreach ($depts as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= h($d['department_code'].' – '.$d['department_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Môn tiên quyết (mặc định cho mọi lớp)</label>
                <select name="prerequisite_course_id" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Không có —</option>
                    <?php foreach ($allCourses as $co): ?><option value="<?= (int)$co['course_id'] ?>"><?= h($co['course_code'].' – '.$co['course_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trạng thái</label>
                <select name="is_active" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="1">Đang mở</option>
                    <option value="0">Tạm dừng</option>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mô tả</label>
                <textarea name="description" rows="2" style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none;resize:vertical;"></textarea></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalAddCourse')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Thêm môn học</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: EDIT COURSE ══════════════════════════════════════ -->
<div id="modalEditCourse" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="closeModal('modalEditCourse')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">✏ Chỉnh sửa môn học</h2>
    <form method="POST">
                    <?= csrf_input() ?>
        <input type="hidden" name="_action"   value="edit_course" />
        <input type="hidden" name="course_id" id="ec_course_id" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã môn (không thể đổi)</label>
                <input type="text" id="ec_code" readonly style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#f8fafc;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Số tín chỉ *</label>
                <input type="number" name="credits" id="ec_credits" min="1" max="10" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên môn học *</label>
                <input type="text" name="course_name" id="ec_name" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bộ môn quản lý *</label>
                <select name="department_id" id="ec_dept" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn bộ môn —</option>
                    <?php foreach ($depts as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= h($d['department_code'].' – '.$d['department_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Môn tiên quyết</label>
                <select name="prerequisite_course_id" id="ec_prereq" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Không có —</option>
                    <?php foreach ($allCourses as $co): ?><option value="<?= (int)$co['course_id'] ?>"><?= h($co['course_code'].' – '.$co['course_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mô tả</label>
                <textarea name="description" id="ec_desc" rows="2" style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none;resize:vertical;"></textarea></div>
            <div><label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;"><input type="checkbox" name="is_active" id="ec_active" /> Đang mở lớp</label></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalEditCourse')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu thay đổi</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: ADD SECTION (OFFERING) ═══════════════════════════ -->
<div id="modalAddSection" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:660px;padding:28px;position:relative;max-height:92vh;overflow-y:auto;">
    <button onclick="closeModal('modalAddSection')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:4px;">➕ Tạo lớp học phần</h2>
    <p style="font-size:12px;color:#64748b;margin-bottom:16px;">Lịch dự kiến, phòng học và giảng viên được kiểm tra trùng trước khi lưu. Lớp mới ở trạng thái <b>Chờ mở lớp</b>. Sau khi tạo lớp, hãy xác nhận lịch tuần chính thức để tạo buổi học theo số tín chỉ. Lịch dự kiến có thể chọn nhiều ngày; lịch chính thức hiện hỗ trợ một ngày trong tuần.</p>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="add_section" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Môn học *</label>
                <select name="course_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn môn học —</option>
                    <?php foreach ($allCourses as $co): ?><option value="<?= (int)$co['course_id'] ?>"><?= h($co['course_code'].' – '.$co['course_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã lớp *</label>
                <input type="text" name="section_code" placeholder="VD: INS3065-01" required maxlength="20"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Học kỳ *</label>
                <select name="semester_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($semesters as $s): ?><option value="<?= (int)$s['semester_id'] ?>" <?= (int)$s['semester_id']===$defaultSem?'selected':'' ?>><?= h($s['semester_name'].' '.$s['academic_year']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giảng viên *</label>
                <select name="lecturer_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn giảng viên —</option>
                    <?php foreach ($lecturers as $l): ?><option value="<?= (int)$l['lecturer_id'] ?>"><?= h($l['full_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:6px;">Ngày học trong tuần *</label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <?php for ($d=1; $d<=7; $d++): ?>
                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:5px 9px;cursor:pointer;">
                        <input type="checkbox" name="study_days[]" value="<?= $d ?>" id="as_day_<?= $d ?>" /> <?= h(RegistrationService::dayLabel($d)) ?>
                    </label>
                    <?php endfor; ?>
                </div>
            </div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giờ bắt đầu *</label>
                <input type="time" name="start_time" id="as_start" required value="07:30"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giờ kết thúc *</label>
                <input type="time" name="end_time" id="as_end" required value="09:00"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Phòng học</label>
                <input type="text" name="room" placeholder="VD: B1.105" maxlength="30"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Sĩ số tối thiểu *</label>
                    <input type="number" name="min_enrollment" id="as_min" min="1" max="200" value="48" required
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
                <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Sĩ số tối đa *</label>
                    <input type="number" name="max_enrollment" id="as_max" min="1" max="200" value="64" required
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            </div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mở đăng ký (tuỳ chọn)</label>
                <input type="datetime-local" name="reg_open"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Đóng đăng ký (tuỳ chọn)</label>
                <input type="datetime-local" name="reg_close"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
        </div>
        <div style="margin-top:10px;font-size:11px;color:#64748b;background:#f8fafc;border-radius:8px;padding:8px 10px;">
            Ngưỡng tự động mở lớp = <b>floor(sĩ số tối thiểu / 2) + 1</b>.
            Với mức tối thiểu 48, lớp mở khi đạt <b>25</b> sinh viên; mức tối thiểu 30 mở khi đạt <b>16</b>.
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalAddSection')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Tạo lớp học phần</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: EDIT SECTION (OFFERING) ══════════════════════════ -->
<div id="modalEditSection" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:660px;padding:28px;position:relative;max-height:92vh;overflow-y:auto;">
    <button onclick="closeModal('modalEditSection')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:4px;">✏ Chỉnh sửa lớp <span id="es_code_label"></span></h2>
    <p style="font-size:12px;color:#64748b;margin-bottom:16px;">Thay đổi được kiểm tra trùng trước khi lưu. Khi lớp đã có lịch chính thức, chỉ sửa mã lớp, sĩ số và thời gian đăng ký tại đây; đổi giảng viên, phòng và lịch tuần tại <a id="es_schedule_link" href="schedule.php">Lịch giảng dạy</a> để giữ nguyên lịch sử. Không thể đổi môn học/học kỳ khi đã có lịch chính thức hoặc dữ liệu học tập.</p>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="edit_section" />
        <input type="hidden" name="section_id" id="es_id" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Môn học *</label>
                <select name="course_id" id="es_course" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($allCourses as $co): ?><option value="<?= (int)$co['course_id'] ?>"><?= h($co['course_code'].' – '.$co['course_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã lớp *</label>
                <input type="text" name="section_code" id="es_code" required maxlength="20"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Học kỳ *</label>
                <select name="semester_id" id="es_sem" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($semesters as $s): ?><option value="<?= (int)$s['semester_id'] ?>"><?= h($s['semester_name'].' '.$s['academic_year']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giảng viên *</label>
                <select name="lecturer_id" id="es_lec" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($lecturers as $l): ?><option value="<?= (int)$l['lecturer_id'] ?>"><?= h($l['full_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:6px;">Ngày học trong tuần *</label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <?php for ($d=1; $d<=7; $d++): ?>
                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:5px 9px;cursor:pointer;">
                        <input type="checkbox" name="study_days[]" value="<?= $d ?>" id="es_day_<?= $d ?>" /> <?= h(RegistrationService::dayLabel($d)) ?>
                    </label>
                    <?php endfor; ?>
                </div>
            </div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giờ bắt đầu *</label>
                <input type="time" name="start_time" id="es_start" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giờ kết thúc *</label>
                <input type="time" name="end_time" id="es_end" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Phòng học</label>
                <input type="text" name="room" id="es_room" maxlength="30"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tối thiểu *</label>
                    <input type="number" name="min_enrollment" id="es_min" min="1" max="200" required
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
                <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tối đa *</label>
                    <input type="number" name="max_enrollment" id="es_max" min="1" max="200" required
                           style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            </div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mở đăng ký</label>
                <input type="datetime-local" name="reg_open" id="es_open"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Đóng đăng ký</label>
                <input type="datetime-local" name="reg_close" id="es_close"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalEditSection')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu thay đổi</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: ASSIGN LECTURER ══════════════════════════════════ -->
<div id="modalAssign" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:420px;padding:28px;position:relative;">
    <button onclick="closeModal('modalAssign')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:4px;">👨‍🏫 Đổi giảng viên</h2>
    <p id="assignLabel" style="font-size:13px;color:#64748b;margin-bottom:16px;"></p>
    <form method="POST">
                    <?= csrf_input() ?>
        <input type="hidden" name="_action"    value="assign_lecturer" />
        <input type="hidden" name="return_tab" id="assign_ret_tab" value="sections" />
        <input type="hidden" name="section_id" id="assign_sid" />
        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:6px;">Giảng viên mới *</label>
        <select name="lecturer_id" id="assign_lid" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;margin-bottom:16px;">
            <?php foreach ($lecturers as $l): ?><option value="<?= (int)$l['lecturer_id'] ?>"><?= h($l['full_name']) ?></option><?php endforeach; ?>
        </select>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" onclick="closeModal('modalAssign')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Cập nhật</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: SECTION DETAIL / ROSTER ══════════════════════════ -->
<?php if ($viewSection):
    $vsEnrolled = (int)$viewSection['enrolled'];
    $vsCap      = RegistrationService::capacityState($viewSection, $vsEnrolled);
    $vsMeta     = RegistrationService::statusMeta($viewSection['section_status']);
    $capDenom   = max(1, (int)$viewSection['max_enrollment']);
    $barPct     = min(100, (int)round($vsEnrolled / $capDenom * 100));
    $barColor   = $vsCap['state']==='full' ? '#ef4444' : ($vsCap['state']==='open' ? '#22c55e' : '#f59e0b');
?>
<div id="modalSection" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:880px;padding:28px;position:relative;max-height:92vh;overflow-y:auto;">
    <button onclick="closeModal('modalSection')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:4px;">👥 Sĩ số lớp <?= h($viewSection['section_code']) ?></h2>
    <p style="font-size:13px;color:#64748b;margin-bottom:14px;">
        <?= h($viewSection['course_code'].' – '.$viewSection['course_name']) ?>
        • <?= h($viewSection['semester_name'].' '.$viewSection['academic_year']) ?>
        • GV: <?= h($viewSection['lecturer_name']) ?>
        <?php if ($viewSection['schedule']): ?> • <?= h($viewSection['schedule']) ?><?php endif; ?>
        <?php if ($viewSection['room']): ?> • <?= h($viewSection['room']) ?><?php endif; ?>
    </p>

    <!-- Capacity & status summary -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
            <span style="font-size:13px;font-weight:700;color:#0d1b2e;"><?= h($vsCap['text']) ?></span>
            <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:9999px;background:<?= $vsMeta['bg'] ?>;color:<?= $vsMeta['fg'] ?>;"><?= h($vsMeta['label']) ?></span>
        </div>
        <div style="height:6px;background:#e2e8f0;border-radius:9999px;overflow:hidden;">
            <div style="height:100%;width:<?= $barPct ?>%;background:<?= $barColor ?>;border-radius:9999px;"></div>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:#64748b;margin-top:8px;">
            <span>Sĩ số tối thiểu: <b><?= (int)$viewSection['min_enrollment'] ?></b></span>
            <span>Sĩ số tối đa: <b><?= (int)$viewSection['max_enrollment'] ?></b></span>
            <span>Ngưỡng mở lớp: <b><?= (int)$vsCap['threshold'] ?></b> (= 50% min + 1)</span>
            <?php if ($viewSection['status_changed_at']): ?>
            <span>Cập nhật trạng thái: <?= date('d/m/Y H:i', strtotime($viewSection['status_changed_at'])) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($viewSection['status_note']): ?>
        <div style="font-size:11px;color:#64748b;margin-top:4px;">Ghi chú: <?= h($viewSection['status_note']) ?></div>
        <?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0;">
            <form method="POST" style="display:flex;gap:6px;align-items:center;margin:0;flex-wrap:wrap;">
                <?= csrf_input() ?>
                <input type="hidden" name="_action" value="override_section_status" />
                <input type="hidden" name="section_id" value="<?= (int)$viewSection['section_id'] ?>" />
                <input type="hidden" name="sem_return" value="<?= (int)$viewSection['semester_id'] ?>" />
                <select name="new_status" required style="height:32px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;background:#fff;padding:0 8px;">
                    <?php foreach (['Confirmed'=>'Đã mở lớp','Pending'=>'Chờ mở lớp','Insufficient'=>'Không đủ sĩ số','Cancelled'=>'Đã hủy'] as $sv=>$sl): ?>
                    <option value="<?= $sv ?>" <?= $viewSection['section_status']===$sv?'selected':'' ?>><?= $sl ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="note" maxlength="200" placeholder="Lý do (bắt buộc với hủy/không đủ)"
                       style="height:32px;width:220px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;padding:0 8px;outline:none;" />
                <button type="submit" class="abtn abtn--outline abtn--sm" style="height:32px;"
                        onclick="return confirm('Xác nhận đổi trạng thái lớp? Thao tác được ghi nhật ký.')">⚡ Đổi trạng thái</button>
            </form>
            <button type="button" class="abtn abtn--outline abtn--sm" style="height:32px;"
                    onclick='closeModal("modalSection");openEditSectionModal(<?= htmlspecialchars(json_encode($viewSection, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>)'>✏ Sửa lịch / sĩ số</button>
        </div>
    </div>

    <!-- Add student -->
    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #e2e8f0;">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="section_enroll" />
        <input type="hidden" name="section_id" value="<?= (int)$viewSection['section_id'] ?>" />
        <input type="hidden" name="sem_return" value="<?= (int)$viewSection['semester_id'] ?>" />
        <input type="hidden" name="section_return" value="<?= (int)$viewSection['section_id'] ?>" />
        <div style="flex:1 1 260px;">
            <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Thêm sinh viên vào lớp</label>
            <select name="student_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                <option value="">— Chọn sinh viên —</option>
                <?php foreach ($allStudents as $st): ?>
                <option value="<?= h($st['student_id']) ?>"><?= h($st['student_id'].' – '.$st['full_name'].' ('.$st['class_code'].')') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#b91c1c;height:36px;cursor:pointer;">
            <input type="checkbox" name="override" /> Vượt sĩ số (xác nhận của QTV)
        </label>
        <button type="submit" class="abtn abtn--primary abtn--sm" style="background:#0d1b2e;height:36px;">➕ Ghi danh</button>
    </form>

    <!-- Roster -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:13px;font-weight:700;color:#0d1b2e;">Danh sách sinh viên (<?= count($sectionStudents) ?>)</span>
    </div>
    <div class="adm-table-wrap" style="max-height:340px;overflow:auto;">
        <table class="adm-table">
            <thead><tr><th>MSSV</th><th>HỌ VÀ TÊN</th><th>LỚP</th><th>EMAIL</th><th>NGÀY ĐK</th><th>THAO TÁC</th></tr></thead>
            <tbody>
                <?php if (empty($sectionStudents)): ?>
                <tr><td colspan="6" style="text-align:center;padding:24px;color:#94a3b8;font-style:italic;">Lớp chưa có sinh viên.</td></tr>
                <?php else: ?>
                <?php foreach ($sectionStudents as $st): ?>
                <tr>
                    <td style="font-family:monospace;font-size:12px;"><?= h($st['student_id']) ?></td>
                    <td style="font-weight:600;color:#0d1b2e;"><?= h($st['full_name']) ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= h($st['class_code']) ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= h($st['email'] ?? '—') ?></td>
                    <td style="font-size:11px;color:#94a3b8;"><?= $st['enrollment_date'] ? date('d/m/Y', strtotime($st['enrollment_date'])) : '—' ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <?php if (!empty($transferTargets)): ?>
                            <form method="POST" style="display:flex;gap:4px;align-items:center;margin:0;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="_action" value="section_transfer" />
                                <input type="hidden" name="enrollment_id" value="<?= (int)$st['enrollment_id'] ?>" />
                                <input type="hidden" name="sem_return" value="<?= (int)$viewSection['semester_id'] ?>" />
                                <input type="hidden" name="section_return" value="<?= (int)$viewSection['section_id'] ?>" />
                                <select name="target_section_id" required style="height:28px;border:1px solid #e2e8f0;border-radius:6px;font-size:11px;background:#fff;">
                                    <option value="">Chuyển sang…</option>
                                    <?php foreach ($transferTargets as $tt): ?>
                                    <option value="<?= (int)$tt['section_id'] ?>"><?= h($tt['section_code'].' ('.(int)$tt['enrolled'].'/'.($tt['capacity'] ? (int)$tt['capacity'] : '∞').')') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label title="Xác nhận vượt sĩ số lớp đích" style="display:flex;align-items:center;gap:2px;font-size:10px;color:#b91c1c;cursor:pointer;">
                                    <input type="checkbox" name="override" />OVR
                                </label>
                                <button type="submit" class="abtn abtn--outline abtn--sm" style="font-size:10px;padding:2px 6px;">Chuyển</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($st['status'] === 'Enrolled'): ?>
                            <form method="POST" onsubmit="return confirm('Rút sinh viên <?= h($st['student_id']) ?> khỏi lớp?');" style="margin:0;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="_action" value="section_unenroll" />
                                <input type="hidden" name="enrollment_id" value="<?= (int)$st['enrollment_id'] ?>" />
                                <input type="hidden" name="sem_return" value="<?= (int)$viewSection['semester_id'] ?>" />
                                <input type="hidden" name="section_return" value="<?= (int)$viewSection['section_id'] ?>" />
                                <button type="submit" class="abtn abtn--red abtn--sm" style="font-size:10px;padding:2px 6px;">Rút</button>
                            </form>
                            <?php else: ?>
                            <span class="apill apill--gray" style="font-size:10px;">Đã hoàn thành</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (empty($transferTargets)): ?>
    <p style="font-size:11px;color:#94a3b8;margin-top:10px;">Chưa có lớp khác cùng môn trong cùng học kỳ để chuyển sinh viên.</p>
    <?php endif; ?>
</div></div>
<?php endif; ?>

<!-- ══ MODAL: VIEW COURSE (+ sections) ════════════════════════ -->
<div id="modalViewCourse" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:760px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="closeModal('modalViewCourse')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 id="vc_title" style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:4px;"></h2>
    <div id="vc_meta" style="font-size:13px;color:#64748b;margin-bottom:14px;"></div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;"><div style="font-size:11px;color:#94a3b8;">Số tín chỉ</div><div id="vc_credits" style="font-size:16px;font-weight:800;color:#0d1b2e;"></div></div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;"><div style="font-size:11px;color:#94a3b8;">Số lớp học phần</div><div id="vc_sections_count" style="font-size:16px;font-weight:800;color:#0d1b2e;"></div></div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;"><div style="font-size:11px;color:#94a3b8;">Trạng thái</div><div id="vc_status" style="font-size:16px;font-weight:800;"></div></div>
    </div>
    <div style="font-size:13px;font-weight:700;color:#0d1b2e;margin-bottom:8px;">Các lớp học phần</div>
    <div class="adm-table-wrap" style="max-height:340px;overflow:auto;">
        <table class="adm-table">
            <thead><tr><th>MÃ LỚP</th><th>HỌC KỲ</th><th>GIẢNG VIÊN</th><th>LỊCH &amp; PHÒNG</th><th style="text-align:center;">SĨ SỐ</th></tr></thead>
            <tbody id="vc_sections_body"></tbody>
        </table>
    </div>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<?php if ($viewSection): ?>
<script>document.addEventListener('DOMContentLoaded',function(){showModal('modalSection');});</script>
<?php endif; ?>
<script>
function showModal(id){document.getElementById(id).style.display='flex';document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).style.display='none';document.body.style.overflow='';}
function setVal(id,val){var el=document.getElementById(id);if(el)el.value=val!==null&&val!==undefined?val:'';}
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];});}
['modalAddCourse','modalEditCourse','modalAddSection','modalAssign','modalSection','modalViewCourse','modalEditSection'].forEach(function(id){
    var el=document.getElementById(id);
    if(el)el.addEventListener('click',function(e){if(e.target===this)closeModal(id);});
});
document.addEventListener('keydown',function(e){if(e.key==='Escape')['modalAddCourse','modalEditCourse','modalAddSection','modalAssign','modalSection','modalViewCourse','modalEditSection'].forEach(closeModal);});

function openEditCourseModal(c){
    setVal('ec_course_id', c.course_id);
    setVal('ec_code',     c.course_code);
    setVal('ec_credits',  c.credits);
    setVal('ec_name',     c.course_name);
    setVal('ec_dept',     c.department_id||'');
    setVal('ec_desc',     c.description||'');
    var pre = document.getElementById('ec_prereq');
    if(pre) pre.value = c.prerequisite_course_id || '';
    document.getElementById('ec_active').checked = !!c.is_active;
    showModal('modalEditCourse');
}
function toLocalInput(v){
    if(!v) return '';
    return String(v).replace(' ','T').slice(0,16);
}
function openEditSectionModal(s){
    setVal('es_id', s.section_id);
    document.getElementById('es_schedule_link').href = 'schedule.php?sem='+encodeURIComponent(s.semester_id)
        +'&course='+encodeURIComponent(s.course_id)+'&section='+encodeURIComponent(s.section_id);
    document.getElementById('es_code_label').textContent = s.section_code || '';
    setVal('es_code', s.section_code);
    setVal('es_course', s.course_id);
    setVal('es_sem', s.semester_id);
    setVal('es_lec', s.lecturer_id);
    setVal('es_room', s.room || '');
    setVal('es_min', s.min_enrollment || 48);
    setVal('es_max', s.max_enrollment || s.capacity || 64);
    setVal('es_open', toLocalInput(s.reg_open));
    setVal('es_close', toLocalInput(s.reg_close));

    var days = String(s.study_days || '').split(',').filter(Boolean);
    var start = s.start_time ? String(s.start_time).slice(0,5) : '';
    var end   = s.end_time ? String(s.end_time).slice(0,5) : '';
    if((!days.length || !start || !end) && s.schedule){
        var m = String(s.schedule).match(/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/);
        if(m){ start = start || m[1]; end = end || m[2]; }
        if(!days.length){
            var dm = String(s.schedule).match(/Thứ\s*([2-7](?:\s*(?:&|,|và|\+)\s*[2-7])*)/i);
            if(dm) days = (dm[1].match(/[2-7]/g) || []).map(function(n){ return String(parseInt(n,10) - 1); });
            else if(/\bCN\b/.test(s.schedule)) days = ['7'];
        }
    }
    for(var d=1; d<=7; d++){
        var cb = document.getElementById('es_day_'+d);
        if(cb) cb.checked = days.indexOf(String(d)) >= 0;
    }
    setVal('es_start', start || '07:30');
    setVal('es_end', end || '09:00');
    showModal('modalEditSection');
}
function openViewCourseModal(cid){
    fetch('courses.php?view_course='+cid).then(function(r){return r.json();}).then(function(c){
        if(!c||!c.course_id){alert('Không tìm thấy.');return;}
        document.getElementById('vc_title').textContent = c.course_code + ' – ' + c.course_name;
        document.getElementById('vc_meta').textContent  = (c.department_name || 'Chưa thuộc ngành');
        document.getElementById('vc_credits').textContent = c.credits + ' TC';
        document.getElementById('vc_sections_count').textContent = (c.sections ? c.sections.length : 0);
        var st = document.getElementById('vc_status');
        st.textContent = c.is_active ? 'Đang mở' : 'Tạm dừng';
        st.style.color = c.is_active ? '#065f46' : '#b91c1c';

        var body = document.getElementById('vc_sections_body');
        body.innerHTML = '';
        if(!c.sections || !c.sections.length){
            body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#94a3b8;font-style:italic;">Chưa có lớp học phần.</td></tr>';
        } else {
            c.sections.forEach(function(s){
                var enr = parseInt(s.enrolled,10)||0;
                var cap = parseInt(s.capacity,10)||0;
                var badge = enr>64 ? ['#fee2e2','#b91c1c'] : (enr<48 ? ['#fef9c3','#854d0e'] : ['#d1fae5','#065f46']);
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:4px;">'+esc(s.section_code)+'</span></td>'+
                    '<td style="font-size:12px;color:#64748b;">'+esc(s.semester_name)+' '+esc(s.academic_year)+'</td>'+
                    '<td style="font-size:12px;color:#44474e;">'+esc(s.lecturer_name)+'</td>'+
                    '<td style="font-size:12px;color:#64748b;">'+esc(s.schedule||'—')+(s.room?' • '+esc(s.room):'')+'</td>'+
                    '<td style="text-align:center;font-weight:700;white-space:nowrap;">'+enr+(cap?' / '+cap:'')+
                        ' <span style="display:inline-block;font-size:10px;font-weight:700;padding:1px 6px;border-radius:9999px;background:'+badge[0]+';color:'+badge[1]+';">'+(enr>64?'Vượt':(enr<48?'Thiếu':'Đạt'))+'</span></td>';
                body.appendChild(tr);
            });
        }
        showModal('modalViewCourse');
    });
}
function openAssignModal(sid, code, curLid, retTab){
    document.getElementById('assign_sid').value = sid;
    document.getElementById('assign_lid').value = curLid||'';
    document.getElementById('assign_ret_tab').value = retTab||'sections';
    document.getElementById('assignLabel').textContent = 'Lớp: ' + code;
    showModal('modalAssign');
}

// ── Cascade: Học kỳ → Môn học → Lớp học phần → Giảng viên ──
(function(){
    var sem       = document.getElementById('af_sem');
    var courseSel = document.getElementById('af_course');
    var secSel    = document.getElementById('af_section');
    var lecSel    = document.getElementById('af_lec');
    var info      = document.getElementById('af_info');
    if(!sem || !courseSel || !secSel || !lecSel || !info) return;
    var data = window.ASSIGN_SECTIONS || [];

    function findSection(){
        var id = String(secSel.value);
        for(var i=0;i<data.length;i++){ if(String(data[i].section_id)===id) return data[i]; }
        return null;
    }
    function fillCourses(){
        var sems = String(sem.value);
        var seen = {}, opts = ['<option value="">— Chọn môn học —</option>'];
        data.forEach(function(s){
            if(String(s.semester_id)!==sems) return;
            var k = String(s.course_id);
            if(seen[k]) return; seen[k]=1;
            opts.push('<option value="'+k+'">'+esc(s.course_code+' – '+s.course_name)+'</option>');
        });
        courseSel.innerHTML = opts.join('');
        document.getElementById('af_sem_id').value = sems;
        document.getElementById('af_sem_hidden').value = sems;
        fillSections();
    }
    function fillSections(){
        var sems = String(sem.value), cid = String(courseSel.value);
        var opts = ['<option value="">— Chọn lớp học phần —</option>'];
        document.getElementById('af_course_id').value = cid;
        if(cid){
            data.forEach(function(s){
                if(String(s.semester_id)!==sems || String(s.course_id)!==cid) return;
                opts.push('<option value="'+s.section_id+'">'+esc(s.section_code+' ('+s.enrolled+'/'+(s.capacity||'∞')+')')+'</option>');
            });
        }
        secSel.innerHTML = opts.join('');
        updateInfo();
    }
    function updateInfo(){
        var s = findSection();
        if(!s){ info.style.display='none'; info.innerHTML=''; return; }
        info.style.display = 'block';
        var html = '<b>'+esc(s.course_code+' – '+s.course_name)+'</b><br>'
                 + 'Ngành: '+esc(s.department_code||'—')+' • Học kỳ: '+esc(s.semester_name+' '+s.academic_year)+'<br>'
                 + 'Giảng viên hiện tại: <b>'+esc(s.lecturer_name)+'</b><br>'
                 + 'Sĩ số: <b>'+s.enrolled+'</b>'+(s.capacity?' / '+s.capacity:'');
        if(String(lecSel.value)===String(s.lecturer_id)){
            html += '<br><span style="color:#b45309;">⚠️ Giảng viên đang chọn đã phụ trách lớp này.</span>';
        }
        info.innerHTML = html;
    }
    sem.addEventListener('change', fillCourses);
    courseSel.addEventListener('change', fillSections);
    secSel.addEventListener('change', function(){
        var s = findSection();
        if(s) lecSel.value = String(s.lecturer_id);
        updateInfo();
    });
    lecSel.addEventListener('change', updateInfo);
    fillCourses();
})();
</script>
