<?php
/**
 * stock/lib/wgroup.php — 관심그룹 화면 (mode=wgroup · 2026-10-07)
 *
 * HTS 관심그룹처럼 종목을 그룹으로 묶어 ①기준가 대비로 보고 ②그룹 전체의 일봉을 한 번에 본다.
 * 3단 — 그룹 목록(200) · 종목 리스트(580) · 멀티 차트(나머지). DB 는 classes/Wg.class,
 * API 는 stock/lib/wgroup_api.php(module=wg). 규칙은 CLAUDE.md 「/stock 관심그룹」 절.
 *
 * ★재무분석 하위의 「관심종목」(mode=watch · pf_watchlist)과 별개다 — 저건 ☆·트리거·알림의 재료,
 *   이건 «내가 보려고 묶은 목록»이다. 여기서 새 판정을 세우지 않는다(보는 자리다).
 * ★차트는 멀티차트 칸과 같은 한 벌이다 — 구성 ④(다크 관찰용) · 차트틀·지표 키 'short' ·
 *   바 하나(기간·지표)가 전부를 조종 · 기간은 ChartFeat::SCREENS['wgroup'] 행이 따로 기억.
 * ★배열은 «격자»다(가로 1~6 × 세로 1~4 · 처음 4×4 · localStorage wg_grid) — 멀티차트(mode=multi)의 배열 방식을 옮겨 왔고
 *   그 메뉴는 없앴다(2026-10-07 밤 · 옛 주소는 여기로 301). 세로 = 한 화면에 보이는 줄 수, 넘치면 스크롤.
 * ★차트는 «풀»이다 — 페이지를 열 때 (그 그룹 종목 수 + 여유)만큼 만들어 두고, 그룹을 바꾸거나
 *   순서가 바뀌면 칸을 «다시 짝짓는다». 지우고 새로 만들지 않는 이유 — 차트 모듈엔 해체가 없고
 *   (창 리스너가 남는다), 기간 바·지표 바는 만들 때의 차트 목록을 복사해 쥔다.
 *   풀보다 큰 그룹을 고르면 주소(&g=)로 다시 연다 — 그게 유일하게 새로 만드는 길이다.
 * ★일봉은 «보이는 칸부터 둘씩» 받는다(IntersectionObserver · 화면 아래 한 화면 미리) —
 *   기존 경로(action=daily)라 단타 대상 종목은 키움(1 req/s · 버스트 2)으로 간다.
 * ★기준가 선은 모듈의 가격선(setPriceLines)이다 — 수평선 그리기(chart_hline)를 쓰지 않는다.
 *   저건 주인이 «종목»이라 다른 화면 전부에 같은 선이 선다. 기준가의 주인은 «그룹-종목»이다.
 * ★장중 시세는 «보고 있는 그룹만» 받는다(module=wg quote_batch → Dt::refreshQuotesLive) ·
 *   주기는 Dt::TICK_SEC 한 숫자 · 탭이 안 보이거나 장 밖이면 화면도 서버도 안 나간다.
 * ★DDL 을 하지 않는다 — 표가 없으면 「만들기」 버튼(api schema)을 보인다.
 * ★테마 그룹(2026-10-12) — 그룹에 «시장 변수»(환율·유가·금리… · classes/Mv.class · module=mv)를 연결하면
 *   ①격자 맨 앞 칸에 변수의 추이 ②종목 차트마다 변수 선 겹치기 ③구분행의 방향(↑ 오를 때 유리 · ↓ 내릴 때 유리)이 켜진다.
 *   변수가 없는 그룹은 예전 그대로다. 판정을 세우지 않는다 — 방향은 내가 적은 것이고 「유리」는 주 변수가 오른 날인지 내린 날인지만 본다.
 * ★테마 md 가져오기(2026-10-26 · 규칙 17) — 「그룹 관리 · 일괄등록」 붙여넣기 칸이 머리말 `wg: theme/1` 을 알아보면
 *   미리보기 표(모드 · 행 상태 · 기준가 · 제외 이유)를 보이고, 적용한 그룹은 리스트 위에 접이식 「테마 노트」(원문의 노트·일정·출처)가 선다.
 */
require_once __DIR__ . '/wgroup_api.php';      // wg_theme_view() — 테마 노트(첫 화면과 item_list 가 같은 함수)

function pf_page_wgroup(PDO $pdo, Pf $pf): void
{
    $wg     = new Wg($pdo);
    $ready  = $wg->ready();
    /* 고정 그룹 둘(보유종목·Top-Pick)이 맨 위, 그 아래가 내가 만든 그룹 — Wg::groupsAll() */
    $groups = $ready ? $wg->groupsAll() : [];
    $ids    = array_column($groups, 'id');

    /* 그룹 — 주소(&g=held · top · <id>) → 마지막으로 본 그룹(쿠키) → 목록의 첫 그룹 */
    $gq  = $_GET['g'] ?? ($_COOKIE['wg_gid'] ?? '');
    $gid = Wg::gidOf(is_scalar($gq) ? (string)$gq : '');
    if (!in_array($gid, $ids, true)) $gid = (int)($ids[0] ?? 0);

    $items = [];
    if ($gid) {
        // 보는 그룹만 새로 받는다 — 실패해도 목록은 DB 값으로 뜬다
        try {
            (new Dt($pdo))->refreshQuotesLive(null, false, array_slice($wg->codes($gid), 0, Wg::QUOTE_MAX));
        } catch (Throwable $e) { /* 낡은 값으로 계속 */ }
        $items = $wg->rows($gid);
    }
    $nStock = count(array_filter($items, static fn($r) => $r['type'] === 'stock'));
    $pool   = max(12, $nStock + 8);
    $F      = $ready ? ChartFeat::vals('wgroup', $pdo) : [];
    /* 테마 변수 — 이 그룹에 연결한 시장 변수. 표가 아직 없으면 「변수」 모달이 만들기를 권한다(화면은 DDL 을 하지 않는다) */
    $mv      = new Mv($pdo);
    $mvReady = $ready && $mv->ready();
    $vars    = [];
    if ($mvReady && $gid > 0) {
        try { $vars = $mv->groupVars($gid); } catch (Throwable $e) { $vars = []; }
    }
    // 등록된 변수 전부 — 종목별 «같이 볼 변수»의 이름·자릿수와 고르는 메뉴가 이것을 본다
    $vall = [];
    if ($mvReady) { try { $vall = $mv->vars(); } catch (Throwable $e) { $vall = []; } }
    // 테마 노트 — 이 그룹을 테마 md 로 가져왔으면(wg_import) 그 원문의 노트·일정·출처 · 표가 없거나 기록이 없으면 null
    $theme = ($ready && $gid > 0) ? wg_theme_view($wg, $gid) : null;

    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
    echo '<title>관심그룹 · 주식 포트폴리오</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css"/>';
    pf_css();

    /* 팔레트는 단타·멀티차트와 같은 값(차트 모듈 dark 테마) — 화면을 오가도 눈이 다시 적응하지 않게 */
    echo <<<'CSS'
<style>
:root{
  --bg:#0e1320; --panel:#141b2b; --panel-2:#1b2335; --line:#26304a;
  --ink:#dfe6f2; --ink-dim:#8893ab; --ink-mute:#5b6884;
  --up:#e8493f; --down:#2f7bd6; --accent:#d9a441; --base:#f2c94c; --mv:#3fd0c9;
  --mono:'SFMono-Regular',ui-monospace,Consolas,'Roboto Mono',monospace;
}
html,body{height:100%;margin:0;overflow:hidden}
body.dt-dark{background:var(--bg);color:var(--ink);display:flex;flex-direction:column}
.up{color:var(--up)} .down{color:var(--down)} .flat{color:var(--ink-mute)}
button{font-family:inherit}

#wg-shell{flex:1;display:flex;min-height:0}
.wg-msg{padding:18px 12px;color:var(--ink-mute);font-size:12.5px;line-height:1.8;text-align:center}
.wg-msg a{color:var(--accent)}

/* ── ① 그룹 목록 ── */
#wg-groups{flex:0 0 200px;display:flex;flex-direction:column;min-height:0;background:var(--panel);border-right:1px solid var(--line)}
.wg-gh{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;padding:9px 11px;border-bottom:1px solid var(--line);font-size:13px}
.wg-gh button{width:24px;height:24px;border-radius:6px;border:1px solid var(--line);background:var(--panel-2);color:var(--ink-dim);font-size:14px;font-weight:800;line-height:1;cursor:pointer;padding:0}
.wg-gh button:hover{border-color:var(--accent);color:var(--accent)}
#wgGList{flex:1;overflow-y:auto;min-height:0}
.wg-g{display:flex;align-items:center;gap:7px;width:100%;padding:8px 11px;border:0;border-bottom:1px solid rgba(38,48,74,.55);
  background:transparent;color:var(--ink);font-size:13px;text-align:left;cursor:pointer}
.wg-g:hover{background:var(--panel-2)}
.wg-g.on{background:#1d2740;box-shadow:inset 3px 0 0 var(--accent);font-weight:800}
.wg-g .no{flex:0 0 16px;text-align:right;color:var(--ink-mute);font-family:var(--mono);font-size:11px}
.wg-g .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wg-g .n{flex:0 0 auto;min-width:15px;text-align:right;color:var(--ink-mute);font-family:var(--mono);font-size:11px}
.wg-g .n.full{color:var(--accent)}
/* 평균 등락 — 그 그룹 종목의 오늘 등락률 단순 평균. 장중에 낡은 값(old)은 흐리게 — 지금 값처럼 읽히지 않게 */
.wg-g .av{flex:0 0 auto;font-family:var(--mono);font-size:11px;font-weight:700;letter-spacing:-.2px}
.wg-g .av.up{color:var(--up)} .wg-g .av.down{color:var(--down)} .wg-g .av.flat{color:var(--ink-mute)}
.wg-g .av.old{opacity:.45}
/* 고정 그룹(보유종목·Top-Pick) — 번호 대신 ★ · 내 그룹과는 굵은 줄로 가른다 */
.wg-g.fx .no{color:var(--accent);font-size:10px}
.wg-g.fx + .wg-g:not(.fx){border-top:2px solid var(--line)}
.wg-ghint{padding:12px 11px;color:var(--ink-mute);font-size:11.5px;line-height:1.7;border-top:2px solid var(--line)}
.wg-fix{flex:0 0 auto;padding:7px 11px;font-size:11.5px;line-height:1.6;color:var(--ink-dim);background:#101727;border-bottom:1px solid var(--line)}
.wg-fix[hidden]{display:none}
.wg-fix a{color:var(--accent)}
.wg-fix b{color:var(--accent)}
#wgGNew{flex:0 0 auto;padding:7px 9px;border-top:1px solid var(--line)}
#wgGNew[hidden]{display:none}
.wg-in{width:100%;box-sizing:border-box;padding:6px 9px;background:var(--panel-2);border:1px solid var(--line);border-radius:7px;
  color:var(--ink);font-size:13px;outline:none;font-family:inherit}
.wg-in:focus{border-color:var(--accent)}
.wg-gfoot{flex:0 0 auto;margin:0;padding:10px;border:0;border-top:1px solid var(--line);background:var(--panel-2);color:var(--ink-dim);
  font-size:12px;font-weight:700;cursor:pointer}
.wg-gfoot:hover{color:var(--accent)}

/* ── ② 종목 리스트 ── */
#wg-list{flex:0 0 580px;display:flex;flex-direction:column;min-height:0;background:var(--panel);border-right:1px solid var(--line)}
.wg-lh{flex:0 0 auto;display:flex;align-items:center;gap:7px;padding:8px 10px;border-bottom:1px solid var(--line);flex-wrap:wrap;row-gap:6px}
.wg-qwrap{position:relative;flex:1;min-width:180px}
/* 마감 변동으로 가는 문 — 단타의 #dtMove 와 같은 정사각 버튼 */
#wgMove{flex:0 0 34px;height:32px;display:flex;align-items:center;justify-content:center;background:var(--panel-2);
  border:1px solid var(--line);border-radius:7px;text-decoration:none;font-size:15px;line-height:1}
#wgMove:hover{border-color:var(--accent);background:#1d2740}
#wgSug,#wgVarSug{display:none;position:absolute;top:calc(100% + 3px);left:0;right:0;z-index:60;background:var(--panel-2);border:1px solid var(--line);
  border-radius:8px;box-shadow:0 10px 26px rgba(0,0,0,.55);max-height:330px;overflow-y:auto;padding:4px 0}
#wgSug .s,#wgVarSug .s{display:flex;justify-content:space-between;gap:8px;padding:7px 11px;cursor:pointer;font-size:13px}
#wgSug .s:hover,#wgVarSug .s:hover{background:var(--panel)}
/* .on = 키보드(↓/↑)로 고른 자리 — 공용 모듈(style/acnav.js)이 붙인다 */
#wgSug .s.on,#wgVarSug .s.on{background:#1d2740;box-shadow:inset 3px 0 0 var(--accent)}
#wgSug .s .c,#wgVarSug .s .c{color:var(--ink-mute);font-size:11px;font-family:var(--mono)}
.wg-stat{font-size:11px;color:var(--ink-mute);font-family:var(--mono);white-space:nowrap}
.wg-stat .live{color:#5fd08a;font-weight:800}
.wg-stat .warn{color:#e0a44a;cursor:help}
.dt-chip{background:var(--panel-2);color:var(--ink-mute);border:1px solid var(--line);border-radius:6px;
  padding:3px 9px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap}
.dt-chip:hover{color:var(--ink)}
.dt-chip.on{background:var(--accent);color:#0b1020;border-color:var(--accent)}
.dt-chip.arm{background:#3a1a17;color:#ff8a80;border-color:#5c2a26}
.dt-chip:disabled{opacity:.35;cursor:default}
.dt-seg{display:inline-flex;border:1px solid var(--line);border-radius:7px;overflow:hidden}
.dt-seg button{background:var(--panel-2);color:var(--ink-mute);border:0;font-size:11.5px;font-weight:700;padding:4px 9px;cursor:pointer}
.dt-seg button + button{border-left:1px solid var(--line)}
.dt-seg button:hover:not(.on){color:var(--ink)}
.dt-seg button.on{background:var(--accent);color:#0b1020}

/* 행 격자 — 머리줄과 행이 같은 칸 나눔을 쓴다 */
/* ★2026-10-07 밤 — 종목 칸은 단타 목록과 «같은 행»(3줄 · style/stockrow.js)이다. 현재가·전일비는 그 안으로 들어갔고,
   이 화면의 것(대금 · 기준가 · 기준대비)만 열로 남는다. */
.wg-th,.wg-r{display:grid;grid-template-columns:20px minmax(0,1fr) 46px 92px 68px 22px;gap:0 6px;align-items:center;padding:0 8px}
/* 종목 칸 — 공용 모듈의 여섯 칸이 놓이는 2열 격자(단타 .dt-it 와 같은 나눔) */
.wg-r .info{display:grid;grid-template-columns:1fr auto;gap:2px 8px;min-width:0;padding:7px 0}
/* 메모가 있으면 종목명 뒤에 빨간 삼각형 — 마우스를 올리면 카드(#wgTip · 꼬리표·트리거·무효는 칩) · 작은 글자라 손이 닿는 자리를 넓힌다 */
.wg-r .wg-memo{flex:0 0 auto;margin-left:1px;padding:3px 4px;color:var(--up);font-size:9px;cursor:help;line-height:1}
/* ── 카드형 툴팁(메모) — 포트폴리오 현황 .pf-tip 과 같은 방식(data-tip 의 HTML 을 떠 있는 상자 하나에) · 다크 ── */
.wg-tip{position:fixed;z-index:99;pointer-events:none;min-width:220px;max-width:420px;background:#1b2335;color:var(--ink);
  border:1px solid #3a4766;border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.55);padding:9px 12px 10px;font-size:12px;line-height:1.55}
.wg-tip[hidden]{display:none}
.wg-tip .tt-h{display:flex;align-items:center;gap:6px;padding-bottom:6px;margin-bottom:7px;border-bottom:1px solid var(--line)}
.wg-tip .tt-h b{font-size:13px;font-weight:800}
.wg-tip .tt-h i{font-style:normal;font-size:10.5px;font-weight:700;color:var(--ink-dim);background:var(--panel);border-radius:10px;padding:0 7px}
.wg-tip .tt-h em{font-style:normal;margin-left:auto;padding-left:12px;font-size:11px;color:var(--ink-mute);font-family:var(--mono)}
.wg-tip .tt-c{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:7px}
.wg-tip .mc{display:inline-flex;align-items:baseline;gap:4px;padding:1px 7px;border-radius:5px;font-size:11.5px;font-weight:800;
  border:1px solid var(--line);white-space:nowrap}
.wg-tip .mc small{font-size:10px;font-weight:700;opacity:.8}
.wg-tip .mc b{font-family:var(--mono);font-variant-numeric:tabular-nums}
/* 근거의 꼬리표 — [단독] 주황 · [공시]·[IR] 초록 · [리포트] 파랑 · [기사] 회색 · [추정] 보라 */
.wg-tip .mc.t-a{color:#0b1020;background:var(--accent);border-color:var(--accent)}
.wg-tip .mc.t-g{color:#5fd08a;border-color:#1e5c3a;background:#12341f}
.wg-tip .mc.t-b{color:#7fb1e8;border-color:#33507a;background:#243250}
.wg-tip .mc.t-n{color:var(--ink-dim);background:var(--panel)}
.wg-tip .mc.t-p{color:#c5b3f0;border-color:#4b3f75;background:#2a2342}
/* 트리거(위로 뚫을 가격) 빨강 · 무효(아래로 깨지면 안 되는 가격) 파랑 — 사이트의 오름·내림 색 */
.wg-tip .mc.k-tr{color:#ff8a80;border-color:#5c2a26;background:#2a1412}
.wg-tip .mc.k-iv{color:#7fb1e8;border-color:#33507a;background:#16233a}
.wg-tip .tt-b{margin:0 0 4px;color:var(--ink)}
.wg-tip dl{display:grid;grid-template-columns:auto 1fr;gap:3px 10px;margin:6px 0 0;padding-top:6px;border-top:1px dashed var(--line)}
.wg-tip dt{color:var(--ink-mute);white-space:nowrap;font-size:11px;padding-top:1px}
.wg-tip dd{margin:0;color:var(--ink-dim)}
.wg-th{flex:0 0 auto;height:26px;font-size:10.5px;color:var(--ink-mute);border-bottom:1px solid var(--line);background:var(--panel-2)}
.wg-th span{text-align:right}
.wg-th span:nth-child(2){text-align:left}
#wgRows{flex:1;overflow-y:auto;min-height:0}
.wg-r{min-height:40px;border-bottom:1px solid rgba(38,48,74,.55);cursor:pointer;font-size:12.5px}
.wg-r:hover{background:var(--panel-2)}
.wg-r.on{background:#1d2740;box-shadow:inset 3px 0 0 var(--accent)}
.wg-r.hid{display:none}
.wg-r.sortable-ghost{opacity:.4}
.wg-r .hd{color:var(--ink-mute);cursor:grab;font-size:11px;letter-spacing:-2px;text-align:center;user-select:none;border-radius:4px;outline:none}
.wg-r .hd:focus{box-shadow:0 0 0 1px var(--accent);color:var(--accent)}
body.wg-view .wg-r .hd,body.wg-fixed .wg-r .hd{opacity:.2;cursor:default}
/* 고정 그룹의 기준가는 읽기 전용이다 — 입력칸처럼 보이지 않게 */
body.wg-fixed .wg-r .bs{cursor:default}
body.wg-fixed .wg-r .bs:hover{border-color:transparent;background:transparent}
body.wg-fixed .wg-r .bs:empty::before{content:'—';font-size:12px}
.wg-r .am,.wg-r .vs{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums;white-space:nowrap}
.wg-r .am{color:var(--ink-dim);font-size:11.5px}
.wg-r .vs{font-weight:700}
.wg-r .bs{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums;color:var(--base);cursor:text;
  height:26px;line-height:26px;padding:0 6px;border:1px solid transparent;border-radius:6px;white-space:nowrap}
.wg-r .bs:hover{border-color:var(--line);background:var(--bg)}
.wg-r .bs:empty::before{content:'입력';color:var(--ink-mute);font-family:inherit;font-size:10.5px;opacity:.55}
.wg-r .bs.ed{padding:0;border-color:transparent}
.wg-r .bsin{width:100%;height:26px;box-sizing:border-box;padding:0 6px;background:var(--bg);border:1px solid var(--accent);border-radius:6px;
  color:var(--base);font-family:var(--mono);font-size:12.5px;text-align:right;outline:none}
.wg-r .bsin.bad{border-color:var(--up);box-shadow:0 0 0 1px var(--up)}
.wg-r .mn{border:0;background:transparent;color:var(--ink-mute);cursor:pointer;font-size:14px;line-height:1;padding:2px 0}
.wg-r .mn:hover{color:var(--accent)}
.dt-bdg{display:inline-block;margin-left:5px;padding:0 5px;border-radius:4px;font-size:10px;font-weight:800;border:1px solid;flex:0 0 auto}
.dt-bdg.held{color:#5dd58a;border-color:#1e5c3a;background:#12341f}
/* 사이트 공통 어휘 — 「보유」=돈이 들어감 · 「편입됨」=자리만 있음 */
.dt-bdg.slot{color:#93a4c3;border-color:#2c3a55;background:#1e2637}
/* 구분행 — 이름 있는 줄(HTS 의 BLANK 자리). 접으면 아래 종목과 차트 섹션이 함께 접힌다 */
.wg-r.sep{grid-template-columns:20px 18px minmax(0,1fr) auto 22px;min-height:28px;background:#101727;font-size:12px}
.wg-r.sep:hover{background:#151d30}
.wg-r.sep .tg{border:0;background:transparent;color:var(--ink-dim);cursor:pointer;font-size:11px;padding:0}
.wg-r.sep .lb{font-weight:800;color:var(--accent);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wg-r.sep .sn{color:var(--ink-mute);font-family:var(--mono);font-size:11px}
.wg-r.sep .lbin{width:100%;box-sizing:border-box;height:22px;background:var(--bg);border:1px solid var(--accent);border-radius:5px;
  color:var(--ink);font-size:12px;padding:0 6px;outline:none;font-family:inherit}
.wg-sk{height:62px;border-bottom:1px solid rgba(38,48,74,.55);position:relative}
.wg-sk::after{content:'';position:absolute;left:34px;right:14px;top:13px;height:13px;border-radius:4px;background:var(--panel-2)}

/* ── ③ 멀티 차트 ── */
#wg-charts{flex:1;min-width:0;display:flex;flex-direction:column;min-height:0}
.dt-bar{flex:0 0 auto;display:flex;align-items:center;gap:9px;padding:6px 11px;background:var(--panel);border-bottom:1px solid var(--line);
  flex-wrap:wrap;row-gap:5px}
.dt-t{font-size:13.5px;font-weight:800;letter-spacing:-.01em}
.dt-t .c{color:var(--ink-mute);font-family:var(--mono);font-size:11.5px;font-weight:600;margin-left:6px}
.dt-note{font-size:11.5px;color:var(--ink-mute);margin-left:auto;font-family:var(--mono)}
/* 격자 고르기 — 멀티차트의 「격자 N × M」 그대로 */
.wg-pick{font-size:11.5px;color:var(--ink-dim);white-space:nowrap}
.wg-pick select{background:var(--panel-2);color:var(--ink);border:1px solid var(--line);border-radius:6px;
  padding:2px 4px;font-size:12px;font-family:inherit;font-weight:700}
#wgScroll{flex:1;min-height:0;overflow-y:scroll}
/* 스크롤바 — 어두운 바탕에서 브라우저 기본 막대가 안 보인다(2026-10-12 사용자 실화면). 차트 영역은 굵고 밝게, 리스트·그룹 목록은 조금 가늘게.
   차트 영역은 늘 자리를 잡아 둔다(overflow-y:scroll) — 종목 수에 따라 막대가 생겼다 없어지면 칸 폭이 달라져 차트가 다시 잰다. */
/* ★차트 영역의 막대는 «왼쪽»(리스트와 차트 사이)에 선다(같은 날 사용자 지시 — 넓은 화면에서 오른쪽 끝은 눈과 손이 멀다).
   막대를 왼쪽에 세우는 길은 글 방향뿐이다: 스크롤 상자만 rtl 로 두고 안의 내용은 ltr 로 되돌린다(세로 스크롤이라 다른 것은 안 바뀐다). */
#wgScroll{direction:rtl}
#wgScroll > *{direction:ltr}
#wg-charts #wgScroll::-webkit-scrollbar-track{border-left:0;border-right:1px solid var(--line)}
#wgScroll::-webkit-scrollbar{width:16px}
#wgRows::-webkit-scrollbar,#wgGList::-webkit-scrollbar,.wg-vb::-webkit-scrollbar,#wgMgI::-webkit-scrollbar,#wgMgG::-webkit-scrollbar{width:11px}
#wgScroll::-webkit-scrollbar-track,#wgRows::-webkit-scrollbar-track,#wgGList::-webkit-scrollbar-track,.wg-vb::-webkit-scrollbar-track,
#wgMgI::-webkit-scrollbar-track,#wgMgG::-webkit-scrollbar-track{background:#0a0f1a;border-left:1px solid var(--line)}
#wgScroll::-webkit-scrollbar-thumb,#wgRows::-webkit-scrollbar-thumb,#wgGList::-webkit-scrollbar-thumb,.wg-vb::-webkit-scrollbar-thumb,
#wgMgI::-webkit-scrollbar-thumb,#wgMgG::-webkit-scrollbar-thumb{background:#5b6a8c;border-radius:8px;border:3px solid #0a0f1a;min-height:48px}
#wgScroll::-webkit-scrollbar-thumb{background:#8493b5}
#wgScroll::-webkit-scrollbar-thumb:hover,#wgRows::-webkit-scrollbar-thumb:hover,#wgGList::-webkit-scrollbar-thumb:hover,
#wgScroll::-webkit-scrollbar-thumb:active{background:var(--accent)}
@supports not selector(::-webkit-scrollbar){
  #wgScroll,#wgRows,#wgGList,.wg-vb,#wgMgI,#wgMgG{scrollbar-color:#8493b5 #0a0f1a}
}
#wgGrid{display:grid;gap:1px;background:var(--line);align-content:start}
/* 칸 높이는 «칸»이 갖는다(격자 auto-rows 가 아니라) — 구분행 제목줄이 같은 격자에 제 높이로 서야 해서다 */
.wg-cell{display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--bg);height:var(--wg-h,300px)}
.wg-cell[hidden]{display:none}
.wg-cell.flash{box-shadow:inset 0 0 0 2px var(--accent);z-index:1}
.wg-ch{flex:0 0 auto;display:flex;align-items:baseline;gap:6px;padding:4px 9px 3px;white-space:nowrap;overflow:hidden;font-size:12.5px;
  background:var(--panel);cursor:pointer}
.wg-ch:hover .nm{color:var(--accent)}
.wg-cell.hld .wg-ch{background:rgba(64,178,104,.22)}
.wg-cell.sel .wg-ch{box-shadow:inset 3px 0 0 var(--accent)}
.wg-ch .nm{font-weight:800;overflow:hidden;text-overflow:ellipsis;min-width:0}
.wg-ch .cd{font-family:var(--mono);font-size:11px;color:var(--ink-mute)}
.wg-ch .px{margin-left:auto;font-family:var(--mono);font-weight:700;font-variant-numeric:tabular-nums}
.wg-ch .rt{font-family:var(--mono);font-size:11.5px;font-variant-numeric:tabular-nums}
.wg-ch .bs{font-family:var(--mono);font-size:11px;color:var(--base)}
.wg-ch .bs:empty{display:none}
.wg-ch .st{font-size:10.5px;color:var(--ink-mute);font-family:var(--mono)}
.wg-host{flex:1;min-height:0}
/* 구분행은 차트 격자에서 «전체 폭 제목줄»이 된다 */
.wg-sec{grid-column:1/-1;height:26px;line-height:26px;padding:0 11px;background:#101727;color:var(--accent);font-size:12px;font-weight:800;
  cursor:pointer}
#wgChartMsg[hidden]{display:none}

/* ── 테마 변수 — 색은 --mv 하나(그룹 꼬리표 · 변수 칸 · 겹친 선 · 「유리」가 같은 색이다) ── */
.wg-g .vt{flex:0 0 auto;color:var(--mv);font-size:9px;line-height:1;cursor:help}
.wg-cell.var .wg-ch{background:#0f2028}
/* 좁은 칸에서 줄어드는 것은 분류·단위(.cd)다 — 변수 이름은 끝까지 남는다(이름이 사라지면 무슨 선인지 모른다) */
.wg-cell.var .wg-ch .nm{color:var(--mv);flex:0 0 auto;overflow:visible}
.wg-cell.var .wg-ch .cd{min-width:0;overflow:hidden;text-overflow:ellipsis}
.wg-cell.var .wg-ch .px,.wg-cell.var .wg-ch .rt,.wg-cell.var .wg-ch .vg{flex:0 0 auto}
.wg-cell.var.ovsel .wg-ch{box-shadow:inset 3px 0 0 var(--mv)}
.wg-ch .ovm{flex:0 0 auto;font-size:10px;color:var(--mv);border:1px solid #1f5a57;border-radius:4px;padding:0 4px}
.wg-ch .ovm[hidden]{display:none}
.wg-ch .vg{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;color:var(--ink-dim);font-family:var(--mono)}
.wg-ch .vg i{position:relative;display:inline-block;width:54px;height:5px;border-radius:3px;background:var(--panel-2)}
.wg-ch .vg i b{position:absolute;top:-2px;width:5px;height:9px;margin-left:-2px;border-radius:2px;background:var(--mv)}
/* 구분행의 방향 — 테마 그룹에서만 보인다. fav = 주 변수가 그 방향으로 움직인 날 */
.wg-r.sep .dr{border:1px solid var(--line);background:transparent;color:var(--ink-mute);border-radius:5px;font-size:10.5px;font-weight:800;
  padding:1px 6px;margin-right:6px;cursor:pointer;font-family:inherit;line-height:1.5}
body:not(.wg-theme) .wg-r.sep .dr,body.wg-fixed .wg-r.sep .dr{display:none}
.wg-r.sep .dr.set{color:var(--ink-dim)}
.wg-r.sep .dr.fav{color:#0b1020;background:var(--mv);border-color:var(--mv)}
.wg-sec .fv{margin-left:9px;font-size:11px;font-weight:700;color:var(--ink-mute)}
.wg-sec .fv.fav{color:var(--mv)}
/* 종목별 «같이 볼 변수» — 호박색(그룹의 변수는 청록) */
.wg-r .wg-iv{flex:0 0 auto;margin-left:5px;color:#e0a44a;font-size:9px;cursor:help;line-height:1}
.wg-ch .iv{flex:0 0 auto;font-size:10.5px;color:#e0a44a;font-family:var(--mono)}
.wg-ch .iv:empty{display:none}
#wgMenu .mi .ck{color:var(--mv)}
#wgOv[hidden]{display:none}
#wgOv.on{background:var(--mv);border-color:var(--mv)}
#wgVarBox{width:580px;max-width:94vw;max-height:88vh;display:flex;flex-direction:column}
.wg-vb{flex:1;min-height:0;overflow-y:auto;padding:2px 15px 15px}
.wg-vh{margin:13px 0 6px;font-size:12.5px;font-weight:800}
.wg-vh span{margin-left:7px;font-weight:400;font-size:11px;color:var(--ink-mute)}
.wg-vr{display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid var(--line);border-radius:7px;margin-bottom:5px;font-size:12.5px;background:var(--panel-2)}
.wg-vr .k{flex:0 0 auto;font-size:10px;font-weight:800;color:#0b1020;background:var(--mv);border-radius:4px;padding:0 5px}
.wg-vr .k.sub{background:transparent;color:var(--ink-mute);border:1px solid var(--line)}
.wg-vr .nm{font-weight:700;white-space:nowrap}
.wg-vr small{color:var(--ink-mute);font-size:11px;font-family:var(--mono);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.wg-vr .sp{flex:1}
.wg-vr .wg-btn{flex:0 0 auto;padding:2px 8px;font-size:11px}
.wg-vnone{color:var(--ink-mute);font-size:12px;padding:6px 2px;line-height:1.7}
#wgVar .wg-qwrap{display:block}

/* ── 메뉴 · 모달 · 토스트 ── */
#wgMenu{position:fixed;z-index:80;min-width:190px;background:var(--panel-2);border:1px solid var(--line);border-radius:9px;
  box-shadow:0 12px 30px rgba(0,0,0,.6);padding:5px 0;font-size:12.5px}
#wgMenu[hidden]{display:none}
#wgMenu .mi{display:flex;justify-content:space-between;gap:14px;padding:7px 13px;cursor:pointer;color:var(--ink)}
#wgMenu .mi:hover{background:#26314c}
#wgMenu .mi.dg{color:#ff8a80}
#wgMenu .mi small{color:var(--ink-mute);font-family:var(--mono)}
#wgMenu .ml{height:1px;background:var(--line);margin:4px 0}
#wgMenu .mh{padding:6px 13px;color:var(--ink-mute);font-size:11px}
.wg-ov{position:fixed;inset:0;z-index:85;background:rgba(5,8,15,.66);display:flex;align-items:center;justify-content:center}
.wg-ov[hidden]{display:none}
.wg-box{background:var(--panel);border:1px solid var(--line);border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.6);color:var(--ink)}
.wg-box .bh{display:flex;align-items:center;justify-content:space-between;padding:11px 15px;border-bottom:1px solid var(--line);font-size:14px}
.wg-box .bx{border:0;background:transparent;color:var(--ink-mute);font-size:18px;cursor:pointer;line-height:1}
.wg-box .bx:hover{color:var(--ink)}
.wg-btn{background:var(--panel-2);color:var(--ink);border:1px solid var(--line);border-radius:7px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer}
.wg-btn:hover{border-color:var(--accent)}
.wg-btn.pri{background:var(--accent);color:#0b1020;border-color:var(--accent)}
.wg-btn.dg{color:#ff8a80;border-color:#5c2a26}
.wg-btn.arm{background:#3a1a17;color:#ff8a80;border-color:#5c2a26}
.wg-btn:disabled{opacity:.4;cursor:default}
#wgMemoBox{width:420px;max-width:92vw}
#wgMemoTx{display:block;width:calc(100% - 30px);margin:13px 15px 6px;height:130px;box-sizing:border-box;background:var(--bg);border:1px solid var(--line);
  border-radius:8px;color:var(--ink);font-size:13px;padding:9px;resize:vertical;outline:none;font-family:inherit;line-height:1.6}
#wgMemoTx:focus{border-color:var(--accent)}
.wg-bf{display:flex;align-items:center;gap:8px;padding:6px 15px 13px;font-size:11px;color:var(--ink-mute)}
.wg-bf .sp{flex:1}
/* 그룹 관리 */
#wgMgBox{width:980px;max-width:96vw;height:680px;max-height:92vh;display:flex;flex-direction:column}
.wg-mb{flex:1;min-height:0;display:flex}
.wg-ml{flex:0 0 280px;border-right:1px solid var(--line);display:flex;flex-direction:column;min-height:0}
#wgMgG{flex:1;overflow-y:auto;min-height:0}
.mg-g{border-bottom:1px solid rgba(38,48,74,.55);font-size:13px}
.mg-g .rw{display:flex;align-items:center;gap:6px;padding:7px 9px;cursor:pointer}
.mg-g:hover .rw{background:var(--panel-2)}
.mg-g.on .rw{background:#1d2740;box-shadow:inset 3px 0 0 var(--accent)}
.mg-g .hd{color:var(--ink-mute);cursor:grab;letter-spacing:-2px;font-size:11px}
.mg-g .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mg-g .n{color:var(--ink-mute);font-family:var(--mono);font-size:11px}
.mg-g .ic{border:0;background:transparent;color:var(--ink-mute);cursor:pointer;font-size:12px;padding:1px 3px}
.mg-g .ic:hover{color:var(--accent)}
.mg-g .cf{padding:8px 10px 10px 30px;background:#2a1412;color:#ffb4ab;font-size:12px;line-height:1.6}
.mg-g .cf div{margin-top:6px;display:flex;gap:6px}
.wg-hint{flex:0 0 auto;padding:7px 10px;font-size:11px;color:var(--ink-mute);border-top:1px solid var(--line)}
.wg-mr{flex:1;min-width:0;display:flex;flex-direction:column;min-height:0}
.wg-mt{flex:0 0 auto;display:flex;align-items:center;gap:7px;padding:8px 12px;border-bottom:1px solid var(--line);font-size:12px;flex-wrap:wrap}
.wg-mt select,.wg-pa select{background:var(--panel-2);color:var(--ink);border:1px solid var(--line);border-radius:6px;padding:4px 6px;font-size:12px;font-family:inherit}
.wg-mt .sp{flex:1}
#wgMgI{flex:1;overflow-y:auto;min-height:0}
.mg-i{display:flex;align-items:center;gap:8px;padding:5px 12px;border-bottom:1px solid rgba(38,48,74,.4);font-size:12.5px;cursor:pointer}
.mg-i:hover{background:var(--panel-2)}
.mg-i .c{color:var(--ink-mute);font-family:var(--mono);font-size:11px}
.mg-i .b{margin-left:auto;color:var(--base);font-family:var(--mono);font-size:11.5px}
.mg-i .m{color:var(--up);font-size:10px}
.wg-mp{flex:0 0 auto;border-top:1px solid var(--line);padding:9px 12px 12px;font-size:12px}
.wg-mp > span{color:var(--ink-mute);margin-left:6px;font-size:11px}
#wgMgTx{display:block;width:100%;height:74px;box-sizing:border-box;margin:7px 0;background:var(--bg);border:1px solid var(--line);border-radius:8px;
  color:var(--ink);font-size:12.5px;padding:7px 9px;resize:vertical;outline:none;font-family:var(--mono)}
#wgMgTx:focus{border-color:var(--accent)}
/* 파일을 창 위로 끌고 오는 동안 — 놓을 자리(칸)를 밝힌다 */
#wgMgTx.over{border-color:var(--mv);box-shadow:0 0 0 2px rgba(63,208,201,.35)}
.wg-pa{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.wg-pa input{width:150px}
#wgMgPr{margin-top:7px;color:var(--ink-dim);line-height:1.7;max-height:66px;overflow-y:auto}
#wgMgPr b{color:var(--ink)}
#wgMgPr .ms{color:#e0a44a}
#wgToast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);z-index:95;background:#1d2740;border:1px solid var(--line);color:var(--ink);
  font-size:12.5px;padding:9px 16px;border-radius:9px;box-shadow:0 10px 26px rgba(0,0,0,.5);display:none}

/* ── 테마 노트 — 테마 md 로 가져온 그룹의 원문(노트·일정·출처). 판정·신호는 보이지 않는다(보는 자리) ── */
.wg-tn{flex:0 0 auto;border-bottom:1px solid var(--line);background:#101727;font-size:12px}
.wg-tn[hidden]{display:none}
.wg-tn .tn-h{display:flex;align-items:center;gap:8px;padding:6px 10px;cursor:pointer;white-space:nowrap;overflow:hidden}
.wg-tn .tn-h:hover b{color:var(--accent)}
.wg-tn .tg{border:0;background:transparent;color:var(--ink-dim);cursor:pointer;font-size:11px;padding:0}
.wg-tn .tn-m{color:var(--ink-mute);font-family:var(--mono);font-size:11px}
.wg-tn .rv{font-size:11px;color:var(--ink-dim);border:1px solid var(--line);border-radius:4px;padding:0 5px}
.wg-tn .rv.late{color:#0b1020;background:var(--accent);border-color:var(--accent);font-weight:800}
.wg-tn .tn-s{color:var(--accent);font-size:11px}
.wg-tn .sp{flex:1}
.wg-tn .tn-b{max-height:42vh;overflow-y:auto;padding:2px 14px 12px;line-height:1.65;color:var(--ink-dim)}
.wg-tn .tn-t{font-weight:800;color:var(--ink);margin:6px 0 2px}
.wg-tn .tn-b h3{margin:10px 0 4px;font-size:12.5px;color:var(--accent)}
.wg-tn .tn-b h2,.wg-tn .tn-b h4,.wg-tn .tn-b h5{font-size:12px;margin:8px 0 3px;color:var(--ink)}
.wg-tn .tn-b p{margin:4px 0}
.wg-tn .tn-b a{color:var(--accent)}
.wg-tn .tn-b b{color:var(--ink)}
.wg-tn .tn-b ul,.wg-tn .tn-b ol{margin:4px 0;padding-left:18px}
.wg-tn .tn-b table{border-collapse:collapse;font-size:11.5px;margin:4px 0}
.wg-tn .tn-b th,.wg-tn .tn-b td{border:1px solid var(--line);padding:3px 7px;text-align:left;vertical-align:top}
.wg-tn .tn-b th{background:var(--panel-2);color:var(--ink)}
/* ── 테마 md 미리보기 — 모달 오른쪽을 통째로 쓴다(종목 목록·복사 줄·옛 대상 고르기는 접는다) ── */
#wgMgBox.th-on .wg-mt,#wgMgBox.th-on #wgMgI{display:none}
#wgMgBox.th-on .wg-mp{flex:1;min-height:0;overflow-y:auto}
#wgMgBox.th-on #wgMgPr{max-height:none}
#wgMgBox.th-on #wgMgPt,#wgMgBox.th-on #wgMgPn,#wgMgBox.th-on #wgMgAdd,#wgMgBox.th-on .wg-pa > span{display:none}
#wgThGo[hidden]{display:none}
.th-hd{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:2px 0 6px}
.th-hd .wg-in{width:190px;padding:3px 7px;font-size:12.5px}
.th-hd .th-ex{color:var(--accent);font-size:11.5px}
.th-hd .th-nw{color:#5fd08a;font-size:11.5px}
.th-hd .th-fn{color:var(--ink-dim);font-size:11.5px;font-family:var(--mono)}
.th-meta{color:var(--ink-mute);font-size:11.5px;margin-bottom:6px}
.th-meta a{color:var(--accent)}
.th-err{color:#ff8a80;margin:3px 0}
.th-warn{color:#e0a44a;margin:3px 0}
.th-cnt{color:var(--ink);margin:6px 0 2px}
.th-box{margin:6px 0;padding:6px 9px;border:1px solid var(--line);border-radius:7px;background:var(--panel-2)}
.th-box b{color:var(--ink)}
.th-box ul{margin:3px 0 0;padding-left:16px}
.th-t{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}
.th-t th{position:sticky;top:0;background:var(--panel-2);color:var(--ink-mute);font-weight:700;font-size:11px;text-align:left;padding:4px 6px}
.th-t td{padding:4px 6px;border-bottom:1px solid rgba(38,48,74,.5);vertical-align:top}
.th-t tr.sec td{background:#101727;color:var(--accent);font-weight:800}
.th-t tr.sec .k{color:var(--ink-mute);font-weight:400;font-size:11px;margin-left:6px}
.th-t tr.x td{opacity:.62}
.th-t .c{color:var(--ink-mute);font-family:var(--mono);font-size:11px;margin-left:4px}
.th-t .px{font-family:var(--mono);text-align:right;white-space:nowrap;color:var(--base)}
.th-t .px small{color:var(--ink-mute);font-size:10.5px;margin-left:3px}
.th-t .ml{color:var(--ink-mute);font-family:var(--mono);font-size:11px;white-space:nowrap;cursor:help}
.th-t .nt{font-size:11px;line-height:1.5}
.th-st{display:inline-block;padding:0 5px;border-radius:4px;font-size:10.5px;font-weight:800;border:1px solid var(--line);white-space:nowrap}
.th-st.new{color:#5fd08a;border-color:#1e5c3a}
.th-st.keep{color:var(--ink-dim)}
.th-st.fill{color:var(--base);border-color:#5a4a22}
.th-st.bad{color:#ff8a80;border-color:#5c2a26}
.th-st.over{color:#e0a44a;border-color:#5a4a22}
.th-pv{display:inline-block;font-size:10px;color:#e0a44a;border:1px solid #5a4a22;border-radius:3px;padding:0 3px;margin-left:4px}

/* 좁은 화면 — 차트 영역이 아래로 내려간다(모바일 최적화는 범위 밖) */
@media (max-width:1100px){
  html,body{height:auto;overflow:auto}
  #wg-shell{flex-wrap:wrap}
  #wg-groups,#wg-list{height:70vh}
  #wg-list{flex:1 1 380px}
  #wg-charts{flex:0 0 100%;height:90vh;border-top:1px solid var(--line)}
}
</style>
CSS;

    echo '</head><body class="dt-dark">';
    pf_topbar('wgroup');

    /* 표가 아직 없다 — 화면은 DDL 을 하지 않는다. 만들기는 명시적 행동(api schema)이다. */
    if (!$ready) {
        echo '<div class="wg-msg" style="margin-top:60px">관심그룹 표(wg_group · wg_item)가 아직 없습니다.<br><br>'
           . '<button type="button" class="wg-btn pri" id="wgInit">표 만들기</button></div>';
        echo <<<'JS'
<script>
document.getElementById('wgInit').onclick = function(){
  var b = this; b.disabled = true;
  fetch('/stock/api.php?module=wg&action=schema', { method:'POST', credentials:'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(j){ if (j && j.ok) location.reload(); else { b.disabled = false; b.textContent = (j && j.error) || '만들지 못했습니다 — 다시 시도'; } })
    .catch(function(){ b.disabled = false; });
};
</script>
JS;
        echo '</body></html>';
        return;
    }

    // ── ① 그룹 목록 ─────────────────────────────────────────────────
    echo '<div id="wg-shell"><aside id="wg-groups">';
    echo '<div class="wg-gh"><b>관심그룹</b><button type="button" id="wgGAdd" title="새 그룹">＋</button></div>';
    echo '<div id="wgGList"></div>';
    echo '<div id="wgGNew" hidden><input class="wg-in" id="wgGNewIn" maxlength="' . Wg::NAME_MAX
       . '" placeholder="새 그룹 이름 (Enter)" autocomplete="off"></div>';
    echo '<button type="button" class="wg-gfoot" id="wgMgBtn">그룹 관리 · 일괄등록</button>';
    echo '</aside>';

    // ── ② 종목 리스트 ───────────────────────────────────────────────
    echo '<section id="wg-list">';
    echo '<div class="wg-lh"><div class="wg-qwrap">'
       . '<input class="wg-in" id="wgQ" placeholder="종목명·코드로 추가 ( / )" autocomplete="off"><div id="wgSug"></div></div>'
       /* 마감 변동으로 가는 문 — 단타 목록 머리줄의 📊 와 같은 자리(검색칸 오른쪽)·같은 모양.
        * 여기서는 «지금 보고 있는 그룹»의 변동을 연다(mode=move&g= · 주소는 화면이 그룹을 바꿀 때마다 맞춘다). */
       . '<a id="wgMove" href="/stock/index.php?mode=move" title="📊 마감 변동 — 이 그룹에서 오늘 ±' . Thr::pct(Thr::EOD_MOVE_PCT)
       . '% 넘게 움직인 종목의 뉴스와 미니 일봉">📊</a>'
       . '<button type="button" class="dt-chip" id="wgSepAdd" title="이름 있는 구분행을 맨 아래에 추가합니다">＋ 구분행</button>'
       . '<span class="wg-stat" id="wgStat"></span></div>';
    echo '<div class="wg-lh"><span class="dt-seg" id="wgSort">'
       . '<button type="button" data-s="mine" class="on" title="저장된 순서 — 끌어서 바꾸고 구분행이 보입니다">내 순서</button>'
       . '<button type="button" data-s="rate" title="보기 전용 — 전일 대비 등락률 큰 순 (시세가 바뀌면 줄이 다시 섭니다)">상승률순</button>'
       . '<button type="button" data-s="base" title="보기 전용 — 기준가 대비 큰 순 (기준가 없는 종목은 맨 아래)">기준대비순</button></span>'
       . '<button type="button" class="dt-chip" id="wgBaseAll" title="그룹 전체의 기준가를 지금 현재가로 — 오늘부터 추적을 시작합니다">기준가 = 현재가 (전체)</button>'
       . '<button type="button" class="dt-chip" id="wgBaseClr" title="그룹 전체의 기준가를 지웁니다">기준가 지우기</button></div>';
    // 고정 그룹일 때만 보이는 한 줄 — 「왜 편집이 안 되는지」와 「어디서 고치는지」를 적는다
    echo '<div class="wg-fix" id="wgFix" hidden></div>';
    // 테마 노트 — 테마 md 로 가져온 그룹일 때만 선다(접힌 한 줄 → 펴면 원문의 노트·일정·출처)
    echo '<div class="wg-tn" id="wgTn" hidden></div>';
    echo '<div class="wg-th"><span></span><span>종목 · 현재가 · 전일비</span>'
       . '<span title="거래대금 — 십억 원(정수 · HTS 와 같은 단위)">대금</span><span>기준가</span><span>기준대비</span><span></span></div>';
    echo '<div id="wgRows"></div>';
    echo '</section>';

    // ── ③ 멀티 차트 ─────────────────────────────────────────────────
    echo '<main id="wg-charts"><div class="dt-bar" id="wgBar">';
    echo '<span class="dt-t" id="wgTitle">차트<span class="c" id="wgCnt"></span></span>';
    /* 격자 — 가로 × 세로 칸 수(2026-10-07 밤 사용자 지시 — 멀티차트 메뉴를 없애고 그 배열 방식을 이리로 옮겼다. 예전엔 1·2·3열).
     * 세로 칸 수 = «한 화면에 몇 줄이 보이나»다(칸 높이 = 보이는 높이 ÷ 줄 수). 넘치는 종목은 아래로 스크롤한다. */
    echo '<span class="wg-pick" title="가로 × 세로 칸 수 — 한 화면에 그만큼이 보이고, 나머지는 아래로 스크롤합니다">격자 <select id="wgGC">';
    for ($i = 1; $i <= 6; $i++) echo '<option value="' . $i . '">' . $i . '</option>';
    echo '</select> × <select id="wgGR">';
    for ($i = 1; $i <= 4; $i++) echo '<option value="' . $i . '">' . $i . '</option>';
    echo '</select></span>';
    /* 테마 변수 — 「변수」 = 이 그룹에 변수를 연결·등록하는 모달 · 「겹치기」 = 종목 차트에 변수 선을 겹칠지(테마 그룹에서만 보인다) */
    echo '<button type="button" class="dt-chip" id="wgVarBtn" title="이 그룹에 시장 변수(환율·유가·금리…)를 연결합니다 — 연결하면 차트 맨 앞에 변수의 추이가 서고 종목 차트마다 변수 선이 겹칩니다">변수</button>'
       . '<button type="button" class="dt-chip on" id="wgOv" hidden title="그룹의 변수 선을 종목 차트에 겹칩니다 — 어느 변수를 겹칠지는 변수 칸의 머리줄을 눌러 고릅니다. 종목에 따로 단 「같이 볼 변수」(호박색 점선)는 이 스위치와 무관하게 늘 보입니다">겹치기</button>';
    if (!empty($F['overlay.intraday_ref'])) {
        // 단타 일봉 패널·멀티차트와 같은 두 칩 — 격자 전체에 한꺼번에 먹는다
        echo '<button type="button" id="wgTH" class="dt-chip on"'
           . ' title="당일 기준 전고점(직전 60봉 최고가) 수평선 — 관찰용 기준선">당일전고</button>'
           . '<button type="button" id="wgCP" class="dt-chip" title="현재가격선 표시/숨김">현재가</button>';
    }
    /* 도구모음은 모듈이 그린다 — 기간 바와 지표 바. 바 «하나»가 격자 전체를 조종한다(멀티차트와 같다) */
    echo '<span id="wgPBar"></span><span id="wgIBar"></span>';
    echo '<span class="dt-note" id="wgNote"></span></div>';
    echo '<div id="wgScroll"><div id="wgGrid"></div>'
       . '<div class="wg-msg" id="wgChartMsg" hidden>종목을 추가하면 여기에 차트가 그려집니다.</div></div>';
    echo '</main></div>';

    // ── 메뉴 · 메모 · 그룹 관리 · 토스트 ────────────────────────────
    echo '<div id="wgMenu" hidden></div>';
    echo '<div class="wg-ov" id="wgMemo" hidden><div class="wg-box" id="wgMemoBox">'
       . '<div class="bh"><b id="wgMemoT">메모</b><button type="button" class="bx" id="wgMemoX">×</button></div>'
       . '<textarea id="wgMemoTx" maxlength="' . Wg::MEMO_MAX . '" placeholder="메모 (최대 ' . Wg::MEMO_MAX . '자)"></textarea>'
       . '<div class="wg-bf"><span id="wgMemoN"></span><span class="sp"></span><span>Ctrl+Enter 저장 · Esc 취소</span>'
       . '<button type="button" class="wg-btn" id="wgMemoC">취소</button>'
       . '<button type="button" class="wg-btn pri" id="wgMemoS">저장</button></div></div></div>';
    echo '<div class="wg-ov" id="wgMg" hidden><div class="wg-box" id="wgMgBox">'
       . '<div class="bh"><b>그룹 관리 · 일괄 등록</b><button type="button" class="bx" id="wgMgX">×</button></div>'
       . '<div class="wg-mb"><div class="wg-ml"><div id="wgMgG"></div>'
       . '<div class="wg-hint">끌어서 순서 변경 · 두 번 눌러 이름 변경 · 바꾸는 즉시 저장됩니다</div></div>'
       . '<div class="wg-mr"><div class="wg-mt"><b id="wgMgT"></b>'
       . '<label><input type="checkbox" id="wgMgAll"> 전체</label><span class="sp"></span>'
       . '<select id="wgMgTo"></select>'
       . '<button type="button" class="wg-btn" id="wgMgCopy" title="기준가·메모도 함께 갑니다">복사</button>'
       . '<button type="button" class="wg-btn" id="wgMgMove" title="기준가·메모도 함께 갑니다">이동</button>'
       . '<button type="button" class="wg-btn dg" id="wgMgDel">삭제</button></div>'
       . '<div id="wgMgI"></div>'
       . '<div class="wg-mp"><b>일괄 등록</b><span>코드·종목명을 붙여넣으세요 — 줄바꿈·탭·콤마로 구분 (종목명은 정확히 일치해야 합니다) · <b style="color:var(--mv)">표(종목 │ 근거 …)</b>를 통째로 붙이면 근거는 메모로, 「~ 때 유리」 줄은 구분행으로 담습니다'
       . ' · <b style="color:var(--mv)">테마 md</b>는 「📄 md 파일」로 고르거나 이 창에 끌어다 놓으세요</span>'
       . '<textarea id="wgMgTx" placeholder="005930&#10;SK하이닉스&#10;000660, 035420"></textarea>'
       . '<div class="wg-pa"><button type="button" class="wg-btn" id="wgMgPv">미리보기</button>'
       /* 테마 md 파일 — 고르면(또는 창에 끌어다 놓으면) 글을 칸에 넣고 바로 미리보기(리서치 화면과 같은 방식) */
       . '<button type="button" class="wg-btn" id="wgMgFileBtn" title="테마 md 파일(.md)을 골라 올립니다 — 이 창에 끌어다 놓아도 됩니다">📄 md 파일</button>'
       . '<input type="file" id="wgMgFile" accept=".md,.markdown,.txt,text/markdown,text/plain" hidden>'
       . '<span>대상</span><select id="wgMgPt"></select>'
       . '<input class="wg-in" id="wgMgPn" maxlength="' . Wg::NAME_MAX . '" placeholder="새 그룹 이름" hidden>'
       . '<button type="button" class="wg-btn pri" id="wgMgAdd" disabled>추가</button>'
       /* 테마 md 로 읽혔을 때만 — 대상은 md 의 group 이 정하고, 적용은 3초 확인(arm) */
       . '<button type="button" class="wg-btn pri" id="wgThGo" hidden disabled>적용</button></div>'
       . '<div id="wgMgPr"></div></div></div></div></div></div>';
    echo '<div class="wg-ov" id="wgVar" hidden><div class="wg-box" id="wgVarBox">'
       . '<div class="bh"><b id="wgVarT">테마 변수</b><button type="button" class="bx" id="wgVarX">×</button></div>'
       . '<div class="wg-vb">'
       . '<div class="wg-vh">이 그룹의 변수<span>첫째가 주 변수 — 구분행의 「유리」를 정합니다 · 최대 ' . Mv::GROUP_VAR_MAX . '개</span></div>'
       . '<div id="wgVarG"></div>'
       . '<div class="wg-vh">변수 찾기<span>환율 · 유가 · 금리 · 금속 · 운임지수 — 고르면 등록하고 이 그룹에 연결합니다</span></div>'
       . '<div class="wg-qwrap"><input class="wg-in" id="wgVarQ" placeholder="예: 달러, WTI, 국채, 구리, BDI" autocomplete="off"><div id="wgVarSug"></div></div>'
       . '<div class="wg-vh">등록된 변수<span id="wgVarCnt"></span></div>'
       . '<div id="wgVarAll"></div>'
       . '</div></div></div>';
    echo '<div id="wgToast"></div>';
    echo '<div id="wgTip" class="wg-tip" hidden></div>';     // 카드형 툴팁(메모) — 하나를 갈아 끼운다

    echo '<script src="/style/dailychart.js?v=63"></script>';
    // 종목 리스트의 행 = 단타 목록과 «같은 행» — 배지의 그리기(QuantBadge)와 행의 칸(StockRow)은 공용 모듈이다
    echo '<script src="/style/quantbadge.js?v=6"></script>';
    echo '<script src="/style/stockrow.js?v=1"></script>';
    /* 배지 표시 설정(설정 > 신호분석 설정 · BadgeFeat 'wgroup') — 판정은 서버가 그대로 내리고, 끄는 것은 «그리기»뿐이다 */
    echo BadgeFeat::script(BadgeFeat::vals('wgroup', $pdo));
    echo '<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>';
    pf_acnav_js();                              // 종목 추가 검색창의 ↓/↑ 이동
    echo ChartFeat::boot('wgroup', $pdo);       // DC_SCREEN·DC_FEATS·DC_VIEW — 기간 기억은 'wgroup' 행
    echo '<script>var WG_GROUPS=' . json_encode($groups, JSON_UNESCAPED_UNICODE)
       . ';var WG_GID=' . $gid
       . ';var WG_ITEMS=' . json_encode($items, JSON_UNESCAPED_UNICODE)
       . ';var WG_POOL=' . $pool
       . ';var WG_TS=' . json_encode(date('H:i:s'))
       /* 주기는 Dt::TICK_SEC 한 숫자다 — 단타의 목록·차트 타이머·서버 신선도 창과 같은 값 */
       . ';var WG_TICK=' . (Dt::TICK_SEC * 1000)
       . ';var WG_BASE_MAX=' . Wg::BASE_MAX
       /* 그룹당 종목 상한 · 평균 등락을 다시 읽는 주기 · 「장중에 이보다 낡으면 낡은 값」(사이트의 장중 신선도 상수 그대로) */
       . ';var WG_MAX=' . Wg::ITEM_MAX
       . ';var WG_STAT=' . (Wg::STAT_SEC * 1000)
       . ';var WG_FRESH=' . NaverFinanceAPI::QUOTE_MAX_AGE
       /* 테마 변수 — 이 그룹에 연결한 변수 · 그룹당 상한 · 변수 표가 있는가 */
       . ';var WG_VARS=' . json_encode($vars, JSON_UNESCAPED_UNICODE)
       . ';var WG_VALL=' . json_encode($vall, JSON_UNESCAPED_UNICODE)
       . ';var WG_VMAX=' . Mv::GROUP_VAR_MAX
       . ';var WG_MV=' . ($mvReady ? 1 : 0)
       /* 테마 노트(없으면 null) · 테마 md 원문 상한(넘으면 서버가 거절한다 — 화면은 보내기 전에 미리 말한다) */
       . ';var WG_THEME=' . json_encode($theme, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
       . ';var WG_THEME_MAX=' . Wg::THEME_MAX_CHARS . ';</script>';

    echo <<<'JS'
<script>
(function(){
  'use strict';
  var API  = '/stock/api.php?module=wg&action=';
  var BASE = '/stock/index.php?mode=wgroup';
  var $    = function(id){ return document.getElementById(id); };
  var F    = DailyChart.feats;
  /* 상태의 주인은 S 하나다 — 리스트·차트·그룹 목록이 전부 이것을 따라 그린다 */
  var S = { groups: WG_GROUPS, gid: WG_GID, items: WG_ITEMS, sort: 'mine', sel: 0, cols: 2,
            fold: {}, edit: false, menu: 0,
            vars: WG_VARS || [], ov: 0,            // vars = 이 그룹의 테마 변수(첫째가 주 변수) · ov = 종목 차트에 겹치는 변수
            vall: WG_VALL || [],                   // 등록된 변수 전부(종목별 «같이 볼 변수»가 이름을 여기서 찾는다)
            theme: WG_THEME || null };             // 테마 노트(테마 md 로 가져온 그룹일 때만)

  // ── 작은 도구 ─────────────────────────────────────────────────────
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }
  function fmt(n){ return Math.round(Number(n) || 0).toLocaleString(); }
  function cls(v){ return v > 0 ? 'up' : (v < 0 ? 'down' : 'flat'); }
  function pct(v){ return (v > 0 ? '+' : '') + Number(v).toFixed(2) + '%'; }
  /* 기준대비(%) = (현재가 − 기준가) ÷ 기준가 × 100 — 저장하지 않는 파생값이라 시세가 올 때마다 다시 만든다 */
  function vsBase(it){ return (it.base && it.price) ? (it.price - it.base) / it.base * 100 : null; }
  function isStock(it){ return it.type === 'stock'; }
  function byId(id){ for (var i = 0; i < S.items.length; i++) if (S.items[i].id === id) return S.items[i]; return null; }
  /* 그룹 — 고정 그룹(fixed · 보유종목·Top-Pick)은 맨 위에 서고 고칠 수 없다. key 는 주소·쿠키에 쓰는 값(held·top·<id>) */
  function groupOf(id){ for (var i = 0; i < S.groups.length; i++) if (S.groups[i].id === id) return S.groups[i]; return null; }
  function groupName(id){ var g = groupOf(id); return g ? g.name : ''; }
  function gKey(id){ var g = groupOf(id); return g ? g.key : String(id || ''); }
  function isFixed(){ var g = groupOf(S.gid); return !!(g && g.fixed); }
  function userGroups(){ return S.groups.filter(function(g){ return !g.fixed; }); }
  var FIXED_MSG = '고정 그룹은 여기서 편집할 수 없습니다.';
  function rowEl(id){ return $('wgRows').querySelector('.wg-r[data-id="' + id + '"]'); }
  function isOpen(){
    var d = new Date(), h = d.getHours() * 100 + d.getMinutes(), w = d.getDay();
    return w >= 1 && w <= 5 && h >= 900 && h < 1530;
  }
  function lsGet(k){ try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v){ try { localStorage.setItem(k, v); } catch (e) {} }
  function toast(t){
    var el = $('wgToast'); el.textContent = t; el.style.display = 'block';
    clearTimeout(el._t); el._t = setTimeout(function(){ el.style.display = 'none'; }, 2800);
  }
  /* 확인이 필요한 버튼 — 브라우저 confirm() 을 쓰지 않는다. 한 번 누르면 3초 동안 「정말?」로 바뀌고,
     그 안에 다시 눌러야 실행된다. */
  function arm(btn, askText, fn){
    var orig = btn.textContent, cl = btn.classList;
    btn.addEventListener('click', function(){
      if (cl.contains('arm')) { reset(); fn(); return; }
      orig = btn.textContent;                     // 글자가 바뀌는 버튼도 있다(테마 「적용」·「갈아끼우기」) — 누른 때의 글자로 돌아간다
      cl.add('arm'); btn.textContent = (typeof askText === 'function') ? askText() : askText;
      btn._t = setTimeout(reset, 3000);
    });
    function reset(){ clearTimeout(btn._t); cl.remove('arm'); btn.textContent = orig; }
  }
  function reply(p){
    return p.then(function(r){ return r.json(); })
      .catch(function(){ throw new Error('응답을 읽지 못했습니다 (로그인이 풀렸을 수 있습니다)'); })
      .then(function(j){
        if (!j || !j.ok) throw new Error((j && j.error) || '처리하지 못했습니다.');
        return j.data;
      });
  }
  function call(action, body){
    return reply(fetch(API + action, { method:'POST', credentials:'same-origin',
                                       headers:{ 'Content-Type':'application/json' },
                                       body: JSON.stringify(body || {}) }));
  }
  /* 테마 md — 원문은 multipart «파일 조각»(md)으로 보낸다. 앞단(openresty)이 일반 POST 본문을 128KB 에서 자른다(리서치와 같은 길) */
  function callForm(action, f){
    var fd = new FormData();
    Object.keys(f).forEach(function(k){
      if (k === 'md') fd.append('md', new Blob([f.md], { type:'text/markdown' }), 'theme.md');
      else fd.append(k, f[k] == null ? '' : String(f[k]));
    });
    return reply(fetch(API + action, { method:'POST', credentials:'same-origin', body: fd }));
  }
  function fail(e){ toast((e && e.message) || '처리하지 못했습니다.'); }

  // 접힌 구분행·격자는 «내 화면 취향»이라 localStorage 에 둔다(시세가 아니라 오래 두어도 거짓이 되지 않는다)
  try { S.fold = JSON.parse(lsGet('wg_fold') || '{}') || {}; } catch (e) { S.fold = {}; }
  /* 격자 — 'wg_grid' = "가로x세로". 처음엔 4×4(사용자 지정). 예전 값(wg_cols = 1~3열)이 있으면 가로만 물려받는다 */
  (function(){
    var m = /^(\d)x(\d)$/.exec(lsGet('wg_grid') || ''), old = parseInt(lsGet('wg_cols') || '', 10);
    S.cols = m ? +m[1] : (old >= 1 && old <= 3 ? old : 4);
    S.rows = m ? +m[2] : 4;
  })();

  // ── 시세 시각 ─────────────────────────────────────────────────────
  var lastTs = WG_TS;
  function stamp(ts, ok){
    if (ts) lastTs = ts;
    $('wgStat').innerHTML = (isOpen() ? '<span class="live">장중</span>' : '장외') + ' · ' + esc(lastTs)
      + (ok === false ? ' <span class="warn" title="시세 조회 실패 — 직전 값을 보이고 있습니다">⚠</span>' : '');
  }

  // ── 그룹의 «평균 등락» ───────────────────────────────────────────
  /* 그 그룹 종목의 오늘 등락률 단순 평균. 서버는 all_stock_info 를 «읽기만» 한다(이 숫자를 위해 시세를 받지 않는다) —
     그래서 신선도가 그룹마다 다르다: 보고 있는 그룹은 10초(화면의 행으로 여기서 바로 낸다), 고정 그룹은 WG_STAT,
     그 밖의 내 그룹은 크론 주기. 장중에 WG_FRESH 초보다 낡은 숫자는 흐리게 그리고 기준 시각을 툴팁에 적는다.
     ★「수익률」이 아니다 — 금액 비중을 보지 않는 단순 평균이라 포트폴리오 현황 카드의 수익률과 다른 수다. */
  function statOf(g){
    if (g.id === S.gid && S.items.length) {          // 보고 있는 그룹 — 화면의 행이 곧 최신이다
      var sum = 0, n = 0, up = 0, dn = 0;
      S.items.forEach(function(it){
        if (!isStock(it) || !it.price || it.rate == null) return;
        sum += +it.rate; n++;
        if (it.rate > 0) up++; else if (it.rate < 0) dn++;
      });
      return { avg: n ? sum / n : null, up: up, dn: dn, cnt: n,
               age: isOpen() ? 0 : null, asof: isOpen() ? lastTs.slice(0, 5) : (g.asof || '') };
    }
    return { avg: g.avg == null ? null : +g.avg, up: g.up || 0, dn: g.dn || 0, cnt: g.cnt || 0,
             age: g.age == null ? null : +g.age, asof: g.asof || '' };
  }
  function avgParts(g){
    var s = statOf(g);
    if (s.avg == null) return { c: 'av', t: '', tip: '' };
    var a = Math.round(s.avg * 100) / 100;
    var old = isOpen() && s.age != null && s.age > WG_FRESH;
    var d = new Date(), today = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    // 기준 시각 — 오늘 것이면 시:분만, 아니면 날짜까지(장 열기 전·휴장일엔 지난 거래일 값이다)
    var at = s.asof.length > 8 ? (s.asof.slice(0, 10) === today ? s.asof.slice(11) : s.asof.slice(5)) : s.asof;
    var tip = '평균 등락 ' + pct(a) + ' · ▲' + s.up + ' ▼' + s.dn + ' · ' + s.cnt + '종목'
            + (s.cnt < g.n ? ' (시세 없는 ' + (g.n - s.cnt) + '종목 제외)' : '')
            + (at ? ' · ' + at + ' 기준' : '')
            + (old ? ' — 장중인데 낡은 값입니다. 그룹을 누르면 새로 받습니다' : '');
    return { c: 'av ' + cls(a) + (old ? ' old' : ''), t: pct(a), tip: tip };
  }
  /* 숫자만 제자리에서 바꾼다 — 목록을 통째로 다시 그리면 누르던 버튼이 바뀌어 클릭이 샌다 */
  function paintGroupStats(){
    S.groups.forEach(function(g){
      var el = $('wgGList').querySelector('.wg-g[data-id="' + g.id + '"] .av');
      if (!el) return;
      var p = avgParts(g);
      el.className = p.c; el.textContent = p.t; el.title = p.tip;
    });
  }
  function fullMsg(n){ return '그룹당 ' + WG_MAX + '종목까지입니다 — ' + n + '종목은 담지 못했습니다.'; }

  // ══ ① 그룹 목록 ══════════════════════════════════════════════════
  function renderGroups(){
    var box = $('wgGList');
    box.innerHTML = '';
    /* 번호(Alt+1~9)는 «내가 만든 그룹»에만 매긴다 — 고정 그룹은 ★ 로 선다(HTS 의 기본 목록과 같은 자리) */
    var no = 0;
    S.groups.forEach(function(g){
      var el = document.createElement('button');
      el.type = 'button';
      el.className = 'wg-g' + (g.fixed ? ' fx' : '') + (g.id === S.gid ? ' on' : '');
      if (g.fixed) el.title = '고정 그룹 — 늘 맨 위에 있고 고칠 수 없습니다';
      else { no++; if (no <= 9) el.title = 'Alt+' + no; }
      el.setAttribute('data-id', g.id);
      var p = avgParts(g), full = !g.fixed && g.n >= WG_MAX;
      el.innerHTML = '<span class="no">' + (g.fixed ? '★' : no) + '</span><span class="nm">' + esc(g.name) + '</span>'
                   // 테마 그룹 — 연결한 주 변수가 있으면 ◆ (이름은 툴팁)
                   + (g['var'] ? '<span class="vt" title="테마 변수 — ' + esc(g['var']) + '">◆</span>' : '')
                   + '<span class="' + p.c + '" title="' + esc(p.tip) + '">' + p.t + '</span>'
                   + '<span class="n' + (full ? ' full' : '') + '"' + (g.fixed ? '' : ' title="' + g.n + ' / ' + WG_MAX + '종목'
                   + (full ? ' — 가득 찼습니다' : '') + '"') + '>' + g.n + '</span>';
      el.onclick = function(){ openGroup(g.id); };
      box.appendChild(el);
    });
    if (!no) {
      var hint = document.createElement('div');
      hint.className = 'wg-ghint';
      hint.innerHTML = '내 그룹이 없습니다.<br>＋ 를 눌러 첫 그룹을 만드세요.';
      box.appendChild(hint);
    }
  }
  function remember(id){
    var k = gKey(id);
    document.cookie = 'wg_gid=' + k + ';path=/stock;max-age=31536000;samesite=lax';
    try { history.replaceState(null, '', BASE + (k ? '&g=' + k : '')); } catch (e) {}
  }
  /* 그룹 열기 — 풀(만들어 둔 차트 수)보다 종목이 많으면 주소로 다시 연다(차트를 새로 만드는 유일한 길) */
  function openGroup(id, force){
    if (!id) return Promise.resolve();
    if (id === S.gid && !force) return Promise.resolve();
    closeMenu();
    skeleton();
    return call('item_list', { group_id: id, live: 1 }).then(function(d){
      S.gid = id; S.sel = 0; S.groups = d.groups;
      S.vars = d.vars || []; pickOv();      // 그 그룹의 테마 변수(없으면 예전 그대로의 그룹이다)
      if (d.vall) S.vall = d.vall;
      S.theme = d.theme || null;            // 테마 노트(테마 md 로 가져온 그룹일 때만)
      setSort(sortFor(id), false);          // 그 그룹의 «여는 정렬»(보유·Top-Pick = 상승률순)
      remember(id);
      stamp(d.ts, true);
      applyItems(d.items);
      loadVars();
    }).catch(function(e){ fail(e); renderList(); });
  }
  function applyItems(items, groups){
    if (groups) S.groups = groups;
    var n = items.filter(isStock).length;
    if (POOL.length && n > POOL.length) { location.href = BASE + '&g=' + gKey(S.gid); return; }
    S.items = items;
    renderAll();
  }
  function renderAll(){ renderGroups(); renderList(); renderNote(); layoutCharts(); }

  // ══ 테마 노트 ═════════════════════════════════════════════════════
  /* 테마 md 로 가져온 그룹이면 리스트 위에 한 줄(asof · 재점검 D-day · 출처) — 펴면 원문의 노트·일정·출처(서버가 이스케이프해 그린 HTML).
     접힘은 «내 화면 취향»이라 localStorage(wg_note_fold · 처음엔 접힘). 되돌리기는 가장 최근 가져오기가 갈아끼우기일 때만 선다.
     판정·신호는 보이지 않는다(보는 자리 원칙). */
  function renderNote(){
    var el = $('wgTn'), t = S.theme;
    if (!t || isFixed()) { el.hidden = true; el.innerHTML = ''; return; }
    var open = lsGet('wg_note_fold') === '0', rv = '';
    if (t.review_on) {
      var dd = t.review_days;
      rv = (dd != null && dd < 0)
        ? '<span class="rv late" title="재점검 날(' + esc(t.review_on) + ')이 지났습니다">재점검</span>'
        : '<span class="rv" title="재점검 날 ' + esc(t.review_on) + '">재점검 ' + (dd === 0 ? '오늘' : 'D-' + dd) + '</span>';
    }
    el.innerHTML = '<div class="tn-h" title="눌러서 ' + (open ? '접기' : '펴기') + '"><button type="button" class="tg">' + (open ? '▾' : '▸') + '</button>'
      + '<b>테마 노트</b><span class="tn-m">asof ' + esc(t.asof || '—') + '</span>' + rv
      + (t.source ? '<a class="tn-s" href="' + esc(t.source) + '" target="_blank" rel="noopener noreferrer">출처</a>' : '')
      + '<span class="sp"></span>'
      + (t.can_undo ? '<button type="button" class="dt-chip" id="wgTnUndo" title="마지막 갈아끼우기 직전으로 되돌립니다">되돌리기</button>' : '')
      + '</div>'
      + (open ? '<div class="tn-b">' + (t.title ? '<div class="tn-t">' + esc(t.title) + '</div>' : '')
              + (t.html || '<div class="wg-msg">노트·일정·출처가 비었습니다.</div>') + '</div>' : '');
    el.hidden = false;
  }
  $('wgTn').addEventListener('click', function(e){
    var u = e.target.closest('#wgTnUndo');
    if (u) { noteUndo(u); return; }
    if (e.target.closest('a') || !e.target.closest('.tn-h')) return;
    lsSet('wg_note_fold', lsGet('wg_note_fold') === '0' ? '1' : '0');
    renderNote();
  });
  /* 되돌리기 — 두 번 누른다. 처음엔 서버에 «무엇이 사라지나»만 묻고(dry) 그 말을 버튼에 적는다(confirm() 을 쓰지 않는다) */
  function noteUndo(btn){
    var t = S.theme;
    if (!t || btn.disabled) return;
    if (btn.classList.contains('arm')) {
      clearTimeout(btn._t); btn.disabled = true;
      call('theme_undo', { import_id: t.id }).then(function(d){
        toast('갈아끼우기 전으로 되돌렸습니다 — ' + d.items + '줄' + (d.lost_vars ? ' · 이제 없는 변수 ' + d.lost_vars + '개는 빠졌습니다' : ''));
        if (d.groups) S.groups = d.groups;
        return openGroup(S.gid, true);
      }).catch(function(e){ fail(e); renderNote(); });
      return;
    }
    btn.disabled = true;
    call('theme_undo', { import_id: t.id, dry: 1 }).then(function(d){
      btn.disabled = false;
      btn.classList.add('arm');
      btn.textContent = '정말 되돌릴까요?' + (d.changed ? ' (그 뒤 고친 ' + d.changed + '줄도 사라집니다)' : '');
      btn._t = setTimeout(renderNote, 4000);
    }).catch(function(e){ btn.disabled = false; fail(e); });
  }

  // ══ 카드형 툴팁 (2026-10-26 사용자 「마우스 오버했을 때 칩으로」) ══════════
  /* [data-tip] 에 마우스를 올리면 그 HTML 을 떠 있는 상자 하나(#wgTip)에 갈아 끼운다 — 포트폴리오 현황의 .pf-tip 과 같은 방식.
     상자는 마우스를 받지 않는다(pointer-events 없음 — 받으면 올린 자리를 덮어 깜박인다). 화면 밖으로 넘치면 위·왼쪽으로 붙인다.
     목록이 스크롤되면 자리가 어긋나므로 닫는다. */
  var tipBox = $('wgTip'), tipOn = null;
  function tipHide(){ if (tipOn) { tipBox.hidden = true; tipOn = null; } }
  document.addEventListener('mouseover', function(e){
    var el = (e.target && e.target.closest) ? e.target.closest('[data-tip]') : null;
    if (!el) { tipHide(); return; }
    if (el === tipOn) return;
    tipOn = el;
    tipBox.innerHTML = el.getAttribute('data-tip');
    tipBox.hidden = false;
    var r = el.getBoundingClientRect(), w = tipBox.offsetWidth, h = tipBox.offsetHeight;
    var x = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)), y = r.bottom + 6;
    if (y + h > window.innerHeight - 8) y = Math.max(8, r.top - h - 6);
    tipBox.style.left = x + 'px'; tipBox.style.top = y + 'px';
  });
  document.addEventListener('scroll', tipHide, true);

  $('wgGAdd').onclick = function(){
    $('wgGNew').hidden = false;
    $('wgGNewIn').value = '';
    $('wgGNewIn').focus();
  };
  $('wgGNewIn').addEventListener('keydown', function(e){
    if (e.key === 'Escape') { $('wgGNew').hidden = true; return; }
    if (e.key !== 'Enter') return;
    var name = this.value.trim();
    if (!name) return;
    call('group_create', { name: name }).then(function(d){
      $('wgGNew').hidden = true;
      S.groups = d.groups;
      return openGroup(d.id, true);
    }).catch(fail);
  });
  $('wgGNewIn').addEventListener('blur', function(){ if (!this.value.trim()) $('wgGNew').hidden = true; });

  // ══ ② 종목 리스트 ════════════════════════════════════════════════
  /* 보이는 순서 — 「내 순서」만 저장된 순서다. 나머지 둘은 보기 전용(구분행 없음 · 값이 없는 종목은 맨 아래) */
  function viewItems(){
    if (S.sort === 'mine') return S.items.slice();
    var key = (S.sort === 'rate') ? function(x){ return x.price ? x.rate : null; } : vsBase;
    return S.items.filter(isStock).map(function(x, i){ return { x:x, i:i, k:key(x) }; })
      .sort(function(a, b){
        if (a.k === null || b.k === null) return (a.k === null ? 1 : 0) - (b.k === null ? 1 : 0) || (a.i - b.i);
        return (b.k - a.k) || (a.i - b.i);
      }).map(function(o){ return o.x; });
  }
  /* 접힌 구분행 아래(다음 구분행 앞까지)의 종목 — 리스트와 차트가 같은 집합을 숨긴다 */
  function hiddenSet(){
    var h = {}, f = false;
    if (S.sort !== 'mine') return h;
    S.items.forEach(function(x){
      if (x.type === 'sep') f = !!S.fold[x.id];
      else if (f) h[x.id] = 1;
    });
    return h;
  }
  function sectionIds(sepId){
    var out = [], inSec = false;
    S.items.forEach(function(x){
      if (x.type === 'sep') inSec = (x.id === sepId);
      else if (inSec) out.push(x.id);
    });
    return out;
  }
  function skeleton(){
    var h = '';
    for (var i = 0; i < 8; i++) h += '<div class="wg-sk"></div>';
    $('wgRows').innerHTML = h;
  }

  /* 종목 칸 — 단타 목록과 «같은 행»이다(공용 모듈 StockRow · 2026-10-07 밤 사용자 지시).
     서버 행(it)을 모듈이 받는 모양으로 옮길 뿐, 칸의 내용·배지·CSS 는 모듈이 만든다.
     메모 표시(빨간 삼각형)만 이 화면의 것이라 이름 뒤에 덧붙인다. */
  function infoHtml(it){
    return StockRow.cells({
      code: it.code, name: it.name, kind: 'stock', price: it.price, rate: it.rate,
      cap: it.cap, amt_eok: it.amt, etf_top: it.etf_top, q: it.q,
      days: it.days, bars: it.bars, holes: it.holes,
      _held: it.held, pos_id: it.pos_id, pf_names: it.pf, pf_n: it.pf_n, open: !it.slot
    }, { bf: window.BADGE_FEATS });
  }
  /* 시세가 바뀐 행을 다시 채운다 — 종목 칸은 통째로(모듈), 이 화면의 열(대금·기준대비)은 따로 */
  function fillQuote(el, it){
    var v = vsBase(it), info = el.querySelector('.info'), vs = el.querySelector('.vs');
    info.innerHTML = infoHtml(it);
    if (it.memo) {
      // 마우스를 올리면 카드(#wgTip) — 브라우저 title 은 글 한 덩어리라 근거·트리거·무효가 안 갈린다
      info.querySelector('.nm').insertAdjacentHTML('beforeend', '<span class="wg-memo" data-tip="' + esc(memoTip(it)) + '">◥</span>');
    }
    // 거래대금은 «십억 원 · 정수»(HTS 와 같은 단위) — 원천은 억원이라 10 으로 나눈다
    el.querySelector('.am').textContent = (it.price && it.amt != null) ? fmt(it.amt / 10) : '';
    vs.className = 'vs ' + (v === null ? 'flat' : cls(v)); vs.textContent = (v === null) ? '—' : pct(v);
    // 종목별 «같이 볼 변수» 표시(호박색 ◆)
    var iv = it.var_id ? varAny(it.var_id) : null;
    if (iv) info.querySelector('.nm').insertAdjacentHTML('beforeend', '<span class="wg-iv" title="같이 볼 변수 — ' + esc(iv.name) + ' (이 종목 차트에만 겹칩니다)">◆</span>');
  }
  /* 메모 → 카드 — 테마 md·표 붙여넣기가 만든 «근거 · 트리거: 값 · 무효: 값 · 메모: 값» 꼴을 층으로 나눈다(글자는 그대로 · 판정 없음).
     칸 이름은 « · 이름: » 표지로 가른다(이름은 12자 안 · 「·」「:」 없음 — 근거 글 속의 « · 7/3 보도의 후속» 같은 것은 안 갈린다).
     첫 덩어리(근거)의 [단독] 같은 꼬리표와 숫자뿐인 트리거·무효는 칩, 나머지 칸은 이름표가 붙은 줄. 그 꼴이 아니면 글 한 덩어리. */
  var MEMO_TAG = { '단독': 't-a', '공시': 't-g', 'IR': 't-g', '리포트': 't-b', '기사': 't-n', '추정': 't-p' };
  function memoParts(m){
    var re = / · ([^·:\n]{1,12}): /g, at = [], x;
    while ((x = re.exec(m))) at.push({ i: x.index, n: x[0].length, k: x[1] });
    var head = at.length ? m.slice(0, at[0].i) : m, kv = [];
    at.forEach(function(p, j){ kv.push({ k: p.k, v: m.slice(p.i + p.n, j + 1 < at.length ? at[j + 1].i : m.length) }); });
    var tag = '', t = /^\[(단독|공시|IR|리포트|기사|추정)\]\s*/.exec(head);
    if (t) { tag = t[1]; head = head.slice(t[0].length); }
    return { tag: tag, head: head, kv: kv };
  }
  function memoTip(it){
    var p = memoParts(String(it.memo || '')), chips = '', rest = '';
    if (p.tag) chips += '<span class="mc ' + MEMO_TAG[p.tag] + '">' + esc(p.tag) + '</span>';
    p.kv.forEach(function(x){
      var v = x.v.trim();
      if ((x.k === '트리거' || x.k === '무효') && /^[\d,]+원?$/.test(v)) {
        chips += '<span class="mc ' + (x.k === '트리거' ? 'k-tr' : 'k-iv') + '"><small>' + x.k + '</small><b>' + esc(v) + '</b></span>';
      } else {
        rest += '<dt>' + esc(x.k) + '</dt><dd>' + esc(v) + '</dd>';
      }
    });
    return '<div class="tt-h"><b>' + esc(it.name || it.code) + '</b><i>메모</i>'
         + (it.memo_at ? '<em>' + esc(String(it.memo_at).slice(0, 10)) + '</em>' : '') + '</div>'
         + (chips ? '<div class="tt-c">' + chips + '</div>' : '')
         + (p.head.trim() ? '<p class="tt-b">' + esc(p.head.trim()) + '</p>' : '')
         + (rest ? '<dl>' + rest + '</dl>' : '');
  }
  function baseTip(it){
    if (isFixed()) {
      return it.base ? '담을 때 가격 · 담은 날 ' + (it.base_date || '') + ' (고정 그룹 — 고칠 수 없습니다)'
                     : '고정 그룹 — 기준가가 없습니다';
    }
    return it.base ? '기준일 ' + (it.base_date || '') + ' — 눌러서 고치기 (비우고 Enter = 지우기)'
                   : '눌러서 기준가 입력 (Enter 저장 · Esc 취소 · ↓ 다음 종목)';
  }
  /* 보유 배지 — 사이트 공통 어휘(「보유」=돈이 들어감 · 「편입됨」=자리만 있음). 리스트와 차트 머리줄이 같은 함수를 쓴다 */
  function heldBadge(it){
    if (!it.held) return '';
    return '<span class="dt-bdg ' + (it.slot ? 'slot' : 'held') + '" title="' + esc(it.pf || '포트폴리오에 있는 종목입니다') + '">'
         + (it.slot ? '편입됨' : '보유') + '</span>';
  }
  function stockRow(it, hidden){
    var el = document.createElement('div');
    el.className = 'wg-r' + (hidden ? ' hid' : '') + (it.id === S.sel ? ' on' : '');
    el.dataset.id = it.id; el.dataset.type = 'stock';
    el.innerHTML =
      '<span class="hd" tabindex="0" title="끌어서 순서 변경 (Alt+↑/↓)">⋮⋮</span>' +
      '<div class="info srow qb-sm"></div>' +
      '<span class="am"></span>' +
      '<span class="bs" title="' + esc(baseTip(it)) + '">' + (it.base ? fmt(it.base) : '') + '</span>' +
      '<span class="vs"></span><button type="button" class="mn" title="메뉴 (우클릭)">⋯</button>';
    fillQuote(el, it);
    return el;
  }
  function sepRow(it){
    var el = document.createElement('div'), f = !!S.fold[it.id];
    el.className = 'wg-r sep' + (it.id === S.sel ? ' on' : '');
    el.dataset.id = it.id; el.dataset.type = 'sep';
    el.innerHTML =
      '<span class="hd" tabindex="0" title="끌어서 순서 변경 (Alt+↑/↓)">⋮⋮</span>' +
      '<button type="button" class="tg" title="접기/펼치기">' + (f ? '▸' : '▾') + '</button>' +
      '<span class="lb" title="두 번 눌러 이름 변경">' + esc(it.label) + '</span>' +
      '<span class="sn">' + (function(){ var p = drParts(it);
        return '<button type="button" class="' + p.c + '" title="' + esc(p.tip) + '">' + p.t + '</button>'; })()
        + sectionIds(it.id).length + '</span>' +
      '<button type="button" class="mn" title="메뉴 (우클릭)">⋯</button>';
    return el;
  }
  function renderList(){
    var box = $('wgRows'), fx = isFixed(), g = groupOf(S.gid);
    document.body.classList.toggle('wg-theme', isTheme());
    $('wgOv').hidden = !hasOv();
    document.body.classList.toggle('wg-view', S.sort !== 'mine');
    document.body.classList.toggle('wg-fixed', fx);
    if (sortable) sortable.option('disabled', S.sort !== 'mine' || fx);
    lockTools(fx, g);
    // 📊 는 «지금 보고 있는 그룹»의 마감 변동으로 간다
    $('wgMove').href = '/stock/index.php?mode=move' + (S.gid ? '&g=' + encodeURIComponent(gKey(S.gid)) : '');
    box.innerHTML = '';
    if (!S.gid) { box.innerHTML = '<div class="wg-msg">왼쪽에서 ＋ 를 눌러 그룹을 만드세요.</div>'; return; }
    if (!S.items.length) {
      // 빈 칸에는 «왜 비었는지»를 적는다 — 고정 그룹은 채우는 자리가 다르다
      box.innerHTML = '<div class="wg-msg">' + (!fx ? '종목이 없습니다 · 위 검색창에서 추가하세요'
        : (g.key === 'recent' ? '최근에 열어 본 종목이 없습니다.<br>단타 화면에서 종목을 열어 잠시 머물면 여기에 쌓입니다.'
         : g.key === 'top' ? 'Top-Pick 이 비어 있습니다.<br>재무분석·퀀트 화면의 ☆ 로 담으면 여기에 나옵니다(보유 중인 종목은 「보유종목」에 있습니다).'
                           : '보유 종목이 없습니다.<br>포트폴리오에 편입하면 여기에 나옵니다.')) + '</div>';
      return;
    }
    var hid = hiddenSet();
    viewItems().forEach(function(it){
      box.appendChild(isStock(it) ? stockRow(it, !!hid[it.id]) : sepRow(it));
    });
  }
  /* 고정 그룹에서는 편집 손잡이를 전부 잠그고, 그 자리에 «왜 잠겼는지·어디서 고치는지»를 한 줄 적는다 */
  function lockTools(fx, g){
    $('wgQ').disabled = fx;
    $('wgQ').placeholder = fx ? '고정 그룹 — 종목을 더하거나 뺄 수 없습니다' : '종목명·코드로 추가 ( / )';
    ['wgSepAdd', 'wgBaseAll', 'wgBaseClr'].forEach(function(id){ $(id).disabled = fx; });
    var b0 = $('wgSort').querySelector('[data-s="mine"]');
    b0.textContent = fx ? '기본 순서' : '내 순서';
    b0.title = !fx ? '저장된 순서 — 끌어서 바꾸고 구분행이 보입니다'
             : ({ held: '포지션을 만든 순서', top: '☆ 목록의 순서', recent: '최근에 본 순서' })[g.key] || '';
    var note = $('wgFix');
    note.hidden = !fx;
    if (!fx) return;
    note.innerHTML = (g.key === 'recent')
      ? '<b>★ 고정 그룹</b> — <a href="/stock/index.php?mode=short">단타</a> 화면에서 최근에 열어 본 종목입니다(본 순). '
        + '열어서 잠시 머물면 오르고, 10거래일 동안 다시 열지 않으면 빠집니다 — 이 종목들은 1분봉이 쌓이고 있습니다.'
      : (g.key === 'top')
      ? '<b>★ 고정 그룹</b> — ☆ 로 담은 종목 중 <b>보유하지 않은 것</b>입니다(담은 순 · 보유 중인 종목은 「보유종목」에 있습니다). 기준가는 «담을 때 가격»이라 기준대비가 '
        + '<a href="/stock/index.php?mode=watch">재무분석 › Top-Pick</a>의 「담은뒤」와 같습니다. 담고 빼는 것은 그 화면과 ☆ 에서 합니다.'
      : '<b>★ 고정 그룹</b> — 포트폴리오에 살아 있는 포지션입니다(보유·편입됨). 편입·청산은 '
        + '<a href="/stock/index.php?mode=all">포트폴리오 › 보유종목</a>이 정합니다.';
  }
  function orderSig(){ return viewItems().map(function(x){ return x.id; }).join(','); }

  // 행 안의 일 — 한 곳에서 받는다(행을 다시 그려도 핸들러를 다시 걸 필요가 없다)
  $('wgRows').addEventListener('click', function(e){
    var el = e.target.closest('.wg-r');
    if (!el) return;
    var id = +el.dataset.id;
    if (e.target.closest('.mn')) { var r = e.target.getBoundingClientRect(); openMenu(id, r.left - 150, r.bottom + 2); return; }
    if (e.target.closest('.tg')) { toggleFold(id); return; }
    if (e.target.closest('.dr')) { var di = byId(id), dv = di ? (di.dir || 0) : 0; setDir(id, dv === 0 ? 1 : (dv === 1 ? -1 : 0)); return; }
    if (e.target.closest('.bsin') || e.target.closest('.lbin')) return;
    if (e.target.closest('.bs') && !isFixed()) { selectRow(id); editBase(id); return; }
    selectRow(id, true);
  });
  $('wgRows').addEventListener('contextmenu', function(e){
    var el = e.target.closest('.wg-r');
    if (!el) return;
    e.preventDefault();
    selectRow(+el.dataset.id);
    openMenu(+el.dataset.id, e.clientX, e.clientY);
  });
  $('wgRows').addEventListener('dblclick', function(e){
    var el = e.target.closest('.wg-r.sep');
    if (el && e.target.closest('.lb')) renameSep(+el.dataset.id);
  });
  // 핸들에 포커스를 두고 Alt+↑/↓ — 드래그의 키보드 대안
  $('wgRows').addEventListener('keydown', function(e){
    if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    var hd = e.target.closest('.hd'), el = hd && hd.closest('.wg-r');
    if (!el || S.sort !== 'mine' || isFixed()) return;
    e.preventDefault(); e.stopPropagation();
    nudge(+el.dataset.id, e.key === 'ArrowUp' ? -1 : 1);
  });

  function selectRow(id, toChart, fromChart){
    S.sel = id;
    Array.prototype.forEach.call($('wgRows').querySelectorAll('.wg-r'), function(el){
      el.classList.toggle('on', +el.dataset.id === id);
    });
    var it = byId(id);
    POOL.forEach(function(c){ c.el.classList.toggle('sel', !!it && c.id === id); });
    if (toChart && it && isStock(it)) {
      var c = byCode[it.code];
      if (c && !c.el.hidden) {
        c.el.scrollIntoView({ block:'nearest', behavior:'smooth' });
        c.el.classList.add('flash');
        clearTimeout(c._f); c._f = setTimeout(function(){ c.el.classList.remove('flash'); }, 1500);
      }
    }
    if (fromChart) { var el = rowEl(id); if (el) el.scrollIntoView({ block:'nearest' }); }
  }

  function toggleFold(id){
    if (S.fold[id]) delete S.fold[id]; else S.fold[id] = 1;
    lsSet('wg_fold', JSON.stringify(S.fold));
    renderList(); layoutCharts();
  }

  // ── 기준가 입력 ──
  function saveBase(it, val){
    var old = { base: it.base, base_date: it.base_date };
    var d = new Date();
    it.base = val;
    it.base_date = val ? d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2) : null;
    repaint(it, true);
    return call('item_update', { id: it.id, base_price: val }).then(function(r){
      it.base = r.base; it.base_date = r.base_date;
      repaint(it, false);
    }).catch(function(e){
      it.base = old.base; it.base_date = old.base_date;      // 낙관적 갱신을 되돌린다
      repaint(it, true);
      fail(e);
    });
  }
  /* 한 종목의 기준가가 바뀐 뒤 — 그 행·그 차트만 고친다(보기 정렬이 기준대비순이면 줄을 다시 세운다) */
  function repaint(it, chart){
    if (S.sort === 'base' && !S.edit) { renderList(); layoutCharts(); }
    else {
      var el = rowEl(it.id);
      if (el) {
        var b = el.querySelector('.bs');
        if (!b.classList.contains('ed')) { b.textContent = it.base ? fmt(it.base) : ''; b.title = baseTip(it); }
        fillQuote(el, it);
      }
    }
    var c = byCode[it.code];
    if (c) { head(c, it); if (chart) baseLine(c, it, true); }
  }
  function nextStockId(id){
    var rows = $('wgRows').querySelectorAll('.wg-r:not(.hid):not(.sep)');
    for (var i = 0; i < rows.length - 1; i++) if (+rows[i].dataset.id === id) return +rows[i + 1].dataset.id;
    return 0;
  }
  function editBase(id){
    var it = byId(id), el = rowEl(id);
    if (!it || !el || S.edit) return;
    var cell = el.querySelector('.bs'), inp = document.createElement('input'), done = false;
    inp.className = 'bsin'; inp.inputMode = 'numeric'; inp.autocomplete = 'off';
    inp.value = it.base ? fmt(it.base) : '';
    cell.textContent = ''; cell.classList.add('ed'); cell.appendChild(inp);
    S.edit = true;
    inp.focus(); inp.select();

    function close(){
      if (done) return;
      done = true; S.edit = false;
      cell.classList.remove('ed');
      cell.textContent = it.base ? fmt(it.base) : '';
    }
    /* 저장 — 숫자만 · 1 ~ 1억. 범위 밖이면 저장하지 않고 칸을 빨갛게 둔다. 비우면 지우기. */
    function commit(){
      var raw = inp.value.replace(/[^0-9]/g, '');
      if (raw === '') { close(); if (it.base !== null) saveBase(it, null); return true; }
      var n = parseInt(raw, 10);
      if (!(n >= 1 && n <= WG_BASE_MAX)) { inp.classList.add('bad'); return false; }
      close();
      if (n !== it.base) saveBase(it, n);
      return true;
    }
    inp.addEventListener('input', function(){
      var raw = inp.value.replace(/[^0-9]/g, '');
      inp.value = raw ? Number(raw).toLocaleString() : '';      // 입력 중 천단위 콤마
      inp.classList.remove('bad');
    });
    inp.addEventListener('keydown', function(e){
      e.stopPropagation();
      if (e.key === 'Enter') { e.preventDefault(); commit(); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'ArrowDown') {
        e.preventDefault();
        var nx = nextStockId(id);
        if (commit() && nx) { selectRow(nx); editBase(nx); }
      }
    });
    inp.addEventListener('blur', function(){ if (!done && !commit()) close(); });
  }

  // ── 순서 ──
  function saveOrder(ids){
    var old = S.items.slice(), map = {};
    old.forEach(function(x){ map[x.id] = x; });
    S.items = ids.map(function(i){ return map[i]; }).filter(Boolean);
    renderList(); layoutCharts();
    return call('item_reorder', { group_id: S.gid, ids: ids }).catch(function(){
      S.items = old;                                          // 실패하면 원위치
      renderList(); layoutCharts();
      toast('순서를 저장하지 못했습니다 — 되돌렸습니다');
    });
  }
  var dragKids = null;
  var sortable = window.Sortable ? new Sortable($('wgRows'), {
    handle: '.hd', draggable: '.wg-r', animation: 150,
    /* 접힌 구분행을 끌면 그 섹션이 «통째로» 따라간다 — 줄 하나만 옮기면 안 보이던 종목들이
       말없이 위 섹션으로 넘어간다 */
    onStart: function(e){
      var id = +e.item.dataset.id, it = byId(id);
      dragKids = (it && it.type === 'sep' && S.fold[id]) ? sectionIds(id) : null;
    },
    onEnd: function(e){
      if (e.oldIndex === e.newIndex) return;
      var ids = Array.prototype.map.call($('wgRows').querySelectorAll('.wg-r'), function(el){ return +el.dataset.id; });
      if (dragKids && dragKids.length) {
        var sep = +e.item.dataset.id;
        ids = ids.filter(function(i){ return dragKids.indexOf(i) < 0; });
        Array.prototype.splice.apply(ids, [ids.indexOf(sep) + 1, 0].concat(dragKids));
      }
      saveOrder(ids);
    }
  }) : null;
  function nudge(id, dir){
    var ids = S.items.map(function(x){ return x.id; }), hid = hiddenSet(), i = ids.indexOf(id), j = i + dir;
    while (j >= 0 && j < ids.length && hid[ids[j]]) j += dir;       // 접혀서 안 보이는 줄은 건너뛴다
    if (i < 0 || j < 0 || j >= ids.length) return;
    ids.splice(i, 1); ids.splice(j, 0, id);
    saveOrder(ids);
    var el = rowEl(id);
    if (el) el.querySelector('.hd').focus();
  }

  /* ── 정렬 보기 ──
   * ★그룹마다 «여는 정렬»이 다르다(2026-10-07 심야 사용자 지시 — 「정렬 기준이 상승률로」):
   *     보유종목 · Top-Pick  → 상승률순(전일 대비 등락률 큰 순). 손으로 정한 순서가 없는 목록이라 편입 순·담은 순은
   *                            아무것도 말해 주지 않는다 — 단타 화면의 같은 목록도 상승률순이다(두 화면이 같은 말).
   *     최근조회             → 기본 순서(최근에 본 순 — 그 순서가 이 목록의 뜻이다).
   *     내가 만든 그룹       → 내 순서(끌어서 정한 순서·구분행이 이 화면의 알맹이다).
   *   한 번 다른 정렬을 고르면 그 그룹은 그것을 기억한다(localStorage wg_sort · 그룹의 key 별). */
  var sortPref = {};
  try { sortPref = JSON.parse(lsGet('wg_sort') || '{}') || {}; } catch (e) { sortPref = {}; }
  function sortFor(gid){
    var k = gKey(gid), v = sortPref[k];
    if (v === 'mine' || v === 'rate' || v === 'base') return v;
    return (k === 'held' || k === 'top') ? 'rate' : 'mine';
  }
  /* 정렬을 바꾸는 «한 자리» — 상태와 버튼을 함께 맞춘다(둘이 따로 놀면 버튼이 거짓말을 한다) */
  function setSort(v, keep){
    S.sort = v;
    Array.prototype.forEach.call($('wgSort').querySelectorAll('button'), function(x){
      x.classList.toggle('on', x.getAttribute('data-s') === v);
    });
    if (keep && S.gid) { sortPref[gKey(S.gid)] = v; lsSet('wg_sort', JSON.stringify(sortPref)); }
  }
  $('wgSort').addEventListener('click', function(e){
    var b = e.target.closest('button');
    if (!b) return;
    setSort(b.getAttribute('data-s'), true);
    renderList(); layoutCharts();
  });
  // 순서를 손대는 일(종목·구분행 추가)은 「내 순서」에서만 뜻이 있다 — 그 보기로 돌려 놓는다
  function mineFirst(){
    if (S.sort !== 'mine') setSort('mine', true);
  }

  // ── 구분행 ──
  function addSep(beforeId){
    if (!S.gid) { toast('먼저 그룹을 만드세요.'); return; }
    if (isFixed()) { toast(FIXED_MSG); return; }
    mineFirst();
    call('sep_add', { group_id: S.gid, label: '구분', before_id: beforeId || 0 }).then(function(d){
      S.items = d.items;
      renderList(); layoutCharts();
      var el = rowEl(d.id);
      if (el) el.scrollIntoView({ block:'nearest' });
      renameSep(d.id);                                        // 만든 자리에서 바로 이름을 적는다
    }).catch(fail);
  }
  $('wgSepAdd').onclick = function(){ addSep(0); };
  function renameSep(id){
    var it = byId(id), el = rowEl(id);
    if (!it || !el || S.edit) return;
    var lb = el.querySelector('.lb'), inp = document.createElement('input'), done = false;
    inp.className = 'lbin'; inp.maxLength = 50; inp.value = it.label;
    lb.textContent = ''; lb.appendChild(inp);
    S.edit = true;
    inp.focus(); inp.select();
    function close(){ if (done) return; done = true; S.edit = false; lb.textContent = it.label; }
    function commit(){
      var v = inp.value.trim();
      if (!v || v === it.label) { close(); return; }
      var old = it.label;
      it.label = v; close(); layoutCharts();
      call('item_update', { id: id, label: v }).catch(function(e){ it.label = old; renderList(); layoutCharts(); fail(e); });
    }
    inp.addEventListener('keydown', function(e){
      e.stopPropagation();
      if (e.key === 'Enter') { e.preventDefault(); commit(); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
    });
    inp.addEventListener('blur', commit);
  }

  // ── 삭제 ──
  function delItems(ids){
    if (isFixed()) { toast(FIXED_MSG); return; }
    var old = S.items.slice();
    S.items = S.items.filter(function(x){ return ids.indexOf(x.id) < 0; });
    if (ids.indexOf(S.sel) >= 0) S.sel = 0;
    renderList(); layoutCharts();
    call('item_delete', { ids: ids }).then(function(d){
      S.groups = d.groups; renderGroups();
    }).catch(function(e){ S.items = old; renderList(); layoutCharts(); fail(e); });
  }

  // ── 일괄 기준가 ──
  function bulkBase(mode, sectionId){
    if (!S.gid) return;
    call('bulk_base', { group_id: S.gid, mode: mode, section_id: sectionId || 0 }).then(function(d){
      S.items = d.items;
      renderList(); layoutCharts(true);
      toast(mode === 'clear' ? '기준가 ' + d.n + '건을 지웠습니다.' : '기준가 ' + d.n + '건을 현재가로 맞췄습니다.');
    }).catch(fail);
  }
  arm($('wgBaseAll'), '전체를 현재가로 덮어쓸까요?', function(){ bulkBase('current', 0); });
  arm($('wgBaseClr'), '전체 기준가를 지울까요?', function(){ bulkBase('clear', 0); });

  // ── 행 메뉴 (우클릭 또는 ⋯) ──
  function closeMenu(){ $('wgMenu').hidden = true; S.menu = 0; }
  function mi(a, text, key, dg){
    return '<div class="mi' + (dg ? ' dg' : '') + '" data-a="' + a + '"><span>' + text + '</span>'
         + (key ? '<small>' + key + '</small>' : '') + '</div>';
  }
  function openMenu(id, x, y){
    var it = byId(id), m = $('wgMenu');
    if (!it) return;
    S.menu = id;
    var h;
    if (isFixed()) {
      /* 고정 그룹 — 고치는 항목이 없다. 내 그룹으로 «복사»해 가는 것과 보는 것만 된다 */
      h = mi('copy', '내 그룹에 복사 ›') + mi('chart', '차트로 이동') + '<div class="ml"></div>'
        + mi('goto', ({ top: 'Top-Pick 화면에서 관리 ›', recent: '단타 화면으로 ›' })[gKey(S.gid)] || '포트폴리오 보유종목으로 ›');
    } else if (isStock(it)) {
      h = mi('memo', it.memo ? '메모 편집' : '메모 쓰기')
        + mi('basecur', '기준가 = 현재가') + mi('baseclr', '기준가 지우기') + mi('ivar', '같이 볼 변수 ›') + '<div class="ml"></div>'
        + (S.sort === 'mine' ? mi('sepabove', '위에 구분행 추가') : '')
        + mi('copy', '다른 그룹에 복사 ›') + mi('move', '다른 그룹으로 이동 ›') + '<div class="ml"></div>'
        + mi('chart', '차트로 이동') + mi('del', '그룹에서 삭제', 'Del', true);
    } else {
      h = mi('rename', '이름 변경') + mi('secbase', '이 섹션 기준가 = 현재가') + mi('secclr', '이 섹션 기준가 지우기')
        // 테마 그룹 — 이 섹션이 변수의 어느 쪽에 유리한지 적어 둔다
        + (isTheme() ? '<div class="ml"></div>' + mi('dirup', '↑ 변수가 오를 때 유리') + mi('dirdn', '↓ 변수가 내릴 때 유리')
                       + (it.dir ? mi('dir0', '방향 지우기') : '') : '')
        + '<div class="ml"></div>' + mi('del', '구분행 삭제', '', true);
    }
    m.innerHTML = h;
    m.hidden = false;
    var w = m.offsetWidth, hh = m.offsetHeight;
    m.style.left = Math.max(4, Math.min(x, window.innerWidth - w - 6)) + 'px';
    m.style.top  = Math.max(4, Math.min(y, window.innerHeight - hh - 6)) + 'px';
  }
  $('wgMenu').addEventListener('click', function(e){
    var el = e.target.closest('.mi');
    if (!el) return;
    var it = byId(S.menu), a = el.getAttribute('data-a');
    if (!it) { closeMenu(); return; }
    if (a === 'copy' || a === 'move') {                       // 하위 메뉴 — 대상 그룹을 고른다
      var others = userGroups().filter(function(g){ return g.id !== S.gid; });   // 고정 그룹으로는 못 넣는다
      this.innerHTML = '<div class="mh">' + (a === 'copy' ? '복사할 그룹' : '옮길 그룹') + ' (기준가·메모 포함)</div>'
        + (others.length ? others.map(function(g){
            return '<div class="mi" data-a="to" data-m="' + a + '" data-g="' + g.id + '"><span>' + esc(g.name)
                 + '</span><small>' + g.n + '</small></div>'; }).join('')
          : '<div class="mh">내 그룹이 없습니다 — 왼쪽 ＋ 로 먼저 만드세요</div>');
      return;
    }
    if (a === 'ivar') {                                        // 하위 메뉴 — 이 종목에만 겹칠 변수를 고른다
      this.innerHTML = '<div class="mh">같이 볼 변수 — 이 종목 차트에만 겹칩니다</div>'
        + (S.vall.length ? S.vall.map(function(v){
            return '<div class="mi" data-a="ivset" data-v="' + v.id + '"><span>' + (it.var_id === v.id ? '<b class="ck">✓</b> ' : '') + esc(v.name)
                 + '</span><small>' + esc(v.label) + '</small></div>'; }).join('')
            + (it.var_id ? '<div class="ml"></div><div class="mi" data-a="ivset" data-v="0"><span>없음으로</span></div>' : '')
          : '<div class="mh">등록된 변수가 없습니다 — 도구모음 「변수」에서 먼저 등록하세요</div>');
      return;
    }
    closeMenu();
    if (a === 'ivset') { setIvar(it, +el.getAttribute('data-v')); return; }
    if (a === 'goto') {
      var k = gKey(S.gid);
      // 최근조회는 그 종목을 짚은 채 단타로 간다(거기가 「열어 보는」 자리다)
      location.href = '/stock/index.php?mode=' + (k === 'top' ? 'watch' : k === 'recent' ? 'short&code=' + encodeURIComponent(it.code) : 'all');
      return;
    }
    if (a === 'to') {
      var mv = el.getAttribute('data-m') === 'move', gid = +el.getAttribute('data-g');
      // 고정 그룹의 행은 wg_item 이 아니라 id 가 없다 — 종목코드로 복사를 청한다
      var req = isFixed() ? { source_group_id: S.gid, codes: [it.code], target_group_id: gid }
                          : { ids: [it.id], target_group_id: gid };
      call(mv ? 'item_move' : 'item_copy', req).then(function(d){
        S.groups = d.groups;
        if (mv && d.done) S.items = S.items.filter(function(x){ return x.id !== it.id; });
        renderAll();
        toast(d.done ? '「' + groupName(gid) + '」(으)로 ' + (mv ? '옮겼습니다.' : '복사했습니다.')
                     : (d.over ? '「' + groupName(gid) + '」이(가) 가득 찼습니다 (그룹당 ' + WG_MAX + '종목).'
                               : '대상 그룹에 이미 있는 종목이라 건너뛰었습니다.'));
      }).catch(fail);
    }
    else if (a === 'memo')     openMemo(it);
    else if (a === 'basecur')  { if (it.price) saveBase(it, Math.round(it.price)); else toast('현재가가 없습니다.'); }
    else if (a === 'baseclr')  { if (it.base !== null) saveBase(it, null); }
    else if (a === 'sepabove') addSep(it.id);
    else if (a === 'chart')    selectRow(it.id, true);
    else if (a === 'del')      delItems([it.id]);
    else if (a === 'rename')   renameSep(it.id);
    else if (a === 'secbase')  bulkBase('current', it.id);
    else if (a === 'secclr')   bulkBase('clear', it.id);
    else if (a === 'dirup')    setDir(it.id, 1);
    else if (a === 'dirdn')    setDir(it.id, -1);
    else if (a === 'dir0')     setDir(it.id, 0);
  });
  document.addEventListener('mousedown', function(e){
    if (!$('wgMenu').hidden && !e.target.closest('#wgMenu') && !e.target.closest('.mn')) closeMenu();
    if (!e.target.closest('.wg-qwrap')) { $('wgSug').style.display = 'none'; $('wgVarSug').style.display = 'none'; }
  });
  $('wgRows').addEventListener('scroll', closeMenu);

  // ── 메모 ──
  var memoIt = null;
  function openMemo(it){
    memoIt = it;
    $('wgMemoT').textContent = '메모 — ' + it.name;
    $('wgMemoTx').value = it.memo || '';
    $('wgMemoN').textContent = it.memo_at ? '작성 ' + String(it.memo_at).slice(0, 16) : '';
    $('wgMemo').hidden = false;
    $('wgMemoTx').focus();
  }
  function closeMemo(){ $('wgMemo').hidden = true; memoIt = null; }
  function saveMemo(){
    var it = memoIt;
    if (!it) return;
    call('item_update', { id: it.id, memo: $('wgMemoTx').value }).then(function(r){
      it.memo = r.memo; it.memo_at = r.memo_at;
      closeMemo(); renderList();
    }).catch(fail);
  }
  $('wgMemoS').onclick = saveMemo;
  $('wgMemoC').onclick = closeMemo;
  $('wgMemoX').onclick = closeMemo;
  $('wgMemoTx').addEventListener('keydown', function(e){
    e.stopPropagation();
    if (e.key === 'Escape') closeMemo();
    else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) saveMemo();
  });

  // ── 종목 추가 (검색) ──
  var sugT = null;
  $('wgQ').addEventListener('input', function(){
    clearTimeout(sugT);
    var q = this.value.trim();
    if (!q) { $('wgSug').style.display = 'none'; return; }
    sugT = setTimeout(function(){ search(q); }, 200);
  });
  /* ↓/↑/Enter/Esc — 공용 모듈(style/acnav.js)이 맡는다. 화면에 다시 적지 않는다. */
  AcNav.attach($('wgQ'), { box:'#wgSug', item:'.s', close:function(){ $('wgSug').style.display = 'none'; } });
  function search(q){
    // 종목 검색은 기존 경로를 그대로 쓴다 (module=stock&action=search)
    fetch('/stock/api.php?module=stock&action=search&q=' + encodeURIComponent(q), { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(list){
        var box = $('wgSug');
        if (!list || !list.length) { box.style.display = 'none'; return; }
        box.innerHTML = '';
        list.slice(0, 10).forEach(function(s){
          var el = document.createElement('div');
          el.className = 's';
          el.innerHTML = '<span>' + esc(s.name) + '</span><span class="c">' + esc(s.code) + '</span>';
          el.onclick = function(){ addCodes([s.code]); };
          box.appendChild(el);
        });
        box.style.display = 'block';
      }).catch(function(){});
  }
  function addCodes(codes){
    $('wgSug').style.display = 'none';
    $('wgQ').value = '';
    if (!S.gid) { toast('먼저 그룹을 만드세요.'); return; }
    if (isFixed()) { toast(FIXED_MSG); return; }
    call('item_add', { group_id: S.gid, codes: codes }).then(function(d){
      var over = (d.over || []).length;
      if (!d.added.length) {
        toast(over ? fullMsg(over) : (d.dup.length ? '이미 이 그룹에 있는 종목입니다.' : '종목을 찾지 못했습니다.'));
        return;
      }
      if (over) toast(fullMsg(over));
      mineFirst();
      applyItems(d.items, d.groups);
      var last = null;
      S.items.forEach(function(x){ if (x.code === d.added[d.added.length - 1]) last = x; });
      if (last) { selectRow(last.id, true); var el = rowEl(last.id); if (el) el.scrollIntoView({ block:'nearest' }); }
    }).catch(fail);
  }

  // ══ ③ 멀티 차트 ═══════════════════════════════════════════════════
  /* 풀 — 만들어 둔 칸을 종목에 «짝지어» 쓴다. 칸은 지우지 않는다(위 파일 머리 주석). */
  var POOL = [], byCode = {}, grid = $('wgGrid'), cache = {}, active = 0, io = null;
  function ttlOk(at){ return (Date.now() - at) < (isOpen() ? 60000 : 3600000); }
  /* ── 격자(가로 × 세로) — 멀티차트의 배열 방식 (2026-10-07 밤 · 그 메뉴는 없애고 이리로 옮겼다) ──
   * 가로 = 한 줄의 칸 수(1~6) · 세로 = «한 화면에 보이는 줄 수»(1~4): 칸 높이 = 보이는 높이 ÷ 줄 수.
   * ★넘치는 종목은 쪽(‹ ›)이 아니라 <b>스크롤</b>로 본다 — 멀티차트가 쪽으로 넘긴 것은 「안 보이는 종목이 없는 종목으로
   *   읽혀서」였는데, 여기는 왼쪽 리스트가 전 종목을 보여 주고 행을 누르면 그 차트로 간다. 구분행(섹션 제목줄)·
   *   보이는 칸만 받기(IntersectionObserver)도 스크롤이라야 그대로 산다.
   * 차트 영역의 크기가 바뀌면 칸 높이를 다시 잰다(차트는 제 ResizeObserver 로 따라온다). */
  function fitRows(){
    var h = $('wgScroll').clientHeight;
    if (h > 0) grid.style.setProperty('--wg-h', Math.max(140, Math.floor(h / S.rows) - 1) + 'px');   // −1 = 칸 사이 1px 줄
  }
  function setGrid(c, r){
    S.cols = Math.max(1, Math.min(6, c | 0));
    S.rows = Math.max(1, Math.min(4, r | 0));
    grid.style.gridTemplateColumns = 'repeat(' + S.cols + ',minmax(0,1fr))';
    $('wgGC').value = S.cols; $('wgGR').value = S.rows;
    fitRows();
  }
  function pickGrid(){
    setGrid(+$('wgGC').value, +$('wgGR').value);
    lsSet('wg_grid', S.cols + 'x' + S.rows);
    // 칸의 폭·높이가 바뀌었다 — 봉이 실린 차트의 창을 다시 잰다(ResizeObserver 가 크기를 잡은 뒤)
    setTimeout(function(){ POOL.forEach(function(c){ if (!c.el.hidden && c.loaded) refit(c); }); pump(); }, 60);
  }
  $('wgGC').onchange = pickGrid;
  $('wgGR').onchange = pickGrid;
  /* ★창 크기만 보면 모자란다 — 도구모음(기간 바·지표 바)이 뒤늦게 그려져 줄이 바뀌면 창은 그대로인데
       차트 영역의 높이만 줄어든다. 그래서 «차트 영역 자체»의 크기를 본다(없는 브라우저만 창 크기로). */
  if (window.ResizeObserver) new ResizeObserver(fitRows).observe($('wgScroll'));
  else window.addEventListener('resize', fitRows);
  setGrid(S.cols, S.rows);

  function head(c, it){
    var q = function(s){ return c.el.querySelector('.wg-ch .' + s); }, v = vsBase(it);
    q('nm').textContent = it.name;
    q('cd').textContent = it.code;
    q('hb').innerHTML = heldBadge(it);
    q('px').className = 'px ' + cls(it.rate); q('px').textContent = it.price ? fmt(it.price) : '—';
    q('rt').className = 'rt ' + cls(it.rate); q('rt').textContent = it.price ? pct(it.rate) : '';
    q('bs').textContent = it.base ? '기준 ' + fmt(it.base) + (v === null ? '' : ' (' + pct(v) + ')') : '';
    q('bs').title = it.base ? '기준일 ' + (it.base_date || '') : '';
    var iv = it.var_id ? varAny(it.var_id) : null;
    q('iv').textContent = iv ? '＋' + iv.name : '';
    q('iv').title = iv ? '같이 볼 변수 — 호박색 점선(「겹치기」와 무관하게 늘 보입니다 · 겹쳐 있는 그룹 변수와 같은 변수면 청록 선 한 줄로 보입니다)' : '';
    c.el.classList.toggle('hld', !!it.held);
    c.el.classList.toggle('sel', it.id === S.sel);
  }
  /* 기준가 선 — 노란 점선(가격선). 가격선은 가격축에 «포함»된다(내가 정한 기준이 화면 밖이면 안 된다).
     ★차트 라이브러리가 축 계산을 캐시해서, 봉이 실린 «뒤에» 선을 바꾸면 축이 따라오지 않는다 —
       그때는 같은 봉을 다시 싣는다(rescale). */
  function baseLine(c, it, rescale){
    var sig = it && it.base ? String(it.base) : '';
    if (c.baseSig === sig && !rescale) return;
    c.baseSig = sig;
    c.dc.setPriceLines(sig ? [{ price: it.base, color: '#f2c94c', style: 'dashed', width: 1 }] : []);
    if (rescale && c.loaded && cache[c.code]) c.dc.setData(cache[c.code].rows);
  }
  /* 숨은 채(폭 0) 잡힌 봉 간격은 뜻이 없다 — 칸이 펴진 뒤 창을 다시 잰다(멀티차트와 같은 처리) */
  function refit(c){
    setTimeout(function(){ if (c.dc.barCount()) c.dc.setViewDays(c.dc.viewDays()); }, 60);
  }
  function note(c, t){ c.el.querySelector('.wg-ch .st').textContent = t; }
  function put(c, rows){
    ovClear(c);                               // 옛 종목의 날짜로 선 겹친 선을 먼저 비운다(시간축은 실린 선 전부의 합집합이다)
    c.dc.setData(rows);                       // fit 하지 않는다 — 보이는 구간의 주인은 기간 바다
    c.loaded = true;
    var r = c.dc.range();
    note(c, r ? (r.from + ' ~ ' + r.to) : '');
    ovApply(c);
  }
  function bind(c, it){
    c.code = it.code; c.id = it.id; c.loaded = false; c.loading = false; c.baseSig = null;
    c.dc.setCode(it.code);                    // SUE 공시 마커·수평선은 모듈이 스스로 얹는다
    baseLine(c, it);
    var k = cache[it.code];
    if (k && ttlOk(k.at) && k.rows.length) put(c, k.rows);
    else { ovClear(c); try { c.dc.setData([], true); } catch (e) {} note(c, ''); }
  }
  /* 리스트에 보이는 순서 그대로 칸을 세운다 — 구분행은 전체 폭 제목줄, 접힌 섹션의 칸은 숨긴다 */
  function layoutCharts(rescale){
    if (!POOL.length) return;
    var order = viewItems(), hid = hiddenSet(), want = {}, free = [], n = 0;
    Array.prototype.forEach.call(grid.querySelectorAll('.wg-sec'), function(x){ x.remove(); });
    order.forEach(function(it){ if (isStock(it)) want[it.code] = 1; });
    vlayout();                                // 테마 변수 칸 — 격자 맨 앞
    byCode = {};
    POOL.forEach(function(c){ if (c.code && want[c.code]) byCode[c.code] = c; else free.push(c); });
    order.forEach(function(it){
      if (!isStock(it)) {
        var s = document.createElement('div'), f = !!S.fold[it.id];
        s.className = 'wg-sec';
        s.textContent = (f ? '▸ ' : '▾ ') + it.label;
        if (isTheme() && it.dir) {            // 방향을 적어 둔 섹션 — 주 변수가 그쪽으로 움직인 날이면 「유리」
          var dp = drParts(it), fv = document.createElement('span');
          fv.className = 'fv' + (dp.fav ? ' fav' : ''); fv.textContent = dp.t; fv.title = dp.tip;
          s.appendChild(fv);
        }
        s.onclick = function(){ toggleFold(it.id); };
        grid.appendChild(s);
        return;
      }
      var c = byCode[it.code];
      if (!c) {
        c = free.shift();
        if (!c) return;                       // 풀이 모자라다 — applyItems 가 미리 주소로 다시 연다
        bind(c, it);
        byCode[it.code] = c;
      }
      c.id = it.id;
      head(c, it);
      baseLine(c, it, !!rescale);
      var was = c.el.hidden;
      c.el.hidden = !!hid[it.id];
      grid.appendChild(c.el);
      if (was && !c.el.hidden && c.loaded) refit(c);
      n++;
    });
    free.forEach(function(c){ ovClear(c); c.el.hidden = true; c.code = ''; c.id = 0; c.loaded = false; grid.appendChild(c.el); });
    VPOOL.forEach(function(vc){ if (vc.el.hidden) grid.appendChild(vc.el); });
    POOL.forEach(ovApply);                    // 겹친 선 — 달라진 것만 다시 싣는다(서명으로 가른다)
    $('wgChartMsg').hidden = n > 0;
    $('wgTitle').firstChild.nodeValue = groupName(S.gid) || '차트';
    $('wgCnt').textContent = n ? n + '종목' : '';
    pump();
  }
  /* 둘씩 차례로 받는다 — 단타 대상 종목의 일봉은 키움(1 req/s · 버스트 2)에서 온다.
     «보이는 칸»(+ 아래 한 화면)만 받고, 스크롤하면 그때 이어 받는다. */
  function pump(){
    while (active < 2) {
      var c = null;
      for (var i = 0; i < grid.children.length; i++) {
        var x = grid.children[i]._c;
        if (x && x.code && x.vis && !x.el.hidden && !x.loaded && !x.loading) { c = x; break; }
      }
      if (!c) return;
      load(c);
    }
  }
  function load(c){
    var code = c.code;
    c.loading = true; active++;
    note(c, '불러오는 중…');
    DailyChart.fetchDaily(code, 480).then(function(rows){
      cache[code] = { rows: rows, at: Date.now() };
      if (c.code !== code) return;            // 그 사이 다른 종목에 짝지어졌다
      if (!rows.length) { c.loaded = true; note(c, '데이터 없음'); return; }
      put(c, rows);
    }).catch(function(){
      if (c.code === code) { c.loaded = true; note(c, '일봉을 불러오지 못했습니다'); }
    }).then(function(){
      if (c.code === code) c.loading = false;
      active--;
      pump();
    });
  }
  function chip(id, fn){
    var b = $(id);
    if (!b) return;
    b.onclick = function(){
      var on = !b.classList.contains('on');
      b.classList.toggle('on', on);
      fn(on);
    };
  }

  // ══ 테마 변수 (시장 변수 — 환율·유가·금리…) ══════════════════════
  /* 그룹에 변수를 연결하면 «테마 그룹»이 된다:
       ①격자 맨 앞 칸에 변수의 추이(변수마다 한 칸) ②종목 차트마다 변수 선을 겹친다 ③구분행에 방향을 적어 두면
       주 변수가 그쪽으로 움직인 날 그 섹션을 「유리」로 표시한다.
     ★판정이 아니다 — 방향은 내가 적은 것이고, 「유리」는 주 변수의 «가장 최근 하루»가 오른 날인지 내린 날인지만 본다.
     ★값은 module=mv 가 준다(등록할 때 과거를 받고, 볼 때 낡았으면 한 장으로 잇는다). 가장 최근 하루는 잠정일 수 있어
       변수 칸에 기준일을 적는다.
     ★겹친 선은 «제 눈금»으로 선다(가격축과 무관 · 축 숫자 없음) — 보는 것은 방향과 꺾이는 자리다. 값은 변수 칸에서 읽는다.
       왼쪽 축을 세우지 않는 이유 — 차트 모듈의 배지·수평선 층이 차트 왼쪽 끝을 기준으로 놓여, 축이 생기면 그 너비만큼 어긋난다.
     ★달력이 다르다(유가는 미국 달력) — 주가 봉에 값이 없는 날은 직전 값을 이어 쓴다. 시간축은 주가의 것이다:
       변수의 날짜를 그대로 실으면 주가에 없는 날이 축에 끼어든다(차트의 시간축은 실린 선 전부의 합집합이다). */
  var MV = '/stock/api.php?module=mv&action=';
  var VS = {}, VPOOL = [];
  var ovOn = lsGet('wg_ov') !== '0';
  function mvJson(p){
    return p.then(function(r){ return r.json(); })
      .catch(function(){ throw new Error('응답을 읽지 못했습니다 (로그인이 풀렸을 수 있습니다)'); })
      .then(function(j){ if (!j || !j.ok) throw new Error((j && j.error) || '처리하지 못했습니다.'); return j.data; });
  }
  function mvCall(action, body){
    return mvJson(fetch(MV + action, { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json' },
                                       body: JSON.stringify(body || {}) }));
  }
  function mvGet(q){ return mvJson(fetch(MV + q, { credentials:'same-origin' })); }
  function vfmt(v, dg){ return Number(v).toLocaleString(undefined, { minimumFractionDigits: dg || 0, maximumFractionDigits: dg || 0 }); }
  function varOf(id){ for (var i = 0; i < S.vars.length; i++) if (S.vars[i].id === id) return S.vars[i]; return null; }
  function isTheme(){ return S.vars.length > 0; }
  function pickOv(){ if (!varOf(S.ov)) S.ov = S.vars.length ? S.vars[0].id : 0; }
  /* 주 변수의 «가장 최근 하루» — 구분행의 「유리」가 이것만 본다 */
  /* ★구분행의 방향은 «변수 하나»에 대한 말이다(「환율이 오를 때 유리」) — 그 변수는 방향을 적을 때 구분행에 함께 적힌다(it.var_id).
       처음엔 늘 그룹의 주 변수를 따르게 했는데, 주 변수를 WTI 로 바꾸자 「환율 상승 때 유리」 섹션에 유가가 오른 날 「유리」가 붙었다(사용자 실화면).
       var_id 가 빈 옛 구분행만 주 변수를 따른다. 적어 둔 변수가 이 그룹에서 떼어졌으면 「유리」를 붙이지 않는다. */
  function sepVar(it){ return it.var_id ? varOf(it.var_id) : (S.vars[0] || null); }
  function varMove(v){
    var s = v && VS[v.id];
    if (!s || s.rows.length < 2) return null;
    var a = s.rows[s.rows.length - 1], b = s.rows[s.rows.length - 2];
    return { name: v.name, d: a[0], dir: a[1] > b[1] ? 1 : (a[1] < b[1] ? -1 : 0), pct: b[1] ? (a[1] - b[1]) / Math.abs(b[1]) * 100 : 0 };
  }
  function drParts(it){
    var d = it.dir || 0, v = sepVar(it), m = varMove(v);
    if (!d) return { c: 'dr', t: '↕', fav: false, vid: 0,
                     tip: '방향 없음 — 눌러서 「' + ((varOf(S.ov) || {}).name || '변수') + '이(가) 오를 때 유리」로 (한 번 더 = 내릴 때 유리)' };
    if (!v) return { c: 'dr set', t: (d > 0 ? '↑' : '↓') + ' ?', fav: false, vid: 0,
                     tip: '이 방향을 적은 변수가 지금 이 그룹에 연결돼 있지 않습니다 — 「변수」에서 다시 연결하거나, 눌러서 방향을 다시 적으세요' };
    var fav = !!(m && m.dir === d), main = S.vars[0] && S.vars[0].id === v.id;
    // 주 변수가 아닌 변수에 대한 방향이면 그 이름을 함께 적는다(무엇이 오를 때인지 헷갈리지 않게)
    return { c: 'dr set' + (fav ? ' fav' : ''), t: (d > 0 ? '↑' : '↓') + (fav ? ' 유리' : '') + (main ? '' : ' · ' + v.name), fav: fav, vid: v.id,
             tip: '「' + v.name + '」이(가) ' + (d > 0 ? '오를 때' : '내릴 때') + ' 유리한 종목'
                + (m ? ' — ' + m.d.slice(5) + ' ' + pct(m.pct) : '') + ' · 눌러서 바꾸기' };
  }
  function setDir(id, v){
    var it = byId(id);
    if (!it || isFixed()) return;
    var old = it.dir || 0, oldVar = it.var_id || 0;
    if (old === v) return;
    /* 방향과 함께 «어느 변수에 대한 방향인지»를 적는다 — 이미 적혀 있고 그 변수가 아직 그룹에 있으면 그대로,
       아니면 지금 겹쳐 보는 변수(없으면 주 변수). 방향을 지우면 변수도 지운다. */
    var vid = !v ? 0 : ((oldVar && varOf(oldVar)) ? oldVar : (S.ov || (S.vars[0] && S.vars[0].id) || 0));
    it.dir = v; it.var_id = vid; renderList(); layoutCharts();
    call('item_update', { id: id, dir: v, var_id: vid })
      .catch(function(e){ it.dir = old; it.var_id = oldVar; renderList(); layoutCharts(); fail(e); });
  }

  // ── 겹친 선 ──
  /* 한 칸에 선이 둘까지 선다 — ov = 그룹의 변수(청록 실선) · ov2 = 그 종목의 «같이 볼 변수»(호박색 점선). 각자 제 눈금이다 */
  var OVS = { ov:  { scale: 'mv',  color: 'rgba(63,208,201,.9)',  style: 0 },
              ov2: { scale: 'mv2', color: 'rgba(224,164,74,.95)', style: 2 } };
  function varAny(id){ for (var i = 0; i < S.vall.length; i++) if (S.vall[i].id === id) return S.vall[i]; return varOf(id); }
  /* 「겹치기」 칩은 «그룹의 변수» 선만 켜고 끈다 — 그룹에 변수가 있을 때만 선다.
     ★종목에 단 «같이 볼 변수»는 그 스위치와 무관하게 늘 보인다(2026-10-12 사용자 실화면 — 겹치기를 꺼 둔 채 종목에 변수를 달았더니
       아무것도 안 그려져 「반영이 안 된다」로 읽혔다. 종목에 직접 단 것은 그 자체가 「이 선을 보겠다」는 뜻이다. 끄려면 변수를 뗀다). */
  function hasOv(){ return isTheme(); }
  function ovClear(c){ ['ov', 'ov2'].forEach(function(n){ if (c[n] && c[n + 'Sig']) c[n].setData([]); c[n + 'Sig'] = ''; }); }
  function ovApply(c){
    var it = c.id ? byId(c.id) : null;
    ovLine(c, 'ov', ovOn ? S.ov : 0);
    // 종목의 변수 — 늘 그린다. 지금 겹쳐 있는 그룹의 변수와 같은 것일 때만 한 줄로 접는다(같은 선을 두 번 그리지 않는다)
    ovLine(c, 'ov2', (it && it.var_id && !(ovOn && it.var_id === S.ov)) ? it.var_id : 0);
  }
  function ovLine(c, n, vid){
    var s = vid ? VS[vid] : null, k = cache[c.code], sk = n + 'Sig';
    if (!s || !s.rows.length || !c.code || !c.loaded || !k || !k.rows.length) { if (c[n] && c[sk]) c[n].setData([]); c[sk] = ''; return; }
    var tf = c.dc.tf(), sig = vid + '|' + c.code + '|' + tf + '|' + s.at + '|' + k.at;
    if (c[sk] === sig) return;
    // 주가 봉의 날짜에 맞춘다(주봉이면 그 주 마지막 거래일 — 차트 모듈과 같은 접기) · 값이 없는 날은 직전 값
    var bars = (tf === 'week') ? DailyChart.resampleWeek(k.rows) : k.rows, rows = s.rows, j = 0, last = null, out = [];
    for (var i = 0; i < bars.length; i++) {
      var t = bars[i].time;
      while (j < rows.length && rows[j][0] <= t) { last = rows[j][1]; j++; }
      if (last !== null) out.push({ time: t, value: last });
    }
    if (!c[n]) {
      var o = OVS[n];
      c[n] = c.dc.chart.addLineSeries({ priceScaleId: o.scale, color: o.color, lineWidth: 2, lineStyle: o.style,
                                        priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
      c.dc.chart.priceScale(o.scale).applyOptions({ scaleMargins: { top: 0.06, bottom: 0.26 } });   // 아래는 거래량 자리
    }
    c[n].setData(out);
    c[sk] = sig;
  }
  function setIvar(it, vid){
    if (isFixed()) { toast(FIXED_MSG); return; }
    var old = it.var_id || 0;
    if (old === vid) return;
    function paint(){
      var el = rowEl(it.id), c = byCode[it.code];
      if (el) fillQuote(el, it);
      if (c) head(c, it);
      $('wgOv').hidden = !hasOv();
      loadVars().then(function(){ POOL.forEach(ovApply); });
    }
    it.var_id = vid; paint();
    call('item_update', { id: it.id, var_id: vid }).catch(function(e){ it.var_id = old; paint(); fail(e); });
  }

  function setOv(on){
    ovOn = !!on;
    lsSet('wg_ov', ovOn ? '1' : '0');
    $('wgOv').classList.toggle('on', ovOn);
    VPOOL.forEach(vpaint);
    POOL.forEach(ovApply);
  }
  $('wgOv').classList.toggle('on', ovOn);
  $('wgOv').onclick = function(){ setOv(!ovOn); };

  // ── 변수 칸 ──
  function vlayout(){
    VPOOL.forEach(function(vc, i){
      var v = S.vars[i];
      if (!v) { vc.el.hidden = true; vc.vid = 0; return; }
      if (vc.vid !== v.id) { vc.vid = v.id; vc.sig = ''; try { vc.dc.setData([], true); } catch (e) {} }
      var was = vc.el.hidden;
      vc.el.hidden = false;
      grid.appendChild(vc.el);
      vpaint(vc);
      // 숨은 채(폭 0) 실린 봉의 창은 뜻이 없다 — 칸이 펴진 뒤 다시 잰다
      if (was) setTimeout(function(){ if (vc.dc.barCount()) vc.dc.setViewDays(vc.dc.viewDays()); }, 60);
    });
  }
  function vpaint(vc){
    var v = varOf(vc.vid);
    if (!v) return;
    var q = function(k){ return vc.el.querySelector('.wg-ch .' + k); }, s = VS[v.id], rows = s ? s.rows : [];
    var on = ovOn && S.ov === v.id;
    q('nm').textContent = v.name;
    q('cd').textContent = v.label + (v.unit ? ' · ' + v.unit : '');
    q('ovm').hidden = !on;
    vc.el.classList.toggle('ovsel', on);
    if (!rows.length) {
      q('px').className = 'px flat'; q('px').textContent = s ? '값 없음' : '불러오는 중…';
      q('rt').textContent = ''; q('vg').innerHTML = ''; q('st').textContent = '';
      return;
    }
    var a = rows[rows.length - 1], b = rows.length > 1 ? rows[rows.length - 2] : null;
    var ch = (b && b[1]) ? (a[1] - b[1]) / Math.abs(b[1]) * 100 : null, cc = ch === null ? 'flat' : cls(ch);
    q('px').className = 'px ' + cc; q('px').textContent = vfmt(a[1], v.digits);
    q('rt').className = 'rt ' + cc; q('rt').textContent = ch === null ? '' : pct(ch);
    /* 최근 1년(값 250개)의 최저~최고 범위에서 지금 어디인가 — 외인비 밴드의 위치 막대와 같은 뜻(값의 비례 · 판정이 아니다) */
    var yr = rows.slice(-250), lo = Infinity, hi = -Infinity;
    yr.forEach(function(r){ if (r[1] < lo) lo = r[1]; if (r[1] > hi) hi = r[1]; });
    var rk = hi > lo ? Math.round((a[1] - lo) / (hi - lo) * 100) : 50;
    q('vg').innerHTML = '<i><b style="left:' + rk + '%"></b></i>1년 범위 ' + rk + '%';
    q('vg').title = '최근 1년(값 ' + yr.length + '개) 최저 ' + vfmt(lo, v.digits) + ' ~ 최고 ' + vfmt(hi, v.digits) + ' 사이에서 지금 값의 자리';
    q('st').textContent = a[0].slice(5) + ' 기준';
    q('st').title = '가장 최근 값의 날짜(그 시장의 날짜) — 최근 하루는 잠정일 수 있습니다';
    var sig = v.id + '|' + s.at;
    if (vc.sig !== sig) {
      vc.sig = sig;
      var dg = v.digits;
      vc.dc.chart.applyOptions({ localization: { priceFormatter: function(x){ return vfmt(x, dg); } } });
      vc.dc.setData(rows.map(function(r){ return { t: r[0], c: r[1] }; }));
    }
  }
  /* 값 받기 — 아직 없는 변수(또는 10분 넘게 지난 것)만. fresh=1 이면 서버가 낡은 변수를 한 장으로 잇는다 */
  function loadVars(force){
    var want = S.vars.map(function(v){ return v.id; });
    S.items.forEach(function(x){ if (x.var_id && want.indexOf(x.var_id) < 0 && varAny(x.var_id)) want.push(x.var_id); });   // 종목별 «같이 볼 변수»
    var need = want.filter(function(id){
      return force || !VS[id] || (Date.now() - VS[id].at) > 600000;
    });
    if (!need.length) return Promise.resolve();
    return mvGet('series&fresh=1&ids=' + need.join(',')).then(function(d){
      need.forEach(function(id){ VS[id] = { rows: (d.series && d.series[id]) || [], at: Date.now() }; });
      (d.vars || []).forEach(function(m){ var v = varOf(m.id); if (v) v.last_d = m.last_d; });
      if (!S.edit && $('wgMenu').hidden) renderList();     // 구분행의 「유리」
      layoutCharts();                                      // 변수 칸 · 겹친 선 · 섹션 제목
    }).catch(fail);
  }

  // ── 변수 모달 — 이 그룹의 변수 · 찾아서 등록 · 등록된 변수 ──
  var VM = { all: [] };
  function vmOpen(){
    if (!S.gid || isFixed()) { toast('변수는 내가 만든 그룹에 연결합니다 — 고정 그룹에는 달 수 없습니다.'); return; }
    $('wgVarT').textContent = '테마 변수 — ' + groupName(S.gid);
    $('wgVarQ').value = ''; $('wgVarSug').style.display = 'none';
    $('wgVar').hidden = false;
    vmGroup(); vmLoadAll();
  }
  function vmClose(){ $('wgVar').hidden = true; }
  function vmGroup(){
    var box = $('wgVarG');
    if (!WG_MV) {
      box.innerHTML = '<div class="wg-vnone">변수 표(mv_var · mv_daily · wg_group_var)가 아직 없습니다.<br>'
                    + '<button type="button" class="wg-btn pri" data-a="schema">표 만들기</button></div>';
      return;
    }
    if (!S.vars.length) {
      box.innerHTML = '<div class="wg-vnone">아직 연결한 변수가 없습니다. 아래에서 찾아 고르면 이 그룹이 «테마 그룹»이 됩니다 — '
                    + '차트 맨 앞에 변수의 추이가 서고, 종목 차트마다 변수 선이 겹치고, 구분행에 방향(↑ 오를 때 유리 · ↓ 내릴 때 유리)을 적을 수 있습니다.</div>';
      return;
    }
    box.innerHTML = S.vars.map(function(v, i){
      return '<div class="wg-vr" data-id="' + v.id + '"><span class="k' + (i ? ' sub' : '') + '">' + (i ? '보조' : '주') + '</span>'
        + '<span class="nm">' + esc(v.name) + '</span><small>' + esc(v.label) + (v.unit ? ' · ' + esc(v.unit) : '')
        + (v.last_d ? ' · ' + esc(String(v.last_d).slice(5)) + ' 까지' : '') + '</small><span class="sp"></span>'
        + (i ? '<button type="button" class="wg-btn" data-a="main" title="이 변수를 주 변수로 올립니다">주 변수로</button>' : '')
        + '<button type="button" class="wg-btn" data-a="rm" title="이 그룹에서만 뗍니다 — 변수와 쌓인 값은 남습니다">떼기</button></div>';
    }).join('');
  }
  function vmApply(vars, groups){
    S.vars = vars || [];
    pickOv();
    if (groups) S.groups = groups;
    else { var g = groupOf(S.gid); if (g) g['var'] = S.vars.length ? S.vars[0].name : ''; }
    vmGroup(); vmLoadAll();
    renderGroups(); renderList(); layoutCharts();
    return loadVars();
  }
  function vmSet(ids){
    return mvCall('group_set', { group_id: S.gid, ids: ids }).then(function(d){ return vmApply(d.vars, d.groups); }).catch(fail);
  }
  function vmLoadAll(){
    if (!WG_MV) { $('wgVarAll').innerHTML = ''; $('wgVarCnt').textContent = ''; return; }
    mvGet('var_list').then(function(d){
      VM.all = d.vars || [];
      S.vall = VM.all;
      $('wgVarCnt').textContent = VM.all.length + ' / ' + d.max + '개 — 매일 마감 뒤 한 번, 그리고 볼 때 낡았으면 이어 받습니다';
      var box = $('wgVarAll');
      if (!VM.all.length) { box.innerHTML = '<div class="wg-vnone">등록된 변수가 없습니다.</div>'; return; }
      box.innerHTML = VM.all.map(function(v){
        var linked = !!varOf(v.id);
        return '<div class="wg-vr" data-id="' + v.id + '"><span class="nm">' + esc(v.name) + '</span><small title="쌓인 날 수 · 기간 · 연결된 그룹 수">'
          + esc(v.label) + ' · ' + (v.n || 0).toLocaleString() + '일' + (v.from ? ' (' + esc(String(v.from).slice(2)) + ' ~ ' + esc(String(v.last_d || '').slice(2)) + ')' : '')
          + ' · 그룹 ' + (v.groups || 0) + '</small><span class="sp"></span>'
          + (linked ? '' : '<button type="button" class="wg-btn" data-a="link" title="이 그룹에 연결합니다">연결</button>')
          + '<button type="button" class="wg-btn" data-a="pull" title="네이버에서 최근 60일을 다시 받아 덮습니다">새로 받기</button>'
          + '<button type="button" class="wg-btn dg" data-a="del" title="변수와 쌓인 값을 지웁니다 — 연결한 그룹에서도 사라집니다(다시 등록하면 다시 받습니다)">삭제</button></div>';
      }).join('');
    }).catch(fail);
  }
  $('wgVarG').addEventListener('click', function(e){
    var b = e.target.closest('[data-a]'), a = b && b.getAttribute('data-a');
    if (!a) return;
    if (a === 'schema') {
      b.disabled = true;
      mvCall('schema').then(function(d){ if (d.ready) { WG_MV = 1; vmGroup(); vmLoadAll(); } else { b.disabled = false; toast('표를 만들지 못했습니다.'); } })
        .catch(function(er){ b.disabled = false; fail(er); });
      return;
    }
    var row = b.closest('.wg-vr'), id = row ? +row.dataset.id : 0, ids = S.vars.map(function(v){ return v.id; });
    if (a === 'rm') vmSet(ids.filter(function(x){ return x !== id; }));
    else if (a === 'main') vmSet([id].concat(ids.filter(function(x){ return x !== id; })));
  });
  $('wgVarAll').addEventListener('click', function(e){
    var b = e.target.closest('[data-a]'), a = b && b.getAttribute('data-a'), row = b && b.closest('.wg-vr'), id = row ? +row.dataset.id : 0;
    if (!a || !id) return;
    if (a === 'link') {
      if (S.vars.length >= WG_VMAX) { toast('한 그룹에 변수는 ' + WG_VMAX + '개까지입니다.'); return; }
      vmSet(S.vars.map(function(v){ return v.id; }).concat([id]));
    } else if (a === 'pull') {
      b.disabled = true;
      mvCall('var_pull', { id: id }).then(function(d){
        delete VS[id];
        toast('「' + d['var'].name + '」 ' + d.rows + '일치를 다시 받았습니다.');
        vmLoadAll();
        return loadVars();
      }).catch(function(er){ b.disabled = false; fail(er); });
    } else if (a === 'del') {
      // confirm() 을 쓰지 않는다 — 한 번 누르면 3초 동안 「정말?」로 바뀌고 그 안에 다시 눌러야 지운다
      if (!b.dataset.arm) {
        var o = b.textContent;
        b.dataset.arm = '1'; b.textContent = '정말 삭제?'; b.classList.add('arm');
        setTimeout(function(){ delete b.dataset.arm; b.textContent = o; b.classList.remove('arm'); }, 3000);
        return;
      }
      mvCall('var_delete', { id: id }).then(function(){
        delete VS[id];
        // 그룹을 다시 읽는다 — 그룹의 연결도, 종목에 달아 둔 «같이 볼 변수»도 함께 사라졌다
        return openGroup(S.gid, true).then(function(){ vmGroup(); vmLoadAll(); });
      }).catch(fail);
    }
  });
  // 찾기 — 목록(네이버 시장지표)에서 고른다. ↓/↑/Enter/Esc 는 공용 모듈(style/acnav.js)
  var vqT = null;
  function vmSearch(q){
    if (!WG_MV) return;
    mvGet('catalog&q=' + encodeURIComponent(q)).then(function(d){
      var box = $('wgVarSug'), list = d.items || [];
      if (!list.length) { box.style.display = 'none'; return; }
      box.innerHTML = '';
      list.forEach(function(c){
        var el = document.createElement('div');
        el.className = 's';
        el.innerHTML = '<span>' + esc(c.name) + '</span><span class="c">' + esc(c.label) + (c.unit ? ' · ' + esc(c.unit) : '')
                     + (c.id ? (varOf(c.id) ? ' · 연결됨' : ' · 등록됨') : '') + '</span>';
        el.onclick = function(){ vmPick(c); };
        box.appendChild(el);
      });
      box.style.display = 'block';
    }).catch(function(){});
  }
  function vmPick(c){
    $('wgVarSug').style.display = 'none';
    $('wgVarQ').value = '';
    if (c.id && varOf(c.id)) { toast('이미 이 그룹에 연결된 변수입니다.'); return; }
    if (S.vars.length >= WG_VMAX) { toast('한 그룹에 변수는 ' + WG_VMAX + '개까지입니다.'); return; }
    if (c.id) { vmSet(S.vars.map(function(v){ return v.id; }).concat([c.id])); return; }
    // 처음 등록 — 서버가 과거 값을 되짚는다(몇 초 ~ 십몇 초). 그동안 칸을 잠근다
    var inp = $('wgVarQ');
    inp.disabled = true; inp.placeholder = '「' + c.name + '」의 과거 값을 받는 중입니다…';
    mvCall('var_add', { cat: c.cat, code: c.code, group_id: S.gid }).then(function(d){
      toast('「' + c.name + '」 ' + (d.rows || 0).toLocaleString() + '일치를 받아 등록했습니다.');
      return vmApply(d.vars);
    }).catch(fail).then(function(){ inp.disabled = false; inp.placeholder = '예: 달러, WTI, 국채, 구리, BDI'; });
  }
  $('wgVarQ').addEventListener('input', function(){
    clearTimeout(vqT);
    var q = this.value.trim();
    vqT = setTimeout(function(){ vmSearch(q); }, 200);
  });
  $('wgVarQ').addEventListener('focus', function(){ if (!this.value.trim()) vmSearch(''); });
  /* Esc — 목록이 떠 있으면 목록만 닫고(공용 모듈), 없으면 모달을 닫는다. ★모듈보다 «먼저» 건다: 뒤에 걸면 모듈이 목록을 닫은 직후라
     같은 한 번의 Esc 에 모달까지 닫힌다. */
  $('wgVarQ').addEventListener('keydown', function(e){ e.stopPropagation(); if (e.key === 'Escape' && $('wgVarSug').style.display === 'none') vmClose(); });
  AcNav.attach($('wgVarQ'), { box:'#wgVarSug', item:'.s', close:function(){ $('wgVarSug').style.display = 'none'; } });
  $('wgVarBtn').onclick = vmOpen;
  $('wgVarX').onclick = vmClose;
  $('wgVar').addEventListener('mousedown', function(e){ if (e.target === this) vmClose(); });

  // ══ 그룹 관리 · 일괄 등록 (모달) ══════════════════════════════════
  var M = { gid: 0, items: [], parsed: null, fname: '' };   // fname = 칸의 글이 온 md 파일 이름(손으로 고치면 비운다)
  function mgOpen(){
    var ug = userGroups();
    M.gid = (S.gid && !isFixed()) ? S.gid : ((ug[0] && ug[0].id) || 0);
    M.parsed = null; M.fname = '';
    $('wgMgPr').innerHTML = ''; $('wgMgAdd').disabled = true;
    thOff();
    $('wgMg').hidden = false;
    mgGroups(); mgLoad();
  }
  /* 닫으면 메인 화면을 다시 읽는다 — 모달 안에서 그룹·종목이 얼마든 바뀌었을 수 있다 */
  function mgClose(){
    $('wgMg').hidden = true;
    var alive = S.groups.some(function(g){ return g.id === S.gid; });
    if (!alive) S.gid = (S.groups[0] && S.groups[0].id) || 0;
    if (S.gid) openGroup(S.gid, true);
    else { S.items = []; remember(0); renderAll(); }
  }
  function mgGroups(){
    var box = $('wgMgG'), ug = userGroups();
    box.innerHTML = '';
    ug.forEach(function(g){
      var el = document.createElement('div');
      el.className = 'mg-g' + (g.id === M.gid ? ' on' : '');
      el.dataset.id = g.id;
      el.innerHTML = '<div class="rw"><span class="hd" title="끌어서 순서 변경">⋮⋮</span><span class="nm">' + esc(g.name)
        + '</span><span class="n">' + g.n + '</span>'
        + '<button type="button" class="ic" data-a="ren" title="이름 변경">✎</button>'
        + '<button type="button" class="ic" data-a="del" title="그룹 삭제">🗑</button></div>';
      box.appendChild(el);
    });
    if (!ug.length) box.innerHTML = '<div class="wg-msg">내 그룹이 없습니다.<br>아래 「일괄 등록」에서 새 그룹으로 만들 수 있습니다.</div>';
    // 대상 그룹 셀렉트 둘 — 복사·이동(지금 그룹 제외) · 일괄 등록(새 그룹 포함)
    var opt = function(g){ return '<option value="' + g.id + '">' + esc(g.name) + '</option>'; };
    $('wgMgTo').innerHTML = ug.filter(function(g){ return g.id !== M.gid; }).map(opt).join('')
                          || '<option value="0">다른 그룹 없음</option>';
    var keep = $('wgMgPt').value;
    $('wgMgPt').innerHTML = ug.map(opt).join('') + '<option value="new">＋ 새 그룹…</option>';
    $('wgMgPt').value = (keep && $('wgMgPt').querySelector('option[value="' + keep + '"]')) ? keep
                      : (M.gid ? String(M.gid) : 'new');
    $('wgMgPn').hidden = $('wgMgPt').value !== 'new';
    $('wgMgT').textContent = groupName(M.gid) || '그룹을 고르세요';
    renderGroups();
  }
  function mgLoad(){
    $('wgMgAll').checked = false;
    if (!M.gid) { M.items = []; mgItems(); return; }
    call('item_list', { group_id: M.gid }).then(function(d){
      M.items = d.items.filter(isStock);
      S.groups = d.groups;
      mgGroups(); mgItems();
    }).catch(fail);
  }
  function mgItems(){
    var box = $('wgMgI');
    if (!M.items.length) { box.innerHTML = '<div class="wg-msg">이 그룹에 종목이 없습니다.</div>'; return; }
    box.innerHTML = M.items.map(function(it){
      return '<label class="mg-i"><input type="checkbox" value="' + it.id + '"><span>' + esc(it.name)
           + '</span><span class="c">' + esc(it.code) + '</span>' + (it.memo ? '<span class="m" title="메모 있음">◥</span>' : '')
           + '<span class="b">' + (it.base ? fmt(it.base) : '') + '</span></label>';
    }).join('');
  }
  function mgChecked(){
    return Array.prototype.map.call($('wgMgI').querySelectorAll('input:checked'), function(x){ return +x.value; });
  }
  $('wgMgAll').onchange = function(){
    var on = this.checked;
    Array.prototype.forEach.call($('wgMgI').querySelectorAll('input'), function(x){ x.checked = on; });
  };
  function mgTransfer(move){
    var ids = mgChecked(), to = +$('wgMgTo').value;
    if (!ids.length) { toast('종목을 고르세요.'); return; }
    if (!to) { toast('대상 그룹이 없습니다.'); return; }
    call(move ? 'item_move' : 'item_copy', { ids: ids, target_group_id: to }).then(function(d){
      S.groups = d.groups;
      toast((move ? '이동 ' : '복사 ') + d.done + '건' + (d.skip ? ' · 이미 있어 건너뜀 ' + d.skip + '건' : '')
            + (d.over ? ' · 가득 차서 못 담음 ' + d.over + '건 (그룹당 ' + WG_MAX + '종목)' : ''));
      mgLoad();
    }).catch(fail);
  }
  $('wgMgCopy').onclick = function(){ mgTransfer(false); };
  $('wgMgMove').onclick = function(){ mgTransfer(true); };
  arm($('wgMgDel'), '고른 종목을 삭제할까요?', function(){
    var ids = mgChecked();
    if (!ids.length) { toast('종목을 고르세요.'); return; }
    call('item_delete', { ids: ids }).then(function(d){ S.groups = d.groups; mgLoad(); }).catch(fail);
  });
  // 그룹 줄 — 고르기 · 이름 변경(두 번 누르기/✎) · 삭제(줄 안의 확인 상자)
  $('wgMgG').addEventListener('click', function(e){
    var el = e.target.closest('.mg-g');
    if (!el) return;
    var id = +el.dataset.id, a = e.target.closest('[data-a]');
    a = a ? a.getAttribute('data-a') : '';
    if (a === 'ren') { mgRename(el, id); return; }
    if (a === 'del') {
      if (el.querySelector('.cf')) return;
      var g = S.groups.filter(function(x){ return x.id === id; })[0];
      var cf = document.createElement('div');
      cf.className = 'cf';
      cf.innerHTML = '이 그룹과 종목 ' + (g ? g.n : 0) + '개, 기준가·메모가 함께 삭제됩니다.'
        + '<div><button type="button" class="wg-btn dg" data-a="delok">그룹 삭제</button>'
        + '<button type="button" class="wg-btn" data-a="delno">취소</button></div>';
      el.appendChild(cf);
      return;
    }
    if (a === 'delno') { el.querySelector('.cf').remove(); return; }
    if (a === 'delok') {
      call('group_delete', { id: id }).then(function(d){
        S.groups = d.groups;
        if (M.gid === id) { var u = userGroups(); M.gid = (u[0] && u[0].id) || 0; }
        mgGroups(); mgLoad();
      }).catch(fail);
      return;
    }
    if (e.target.closest('input')) return;
    if (M.gid !== id) { M.gid = id; mgGroups(); mgLoad(); }
  });
  $('wgMgG').addEventListener('dblclick', function(e){
    var el = e.target.closest('.mg-g');
    if (el && e.target.closest('.nm')) mgRename(el, +el.dataset.id);
  });
  function mgRename(el, id){
    var nm = el.querySelector('.nm');
    if (nm.querySelector('input')) return;
    var old = groupName(id), inp = document.createElement('input'), done = false;
    inp.className = 'wg-in'; inp.maxLength = 30; inp.value = old; inp.style.padding = '2px 6px';
    nm.textContent = ''; nm.appendChild(inp);
    inp.focus(); inp.select();
    function close(){ if (done) return; done = true; mgGroups(); }
    function commit(){
      var v = inp.value.trim();
      if (!v || v === old) { close(); return; }
      done = true;
      call('group_rename', { id: id, name: v }).then(function(d){ S.groups = d.groups; mgGroups(); })
        .catch(function(e){ fail(e); mgGroups(); });
    }
    inp.addEventListener('keydown', function(e){
      e.stopPropagation();
      if (e.key === 'Enter') commit(); else if (e.key === 'Escape') close();
    });
    inp.addEventListener('blur', function(){ if (!done) commit(); });
  }
  if (window.Sortable) new Sortable($('wgMgG'), {
    handle: '.hd', draggable: '.mg-g', animation: 150,
    onEnd: function(e){
      if (e.oldIndex === e.newIndex) return;
      var ids = Array.prototype.map.call($('wgMgG').querySelectorAll('.mg-g'), function(x){ return +x.dataset.id; });
      call('group_reorder', { ids: ids }).then(function(d){ S.groups = d.groups; mgGroups(); })
        .catch(function(er){ fail(er); mgGroups(); });
    }
  });
  // 일괄 등록 — 미리보기(인식/미인식) → 대상 그룹 → 추가
  function mgTarget(){ var v = $('wgMgPt').value; return v === 'new' ? 0 : +v; }
  function mgPreview(){
    var text = $('wgMgTx').value;
    M.parsed = null; $('wgMgAdd').disabled = true;
    if (!text.trim()) { thOff(); $('wgMgPr').innerHTML = ''; return; }
    if (isThemeText(text)) { thPreview(text); return; }     // 머리말(---)로 시작하면 테마 md 부터 물어본다
    thOff();
    mgParse(text);
  }
  /* 옛 붙여넣기 — 목록 또는 표(종목 │ 근거 …). 테마 md 가 아니면 여기로 온다 */
  function mgParse(text){
    call('item_parse', { text: text, group_id: mgTarget() }).then(function(d){
      M.parsed = d; M.text = text;
      var fresh = d.ok.filter(function(x){ return !x.dup; }).length, dup = d.ok.length - fresh;
      $('wgMgPr').innerHTML = '<b>인식 ' + d.ok.length + '</b> (신규 ' + fresh + ' · 중복 ' + dup + ')'
        + ' / <b class="' + (d.miss.length ? 'ms' : '') + '">미인식 ' + d.miss.length + '</b>'
        + (d.miss.length ? ' — <span class="ms">' + d.miss.map(esc).join(' · ') + '</span>' : '')
        // 표로 읽혔다 — 무엇이 어디로 가는지 담기 전에 말한다
        + (d.table ? '<br><b style="color:var(--mv)">표로 읽었습니다</b> — 근거 ' + d.table.memos + '건은 메모로'
            + (d.table.secs.length ? ' · 구분행 ' + d.table.secs.map(function(s){
                return '「' + esc(s.label) + '」' + (s.dir > 0 ? '↑' : (s.dir < 0 ? '↓' : '')) + ' ' + s.n; }).join(' · ') : '')
            + ' (이미 있는 종목의 메모는 덮지 않습니다)' : '')
        // 그룹당 WG_MAX 종목 — 자리가 모자라면 앞에서부터 자리만큼만 담긴다는 것을 «담기 전에» 말한다
        + (fresh > d.room ? '<br><span class="ms">남은 자리 ' + d.room + '개 — 신규 ' + fresh + '종목 중 '
                            + (fresh - d.room) + '종목은 담기지 않습니다 (그룹당 ' + WG_MAX + '종목)</span>' : '');
      $('wgMgAdd').disabled = fresh === 0 || d.room === 0;
    }).catch(fail);
  }
  $('wgMgPv').onclick = mgPreview;
  $('wgMgPt').onchange = function(){
    $('wgMgPn').hidden = this.value !== 'new';
    if (this.value === 'new') $('wgMgPn').focus();
    if ($('wgMgTx').value.trim()) mgPreview();          // 대상이 바뀌면 「중복」이 달라진다
  };
  $('wgMgAdd').onclick = function(){
    if (!M.parsed) return;
    var codes = M.parsed.ok.filter(function(x){ return !x.dup; }).map(function(x){ return x.code; });
    if (!codes.length) return;
    var btn = this, gid = mgTarget();
    btn.disabled = true;
    var p = gid ? Promise.resolve(gid) : (function(){
      var name = $('wgMgPn').value.trim();
      if (!name) return Promise.reject(new Error('새 그룹 이름을 적어 주세요.'));
      return call('group_create', { name: name }).then(function(d){ S.groups = d.groups; return d.id; });
    })();
    p.then(function(id){
      var tb = !!M.parsed.table;               // 표로 읽힌 글은 서버가 다시 읽어 구분행·메모까지 담는다
      return call(tb ? 'table_add' : 'item_add', tb ? { group_id: id, text: M.text } : { group_id: id, codes: codes }).then(function(d){
        S.groups = d.groups;
        toast('「' + groupName(id) + '」에 ' + d.added.length + '종목을 추가했습니다.'
              + (tb ? ' (구분행 ' + d.seps + ' · 메모 ' + d.memos + ')' : '')
              + (d.dup.length ? ' (중복 ' + d.dup.length + ')' : '')
              + ((d.over || []).length ? ' · 가득 차서 ' + d.over.length + '종목은 못 담았습니다' : ''));
        $('wgMgTx').value = ''; $('wgMgPn').value = ''; $('wgMgPr').innerHTML = ''; M.parsed = null;
        M.gid = id;
        mgGroups();
        $('wgMgPt').value = String(id); $('wgMgPn').hidden = true;
        mgLoad();
      });
    }).catch(function(e){ btn.disabled = false; fail(e); });
  };
  // ── 테마 md (머리말 wg: theme/1) — 미리보기 → 모드 → 적용 (2026-10-26 · CLAUDE.md 관심그룹 규칙 17) ──
  /* 대상 그룹은 md 의 group 이 정한다(이름 칸에서 바꿀 수 있다) — 같은 이름이 있으면 덧붙이기·갈아끼우기, 없으면 새 그룹.
     ★화면은 원문만 보낸다 — 서버가 다시 읽고, 적용 때 계획을 다시 만들어 미리보기의 sig 와 맞춰 본다(그 사이 바뀌었으면 막는다).
     ★행 단위 오류는 그 행만 빼고 적용할 수 있다 · 파일 단위 오류가 하나라도 있으면 적용 버튼이 잠긴다. */
  var TH = { text: '', data: null, mode: '', name: '' };
  var TH_ST   = { 'new': ['new', '신규'], keep: ['keep', '그대로'], fill: ['fill', '기준가 채움'], over: ['over', WG_MAX + ' 초과'], bad: ['bad', '제외'] };
  var TH_MODE = { 'new': '새 그룹', append: '덧붙이기', replace: '갈아끼우기' };
  var TH_TIP  = { 'new': '그룹을 새로 만들어 md 순서대로 담습니다',
                  append: '새 종목만 더합니다 — 이미 있는 종목은 메모·기준가·자리를 건드리지 않습니다(비어 있는 기준가만 채웁니다)',
                  replace: '그룹을 md 대로 다시 만듭니다 — 지금 모습은 백업되고, 테마 노트의 「되돌리기」로 돌아갈 수 있습니다' };
  function isThemeText(t){ return /^﻿?\s*---[ \t]*\r?\n/.test(t); }
  function thOff(){
    TH.text = ''; TH.data = null; TH.mode = ''; TH.name = '';
    $('wgMgBox').classList.remove('th-on');
    $('wgThGo').hidden = true; $('wgThGo').disabled = true;
  }
  /* 적용하면 무엇이든 바뀌나 — 바뀔 것이 없으면 버튼을 잠근다(같은 파일을 덧붙이기로 또 넣는 경우) */
  function thWrites(d){
    var c = d.counts, m = d.group.mode;
    return m === 'replace' || c.add > 0 || c.fill > 0 || (m === 'append' && d.vars.after.join('|') !== d.vars.before.join('|'));
  }
  function thPreview(text){
    if (text !== TH.text) { TH.mode = ''; TH.name = ''; }   // 새 글이면 모드·이름은 md 의 것부터
    $('wgThGo').disabled = true;
    callForm('theme_parse', { md: text, mode: TH.mode, name: TH.name }).then(function(d){
      if (!d.theme) { thOff(); mgParse(text); return; }       // 머리말에 wg: 가 없다 — 옛 붙여넣기로
      TH.text = text; TH.data = d; TH.mode = d.group.mode;
      $('wgMgBox').classList.add('th-on');
      var go = $('wgThGo');
      go.hidden = false;
      go.textContent = TH_MODE[d.group.mode] === '새 그룹' ? '적용 — 새 그룹' : TH_MODE[d.group.mode];
      go.disabled = d.errors.length > 0 || !thWrites(d);
      $('wgMgPr').innerHTML = thHtml(d);
    }).catch(fail);
  }
  function thBox(title, items){ return '<div class="th-box"><b>' + esc(title) + '</b><ul><li>' + items.join('</li><li>') + '</li></ul></div>'; }
  function thHtml(d){
    var g = d.group, m = d.meta, c = d.counts, h = '';
    h += '<div class="th-hd"><b>테마 md</b>' + (M.fname ? '<span class="th-fn" title="이 파일에서 읽었습니다">📄 ' + esc(M.fname) + '</span>' : '') + '<span>그룹</span>'
       + '<input class="wg-in" id="wgThName" maxlength="30" value="' + esc(g.name || g.name_md) + '" title="이름을 바꾸고 Enter — 그 이름으로 다시 봅니다">'
       + (g.exists ? '<span class="th-ex">있는 그룹 · ' + g.n + '종목</span>' : '<span class="th-nw">새 그룹</span>')
       + '<span class="dt-seg" id="wgThMode">' + g.modes.map(function(k){
           return '<button type="button" data-m="' + k + '" class="' + (k === g.mode ? 'on' : '') + '" title="' + esc(TH_TIP[k]) + '">' + TH_MODE[k] + '</button>';
         }).join('') + '</span></div>';
    h += '<div class="th-meta">asof ' + esc(m.asof || '—') + ' · 기준가 ' + ({ close: 'asof 종가', now: '지금 현재가', none: '비움' }[m.base] || '—')
       + (m.review ? ' · 재점검 ' + esc(m.review) : '') + ' · ' + (m.vars.length ? '변수 ' + esc(m.vars.join(', ')) : '변수 없음')
       + (m.rebase ? ' · rebase' : '')
       + (m.source ? ' · <a href="' + esc(m.source) + '" target="_blank" rel="noopener noreferrer">출처</a>' : '')
       + (m.title ? '<br>' + esc(m.title) : '') + '</div>';
    d.errors.forEach(function(x){ h += '<div class="th-err">⛔ ' + esc(x) + '</div>'; });
    d.warns.forEach(function(x){ h += '<div class="th-warn">⚠ ' + esc(x) + '</div>'; });
    h += '<div class="th-cnt"><b>구분행 ' + c.sections + ' · 종목 ' + c.stocks + '</b> — 신규 ' + c.add
       + (g.mode !== 'new' ? ' · 그대로 ' + c.keep : '') + (c.fill ? ' · 기준가 채움 ' + c.fill : '')
       + (c.excluded ? ' · <span class="th-err">제외 ' + c.excluded + '</span>' : '')
       + (c.over ? ' · <span class="th-warn">초과 ' + c.over + '</span>' : '')
       + (c.delete ? ' · <span class="th-err">지움 ' + c.delete + '</span>' : '') + '</div>';
    if (d.errors.length) h += '<div class="th-err">파일 오류가 있어 적용할 수 없습니다 — md 를 고쳐 다시 붙여넣으세요.</div>';
    else if (!thWrites(d)) h += '<div class="th-warn">바뀔 것이 없습니다 — 이미 다 들어 있습니다.</div>';
    // 갈아끼우기에서 사라지는 것 · 바뀌는 메모 · 이어받는 기준가 · 상한 초과 — 담기 «전»에 말한다
    if (d.deletes.length) h += thBox('지워질 종목 ' + d.deletes.length, d.deletes.map(function(x){
      return esc(x.name) + ' <span class="c">' + esc(x.code) + '</span>' + (x.held ? ' <span class="dt-bdg held">보유</span>' : ''); }));
    if (d.memo_changes.length) h += thBox('메모가 바뀌는 종목 ' + d.memo_changes.length, d.memo_changes.map(function(x){
      return esc(x.name) + ' <span class="c">' + esc(x.code) + '</span> — <span title="' + esc(x.old) + '">지금 ' + x.old.length + '자</span> → ' + x.new.length + '자'; }));
    if (d.inherits.length) h += thBox('기준가를 이어받는 종목 ' + d.inherits.length + ' (머리말 rebase: yes 면 새로 계산합니다)', d.inherits.map(function(x){
      return esc(x.name) + ' ' + fmt(x.price) + ' <span class="c">' + esc(x.date || '') + '</span>'; }));
    if (d.over.length) h += thBox('그룹당 ' + WG_MAX + '종목을 넘어 담지 않는 종목 ' + d.over.length, d.over.map(function(x){
      return esc(x.name) + ' <span class="c">' + esc(x.code) + ' · ' + x.line + '행</span>'; }));
    h += '<table class="th-t"><thead><tr><th>종목</th><th>상태</th><th style="text-align:right">기준가</th><th>메모</th><th>알림</th></tr></thead><tbody>';
    d.sections.forEach(function(s){
      var nNew = s.rows.filter(function(r){ return r.state === 'new'; }).length;
      h += '<tr class="sec"><td colspan="5">' + (s.label ? esc(s.label) : '(구분행 없음 — 맨 위)')
         + (s.dir ? '<span class="k">' + (s.dir > 0 ? '↑ ' : '↓ ') + esc(s.var) + (s.dir > 0 ? '이(가) 오를 때 유리' : '이(가) 내릴 때 유리') + '</span>' : '')
         + (s.label ? '<span class="k">' + (s.sep === 'exists' ? '있는 구분행 — 그 섹션 끝에'
                                          : (g.mode === 'append' && !nNew ? '새 종목 없음 — 구분행을 만들지 않습니다' : '새 구분행')) + '</span>' : '')
         + '</td></tr>';
      s.rows.forEach(function(r){
        var st = TH_ST[r.state] || ['bad', r.state], b = r.base;
        var px = '';
        if (b && b.price) {
          px = fmt(b.price) + '<small>' + esc((b.date || '').slice(5)) + '</small>'
             + (b.src === 'n' ? '<span class="th-pv" title="15:50 잠정 종가 — 다음 거래일 13:05 에 KRX 확정값이 들어옵니다(기준가는 저절로 고쳐지지 않습니다)">잠정</span>' : '')
             + (b.how === 'inherit' ? '<small title="갈아끼우기 전의 기준가를 이어받습니다">이어받음</small>' : (b.how === 'keep' ? '<small>그대로</small>' : ''));
        }
        h += '<tr' + (r.state === 'bad' || r.state === 'over' ? ' class="x"' : '') + '><td>' + esc(r.name_md || '—') + '<span class="c">' + esc(r.code || '') + '</span>'
           + (r.held ? ' <span class="dt-bdg held">보유</span>' : '') + '</td>'
           + '<td><span class="th-st ' + st[0] + '">' + st[1] + '</span></td>'
           + '<td class="px">' + px + '</td>'
           + '<td class="ml" title="' + esc(r.memo) + '">' + (r.memo_len ? r.memo_len + '자' : '—') + '</td>'
           + '<td class="nt">' + r.errs.map(function(x){ return '<div class="th-err">' + esc(x) + '</div>'; }).join('')
           + r.warns.map(function(x){ return '<div class="th-warn">' + esc(x) + '</div>'; }).join('') + '</td></tr>';
      });
    });
    return h + '</tbody></table>';
  }
  // 모드 고르기 · 이름 바꾸기 — 같은 글로 다시 물어본다
  $('wgMgPr').addEventListener('click', function(e){
    var b = e.target.closest('#wgThMode button');
    if (!b || !TH.data) return;
    var m = b.getAttribute('data-m');
    if (m === TH.data.group.mode) return;
    TH.mode = m; thPreview(TH.text);
  });
  function thRename(inp){
    if (!TH.data) return;
    var v = inp.value.trim();
    if (!v || v === TH.data.group.name) return;
    TH.name = v; TH.mode = ''; thPreview(TH.text);
  }
  $('wgMgPr').addEventListener('keydown', function(e){
    if (e.target.id === 'wgThName' && e.key === 'Enter') { e.preventDefault(); thRename(e.target); }
  });
  $('wgMgPr').addEventListener('change', function(e){ if (e.target.id === 'wgThName') thRename(e.target); });
  // 글을 고치면 지난 미리보기는 낡았다 — 다시 보기 전에는 적용하지 않는다
  $('wgMgTx').addEventListener('input', function(){
    if (!TH.data) return;
    TH.data = null; $('wgThGo').disabled = true;
    $('wgMgPr').insertAdjacentHTML('afterbegin', '<div class="th-warn">글이 바뀌었습니다 — 「미리보기」를 다시 누르세요.</div>');
  });
  arm($('wgThGo'), function(){
    return (TH.data && TH.data.group.mode === 'replace') ? '정말 갈아끼울까요? (지금 모습은 백업됩니다)' : '정말 적용할까요?';
  }, thApply);
  function thApply(){
    var d = TH.data;
    if (!d) return;
    $('wgThGo').disabled = true;
    callForm('theme_apply', { md: TH.text, mode: d.group.mode, name: TH.name, sig: d.sig }).then(function(r){
      toast('「' + d.group.name + '」 ' + ({ 'new': '만들었습니다', append: '덧붙였습니다', replace: '갈아끼웠습니다' }[r.mode] || '적용했습니다')
            + ' — 구분행 ' + r.seps + ' · 종목 ' + r.added + (r.kept ? ' · 그대로 ' + r.kept : '') + (r.filled ? ' · 기준가 채움 ' + r.filled : '')
            + (r.deleted ? ' · 지움 ' + r.deleted : ''));
      $('wgMgTx').value = ''; $('wgMgPr').innerHTML = ''; thOff(); M.fname = '';
      S.groups = r.groups; S.gid = r.group_id;
      mgClose();                                   // 닫으면서 그 그룹을 연다(테마 노트가 선다)
    }).catch(function(e){
      fail(e);
      if (TH.text) thPreview(TH.text);             // 미리보기 뒤에 바뀌었으면 — 지금 모습으로 다시 보여 준다
    });
  }

  // ── md 파일 올리기 — 「📄 md 파일」 버튼 또는 창에 끌어다 놓기(리서치 화면과 같은 방식) ──
  /* 읽은 글은 붙여넣기 칸에 넣고 바로 미리보기를 돌린다 — 그 뒤는 붙여넣은 것과 똑같은 길이다(서버에는 파일 조각으로 간다).
     칸에 넣는 이유: 무엇을 읽었는지 눈으로 보이고, 고치고 싶으면 그 자리에서 고쳐 다시 미리보기를 누르면 된다. */
  function mgFile(f){
    if (!f) return;
    if (/\.pdf$/i.test(f.name) || f.type === 'application/pdf') { toast('PDF 는 받지 않습니다 — Markdown(.md) 파일을 올려 주세요.'); return; }
    if (f.size > 1048576) { toast('파일이 너무 큽니다(1MB 까지).'); return; }
    var r = new FileReader();
    r.onload = function(){
      $('wgMgTx').value = String(r.result || '').replace(/^﻿/, '');
      M.fname = f.name;
      mgPreview();
    };
    r.onerror = function(){ toast('파일을 읽지 못했습니다.'); };
    r.readAsText(f, 'utf-8');
  }
  $('wgMgFileBtn').onclick = function(){ $('wgMgFile').click(); };
  $('wgMgFile').addEventListener('change', function(){ if (this.files[0]) mgFile(this.files[0]); this.value = ''; });
  // 끌어다 놓기 — 창(어두운 바탕 포함) 어디에 놓아도 받는다. 놓친 파일을 브라우저가 열어 버리지 않게 기본 동작을 막는다
  function hasFiles(e){ return !!(e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0); }
  ['dragenter', 'dragover'].forEach(function(ev){
    $('wgMg').addEventListener(ev, function(e){ if (!hasFiles(e)) return; e.preventDefault(); $('wgMgTx').classList.add('over'); });
  });
  $('wgMg').addEventListener('dragleave', function(e){ if (!e.relatedTarget || !this.contains(e.relatedTarget)) $('wgMgTx').classList.remove('over'); });
  $('wgMg').addEventListener('drop', function(e){
    if (!hasFiles(e)) return;
    e.preventDefault(); $('wgMgTx').classList.remove('over');
    if (e.dataTransfer.files && e.dataTransfer.files.length) mgFile(e.dataTransfer.files[0]);
  });
  $('wgMgTx').addEventListener('input', function(){ M.fname = ''; });     // 손으로 고치면 더는 «그 파일»이 아니다

  $('wgMgBtn').onclick = mgOpen;
  $('wgMgX').onclick = mgClose;
  $('wgMg').addEventListener('mousedown', function(e){ if (e.target === this) mgClose(); });

  // ══ 단축키 ════════════════════════════════════════════════════════
  var delArm = 0;
  document.addEventListener('keydown', function(e){
    // Alt+1~9 — 그 번째 그룹으로 (입력칸 안에서도 먹는다)
    if (e.altKey && !e.ctrlKey && !e.metaKey && /^[1-9]$/.test(e.key) && $('wgMg').hidden && $('wgMemo').hidden && $('wgVar').hidden) {
      var g = userGroups()[+e.key - 1];          // 번호는 내가 만든 그룹의 것이다(고정 그룹은 ★)
      if (g) { e.preventDefault(); openGroup(g.id); }
      return;
    }
    if (e.key === 'Escape') {
      if (!$('wgMenu').hidden) { closeMenu(); return; }
      if (!$('wgMemo').hidden) { closeMemo(); return; }
      if (!$('wgVar').hidden)  { vmClose(); return; }
      if (!$('wgMg').hidden)   { mgClose(); return; }
    }
    var t = e.target && e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || e.altKey || e.ctrlKey || e.metaKey) return;
    if (!$('wgMg').hidden || !$('wgMemo').hidden || !$('wgVar').hidden) return;
    if (e.key === '/') { e.preventDefault(); $('wgQ').focus(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      var rows = Array.prototype.slice.call($('wgRows').querySelectorAll('.wg-r:not(.hid):not(.sep)'));
      if (!rows.length) return;
      e.preventDefault();
      var i = -1;
      rows.forEach(function(el, k){ if (+el.dataset.id === S.sel) i = k; });
      i = Math.max(0, Math.min(rows.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)));
      selectRow(+rows[i].dataset.id, true, true);
      return;
    }
    if (e.key === 'Delete' && S.sel) {
      if (isFixed()) { toast(FIXED_MSG); return; }
      /* 실수로 지워지지 않게 두 번 — 처음엔 알려 주기만 하고, 2.5초 안에 다시 누르면 지운다 */
      if (delArm === S.sel) { delArm = 0; delItems([S.sel]); }
      else { delArm = S.sel; toast('Del 을 한 번 더 누르면 그룹에서 삭제합니다.'); setTimeout(function(){ delArm = 0; }, 2500); }
    }
  });

  // ══ 시세 갱신 ═════════════════════════════════════════════════════
  /* 장중에만 · 탭이 보일 때만. 장 밖에서는 페이지를 열 때 받은 한 번이 전부다(값이 안 변한다).
     실패하면 직전 값을 그대로 두고 시각 옆에 ⚠ 를 단다. */
  function tick(){
    if (document.hidden || !S.gid || !isOpen()) return;
    var gid = S.gid;
    call('quote_batch', { group_id: gid }).then(function(d){
      if (gid !== S.gid) return;                 // 그 사이 다른 그룹으로 넘어갔다
      var q = d.quotes || {};
      S.items.forEach(function(it){
        var x = q[it.code];
        if (isStock(it) && x) { it.price = x.price; it.rate = x.rate; it.diff = x.diff; it.amt = x.amt; }
      });
      stamp(d.ts, true);
      paintQuotes();
      paintGroupStats();                         // 보고 있는 그룹의 평균 등락도 같은 값으로
    }).catch(function(){ stamp(null, false); });
  }
  /* 그룹 목록의 평균 등락 — WG_STAT 마다 다시 읽는다(장중 · 탭이 보일 때만). 서버는 고정 그룹 셋의 시세만 낡았을 때 다시 받고
     (키움 한 콜 · 단타 화면이 이미 받고 있으면 0콜) 내 그룹은 DB 에 있는 값 그대로 평균 낸다. */
  function statTick(){
    if (document.hidden || !isOpen()) return;
    call('group_stats').then(function(d){
      var same = d.groups.length === S.groups.length && d.groups.every(function(g, i){
        var o = S.groups[i]; return g.id === o.id && g.n === o.n && g.name === o.name;
      });
      S.groups = d.groups;
      if (same) paintGroupStats(); else renderGroups();
    }).catch(function(){});                      // 실패하면 직전 숫자를 그대로 둔다
  }
  var lastSig = '';
  function paintQuotes(){
    /* 보기 정렬(상승률순·기준대비순)에서는 순서가 바뀌었을 때만 줄을 다시 세운다 —
       입력·메뉴가 열려 있으면 건드리지 않는다(쓰던 칸이 사라진다) */
    var sig = orderSig();
    if (S.sort !== 'mine' && sig !== lastSig && !S.edit && $('wgMenu').hidden) {
      lastSig = sig;
      renderList(); layoutCharts();
      return;
    }
    S.items.forEach(function(it){
      if (!isStock(it)) return;
      var el = rowEl(it.id), c = byCode[it.code];
      if (el) fillQuote(el, it);
      if (c) head(c, it);
    });
  }
  setInterval(tick, WG_TICK);
  setInterval(statTick, WG_STAT);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) { tick(); statTick(); } });
  statTick();                                    // 열자마자 한 번 — 처음 그린 값은 DB 에 있던 값이다

  // ══ 시작 ══════════════════════════════════════════════════════════
  pickOv();                                 // 처음 여는 그룹의 겹칠 변수(= 주 변수)
  setSort(sortFor(S.gid), false);           // 처음 여는 그룹의 정렬(서버가 준 순서 위에 화면이 세운다)
  renderGroups();
  renderList();
  renderNote();
  stamp(WG_TS, true);

  DailyChart.load().then(function(){
    var ref = F('overlay.intraday_ref'), dcs = [];
    /* 단타 일봉 패널·멀티차트 칸과 같은 create() 옵션 세트(구성 ④) — screen:'' 이라 높이는 기억하지 않는다
       (칸 높이는 열 수가 정한다). 차트틀·지표 키는 'short' 라 단타에서 저장한 차트가 그대로 실린다. */
    for (var i = 0; i < WG_POOL; i++) {
      var el = document.createElement('div');
      el.className = 'wg-cell'; el.hidden = true;
      el.innerHTML = '<div class="wg-ch"><span class="nm"></span><span class="cd"></span><span class="hb"></span>'
                   + '<span class="px"></span><span class="rt"></span><span class="bs"></span><span class="iv"></span><span class="st"></span></div>'
                   + '<div class="wg-host"></div>';
      grid.appendChild(el);
      var c = { el: el, code: '', id: 0, loaded: false, loading: false, vis: false, baseSig: null,
                dc: DailyChart.create(el.lastChild, { theme:'dark', key:'short', screen:'', resize:false,
                                                      todayHigh: ref, curPrice: ref ? '#d9a441' : null,
                                                      legend: null }) };
      el._c = c;
      // 차트 머리줄을 누르면 리스트의 그 행을 고른다(양방향 동기화)
      el.firstChild.onclick = (function(cc){ return function(){ if (cc.id) selectRow(cc.id, false, true); }; })(c);
      // 일봉↔주봉을 바꾸면 봉의 날짜가 달라진다 — 겹친 선도 그 날짜로 다시 싣는다
      if (c.dc.onTf) c.dc.onTf((function(cc){ return function(){ ovApply(cc); }; })(c));
      POOL.push(c); dcs.push(c.dc);
    }
    /* 테마 변수 칸 — 그룹당 WG_VMAX 개까지라 그만큼만 만들어 둔다(종목 칸과 같은 풀 방식 · 숨겨 두고 짝짓는다).
       변수는 종가뿐인 선이다(거래량 없음 · 지표·수평선 도구 없음). 기간 바에는 함께 묶는다 — 기간이 종목 차트와 같이 움직인다. */
    var vdcs = [];
    for (var j = 0; j < WG_VMAX; j++) {
      var ve = document.createElement('div');
      ve.className = 'wg-cell var'; ve.hidden = true;
      ve.innerHTML = '<div class="wg-ch" title="눌러서 이 변수를 종목 차트에 겹칩니다 (겹쳐 있으면 끕니다)"><span class="nm"></span><span class="cd"></span>'
                   + '<span class="ovm" hidden>겹침</span><span class="px"></span><span class="rt"></span><span class="vg"></span><span class="st"></span></div>'
                   + '<div class="wg-host"></div>';
      grid.appendChild(ve);
      var vc = { el: ve, vid: 0, sig: '',
                 dc: DailyChart.create(ve.lastChild, { theme:'dark', key:'', screen:'', resize:false, volume:false, draw:false, legend:null }) };
      ve.firstChild.onclick = (function(x){ return function(){
        if (!x.vid) return;
        if (ovOn && S.ov === x.vid) setOv(false); else { S.ov = x.vid; setOv(true); }
      }; })(vc);
      VPOOL.push(vc); vdcs.push(vc.dc);
    }
    // 기간 바 — 기억 키는 DC_SCREEN('wgroup') · 전체화면은 차트가 여럿이라 없다
    DailyChart.periodBar('wgPBar', dcs.concat(vdcs), {
      theme:'dark', defaultIndex:0, fullscreen:false,
      onChange: function(info){ $('wgNote').textContent = (info.tf === 'week' ? '주봉' : '일봉') + ' · ' + info.label; }
    });
    DailyChart.indicatorBar('wgIBar', dcs, { theme:'dark', key:'short', preset: F('preset.select') });
    chip('wgTH', function(on){ dcs.forEach(function(dc){ dc.setTodayHigh(on); }); });
    chip('wgCP', function(on){ dcs.forEach(function(dc){ dc.setCurPrice(on); }); });

    /* 보이는 칸만 받는다 — 화면 아래로 한 화면만큼 미리 본다 */
    if (window.IntersectionObserver) {
      io = new IntersectionObserver(function(es){
        es.forEach(function(e){ if (e.target._c) e.target._c.vis = e.isIntersecting; });
        pump();
      }, { root: $('wgScroll'), rootMargin: '0px 0px 100% 0px' });
      POOL.forEach(function(x){ io.observe(x.el); });
    } else {
      POOL.forEach(function(x){ x.vis = true; });
    }
    /* 차트 모듈을 받는 사이에 종목이 풀보다 늘었으면(그 사이 추가·그룹 전환) 주소로 다시 연다 */
    if (S.items.filter(isStock).length > POOL.length) { location.href = BASE + '&g=' + gKey(S.gid); return; }
    layoutCharts();
    loadVars();                               // 테마 그룹이면 변수 값을 받아 변수 칸·겹친 선을 채운다
  });
})();
</script>
JS;
    echo '</body></html>';
}
?>
