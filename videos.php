<?php
// ==========================================================
// videos.php — 영상 보관함 (구글 드라이브 "영상" 폴더, 임의 깊이 중첩 + 폴더별 공유)
// ----------------------------------------------------------
//  라우팅:
//    (기본)           루트 = "영상" 폴더 (소유자: 로그인 / 게스트: ?share=토큰)
//    ?folder={id}     그 폴더의 하위폴더(카드) + 직속 영상(재생카드) + 빵부스러기
//    ?share={token}   게스트 열람 — 그 공유폴더(및 하위)만, 로그인 불필요
//    ?mode=sync            드라이브 재귀 재스캔 (JSON, 소유자)
//    ?mode=share_create    폴더 공유 링크 생성 (JSON, 소유자)  &folder= &ttl=
//    ?mode=share_revoke    폴더 공유 중단     (JSON, 소유자)  &folder=
//
//  썸네일 = vod_thumb.php / 재생 = vod_video.php (SA 프록시, 게스트는 &share= 로 인가)
// ==========================================================

require_once __DIR__ . '/env/cnt.inc';        // PDO(mysqli) + 클래스 오토로더
require_once __DIR__ . '/env/auth_fnc.php';   // 인증
if (file_exists(__DIR__ . '/env/gdrive.inc')) require_once __DIR__ . '/env/gdrive.inc';
require_once __DIR__ . '/env/nav.inc';

$ROOT = defined('VOD_DRIVE_ROOT_ID') ? VOD_DRIVE_ROOT_ID : '';
$mode = $_GET['mode'] ?? '';

// ── 소유자 전용 JSON 엔드포인트 ──────────────────────────
if ($mode === 'sync') {
    require_login();
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(['ok' => true] + Vod::sync($pdo), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if ($mode === 'share_create') {
    require_login();
    header('Content-Type: application/json; charset=utf-8');
    $fid = trim((string)($_GET['folder'] ?? '')); if ($fid === '') $fid = $ROOT;
    $ttl = (int)($_GET['ttl'] ?? 604800);
    if ($fid !== $ROOT && Vod::folderInfo($pdo, $fid) === null) {
        echo json_encode(['ok' => false, 'error' => '폴더 없음']); exit;
    }
    $sh = Vod::createShare($pdo, $fid, $ttl);
    echo json_encode(['ok' => true, 'token' => $sh['token'], 'expires_at' => $sh['expires_at'] ?? null], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($mode === 'share_revoke') {
    require_login();
    header('Content-Type: application/json; charset=utf-8');
    $fid = trim((string)($_GET['folder'] ?? '')); if ($fid === '') $fid = $ROOT;
    Vod::revokeShares($pdo, $fid);
    echo json_encode(['ok' => true]);
    exit;
}

/** 바이트 → 사람이 읽는 크기 */
function vod_size(?int $b): string
{
    if (!$b) return '';
    return $b >= 1073741824 ? number_format($b / 1073741824, 2) . ' GB'
                            : number_format($b / 1048576, 0) . ' MB';
}

// ── 게스트(공유토큰) vs 소유자(로그인) ──────────────────
$shareTok = trim((string)($_GET['share'] ?? ''));
$guest    = $shareTok !== '' ? Vod::getValidShare($pdo, $shareTok) : null;
$isGuest  = ($guest !== null);

if ($shareTok !== '' && !$isGuest) {   // 만료·취소·잘못된 토큰
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>공유 링크 만료</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:440px;margin:16vh auto;text-align:center;color:#444">'
       . '<div style="font-size:3rem">🔒</div><h2>공유 링크가 만료되었거나 중단되었습니다</h2>'
       . '<p style="color:#888">공유한 사람에게 새 링크를 요청하세요.</p></div>';
    exit;
}
if (!$isGuest) require_login();

// ── 범위(scope) 및 현재 폴더 ────────────────────────────
$scopeRoot = $isGuest ? $guest['folder_id'] : $ROOT;
$scopeName = '영상';
if ($isGuest) {
    $sInfo = Vod::folderInfo($pdo, $scopeRoot);
    $scopeName = $sInfo['name'] ?? '영상';
}

$shareQ  = $isGuest ? ('&share=' . urlencode($shareTok)) : '';
$homeUrl = $isGuest ? ('videos.php?folder=' . urlencode($scopeRoot) . '&share=' . urlencode($shareTok)) : 'videos.php';
$furl = function (string $fid) use ($shareQ) { return 'videos.php?folder=' . urlencode($fid) . $shareQ; };

$folderId = trim((string)($_GET['folder'] ?? ''));
$atRoot   = ($folderId === '' || $folderId === $scopeRoot);
$curId    = $atRoot ? $scopeRoot : $folderId;

$curName = $scopeName;
if (!$atRoot) {
    $info = Vod::folderInfo($pdo, $curId);
    if ($info === null) { header('Location: ' . $homeUrl); exit; }
    if ($isGuest && !Vod::isDescendantOrSelf($pdo, $curId, $scopeRoot)) {   // 범위 밖 차단
        header('Location: ' . $homeUrl); exit;
    }
    $curName = $info['name'];
}

$crumbs = $atRoot ? [] : Vod::breadcrumb($pdo, $curId, $scopeRoot);
$subs   = Vod::listChildren($pdo, $curId);
$videos = Vod::listVideosIn($pdo, $curId);

// 소유자 공유 UI 상태
$curLabel    = $atRoot ? ($isGuest ? $scopeName : '영상 전체') : $curName;
$activeShare = (!$isGuest) ? Vod::getActiveShare($pdo, $curId) : null;

$PLAY_SVG = '<svg viewBox="0 0 68 48"><path d="M66.5 7.7c-.8-2.9-2.5-5.4-5.4-6.2C55.8.2 34 0 34 0S12.2.2 6.9 1.5C4 2.3 2.3 4.8 1.5 7.7.2 13 0 24 0 24s.2 11 1.5 16.3c.8 2.9 2.5 5.4 5.4 6.2C12.2 47.8 34 48 34 48s21.8-.2 27.1-1.5c2.9-.8 4.6-3.3 5.4-6.2C67.8 35 68 24 68 24s-.2-11-1.5-16.3z" fill="#f00"/><path d="M45 24 27 14v20z" fill="#fff"/></svg>';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>영상 보관함<?= $atRoot && !$isGuest ? '' : ' · ' . htmlspecialchars($curName) ?></title>
<?php if (!$isGuest) nav_css(); ?>
<style>
  :root{
    --bg:#f4f5f7; --card:#fff; --fg:#1a1c1e; --sub:#6b7280;
    --line:#e5e7eb; --accent:#2563eb; --badge:rgba(0,0,0,.72);
    --shadow:0 1px 3px rgba(0,0,0,.08),0 6px 20px rgba(0,0,0,.06);
  }
  @media (prefers-color-scheme:dark){
    :root{
      --bg:#0f1115; --card:#191c22; --fg:#e8eaed; --sub:#9aa0a6;
      --line:#2a2e37; --accent:#5b9dff; --badge:rgba(0,0,0,.8);
      --shadow:0 1px 3px rgba(0,0,0,.4),0 8px 24px rgba(0,0,0,.3);
    }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--fg);
    font-family:"Segoe UI","Malgun Gothic",-apple-system,system-ui,sans-serif;
    -webkit-font-smoothing:antialiased;line-height:1.5}
  a{color:inherit;text-decoration:none}
  .gbar{background:#2c3e50;color:#fff;height:52px;display:flex;align-items:center;
    gap:8px;padding:0 16px;font-weight:700}
  .wrap{max-width:1200px;margin:0 auto;padding:20px 18px 60px}
  .crumbs{display:flex;flex-wrap:wrap;align-items:center;gap:5px;color:var(--sub);
    font-size:.9rem;font-weight:600;margin:2px 0 6px}
  .crumbs a:hover{color:var(--accent)}
  .crumbs .sepc{opacity:.5}
  .crumbs .cur{color:var(--fg)}
  .head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:6px 0 4px}
  .head h1{margin:0;font-size:1.5rem;letter-spacing:-.02em}
  .head .meta{color:var(--sub);font-size:.9rem}
  .head .sp{flex:1}
  .btn{border:1px solid var(--line);background:var(--card);color:var(--fg);
    font-size:.88rem;font-weight:600;padding:8px 14px;border-radius:9px;cursor:pointer;
    display:inline-flex;align-items:center;gap:6px}
  .btn:hover{border-color:var(--accent);color:var(--accent)}
  .btn[disabled]{opacity:.55;cursor:default}
  .toast{margin-top:8px;font-size:.88rem;color:var(--sub);min-height:1.2em}
  /* 공유 패널 */
  .sharePanel{margin-top:12px;border:1px solid var(--line);background:var(--card);
    border-radius:12px;padding:14px 16px;box-shadow:var(--shadow);max-width:620px}
  .sharePanel h3{margin:0 0 4px;font-size:.98rem}
  .sharePanel .desc{color:var(--sub);font-size:.83rem;margin-bottom:10px}
  .sp-ttl{display:flex;gap:8px;flex-wrap:wrap}
  .sp-ttl button{border:1px solid var(--line);background:transparent;color:var(--fg);
    border-radius:8px;padding:7px 13px;cursor:pointer;font-weight:600;font-size:.85rem}
  .sp-ttl button:hover{border-color:var(--accent);color:var(--accent)}
  .sp-link{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
  .sp-link input{flex:1;min-width:220px;border:1px solid var(--line);border-radius:8px;
    padding:8px 10px;background:var(--bg);color:var(--fg);font-size:.85rem}
  .sp-exp{color:var(--sub);font-size:.82rem;margin-top:8px}
  .sp-revoke{color:#e74c3c;border-color:transparent;background:transparent;cursor:pointer;
    font-size:.83rem;font-weight:600;padding:6px 0;margin-top:2px}
  .empty{margin-top:40px;padding:34px;text-align:center;border:1px dashed var(--line);
    border-radius:14px;color:var(--sub);line-height:1.8}
  .sec-label{margin:26px 0 2px;font-size:.82rem;font-weight:700;color:var(--sub)}
  .grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fill,minmax(258px,1fr));margin-top:12px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;
    overflow:hidden;box-shadow:var(--shadow);cursor:pointer;display:block;
    transition:transform .12s ease,box-shadow .12s ease}
  .card:hover{transform:translateY(-3px)}
  .thumb{position:relative;aspect-ratio:16/9;background:#0b0d10;overflow:hidden}
  .thumb img{width:100%;height:100%;object-fit:cover;display:block;opacity:0;transition:opacity .3s}
  .thumb img.on{opacity:1}
  .thumb .fb{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    color:#3a4048;font-size:2.6rem;z-index:1}
  .play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:2}
  .play svg{width:52px;height:52px;filter:drop-shadow(0 2px 6px rgba(0,0,0,.5));opacity:.94;transition:transform .12s}
  .card:hover .play svg{transform:scale(1.12)}
  .dur{position:absolute;right:8px;bottom:8px;z-index:2;background:var(--badge);color:#fff;
    font-size:.74rem;font-weight:600;padding:2px 7px;border-radius:5px}
  .cnt-badge{position:absolute;right:8px;top:8px;z-index:3;background:var(--badge);color:#fff;
    font-size:.74rem;font-weight:600;padding:3px 8px;border-radius:20px}
  .body{padding:11px 13px 13px}
  .title{font-size:.95rem;font-weight:600;letter-spacing:-.01em;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .no{color:var(--accent);font-weight:700;margin-right:4px}
  .row{display:flex;justify-content:space-between;gap:8px;margin-top:6px;color:var(--sub);font-size:.8rem}
  .fmeta{padding:12px 14px;display:flex;align-items:center;gap:8px}
  .fmeta .fname{font-size:1rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .fmeta .fc{margin-left:auto;color:var(--sub);font-size:.82rem;white-space:nowrap}
  .modal{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:2000;display:none;
    align-items:center;justify-content:center;padding:18px}
  .modal.open{display:flex}
  .modal .box{width:min(1000px,100%)}
  .modal video{width:100%;max-height:78vh;background:#000;border-radius:12px;
    box-shadow:0 20px 60px rgba(0,0,0,.6);display:block}
  .modal .cap{color:#eee;margin-top:12px;font-size:.95rem;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .modal .close{position:fixed;top:14px;right:20px;color:#fff;font-size:2rem;cursor:pointer;
    line-height:1;background:none;border:0;opacity:.85}
  .modal .close:hover{opacity:1}
</style>
</head>
<body>
<?php if ($isGuest): ?>
  <div class="gbar">🎞️ 영상 공유 · <?= htmlspecialchars($scopeName) ?></div>
<?php else: ?>
  <?php render_nav('vod'); ?>
<?php endif; ?>

<div class="wrap">

  <!-- 빵부스러기 -->
  <div class="crumbs">
    <a href="<?= htmlspecialchars($homeUrl) ?>"><?= $isGuest ? '📁 ' . htmlspecialchars($scopeName) : '🎞️ 영상' ?></a>
    <?php foreach ($crumbs as $i => $c): $last = ($i === count($crumbs) - 1); ?>
      <span class="sepc">›</span>
      <?php if ($last): ?>
        <span class="cur"><?= htmlspecialchars($c['name']) ?></span>
      <?php else: ?>
        <a href="<?= htmlspecialchars($furl($c['id'])) ?>"><?= htmlspecialchars($c['name']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="head">
    <h1><?= $atRoot && !$isGuest ? '🎞️ 영상 보관함' : '📁 ' . htmlspecialchars($curName) ?></h1>
    <span class="meta">
      <?php
        $bits = [];
        if (count($subs))   $bits[] = count($subs) . '개 폴더';
        if (count($videos)) $bits[] = count($videos) . '개 영상';
        echo $bits ? htmlspecialchars(implode(' · ', $bits)) : '비어 있음';
      ?>
    </span>
    <?php if (!$isGuest): ?>
      <span class="sp"></span>
      <button class="btn" onclick="toggleShare()">🔗 이 폴더 공유</button>
      <?php if ($atRoot): ?><button class="btn" id="syncBtn" onclick="vodSync()">🔄 동기화</button><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($atRoot && !$isGuest): ?><div class="toast" id="toast"></div><?php endif; ?>

  <?php if (!$isGuest): ?>
  <!-- 공유 패널 (소유자) -->
  <div class="sharePanel" id="sharePanel" hidden>
    <h3>🔗 <b><?= htmlspecialchars($curLabel) ?></b> 공유</h3>
    <div class="desc">로그인 없이 이 폴더(및 하위 폴더)의 영상만 볼 수 있는 링크입니다.</div>
    <div id="spCreate" <?= $activeShare ? 'hidden' : '' ?>>
      <div class="sp-ttl">
        유효기간
        <button onclick="spCreate(86400)">1일</button>
        <button onclick="spCreate(604800)">7일</button>
        <button onclick="spCreate(2592000)">30일</button>
      </div>
    </div>
    <div id="spActive" <?= $activeShare ? '' : 'hidden' ?>>
      <div class="sp-link">
        <input id="spLink" readonly value="<?= $activeShare ? htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'economist.kr') . '/videos.php?folder=' . urlencode($curId) . '&share=' . urlencode($activeShare['token'])) : '' ?>">
        <button class="btn" onclick="spCopy()">복사</button>
      </div>
      <div class="sp-exp">만료: <span id="spExp"><?= $activeShare ? htmlspecialchars($activeShare['expires_at']) : '' ?></span></div>
      <button class="sp-revoke" onclick="spRevoke()">공유 중단</button>
    </div>
  </div>
  <?php endif; ?>

<?php if (!$subs && !$videos): ?>
  <div class="empty">
    <?php if ($atRoot && !$isGuest): ?>
      아직 동기화된 영상이 없습니다.<br>상단 <b>🔄 동기화</b> 를 눌러 "영상" 폴더를 불러오세요.
    <?php else: ?>
      이 폴더는 비어 있습니다.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($subs): ?>
  <?php if ($videos): ?><div class="sec-label">📁 폴더</div><?php endif; ?>
  <div class="grid">
    <?php foreach ($subs as $f):
        $fnm   = htmlspecialchars($f['name']);
        $cover = htmlspecialchars((string)$f['cover_file_id']);
    ?>
    <a class="card" href="<?= htmlspecialchars($furl($f['folder_id'])) ?>">
      <div class="thumb">
        <div class="fb">📁</div>
        <?php if ($cover): ?>
          <img src="vod_thumb.php?id=<?= $cover ?>&w=640<?= $shareQ ?>" alt="" loading="lazy" onload="this.classList.add('on')">
        <?php endif; ?>
        <?php if ((int)$f['vid_count']): ?><div class="cnt-badge"><?= (int)$f['vid_count'] ?>개</div><?php endif; ?>
      </div>
      <div class="fmeta">
        <span class="fname">📁 <?= $fnm ?></span>
        <span class="fc"><?= (int)$f['vid_count'] ?>개 영상</span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($videos): ?>
  <?php if ($subs): ?><div class="sec-label">🎬 영상</div><?php endif; ?>
  <div class="grid">
    <?php foreach ($videos as $v):
        $fid = htmlspecialchars($v['drive_file_id']);
        $ttl = htmlspecialchars($v['title']);
        $no  = $v['seq'] !== null ? 'No.' . str_pad((string)$v['seq'], 2, '0', STR_PAD_LEFT) : '';
        $dt  = htmlspecialchars((string)$v['taken_label']);
        $du  = htmlspecialchars((string)$v['dur_label']);
        $sz  = vod_size($v['size'] !== null ? (int)$v['size'] : null);
    ?>
    <div class="card" onclick='vodPlay(<?= json_encode([
        "id"=>$v["drive_file_id"], "no"=>$no, "title"=>$v["title"],
        "date"=>$v["taken_label"], "dur"=>$v["dur_label"], "size"=>$sz
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) ?>)'>
      <div class="thumb">
        <div class="fb">🎬</div>
        <img src="vod_thumb.php?id=<?= $fid ?>&w=640<?= $shareQ ?>" alt="" loading="lazy" onload="this.classList.add('on')">
        <div class="play"><?= $PLAY_SVG ?></div>
        <?php if ($du): ?><div class="dur"><?= $du ?></div><?php endif; ?>
      </div>
      <div class="body">
        <div class="title"><?php if ($no): ?><span class="no"><?= $no ?></span><?php endif; ?><?= $ttl ?></div>
        <div class="row"><span><?= $dt ?></span><span><?= htmlspecialchars($sz) ?></span></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</div>

<!-- 재생 모달 -->
<div class="modal" id="modal">
  <button class="close" onclick="vodClose()">&times;</button>
  <div class="box">
    <video id="player" controls playsinline preload="metadata"></video>
    <div class="cap" id="cap"></div>
  </div>
</div>

<script>
const SHARE      = <?= json_encode($isGuest ? $shareTok : '') ?>;
const CUR_FOLDER = <?= json_encode($curId) ?>;
const modal  = document.getElementById('modal');
const player = document.getElementById('player');
const cap    = document.getElementById('cap');

function vodPlay(v){
  player.src = 'vod_video.php?id=' + encodeURIComponent(v.id) + (SHARE ? '&share=' + encodeURIComponent(SHARE) : '');
  const bits = [v.date, v.dur, v.size].filter(Boolean).join(' · ');
  cap.innerHTML = '<strong>' + (v.no ? v.no + ' ' : '') +
     String(v.title).replace(/</g,'&lt;') + '</strong>' +
     (bits ? ' <span style="opacity:.7">' + bits + '</span>' : '');
  modal.classList.add('open');
  player.play().catch(()=>{});
}
function vodClose(){
  modal.classList.remove('open');
  player.pause(); player.removeAttribute('src'); player.load();
}
modal.addEventListener('click', e => { if (e.target === modal) vodClose(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') vodClose(); });

<?php if (!$isGuest): ?>
/* ── 동기화 ── */
function vodSync(){
  const b = document.getElementById('syncBtn'), t = document.getElementById('toast');
  if(!b) return;
  b.disabled = true; b.textContent = '⏳ 동기화 중…'; if(t) t.textContent = '드라이브를 스캔하고 있습니다…';
  fetch('videos.php?mode=sync', {cache:'no-store'}).then(r => r.json()).then(d => {
    if (d.ok){ if(t) t.textContent = `✅ 폴더 ${d.folders} · 영상 ${d.videos}` + (d.deleted ? ` · 삭제 ${d.deleted}` : '');
      setTimeout(() => location.reload(), 700);
    } else { if(t) t.textContent = '⚠️ ' + (d.error || '실패'); b.disabled=false; b.textContent='🔄 동기화'; }
  }).catch(e => { if(t) t.textContent = '⚠️ ' + e; b.disabled=false; b.textContent='🔄 동기화'; });
}

/* ── 폴더 공유 ── */
function toggleShare(){ const p=document.getElementById('sharePanel'); p.hidden=!p.hidden; }
function shareUrl(token){
  return location.origin + '/videos.php?folder=' + encodeURIComponent(CUR_FOLDER) + '&share=' + token;
}
function spCreate(ttl){
  fetch('videos.php?mode=share_create&folder=' + encodeURIComponent(CUR_FOLDER) + '&ttl=' + ttl, {cache:'no-store'})
    .then(r=>r.json()).then(d=>{
      if(!d.ok){ alert('공유 생성 실패: ' + (d.error||'')); return; }
      document.getElementById('spLink').value = shareUrl(d.token);
      document.getElementById('spExp').textContent = d.expires_at || '';
      document.getElementById('spCreate').hidden = true;
      document.getElementById('spActive').hidden = false;
    }).catch(e=>alert('오류: ' + e));
}
function spCopy(){
  const el = document.getElementById('spLink'); el.select();
  navigator.clipboard?.writeText(el.value).then(()=>{ const o=el.value; el.value='✅ 복사됨'; setTimeout(()=>el.value=o,900); })
    .catch(()=>document.execCommand('copy'));
}
function spRevoke(){
  if(!confirm('이 폴더 공유 링크를 중단할까요? 기존 링크는 즉시 열리지 않게 됩니다.')) return;
  fetch('videos.php?mode=share_revoke&folder=' + encodeURIComponent(CUR_FOLDER), {cache:'no-store'})
    .then(r=>r.json()).then(()=>{ document.getElementById('spActive').hidden=true; document.getElementById('spCreate').hidden=false; })
    .catch(e=>alert('오류: ' + e));
}
<?php endif; ?>
</script>
</body>
</html>
