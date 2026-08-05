<?php
// ardent_log.php — 아덴트뉴스 수집 기록 (라우터+뷰)
//   "한 달에 한 번 돌린 그 회차에, 어떤 기사를 가져왔고 / 어느 장소에 붙였고 / 요약은 뭐였나"를 날짜별로 본다.
//   데이터는 tbl_ardent_run(_item) — 기록은 cron/ardent_crawl.php?log_run=1 이 남기고, 여기서는 읽기만 한다.
//   DB 접근은 전부 classes/ArdentLog.class 를 경유한다(이 화면에 쿼리를 적지 않는다).
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();
require_once "./env/nav.inc";

$log = new ArdentLog($pdo);
$log->ensureTables();

$runs = $log->runList(36);
$runId = (int)($_GET['run'] ?? 0);
$run   = $runId > 0 ? $log->runGet($runId) : ($runs[0] ?? null);
if ($run) $runId = (int)$run['id'];
$arts  = $run ? $log->itemsByRun($runId) : [];

// 회차 안에서 「기사」와 「장소」는 수가 다르다 — 카드 머리글에 둘 다 보여 준다.
$placeRows = 0;
foreach ($arts as $a) foreach ($a['places'] as $p) if ($p['place_id']) $placeRows++;

$CAT_KO = ['travel' => '여행지', 'restaurant' => '맛집', 'stay' => '숙소', 'camping' => '캠핑장', 'etc' => '기타'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

echo '<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"/>';
echo '<meta name="viewport" content="width=device-width, initial-scale=1.0"/>';
echo '<title>아덴트 수집기록</title>';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css"/>';
nav_css();
?>
<style>
  * { box-sizing: border-box; }
  body { margin:0; font-family:Pretendard,-apple-system,sans-serif; background:#f4f6f9; color:#2c3440; }
  a { color:inherit; }
  .aw { max-width:1180px; margin:0 auto; padding:16px 16px 60px; }
  h1.ah { font-size:20px; margin:6px 0 4px; display:flex; align-items:center; gap:8px; }
  .asub { color:#8894a5; font-size:12.5px; margin-bottom:14px; line-height:1.6; }

  /* 회차 고르기 — 날짜 알약. 회차가 곧 "내가 돌린 날"이다. */
  .runbar { display:flex; gap:8px; overflow-x:auto; padding:2px 0 12px; }
  .runpill { flex:0 0 auto; background:#fff; border:1px solid #e3e8ef; border-radius:12px;
             padding:9px 13px; text-decoration:none; min-width:132px; box-shadow:0 1px 2px rgba(20,40,80,.04); }
  .runpill:hover { border-color:#c8d2e0; }
  .runpill.on { border-color:#4c7ef3; background:#f2f6ff; box-shadow:0 2px 8px rgba(76,126,243,.14); }
  .runpill .d { font-weight:700; font-size:14px; }
  .runpill .m { color:#8894a5; font-size:11.5px; margin-top:3px; }

  .sum { display:flex; flex-wrap:wrap; gap:8px; margin:2px 0 16px; }
  .sum span { background:#fff; border:1px solid #e8ecf2; border-radius:999px; padding:5px 12px; font-size:12.5px; }
  .sum b { font-size:13.5px; }

  .art { background:#fff; border:1px solid #e8ecf2; border-radius:14px; padding:14px 16px; margin-bottom:12px; }
  .art-hd { display:flex; align-items:flex-start; gap:10px; }
  .art-hd .t { font-weight:700; font-size:15px; line-height:1.45; flex:1; }
  .art-hd .t a { text-decoration:none; }
  .art-hd .t a:hover { text-decoration:underline; }
  .art-meta { color:#9aa5b5; font-size:11.5px; white-space:nowrap; padding-top:3px; }

  .pl { border-top:1px dashed #e8ecf2; margin-top:11px; padding-top:11px; }
  .pl:first-of-type { border-top:0; }
  .pl-hd { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .pl-nm { font-weight:700; font-size:14px; text-decoration:none; }
  .pl-nm:hover { text-decoration:underline; }
  .tag { font-size:11px; border-radius:6px; padding:2px 7px; font-weight:700; }
  .t-new { background:#e6f6ec; color:#1a7f45; }
  .t-enr { background:#e8f0ff; color:#2b5fd0; }
  .t-skip{ background:#f2f3f5; color:#8a94a3; }
  .t-cat { background:#f5f6f8; color:#6b7686; font-weight:600; }
  .pl-rg { color:#8894a5; font-size:12px; }
  .pl-sum { margin-top:7px; font-size:13.5px; line-height:1.75; color:#3a4452; white-space:pre-wrap; }
  .pl-ft { margin-top:6px; color:#8894a5; font-size:11.5px; }
  .empty { background:#fff; border:1px dashed #dbe2ec; border-radius:14px; padding:34px; text-align:center; color:#8894a5; }
  @media (max-width:640px) { .aw { padding:12px 10px 50px; } .art-meta { display:none; } }
</style>
</head><body>
<?php render_nav('ardent'); ?>
<div class="aw">
  <h1 class="ah">🗞️ 아덴트 수집기록</h1>
  <div class="asub">
    아덴트뉴스 국내여행 기사를 읽어 여행지 DB(<a href="/places.php">여행지도</a>)에 담은 내역입니다.
    회차 = 수집을 돌린 날. 요약은 <b>그때 기록한 원문</b>이라, 이후 다른 기사가 장소 요약을 덮어써도 여기 남은 글은 바뀌지 않습니다.
  </div>

<?php if (!$runs): ?>
  <div class="empty">아직 기록된 회차가 없습니다.<br>수집을 한 번 돌리면 이 자리에 날짜별로 쌓입니다.</div>
<?php else: ?>
  <div class="runbar">
    <?php foreach ($runs as $r): ?>
      <a class="runpill <?= ((int)$r['id'] === $runId ? 'on' : '') ?>" href="?run=<?= (int)$r['id'] ?>">
        <div class="d"><?= $h($r['run_key']) ?></div>
        <div class="m">기사 <?= (int)$r['articles'] ?> · 신규 <?= (int)$r['new_cnt'] ?> · 보강 <?= (int)$r['enr_cnt'] ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($run): ?>
    <div class="sum">
      <span>회차 <b><?= $h($run['run_key']) ?></b></span>
      <span>기사 <b><?= (int)$run['articles'] ?></b>건</span>
      <span>🟢 신규 <b><?= (int)$run['new_cnt'] ?></b></span>
      <span>🔵 보강 <b><?= (int)$run['enr_cnt'] ?></b></span>
      <span>담긴 장소 <b><?= $placeRows ?></b>곳</span>
      <?php if ((int)$run['skip_cnt'] > 0): ?><span>제외 <b><?= (int)$run['skip_cnt'] ?></b>건</span><?php endif; ?>
      <?php if (!empty($run['note'])): ?><span><?= $h($run['note']) ?></span><?php endif; ?>
    </div>

    <?php if (!$arts): ?>
      <div class="empty">이 회차에는 기록된 기사가 없습니다.</div>
    <?php endif; ?>

    <?php foreach ($arts as $a): ?>
      <div class="art">
        <div class="art-hd">
          <div class="t">
            <?php if ($a['url']): ?><a href="<?= $h($a['url']) ?>" target="_blank" rel="noopener"><?= $h($a['title'] ?: ('#' . $a['idxno'])) ?></a>
            <?php else: ?><?= $h($a['title'] ?: ('#' . $a['idxno'])) ?><?php endif; ?>
          </div>
          <div class="art-meta">#<?= (int)$a['idxno'] ?><?= $a['pub_date'] ? ' · ' . $h($a['pub_date']) : '' ?></div>
        </div>

        <?php foreach ($a['places'] as $p):
            $act = (string)$p['action'];
            $cls = $act === 'new' ? 't-new' : ($act === 'enrich' ? 't-enr' : 't-skip');
            $lbl = $act === 'new' ? '🟢 신규' : ($act === 'enrich' ? '🔵 보강' : '제외');
            $nm  = (string)($p['live_name'] ?: $p['place_name']);
            $cat = (string)($p['live_cat'] ?: $p['category']);
            $rg  = trim((string)($p['region_lv1'] ?? '') . ' ' . (string)($p['region_lv2'] ?? '')) ?: (string)$p['region'];
        ?>
          <div class="pl">
            <div class="pl-hd">
              <span class="tag <?= $cls ?>"><?= $lbl ?></span>
              <?php if ($p['place_id']): ?>
                <a class="pl-nm" href="/places.php?place=<?= (int)$p['place_id'] ?>" target="_blank" rel="noopener"><?= $h($nm) ?></a>
              <?php elseif ($nm !== ''): ?>
                <span class="pl-nm"><?= $h($nm) ?></span>
              <?php endif; ?>
              <?php if ($cat !== ''): ?><span class="tag t-cat"><?= $h($CAT_KO[$cat] ?? $cat) ?></span><?php endif; ?>
              <?php if ($rg !== ''): ?><span class="pl-rg"><?= $h($rg) ?></span><?php endif; ?>
              <?php if (!empty($p['note'])): ?><span class="pl-rg"><?= $h($p['note']) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($p['summary'])): ?><div class="pl-sum"><?= $h($p['summary']) ?></div><?php endif; ?>
            <?php if (!empty($p['features']) || !empty($p['months'])): ?>
              <div class="pl-ft">
                <?= !empty($p['features']) ? $h($p['features']) : '' ?>
                <?= !empty($p['months']) ? ' · 추천 ' . $h($p['months']) . '월' : '' ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
</div>
</body></html>
