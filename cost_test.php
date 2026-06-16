<?php
// cost_test.php — 장소에 연결된 뉴스 본문으로 '장소/이름/키워드 추출' 비용을 점검한다.
//   모드 2가지:
//   ① 추정(estimate) — API 키 없이 동작. 본문 글자수로 토큰을 근사(±범위) → 비용 추정.   ※ 정확값 아님
//   ② 실측(api)      — ANTHROPIC_API_KEY 있을 때. 실제 Claude 호출 후 usage.input/output 토큰을 '그대로' 사용.
//
//   단가(1M 토큰당, USD): Haiku 4.5 = $1 in / $5 out, Sonnet 4.6 = $3 in / $15 out
//   본문 추출: ArdentNews::extractBodyText (크롤러 재사용). DB 적재 없음 — 결과·비용만 화면 출력.
//
//   /cost_test.php?place=삼악산호수케이블카              (키 없으면 자동 추정)
//   /cost_test.php?...&est=1                              (키 있어도 추정으로 보기)
//   /cost_test.php?...&run=1&model=haiku|sonnet|both      (실측: API 호출, 비용 발생)
//   &cpt=1.5 (한글 글자/토큰 가정) · &outtok=220 (가정 출력 토큰) · &id=N · &limit=N
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

if (file_exists("./env/anthropic.inc")) require_once "./env/anthropic.inc";
$API_KEY    = getenv('ANTHROPIC_API_KEY') ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');
$apiMissing = ($API_KEY === '');

$PRICING = [
    'claude-haiku-4-5-20251001' => ['in' => 1.00, 'out' => 5.00,  'label' => 'Haiku 4.5'],
    'claude-sonnet-4-6'         => ['in' => 3.00, 'out' => 15.00, 'label' => 'Sonnet 4.6'],
];
$MODEL_SET = [
    'haiku'  => ['claude-haiku-4-5-20251001'],
    'sonnet' => ['claude-sonnet-4-6'],
    'both'   => ['claude-haiku-4-5-20251001', 'claude-sonnet-4-6'],
];

// ── 파라미터 ──
$placeQ  = trim((string)($_GET['place'] ?? '삼악산호수케이블카'));
$placeId = (int)($_GET['id'] ?? 0);
$modelK  = in_array($_GET['model'] ?? 'haiku', ['haiku', 'sonnet', 'both'], true) ? $_GET['model'] : 'haiku';
$run     = (int)($_GET['run'] ?? 0) === 1;
$limit   = max(0, (int)($_GET['limit'] ?? 0));
$models  = $MODEL_SET[$modelK];
$estMode = $apiMissing || ((int)($_GET['est'] ?? 0) === 1) || !$run;  // 실측은 run=1 + 키 있을 때만
if ($apiMissing) $estMode = true;

// 추정 가정값(조절 가능)
$KO_CPT  = (float)($_GET['cpt']    ?? 1.5);   // 한글 1토큰당 글자수(낮을수록 토큰↑)
if ($KO_CPT < 0.5) $KO_CPT = 1.5;
$OUT_TOK = (int)($_GET['outtok'] ?? 220);     // 가정 출력 토큰(JSON 추출 결과 평균)
if ($OUT_TOK < 1) $OUT_TOK = 220;

// ── 장소 + refs ──
if ($placeId <= 0 && $placeQ !== '') {
    $st = $pdo->prepare("SELECT id FROM place WHERE name LIKE :kw ORDER BY (name = :exact) DESC, id LIMIT 1");
    $st->execute([':kw' => '%' . $placeQ . '%', ':exact' => $placeQ]);
    $placeId = (int)($st->fetchColumn() ?: 0);
}
$placeName = '';
if ($placeId > 0) {
    $st = $pdo->prepare("SELECT name FROM place WHERE id = ?");
    $st->execute([$placeId]);
    $placeName = (string)($st->fetchColumn() ?: '');
}
$placeObj = new Place($pdo);
$refs = $placeId > 0 ? $placeObj->getRefs($placeId) : [];
if ($limit > 0) $refs = array_slice($refs, 0, $limit);

$SYSTEM = "당신은 국내 여행 기사에서 '실제 방문 장소'를 추출하는 전문가입니다. "
        . "기사 본문에서 언급된 여행지/맛집/숙소 등 실제 장소를 모두 뽑아 이름·분류·주소(있으면)를 정리하고, "
        . "기사 전체의 대표 키워드(태그)와 방문하기 좋은 월을 추출합니다. 반드시 아래 JSON 형식으로만 답하세요.\n"
        . "{\"places\":[{\"name\":\"\",\"category\":\"travel|stay|restaurant|etc\",\"address\":\"\"}],\"keywords\":[\"\"],\"months\":[]}";

// ── 헬퍼 ──
function ct_classify(string $st): string { return $st === 'article' ? '기사' : ($st === 'youtube' ? '영상' : '기타'); }
function ct_usd(float $v, int $d = 6): string { return '$' . number_format($v, $d); }
function ct_esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ct_cost(int $in, int $out, array $p): float { return $in / 1e6 * $p['in'] + $out / 1e6 * $p['out']; }

$ardent = new ArdentNews(__DIR__ . '/cache/ardent');
function ct_body(ArdentNews $ardent, array $ref): array {
    $type = ct_classify($ref['source_type']);
    $title = (string)($ref['title'] ?? '');
    if ($type === '기사' && !empty($ref['url'])) {
        $idx = $ref['extra']['idxno'] ?? null;
        $key = $idx ? "view_{$idx}" : 'u_' . substr(sha1($ref['url']), 0, 16);
        $html = $ardent->httpGet($ref['url'], $key);
        $body = $html ? ArdentNews::extractBodyText($html) : '';
        if ($body !== '') return ['text' => $title . "\n\n" . $body, 'src' => '본문'];
    }
    $sum = (string)($ref['summary'] ?? '');
    return ['text' => trim($title . "\n" . $sum), 'src' => ($type === '기사' ? '제목+요약(본문실패)' : '제목+요약')];
}

// 글자 구성으로 토큰 근사. koCpt=한글 1토큰당 글자수(낮을수록 토큰 많음)
function ct_token_est(string $text, float $koCpt): float {
    preg_match_all('/[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $text, $mk);
    $ko = count($mk[0]);
    preg_match_all('/[A-Za-z0-9]/u', $text, $ma);
    $as = count($ma[0]);
    $other = max(0, mb_strlen($text) - $ko - $as);
    return $ko / $koCpt + $as / 4.0 + $other / 3.0;
}

// ── 실측(API) 호출 ──
function ct_call_claude(string $apiKey, string $model, string $system, string $userText, int $maxTokens = 1024): array {
    $payload = json_encode([
        'model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
        'messages' => [['role' => 'user', 'content' => $userText]],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'error' => 'cURL: ' . $cerr];
    $j = json_decode($resp, true);
    if ($code !== 200 || !is_array($j)) return ['ok' => false, 'error' => ($j['error']['message'] ?? ('HTTP ' . $code . ' ' . substr((string)$resp, 0, 200)))];
    $text = '';
    foreach (($j['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') $text .= $b['text']; }
    return ['ok' => true, 'input_tokens' => (int)($j['usage']['input_tokens'] ?? 0), 'output_tokens' => (int)($j['usage']['output_tokens'] ?? 0), 'text' => $text];
}

// ── 데이터 만들기 ──
$sysChars = mb_strlen($SYSTEM);
$rowsByRef = [];   // 공통: ref별 본문/타입/추정 토큰 (모델 무관)
foreach ($refs as $ref) {
    $b = ct_body($ardent, $ref);
    $full = $SYSTEM . "\n" . $b['text'];
    $rowsByRef[] = [
        'ref' => $ref, 'type' => ct_classify($ref['source_type']), 'bsrc' => $b['src'],
        'text' => $b['text'], 'blen' => mb_strlen($b['text']),
        'estIn'    => (int)round(ct_token_est($full, $KO_CPT)),         // mid
        'estInLo'  => (int)round(ct_token_est($full, 2.0)),            // 토큰 적게(낙관)
        'estInHi'  => (int)round(ct_token_est($full, 1.2)),            // 토큰 많게(비관)
    ];
}

// 실측 호출 (실측 모드일 때만)
$apiRows = [];   // apiRows[model][refIndex] = ['in','out','text','err']
if (!$estMode) {
    foreach ($models as $model) {
        foreach ($rowsByRef as $i => $rr) {
            if (trim($rr['text']) === '') { $apiRows[$model][$i] = ['err' => '본문/텍스트 없음']; continue; }
            $r = ct_call_claude($API_KEY, $model, $SYSTEM, $rr['text']);
            $apiRows[$model][$i] = empty($r['ok'])
                ? ['err' => $r['error']]
                : ['in' => $r['input_tokens'], 'out' => $r['output_tokens'], 'text' => $r['text']];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>추출 비용 <?= $estMode ? '추정' : '측정' ?> · <?= ct_esc($placeName ?: $placeQ) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
* { box-sizing: border-box; }
body { font-family:'Pretendard','Malgun Gothic',sans-serif; background:#f0f2f5; color:#2c3e50; margin:0; padding:22px; line-height:1.5; }
h1 { font-size:21px; margin:0 0 4px; } h2 { font-size:17px; margin:26px 0 8px; }
.sub { color:#7f8c8d; font-size:13px; margin-bottom:16px; }
.bar { background:#fff; border-radius:10px; padding:12px 16px; box-shadow:0 1px 4px rgba(0,0,0,.08); margin-bottom:16px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
.bar a { display:inline-block; text-decoration:none; font-size:13px; font-weight:600; padding:7px 13px; border-radius:7px; border:1px solid #cdd6de; color:#2c3e50; background:#fff; }
.bar a.run { background:#e74c3c; color:#fff; border-color:#c0392b; }
.bar a.on { background:#3498db; color:#fff; border-color:#2980b9; }
.warn { background:#fff5e6; border:1px solid #ffd79a; color:#a05a00; padding:12px 16px; border-radius:10px; margin-bottom:16px; font-size:13.5px; }
.err { background:#fdecea; border:1px solid #f5c6c0; color:#c0392b; padding:12px 16px; border-radius:10px; margin-bottom:16px; font-size:13.5px; }
.note { background:#eef6ff; border:1px solid #bfe0fb; color:#21618c; padding:12px 16px; border-radius:10px; margin-bottom:16px; font-size:13px; }
table { width:100%; border-collapse:collapse; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); font-size:13px; }
th, td { padding:9px 11px; text-align:right; border-bottom:1px solid #eef1f4; }
th:first-child, td:first-child, th.l, td.l { text-align:left; }
th { background:#f7f9fb; font-size:12px; color:#5b6b7b; }
tr:last-child td { border-bottom:none; }
tfoot td { font-weight:800; background:#f7f9fb; }
.num { font-variant-numeric:tabular-nums; }
.t-기사 { color:#16a085; font-weight:700; } .t-영상 { color:#c0392b; font-weight:700; } .t-기타 { color:#7f8c8d; font-weight:700; }
.summary { display:flex; flex-wrap:wrap; gap:14px; margin:12px 0 4px; }
.kpi { background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08); padding:12px 16px; min-width:160px; }
.kpi .k { font-size:12px; color:#7f8c8d; } .kpi .v { font-size:19px; font-weight:800; margin-top:3px; }
.kpi .band { font-size:11.5px; color:#9aa6b1; margin-top:2px; }
.card { background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08); padding:12px 14px; margin-bottom:10px; }
.card .ct { font-size:13.5px; font-weight:700; margin-bottom:6px; }
.card pre { background:#f7f9fb; border:1px solid #eef1f4; border-radius:7px; padding:10px; font-size:12px; overflow-x:auto; white-space:pre-wrap; margin:0; }
code { background:#eef1f4; padding:1px 6px; border-radius:5px; font-size:12px; }
form.inl { display:inline-flex; gap:6px; align-items:center; }
form.inl input { width:62px; font-size:12px; padding:5px 7px; border:1px solid #cdd6de; border-radius:6px; }
</style>
</head>
<body>
<h1>🧪 추출 비용 <?= $estMode ? '추정' : '실측' ?> — <?= ct_esc($placeName ?: $placeQ) ?><?= $placeId ? " <span style='color:#aaa;font-weight:400'>#{$placeId}</span>" : '' ?></h1>
<div class="sub">연결된 뉴스 본문으로 '장소·이름·키워드 추출' 비용을 <?= $estMode ? '글자수 기반으로 <b>추정</b>합니다(정확값 아님).' : 'API <b>usage 토큰</b>으로 계산합니다.' ?> (DB 적재 없음)</div>

<div class="bar">
    <?php foreach (['haiku' => 'Haiku 4.5', 'sonnet' => 'Sonnet 4.6', 'both' => '둘 다'] as $mk => $ml): ?>
        <a class="<?= $modelK === $mk ? 'on' : '' ?>" href="?place=<?= urlencode($placeQ) ?>&id=<?= $placeId ?>&model=<?= $mk ?><?= $estMode ? '&est=1' : '&run=1' ?>&cpt=<?= $KO_CPT ?>&outtok=<?= $OUT_TOK ?>"><?= $ml ?></a>
    <?php endforeach; ?>
    <span style="margin-left:6px">|</span>
    <?php if (!$apiMissing): ?>
        <a class="<?= $estMode ? '' : 'on' ?>" href="?place=<?= urlencode($placeQ) ?>&id=<?= $placeId ?>&model=<?= $modelK ?>&est=1">추정</a>
        <a class="run" href="?place=<?= urlencode($placeQ) ?>&id=<?= $placeId ?>&model=<?= $modelK ?>&run=1" onclick="return confirm('실제 API를 호출해 비용이 발생합니다. 진행할까요?')">▶ 실측(API 호출)</a>
    <?php endif; ?>
    <form class="inl" method="get" style="margin-left:auto">
        <input type="hidden" name="place" value="<?= ct_esc($placeQ) ?>"><input type="hidden" name="id" value="<?= $placeId ?>">
        <input type="hidden" name="model" value="<?= $modelK ?>"><input type="hidden" name="est" value="1">
        <label style="font-size:12px;color:#7f8c8d">한글/토큰</label><input type="number" step="0.1" name="cpt" value="<?= $KO_CPT ?>">
        <label style="font-size:12px;color:#7f8c8d">출력토큰</label><input type="number" name="outtok" value="<?= $OUT_TOK ?>">
        <button type="submit" style="font-size:12px;padding:6px 10px;border:1px solid #cdd6de;border-radius:6px;background:#fff;cursor:pointer">재추정</button>
    </form>
</div>

<?php if ($placeId <= 0): ?><div class="err">장소를 찾지 못했습니다. <code>?place=이름</code> 또는 <code>?id=숫자</code>.</div>
<?php elseif (!$refs): ?><div class="err">이 장소에 연결된 뉴스(ref)가 없습니다.</div><?php endif; ?>

<?php if ($estMode): ?>
    <div class="note">📐 <b>추정 모드</b>입니다(API 키 없이 계산). 토큰은 <b>실제 토크나이저와 다를 수 있어</b> 가정값에 따라 <b>±범위</b>로 표시합니다.
        현재 가정: 한글 <code><?= $KO_CPT ?></code>글자/토큰(범위 1.2~2.0), 가정 출력 <code><?= $OUT_TOK ?></code>토큰/건. 위 입력칸으로 조절하세요.
        <?php if ($apiMissing): ?><br>정확한 값이 필요하면 <code>ANTHROPIC_API_KEY</code>를 넣고 ▶ 실측을 쓰세요. (Anthropic의 <code>count_tokens</code>는 무료라 입력 토큰은 정확·무과금으로 셀 수 있습니다.)<?php endif; ?></div>
<?php endif; ?>

<?php if ($refs && $estMode):
    foreach ($models as $model):
        $price = $PRICING[$model];
        $sumInMid = $sumCostMid = $sumCostLo = $sumCostHi = 0.0; $n = 0;
        $byType = [];
        foreach ($rowsByRef as $rr) {
            if (trim($rr['text']) === '') continue;
            $n++;
            $cMid = ct_cost($rr['estIn'],   $OUT_TOK, $price);
            $cLo  = ct_cost($rr['estInLo'], $OUT_TOK, $price);
            $cHi  = ct_cost($rr['estInHi'], $OUT_TOK, $price);
            $sumInMid += $rr['estIn']; $sumCostMid += $cMid; $sumCostLo += $cLo; $sumCostHi += $cHi;
            $t = $rr['type'];
            $byType[$t] = $byType[$t] ?? ['cost' => 0.0, 'n' => 0];
            $byType[$t]['cost'] += $cMid; $byType[$t]['n']++;
        }
        $avg = $n ? $sumCostMid / $n : 0;
        $avgLo = $n ? $sumCostLo / $n : 0;
        $avgHi = $n ? $sumCostHi / $n : 0;
?>
    <h2><?= ct_esc($price['label']) ?> <span style="font-size:12px;color:#8a97a3;font-weight:400">($<?= number_format($price['in'],2) ?> in / $<?= number_format($price['out'],2) ?> out per 1M) — 추정</span></h2>
    <table>
        <thead><tr><th class="l">#</th><th class="l">제목</th><th class="l">종류</th><th>본문(자)</th><th>추정 input(범위)</th><th>가정 output</th><th>추정 비용</th></tr></thead>
        <tbody>
        <?php foreach ($rowsByRef as $i => $rr):
            if (trim($rr['text']) === '') { ?>
                <tr><td class="l"><?= $i+1 ?></td><td class="l"><?= ct_esc(mb_strimwidth((string)$rr['ref']['title'],0,48,'…')) ?></td><td class="l"><span class="t-<?= $rr['type'] ?>"><?= $rr['type'] ?></span></td><td colspan="4" class="l" style="color:#c0392b">본문/텍스트 없음</td></tr>
        <?php continue; }
            $cMid = ct_cost($rr['estIn'], $OUT_TOK, $price); ?>
            <tr>
                <td class="l"><?= $i+1 ?></td>
                <td class="l"><?= ct_esc(mb_strimwidth((string)$rr['ref']['title'],0,48,'…')) ?></td>
                <td class="l"><span class="t-<?= $rr['type'] ?>"><?= $rr['type'] ?></span></td>
                <td class="num"><?= number_format($rr['blen']) ?></td>
                <td class="num"><?= number_format($rr['estIn']) ?> <span style="color:#9aa6b1">(<?= number_format($rr['estInLo']) ?>~<?= number_format($rr['estInHi']) ?>)</span></td>
                <td class="num"><?= number_format($OUT_TOK) ?></td>
                <td class="num"><?= ct_usd($cMid) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td class="l" colspan="4">합계/평균 (<?= $n ?>건)</td><td class="num"><?= number_format($sumInMid) ?></td><td class="num"><?= number_format($OUT_TOK*$n) ?></td><td class="num">합 <?= ct_usd($sumCostMid) ?></td></tr></tfoot>
    </table>
    <div class="summary">
        <div class="kpi"><div class="k">평균 건당(추정)</div><div class="v"><?= ct_usd($avg) ?></div><div class="band"><?= ct_usd($avgLo) ?> ~ <?= ct_usd($avgHi) ?></div></div>
        <?php $art = (isset($byType['기사']) && $byType['기사']['n']) ? $byType['기사']['cost']/$byType['기사']['n'] : null;
              $vid = (isset($byType['영상']) && $byType['영상']['n']) ? $byType['영상']['cost']/$byType['영상']['n'] : null; ?>
        <div class="kpi"><div class="k">기사 평균 건당</div><div class="v"><?= $art!==null ? ct_usd($art)." <span style='font-size:12px;color:#8a97a3'>({$byType['기사']['n']}건)</span>" : '—' ?></div></div>
        <div class="kpi"><div class="k">영상 평균 건당</div><div class="v"><?= $vid!==null ? ct_usd($vid)." <span style='font-size:12px;color:#8a97a3'>({$byType['영상']['n']}건)</span>" : '—' ?></div></div>
        <div class="kpi"><div class="k">1,000건 예상 총비용</div><div class="v">$<?= number_format($avg*1000, 2) ?></div><div class="band">$<?= number_format($avgLo*1000,2) ?> ~ $<?= number_format($avgHi*1000,2) ?></div></div>
    </div>
<?php endforeach; ?>

<?php elseif ($refs && !$estMode): /* ── 실측 결과 ── */
    foreach ($models as $model):
        $price = $PRICING[$model]; $rows = $apiRows[$model] ?? [];
        $sumIn=$sumOut=0; $sumCost=0.0; $okN=0; $byType=[];
        foreach ($rowsByRef as $i => $rr) { $a=$rows[$i]??[]; if(!empty($a['err']))continue; $okN++; $sumIn+=$a['in']; $sumOut+=$a['out']; $c=ct_cost($a['in'],$a['out'],$price); $sumCost+=$c; $t=$rr['type']; $byType[$t]=$byType[$t]??['cost'=>0,'n'=>0]; $byType[$t]['cost']+=$c; $byType[$t]['n']++; }
        $avg=$okN?$sumCost/$okN:0;
?>
    <h2><?= ct_esc($price['label']) ?> <span style="font-size:12px;color:#8a97a3;font-weight:400">($<?= number_format($price['in'],2) ?> in / $<?= number_format($price['out'],2) ?> out per 1M) — 실측</span></h2>
    <table>
        <thead><tr><th class="l">#</th><th class="l">제목</th><th class="l">종류</th><th>input 토큰</th><th>output 토큰</th><th>건당 비용</th></tr></thead>
        <tbody>
        <?php foreach ($rowsByRef as $i => $rr): $a=$rows[$i]??[]; ?>
            <tr><td class="l"><?= $i+1 ?></td><td class="l"><?= ct_esc(mb_strimwidth((string)$rr['ref']['title'],0,48,'…')) ?></td><td class="l"><span class="t-<?= $rr['type'] ?>"><?= $rr['type'] ?></span></td>
            <?php if (!empty($a['err'])): ?><td colspan="3" class="l" style="color:#c0392b"><?= ct_esc($a['err']) ?></td>
            <?php else: ?><td class="num"><?= number_format($a['in']) ?></td><td class="num"><?= number_format($a['out']) ?></td><td class="num"><?= ct_usd(ct_cost($a['in'],$a['out'],$price)) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td class="l" colspan="3">합계/평균 (성공 <?= $okN ?>건)</td><td class="num"><?= number_format($sumIn) ?></td><td class="num"><?= number_format($sumOut) ?></td><td class="num">합 <?= ct_usd($sumCost) ?></td></tr></tfoot>
    </table>
    <div class="summary">
        <div class="kpi"><div class="k">평균 건당</div><div class="v"><?= ct_usd($avg) ?></div></div>
        <?php $art=(isset($byType['기사'])&&$byType['기사']['n'])?$byType['기사']['cost']/$byType['기사']['n']:null; $vid=(isset($byType['영상'])&&$byType['영상']['n'])?$byType['영상']['cost']/$byType['영상']['n']:null; ?>
        <div class="kpi"><div class="k">기사 평균 건당</div><div class="v"><?= $art!==null?ct_usd($art):'—' ?></div></div>
        <div class="kpi"><div class="k">영상 평균 건당</div><div class="v"><?= $vid!==null?ct_usd($vid):'—' ?></div></div>
        <div class="kpi"><div class="k">1,000건 예상 총비용</div><div class="v">$<?= number_format($avg*1000,2) ?></div></div>
    </div>
    <h2 style="margin-top:18px">추출 결과 (<?= ct_esc($price['label']) ?>)</h2>
    <?php foreach ($rowsByRef as $i => $rr): $a=$rows[$i]??[]; if(!empty($a['err']))continue; ?>
        <div class="card"><div class="ct"><?= $i+1 ?>. <?= ct_esc(mb_strimwidth((string)$rr['ref']['title'],0,68,'…')) ?>
            <span style="font-size:11.5px;color:#8a97a3;font-weight:400"> · in <?= number_format($a['in']) ?> / out <?= number_format($a['out']) ?> · <?= ct_usd(ct_cost($a['in'],$a['out'],$price)) ?></span></div>
            <pre><?= ct_esc($a['text']) ?></pre></div>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($refs && $modelK === 'both'): ?>
    <p class="sub" style="margin-top:14px">※ Haiku/Sonnet는 토큰 수가 같다고 가정(추정 모드)하므로 비용은 단가 비율(Sonnet ≈ Haiku ×3)로 벌어집니다. 품질 비교는 실측 모드의 추출 결과 카드로 대조하세요.</p>
<?php endif; ?>
</body>
</html>
<?php /* end cost_test.php */ ?>
