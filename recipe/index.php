<?php
/**
 * recipe/index.php — 요리 레시피 모음 (화면)
 *
 * 무엇인가 (2026-08-31 · classes/Recipe.class 헤더 참조)
 *   유튜브·웹을 보다가 URL 하나만 붙여넣으면 제목·채널·썸네일이 자동으로 오고 태그가 붙는다.
 *   모바일이 주 무대 — 사람이 적는 칸은 URL 뿐, 나머지는 나중에 고친다.
 *
 * 주소 = 상태 (북마크·공유가 되게): ?tag=<태그id> · ?q=<검색어> · ?u=<URL>(붙여넣기 칸 미리 채움 — 공유 시트/단축어용)
 * 쓰기는 recipe/api.php. DB 접근은 classes/Recipe.class 경유.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/nav.inc';
require_login();

if (empty($_SESSION['rc_csrf'])) $_SESSION['rc_csrf'] = bin2hex(random_bytes(16));

$rc = new Recipe($pdo);
function rh(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$tagId = (int)($_GET['tag'] ?? 0);
$q     = trim((string)($_GET['q'] ?? ''));
$pre   = trim((string)($_GET['u'] ?? ''));

/* 표가 아직 없으면 안내만 — 화면은 DDL 을 하지 않는다 */
$schemaOk = true;
try { $tagsAll = $rc->tagsAll(); $rows = $rc->list($tagId, $q); $total = $rc->count(); }
catch (Throwable $e) { $schemaOk = false; $tagsAll = []; $rows = []; $total = 0; }

$activeTag = null;
$byKind = [];
foreach ($tagsAll as $t) {
    $byKind[$t['kind']][] = $t;
    if ((int)$t['id'] === $tagId) $activeTag = $t;
}
function rc_url(int $tag, string $q): string
{
    $p = [];
    if ($tag > 0) $p['tag'] = $tag;
    if ($q !== '') $p['q'] = $q;
    return '/recipe/index.php' . ($p ? '?' . http_build_query($p) : '');
}

echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
echo '<title>레시피 모음</title>';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
nav_css();
echo <<<'CSS'
<style>
body { margin:0; font-family:'Segoe UI','Malgun Gothic',sans-serif; background:#f4f6f8; color:#2c3e50; }
.rc-body { max-width:960px; margin:0 auto; padding:14px 12px 70px; }
h2.rc-h { margin:6px 0 10px; font-size:18px; display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; }
h2.rc-h small { font-size:12px; color:#95a5a6; font-weight:400; }
.rc-card { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px 14px; margin:0 0 12px; }
.rc-add { display:flex; gap:8px; align-items:center; }
.rc-add input { flex:1 1 auto; min-width:0; padding:10px 12px; border:1px solid #cfd8e0; border-radius:8px; font-size:15px; }
.rc-btn { padding:9px 14px; border:0; border-radius:8px; background:#2c7be5; color:#fff; font-size:14px; font-weight:600; cursor:pointer; white-space:nowrap; }
.rc-btn:disabled { opacity:.55; cursor:default; }
.rc-btn.gray { background:#8798a8; } .rc-btn.red { background:#e74c3c; } .rc-btn.sm { padding:5px 9px; font-size:12.5px; }
.rc-btn.ghost { background:#eef2f6; color:#2c3e50; }
.rc-status { font-size:13px; margin-top:8px; min-height:16px; color:#4a5b6c; }
.rc-status.err { color:#e74c3c; } .rc-status.ok { color:#27ae60; }
.rc-note { font-size:12px; color:#95a5a6; margin-top:6px; }
/* 필터 */
.rc-filter { display:flex; gap:8px; align-items:center; margin:12px 0 6px; }
.rc-filter input { flex:1 1 auto; min-width:0; padding:8px 10px; border:1px solid #cfd8e0; border-radius:8px; font-size:14px; }
.rc-kinds { margin:4px 0 10px; }
.rc-kind { display:flex; align-items:flex-start; gap:6px; margin:4px 0; font-size:12.5px; }
.rc-kl { flex:0 0 58px; color:#7f8c8d; padding-top:4px; font-weight:600; }
.rc-chips { display:flex; flex-wrap:wrap; gap:5px; }
.chip { display:inline-block; padding:3px 9px; border-radius:999px; font-size:12.5px; text-decoration:none; color:#2c3e50; background:#eef2f6; border:1px solid transparent; line-height:1.5; white-space:nowrap; }
.chip small { opacity:.6; margin-left:2px; }
.chip.k-type   { background:#e8f0fe; color:#1a4fa3; }
.chip.k-main   { background:#e6f6ec; color:#1f7a3f; }
.chip.k-method { background:#f0e9fb; color:#5b3a9e; }
.chip.k-level  { background:#fff1e0; color:#a5580b; }
.chip.k-etc    { background:#eef2f6; color:#4a5b6c; }
.chip.on { border-color:currentColor; font-weight:700; box-shadow:0 0 0 2px rgba(44,123,229,.15); }
.rc-active { display:flex; align-items:center; gap:8px; font-size:13px; margin:6px 0 10px; }
/* 카드 */
.rc-item { display:flex; gap:12px; background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:10px; margin-bottom:10px; }
.rc-th { flex:0 0 104px; width:104px; height:104px; border-radius:8px; overflow:hidden; background:#e9edf1; display:block; }
.rc-th img { width:100%; height:100%; object-fit:cover; display:block; }
.rc-th .noimg { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:30px; }
.rc-bd { flex:1 1 auto; min-width:0; }
.rc-t { font-size:15px; font-weight:700; color:#2c3e50; text-decoration:none; display:block; line-height:1.35; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.rc-t:hover { text-decoration:underline; }
.rc-m { font-size:12px; color:#7f8c8d; margin:3px 0 5px; }
.rc-m b { color:#2c3e50; font-size:13px; }
.rc-tags { display:flex; flex-wrap:wrap; gap:4px; }
.rc-memo { font-size:13px; color:#4a5b6c; margin-top:5px; white-space:pre-wrap; }
.rc-act { display:flex; gap:6px; margin-top:7px; align-items:center; }
.rc-act .rc-btn { padding:4px 8px; font-size:12px; }
.rc-empty { color:#95a5a6; font-size:14px; padding:22px 6px; text-align:center; }
.rc-warn { background:#fdf3f2; border:1px solid #f5c6c2; color:#b03a2e; padding:12px 14px; border-radius:8px; font-size:14px; }
/* 모달 */
.rc-ov { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:flex-end; justify-content:center; z-index:2000; }
.rc-ov.open { display:flex; }
.rc-md { background:#fff; width:100%; max-width:560px; border-radius:14px 14px 0 0; padding:14px 16px 18px; max-height:92vh; overflow:auto; }
@media (min-width:600px) { .rc-ov { align-items:center; } .rc-md { border-radius:12px; } }
.rc-md h3 { margin:0 0 10px; font-size:16px; }
.rc-md label { display:block; font-size:12px; color:#7f8c8d; margin:8px 0 3px; }
.rc-md input[type=text], .rc-md textarea, .rc-md select { width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #cfd8e0; border-radius:8px; font-size:14px; font-family:inherit; }
.rc-md textarea { min-height:64px; }
.rc-etags { display:flex; flex-wrap:wrap; gap:5px; margin:4px 0 6px; min-height:24px; }
.rc-etags .chip { cursor:pointer; }
.rc-etags .chip:after { content:' ×'; opacity:.6; }
.rc-tadd { display:flex; gap:6px; }
.rc-tadd input { flex:1 1 auto; } .rc-tadd select { flex:0 0 96px; width:auto; }
.rc-mact { display:flex; gap:8px; justify-content:flex-end; margin-top:14px; }
</style>
CSS;
echo '</head><body>';
render_nav('recipe');
echo '<div class="rc-body">';
echo '<h2 class="rc-h">🍳 레시피 모음 <small>' . $total . '개 · URL 만 붙여넣으면 제목·태그가 자동으로 붙습니다</small></h2>';

if (!$schemaOk) {
    echo '<div class="rc-warn">표가 아직 없습니다 — 최초 1회 <button class="rc-btn sm" onclick="rcSchema()">표 만들기</button></div>';
    echo '</div><script>const RC_CSRF=' . json_encode($_SESSION['rc_csrf']) . ';
async function rcSchema(){const fd=new FormData();fd.append("action","schema");fd.append("csrf",RC_CSRF);const r=await fetch("/recipe/api.php",{method:"POST",body:fd});const j=await r.json();alert(j.msg);if(j.ok)location.reload();}</script></body></html>';
    exit;
}

/* ── 등록 ─────────────────────────────────────────────── */
echo '<div class="rc-card">';
echo '<div class="rc-add">';
echo '<input type="url" id="rcUrl" inputmode="url" autocomplete="off" placeholder="유튜브·쇼츠·웹 레시피 URL 붙여넣기" value="' . rh($pre) . '">';
echo '<button class="rc-btn" id="rcAddBtn" onclick="rcAdd()">담기</button>';
echo '</div>';
echo '<div id="rcAddSt" class="rc-status"></div>';
echo '<div class="rc-note">제목·채널·썸네일은 유튜브에서, 태그는 AI 가 붙입니다(등록 때 한 번). 틀리면 카드의 ✏️ 로 고치세요.</div>';
echo '</div>';

/* ── 검색 + 태그 칩 ───────────────────────────────────── */
echo '<form class="rc-filter" method="get" action="/recipe/index.php">';
if ($tagId > 0) echo '<input type="hidden" name="tag" value="' . $tagId . '">';
echo '<input type="search" name="q" value="' . rh($q) . '" placeholder="검색 — 제목·요리명·채널·메모·태그">';
echo '<button class="rc-btn gray" type="submit">찾기</button>';
echo '</form>';

if ($tagsAll) {
    echo '<div class="rc-kinds">';
    foreach (Recipe::KINDS as $k => $label) {
        if (empty($byKind[$k])) continue;
        echo '<div class="rc-kind"><span class="rc-kl">' . rh($label) . '</span><div class="rc-chips">';
        foreach ($byKind[$k] as $t) {
            $on  = (int)$t['id'] === $tagId;
            $href = $on ? rc_url(0, $q) : rc_url((int)$t['id'], $q);
            echo '<a class="chip k-' . rh($k) . ($on ? ' on' : '') . '" href="' . rh($href) . '">' . rh($t['name']) . '<small>' . (int)$t['n'] . '</small></a>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}
if ($activeTag || $q !== '') {
    echo '<div class="rc-active">';
    echo '<span>' . count($rows) . '개';
    if ($activeTag) echo ' · 태그 <b>' . rh($activeTag['name']) . '</b>';
    if ($q !== '') echo ' · 검색 <b>' . rh($q) . '</b>';
    echo '</span>';
    if ($activeTag) echo '<button class="rc-btn ghost sm" onclick="rcTagEdit(' . (int)$activeTag['id'] . ',' . json_encode($activeTag['name'], JSON_UNESCAPED_UNICODE) . ',' . json_encode($activeTag['kind']) . ')">✏️ 태그</button>';
    echo '<a class="rc-btn ghost sm" style="text-decoration:none" href="/recipe/index.php">필터 풀기</a>';
    echo '</div>';
}

/* ── 목록 ─────────────────────────────────────────────── */
if (!$rows) {
    if ($total === 0) echo '<div class="rc-empty">아직 담은 레시피가 없습니다 — 위에 URL 을 붙여넣어 시작하세요.</div>';
    else echo '<div class="rc-empty">지금 조건에 맞는 레시피가 없습니다. <a href="/recipe/index.php">필터 풀기</a></div>';
}
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $j  = ['id' => $id, 'title' => $r['title'], 'dish' => $r['dish'], 'memo' => (string)($r['memo'] ?? ''),
           'tags' => array_map(fn($t) => ['name' => $t['name'], 'kind' => $t['kind']], $r['tags'])];
    echo '<div class="rc-item" id="rc' . $id . '" data-j="' . rh(json_encode($j, JSON_UNESCAPED_UNICODE)) . '">';
    echo '<a class="rc-th" href="' . rh($r['url']) . '" target="_blank" rel="noopener">';
    echo $r['thumb'] !== '' ? '<img src="' . rh($r['thumb']) . '" alt="" loading="lazy">' : '<span class="noimg">🍽️</span>';
    echo '</a>';
    echo '<div class="rc-bd">';
    echo '<a class="rc-t" href="' . rh($r['url']) . '" target="_blank" rel="noopener">' . rh($r['title']) . '</a>';
    echo '<div class="rc-m">';
    if ($r['dish'] !== '') echo '<b>' . rh($r['dish']) . '</b> · ';
    echo ($r['src'] === 'youtube' ? '▶ ' : '🔗 ') . rh($r['channel'] !== '' ? $r['channel'] : parse_url($r['url'], PHP_URL_HOST)) . ' · ' . rh(substr((string)$r['created_at'], 0, 10));
    echo '</div>';
    if ($r['tags']) {
        echo '<div class="rc-tags">';
        foreach ($r['tags'] as $t) {
            echo '<a class="chip k-' . rh($t['kind']) . ((int)$t['id'] === $tagId ? ' on' : '') . '" href="' . rh(rc_url((int)$t['id'], $q)) . '">' . rh($t['name']) . '</a>';
        }
        echo '</div>';
    }
    if (!empty($r['memo'])) echo '<div class="rc-memo">' . rh($r['memo']) . '</div>';
    echo '<div class="rc-act">';
    echo '<button class="rc-btn ghost" onclick="rcEdit(' . $id . ')">✏️ 고치기</button>';
    echo '<button class="rc-btn ghost" onclick="rcRetag(' . $id . ',this)">🏷 태그 다시</button>';
    echo '</div>';
    echo '</div></div>';
}
echo '</div>';

/* ── 고치기 모달 ──────────────────────────────────────── */
echo '<div class="rc-ov" id="rcOv" onclick="if(event.target===this)rcClose()"><div class="rc-md">';
echo '<h3>✏️ 레시피 고치기</h3>';
echo '<input type="hidden" id="eId">';
echo '<label>제목</label><input type="text" id="eTitle">';
echo '<label>요리명</label><input type="text" id="eDish" placeholder="예: 갈비탕">';
echo '<label>태그 (누르면 뺌)</label><div class="rc-etags" id="eTags"></div>';
echo '<div class="rc-tadd"><input type="text" id="eTagName" list="rcTagList" placeholder="태그 추가"><select id="eTagKind">';
foreach (Recipe::KINDS as $k => $label) echo '<option value="' . $k . '">' . rh($label) . '</option>';
echo '</select><button class="rc-btn gray sm" type="button" onclick="rcTagAdd()">＋</button></div>';
echo '<datalist id="rcTagList">';
foreach ($tagsAll as $t) echo '<option value="' . rh($t['name']) . '">';
echo '</datalist>';
echo '<label>메모</label><textarea id="eMemo" placeholder="내가 바꾼 재료·팁·다음에 해볼 것"></textarea>';
echo '<div class="rc-mact"><button class="rc-btn red" style="margin-right:auto" onclick="rcDel()">삭제</button><button class="rc-btn gray" onclick="rcClose()">닫기</button><button class="rc-btn" onclick="rcSave()">저장</button></div>';
echo '</div></div>';

/* 태그 이름·종류 고치기 모달 */
echo '<div class="rc-ov" id="rcTOv" onclick="if(event.target===this)this.classList.remove(\'open\')"><div class="rc-md">';
echo '<h3>✏️ 태그 고치기</h3><input type="hidden" id="tId">';
echo '<label>이름 (같은 이름이 있으면 그쪽으로 합쳐집니다)</label><input type="text" id="tName">';
echo '<label>종류</label><select id="tKind">';
foreach (Recipe::KINDS as $k => $label) echo '<option value="' . $k . '">' . rh($label) . '</option>';
echo '</select>';
echo '<div class="rc-mact"><button class="rc-btn gray" onclick="document.getElementById(\'rcTOv\').classList.remove(\'open\')">닫기</button><button class="rc-btn" onclick="rcTagSave()">저장</button></div>';
echo '</div></div>';

echo '<script>const RC_CSRF=' . json_encode($_SESSION['rc_csrf']) . ';const RC_KINDS=' . json_encode(Recipe::KINDS, JSON_UNESCAPED_UNICODE) . ';';
echo 'const RC_TAGKIND=' . json_encode(array_column($tagsAll, 'kind', 'name'), JSON_UNESCAPED_UNICODE) . ';</script>';
echo <<<'JS'
<script>
async function rcPost(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', RC_CSRF);
    for (const k in data) fd.append(k, data[k]);
    const r = await fetch('/recipe/api.php', { method: 'POST', body: fd });
    try { return await r.json(); } catch (e) { return { ok: false, msg: 'HTTP ' + r.status }; }
}
function rcSt(msg, cls) {
    const el = document.getElementById('rcAddSt');
    el.textContent = msg;
    el.className = 'rc-status ' + (cls || '');
}
async function rcAdd() {
    const inp = document.getElementById('rcUrl');
    const url = inp.value.trim();
    if (!url) { rcSt('URL 을 붙여넣으세요', 'err'); inp.focus(); return; }
    const btn = document.getElementById('rcAddBtn');
    btn.disabled = true;
    rcSt('제목을 받아오고 태그를 붙이는 중… (3~8초)');
    const j = await rcPost('add', { url: url });
    btn.disabled = false;
    if (!j.ok) { rcSt(j.msg, 'err'); return; }
    if (j.dup) {
        rcSt(j.msg, 'err');
        const el = document.getElementById('rc' + j.id);
        if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.style.boxShadow = '0 0 0 3px #f1c40f'; }
        return;
    }
    rcSt('담았습니다: ' + j.title + (j.tags.length ? ' — ' + j.tags.map(t => t.name).join(', ') : '') + (j.warn.length ? ' (' + j.warn.join(' · ') + ')' : ''), 'ok');
    setTimeout(() => location.href = '/recipe/index.php', 700);
}
document.getElementById('rcUrl').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); rcAdd(); } });
/* 붙여넣는 순간 바로 담기 — 모바일에서 버튼 한 번을 아낀다 (붙여넣은 값이 URL 모양일 때만) */
document.getElementById('rcUrl').addEventListener('paste', e => {
    const s = (e.clipboardData || window.clipboardData).getData('text').trim();
    if (/^https?:\/\/\S+$/i.test(s)) { e.preventDefault(); e.target.value = s; rcAdd(); }
});
if (document.getElementById('rcUrl').value) rcAdd();   /* ?u= 로 들어오면 바로 담는다 */

/* ── 고치기 ── */
let _e = { tags: [] };
function rcEdit(id) {
    const el = document.getElementById('rc' + id);
    const j = JSON.parse(el.getAttribute('data-j'));
    _e = { id: j.id, tags: j.tags.slice() };
    document.getElementById('eId').value = j.id;
    document.getElementById('eTitle').value = j.title;
    document.getElementById('eDish').value = j.dish;
    document.getElementById('eMemo').value = j.memo;
    rcTagsDraw();
    document.getElementById('rcOv').classList.add('open');
}
function rcTagsDraw() {
    const box = document.getElementById('eTags');
    box.innerHTML = '';
    _e.tags.forEach((t, i) => {
        const s = document.createElement('span');
        s.className = 'chip k-' + t.kind;
        s.textContent = t.name;
        s.title = RC_KINDS[t.kind] || '';
        s.onclick = () => { _e.tags.splice(i, 1); rcTagsDraw(); };
        box.appendChild(s);
    });
}
function rcTagAdd() {
    const inp = document.getElementById('eTagName');
    const name = inp.value.trim().replace(/[#,\/]/g, ' ').replace(/\s+/g, ' ');
    if (!name) return;
    if (_e.tags.some(t => t.name === name)) { inp.value = ''; return; }
    const kind = RC_TAGKIND[name] || document.getElementById('eTagKind').value;   /* 이미 있는 태그면 그 종류를 따른다 */
    _e.tags.push({ name: name, kind: kind });
    inp.value = '';
    rcTagsDraw();
}
document.getElementById('eTagName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); rcTagAdd(); } });
function rcClose() { document.getElementById('rcOv').classList.remove('open'); }
async function rcSave() {
    const j = await rcPost('update', {
        id: document.getElementById('eId').value,
        title: document.getElementById('eTitle').value,
        dish: document.getElementById('eDish').value,
        memo: document.getElementById('eMemo').value,
        tags: JSON.stringify(_e.tags)
    });
    if (j.ok) location.reload(); else alert(j.msg);
}
async function rcRetag(id, btn) {
    btn.disabled = true; btn.textContent = '🏷 붙이는 중…';
    const j = await rcPost('retag', { id: id });
    if (j.ok) location.reload(); else { alert(j.msg); btn.disabled = false; btn.textContent = '🏷 태그 다시'; }
}
async function rcDel() {
    if (!confirm('이 레시피를 지울까요?\n' + document.getElementById('eTitle').value)) return;
    const j = await rcPost('del', { id: document.getElementById('eId').value });
    if (j.ok) location.reload(); else alert(j.msg);
}
/* ── 태그 고치기 ── */
function rcTagEdit(id, name, kind) {
    document.getElementById('tId').value = id;
    document.getElementById('tName').value = name;
    document.getElementById('tKind').value = kind;
    document.getElementById('rcTOv').classList.add('open');
}
async function rcTagSave() {
    const j = await rcPost('tag_rename', { id: document.getElementById('tId').value, name: document.getElementById('tName').value, kind: document.getElementById('tKind').value });
    if (j.ok) location.href = '/recipe/index.php'; else alert(j.msg);
}
</script>
JS;
echo '</body></html>';
?>
