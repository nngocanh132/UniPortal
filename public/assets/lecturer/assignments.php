<?php
/**
 * UniPortal – Bài tập & Bài nộp / Chấm điểm trực tuyến
 * Figma: ex (assignments) screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId      = (int)($_SESSION['lecturer_id'] ?? 0);
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);
$sections   = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount  = LecturerModel::getUnreadWarningCount($lecId);

// ── Handles grade save POST ───────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    $assessmentId = (int)($_POST['assessment_id'] ?? 0);
    $scores = $_POST['scores'] ?? [];
    $saved = 0;
    foreach ($scores as $enrollmentId => $score) {
        $score = trim($score);
        if ($score === '' || $score === '—') continue;
        $scoreF = (float)$score;
        if ($scoreF < 0 || $scoreF > (float)($_POST['max_score'] ?? 10)) continue;
        $ok = LecturerModel::saveGrade((int)$enrollmentId, $assessmentId, $scoreF, $lecId, $_POST['feedback'][$enrollmentId] ?? '');
        if ($ok) $saved++;
    }
    $flash = $saved > 0 ? "success:Đã lưu $saved điểm thành công." : 'error:Không có điểm nào được lưu.';
}

// ── Selected section & assessment ────────────────────────────
$selSection    = (int)($_GET['section'] ?? ($sections[0]['section_id'] ?? 0));
$selAssessment = (int)($_GET['assessment'] ?? 0);

$selSectionInfo = null;
foreach ($sections as $s) {
    if ((int)$s['section_id'] === $selSection) { $selSectionInfo = $s; break; }
}

$assessments = $selSection ? LecturerModel::getAssessments($selSection) : [];
if (!$selAssessment && !empty($assessments)) {
    $selAssessment = (int)$assessments[0]['assessment_id'];
}

$selAssessmentInfo = null;
$grades = [];
if ($selAssessment && $selSection) {
    foreach ($assessments as $a) {
        if ((int)$a['assessment_id'] === $selAssessment) { $selAssessmentInfo = $a; break; }
    }
    $grades = LecturerModel::getGradesForAssessment($selAssessment, $selSection);
}

// Stats
$pendingStats = LecturerModel::getPendingGradingStats($lecId);

$pageTitle  = 'Bài tập & Bài nộp';
$activePage = 'assignments';
require __DIR__ . '/_layout.php';

function initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1));
    return substr($r,-2);
}
?>

<!-- ═══════════ TAB FILTER + TOP STATS ═══════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="font-size:20px;font-weight:800;color:#001634;">Quản Lý Bài Tập & Chấm Điểm Trực Tuyến</h1>
    </div>
    <a href="#" class="lbtn lbtn--primary">+ Tạo Bài Tập Mới</a>
</div>

<?php if ($flash): [$ftype, $fmsg] = explode(':', $flash, 2); ?>
<div class="lec-flash lec-flash--<?= $ftype === 'success' ? 'success' : 'error' ?>">
    <?= $ftype === 'success' ? '✅' : '⚠️' ?> <?= htmlspecialchars($fmsg) ?>
</div>
<?php endif; ?>

<!-- Status tabs -->
<div class="lec-tabs">
    <button class="lec-tab active">Tất cả bài tập <?= count($assessments) ?></button>
    <button class="lec-tab">Bản nháp 1</button>
    <button class="lec-tab">Đang mở 3</button>
    <button class="lec-tab active yellow">Chờ chấm điểm <?= (int)($pendingStats['pending_grades'] ?? 0) ?></button>
    <button class="lec-tab">Đã chấm &amp; Công bố 2</button>
    <button class="lec-tab">Đã đóng 1</button>
</div>

<!-- 4 stat mini cards -->
<div class="lec-kpi-row">
    <?php
    $totalAssess = 0;
    $totalEnrolled = 0;
    $totalGraded = 0;
    foreach ($assessments as $a) {
        $totalAssess++;
        $totalEnrolled += (int)$a['enrolled_count'];
        $totalGraded   += (int)$a['graded_count'];
    }
    $submitRate = $totalEnrolled > 0 ? round($totalGraded/$totalEnrolled*100,1) : 0;
    ?>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Tổng bài tập trong kỳ</span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= str_pad($totalAssess,'2','0',STR_PAD_LEFT) ?></span></div>
        <div class="lec-kpi__sub">Gồm <?= $totalAssess ?> bài tập cá nhân</div>
        <div class="lec-kpi__sub"><a href="#">Phù hợp khung đề cương</a></div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Tỷ lệ nộp bài đúng hạn</span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= $submitRate ?>%</span></div>
        <div class="lec-kpi__sub">Đúng hạn <?= $submitRate ?>% • Trễ hạn <?= max(0,100-$submitRate) ?>%</div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Bài nộp chờ chấm</span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num" style="color:#f59e0b;"><?= (int)($pendingStats['pending_grades'] ?? 0) ?></span></div>
        <div class="lec-kpi__sub">Đồ án giữa kỳ • <strong>INS3065</strong></div>
        <div class="lec-kpi__sub" style="color:#ef4444;">Còn hạn hoàn thành trước 02/12/2024</div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Điểm trung bình khóa học</span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num">8.2</span><span class="lec-kpi__unit">/ 10</span></div>
        <div class="lec-kpi__sub">Đã lệch chuẩn: 0.64 (Phổ điểm tốt)</div>
        <div class="lec-kpi__sub">↑ +0.4 so với cùng kỳ năm trước</div>
    </div>
</div>

<!-- ═══════════ SECTION SELECTOR ═════════════════════════════ -->
<?php if (!empty($sections)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Lớp:</span>
    <?php foreach ($sections as $s): ?>
    <a href="?section=<?= (int)$s['section_id'] ?>"
       class="lpill <?= (int)$s['section_id'] === $selSection ? 'lpill--blue' : 'lpill--gray' ?>">
        <?= htmlspecialchars($s['section_code']) ?> • <?= (int)$s['enrolled_count'] ?> SV
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══════════ ASSESSMENT CARD ══════════════════════════════ -->
<?php if ($selSectionInfo && !empty($assessments)): ?>

<?php foreach ($assessments as $a):
    $isSelected  = (int)$a['assessment_id'] === $selAssessment;
    $enrolled    = (int)$a['enrolled_count'];
    $graded      = (int)$a['graded_count'];
    $pct         = $enrolled > 0 ? round($graded/$enrolled*100) : 0;
    $isDone      = $graded >= $enrolled && $enrolled > 0;
    $isOverdue   = strtotime($a['due_date']) < time();
    $barCls      = $isDone ? 'green' : ($pct > 60 ? 'yellow' : 'navy');
?>
<div class="lcard" style="padding:20px 24px;<?= $isSelected ? 'border:2px solid #3b82f6;' : '' ?>">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <div style="width:32px;height:32px;background:#e5eeff;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                <span style="font-size:11px;font-weight:800;color:#1e40af;"><?= htmlspecialchars($a['section_code'] ?? $selSectionInfo['section_code']) ?></span>
            </div>
            <div>
                <div style="font-size:11px;color:#94a3b8;"><?= (int)$enrolled ?> Sinh viên</div>
            </div>
        </div>
        <div>
            <?php if ($isDone): ?>
            <span class="lpill lpill--green">✅ Đã hoàn thành chấm</span>
            <?php elseif ($pct > 0): ?>
            <span class="lpill lpill--yellow">⏳ Còn <?= $enrolled-$graded ?> bài chờ</span>
            <?php else: ?>
            <span class="lpill lpill--orange">📥 Đang tiếp nhận nộp</span>
            <?php endif; ?>
        </div>
    </div>

    <div style="font-size:16px;font-weight:800;color:#001634;margin-bottom:8px;">
        <?= htmlspecialchars($a['assessment_name']) ?>
    </div>

    <div style="display:flex;gap:16px;font-size:12px;color:#64748b;flex-wrap:wrap;margin-bottom:10px;">
        <span>📅 Ngày giao: 10/11/2024 (08:00)</span>
        <span style="color:<?= $isOverdue ? '#ef4444' : '#44474e' ?>;">⏰ Hạn chót: <?= date('H:i, D\g\à\y d/m/Y', strtotime($a['due_date'])) ?></span>
        <span>📊 Quy tắc nộp trễ: Cho phép trễ tối đa 24h (-10%)</span>
    </div>

    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <div class="lec-assign-progress__bar" style="flex:1;">
            <div class="lec-assign-progress__fill lec-assign-progress__fill--<?= $barCls ?>" style="width:<?= $pct ?>%"></div>
        </div>
        <span style="font-size:12px;color:#64748b;white-space:nowrap;">
            Đã chấm: <?= $graded ?> bài (<?= $pct ?>%) • Chờ chấm: <?= $enrolled-$graded ?> SV
        </span>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
        <a href="?section=<?= (int)$selSection ?>&assessment=<?= (int)$a['assessment_id'] ?>"
           class="lbtn lbtn--primary lbtn--sm">→ Vào chấm ngay</a>
        <a href="#" class="lbtn lbtn--outline lbtn--sm">Đóng Nộp & Khóa Sổ</a>
    </div>
</div>
<?php endforeach; ?>

<!-- ═══════════ GRADE ENTRY TABLE (when assessment selected) ═ -->
<?php if ($selAssessmentInfo && !empty($grades)): ?>
<div class="lcard" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <div style="font-size:15px;font-weight:700;color:#001634;"><?= htmlspecialchars($selAssessmentInfo['assessment_name']) ?></div>
            <div style="font-size:12px;color:#64748b;">
                Lớp: <?= htmlspecialchars($selSectionInfo['section_code']) ?> •
                Điểm tối đa: <?= number_format((float)$selAssessmentInfo['max_score'], 0) ?> •
                Trọng số: <?= number_format((float)$selAssessmentInfo['weight_percent'], 0) ?>%
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <div class="lec-toolbar__search" style="flex:none;">
                <img class="si" src="../img/kqht-icon-search.svg" alt="" />
                <input type="search" id="gradeSearch" placeholder="Tìm tên, MSSV..." style="width:200px;" />
            </div>
        </div>
    </div>

    <form method="POST">
        <input type="hidden" name="save_grades" value="1" />
        <input type="hidden" name="assessment_id" value="<?= (int)$selAssessment ?>" />
        <input type="hidden" name="max_score" value="<?= htmlspecialchars($selAssessmentInfo['max_score']) ?>" />

        <div class="lec-table-wrap">
            <table class="lec-table" id="gradeTable">
                <thead>
                    <tr>
                        <th style="width:44px;text-align:center;">STT</th>
                        <th>Sinh viên</th>
                        <th>Trạng thái & Thời gian</th>
                        <th>Tệp đính kèm</th>
                        <th style="width:100px;text-align:center;">Điểm (/10)</th>
                        <th style="width:80px;">Trạng thái chấm</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grades as $i => $g):
                        $ini = initials($g['full_name']);
                        $hasGrade = $g['score'] !== null;
                        $scoreVal = $hasGrade ? number_format((float)$g['score'] / 10, 1) : '';
                    ?>
                    <tr>
                        <td style="text-align:center;font-size:12px;color:#94a3b8;"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="lec-av" style="width:32px;height:32px;"><?= htmlspecialchars($ini) ?></span>
                                <div>
                                    <div style="font-weight:700;font-size:13px;color:#001634;"><?= htmlspecialchars($g['full_name']) ?></div>
                                    <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($g['student_id']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($hasGrade): ?>
                            <span class="lpill lpill--green" style="font-size:10px;">Đúng hạn</span>
                            <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?= date('d/m/Y', strtotime($g['graded_at']??'now')) ?></div>
                            <?php else: ?>
                            <span class="lpill lpill--gray" style="font-size:10px;">Chưa nộp</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:#64748b;">
                            <?= $hasGrade ? '📄 File nộp' : '—' ?>
                        </td>
                        <td style="text-align:center;">
                            <input type="number"
                                   class="lec-score-input"
                                   name="scores[<?= (int)$g['enrollment_id'] ?>]"
                                   value="<?= htmlspecialchars($scoreVal) ?>"
                                   min="0" max="<?= htmlspecialchars($selAssessmentInfo['max_score']) ?>"
                                   step="0.5"
                                   placeholder="—"
                                   style="width:72px;" />
                        </td>
                        <td>
                            <?php if ($hasGrade): ?>
                            <span class="lpill lpill--green" style="font-size:10px;">Đã chấm</span>
                            <?php else: ?>
                            <span class="lpill lpill--gray" style="font-size:10px;">Chưa chấm</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;">
                                <a href="#" class="lbtn lbtn--outline lbtn--sm">👁 Xem bài</a>
                                <?php if ($hasGrade): ?>
                                <a href="#" class="lbtn lbtn--outline lbtn--sm">Xem lại</a>
                                <?php else: ?>
                                <a href="#" class="lbtn lbtn--yellow lbtn--sm">Chấm bài</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:10px;">
            <span style="font-size:12px;color:#64748b;">
                Hiển thị 1 – <?= count($grades) ?> trên tổng số <?= (int)$selAssessmentInfo['enrolled_count'] ?> bài nộp
            </span>
            <div style="display:flex;gap:8px;">
                <button type="submit" name="action" value="draft" class="lbtn lbtn--outline">💾 Lưu Bản Nháp</button>
                <button type="submit" name="action" value="publish" class="lbtn lbtn--primary">📤 Lưu Điểm &amp; Công Bố Cho Sinh Viên</button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
document.getElementById('gradeSearch')?.addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#gradeTable tbody tr').forEach(function(tr) {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
// Score input validation
document.querySelectorAll('.lec-score-input').forEach(function(inp) {
    inp.addEventListener('input', function() {
        var max = parseFloat(this.max);
        var val = parseFloat(this.value);
        if (val > max) { this.classList.add('is-invalid'); }
        else { this.classList.remove('is-invalid'); }
    });
});
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
