<?php
// _chk_dup_addr.php — [일회용·소유자전용] 등록된 장소 중 주소/좌표 중복 점검 + 병합
//   서버에서 로그인 상태로 https://economist.kr/_chk_dup_addr.php 접속
//   각 그룹에서 대표(◉)를 고르고, 합칠 항목(☑)을 선택해 '통합' → 기사·태그를 대표로 이관 후 나머지 삭제
//   확인/정리 끝나면 이 파일은 삭제할 것(커밋 제외).
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();
header('Content-Type: text/html; charset=utf-8');

$CAT = ['travel' => '여행지', 'event' => '축제', 'restaurant' => '맛집', 'etc' => '기타'];
$NOTSYS = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(attributes,'$.system')),'') <> 'uncategorized'";

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fetchPlaces(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $sql = "SELECT p.id, p.name, p.category, p.address, p.lat, p.lng, p.geocode_status,
                   p.region_lv1, p.region_lv2,
                   (SELECT COUNT(*) FROM place_ref r WHERE r.place_id = p.id) AS ref_cnt
              FROM place p WHERE p.id IN ({$in}) ORDER BY p.id";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// 한 그룹 박스 렌더 (대표 라디오 + 병합 체크박스 + 통합 버튼)
function renderGroup(PDO $pdo, array $CAT, int $gi, string $headHtml, array $ids): void {
    $places = fetchPlaces($pdo, $ids);
    if (count($places) < 2) return;
    echo '<div class="grp" id="grp_' . $gi . '" onclick="grpClick(event,' . $gi . ')">';
    echo '<div class="grp-hd"><label class="gsel-l"><input type="checkbox" class="gsel" data-gi="' . $gi . '" onchange="updateBatchCount()"> 일괄대상</label> '
        . $headHtml . ' <span class="cnt">(' . count($places) . '곳)</span></div>';
    $first = true;
    foreach ($places as $p) {
        $reg = trim(($p['region_lv1'] ?? '') . ' ' . ($p['region_lv2'] ?? ''));
        echo '<div class="pl" onclick="rowSelect(event,' . $gi . ',' . (int)$p['id'] . ')">';
        echo '<label class="rep"><input type="radio" name="rep_' . $gi . '" value="' . (int)$p['id'] . '"' . ($first ? ' checked' : '') . ' onclick="selectRep(' . $gi . ',' . (int)$p['id'] . ')"> 대표</label>';
        echo '<label class="mg"><input type="checkbox" class="mg_' . $gi . '" value="' . (int)$p['id'] . '" checked> 합치기</label>';
        echo '<div class="pl-info">';
        echo '<b>#' . (int)$p['id'] . '</b> '
            . '<span class="cat">' . h($CAT[$p['category']] ?? $p['category']) . '</span> '
            . h($p['name'])
            . ' <span class="muted">· 출처 ' . (int)$p['ref_cnt'] . '건 · ' . h($p['geocode_status']) . ($reg ? ' · ' . h($reg) : '') . '</span>';
        if ($p['address']) echo '<div class="addr">📍 ' . h($p['address']) . '</div>';
        if ($p['lat'] !== null) echo '<div class="coord">(' . h($p['lat']) . ', ' . h($p['lng']) . ')</div>';
        echo '</div></div>';
        $first = false;
    }
    echo '<div class="grp-ft"><button class="mbtn" onclick="mergeGroup(' . $gi . ')">↪ 선택 항목을 대표로 통합</button>'
        . '<span class="grp-msg" id="msg_' . $gi . '"></span></div>';
    echo '</div>';
}

?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body { font-family: system-ui, "Apple SD Gothic Neo", sans-serif; max-width: 900px; margin: 18px auto; padding: 0 14px; line-height: 1.5; color: #2c3e50; }
  h2 { margin-bottom: 4px; }
  .warn { background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; padding: 10px 12px; font-size: 13px; color: #8a6d00; margin: 10px 0 18px; }
  h3 { margin-top: 26px; }
  .grp { border: 1px solid #e3e8ee; border-radius: 10px; padding: 10px 12px; margin: 9px 0; cursor: pointer; }
  .grp:has(.gsel:checked) { border-color: #2980b9; background: #f4f9fd; box-shadow: inset 0 0 0 1px #2980b9; }
  .grp-hd { font-weight: 700; margin-bottom: 6px; }
  .grp-hd .cnt { color: #c0392b; }
  .pl { display: flex; align-items: flex-start; gap: 8px; padding: 6px 6px; border-top: 1px dashed #eee; cursor: pointer; border-radius: 6px; }
  .pl:hover { background: #f7fbff; }
  .pl.sel-rep { background: #e3f1ff; box-shadow: inset 3px 0 0 #2980b9; }
  .pl .rep, .pl .mg { flex-shrink: 0; font-size: 11px; color: #5b6b7b; white-space: nowrap; cursor: pointer; user-select: none; }
  .pl .mg { color: #c0392b; }
  .pl-info { min-width: 0; flex: 1; }
  .cat { background: #eef; border-radius: 8px; padding: 1px 6px; font-size: 11px; }
  .muted { color: #9aa6b1; font-size: 12px; }
  .addr { color: #777; font-size: 12px; }
  .coord { color: #b3bcc4; font-size: 11px; }
  .grp-ft { margin-top: 8px; display: flex; align-items: center; gap: 10px; }
  .mbtn { background: #2980b9; border: none; color: #fff; font-size: 12.5px; font-weight: 600; padding: 6px 12px; border-radius: 7px; cursor: pointer; }
  .mbtn:hover { background: #2471a3; }
  .mbtn:disabled { background: #b8c4ce; cursor: default; }
  .grp-msg { font-size: 12.5px; font-weight: 600; }
  .done { color: #1e8449; }
  .err { color: #c0392b; }
  .gsel-l { font-size: 11px; color: #2980b9; cursor: pointer; user-select: none; margin-right: 4px; }
  .bar { position: sticky; top: 0; background: #fff; padding: 10px 0; margin-bottom: 6px; border-bottom: 2px solid #2980b9; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; z-index: 10; }
  .lbtn { background: #eef2f6; border: 1px solid #dde3e9; color: #5b6b7b; font-size: 12px; padding: 6px 10px; border-radius: 7px; cursor: pointer; }
  .lbtn:hover { background: #e1e8ef; }
</style>
<body>
<h2>🔍 장소 중복 점검 · 통합 <span style="font-size:12px;color:#27ae60;font-weight:400">[v4 · 행 클릭=대표선택]</span></h2>
<p style="color:#888">미분류 보관함 제외 · 활성 장소만</p>
<div class="warn">⚠️ <b>주의</b>: 아래 ‘같은 주소·좌표’ 묶음에는 <b>지오코딩이 한 지점으로 잘못 몰아넣은 서로 다른 장소</b>(예: 죽주산성 vs 안성팜랜드)가 섞여 있을 수 있습니다.
<b>진짜 같은 장소만</b> ☑ 합치기로 두고, 다른 장소는 체크를 해제하세요. 대표(◉)는 유지될 장소입니다. 통합 = 합칠 장소의 <b>기사·태그를 대표로 이관 후 그 장소 삭제</b>(되돌릴 수 없음).</div>

<div class="bar">
    <button class="mbtn" id="batchBtn" onclick="batchMerge()" disabled>↪ 선택한 0개 그룹 일괄 통합</button>
    <button class="lbtn" onclick="selectAllGroups(true)">표시된 그룹 전체 선택</button>
    <button class="lbtn" onclick="selectAllGroups(false)">전체 해제</button>
    <span style="color:#888;font-size:12px">그룹의 <b>일괄대상</b>에 체크 → 한 번에 통합(왕복 1회)</span>
</div>

<?php
// ── ① 같은 주소(공백 정리 후) 2건 이상 ──
$rows = $pdo->query(
    "SELECT TRIM(address) AS addr, COUNT(*) cnt, GROUP_CONCAT(id ORDER BY id) ids
       FROM place
      WHERE address IS NOT NULL AND TRIM(address) <> '' AND is_active = 1 AND {$NOTSYS}
      GROUP BY TRIM(address)
      HAVING cnt > 1
      ORDER BY cnt DESC, addr"
)->fetchAll(PDO::FETCH_ASSOC);

echo '<h3>① 같은 주소 — ' . count($rows) . '개 그룹</h3>';
if (!$rows) echo '<p class="done">같은 주소를 가진 장소가 없습니다 🎉</p>';
$gi = 0;
foreach ($rows as $g) {
    renderGroup($pdo, $CAT, $gi++, '📍 ' . h($g['addr']), explode(',', $g['ids']));
}

// ── ② 같은 좌표(소수 5자리 반올림) 2건 이상 ──
$rows2 = $pdo->query(
    "SELECT ROUND(lat,5) la, ROUND(lng,5) ln, COUNT(*) cnt, GROUP_CONCAT(id ORDER BY id) ids
       FROM place
      WHERE geocode_status = 'ok' AND lat IS NOT NULL AND lng IS NOT NULL AND is_active = 1 AND {$NOTSYS}
      GROUP BY ROUND(lat,5), ROUND(lng,5)
      HAVING cnt > 1
      ORDER BY cnt DESC"
)->fetchAll(PDO::FETCH_ASSOC);

echo '<h3>② 같은 좌표(≈11m 이내) — ' . count($rows2) . '개 그룹</h3>';
echo '<p style="color:#888;font-size:12px">주소 글자는 달라도 지도상 같은 지점에 찍히는 중복(병합 후보)</p>';
if (!$rows2) echo '<p class="done">같은 좌표의 장소가 없습니다 🎉</p>';
foreach ($rows2 as $g) {
    renderGroup($pdo, $CAT, $gi++, '🗺️ (' . h($g['la']) . ', ' . h($g['ln']) . ')', explode(',', $g['ids']));
}
?>
<hr style="margin:24px 0"><p style="color:#aaa;font-size:12px">정리가 끝나면 이 파일(_chk_dup_addr.php)은 삭제하세요.</p>

<script>
function mergeGroup(gi) {
    var rep = document.querySelector('input[name="rep_' + gi + '"]:checked');
    var msg = document.getElementById('msg_' + gi);
    if (!rep) { alert('대표(유지할 장소)를 선택하세요'); return; }
    var toId = parseInt(rep.value, 10);
    var boxes = document.querySelectorAll('.mg_' + gi + ':checked');
    var from = [];
    for (var i = 0; i < boxes.length; i++) {
        var v = parseInt(boxes[i].value, 10);
        if (v !== toId) from.push(v);
    }
    if (!from.length) { alert('통합할(☑ 합치기) 장소가 없습니다. 대표 외에 합칠 항목을 체크하세요.'); return; }
    if (!confirm('대표 #' + toId + ' 로 ' + from.length + '곳의 기사·태그를 통합하고\n그 ' + from.length + '곳은 삭제합니다. 진행할까요?')) return;

    var box = document.getElementById('grp_' + gi);
    var btn = box.querySelector('.mbtn');
    btn.disabled = true; msg.className = 'grp-msg'; msg.textContent = '통합 중…';
    fetch('place_api.php?module=place&action=place_merge&to_id=' + toId + '&from_ids=' + from.join(','))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { btn.disabled = false; msg.className = 'grp-msg err'; msg.textContent = (d && d.msg) || '통합 실패'; return; }
            box.remove();   // 완료된 그룹은 목록에서 제거
        })
        .catch(function () { btn.disabled = false; msg.className = 'grp-msg err'; msg.textContent = '통합 실패(네트워크)'; });
}

// 행(장소) 아무 데나 클릭 = 그 장소를 대표로 선택 (라디오 직접 안 눌러도). '합치기' 체크박스는 그대로 토글.
function rowSelect(e, gi, id) {
    if (e.target.closest('.mg')) { e.stopPropagation(); return; } // 합치기 체크박스/라벨은 토글만
    selectRep(gi, id);
    e.stopPropagation();   // 그룹 박스의 일괄대상 토글로 안 번지게
}
// 그 장소를 대표로 지정 + 같은 그룹 행 하이라이트 + 일괄대상 자동 체크
function selectRep(gi, id) {
    var radio = document.querySelector('input[name="rep_' + gi + '"][value="' + id + '"]');
    if (radio) radio.checked = true;
    var grp = document.getElementById('grp_' + gi);
    if (grp) {
        var rows = grp.querySelectorAll('.pl');
        for (var i = 0; i < rows.length; i++) rows[i].classList.remove('sel-rep');
        if (radio) { var row = radio.closest('.pl'); if (row) row.classList.add('sel-rep'); }
    }
    grpSelect(gi);
}

// 대표(라디오) 선택 시 '일괄대상' 자동 체크 (체크박스 직접 안 눌러도)
function grpSelect(gi) {
    var cb = document.querySelector('.gsel[data-gi="' + gi + '"]');
    if (cb && !cb.disabled && !cb.checked) { cb.checked = true; updateBatchCount(); }
}

// 그룹 박스를 클릭하면 '일괄대상' 자동 체크 토글 (내부 컨트롤 클릭은 제외)
function grpClick(e, gi) {
    if (e.target.closest('label, button, input, a')) return; // 대표/합치기/통합 버튼 등은 그대로
    var cb = document.querySelector('.gsel[data-gi="' + gi + '"]');
    if (!cb || cb.disabled) return;
    cb.checked = !cb.checked;
    updateBatchCount();
}

// ── 일괄 통합 ──
function gatherJob(gi) {
    var rep = document.querySelector('input[name="rep_' + gi + '"]:checked');
    if (!rep) return null;
    var toId = parseInt(rep.value, 10);
    var boxes = document.querySelectorAll('.mg_' + gi + ':checked');
    var from = [];
    for (var i = 0; i < boxes.length; i++) {
        var v = parseInt(boxes[i].value, 10);
        if (v !== toId) from.push(v);
    }
    if (!from.length) return null;
    return { gi: gi, to_id: toId, from_ids: from };
}
function selectedGroupGis() {
    var sel = document.querySelectorAll('.gsel:checked'), out = [];
    for (var i = 0; i < sel.length; i++) out.push(parseInt(sel[i].getAttribute('data-gi'), 10));
    return out;
}
function updateBatchCount() {
    var n = selectedGroupGis().length;
    var b = document.getElementById('batchBtn');
    b.textContent = '↪ 선택한 ' + n + '개 그룹 일괄 통합';
    b.disabled = (n === 0);
}
function selectAllGroups(on) {
    var sel = document.querySelectorAll('.gsel');
    for (var i = 0; i < sel.length; i++) { if (!sel[i].disabled) sel[i].checked = on; }
    updateBatchCount();
}
function applyResult(r) {
    var box = document.getElementById('grp_' + r.gi); if (!box) return;
    if (r.ok) {
        box.remove();   // 완료된 그룹은 목록에서 제거
    } else {
        var msg = document.getElementById('msg_' + r.gi);
        if (msg) { msg.className = 'grp-msg err'; msg.textContent = r.msg || '통합 실패'; }
    }
}
function batchMerge() {
    var gis = selectedGroupGis();
    if (!gis.length) { alert('일괄대상 그룹을 선택하세요'); return; }
    var jobs = [], skipped = 0;
    for (var i = 0; i < gis.length; i++) { var j = gatherJob(gis[i]); if (j) jobs.push(j); else skipped++; }
    if (!jobs.length) { alert('유효한 그룹이 없습니다(대표 ◉ 선택 + 합칠 ☑ 항목 확인).'); return; }
    var note = skipped ? ('\n(대표 미선택 또는 합칠 항목 없는 ' + skipped + '개 그룹은 제외)') : '';
    if (!confirm(jobs.length + '개 그룹을 일괄 통합합니다.\n각 그룹의 합칠 장소들이 대표로 합쳐지고 삭제됩니다(되돌릴 수 없음).' + note + '\n진행할까요?')) return;

    var btn = document.getElementById('batchBtn'); btn.disabled = true; var ot = btn.textContent; btn.textContent = '통합 중… (' + jobs.length + '개)';
    var fd = new FormData();
    fd.append('module', 'place'); fd.append('action', 'place_merge_batch'); fd.append('jobs', JSON.stringify(jobs));
    fetch('place_api.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false; btn.textContent = ot;
            if (!d || !d.ok) { alert((d && d.msg) || '일괄 통합 실패'); return; }
            var ok = 0, fail = 0;
            (d.results || []).forEach(function (r) { applyResult(r); if (r.ok) ok++; else fail++; });
            updateBatchCount();
            alert('완료: ' + ok + '개 그룹 통합' + (fail ? (' · 실패 ' + fail + '개') : ''));
        })
        .catch(function () { btn.disabled = false; btn.textContent = ot; alert('일괄 통합 실패(네트워크/타임아웃) — 더 적게 선택해 다시 시도하세요'); });
}

// 초기: 기본 선택(첫 행)된 대표 행에 하이라이트 표시
document.querySelectorAll('input[type=radio]:checked').forEach(function (r) {
    var row = r.closest('.pl'); if (row) row.classList.add('sel-rep');
});
</script>
</body>
