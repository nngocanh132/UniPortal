<?php
/**
 * UniPortal – GPA Planner & AI Academic Advisor
 *
 * All GPA math is computed by GpaAdvisor (credit-weighted, based on the real
 * GradeScale and the configured retake policy). GpaAiService only narrates the
 * calculated facts; when no provider is configured it is clearly labelled as
 * rule-based analysis.
 *
 * This page never modifies official Grade or Enrollment records. Simulations
 * are estimates and are labelled as such.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/Gpa.php';
require_once $root . '/src/src/models/GpaPlanner.php';
require_once $root . '/src/src/models/GpaAdvisor.php';
require_once $root . '/src/src/services/SystemConfig.php';
require_once $root . '/src/src/services/GpaAiService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$sid = (string)($_SESSION['student_id'] ?? '');
if ($sid === '') { header('Location: /UniPortal/public/index.php'); exit; }

function gf(?float $v): string { return $v !== null ? number_format($v, 2) : '—'; }

$scale = GpaPlanner::getGradeScale();

// ── Student profile (server-side identity only) ─────────────
$profile  = GpaAdvisor::getProfile($sid);
$official = GpaModel::getCumulative($sid);

// ── Target GPA (0.00 – 4.00) ────────────────────────────────
$targetGpa = 3.20;
$rawTarget = $_POST['target'] ?? $_GET['target'] ?? null;
if ($rawTarget !== null && is_numeric($rawTarget)) {
    $targetGpa = (float)$rawTarget;
}
$targetGpa = max(0.0, min(4.0, $targetGpa));

$thresholds = [
    ['label' => 'Trung bình', 'gpa' => SystemConfig::getFloat('grad_pass_gpa',      2.00), 'short' => 'TB'],
    ['label' => 'Khá',        'gpa' => SystemConfig::getFloat('grad_average_gpa',   2.50), 'short' => 'Khá'],
    ['label' => 'Giỏi',       'gpa' => SystemConfig::getFloat('grad_good_gpa',      3.20), 'short' => 'Giỏi'],
    ['label' => 'Xuất sắc',   'gpa' => SystemConfig::getFloat('grad_excellent_gpa', 3.60), 'short' => 'XS'],
];
$targetLabel = 'Tùy chỉnh';
foreach ($thresholds as $t) {
    if (abs($targetGpa - $t['gpa']) < 0.001) { $targetLabel = $t['label']; break; }
}

// ── Deterministic analysis ───────────────────────────────────
$analysis = GpaAdvisor::analyze($profile, $targetGpa);

$currentSem     = StudentModel::getCurrentSemester();
$currentSemId   = (int)($currentSem['semester_id'] ?? 0);
$currentSemLabel = trim(($currentSem['semester_name'] ?? '') . ' ' . ($currentSem['academic_year'] ?? ''));
$currentSemCredits = 0;
foreach ($profile['current_enrolled'] as $enr) {
    if ($currentSemId > 0 && (int)$enr['semester_id'] === $currentSemId) {
        $currentSemCredits += (int)$enr['credits'];
    }
}

$roadmap    = GpaAdvisor::buildRoadmap($profile, $analysis, null, $currentSemId, $currentSemCredits, $currentSemLabel);
$planning   = GpaAdvisor::getPlanningCourses($sid);
$courseRecs = GpaAdvisor::recommendCourseGrades($profile, $analysis, $planning);
$candidates = GpaAdvisor::getRetakeCandidates($profile, $analysis);
$presets    = GpaAdvisor::presetScenarios($profile, $analysis, $candidates);
$facts      = GpaAdvisor::buildFacts($profile, $analysis, $roadmap, $candidates, $courseRecs);

$feasMeta = [
    'achieved'       => ['✅', 'Đã đạt mục tiêu',  '#059669', '#ecfdf5'],
    'achievable'     => ['🎯', 'Khả thi',          '#059669', '#ecfdf5'],
    'difficult'      => ['⚠️', 'Khó – cần nỗ lực cao', '#d97706', '#fffbeb'],
    'very_difficult' => ['🔴', 'Rất khó',          '#dc2626', '#fff1f2'],
    'unachievable'   => ['❌', 'Không khả thi',    '#b91c1c', '#fee2e2'],
    'no_future'      => ['📊', 'Không còn tín chỉ kế hoạch', '#64748b', '#f1f5f9'],
    'no_data'        => ['ℹ️', 'Chưa có dữ liệu',  '#64748b', '#f1f5f9'],
];
$feas = $feasMeta[$analysis['feasibility']] ?? $feasMeta['no_data'];

// ── POST actions ─────────────────────────────────────────────
$flash = '';
$simResult = null;
$simInput  = ['future_gpa' => $analysis['required_future_gpa'] !== null
        ? round(min(4.0, max(0.0, (float)$analysis['required_future_gpa'])), 2) : 3.0,
    'retakes' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['_action'] ?? '');

    if ($action === 'analyze') {
        $narrative = GpaAiService::narrate($facts);
        $_SESSION['gpa_narrative'] = $narrative + [
            'target' => $targetGpa,
            'at'     => date('H:i d/m/Y'),
        ];
        $flash = 'success:Đã phân tích lộ trình cho mục tiêu ' . number_format($targetGpa, 2) . '.';

    } elseif ($action === 'chat') {
        $question = trim((string)($_POST['question'] ?? ''));
        if (!mb_check_encoding($question, 'UTF-8')) {
            $question = '';
            $flash = 'error:Câu hỏi không hợp lệ (mã hóa ký tự). Vui lòng nhập lại.';
        } elseif (mb_strlen($question) > 500) {
            $question = mb_substr($question, 0, 500);
        }
        if ($question === '' && $flash === '') {
            $flash = 'error:Vui lòng nhập câu hỏi.';
        } elseif ($question !== '') {
            $ans = GpaAdvisor::answerQuestion($question, $profile, $analysis, $roadmap, $candidates, $courseRecs, $targetGpa);
            $_SESSION['gpa_chat'] = [
                'q'      => $question,
                'a'      => $ans['answer'],
                'intent' => $ans['intent'],
                'target' => $ans['target'],
                'at'     => date('H:i d/m/Y'),
            ];
        }

    } elseif ($action === 'clear_chat') {
        unset($_SESSION['gpa_chat']);
        $flash = 'success:Đã xóa hội thoại.';

    } elseif ($action === 'simulate') {
        $future = max(0.0, min(4.0, (float)($_POST['future_gpa'] ?? 0)));
        $eligibleCodes = [];
        foreach ($candidates as $c) {
            if ($c['eligible']) $eligibleCodes[strtoupper($c['course_code'])] = true;
        }
        $improvements = [];
        $selected = [];
        foreach ((array)($_POST['retake'] ?? []) as $code => $letter) {
            $code = strtoupper(trim((string)$code));
            if (!isset($eligibleCodes[$code])) continue;
            $gp = GpaAdvisor::gradePointForLetter((string)$letter, $scale);
            if ($gp === null) continue;
            $improvements[] = ['course_code' => $code, 'new_gpa' => $gp];
            $selected[$code] = (string)$letter;
        }
        $simResult = GpaAdvisor::simulateScenario($profile, $improvements, $future, $targetGpa);
        $simInput  = ['future_gpa' => $future, 'retakes' => $selected];

    } elseif ($action === 'save_simulation') {
        $name = trim((string)($_POST['sim_name'] ?? ''));
        $future = max(0.0, min(4.0, (float)($_POST['future_gpa'] ?? 0)));
        $decoded = json_decode((string)($_POST['retakes_json'] ?? '[]'), true);
        if (!is_array($decoded)) $decoded = [];
        $improvements = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) continue;
            $code = strtoupper(trim((string)($row['course_code'] ?? '')));
            $gp   = (float)($row['new_gpa'] ?? -1);
            if ($code === '' || !isset($profile['groups'][$code]) || $gp < 0 || $gp > 4) continue;
            $improvements[$code] = ['course_code' => $code, 'new_gpa' => $gp];
        }
        if ($name === '' || mb_strlen($name) > 150) {
            $flash = 'error:Tên kịch bản mô phỏng là bắt buộc (tối đa 150 ký tự).';
        } else {
            $recomputed = GpaAdvisor::simulateScenario($profile, array_values($improvements), $future, $targetGpa);
            GpaAdvisor::saveSimulation($sid, [
                'scenario_name'        => $name,
                'target_gpa'           => $targetGpa,
                'future_gpa'           => $future,
                'total_future_credits' => (int)$recomputed['total_future_credits'],
                'improvement_credits'  => (int)$recomputed['improvement_credits'],
                'improvements'         => $recomputed['improvements'],
                'expected_gpa'         => $recomputed['expected_gpa'],
                'required_future_gpa'  => $recomputed['required_future_gpa'],
                'feasibility'          => $recomputed['feasibility'],
            ]);
            $flash = 'success:Đã lưu mô phỏng "' . $name . '".';
        }

    } elseif ($action === 'delete_simulation') {
        $simId = (int)($_POST['simulation_id'] ?? 0);
        if ($simId > 0) GpaAdvisor::deleteSimulation($sid, $simId);
        $flash = 'success:Đã xóa mô phỏng.';

    } elseif ($action === 'save_scenario') {
        $name   = trim((string)($_POST['scenario_name'] ?? ''));
        $tgpa   = (float)($_POST['target_gpa'] ?? $targetGpa);
        $tlabel = trim((string)($_POST['target_label'] ?? $targetLabel));
        $fcrd   = (int)($_POST['future_credits'] ?? $profile['remaining_credits']);
        $scAnalysis = GpaAdvisor::analyze($profile, max(0.0, min(4.0, $tgpa)));
        $reqGpa = $scAnalysis['required_future_gpa'];
        if ($name === '' || $tgpa <= 0 || $tgpa > 4.0) {
            $flash = 'error:Tên kịch bản và GPA mục tiêu (0–4,0) là bắt buộc.';
        } else {
            GpaPlanner::saveScenario($sid, $name, $tgpa, $tlabel, $fcrd, $reqGpa, $scAnalysis['feasibility']);
            $flash = 'success:Đã lưu kịch bản "' . $name . '".';
        }

    } elseif ($action === 'delete_scenario') {
        $scId = (int)($_POST['scenario_id'] ?? 0);
        if ($scId > 0) GpaPlanner::deleteScenario($sid, $scId);
        $flash = 'success:Đã xóa kịch bản.';
    }
}

// Narrative (session result for this target, or fresh rule-based text)
$narrative = null;
if (isset($_SESSION['gpa_narrative']) && is_array($_SESSION['gpa_narrative'])
    && abs((float)($_SESSION['gpa_narrative']['target'] ?? -1) - $targetGpa) < 0.005) {
    $narrative = $_SESSION['gpa_narrative'];
}
if ($narrative === null) {
    $narrative = [
        'source' => 'rule_based',
        'label'  => GpaAiService::sourceLabel('rule_based'),
        'text'   => GpaAiService::ruleBased($facts),
        'model'  => null,
        'at'     => null,
    ];
}
$chat = $_SESSION['gpa_chat'] ?? null;

$savedSims = GpaAdvisor::getSavedSimulations($sid, 8);
$savedScenarios = GpaPlanner::getScenarios($sid);

// ── Chart series (official history + plan projection) ───────
$history = GpaModel::getGpaHistory($sid);
$labels = $actualSem = $actualCum = $planSem = $planCum = [];
$rCrd = $rPts = 0;
foreach ($history as $r) {
    $labels[]    = preg_replace('/^Học kỳ\s*/iu', 'HK', (string)($r['semester_name'] ?? '')) . ' (' . ($r['academic_year'] ?? '') . ')';
    $semGpa      = (float)($r['semester_gpa'] ?? 0);
    $actualSem[] = $semGpa;
    $rCrd       += (int)($r['credits_this_sem'] ?? 0);
    $rPts       += $semGpa * (int)($r['credits_this_sem'] ?? 1);
    $actualCum[] = $rCrd > 0 ? round($rPts / $rCrd, 2) : 0;
    $planSem[]   = null;
    $planCum[]   = null;
}
$histCount = count($history);
if ($histCount > 0 && !empty($actualCum) && !empty($roadmap)) {
    $planCum[$histCount - 1] = end($actualCum);
}
foreach ($roadmap as $i => $p) {
    $labels[]    = 'KH ' . ($i + 1);
    $actualSem[] = null;
    $actualCum[] = null;
    $planSem[]   = $p['target_gpa'];
    $planCum[]   = $p['projected_cum'];
}
$jsLabels = json_encode($labels, JSON_UNESCAPED_UNICODE);
$jsActSem = json_encode($actualSem);
$jsActCum = json_encode($actualCum);
$jsPlanSem = json_encode($planSem);
$jsPlanCum = json_encode($planCum);

// Retake data for the simulation modal (server-rendered, escaped)
$retakeJs = [];
foreach ($candidates as $c) {
    $retakeJs[strtoupper($c['course_code'])] = [
        'code'     => $c['course_code'],
        'name'     => $c['course_name'],
        'credits'  => (int)$c['credits'],
        'current'  => $c['best_letter'],
        'current_gp' => (float)$c['best_gpa'],
        'eligible' => (bool)$c['eligible'],
        'attempts' => (int)$c['attempt_count'],
        'priority' => $c['priority'],
        'reason'   => $c['reason'],
        'options'  => array_map(static fn($o) => [
            'letter'   => $o['letter'],
            'min_score'=> (float)$o['min_score'],
            'delta'    => (float)$o['delta'],
            'new_gpa'  => $o['new_gpa'],
            'expected' => $o['expected_gpa'],
            'required' => $o['required_after'],
            'max'      => $o['max_after'],
        ], $c['options']),
    ];
}
$retakeJson = json_encode($retakeJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$classification = SystemConfig::getClassification($profile['current_gpa']);
$creditPct = $profile['required_credits'] > 0
    ? min(100, round($profile['graduation_earned'] / $profile['required_credits'] * 100))
    : 0;
$gpaPct = $profile['current_gpa'] !== null ? min(100, $profile['current_gpa'] / 4 * 100) : 0;
$targetPct = min(100, $targetGpa / 4 * 100);

$pageTitle  = 'Kế hoạch & Dự báo GPA (AI)';
$activePage = 'gpa-forecast';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
require_once __DIR__ . '/../partials/layout.php';
?>

<style>
.gpa-stat{background:#fff;border:1px solid #e6ecf5;border-radius:14px;padding:15px 16px;display:flex;flex-direction:column;gap:3px;}
.gpa-stat .lbl{font-size:11px;font-weight:700;color:#7c8aa0;text-transform:uppercase;letter-spacing:.35px;}
.gpa-stat .val{font-size:25px;font-weight:800;color:#001634;line-height:1.15;}
.gpa-stat .sub{font-size:11.5px;color:#64748b;}
.gpa-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:999px;font-size:11.5px;font-weight:700;}
.gpa-bar{height:9px;border-radius:999px;background:#e8eef7;overflow:hidden;position:relative;}
.gpa-bar>i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#102b50,#2f6fbe);}
.gpa-bar--gold>i{background:linear-gradient(90deg,#d9a800,#fdcc35);}
.gpa-marker{position:absolute;top:-3px;bottom:-3px;width:2.5px;background:#ef4444;border-radius:2px;}
.target-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;border:1.5px solid #dbe4f0;background:#fff;color:#102b50;font-size:13px;font-weight:700;text-decoration:none;transition:.15s;}
.target-pill:hover{border-color:#102b50;background:#f5f8ff;}
.target-pill.active{border-color:#102b50;background:#102b50;color:#fff;}
.btn-ai{display:inline-flex;align-items:center;gap:9px;padding:12px 22px;border:none;border-radius:11px;cursor:pointer;font-size:13.5px;font-weight:800;color:#001634;background:linear-gradient(135deg,#fdcc35,#ffd968);box-shadow:0 6px 16px rgba(253,204,53,.35);}
.btn-ai:hover{filter:brightness(1.04);}
.btn-solid{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:9px;background:#102b50;color:#fff;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;}
.btn-solid:hover{background:#183c6d;}
.btn-ghost{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:1px solid #dbe4f0;border-radius:8px;background:#fff;color:#102b50;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;}
.btn-ghost:hover{border-color:#102b50;background:#f5f8ff;}
.btn-danger{display:inline-flex;align-items:center;padding:5px 9px;border:none;border-radius:7px;background:#fee2e2;color:#b91c1c;font-size:12px;cursor:pointer;}
.sec-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.sec-num{width:24px;height:24px;border-radius:8px;background:#102b50;color:#fff;font-size:12px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;}
.sec-title{font-size:15.5px;font-weight:800;color:#001634;letter-spacing:-.2px;display:flex;align-items:center;gap:9px;margin:0;}
.sec-sub{font-size:12px;color:#64748b;margin:3px 0 0;}
.timeline{position:relative;padding-left:26px;display:flex;flex-direction:column;gap:14px;}
.timeline::before{content:"";position:absolute;left:9px;top:8px;bottom:8px;width:2px;background:#dbe4f0;border-radius:2px;}
.tl-item{position:relative;background:#f8fbff;border:1px solid #e6ecf5;border-radius:12px;padding:13px 15px;}
.tl-item::before{content:"";position:absolute;left:-22px;top:17px;width:12px;height:12px;border-radius:50%;background:#fff;border:3px solid #102b50;}
.tl-item.done::before{border-color:#059669;}
.tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.tbl th{background:#f4f7fc;color:#475569;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.3px;padding:9px 10px;text-align:left;white-space:nowrap;}
.tbl td{padding:9px 10px;border-top:1px solid #eef2f7;color:#1e293b;vertical-align:top;}
.tbl tr:hover td{background:#fbfdff;}
.chip{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:700;white-space:nowrap;}
.chip--red{background:#fee2e2;color:#b91c1c;}
.chip--amber{background:#fffbeb;color:#b45309;}
.chip--green{background:#ecfdf5;color:#047857;}
.chip--blue{background:#eff4ff;color:#1d4ed8;}
.chip--slate{background:#f1f5f9;color:#64748b;}
.chip--purple{background:#f3e8ff;color:#7e22ce;}
.narr{white-space:pre-line;font-size:13px;line-height:1.75;color:#233247;background:#fbfdff;border:1px solid #e6ecf5;border-radius:12px;padding:16px 18px;}
.qchip{border:1px solid #dbe4f0;background:#fff;color:#102b50;border-radius:999px;padding:6px 12px;font-size:11.5px;font-weight:600;cursor:pointer;}
.qchip:hover{background:#f5f8ff;border-color:#102b50;}
.chat-q{background:#102b50;color:#fff;border-radius:12px 12px 3px 12px;padding:11px 14px;font-size:13px;max-width:85%;align-self:flex-end;}
.chat-a{background:#f4f7fc;border:1px solid #e6ecf5;border-radius:12px 12px 12px 3px;padding:13px 15px;font-size:12.5px;white-space:pre-line;line-height:1.7;color:#233247;max-width:95%;align-self:flex-start;}
.sim-card{border:1px solid #e6ecf5;border-radius:13px;padding:15px;background:#fff;display:flex;flex-direction:column;gap:7px;}
.sim-card.hl{border-color:#fdcc35;background:#fffdf4;box-shadow:0 4px 14px rgba(253,204,53,.18);}
.modal-bg{position:fixed;inset:0;background:rgba(0,22,52,.55);display:none;align-items:center;justify-content:center;z-index:1200;padding:16px;}
.modal-box{background:#fff;border-radius:16px;max-width:760px;width:100%;max-height:88vh;overflow:auto;padding:22px 24px;}
.roadmap-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:18px;align-items:start;}
@media (max-width:900px){.roadmap-grid{grid-template-columns:1fr;}}
@media (max-width:720px){.tbl{font-size:11.5px}}
</style>

<!-- ── Policy info ───────────────────────────────────────────── -->
<div style="display:flex;justify-content:flex-end;gap:18px;flex-wrap:wrap;font-size:12px;color:#64748b;">
    <div>Chính sách học lại: <strong style="color:#001634;"><?= h($profile['policy_label']) ?></strong></div>
    <div>Tín chỉ tốt nghiệp: <strong style="color:#001634;"><?= (int)$profile['required_credits'] ?> TC</strong></div>
</div>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft === 'success' ? '#d1fae5' : '#fee2e2' ?>;
            border:1px solid <?= $ft === 'success' ? '#6ee7b7' : '#fca5a5' ?>;
            color:<?= $ft === 'success' ? '#065f46' : '#b91c1c' ?>;">
    <?= $ft === 'success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- ═══ 1. ACADEMIC OVERVIEW ═══════════════════════════════════ -->
<div class="card" style="padding:20px 24px;">
    <div class="sec-head" style="margin-bottom:16px;">
        <div>
            <h2 class="sec-title"><span class="sec-num">1</span> Tổng quan học vụ</h2>
            <p class="sec-sub">Dữ liệu chính thức từ CSDL, tính theo chính sách học lại hiện hành</p>
        </div>
        <span class="gpa-badge" style="background:<?= $feas[3] ?>;color:<?= $feas[2] ?>;">
            <?= $feas[0] ?> Mục tiêu <?= number_format($targetGpa, 2) ?>: <?= h($feas[1]) ?>
        </span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;">
        <div class="gpa-stat">
            <span class="lbl">GPA tích lũy</span>
            <span class="val"><?= gf($profile['current_gpa']) ?><span style="font-size:13px;color:#94a3b8;">/4,00</span></span>
            <span class="sub"><?= h($classification) ?> · <?= (int)$profile['base_credits'] ?> TC tính GPA</span>
        </div>
        <div class="gpa-stat">
            <span class="lbl">Tín chỉ hoàn thành</span>
            <span class="val"><?= (int)$profile['graduation_earned'] ?><span style="font-size:13px;color:#94a3b8;">/<?= (int)$profile['required_credits'] ?></span></span>
            <div class="gpa-bar" style="margin-top:5px;"><i style="width:<?= $creditPct ?>%"></i></div>
            <span class="sub">Còn <?= (int)$profile['remaining_credits'] ?> TC</span>
        </div>
        <div class="gpa-stat">
            <span class="lbl">Mục tiêu tốt nghiệp</span>
            <span class="val" style="color:#b45309;"><?= number_format($targetGpa, 2) ?></span>
            <span class="sub"><?= h($targetLabel) ?></span>
        </div>
        <div class="gpa-stat">
            <span class="lbl">GPA tối đa có thể đạt</span>
            <span class="val"><?= gf($analysis['max_possible_gpa']) ?></span>
            <span class="sub">Nếu mọi TC còn lại đạt A+ (4,0)</span>
        </div>
        <div class="gpa-stat">
            <span class="lbl">GPA cần đạt còn lại</span>
            <span class="val" style="color:<?= $feas[2] ?>;">
                <?= ($analysis['raw_required_future_gpa'] !== null && $analysis['raw_required_future_gpa'] > 4.0)
                    ? '&gt; 4,00' : gf($analysis['required_future_gpa']) ?>
            </span>
            <span class="sub">Trung bình trên <?= (int)$profile['remaining_credits'] ?> TC</span>
        </div>
    </div>

    <div style="margin-top:16px;padding:13px 16px;background:#fbfdff;border:1px solid #e6ecf5;border-radius:12px;">
        <div style="display:flex;justify-content:space-between;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:6px;">
            <span>GPA hiện tại <?= gf($profile['current_gpa']) ?></span>
            <span>Mục tiêu <?= number_format($targetGpa, 2) ?> · Tối đa <?= gf($analysis['max_possible_gpa']) ?></span>
        </div>
        <div class="gpa-bar gpa-bar--gold" style="height:11px;">
            <i style="width:<?= $gpaPct ?>%"></i>
            <span class="gpa-marker" style="left:<?= $targetPct ?>%;" title="Mục tiêu <?= number_format($targetGpa, 2) ?>"></span>
        </div>
        <?php if ($profile['official_gpa'] !== null && $profile['current_gpa'] !== null
                  && abs($profile['official_gpa'] - $profile['current_gpa']) >= 0.005): ?>
        <div style="font-size:11.5px;color:#64748b;margin-top:8px;">
            ℹ️ GPA chính thức theo bảng điểm hiện tại: <strong><?= number_format($profile['official_gpa'], 2) ?></strong>.
            Chênh lệch do chính sách học lại <strong><?= h($profile['policy_label']) ?></strong>.
        </div>
        <?php endif; ?>
        <?php if (!$profile['has_attempts']): ?>
        <div style="font-size:12px;color:#1e40af;margin-top:8px;">
            ℹ️ Chưa có điểm chính thức nào được ghi nhận. Các phân tích bên dưới dựa trên mục tiêu bạn chọn.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ 2. SET GOAL ════════════════════════════════════════════ -->
<div class="card" style="padding:20px 24px;">
    <div class="sec-head">
        <div>
            <h2 class="sec-title"><span class="sec-num">2</span> Đặt mục tiêu tốt nghiệp</h2>
            <p class="sec-sub">Chọn mức xếp loại hoặc nhập GPA tùy chỉnh (0,00 – 4,00)</p>
        </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <?php foreach ($thresholds as $t): ?>
        <a class="target-pill <?= abs($targetGpa - $t['gpa']) < 0.001 ? 'active' : '' ?>"
           href="?target=<?= number_format($t['gpa'], 2, '.', '') ?>">
            <?= h($t['short']) ?> · <?= number_format($t['gpa'], 2) ?>
        </a>
        <?php endforeach; ?>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <input type="number" name="target" step="0.01" min="0" max="4"
                   value="<?= number_format($targetGpa, 2, '.', '') ?>"
                   style="width:96px;height:38px;border:1.5px solid #dbe4f0;border-radius:9px;padding:0 10px;font-size:13px;"
                   aria-label="GPA mục tiêu tùy chỉnh" />
            <button type="submit" class="btn-ghost" style="height:38px;">Áp dụng</button>
        </form>
        <form method="POST" style="margin-left:auto;">
            <?= csrf_input() ?>
            <input type="hidden" name="_action" value="analyze" />
            <input type="hidden" name="target" value="<?= number_format($targetGpa, 2, '.', '') ?>" />
            <button type="submit" class="btn-ai">✨ Phân tích lộ trình bằng AI</button>
        </form>
    </div>
    <div style="font-size:11.5px;color:#64748b;margin-top:10px;">
        💡 Nguồn phân tích: <strong><?= h(GpaAiService::isConfigured() ? 'AI API đã cấu hình (OpenAI-compatible)' : 'bộ phân tích dựa trên quy tắc') ?></strong>.
        Mọi phép tính GPA đều do backend thực hiện, AI chỉ diễn giải kết quả.
    </div>

    <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="save_scenario" />
        <input type="hidden" name="target" value="<?= number_format($targetGpa, 2, '.', '') ?>" />
        <input type="hidden" name="target_gpa" value="<?= number_format($targetGpa, 2, '.', '') ?>" />
        <input type="hidden" name="target_label" value="<?= h($targetLabel) ?>" />
        <input type="hidden" name="future_credits" value="<?= (int)$profile['remaining_credits'] ?>" />
        <input type="text" name="scenario_name" required maxlength="150"
               placeholder="Lưu mục tiêu này thành kịch bản (tên kịch bản)"
               style="flex:1;min-width:200px;height:36px;border:1px solid #dbe4f0;border-radius:8px;padding:0 10px;font-size:12.5px;" />
        <button type="submit" class="btn-ghost" style="height:36px;">📌 Lưu mục tiêu</button>
    </form>

    <?php if (!empty($savedScenarios)): ?>
    <div style="margin-top:16px;border-top:1px solid #eef2f7;padding-top:12px;">
        <div style="font-size:11.5px;font-weight:700;color:#475569;margin-bottom:7px;">Kịch bản mục tiêu đã lưu</div>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach (array_slice($savedScenarios, 0, 5) as $sc): ?>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;background:#f8fbff;border:1px solid #e6ecf5;border-radius:9px;padding:7px 11px;">
                <strong style="color:#001634;"><?= h($sc['scenario_name']) ?></strong>
                <span style="color:#64748b;">Mục tiêu <?= number_format((float)$sc['target_gpa'], 2) ?> · <?= date('d/m/Y', strtotime($sc['created_at'])) ?></span>
                <a href="?target=<?= (float)$sc['target_gpa'] ?>" class="btn-ghost" style="margin-left:auto;padding:3px 9px;font-size:11px;">Áp dụng</a>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa kịch bản này?')">
                    <?= csrf_input() ?>
                    <input type="hidden" name="_action" value="delete_scenario" />
                    <input type="hidden" name="scenario_id" value="<?= (int)$sc['scenario_id'] ?>" />
                    <button type="submit" class="btn-danger" style="padding:3px 7px;">🗑</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ 3. AI ANALYSIS ═════════════════════════════════════════ -->
<div class="card" style="padding:20px 24px;">
    <div class="sec-head">
        <div>
            <h2 class="sec-title"><span class="sec-num">3</span> Phân tích học vụ</h2>
            <p class="sec-sub">Đánh giá dựa trên điểm thực tế, số tín chỉ và chính sách hiện hành</p>
        </div>
        <span class="gpa-badge" style="background:<?= $narrative['source'] === 'ai' ? '#eff4ff' : '#f1f5f9' ?>;color:<?= $narrative['source'] === 'ai' ? '#1d4ed8' : '#475569' ?>;">
            <?= $narrative['source'] === 'ai' ? '🤖' : '📐' ?> <?= h($narrative['label']) ?>
            <?php if (!empty($narrative['model'])): ?><span style="font-weight:600;opacity:.75;">(<?= h($narrative['model']) ?>)</span><?php endif; ?>
        </span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:14px;">
        <div style="padding:11px 14px;border-radius:10px;background:<?= $feas[3] ?>;border:1px solid <?= $feas[2] ?>22;">
            <div style="font-size:11px;font-weight:700;color:<?= $feas[2] ?>;text-transform:uppercase;">Mức khả thi</div>
            <div style="font-size:14px;font-weight:800;color:#001633;margin-top:2px;"><?= $feas[0] ?> <?= h($feas[1]) ?></div>
        </div>
        <div style="padding:11px 14px;border-radius:10px;background:#fbfdff;border:1px solid #e6ecf5;">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Công thức</div>
            <div style="font-size:12px;color:#233247;margin-top:3px;">
                (<?= number_format($targetGpa, 2) ?> × <?= (int)$profile['base_credits'] + (int)$profile['remaining_credits'] ?>
                − <?= number_format((float)$profile['base_points'], 1) ?>) ÷ <?= (int)$profile['remaining_credits'] ?> TC
            </div>
        </div>
        <?php if (!empty($facts['strengths'])): ?>
        <div style="padding:11px 14px;border-radius:10px;background:#fbfdff;border:1px solid #e6ecf5;">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Điểm mạnh</div>
            <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:5px;">
                <?php foreach ($facts['strengths'] as $s): ?>
                <span class="chip chip--green"><?= h($s['code']) ?> <?= h($s['letter']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($facts['weaknesses'])): ?>
        <div style="padding:11px 14px;border-radius:10px;background:#fbfdff;border:1px solid #e6ecf5;">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Cần chú ý</div>
            <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:5px;">
                <?php foreach ($facts['weaknesses'] as $w): ?>
                <span class="chip chip--red"><?= h($w['code']) ?> <?= h($w['letter']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="narr"><?= h($narrative['text']) ?></div>
    <?php if (!empty($narrative['at'])): ?>
    <div style="font-size:11px;color:#94a3b8;margin-top:8px;">Phân tích lúc <?= h($narrative['at']) ?> cho mục tiêu <?= number_format((float)$narrative['target'], 2) ?>.</div>
    <?php endif; ?>
    <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
        ⚠️ Các con số dự báo là ước tính từ dữ liệu CSDL, không phải kết quả GPA chính thức.
    </div>
</div>

<!-- ═══ 4. ROADMAP ═════════════════════════════════════════════ -->
<div class="card" style="padding:20px 24px;">
    <div class="sec-head">
        <div>
            <h2 class="sec-title"><span class="sec-num">4</span> Lộ trình học kỳ được cá nhân hóa</h2>
            <p class="sec-sub">Chia <?= (int)$profile['remaining_credits'] ?> tín chỉ còn lại thành các kỳ với GPA mục tiêu từng kỳ</p>
        </div>
        <?php if ($currentSemCredits > 0): ?>
        <span class="chip chip--blue">Học kỳ hiện tại: <?= $currentSemCredits ?> TC</span>
        <?php endif; ?>
    </div>

    <?php if (empty($roadmap)): ?>
    <div style="font-size:13px;color:#64748b;padding:10px 0;">Không còn tín chỉ kế hoạch – bạn đã hoàn thành chương trình theo cấu hình hiện tại.</div>
    <?php else: ?>
    <div class="roadmap-grid">
        <div class="timeline">
            <?php foreach ($roadmap as $r): ?>
            <div class="tl-item <?= $r['reaches_target'] ? 'done' : '' ?>">
                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <strong style="font-size:13px;color:#001634;"><?= h($r['label']) ?></strong>
                    <span class="chip <?= $r['reaches_target'] ? 'chip--green' : 'chip--amber' ?>">
                        GPA tích lũy dự kiến <?= number_format((float)$r['projected_cum'], 2) ?>
                    </span>
                </div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:6px;font-size:12px;color:#475569;">
                    <span>📚 <strong><?= (int)$r['credits'] ?> TC</strong></span>
                    <span>🎯 GPA kỳ cần đạt <strong style="color:#102b50;"><?= number_format((float)$r['target_gpa'], 2) ?></strong></span>
                    <span><?= $r['reaches_target'] ? '✅ Đạt mục tiêu tích lũy' : '⏳ Chưa chạm mục tiêu' ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div>
            <div style="border:1px solid #e6ecf5;border-radius:12px;padding:10px 12px 4px;background:#fff;">
                <div style="font-size:11.5px;font-weight:700;color:#475569;margin-bottom:6px;">GPA tích lũy: thực tế &amp; kế hoạch</div>
                <div style="height:260px;"><canvas id="gpaChartCanvas"></canvas></div>
            </div>
            <div style="font-size:10.5px;color:#94a3b8;margin-top:6px;">Nét đứt = dự báo theo lộ trình; đường đỏ = mục tiêu <?= number_format($targetGpa, 2) ?>.</div>
        </div>
    </div>

    <div style="margin-top:18px;">
        <div style="font-size:12.5px;font-weight:800;color:#001634;margin-bottom:8px;">Điểm mục tiêu cho từng môn</div>
        <?php if (empty($courseRecs)): ?>
        <div style="font-size:12.5px;color:#64748b;">Không có môn nào trong chương trình cần lên kế hoạch.</div>
        <?php else: ?>
        <div style="overflow:auto;max-height:340px;border:1px solid #eef2f7;border-radius:10px;">
            <table class="tbl">
                <thead><tr>
                    <th>Môn học</th><th>TC</th><th>Trạng thái</th><th>Điểm tối thiểu</th><th>GPA mục tiêu</th><th>Ưu tiên</th>
                </tr></thead>
                <tbody>
                <?php foreach ($courseRecs as $rec): ?>
                <tr>
                    <td>
                        <div style="font-weight:700;color:#001634;"><?= h($rec['course_code']) ?></div>
                        <div style="font-size:11px;color:#64748b;"><?= h($rec['course_name']) ?></div>
                    </td>
                    <td><?= (int)$rec['credits'] ?></td>
                    <td>
                        <span class="chip <?= $rec['is_enrolled'] ? 'chip--blue' : 'chip--slate' ?>">
                            <?= $rec['is_enrolled'] ? 'Đang học' : 'Sắp học' ?>
                        </span>
                    </td>
                    <td><strong><?= h($rec['target_letter']) ?></strong> · <?= number_format((float)$rec['min_score_100'], 0) ?>/100</td>
                    <td><?= number_format((float)$rec['target_gpa'], 1) ?></td>
                    <td><span class="chip <?= $rec['priority'] === 'Cao' ? 'chip--amber' : 'chip--slate' ?>"><?= h($rec['priority']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ 5. RETAKE RECOMMENDATIONS ═══════════════════════════════ -->
<div class="card" style="padding:20px 24px;">
    <div class="sec-head">
        <div>
            <h2 class="sec-title"><span class="sec-num">5</span> Môn nên cải thiện điểm</h2>
            <p class="sec-sub">
                Chỉ các môn dưới ngưỡng <?= number_format((float)$profile['pass_gpa'], 2) ?> và đủ điều kiện theo chính sách
                "<?= h($profile['policy_label']) ?>" (điểm hiện tại &lt; <?= number_format((float)$profile['retake_max_gpa'], 2) ?>)
            </p>
        </div>
    </div>

    <?php if (empty($candidates)): ?>
    <div style="font-size:13px;color:#475569;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:12px 14px;">
        ✅ Không có môn nào dưới ngưỡng đạt trong hồ sơ hiện tại – không cần cải thiện điểm cũ.
    </div>
    <?php else: ?>
    <div style="overflow:auto;border:1px solid #eef2f7;border-radius:10px;">
        <table class="tbl">
            <thead><tr>
                <th>Môn học</th><th>TC</th><th>Điểm hiện tại</th><th>Lần học</th><th>Điều kiện</th>
                <th>Cải thiện lên A</th><th>Ưu tiên</th><th>Thao tác</th>
            </tr></thead>
            <tbody>
            <?php foreach ($candidates as $c): ?>
            <?php
            $prioClass = match ($c['priority']) {
                'Rất nên cải thiện'      => 'chip--red',
                'Cần cải thiện (chưa đủ)' => 'chip--amber',
                'Nên cải thiện'          => 'chip--amber',
                'Không đủ điều kiện'     => 'chip--slate',
                default                  => 'chip--slate',
            };
            $aOpt = null;
            foreach ($c['options'] as $o) if ($o['letter'] === 'A') $aOpt = $o;
            ?>
            <tr>
                <td>
                    <div style="font-weight:700;color:#001634;"><?= h($c['course_code']) ?></div>
                    <div style="font-size:11px;color:#64748b;"><?= h($c['course_name']) ?></div>
                </td>
                <td><?= (int)$c['credits'] ?></td>
                <td>
                    <strong style="color:#b91c1c;"><?= h($c['best_letter']) ?></strong>
                    <span style="color:#64748b;">(<?= number_format((float)$c['best_gpa'], 1) ?>)</span>
                </td>
                <td><?= (int)$c['attempt_count'] ?></td>
                <td>
                    <?php if ($c['eligible']): ?>
                    <span class="chip chip--green">Đủ điều kiện</span>
                    <?php else: ?>
                    <span class="chip chip--slate">Không đủ</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($aOpt): ?>
                    <strong style="color:#047857;">+<?= number_format((float)$aOpt['delta'], 2) ?></strong>
                    <span style="font-size:11px;color:#64748b;">GPA</span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><span class="chip <?= $prioClass ?>"><?= h($c['priority']) ?></span></td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn-ghost" style="padding:5px 9px;font-size:11px;"
                            onclick="openRetake('<?= h(strtoupper($c['course_code'])) ?>')">🔮 Mô phỏng</button>
                    <?php if ($c['eligible']): ?>
                    <button type="button" class="btn-ghost" style="padding:5px 9px;font-size:11px;"
                            onclick="prefillSim('<?= h(strtoupper($c['course_code'])) ?>')">⚖️ So sánh</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="font-size:11.5px;color:#64748b;margin-top:8px;">
        ℹ️ Cải thiện điểm chỉ áp dụng cho môn đủ điều kiện. Kết quả mô phỏng theo chính sách
        <strong><?= h($profile['policy_label']) ?></strong> và là ước tính, không phải GPA chính thức.
    </div>
    <?php endif; ?>
</div>

<!-- ═══ 6. WHAT-IF SIMULATION ═══════════════════════════════════ -->
<div class="card" id="sim-section" style="padding:20px 24px;">
    <div class="sec-head">
        <div>
            <h2 class="sec-title"><span class="sec-num">6</span> Mô phỏng kịch bản GPA</h2>
            <p class="sec-sub">So sánh các phương án theo cùng mục tiêu <?= number_format($targetGpa, 2) ?></p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;">
        <?php foreach ($presets as $ps): ?>
        <?php $pm = $feasMeta[$ps['feasibility']] ?? $feasMeta['no_data']; ?>
        <div class="sim-card <?= $ps['key'] === 'priority' ? 'hl' : '' ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <strong style="font-size:13px;color:#001634;"><?= h($ps['name']) ?></strong>
                <span class="chip" style="background:<?= $pm[3] ?>;color:<?= $pm[2] ?>;"><?= $pm[0] ?> <?= h($pm[1]) ?></span>
            </div>
            <div style="font-size:11.5px;color:#64748b;">
                <?= (int)$ps['improvement_count'] ?> môn cải thiện · <?= (int)$ps['improvement_credits'] ?> TC
                <?= (int)$ps['extra_credits'] > 0 ? ' · +' . (int)$ps['extra_credits'] . ' TC học lại' : '' ?>
            </div>
            <div style="display:flex;gap:14px;margin-top:4px;">
                <div>
                    <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">GPA tốt nghiệp</div>
                    <div style="font-size:21px;font-weight:800;color:#102b50;"><?= gf($ps['expected_gpa']) ?></div>
                </div>
                <div>
                    <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Cần đạt còn lại</div>
                    <div style="font-size:21px;font-weight:800;color:<?= $pm[2] ?>;"><?= gf($ps['required_future_gpa']) ?></div>
                </div>
            </div>
            <?php if (!empty($ps['improvements'])): ?>
            <div style="font-size:11px;color:#475569;">
                Cải thiện: <?= h(implode(', ', array_map(static fn($i) => $i['course_code'] . ' ' . $i['old_letter'] . '→' . number_format((float)$i['new_gpa'], 1), $ps['improvements']))) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:18px;border-top:1px solid #eef2f7;padding-top:16px;">
        <div style="font-size:13px;font-weight:800;color:#001634;margin-bottom:10px;">Tự tạo kịch bản</div>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="_action" value="simulate" />
            <input type="hidden" name="target" value="<?= number_format($targetGpa, 2, '.', '') ?>" />
            <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
                <div>
                    <label for="future_gpa" style="font-size:11.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px;">
                        GPA trung bình các kỳ còn lại
                    </label>
                    <input id="future_gpa" type="number" name="future_gpa" step="0.05" min="0" max="4"
                           value="<?= number_format((float)$simInput['future_gpa'], 2, '.', '') ?>"
                           style="width:120px;height:38px;border:1.5px solid #dbe4f0;border-radius:9px;padding:0 10px;font-size:13px;" />
                </div>
                <button type="submit" class="btn-solid" style="height:38px;">🧪 Chạy mô phỏng</button>
            </div>

            <?php $eligibleCands = array_values(array_filter($candidates, static fn($c) => $c['eligible'])); ?>
            <?php if (!empty($eligibleCands)): ?>
            <div style="font-size:11.5px;font-weight:700;color:#475569;margin-bottom:6px;">Chọn môn cải thiện (tùy chọn)</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;">
                <?php foreach ($eligibleCands as $c): ?>
                <?php $codeKey = strtoupper($c['course_code']); ?>
                <label style="display:flex;align-items:center;gap:8px;border:1px solid #e6ecf5;border-radius:9px;padding:7px 10px;font-size:12px;background:#fff;cursor:pointer;">
                    <input type="checkbox" name="retake[<?= h($codeKey) ?>]" value="A"
                           <?= isset($simInput['retakes'][$codeKey]) ? 'checked' : '' ?> />
                    <span style="flex:1;min-width:0;">
                        <strong><?= h($c['course_code']) ?></strong>
                        <span style="color:#64748b;">· <?= h($c['best_letter']) ?> → A</span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
                Mặc định cải thiện lên A (3,7). Kết quả chi tiết từng mức điểm xem tại nút “Mô phỏng” ở mục 5.
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($simResult !== null): ?>
    <div style="margin-top:16px;border:1px solid #fdcc35;background:#fffdf4;border-radius:12px;padding:15px 17px;">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">
            <strong style="font-size:13.5px;color:#001634;">Kết quả mô phỏng</strong>
            <?php $rm = $feasMeta[$simResult['feasibility']] ?? $feasMeta['no_data']; ?>
            <span class="chip" style="background:<?= $rm[3] ?>;color:<?= $rm[2] ?>;"><?= $rm[0] ?> <?= h($rm[1]) ?></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-top:12px;">
            <div><div style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">GPA tốt nghiệp dự kiến</div>
                <div style="font-size:22px;font-weight:800;color:#102b50;"><?= gf($simResult['expected_gpa']) ?></div></div>
            <div><div style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">GPA cần đạt còn lại</div>
                <div style="font-size:22px;font-weight:800;color:<?= $rm[2] ?>;"><?= gf($simResult['required_future_gpa']) ?></div></div>
            <div><div style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">GPA sau cải thiện</div>
                <div style="font-size:22px;font-weight:800;color:#001634;"><?= gf($simResult['post_retake_gpa']) ?></div></div>
            <div><div style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Khối lượng</div>
                <div style="font-size:15px;font-weight:800;color:#001634;margin-top:4px;">
                    <?= (int)$simResult['improvement_count'] ?> môn · <?= (int)$simResult['improvement_credits'] ?> TC
                    <?= (int)$simResult['extra_credits'] > 0 ? ' + ' . (int)$simResult['extra_credits'] . ' TC học lại' : '' ?>
                </div></div>
        </div>
        <div style="font-size:11.5px;color:#64748b;margin-top:10px;">
            Giả định GPA các kỳ còn lại = <strong><?= number_format((float)$simResult['future_gpa'], 2) ?></strong>.
            Kết quả là ước tính theo chính sách <?= h($profile['policy_label']) ?>.
        </div>
        <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;">
            <?= csrf_input() ?>
            <input type="hidden" name="_action" value="save_simulation" />
            <input type="hidden" name="target" value="<?= number_format($targetGpa, 2, '.', '') ?>" />
            <input type="hidden" name="future_gpa" value="<?= number_format((float)$simResult['future_gpa'], 2, '.', '') ?>" />
            <input type="hidden" name="retakes_json" value="<?= h(json_encode(
                array_map(static fn($i) => ['course_code' => $i['course_code'], 'new_gpa' => $i['new_gpa']], $simResult['improvements']),
                JSON_UNESCAPED_UNICODE)) ?>" />
            <input type="text" name="sim_name" required maxlength="150" placeholder="Tên kịch bản mô phỏng"
                   style="flex:1;min-width:180px;height:36px;border:1px solid #dbe4f0;border-radius:8px;padding:0 10px;font-size:12.5px;" />
            <button type="submit" class="btn-solid" style="height:36px;">💾 Lưu mô phỏng</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($savedSims)): ?>
    <div style="margin-top:18px;">
        <div style="font-size:12.5px;font-weight:800;color:#001634;margin-bottom:8px;">So sánh các mô phỏng đã lưu</div>
        <div style="overflow:auto;border:1px solid #eef2f7;border-radius:10px;">
            <table class="tbl">
                <thead><tr>
                    <th>Kịch bản</th><th>Mục tiêu</th><th>Cải thiện</th><th>GPA kỳ còn lại</th>
                    <th>GPA tốt nghiệp</th><th>Cần đạt</th><th>Đánh giá</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($savedSims as $s): ?>
                <?php $sm = $feasMeta[$s['feasibility']] ?? $feasMeta['no_data']; ?>
                <tr>
                    <td>
                        <div style="font-weight:700;color:#001634;"><?= h($s['scenario_name']) ?></div>
                        <div style="font-size:10.5px;color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($s['created_at'])) ?></div>
                    </td>
                    <td><?= number_format((float)$s['target_gpa'], 2) ?></td>
                    <td>
                        <?= count($s['improvements']) ?> môn / <?= (int)$s['improvement_credits'] ?> TC
                        <?php if (!empty($s['improvements'])): ?>
                        <div style="font-size:10.5px;color:#64748b;">
                            <?= h(implode(', ', array_map(static fn($i) => $i['course_code'] ?? '', $s['improvements']))) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((float)$s['future_gpa'], 2) ?></td>
                    <td><strong style="color:#102b50;"><?= gf($s['expected_gpa'] !== null ? (float)$s['expected_gpa'] : null) ?></strong></td>
                    <td><?= gf($s['required_future_gpa'] !== null ? (float)$s['required_future_gpa'] : null) ?></td>
                    <td><span class="chip" style="background:<?= $sm[3] ?>;color:<?= $sm[2] ?>;"><?= $sm[0] ?> <?= h($sm[1]) ?></span></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Xóa mô phỏng này?')">
                            <?= csrf_input() ?>
                            <input type="hidden" name="_action" value="delete_simulation" />
                            <input type="hidden" name="simulation_id" value="<?= (int)$s['simulation_id'] ?>" />
                            <button type="submit" class="btn-danger">🗑</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ 7. AI ASSISTANT ════════════════════════════════════════ -->
<div class="card" style="padding:20px 24px;">
    <div class="sec-head">
        <div>
            <h2 class="sec-title"><span class="sec-num">7</span> Trợ lý học vụ</h2>
            <p class="sec-sub">Hỏi về mục tiêu, môn cần cải thiện, kỳ tới hoặc khả năng tốt nghiệp – câu trả lời dùng đúng dữ liệu của bạn</p>
        </div>
        <?php if (!empty($chat)): ?>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="_action" value="clear_chat" />
            <button type="submit" class="btn-ghost" style="padding:5px 10px;font-size:11px;">🧹 Xóa hội thoại</button>
        </form>
        <?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px;">
        <?php if (!empty($chat)): ?>
        <div class="chat-q"><?= h($chat['q']) ?></div>
        <div class="chat-a"><?= h($chat['a']) ?></div>
        <div style="font-size:10.5px;color:#94a3b8;align-self:flex-start;">Trả lời lúc <?= h($chat['at'] ?? '') ?>
            · mục tiêu <?= number_format((float)($chat['target'] ?? $targetGpa), 2) ?> · từ dữ liệu thực tế</div>
        <?php else: ?>
        <div style="font-size:12.5px;color:#64748b;background:#fbfdff;border:1px dashed #dbe4f0;border-radius:10px;padding:12px 14px;">
            👋 Hãy đặt câu hỏi hoặc chọn một gợi ý bên dưới. Câu trả lời được tính trực tiếp từ điểm, tín chỉ và chính sách hiện hành
            của bạn; AI không tự bịa điểm hay môn học.
        </div>
        <?php endif; ?>
    </div>

    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
        <?php
        $suggestions = [
            'Làm sao để tốt nghiệp với GPA 3.2?',
            'Nên cải thiện môn nào trước?',
            'Nếu tất cả môn còn lại đạt A thì sao?',
            'Tôi còn có thể tốt nghiệp với GPA 3.6 không?',
            'Nên cải thiện điểm D hay tập trung môn còn lại?',
            'Học kỳ tới tôi nên đạt GPA bao nhiêu?',
        ];
        foreach ($suggestions as $q): ?>
        <button type="button" class="qchip" data-q="<?= h($q) ?>"><?= h($q) ?></button>
        <?php endforeach; ?>
    </div>

    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
        <?= csrf_input() ?>
        <input type="hidden" name="_action" value="chat" />
        <input type="hidden" name="target" value="<?= number_format($targetGpa, 2, '.', '') ?>" />
        <input id="chatQ" type="text" name="question" maxlength="500" required
               placeholder="VD: Cần làm gì để đạt GPA 3.2 khi tốt nghiệp?"
               style="flex:1;min-width:220px;height:40px;border:1.5px solid #dbe4f0;border-radius:10px;padding:0 12px;font-size:13px;" />
        <button type="submit" class="btn-solid" style="height:40px;">Hỏi trợ lý →</button>
    </form>
    <div style="font-size:10.5px;color:#94a3b8;margin-top:8px;">
        Câu trả lời gồm: dữ liệu thực tế · tính toán · khuyến nghị · lưu ý. Đây không phải kết quả học vụ chính thức.
    </div>
</div>

<!-- ═══ GRADE SCALE REFERENCE ══════════════════════════════════ -->
<div class="card" style="padding:18px 22px;">
    <details>
        <summary style="font-size:13px;font-weight:800;color:#001634;cursor:pointer;user-select:none;">
            📖 Thang điểm quy đổi (GradeScale – đọc từ CSDL)
        </summary>
        <table class="tbl" style="margin-top:10px;max-width:620px;">
            <thead><tr><th>Điểm chữ</th><th>Thang 10</th><th>Thang 100</th><th>GPA (thang 4)</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($scale) as $gs): ?>
            <tr>
                <td><strong style="color:#001634;font-size:13px;"><?= h($gs['letter_grade']) ?></strong></td>
                <td><?= number_format((float)$gs['min_score'], 2) ?> – <?= number_format((float)$gs['max_score'], 2) ?></td>
                <td><?= number_format((float)$gs['min_score'] * 10, 0) ?> – <?= number_format((float)$gs['max_score'] * 10, 0) ?></td>
                <td><strong><?= number_format((float)$gs['grade_point'], 1) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</div>

<!-- Retake simulation modal -->
<div class="modal-bg" id="retakeModal" onclick="if(event.target===this)closeRetake()">
    <div class="modal-box">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px;">
            <div>
                <div id="rmTitle" style="font-size:16px;font-weight:800;color:#001634;">Mô phỏng cải thiện</div>
                <div id="rmSub" style="font-size:11.5px;color:#64748b;margin-top:2px;"></div>
            </div>
            <button type="button" class="btn-ghost" onclick="closeRetake()">✕</button>
        </div>
        <div id="rmBody"></div>
        <div style="font-size:11px;color:#64748b;margin-top:12px;border-top:1px solid #eef2f7;padding-top:10px;">
            Ước tính theo chính sách <strong><?= h($profile['policy_label']) ?></strong>; GPA “cần đạt còn lại” giả định các tín chỉ còn lại giữ GPA kỳ vọng.
            Đây không phải kết quả chính thức.
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    const RETAKE = <?= $retakeJson ?>;
    const fmt = v => (v === null || v === undefined || isNaN(v)) ? '—' : Number(v).toFixed(2);

    window.openRetake = function (code) {
        const d = RETAKE[code];
        if (!d) return;
        document.getElementById('rmTitle').textContent = d.code + ' – ' + d.name;
        document.getElementById('rmSub').textContent =
            d.credits + ' TC · hiện ' + d.current + ' (' + Number(d.current_gp).toFixed(1) + ') · ' +
            d.attempts + ' lần học · ' + (d.eligible ? 'đủ điều kiện cải thiện' : 'không đủ điều kiện');

        const body = document.getElementById('rmBody');
        body.textContent = '';

        const note = document.createElement('div');
        note.style.cssText = 'font-size:12px;color:#475569;background:#f8fbff;border:1px solid #e6ecf5;border-radius:9px;padding:9px 12px;margin-bottom:10px;';
        note.textContent = d.reason;
        body.appendChild(note);

        if (!d.options || !d.options.length) {
            const p = document.createElement('div');
            p.style.cssText = 'font-size:12.5px;color:#64748b;';
            p.textContent = 'Không có mức điểm cao hơn điểm hiện tại trong thang điểm.';
            body.appendChild(p);
            document.getElementById('retakeModal').style.display = 'flex';
            return;
        }

        const wrap = document.createElement('div');
        wrap.style.cssText = 'overflow:auto;border:1px solid #eef2f7;border-radius:10px;';
        const table = document.createElement('table');
        table.className = 'tbl';
        table.innerHTML = '<thead><tr><th>Điểm chữ</th><th>Thang 100</th><th>GPA tăng</th>' +
            '<th>GPA tích lũy</th><th>GPA tốt nghiệp dự kiến</th><th>GPA cần đạt còn lại</th></tr></thead>';
        const tbody = document.createElement('tbody');
        d.options.forEach(o => {
            const tr = document.createElement('tr');
            const cells = [
                o.letter,
                Number(o.min_score).toFixed(0) + '/100',
                '+' + Number(o.delta).toFixed(2),
                fmt(o.new_gpa),
                fmt(o.expected),
                fmt(o.required)
            ];
            cells.forEach((val, i) => {
                const td = document.createElement('td');
                td.textContent = val;
                if (i === 0) { td.style.fontWeight = '800'; td.style.color = '#001634'; }
                if (i === 2) { td.style.color = '#047857'; td.style.fontWeight = '700'; }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        body.appendChild(wrap);

        if (d.eligible) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-solid';
            btn.style.marginTop = '12px';
            btn.textContent = '⚖️ Đưa vào mô phỏng kịch bản';
            btn.onclick = function () { closeRetake(); prefillSim(code); };
            body.appendChild(btn);
        }

        document.getElementById('retakeModal').style.display = 'flex';
    };

    window.closeRetake = function () {
        document.getElementById('retakeModal').style.display = 'none';
    };

    window.prefillSim = function (code) {
        const box = document.querySelector('input[name="retake[' + code + ']"]');
        if (box) { box.checked = true; }
        const sec = document.getElementById('sim-section');
        if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    document.querySelectorAll('.qchip').forEach(chip => {
        chip.addEventListener('click', () => {
            const input = document.getElementById('chatQ');
            if (!input) return;
            input.value = chip.dataset.q || chip.textContent;
            input.focus();
        });
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') window.closeRetake();
    });

    // ── Chart ────────────────────────────────────────────────
    const canvas = document.getElementById('gpaChartCanvas');
    if (!canvas || typeof Chart === 'undefined') return;

    const labels  = <?= $jsLabels ?>;
    const actSem  = <?= $jsActSem ?>;
    const actCum  = <?= $jsActCum ?>;
    const planSem = <?= $jsPlanSem ?>;
    const planCum = <?= $jsPlanCum ?>;
    const target  = <?= number_format($targetGpa, 2, '.', '') ?>;

    Chart.defaults.font.family = "'Inter', 'Be Vietnam Pro', sans-serif";
    Chart.defaults.font.size = 10;
    Chart.defaults.color = '#64748b';

    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'GPA học kỳ (thực tế)',
                    data: actSem,
                    borderColor: '#102b50',
                    backgroundColor: 'rgba(16,43,80,.08)',
                    borderWidth: 2,
                    tension: .3,
                    pointRadius: 4,
                    pointBackgroundColor: '#102b50',
                    spanGaps: false
                },
                {
                    label: 'GPA học kỳ (kế hoạch)',
                    data: planSem,
                    borderColor: '#2563eb',
                    borderWidth: 2,
                    borderDash: [6, 4],
                    tension: .3,
                    pointRadius: 4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#2563eb',
                    pointBorderWidth: 2,
                    spanGaps: false
                },
                {
                    label: 'GPA tích lũy (thực tế)',
                    data: actCum,
                    borderColor: '#fdcc35',
                    backgroundColor: 'rgba(253,204,53,.12)',
                    borderWidth: 2.5,
                    tension: .3,
                    pointRadius: 4,
                    pointBackgroundColor: '#fdcc35',
                    spanGaps: false
                },
                {
                    label: 'GPA tích lũy (kế hoạch)',
                    data: planCum,
                    borderColor: '#7c3aed',
                    borderWidth: 2.5,
                    borderDash: [6, 4],
                    tension: .3,
                    pointRadius: 4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#7c3aed',
                    pointBorderWidth: 2,
                    spanGaps: false
                },
                {
                    label: 'Mục tiêu ' + target.toFixed(2),
                    data: new Array(labels.length).fill(target),
                    borderColor: 'rgba(239,68,68,.5)',
                    borderWidth: 1.5,
                    borderDash: [3, 3],
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: c => c.dataset.label + ': ' + (c.parsed.y !== null ? c.parsed.y.toFixed(2) : '—')
                    }
                }
            },
            scales: {
                y: {
                    min: 0, max: 4.1,
                    ticks: { stepSize: .5, callback: v => Number(v).toFixed(1) },
                    grid: { color: '#f1f5f9' }
                },
                x: {
                    ticks: { maxRotation: 40 },
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
<script src="../../assets/js/dashboard.js"></script>
</div><!-- /.db-content -->
</main><!-- /.db-main -->
</div><!-- /.db-layout -->
</body></html>
