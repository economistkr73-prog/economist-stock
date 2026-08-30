<?php
/**
 * hotel/index.php — 아고다 호텔 가격 추적 (화면)
 *
 * 층이 둘이다 (설계 2026-08-20 · classes/Agoda.class 헤더 참조):
 *   ① 롤링(기본층) — 호텔만 등록하면 크론이 매일 D+30·1박·성인2 를 찍는다 (시세 추세)
 *   ② 특정일 추적 — 날짜를 지정하면 매일 «같은 날짜»를 다시 재서 가격 변동을 쌓는다.
 *                    목표가 알림(Pushover)은 이 층에만 있다.
 *
 * 화면은 «보기·등록»만 한다 — 적재는 크론(cron_job.php?task=agoda · 매일 08:40),
 * 즉석 조회는 등록 없이 지금 1콜(대략의 현재가 확인용 · DB 에 안 남는다).
 * 가격은 전부 <b>1박·세전 USD</b> — 아고다 API 가 USD 고정이라 KRW 로 못 받는다.
 *
 * DB 접근은 classes/Agoda.class 경유. 쓰기는 hotel/api.php.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/nav.inc';
require_login();

if (empty($_SESSION['ag_csrf'])) $_SESSION['ag_csrf'] = bin2hex(random_bytes(16));

$ag = new Agoda($pdo);

function ah(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ausd($v): string { return $v === null || $v === '' ? '-' : '$' . number_format((float)$v, 2); }

/* 표가 아직 없으면(스키마 미적용) 안내만 낸다 — 화면·API 는 DDL 을 하지 않는다 */
$schemaOk = true;
try { $hotels = $ag->hotelList(); } catch (Throwable $e) { $schemaOk = false; $hotels = []; }
$tracksAll = $schemaOk ? $ag->trackList() : [];
$tracksBy  = [];
foreach ($tracksAll as $t) $tracksBy[(int)$t['hotel_id']][] = $t;

echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
echo '<title>호텔 가격 추적</title>';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
nav_css();
echo <<<'CSS'
<style>
body { margin:0; font-family:'Segoe UI','Malgun Gothic',sans-serif; background:#f4f6f8; color:#2c3e50; }
.ag-body { max-width:1100px; margin:0 auto; padding:18px 14px 60px; }
.ag-note { font-size:12.5px; color:#7f8c8d; margin:6px 0 0; }
.ag-card { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:14px 16px; margin:14px 0; }
.ag-card h3 { margin:0 0 4px; font-size:16px; }
.ag-card h3 a { color:#2c3e50; text-decoration:none; }
.ag-card h3 a:hover { text-decoration:underline; }
.ag-meta { font-size:12px; color:#95a5a6; }
.ag-form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.ag-form input[type=text], .ag-form input[type=date] { padding:7px 9px; border:1px solid #cfd8e0; border-radius:6px; font-size:14px; }
.ag-form select { padding:7px 6px; border:1px solid #cfd8e0; border-radius:6px; font-size:14px; }
.ag-url { flex:1 1 340px; }
.ag-target { width:90px; }
button.ag-btn { padding:7px 14px; border:0; border-radius:6px; background:#2c7be5; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
button.ag-btn:hover { background:#1a68d1; }
button.ag-btn.gray { background:#8798a8; }
button.ag-btn.red { background:#e74c3c; }
button.ag-btn.sm { padding:4px 9px; font-size:12.5px; }
table.ag-t { width:100%; border-collapse:collapse; margin-top:8px; font-size:13.5px; }
table.ag-t th, table.ag-t td { padding:6px 8px; border-bottom:1px solid #eef2f5; text-align:left; white-space:nowrap; }
table.ag-t th { font-size:12px; color:#7f8c8d; font-weight:600; background:#f8fafc; }
.ag-price { font-weight:700; font-variant-numeric:tabular-nums; }
.ag-hit { color:#e74c3c; font-weight:700; }
.ag-roll { font-size:13px; color:#4a5b6c; margin:8px 0 2px; line-height:1.9; }
.ag-roll b { font-variant-numeric:tabular-nums; }
.ag-roll .up { color:#e74c3c; } .ag-roll .dn { color:#2c7be5; }
.ag-status { font-size:13px; margin-left:8px; }
.ag-status.err { color:#e74c3c; } .ag-status.ok { color:#27ae60; }
.ag-rooms { margin-top:8px; font-size:13px; background:#f8fafc; border-radius:6px; padding:8px 12px; display:none; }
.ag-rooms table { border-collapse:collapse; width:100%; }
.ag-rooms td { padding:3px 8px 3px 0; }
.ag-empty { color:#95a5a6; font-size:13.5px; padding:14px 4px; }
.ag-warn { background:#fdf3f2; border:1px solid #f5c6c2; color:#b03a2e; padding:12px 14px; border-radius:8px; font-size:14px; }
h2.ag-h { margin:20px 0 6px; font-size:17px; }
</style>
CSS;
echo '</head><body>';
render_nav('hotel');
echo '<div class="ag-body">';
echo '<h2 class="ag-h">🏨 호텔 가격 추적 <span style="font-size:12px;color:#95a5a6;font-weight:400">아고다 · 1박 세전 USD · 매일 08:40 크론 수집</span></h2>';

if (!$schemaOk) {
    echo '<div class="ag-warn">표가 아직 없습니다 — 서버에서 최초 1회: <code>/usr/local/php84/bin/php cron/agoda_track.php job=schema</code></div>';
    echo '</div></body></html>';
    exit;
}

/* ── 등록 폼 ─────────────────────────────────────────────── */
echo '<div class="ag-card">';
echo '<div class="ag-form">';
echo '<input type="text" id="agUrl" class="ag-url" placeholder="아고다 호텔 페이지 URL 붙여넣기 (날짜가 든 URL 이면 그 날짜를 추적)">';
echo '<input type="text" id="agTarget" class="ag-target" placeholder="목표가 $">';
echo '<button class="ag-btn" onclick="agAdd()">추가</button>';
echo '<span id="agAddSt" class="ag-status"></span>';
echo '</div>';
echo '<p class="ag-note">날짜 없는 URL → D+' . Agoda::ROLL_DAYS . ' 시세 추세만 쌓입니다 · 날짜 있는 URL → 그 날짜 추적 + 목표가 도달 시 Pushover (체크인 지나면 자동 종료)</p>';
echo '</div>';

/* ── 호텔 목록 ───────────────────────────────────────────── */
if (!$hotels) {
    echo '<div class="ag-empty">등록된 호텔이 없습니다 — 위에 아고다 URL 을 붙여넣어 시작하세요.</div>';
}
foreach ($hotels as $h) {
    $hid  = (int)$h['id'];
    $roll = $ag->rollSeries($hid, 14);
    echo '<div class="ag-card">';
    echo '<h3><a href="' . ah($h['url']) . '" target="_blank" rel="noopener">' . ah($h['name'] !== '' ? $h['name'] : '#' . $h['agoda_id']) . '</a>'
       . ' <button class="ag-btn red sm" style="float:right" onclick="agDel(' . $hid . ',' . json_encode((string)$h['name']) . ')">호텔 삭제</button></h3>';
    echo '<div class="ag-meta">agoda_id ' . (int)$h['agoda_id'] . ' · 등록 ' . ah(substr((string)$h['created_at'], 0, 10)) . '</div>';

    /* 롤링 추세 — 최근 14회 (잰 날 → D+30 가격) */
    echo '<div class="ag-roll">D+' . Agoda::ROLL_DAYS . ' 추세: ';
    if (!$roll) {
        echo '<span style="color:#95a5a6">아직 없음 — 다음 크론(매일 08:40)부터 쌓입니다</span>';
    } else {
        $parts = [];
        $prev  = null;   // 표시는 최신→과거지만 대비는 시간순으로 계산한다
        $chron = array_reverse($roll);
        $delta = [];
        foreach ($chron as $r) {
            $cur = $r['min_usd'] !== null ? (float)$r['min_usd'] : null;
            $delta[$r['d']] = ($cur !== null && $prev !== null) ? $cur - $prev : null;
            if ($cur !== null) $prev = $cur;
        }
        foreach ($roll as $r) {
            $v = $r['min_usd'] !== null ? '$' . number_format((float)$r['min_usd'], 0) : ($r['soldout'] ? '매진' : '-');
            $dl = $delta[$r['d']] ?? null;
            $cls = $dl === null ? '' : ($dl > 0 ? 'up' : ($dl < 0 ? 'dn' : ''));
            $parts[] = '<span class="' . $cls . '">' . ah(substr((string)$r['d'], 5)) . ' <b>' . $v . '</b></span>';
        }
        echo implode(' · ', $parts);
    }
    echo '</div>';

    /* 특정일 추적 표 */
    $trs = $tracksBy[$hid] ?? [];
    if ($trs) {
        echo '<table class="ag-t"><tr><th>체크인</th><th>조건</th><th>최신가 (잰 날)</th><th>목표가 $</th><th></th><th>이력</th><th></th></tr>';
        foreach ($trs as $t) {
            $tid = (int)$t['id'];
            $hit = $t['target_usd'] !== null && $t['last_usd'] !== null && (float)$t['last_usd'] <= (float)$t['target_usd'];
            $last = $t['last_usd'] !== null
                ? '<span class="ag-price' . ($hit ? ' ag-hit' : '') . '">' . ausd($t['last_usd']) . '</span>'
                  . ' <span class="ag-meta">(' . ah(substr((string)$t['last_d'], 5)) . ')</span>' . ($hit ? ' 🔔도달' : '')
                : ((string)$t['last_soldout'] === '1' ? '매진' : '<span class="ag-meta">수집 전</span>');
            $hist = [];
            foreach ($ag->trackSeries($tid, 10) as $s) {
                $hist[] = ah(substr((string)$s['d'], 5)) . ' ' . ($s['min_usd'] !== null ? '$' . number_format((float)$s['min_usd'], 0) : '매진');
            }
            echo '<tr>';
            echo '<td><b>' . ah((string)$t['checkin']) . '</b></td>';
            echo '<td>' . (int)$t['nights'] . '박 · ' . (int)$t['adults'] . '인</td>';
            echo '<td>' . $last . '</td>';
            echo '<td><input type="text" class="ag-target" style="width:70px" id="agTg' . $tid . '" value="' . ($t['target_usd'] !== null ? ah(number_format((float)$t['target_usd'], 2)) : '') . '"></td>';
            echo '<td><button class="ag-btn gray sm" onclick="agTarget(' . $tid . ')">저장</button></td>';
            echo '<td style="white-space:normal;font-size:12px;color:#7f8c8d">' . ($hist ? implode(' · ', $hist) : '-') . '</td>';
            echo '<td><button class="ag-btn red sm" onclick="agTrackDel(' . $tid . ')">종료</button></td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    /* 추적 추가 + 즉석 조회 */
    echo '<div class="ag-form" style="margin-top:10px">';
    echo '<input type="date" id="agCi' . $hid . '" min="' . date('Y-m-d') . '">';
    echo '<select id="agN' . $hid . '">';
    for ($i = 1; $i <= 7; $i++) echo '<option value="' . $i . '"' . ($i === 1 ? ' selected' : '') . '>' . $i . '박</option>';
    echo '</select>';
    echo '<select id="agA' . $hid . '">';
    for ($i = 1; $i <= 4; $i++) echo '<option value="' . $i . '"' . ($i === 2 ? ' selected' : '') . '>' . $i . '인</option>';
    echo '</select>';
    echo '<input type="text" id="agT' . $hid . '" class="ag-target" placeholder="목표가 $">';
    echo '<button class="ag-btn sm" onclick="agTrackAdd(' . $hid . ')">이 날짜 추적</button>';
    echo '<button class="ag-btn gray sm" onclick="agCheck(' . $hid . ')">지금 조회</button>';
    echo '<span id="agSt' . $hid . '" class="ag-status"></span>';
    echo '</div>';
    echo '<div class="ag-rooms" id="agRooms' . $hid . '"></div>';
    echo '</div>';
}

echo '</div>';
echo '<script>const AG_CSRF=' . json_encode($_SESSION['ag_csrf']) . ';</script>';
echo <<<'JS'
<script>
async function agPost(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', AG_CSRF);
    for (const k in data) fd.append(k, data[k]);
    const r = await fetch('/hotel/api.php', { method: 'POST', body: fd });
    return r.json();
}
function agSt(el, msg, err) {
    el.textContent = msg;
    el.className = 'ag-status ' + (err ? 'err' : 'ok');
}
async function agAdd() {
    const st = document.getElementById('agAddSt');
    const url = document.getElementById('agUrl').value.trim();
    if (!url) { agSt(st, 'URL 을 붙여넣으세요', 1); return; }
    agSt(st, '아고다 페이지에서 hotel_id 추출 중… (수 초)', 0);
    const j = await agPost('add', { url: url, target: document.getElementById('agTarget').value });
    if (j.ok) location.reload(); else agSt(st, j.msg, 1);
}
async function agDel(id, name) {
    if (!confirm((name || '#' + id) + ' — 호텔과 가격 이력을 전부 지웁니다. 계속할까요?')) return;
    const j = await agPost('del', { id: id });
    if (j.ok) location.reload(); else alert(j.msg);
}
async function agTrackAdd(hid) {
    const st = document.getElementById('agSt' + hid);
    const ci = document.getElementById('agCi' + hid).value;
    if (!ci) { agSt(st, '체크인 날짜를 고르세요', 1); return; }
    const j = await agPost('track_add', {
        hotel_id: hid, checkin: ci,
        nights: document.getElementById('agN' + hid).value,
        adults: document.getElementById('agA' + hid).value,
        target: document.getElementById('agT' + hid).value
    });
    if (j.ok) location.reload(); else agSt(st, j.msg, 1);
}
async function agTrackDel(id) {
    if (!confirm('이 날짜 추적을 종료할까요? (이력은 남습니다)')) return;
    const j = await agPost('track_del', { id: id });
    if (j.ok) location.reload(); else alert(j.msg);
}
async function agTarget(id) {
    const j = await agPost('target', { id: id, target: document.getElementById('agTg' + id).value });
    if (!j.ok) alert(j.msg);
}
async function agCheck(hid) {
    const st = document.getElementById('agSt' + hid);
    const box = document.getElementById('agRooms' + hid);
    const ci = document.getElementById('agCi' + hid).value;
    if (!ci) { agSt(st, '체크인 날짜를 고르세요', 1); return; }
    agSt(st, '아고다 조회 중… (2~5초)', 0);
    box.style.display = 'none';
    const q = new URLSearchParams({
        action: 'check', hotel: hid, checkin: ci,
        nights: document.getElementById('agN' + hid).value,
        adults: document.getElementById('agA' + hid).value
    });
    const r = await fetch('/hotel/api.php?' + q);
    const j = await r.json();
    if (!j.ok) { agSt(st, j.msg, 1); return; }
    agSt(st, j.min !== null ? ('최저 $' + Number(j.min).toFixed(2)) : '매진/판매 없음', 0);
    let html = '<b>' + j.checkin + '</b> 객실별 (1박·세전 USD — 등록 전 확인용, 저장 안 됨)<table>';
    for (const rm of j.rooms) {
        html += '<tr><td>' + rm.name + '</td><td style="text-align:right;font-weight:700">'
              + (rm.price !== null ? '$' + Number(rm.price).toFixed(2) : '-')
              + '</td><td style="color:#7f8c8d">' + (rm.cxl || '') + '</td></tr>';
    }
    html += '</table>';
    box.innerHTML = html;
    box.style.display = 'block';
}
</script>
JS;
echo '</body></html>';
?>
