<?php
/**
 * UniPortal – Quản lý Điểm danh (Admin)
 * Figma: att screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/AttendanceService.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: ' . app_url('assets/student/dashboard.php')); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: ' . app_url('assets/lecturer/dashboard.php')); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

$pdo = get_db();

// Official-session based monitoring (shared calculation with student/lecturer).
$policy      = AttendanceService::policy();
$currentSemRow = $pdo->query("SELECT semester_id,semester_name,academic_year FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1")->fetch();
$semId       = (int)($currentSemRow['semester_id'] ?? 0);
$overview    = $semId ? AttendanceService::semesterOverview($pdo, $semId) : ['sections'=>[], 'students'=>[], 'stats'=>['sections'=>0,'official'=>0,'completed'=>0,'students'=>0,'at_risk'=>0,'at_threshold'=>0,'ineligible'=>0,'absent_total'=>0]];
$overviewStats = $overview['stats'];
$allStudents   = $overview['students'];

$totalSV      = (int)$overviewStats['students'];
$absentCnt    = (int)$overviewStats['absent_total'];
$ineligibleCnt= (int)$overviewStats['ineligible'];
$atRiskCnt    = (int)$overviewStats['at_risk'] + (int)($overviewStats['at_threshold'] ?? 0);

// Overall attendance rate = attended records / available completed sessions
$attendedSum = 0; $completedSum = 0;
foreach ($allStudents as $st) {
    $attendedSum  += (int)$st['present'] + (int)$st['late'] + (int)$st['excused'];
    $completedSum += (int)$st['completed_total'];
}
$attRate = $completedSum > 0 ? round($attendedSum / $completedSum * 100, 1) : null;

// Students at/above the warning threshold (exceeding / approaching)
$flaggedRows = AttendanceService::flaggedStudents($allStudents);
$lowAttRows  = array_slice($flaggedRows, 0, 20);

// Section-level distribution (real official-session data)
$sectionRows = [];
foreach ($overview['sections'] as $sr) {
    $den = (int)$sr['completed'] * max(1, (int)$sr['enrolled']);
    $sr['att_rate'] = $den > 0 ? round((int)$sr['attended_total'] / $den * 100, 1) : null;
    $sectionRows[] = $sr;
}
usort($sectionRows, fn($a, $b) => ($a['att_rate'] ?? 101) <=> ($b['att_rate'] ?? 101));
$sectionRows = array_slice($sectionRows, 0, 10);

// Bar chart data per section
$barLabels = []; $barData = [];
foreach ($sectionRows as $sr) {
    if ($sr['att_rate'] === null) continue;
    $barLabels[] = $sr['section_code'];
    $barData[]   = $sr['att_rate'];
}

// Urgent requests (attendance certificates)
$urgent = $pdo->query(
    "SELECT aw.student_id, s.full_name, aw.message, aw.created_at
     FROM AcademicWarning aw JOIN Student s ON s.student_id=aw.student_id
     WHERE aw.warning_type='Attendance' AND aw.is_read=FALSE AND aw.status='Active'
     LIMIT 2"
)->fetchAll();

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Quản lý điểm danh';
$activePage = 'attendance';
$breadcrumb = 'Quản trị › Học vụ & Sinh viên';
require __DIR__ . '/_layout.php';
?>

<!-- ═══ 4 KPI CARDS ═══════════════════════════════════════ -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tổng số sinh viên theo dõi</span>
            <span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-profile.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= number_format($totalSV) ?></span></div>
        <div class="adm-kpi__sub"><?= htmlspecialchars(($currentSemRow['semester_name'] ?? 'Học kỳ hiện tại').' '.($currentSemRow['academic_year'] ?? '')) ?></div>
        <div class="adm-kpi__sub"><?= (int)$overviewStats['sections'] ?> lớp • <?= (int)$overviewStats['official'] ?> buổi chính thức</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Tỷ lệ chuyên cần trung bình</span>
            <span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-icon-attendance.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $attRate !== null ? number_format($attRate,1).'%' : '—' ?></span></div>
        <div class="adm-kpi__sub"><?= (int)$overviewStats['completed'] ?> lượt buổi đã dạy • <?= $absentCnt ?> lượt vắng</div>
    </div>
    <div class="acard adm-kpi">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Cận ngưỡng cấm thi (≥<?= rtrim(rtrim(number_format($policy['warn_from'],1,'.',''),'0'),'.') ?>%)</span>
            <span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-icon-alert-warn.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $atRiskCnt ?></span><span class="adm-kpi__unit">trường hợp</span></div>
        <div class="adm-kpi__sub">Vắng đúng <?= rtrim(rtrim(number_format($policy['ban_pct'],1,'.',''),'0'),'.') ?>% vẫn đủ điều kiện dự thi</div>
    </div>
    <div class="acard adm-kpi" style="border-left:4px solid #ef4444;">
        <div class="adm-kpi__header">
            <span class="adm-kpi__label">Vượt ngưỡng cấm thi (&gt;<?= rtrim(rtrim(number_format($policy['ban_pct'],1,'.',''),'0'),'.') ?>%)</span>
            <span class="adm-kpi__icon adm-kpi__icon--red"><img src="../img/db-icon-alert-warn.svg" alt="" /></span>
        </div>
        <div class="adm-kpi__value"><span class="adm-kpi__num adm-kpi__num--red"><?= $ineligibleCnt ?></span><span class="adm-kpi__unit">trường hợp</span></div>
        <div class="adm-kpi__sub" style="color:#ef4444;">Không đủ điều kiện dự thi • cảnh báo tự động đã ghi nhận</div>
    </div>
</div>

<!-- ═══ ROW 2: BAR CHART + URGENT PANEL ══════════════════ -->
<div style="display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start;">

    <div class="acard" style="padding:20px 24px;">
        <div class="adm-section-title" style="margin-bottom:4px;">Phân bố tỷ lệ chuyên cần theo lớp học phần</div>
        <div class="adm-section-sub" style="margin-bottom:16px;">
            Dựa trên <?= (int)$overviewStats['official'] ?> buổi chính thức của <?= (int)$overviewStats['sections'] ?> lớp
            • Tỷ lệ vắng tính trên tổng buổi chính thức, không tính buổi hủy/tương lai/chưa điểm danh
        </div>
        <div style="position:relative;height:200px;">
            <canvas id="attBarChart"></canvas>
        </div>
    </div>

    <div class="acard" style="padding:16px;">
        <div style="font-size:14px;font-weight:700;color:#0d1b2e;margin-bottom:10px;">
            ⚡ Xử lý chuyên cần
            <?php if (!empty($urgent)): ?>
            <span class="apill apill--orange" style="font-size:10px;margin-left:4px;"><?= count($urgent) ?> đang chờ</span>
            <?php endif; ?>
        </div>
        <?php if (empty($urgent)): ?>
        <div class="adm-empty"><p>Không có yêu cầu xử lý khẩn.</p></div>
        <?php else: ?>
        <?php foreach ($urgent as $u): ?>
        <div style="padding:10px;border:1px solid #fecaca;background:#fff5f5;border-radius:8px;margin-bottom:8px;">
            <div style="font-size:12px;font-weight:700;color:#0d1b2e;">
                <?= htmlspecialchars($u['full_name']) ?>
                <span style="font-size:10px;color:#64748b;">(<?= htmlspecialchars($u['student_id']) ?>)</span>
            </div>
            <div style="font-size:11px;color:#64748b;margin:3px 0;">Minh chứng giấy khám bệnh – <?= date('d/m/Y', strtotime($u['created_at'])) ?></div>
            <div style="font-size:11px;color:#64748b;">Gửi lúc: <?= date('H:i d/m/Y', strtotime($u['created_at'])) ?></div>
            <div style="display:flex;gap:5px;margin-top:6px;">
                <a href="auditlog.php" class="abtn abtn--green abtn--sm" style="font-size:11px;padding:4px 10px;">Duyệt</a>
                <a href="#" class="abtn abtn--outline abtn--sm" style="font-size:11px;padding:4px 10px;">Từ chối</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <a href="students.php" style="display:block;text-align:center;margin-top:8px;font-size:12px;font-weight:600;color:#1a3a6b;">Toàn bộ yêu cầu →</a>
    </div>
</div>

<!-- ═══ ATTENDANCE DETAIL TABLE ════════════════════════════ -->
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-tabs" style="padding:0 20px;">
        <a href="#" class="adm-tab active">Tổng quan chuyên cần</a>
        <a href="#" class="adm-tab">Dữ liệu điểm danh chi tiết</a>
        <a href="#" class="adm-tab" style="color:#ef4444;">Sinh viên cần theo dõi (Nguy cơ cao) ●</a>
        <a href="#" class="adm-tab">Lịch sử điều chỉnh điểm danh</a>
    </div>

    <!-- Filter row -->
    <div style="padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid #f1f5f9;">
        <div style="position:relative;">
            <img src="../img/icon-search.svg" alt="" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);width:12px;opacity:.4;" />
            <input type="search" placeholder="Tìm tên MSSV, Họ và tên..." style="width:200px;height:32px;background:#f1f5f9;border:none;border-radius:6px;padding:0 10px 0 26px;font-size:12px;outline:none;" />
        </div>
        <select style="height:32px;padding:0 8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
            <option>Tất cả học phần</option>
            <?php foreach ($sectionRows as $sr): ?>
            <option><?= htmlspecialchars($sr['section_code']) ?></option>
            <?php endforeach; ?>
        </select>
        <select style="height:32px;padding:0 8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
            <option>Tất cả trạng thái</option>
            <option>Bình thường</option>
            <option>Theo dõi</option>
            <option>Nguy cơ</option>
        </select>
        <select style="height:32px;padding:0 8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
            <option>Tất cả mức chuyên cần</option>
            <option>&gt;90% – Tốt</option>
            <option>80-89% – Đạt</option>
            <option>&lt;80% – Cần theo dõi</option>
        </select>
    </div>

    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>MSSV</th>
                    <th>HỌ VÀ TÊN</th>
                    <th>LỚP HỌC PHẦN</th>
                    <th style="text-align:center;">TỔNG CHÍNH THỨC</th>
                    <th style="text-align:center;">HOÀN THÀNH</th>
                    <th style="text-align:center;">VẮNG (TÍNH)</th>
                    <th>TỶ LỆ VẮNG</th>
                    <th style="text-align:center;">CÒN ĐƯỢC VẮNG</th>
                    <th>ĐIỀU KIỆN DỰ THI</th>
                    <th>THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lowAttRows)): ?>
                <tr><td colspan="10" style="text-align:center;padding:36px;color:#94a3b8;font-style:italic;">
                    Không có sinh viên nào chạm ngưỡng cảnh báo chuyên cần trong học kỳ này.
                </td></tr>
                <?php else: ?>
                <?php foreach ($lowAttRows as $r):
                    $absPct   = (float)$r['absent_pct'];
                    $official = (int)$r['official_total'];
                    $counted  = (int)$r['absent_counted'];
                    $isIneligible = $r['status'] === 'ineligible';
                    $riskLbl  = $isIneligible ? 'Không đủ ĐK dự thi'
                              : ((int)$r['risk_level'] === 2 ? 'Chạm ngưỡng cấm thi' : 'Nguy cơ mất ĐK');
                    $riskCls  = $isIneligible || (int)$r['risk_level'] === 2 ? 'apill--red' : 'apill--orange';
                ?>
                <tr<?= $isIneligible ? ' style="background:#fff5f5;"' : '' ?>>
                    <td style="font-family:monospace;font-size:12px;color:#3b82f6;"><?= htmlspecialchars($r['student_id']) ?></td>
                    <td style="font-weight:600;color:#0d1b2e;"><?= htmlspecialchars($r['full_name']) ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($r['section_code']) ?><br/><span style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($r['course_name']) ?></span></td>
                    <td style="text-align:center;"><?= $official ?></td>
                    <td style="text-align:center;"><?= (int)$r['completed_total'] ?></td>
                    <td style="text-align:center;font-weight:700;color:#ef4444;"><?= $counted ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <div style="flex:1;height:5px;background:#e2e8f0;border-radius:9999px;overflow:hidden;min-width:60px;">
                                <div style="height:100%;width:<?= min(100,$absPct) ?>%;background:<?= $isIneligible?'#ef4444':'#f59e0b' ?>;border-radius:9999px;"></div>
                            </div>
                            <span style="font-size:12px;font-weight:700;"><?= number_format($absPct,1) ?>%</span>
                        </div>
                    </td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$r['remaining_allowed'] ?></td>
                    <td><span class="apill <?= $riskCls ?>" title="<?= htmlspecialchars($r['explanation']) ?>"><?= $riskLbl ?></span></td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <a href="courses.php" class="abtn abtn--outline abtn--sm" style="font-size:11px;">Xem lớp</a>
                            <?php if ($isIneligible): ?>
                            <a href="auditlog.php" class="abtn abtn--red abtn--sm" style="font-size:11px;">Gửi cảnh báo</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="padding:10px 16px;font-size:12px;color:#64748b;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;">
        <span>Hiển thị <?= count($lowAttRows) ?> trường hợp cần theo dõi trên tổng số <?= number_format($totalSV) ?> lượt đăng ký học phần</span>
        <div class="adm-pagination"><span class="active">1</span></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
Chart.defaults.font.family = "'Inter', 'Be Vietnam Pro', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#64748b';
new Chart(document.getElementById('attBarChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($barLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            data: <?= json_encode($barData) ?>,
            backgroundColor: <?= json_encode(array_map(fn($v) => $v < 80 ? '#ef4444' : ($v < 90 ? '#fdcc35' : '#102b50'), $barData)) ?>,
            borderRadius: 4, barPercentage: 0.6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { min: 0, max: 100, ticks: { callback: v => v+'%', font:{size:10}, color:'#94a3b8' },
                 grid: { color: 'rgba(0,0,0,.04)' } },
            x: { ticks: { font:{size:10}, color:'#94a3b8' }, grid: { display: false } }
        }
    }
});
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
