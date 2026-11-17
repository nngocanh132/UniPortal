<?php
/**
 * UniPortal – Lớp học & Sinh viên
 * Figma: class screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';
require_once $root . '/src/src/services/AttendanceService.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$lecId   = (int)($_SESSION['lecturer_id'] ?? 0);
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);

$sections  = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount = LecturerModel::getUnreadWarningCount($lecId);

// Selected section
$selSection = (int)($_GET['section'] ?? ($sections[0]['section_id'] ?? 0));
$search     = trim($_GET['q'] ?? '');

// Find selected section info
$selSectionInfo = null;
foreach ($sections as $s) {
    if ((int)$s['section_id'] === $selSection) { $selSectionInfo = $s; break; }
}

// Students in selected section
$statusF  = trim($_GET['status'] ?? '');
$students = $selSection ? LecturerModel::getStudentsInSection($selSection, $search) : [];
$sectionAtt = $selSection ? AttendanceService::sectionSummary(get_db(), $selSection) : [];
$attByEnrollment = [];
foreach (($sectionAtt['students'] ?? []) as $st) {
    $attByEnrollment[(int)$st['enrollment_id']] = $st;
}
if ($statusF !== '') {
    $students = array_values(array_filter($students, function ($st) use ($statusF, $attByEnrollment) {
        $stat = $attByEnrollment[(int)$st['enrollment_id']] ?? null;
        if (!$stat) return true;
        if ($statusF === 'ok')   return $stat['status'] === 'eligible' && (int)$stat['risk_level'] !== 2;
        if ($statusF === 'warn') return $stat['status'] === 'at_risk' || (int)$stat['risk_level'] === 2;
        if ($statusF === 'crit') return $stat['status'] === 'ineligible';
        return true;
    }));
}

// ── Student academic detail JSON (own sections only) ──────────
if (isset($_GET['student'])) {
    $sid   = trim($_GET['student']);
    $secId = (int)($_GET['section'] ?? 0);
    $pdo   = get_db();

    // The student must be enrolled in a section taught by this lecturer.
    $q = $pdo->prepare(
        'SELECT s.student_id, s.full_name, s.class_code, s.email, s.gender,
                s.admission_year, s.status AS student_status,
                e.enrollment_id, e.status AS enroll_status, e.enrollment_date,
                cs.section_id, cs.section_code, cs.schedule, cs.room,
                c.course_code, c.course_name, c.credits,
                sem.semester_name, sem.academic_year
         FROM Enrollment e
         JOIN Student s ON s.student_id = e.student_id
         JOIN ClassSection cs ON cs.section_id = e.section_id
         JOIN Course c ON c.course_id = cs.course_id
         JOIN Semester sem ON sem.semester_id = cs.semester_id
         WHERE s.student_id = :sid AND cs.lecturer_id = :lid'
        . ($secId > 0 ? ' AND cs.section_id = :sec' : '') .
        ' ORDER BY sem.start_date DESC LIMIT 1'
    );
    $params = [':sid' => $sid, ':lid' => $lecId];
    if ($secId > 0) $params[':sec'] = $secId;
    $q->execute($params);
    $detail = $q->fetch();

    header('Content-Type: application/json');
    if (!$detail) { echo json_encode([]); exit; }

    $detail['attendance'] = AttendanceService::studentSectionStats(
        $pdo, (int)$detail['enrollment_id'], (int)$detail['section_id']
    );

    $asm = $pdo->prepare(
        "SELECT a.assignment_id, a.title, a.due_date, a.max_score, a.status,
                sub.submission_id, sub.submitted_at, sub.score
         FROM Assignment a
         LEFT JOIN Submission sub ON sub.assignment_id = a.assignment_id
                                  AND sub.enrollment_id = :eid
         WHERE a.section_id = :sec AND a.is_deleted = 0 AND a.status <> 'Draft'
         ORDER BY a.due_date ASC"
    );
    $asm->execute([':eid' => $detail['enrollment_id'], ':sec' => $detail['section_id']]);
    $detail['assignments'] = $asm->fetchAll();

    $wg = $pdo->prepare(
        "SELECT warning_type, message, created_at FROM AcademicWarning
         WHERE student_id = :sid AND status = 'Active'
         ORDER BY created_at DESC LIMIT 5"
    );
    $wg->execute([':sid' => $sid]);
    $detail['warnings'] = $wg->fetchAll();
    $detail['warn_count'] = count($detail['warnings']);
    $detail['assignment_total'] = count($detail['assignments']);
    $detail['submitted_total']  = count(array_filter($detail['assignments'], fn($a) => !empty($a['submission_id'])));

    echo json_encode($detail, JSON_UNESCAPED_UNICODE);
    exit;
}

// Warning students for right panel
$warnings = $selSection ? array_filter(
    LecturerModel::getWarningsForLecturer($lecId, 10),
    fn($w) => ($w['section_code'] ?? '') === ($selSectionInfo['section_code'] ?? '')
) : [];

$pageTitle  = 'Lớp học & Sinh viên';
$activePage = 'classes';
require __DIR__ . '/_layout.php';

function initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1));
    return substr($r,-2);
}
?>

<!-- ═══════════ COURSE CARDS TOP ROW ════════════════════════ -->
<div class="lec-course-cards">
    <?php foreach ($sections as $sec):
        $isActive = (int)$sec['section_id'] === $selSection;
        $wc = (int)$sec['warning_count'];
        $pdo2 = get_db();
        $subStmt = $pdo2->prepare(
            "SELECT
                (SELECT COUNT(*) FROM Assignment a
                 WHERE a.section_id=:sid1 AND a.is_deleted=0 AND a.status<>'Draft') AS asg_total,
                (SELECT COUNT(*) FROM Submission sub
                 JOIN Assignment a2 ON a2.assignment_id=sub.assignment_id
                 WHERE a2.section_id=:sid2 AND a2.is_deleted=0 AND a2.status<>'Draft'
                   AND sub.submitted_at IS NOT NULL) AS sub_total"
        );
        $subStmt->execute([':sid1' => $sec['section_id'], ':sid2' => $sec['section_id']]);
        $subRow = $subStmt->fetch();
        $asgTotal = (int)($subRow['asg_total'] ?? 0);
        $subTotal = (int)($subRow['sub_total'] ?? 0);
        $expected = $asgTotal * max(1, (int)$sec['enrolled_count']);
        $submitPct = $expected > 0 ? min(100, round($subTotal / $expected * 100)) : 0;
        // attendance rate + eligibility distribution (official sessions only)
        $secSum = ((int)$sec['section_id'] === $selSection && !empty($sectionAtt))
            ? $sectionAtt
            : AttendanceService::sectionSummary(get_db(), (int)$sec['section_id']);
        $attRate  = $secSum['attendance_rate'];
        $riskCount = (int)$secSum['at_risk'] + (int)($secSum['at_threshold'] ?? 0) + (int)$secSum['ineligible'];
    ?>
    <a href="?section=<?= (int)$sec['section_id'] ?>" class="lec-course-card <?= $isActive ? 'active' : '' ?>"
       style="text-decoration:none;display:block;">
        <div class="lec-course-card__badge <?= $sec['sem_status']==='Active' ? 'lec-course-card__badge--active' : 'lec-course-card__badge--teaching' ?>">
            <?= $sec['sem_status']==='Active' ? '● Đang giảng dạy' : '● Học kỳ đã kết thúc' ?>
        </div>
        <div class="lec-course-card__code"><?= htmlspecialchars($sec['section_code']) ?></div>
        <div class="lec-course-card__name"><?= htmlspecialchars($sec['course_name']) ?></div>
        <div class="lec-course-card__stats">
            <div class="lec-course-card__stat">
                <span class="lec-course-card__stat-val"><?= (int)$sec['enrolled_count'] ?></span>
                <span class="lec-course-card__stat-lbl">Sĩ số</span>
            </div>
            <div class="lec-course-card__stat">
                <span class="lec-course-card__stat-val"><?= $attRate !== null ? $attRate.'%' : '—' ?></span>
                <span class="lec-course-card__stat-lbl">Chuyên cần</span>
            </div>
        </div>
        <div class="lec-course-card__progress">
            <div class="lec-course-card__progress-bar">
                <div class="lec-course-card__progress-fill" style="width:<?= $submitPct ?>%"></div>
            </div>
            <div class="lec-course-card__progress-label">Nộp bài: <?= $submitPct ?>%</div>
        </div>
        <div class="lec-course-card__footer">
            <?php if ($wc > 0): ?>
            <span class="lec-course-card__warn">⚠️ <?= $wc ?> SV cảnh báo học vụ</span>
            <?php endif; ?>
            <?php if ($riskCount > 0): ?>
            <span class="lec-course-card__warn" style="color:#b91c1c;">🚫 <?= $riskCount ?> SV nguy cơ/không đủ ĐK</span>
            <?php endif; ?>
            <span class="lec-course-card__action">Chuyển sang lớp này →</span>
        </div>
    </a>
    <?php endforeach; ?>
    <?php if (empty($sections)): ?>
    <div class="lcard" style="padding:24px;grid-column:1/-1;">
        <div class="lec-empty"><div class="lec-empty__icon">📚</div><p>Chưa có lớp học phần nào trong học kỳ này.</p></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($selSectionInfo): ?>

<!-- ═══════════ SECTION BANNER ════════════════════════════ -->
<div class="lec-section-banner">
    <div class="lec-section-banner__info">
        <span class="lec-section-banner__code"><?= htmlspecialchars($selSectionInfo['section_code']) ?> • <?= (int)$selSectionInfo['credits'] ?> TC</span>
        <span class="lec-section-banner__name"><?= htmlspecialchars($selSectionInfo['course_name']) ?></span>
        <div class="lec-section-banner__meta">
            <span>🕐 <?= htmlspecialchars($selSectionInfo['schedule'] ?? 'TBA') ?></span>
            <span>📍 <?= htmlspecialchars($selSectionInfo['room'] ?? 'TBA') ?></span>
            <span>📊 Tiến độ nộp bài: <?= (int)$selSectionInfo['enrolled_count'] ?> SV</span>
        </div>
    </div>
    <div class="lec-section-banner__actions">
        <a href="assignments.php?section=<?= (int)$selSection ?>" class="lbtn lbtn--yellow lbtn--sm">📋 Tạo bài tập</a>
        <a href="grading.php?section=<?= (int)$selSection ?>" class="lbtn lbtn--outline lbtn--sm" style="color:#fff;border-color:rgba(255,255,255,.3);">✅ Điểm danh lớp</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;">

<!-- STUDENT TABLE -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <!-- Toolbar -->
    <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="section" value="<?= (int)$selSection ?>" />
            <div style="position:relative;">
                <img src="../img/icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;height:13px;opacity:.4;" />
                <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Tìm MSSV, tên sinh viên, email..."
                       style="width:280px;height:36px;background:#f1f5f9;border:none;border-radius:8px;padding:0 12px 0 32px;font-size:13px;outline:none;" />
            </div>
            <select name="status" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                <option value="">Trạng thái: Tất cả</option>
                <option value="ok"   <?= $statusF==='ok'?'selected':'' ?>>An toàn</option>
                <option value="warn" <?= $statusF==='warn'?'selected':'' ?>>Theo dõi</option>
                <option value="crit" <?= $statusF==='crit'?'selected':'' ?>>Nguy cơ cao</option>
            </select>
            <button type="submit" class="lbtn lbtn--primary lbtn--sm">Lọc</button>
        </form>
        <div style="font-size:13px;color:#64748b;">
            Hiển thị <?= count($students) ?> / <?= (int)($selSectionInfo['enrolled_count'] ?? 0) ?> sinh viên
        </div>
    </div>

    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th style="width:40px;text-align:center;">STT</th>
                    <th>Học viên & MSSV</th>
                    <th>Chuyên cần</th>
                    <th>Bài tập thiếu</th>
                    <th>Trạng thái học vụ</th>
                    <th>Tác vụ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không tìm thấy sinh viên nào.
                </td></tr>
                <?php else: ?>
                <?php foreach ($students as $i => $st):
                    $ini = initials($st['full_name']);
                    $stat = $attByEnrollment[(int)$st['enrollment_id']] ?? null;
                    $official = (int)($stat['official_total'] ?? 0);
                    $counted  = (int)($stat['absent_counted'] ?? 0);
                    $absPct2  = (float)($stat['absent_pct'] ?? 0);
                    $pct      = $official > 0 ? round(100 - $absPct2, 1) : 0;
                    $stStatus = $stat['status'] ?? 'no_data';
                    $stAtThreshold = (int)($stat['risk_level'] ?? 0) === 2;
                    $barCls   = $stStatus === 'ineligible' ? 'crit'
                              : ($stAtThreshold ? 'warn' : ($stStatus === 'at_risk' ? 'warn' : ($stStatus === 'eligible' ? 'ok' : 'crit')));
                    $pctCls   = $barCls;
                    if ($stStatus === 'ineligible') {
                        $risk = 'Không đủ ĐK dự thi'; $riskLpill = 'lpill--red';
                    } elseif ($stAtThreshold) {
                        $risk = 'Chạm ngưỡng cấm thi'; $riskLpill = 'lpill--red';
                    } elseif ($stStatus === 'at_risk') {
                        $risk = 'Nguy cơ mất ĐK'; $riskLpill = 'lpill--orange';
                    } elseif ($stStatus === 'no_data') {
                        $risk = 'Chưa có buổi'; $riskLpill = 'lpill--gray';
                    } else {
                        $risk = 'Đủ điều kiện'; $riskLpill = 'lpill--green';
                    }
                    $missing = max(0, (int)($st['assignment_total'] ?? 0) - (int)($st['submitted_total'] ?? 0));
                ?>
                <tr>
                    <td style="text-align:center;font-size:12px;color:#94a3b8;"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="lec-av"><?= htmlspecialchars($ini) ?></span>
                            <div>
                                <div style="font-weight:700;color:#001634;font-size:13px;"><?= htmlspecialchars($st['full_name']) ?></div>
                                <div style="font-size:11px;color:#64748b;">
                                    <?= htmlspecialchars($st['student_id']) ?> • <?= htmlspecialchars($st['class_code']) ?>
                                </div>
                                <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($st['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="att-bar-wrap">
                            <div class="att-bar">
                                <div class="att-bar__fill att-bar__fill--<?= $barCls ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                            <span class="att-pct att-pct--<?= $pctCls ?>"><?= $official > 0 ? $pct.'%' : '—' ?></span>
                        </div>
                        <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Vắng <?= $counted ?>/<?= $official ?> buổi chính thức</div>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($missing > 0): ?>
                        <span class="lpill lpill--red"><?= $missing ?> bài</span>
                        <?php else: ?>
                        <span class="lpill lpill--green">Đủ bài</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="lpill <?= $riskLpill ?>"><?= $risk ?></span>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <button type="button" onclick="openStudentDetail('<?= htmlspecialchars($st['student_id']) ?>', <?= (int)$selSection ?>)"
                                    class="lbtn lbtn--outline lbtn--sm" title="Chi tiết học vụ">👁</button>
                            <a href="grading.php?section=<?= (int)$selSection ?>"
                               class="lbtn lbtn--outline lbtn--sm" title="Điểm danh">📋</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Summary -->
    <div style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;">
        <span style="font-size:12px;color:#64748b;">
            Hiển thị <?= count($students) ?> trên tổng số <?= (int)$selSectionInfo['enrolled_count'] ?> sinh viên của lớp
            <?= ($search||$statusF) ? ' (đang lọc)' : '' ?>
        </span>
    </div>
</div>

<!-- RIGHT PANEL: Warning profile -->
<div class="lcard" style="padding:20px;">
    <div style="font-size:15px;font-weight:700;color:#001634;margin-bottom:14px;">
        🔴 Hồ sơ cảnh báo học vụ
        <?php if (!empty($warnings)): ?>
        <span class="lpill lpill--red" style="margin-left:6px;font-size:11px;">Cần thi</span>
        <?php endif; ?>
    </div>
    <?php if (empty($warnings)): ?>
    <div class="lec-empty"><div class="lec-empty__icon">✅</div><p>Không có sinh viên nào cần can thiệp.</p></div>
    <?php else: ?>
    <?php
    // Show first warning student with full details
    $fw = array_values($warnings)[0];
    $ini2 = initials($fw['full_name']);
    $wType = ['Attendance'=>'🔴 Vắng học','LowGPA'=>'📉 GPA thấp','LowGrade'=>'📝 Điểm thấp','GraduationRisk'=>'🎓 Nguy cơ tốt nghiệp'];
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#fff5f5;border-radius:8px;margin-bottom:12px;">
        <div class="lec-av" style="width:44px;height:44px;font-size:15px;"><?= htmlspecialchars($ini2) ?></div>
        <div>
            <div style="font-weight:700;color:#001634;"><?= htmlspecialchars($fw['full_name']) ?></div>
            <div style="font-size:12px;color:#64748b;">MSSV: <?= htmlspecialchars($fw['student_id']) ?> • Lớp <?= htmlspecialchars($fw['class_code']) ?></div>
        </div>
    </div>

    <?php foreach (array_values($warnings) as $w):
        $wl = $wType[$w['warning_type']] ?? '⚠️ Cảnh báo';
    ?>
    <div style="border:1px solid #fecaca;background:#fff5f5;border-radius:8px;padding:12px;margin-bottom:8px;">
        <div style="font-size:12px;font-weight:700;color:#b91c1c;margin-bottom:4px;"><?= $wl ?></div>
        <div style="font-size:12px;color:#44474e;line-height:16px;">
            <?= htmlspecialchars(mb_strimwidth($w['message'],0,120,'…')) ?>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
            <?= date('d/m/Y H:i', strtotime($w['created_at'])) ?>
        </div>
        <div style="display:flex;gap:6px;margin-top:8px;">
            <a href="warnings.php?section=<?= (int)$selSection ?>" class="lbtn lbtn--red lbtn--sm">Ghi nhận can thiệp</a>
        </div>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:8px;">
        <a href="warnings.php?section=<?= (int)$selSection ?>" class="lbtn lbtn--outline" style="width:100%;justify-content:center;font-size:12px;">
            Xem toàn bộ cảnh báo →
        </a>
    </div>
    <?php endif; ?>
</div>

</div><!-- end 2-col grid -->
<?php endif; ?>

<!-- ═══════════ MODAL: STUDENT ACADEMIC DETAIL ════════════════ -->
<div id="modalStudent" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:780px;max-height:92vh;overflow-y:auto;padding:28px;position:relative;">
    <button type="button" onclick="closeStudentDetail()" style="position:absolute;top:14px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
    <h2 style="font-size:17px;font-weight:800;color:#001634;margin-bottom:14px;">👁 Hồ sơ học tập sinh viên</h2>
    <div id="sdBody"><div style="text-align:center;padding:32px;color:#94a3b8;">Đang tải...</div></div>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];});}
function fmtDate(v){if(!v)return '—';var d=new Date(String(v).replace(' ','T'));if(isNaN(d))return esc(v);return ('0'+d.getDate()).slice(-2)+'/'+('0'+(d.getMonth()+1)).slice(-2)+'/'+d.getFullYear();}
function openStudentDetail(sid, sectionId){
    document.getElementById('sdBody').innerHTML = '<div style="text-align:center;padding:32px;color:#94a3b8;">Đang tải...</div>';
    document.getElementById('modalStudent').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    fetch('classes.php?student='+encodeURIComponent(sid)+'&section='+sectionId)
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d || !d.student_id){document.getElementById('sdBody').innerHTML='<p style="color:#94a3b8;">Không tìm thấy sinh viên trong lớp bạn phụ trách.</p>';return;}
            var a = d.attendance || {};
            var official = parseInt(a.official_total,10)||0;
            var completed = parseInt(a.completed_total,10)||0;
            var present = parseInt(a.present,10)||0, late = parseInt(a.late,10)||0,
                absent = parseInt(a.absent_counted,10)||0, excuse = parseInt(a.excused,10)||0,
                rawAbsent = parseInt(a.absent,10)||0;
            var rate = official>0 ? Math.round((official-absent)/official*1000)/10 : 0;
            var eligColor = a.status==='ineligible' ? '#b91c1c'
                          : ((a.at_threshold || a.risk_level===2) ? '#b45309'
                          : (a.status==='at_risk' ? '#b45309' : '#166534'));
            var html = '<div style="display:flex;align-items:center;gap:12px;padding:12px;background:#f8fafc;border-radius:10px;margin-bottom:14px;">'
                + '<span class="lec-av" style="width:44px;height:44px;font-size:15px;">'+esc((d.full_name||'').trim().split(/\s+/).map(function(w){return w[0]||'';}).join('').slice(-2).toUpperCase())+'</span>'
                + '<div><div style="font-weight:800;color:#001634;font-size:15px;">'+esc(d.full_name)+'</div>'
                + '<div style="font-size:12px;color:#64748b;">MSSV '+esc(d.student_id)+' • Lớp '+esc(d.class_code)+' • '+esc(d.email)+'</div>'
                + '<div style="font-size:11px;color:#94a3b8;">'+esc(d.course_code+' – '+d.course_name)+' • '+esc(d.section_code)+' • '+esc(d.semester_name+' '+d.academic_year)+'</div></div></div>';

            html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px;">'
                + '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px;text-align:center;"><div style="font-size:10px;color:#166534;">Có mặt</div><div style="font-size:18px;font-weight:800;color:#166534;">'+present+'</div></div>'
                + '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px;text-align:center;"><div style="font-size:10px;color:#92400e;">Đi muộn</div><div style="font-size:18px;font-weight:800;color:#92400e;">'+late+'</div></div>'
                + '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px;text-align:center;"><div style="font-size:10px;color:#b91c1c;">Vắng (tính)</div><div style="font-size:18px;font-weight:800;color:#b91c1c;">'+absent+'</div></div>'
                + '<div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:8px;text-align:center;"><div style="font-size:10px;color:#5b21b6;">Có phép</div><div style="font-size:18px;font-weight:800;color:#5b21b6;">'+excuse+'</div></div>'
                + '</div>';
            html += '<div style="font-size:12px;color:#64748b;margin-bottom:6px;">Tỷ lệ chuyên cần: <strong style="color:#001634;">'+rate+'%</strong> • Vắng <strong>'+absent+'/'+official+'</strong> buổi chính thức ('+(a.absent_pct||0)+'%) • Đã hoàn thành '+completed+' buổi • Trạng thái ĐK: <strong style="color:'+eligColor+';">'+esc(a.status_label||'—')+'</strong>'+(rawAbsent!==absent?' • gồm '+rawAbsent+' vắng không phép':'')+'</div>';
            html += '<div style="font-size:11px;color:#94a3b8;margin-bottom:14px;">'+esc(a.explanation||'')+' • Trạng thái đăng ký: <strong>'+esc(d.enroll_status)+'</strong>'+(d.enrollment_date?' • Ngày ĐK: '+fmtDate(d.enrollment_date):'')+'</div>';

            html += '<div style="font-size:13px;font-weight:700;color:#001634;margin-bottom:6px;">Bài tập ('+d.submitted_total+'/'+d.assignment_total+' đã nộp)</div>';
            if(!d.assignments || !d.assignments.length){
                html += '<p style="font-size:12px;color:#94a3b8;font-style:italic;">Lớp chưa có bài tập nào được công bố.</p>';
            } else {
                html += '<div class="lec-table-wrap" style="max-height:240px;overflow:auto;"><table class="lec-table"><thead><tr><th>BÀI TẬP</th><th>HẠN NỘP</th><th>TRẠNG THÁI</th><th style="text-align:center;">ĐIỂM</th></tr></thead><tbody>';
                d.assignments.forEach(function(asg){
                    var st, cls;
                    var late = asg.submitted_at && asg.due_date && new Date(String(asg.submitted_at).replace(' ','T')) > new Date(String(asg.due_date).replace(' ','T'));
                    if(!asg.submission_id){ st='Chưa nộp'; cls='lpill--gray'; }
                    else if(asg.score!=null){ st='Đã chấm'; cls='lpill--green'; }
                    else if(late){ st='Nộp muộn'; cls='lpill--orange'; }
                    else { st='Đã nộp'; cls='lpill--blue'; }
                    html += '<tr><td style="font-size:12px;font-weight:600;color:#001634;">'+esc(asg.title)+'</td>'
                         + '<td style="font-size:11px;color:#64748b;">'+fmtDate(asg.due_date)+'</td>'
                         + '<td><span class="lpill '+cls+'" style="font-size:10px;">'+st+'</span></td>'
                         + '<td style="text-align:center;font-weight:700;">'+(asg.score!=null?esc(asg.score)+'/'+esc(asg.max_score):'—')+'</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            if(d.warn_count > 0){
                html += '<div style="font-size:13px;font-weight:700;color:#b91c1c;margin:14px 0 6px;">Cảnh báo học vụ ('+d.warn_count+')</div>';
                d.warnings.forEach(function(w){
                    html += '<div style="border:1px solid #fecaca;background:#fff5f5;border-radius:8px;padding:8px 10px;margin-bottom:6px;font-size:12px;color:#7f1d1d;">'
                         + esc(w.message)+' <span style="color:#94a3b8;">('+fmtDate(w.created_at)+')</span></div>';
                });
            }
            document.getElementById('sdBody').innerHTML = html;
        })
        .catch(function(){document.getElementById('sdBody').innerHTML='<p style="color:#ef4444;">Lỗi tải dữ liệu.</p>';});
}
function closeStudentDetail(){document.getElementById('modalStudent').style.display='none';document.body.style.overflow='';}
document.getElementById('modalStudent').addEventListener('click', function(e){ if(e.target===this) closeStudentDetail(); });
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeStudentDetail(); });
</script>
