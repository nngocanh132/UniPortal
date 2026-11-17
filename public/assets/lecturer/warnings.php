<?php
/**
 * UniPortal – Cảnh báo & Can thiệp (Giảng viên)
 *
 * Access control:
 *  - The signed-in lecturer only sees warnings attached to sections they teach,
 *    plus course-level warnings for students enrolled in one of their sections.
 *  - The class selector only lists sections assigned to the lecturer; any other
 *    ?section value is rejected with HTTP 403.
 *  - Intervention reads/writes are re-validated server-side on every request
 *    (including the JSON detail endpoint and POST handlers).
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';
require_once $root . '/src/src/services/WarningInterventionService.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId     = (int)($_SESSION['lecturer_id'] ?? 0);
$accountId = (int)($_SESSION['account_id'] ?? 0);
$warnCount = LecturerModel::getUnreadWarningCount($lecId);

$sections = WarningInterventionService::getAssignedSections($lecId);
$sectionsById = [];
foreach ($sections as $s) $sectionsById[(int)$s['section_id']] = $s;

// ── Class filter (validated against the lecturer's assignments) ─────────
$requestedSection = isset($_GET['section']) ? (int)$_GET['section'] : null;
$sectionId        = WarningInterventionService::resolveSectionFilter($lecId, $requestedSection);

if ($sectionId === -1) {
    http_response_code(403);
    $pageTitle  = 'Không có quyền truy cập';
    $activePage = 'warnings';
    require __DIR__ . '/_layout.php';
    ?>
    <div class="lcard" style="padding:40px;text-align:center;">
        <div style="font-size:40px;margin-bottom:10px;">🔒</div>
        <div style="font-size:18px;font-weight:800;color:#001634;margin-bottom:6px;">Không có quyền truy cập</div>
        <p style="font-size:13px;color:#64748b;max-width:460px;margin:0 auto 18px;">
            Lớp học phần bạn yêu cầu không thuộc phân công giảng dạy của bạn.
            Mọi cảnh báo và can thiệp chỉ được phép trong các lớp bạn phụ trách.
        </p>
        <a href="warnings.php" class="lbtn lbtn--primary lbtn--sm">Về danh sách lớp của tôi</a>
    </div>
    <?php
    require __DIR__ . '/_layout_end.php';
    exit;
}

$selectedSection = $sectionId > 0 ? ($sectionsById[$sectionId] ?? null) : null;

$typeLabels = [
    'Attendance'     => 'Chuyên cần',
    'LowGrade'       => 'Điểm thấp',
    'LowGPA'         => 'GPA thấp',
    'GraduationRisk' => 'Tốt nghiệp',
];

// Display-only course renaming for this page; the course record itself is untouched.
const WI_COURSE_NAME_RENAMES = ['INS3056' => 'Mô hình hóa và thiết kế các hệ thống thông tin'];
function wi_course_name(string $code, ?string $name): string {
    return WI_COURSE_NAME_RENAMES[$code] ?? (string)$name;
}
function wi_warning_text(string $text): string {
    return str_replace('Enterprise Information Systems', WI_COURSE_NAME_RENAMES['INS3056'], $text);
}

$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'active', 'unread', 'resolved'], true)) $filter = 'all';
$typeF  = (string)($_GET['type'] ?? '');
if (!isset($typeLabels[$typeF])) $typeF = '';
$search = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));

// ── JSON detail endpoint (authorized per request) ───────────────────────
if (isset($_GET['detail'])) {
    header('Content-Type: application/json; charset=utf-8');
    $warning = WarningInterventionService::getWarning($lecId, (int)$_GET['detail'], $sectionId);
    if (!$warning) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cảnh báo không thuộc lớp học phần bạn phụ trách.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $display = $sectionsById[(int)$warning['display_section_id']] ?? null;
    $lines   = array_values(array_filter(array_map('trim', explode("\n", (string)$warning['message'])), fn($l) => $l !== ''));
    $action  = '';
    $details = [];
    foreach ($lines as $line) {
        if (mb_stripos($line, 'Đề xuất:') === 0) { $action = trim(mb_substr($line, 8)); continue; }
        $details[] = $line;
    }
    $level = max(1, min(3, (int)$warning['warning_level']));
    $sev   = [1 => 'Nhắc nhở', 2 => 'Cần chú ý', 3 => 'Nguy cơ cao'][$level];

    $interventions = [];
    foreach (WarningInterventionService::getInterventionsForWarning($lecId, (int)$warning['warning_id']) as $wi) {
        $interventions[] = [
            'id'           => (int)$wi['intervention_id'],
            'action_label' => WarningInterventionService::ACTION_TYPES[$wi['action_type']] ?? $wi['action_type'],
            'status'       => $wi['status'],
            'status_label' => WarningInterventionService::STATUSES[$wi['status']] ?? $wi['status'],
            'note'         => (string)($wi['note'] ?? ''),
            'created_at'   => date('d/m/Y H:i', strtotime($wi['created_at'])),
        ];
    }

    echo json_encode([
        'ok' => true,
        'warning' => [
            'id'              => (int)$warning['warning_id'],
            'type'            => $warning['warning_type'],
            'type_label'      => $typeLabels[$warning['warning_type']] ?? $warning['warning_type'],
            'level'           => $level,
            'severity_label'  => $sev,
            'status'          => $warning['warn_status'],
            'is_read'         => (bool)$warning['is_read'],
            'created_at'      => date('d/m/Y H:i', strtotime($warning['created_at'])),
            'message_details' => array_map('wi_warning_text', $details),
            'action'          => wi_warning_text($action),
            'section_id'      => (int)$warning['display_section_id'],
            'section_code'    => $display['section_code'] ?? '',
            'course_code'     => $display['course_code'] ?? '',
            'course_name'     => wi_course_name($display['course_code'] ?? '', $display['course_name'] ?? ''),
            'semester'        => trim(($display['semester_name'] ?? '') . ' ' . ($display['academic_year'] ?? '')),
            'student'         => [
                'id'         => $warning['student_id'],
                'name'       => $warning['full_name'],
                'class_code' => $warning['class_code'],
                'email'      => (string)($warning['email'] ?? ''),
                'phone'      => (string)($warning['phone'] ?? ''),
            ],
            'interventions'   => $interventions,
        ],
        'action_types' => WarningInterventionService::ACTION_TYPES,
        'statuses'     => WarningInterventionService::STATUSES,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// ── POST handlers (CSRF + authorization, then redirect) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (isset($_POST['record_intervention'])) {
        $res = WarningInterventionService::record($lecId, $accountId, $_POST);
        $_SESSION['lec_warn_flash'] = ['success' => $res['ok'], 'message' => $res['message']];
    } elseif (isset($_POST['update_intervention'])) {
        $res = WarningInterventionService::updateStatus(
            $lecId,
            (int)($_POST['intervention_id'] ?? 0),
            (string)($_POST['status'] ?? '')
        );
        $_SESSION['lec_warn_flash'] = ['success' => $res['ok'], 'message' => $res['message']];
    }

    $qs = [];
    if ($sectionId > 0)    $qs['section'] = $sectionId;
    if ($filter !== 'all') $qs['filter'] = $filter;
    if ($typeF !== '')     $qs['type'] = $typeF;
    if ($search !== '')    $qs['q'] = $search;
    if ($page > 1)         $qs['page'] = $page;
    header('Location: warnings.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

$flash = $_SESSION['lec_warn_flash'] ?? null;
unset($_SESSION['lec_warn_flash']);

// ── Data for the current scope ──────────────────────────────────────────
$perPage = 50;
$offset  = ($page - 1) * $perPage;
$result  = WarningInterventionService::getWarnings(
    $lecId, $sectionId,
    ['status' => $filter, 'type' => $typeF, 'search' => $search],
    $perPage, $offset
);
$totalFiltered = (int)$result['total'];
$totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page   = $totalPages;
    $offset = ($page - 1) * $perPage;
    $result = WarningInterventionService::getWarnings(
        $lecId, $sectionId,
        ['status' => $filter, 'type' => $typeF, 'search' => $search],
        $perPage, $offset
    );
}
$warnings = $result['rows'];
$stats    = WarningInterventionService::getStats($lecId, $sectionId);
$recentInterventions = WarningInterventionService::getInterventions($lecId, $sectionId, 10);

$scopeLabel = $selectedSection
    ? trim($selectedSection['section_code'] . ' · ' . $selectedSection['course_code'] . ' — ' . $selectedSection['semester_name'] . ' ' . $selectedSection['academic_year'])
    : 'Tất cả lớp học phần của tôi (' . count($sections) . ' lớp)';

$pageTitle  = 'Cảnh báo & Can thiệp';
$activePage = 'warnings';
require __DIR__ . '/_layout.php';

function initials(string $n): string {
    $r = '';
    foreach (explode(' ', trim($n)) as $w) $r .= strtoupper(substr($w, 0, 1));
    return substr($r, -2);
}
?>

<!-- ═══════════ CLASS FILTER + SCOPE ═══════════════════════════════ -->
<div class="lcard wi-scope-bar">
    <form method="GET" class="wi-scope-form" id="wiScopeForm">
        <div class="wi-scope-form__field">
            <label for="wiSection">Lớp học phần</label>
            <select name="section" id="wiSection" onchange="this.form.submit()">
                <option value="0"<?= $sectionId === 0 ? ' selected' : '' ?>>Tất cả lớp của tôi</option>
                <?php foreach ($sections as $s): ?>
                <option value="<?= (int)$s['section_id'] ?>"<?= $sectionId === (int)$s['section_id'] ? ' selected' : '' ?>>
                    <?= htmlspecialchars($s['section_code']) ?> · <?= htmlspecialchars($s['course_code']) ?>
                    — <?= htmlspecialchars($s['semester_name'] . ' ' . $s['academic_year']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>" />
        <input type="hidden" name="type" value="<?= htmlspecialchars($typeF) ?>" />
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>" />
        <div class="wi-scope-form__info">
            <span class="wi-scope-chip"><?= htmlspecialchars($scopeLabel) ?></span>
            <?php if (count($sections) === 1): ?>
            <span class="wi-scope-note">Tự động chọn lớp duy nhất được phân công.</span>
            <?php elseif (!$sections): ?>
            <span class="wi-scope-note" style="color:#b91c1c;">Bạn chưa được phân công lớp học phần nào.</span>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($flash): ?>
<div class="lec-flash lec-flash--<?= !empty($flash['success']) ? 'success' : 'error' ?>">
    <?= !empty($flash['success']) ? '✅' : '⚠️' ?> <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

<!-- ═══════════ FILTER TABS ══════════════════════════════════ -->
<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
    <?php
    $filters = [
        'all'      => 'Tất cả (' . (int)$stats['total_warnings'] . ')',
        'active'   => 'Đang hoạt động (' . (int)$stats['active_warnings'] . ')',
        'unread'   => 'Chưa xử lý (' . (int)$stats['unread_warnings'] . ')',
        'resolved' => 'Đã giải quyết (' . (int)$stats['resolved_warnings'] . ')',
    ];
    $qsBase = $sectionId > 0 ? ['section' => $sectionId] : [];
    foreach ($filters as $k => $v):
    ?>
    <a href="?<?= htmlspecialchars(http_build_query($qsBase + ['filter' => $k])) ?>"
       class="lpill <?= $filter === $k ? 'lpill--blue' : 'lpill--gray' ?>">
        <?= htmlspecialchars($v) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ═══════════ SEARCH + TABLE ═══════════════════════════════ -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;">
            <?php if ($sectionId > 0): ?>
            <input type="hidden" name="section" value="<?= $sectionId ?>" />
            <?php endif; ?>
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>" />
            <div style="position:relative;flex:1;min-width:220px;">
                <img src="../img/icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
                <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Tìm tên sinh viên, MSSV, email..."
                       style="width:100%;height:36px;background:#f1f5f9;border:none;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
            </div>
            <select name="type" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                <option value="">Tất cả loại</option>
                <?php foreach ($typeLabels as $k => $v): ?>
                <option value="<?= $k ?>"<?= $typeF === $k ? ' selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="lbtn lbtn--primary lbtn--sm">Lọc</button>
            <?php if ($search || $typeF || $filter !== 'all'): ?>
            <a href="warnings.php<?= $sectionId > 0 ? '?section=' . $sectionId : '' ?>" class="lbtn lbtn--outline lbtn--sm">Xoá lọc</a>
            <?php endif; ?>
        </form>
    </div>

    <div style="padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
        <div style="font-size:14px;font-weight:700;color:#001634;">☑ Danh Sách Cảnh Báo Học Vụ</div>
        <div style="font-size:12px;color:#64748b;"><?= htmlspecialchars($scopeLabel) ?></div>
    </div>

    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Sinh viên &amp; Lớp</th>
                    <th>Loại cảnh báo</th>
                    <th>Mức độ</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($warnings)): ?>
                <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có cảnh báo nào phù hợp với bộ lọc trong phạm vi lớp của bạn.
                </td></tr>
                <?php else: ?>
                <?php foreach ($warnings as $w):
                    $ini     = initials($w['full_name']);
                    $wt      = $w['warning_type'];
                    $sevLvl  = max(1, min(3, (int)$w['warning_level']));
                    $sevLabel = [1 => 'Nhắc nhở', 2 => 'Cần chú ý', 3 => 'Nguy cơ cao'][$sevLvl];
                    $lvlCls  = $sevLvl === 3 ? 'lpill--red' : ($sevLvl === 2 ? 'lpill--orange' : 'lpill--yellow');
                    $tagLabel = ['Attendance' => 'Vắng %', 'LowGPA' => 'GPA thấp', 'LowGrade' => 'Điểm thấp', 'GraduationRisk' => 'Tốt nghiệp'][$wt] ?? $wt;
                    $tagCls   = ['Attendance' => 'att', 'LowGPA' => 'gpa', 'LowGrade' => 'grade'][$wt] ?? 'att';
                    $msgLines = array_values(array_filter(array_map('trim', explode("\n", wi_warning_text((string)$w['message']))), fn($l) => $l !== ''));
                    $msgHead  = $msgLines[0] ?? '';
                    $msgAction = '';
                    foreach ($msgLines as $ln) {
                        if (mb_stripos($ln, 'Đề xuất:') === 0) { $msgAction = trim(mb_substr($ln, 8)); }
                    }
                    $display  = $sectionsById[(int)$w['display_section_id']] ?? null;
                    $wiOpen   = (int)$w['my_intervention_count'];
                ?>
                <tr style="<?= !$w['is_read'] ? 'background:#fffbeb;' : '' ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="lec-av" style="width:36px;height:36px;background:<?= $sevLvl === 3 ? '#fee2e2' : ($sevLvl === 2 ? '#fff7ed' : '#eff4ff') ?>;">
                                <?= htmlspecialchars($ini) ?>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:13px;color:#001634;">
                                    <?= htmlspecialchars($w['full_name']) ?>
                                    <?php if (!$w['is_read']): ?>
                                    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#ef4444;margin-left:4px;vertical-align:middle;"></span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:11px;color:#64748b;">
                                    <?= htmlspecialchars($w['student_id']) ?> • <?= htmlspecialchars($display['section_code'] ?? '—') ?>
                                </div>
                                <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($w['email'] ?? '') ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <span class="lec-warn-item__tag lec-warn-item__tag--<?= $tagCls ?>">
                                <?= htmlspecialchars($tagLabel) ?>
                            </span>
                            <div style="font-size:12px;color:#44474e;max-width:320px;line-height:16px;">
                                <?= htmlspecialchars(mb_strimwidth($msgHead, 0, 110, '…')) ?>
                            </div>
                            <?php if ($msgAction !== ''): ?>
                            <div style="font-size:11px;color:#1e40af;">➜ <?= htmlspecialchars(mb_strimwidth($msgAction, 0, 90, '…')) ?></div>
                            <?php endif; ?>
                            <div style="font-size:11px;color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($w['created_at'])) ?></div>
                        </div>
                    </td>
                    <td>
                        <span class="lpill <?= $lvlCls ?>"><?= $sevLabel ?></span>
                    </td>
                    <td>
                        <?php if ($w['warn_status'] === 'Resolved'): ?>
                        <span class="lpill lpill--green" style="font-size:11px;">✅ Đã hoàn tất</span>
                        <?php elseif ($w['latest_intervention_status'] === 'In Progress'): ?>
                        <span class="lpill lpill--blue" style="font-size:11px;">Đang can thiệp</span>
                        <?php elseif ($w['is_read']): ?>
                        <span class="lpill lpill--gray" style="font-size:11px;">Đang theo dõi</span>
                        <?php else: ?>
                        <span class="lpill lpill--red" style="font-size:11px;">Cần xử lý</span>
                        <?php endif; ?>
                        <?php if ($wiOpen > 0): ?>
                        <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= $wiOpen ?> can thiệp đã ghi</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <?php if ($display): ?>
                            <a href="classes.php?section=<?= (int)$display['section_id'] ?>" class="lbtn lbtn--outline lbtn--sm" title="Xem lớp học phần">👁</a>
                            <?php endif; ?>
                            <button type="button" class="lbtn lbtn--outline lbtn--sm wi-open"
                                    data-warning="<?= (int)$w['warning_id'] ?>" data-mode="detail">Chi tiết</button>
                            <button type="button" class="lbtn lbtn--red lbtn--sm wi-open"
                                    data-warning="<?= (int)$w['warning_id'] ?>" data-mode="intervene">Ghi nhận can thiệp</button>
                            <button type="button" class="lbtn lbtn--outline lbtn--sm wi-open"
                                    data-warning="<?= (int)$w['warning_id'] ?>" data-mode="contact">Liên hệ SV &amp; Cố vấn</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="padding:12px 16px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <span style="font-size:12px;color:#64748b;">
            Hiển thị <?= $totalFiltered ? $offset + 1 : 0 ?>–<?= min($offset + $perPage, $totalFiltered) ?> trên tổng số <?= $totalFiltered ?> cảnh báo
        </span>
        <?php if ($totalPages > 1):
            $qsBase = ['filter' => $filter, 'type' => $typeF, 'q' => $search];
            if ($sectionId > 0) $qsBase = ['section' => $sectionId] + $qsBase;
        ?>
        <div class="lec-pagination">
            <?php if ($page > 1): ?>
            <a href="?<?= htmlspecialchars(http_build_query($qsBase + ['page' => $page - 1])) ?>">‹</a>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <?php if ($p === $page): ?>
                <span class="active"><?= $p ?></span>
                <?php else: ?>
                <a href="?<?= htmlspecialchars(http_build_query($qsBase + ['page' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?= htmlspecialchars(http_build_query($qsBase + ['page' => $page + 1])) ?>">›</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════ RECENT INTERVENTIONS (own records only) ═══════════ -->
<div class="lcard wi-history">
    <div class="wi-history__head">
        <div>
            <div class="wi-history__title">Hồ sơ can thiệp gần đây</div>
            <div class="wi-history__sub">Chỉ hiển thị hồ sơ do bạn ghi nhận trong <?= htmlspecialchars($scopeLabel) ?>.</div>
        </div>
    </div>
    <?php if (empty($recentInterventions)): ?>
    <div class="lec-empty" style="padding:28px 20px;">
        <div class="lec-empty__icon">📄</div>
        <p>Chưa có hồ sơ can thiệp nào trong phạm vi này.</p>
    </div>
    <?php else: ?>
    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Sinh viên</th>
                    <th>Lớp / Môn</th>
                    <th>Hình thức</th>
                    <th>Ghi chú</th>
                    <th>Trạng thái</th>
                    <th>Cập nhật</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentInterventions as $wi): ?>
                <tr>
                    <td>
                        <div style="font-weight:700;font-size:13px;color:#001634;"><?= htmlspecialchars($wi['full_name']) ?></div>
                        <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($wi['student_id']) ?></div>
                    </td>
                    <td>
                        <div style="font-size:12px;color:#001634;font-weight:600;"><?= htmlspecialchars($wi['section_code']) ?></div>
                        <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($wi['course_code']) ?></div>
                    </td>
                    <td style="font-size:12px;"><?= htmlspecialchars(WarningInterventionService::ACTION_TYPES[$wi['action_type']] ?? $wi['action_type']) ?></td>
                    <td style="font-size:12px;color:#44474e;max-width:260px;">
                        <?= htmlspecialchars(mb_strimwidth((string)($wi['note'] ?? '—'), 0, 120, '…')) ?>
                    </td>
                    <td>
                        <span class="lpill <?= $wi['status'] === 'Resolved' ? 'lpill--green' : 'lpill--blue' ?>" style="font-size:11px;">
                            <?= htmlspecialchars(WarningInterventionService::STATUSES[$wi['status']] ?? $wi['status']) ?>
                        </span>
                        <div style="font-size:11px;color:#94a3b8;margin-top:4px;"><?= date('d/m/Y H:i', strtotime($wi['updated_at'])) ?></div>
                    </td>
                    <td>
                        <form method="POST" action="" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="update_intervention" value="1" />
                            <input type="hidden" name="intervention_id" value="<?= (int)$wi['intervention_id'] ?>" />
                            <select name="status" class="wi-status-select">
                                <?php foreach (WarningInterventionService::STATUSES as $sk => $sv): ?>
                                <option value="<?= htmlspecialchars($sk) ?>"<?= $wi['status'] === $sk ? ' selected' : '' ?>><?= htmlspecialchars($sv) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="lbtn lbtn--outline lbtn--sm">Cập nhật</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════ WARNING DETAIL / INTERVENTION MODAL ═══════════ -->
<div class="lec-modal-overlay hidden" id="wiModal" role="dialog" aria-modal="true" aria-labelledby="wiModalTitle">
    <div class="lec-modal wi-modal">
        <div class="lec-modal__header">
            <div>
                <div class="lec-modal__title" id="wiModalTitle">Chi tiết cảnh báo</div>
                <div class="wi-modal__sub" id="wiModalSub"></div>
            </div>
            <button type="button" class="lec-modal__close" data-wi-close aria-label="Đóng">×</button>
        </div>
        <div id="wiModalBody">
            <div class="wi-loading">Đang tải dữ liệu…</div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var modal   = document.getElementById('wiModal');
    var body    = document.getElementById('wiModalBody');
    var sub     = document.getElementById('wiModalSub');
    var section = <?= (int)$sectionId ?>;
    var csrf    = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function openModal() {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    function render(data, mode) {
        var w = data.warning;
        body.innerHTML = '';
        sub.textContent = w.student.name + ' · ' + w.student.id + ' · ' + (w.section_code || '—');

        var sev = el('div', 'wi-severity wi-severity--' + w.level);
        sev.appendChild(el('span', 'wi-severity__tag', w.type_label));
        sev.appendChild(el('span', 'wi-severity__level', w.severity_label));
        sev.appendChild(el('span', 'wi-severity__meta', w.created_at + ' · ' + (w.course_code || '—')));
        body.appendChild(sev);

        var msg = el('div', 'wi-message');
        (w.message_details || []).forEach(function (line) { msg.appendChild(el('div', null, line)); });
        if (w.action) {
            var act = el('div', 'wi-message__action');
            act.appendChild(el('span', null, '➜ '));
            act.appendChild(document.createTextNode(w.action));
            msg.appendChild(act);
        }
        body.appendChild(msg);

        var info = el('div', 'wi-grid');
        [
            ['Sinh viên', w.student.name],
            ['MSSV', w.student.id],
            ['Lớp sinh hoạt', w.student.class_code],
            ['Lớp học phần', w.section_code || '—'],
            ['Môn học', (w.course_code || '—') + (w.course_name ? ' – ' + w.course_name : '')],
            ['Học kỳ', w.semester || '—']
        ].forEach(function (pair) {
            var cell = el('div', 'wi-grid__cell');
            cell.appendChild(el('div', 'wi-grid__label', pair[0]));
            cell.appendChild(el('div', 'wi-grid__value', pair[1]));
            info.appendChild(cell);
        });
        body.appendChild(info);

        var contact = el('div', 'wi-contact');
        contact.id = 'wiContact';
        contact.appendChild(el('div', 'wi-contact__title', 'Liên hệ sinh viên & cố vấn'));
        var cRow = el('div', 'wi-contact__row');
        if (w.student.email) {
            var mail = el('a', 'lbtn lbtn--outline lbtn--sm', '✉ ' + w.student.email);
            mail.href = 'mailto:' + encodeURIComponent(w.student.email);
            cRow.appendChild(mail);
        }
        if (w.student.phone) {
            var tel = el('a', 'lbtn lbtn--outline lbtn--sm', '☎ ' + w.student.phone);
            tel.href = 'tel:' + w.student.phone.replace(/[^\d+]/g, '');
            cRow.appendChild(tel);
        }
        cRow.appendChild(el('span', 'wi-contact__note', 'Cố vấn học tập: liên hệ Khoa quản lý lớp học phần.'));
        contact.appendChild(cRow);
        body.appendChild(contact);

        if (w.interventions && w.interventions.length) {
            var hist = el('div', 'wi-sub');
            hist.appendChild(el('div', 'wi-sub__title', 'Can thiệp đã ghi cho cảnh báo này'));
            var list = el('div', 'wi-sub__list');
            w.interventions.forEach(function (it) {
                var row = el('div', 'wi-sub__item');
                row.appendChild(el('span', 'lpill ' + (it.status === 'Resolved' ? 'lpill--green' : 'lpill--blue'), it.status_label));
                row.appendChild(el('span', 'wi-sub__action', it.action_label));
                row.appendChild(el('span', 'wi-sub__date', it.created_at));
                if (it.note) row.appendChild(el('div', 'wi-sub__note', it.note));
                list.appendChild(row);
            });
            hist.appendChild(list);
            body.appendChild(hist);
        }

        var form = el('form', 'wi-form');
        form.method = 'POST';
        form.action = '';
        form.id = 'wiForm';
        function hidden(name, value) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = name; i.value = value;
            form.appendChild(i);
        }
        hidden('_csrf', csrf);
        hidden('record_intervention', '1');
        hidden('warning_id', String(w.id));
        hidden('section_id', String(w.section_id));

        form.appendChild(el('div', 'wi-sub__title', 'Ghi nhận can thiệp'));
        var fields = el('div', 'lec-modal-fields');
        var row2 = el('div', 'wi-form__row');

        var f1 = el('div', 'lec-modal-field');
        var lab1 = el('label', null, 'Hình thức can thiệp');
        lab1.setAttribute('for', 'wiActionType');
        var sel1 = document.createElement('select');
        sel1.name = 'action_type'; sel1.id = 'wiActionType';
        Object.keys(data.action_types).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k; o.textContent = data.action_types[k];
            sel1.appendChild(o);
        });
        f1.appendChild(lab1); f1.appendChild(sel1);
        row2.appendChild(f1);

        var f2 = el('div', 'lec-modal-field');
        var lab2 = el('label', null, 'Trạng thái');
        lab2.setAttribute('for', 'wiStatus');
        var sel2 = document.createElement('select');
        sel2.name = 'status'; sel2.id = 'wiStatus';
        Object.keys(data.statuses).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k; o.textContent = data.statuses[k];
            sel2.appendChild(o);
        });
        f2.appendChild(lab2); f2.appendChild(sel2);
        row2.appendChild(f2);
        fields.appendChild(row2);

        var f3 = el('div', 'lec-modal-field');
        var lab3 = el('label', null, 'Ghi chú / nội dung trao đổi');
        lab3.setAttribute('for', 'wiNote');
        var ta = document.createElement('textarea');
        ta.name = 'note'; ta.id = 'wiNote'; ta.rows = 3;
        ta.maxLength = 1000;
        ta.placeholder = 'Ví dụ: Đã gọi điện trao đổi, SV cam kết bổ sung bài tập trước 20/11.';
        f3.appendChild(lab3); f3.appendChild(ta);
        fields.appendChild(f3);
        form.appendChild(fields);

        var actions = el('div', 'lec-modal__actions');
        var cancel = el('button', 'lbtn lbtn--outline', 'Đóng');
        cancel.type = 'button';
        cancel.setAttribute('data-wi-close', '');
        var submit = el('button', 'lbtn lbtn--red', 'Lưu can thiệp');
        submit.type = 'submit';
        actions.appendChild(cancel);
        actions.appendChild(submit);
        form.appendChild(actions);
        body.appendChild(form);

        if (mode === 'contact') {
            contact.scrollIntoView({block: 'center'});
        } else if (mode === 'intervene') {
            form.scrollIntoView({block: 'center'});
            setTimeout(function () { sel1.focus(); }, 50);
        }
    }

    function loadWarning(id, mode) {
        var params = new URLSearchParams();
        params.set('detail', id);
        if (section > 0) params.set('section', section);
        openModal();
        body.innerHTML = '';
        body.appendChild(el('div', 'wi-loading', 'Đang tải dữ liệu…'));
        sub.textContent = '';
        fetch('warnings.php?' + params.toString(), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) { return r.json().then(function (j) { return {ok: r.ok, data: j}; }); })
            .then(function (res) {
                if (!res.ok || !res.data.ok) {
                    body.innerHTML = '';
                    body.appendChild(el('div', 'wi-error', res.data.error || 'Không thể tải cảnh báo.'));
                    return;
                }
                render(res.data, mode);
            })
            .catch(function () {
                body.innerHTML = '';
                body.appendChild(el('div', 'wi-error', 'Không thể tải dữ liệu. Vui lòng thử lại.'));
            });
    }

    document.querySelectorAll('.wi-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            loadWarning(btn.getAttribute('data-warning'), btn.getAttribute('data-mode') || 'detail');
        });
    });

    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.closest('[data-wi-close]')) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });
})();
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
