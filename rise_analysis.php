<?php
// rise_analysis.php — 상승확률 분석 (가격·거래량 신호 엔진 UI)
//   mode=run     : 장 종료 후 60종목 분석 실행(신호 저장 + 과거 예측 채점) — RiseAnalyzer
//   (default)    : 리포트 — 오늘 신호 종목 확률표 + 누적 적중률(칼리브레이션) + 최근 채점결과
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();
require_once "./env/nav.inc";
$current_user = $_SESSION['usr_name'] ?? '';

$mode = $_REQUEST['mode'] ?? '';
$ra = new RiseAnalyzer($pdo);

if ($mode === 'run')    { rise_run($ra); }
elseif ($mode === 'bt') { rise_backtest($ra); }
else                    { rise_report($ra); }

// ==========================================================
function rise_run(RiseAnalyzer $ra): void {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html lang='ko'><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    echo "<style>body{font-family:'Malgun Gothic',sans-serif;background:#0e1320;color:#dfe6f2;padding:18px;font-size:14px;line-height:1.7}"
       . "a{color:#d9a441}.box{background:#141b2b;border:1px solid #26304a;border-radius:8px;padding:16px;margin-top:14px}"
       . ".big{font-size:20px;font-weight:800;color:#e8493f}</style></head><body>";
    echo "<h2>📊 상승확률 분석 실행</h2><div style='color:#8893ab'>전종목 스캔(종가3%↑ 또는 고가7%↑) → 후보 일봉 수집 → 신호 추출 → 과거 예측 채점 (약 2분 소요)</div><hr style='border-color:#26304a'>";
    @ob_flush(); @flush();

    $t0 = microtime(true);
    $res = $ra->runDaily(true);
    $sec = round(microtime(true) - $t0, 1);

    if (!empty($res['skipped'])) {
        echo "<div class='box'>";
        echo "<div class='big' style='color:#d9a441'>스킵됨 ({$sec}초)</div>";
        echo "<p>분석일 <b>{$res['date']}</b> — {$res['reason']}</p>";
        echo "<p style='color:#8893ab'>휴장일이거나 네이버가 아직 오늘 일봉을 게시하지 않았습니다(장 마감 직후일 수 있음). 평일 저녁에 다시 시도하세요. 기존 데이터는 그대로입니다.</p>";
        echo "<p><a href='rise_analysis.php'>▶ 리포트 보기</a></p>";
        echo "</div></body></html>";
        return;
    }

    echo "<div class='box'>";
    echo "<div class='big'>완료 ({$sec}초)</div>";
    echo "<p>분석일: <b>{$res['date']}</b><br>";
    echo "전종목 스캔: {$res['scanned']} &nbsp;|&nbsp; 후보: {$res['universe']} &nbsp;|&nbsp; 신호종목: {$res['breakouts']} &nbsp;|&nbsp; <b style='color:#e8493f'>대량동반 핵심: {$res['signals']}</b><br>";
    echo "이번에 채점된 과거 예측: {$res['graded']}건 &nbsp;|&nbsp; 재테스트(지지/저항) 채점: {$res['retested']}건</p>";
    echo "<p><a href='rise_analysis.php'>▶ 리포트 보기</a></p>";
    echo "</div></body></html>";
}

// ==========================================================
function rise_report(RiseAnalyzer $ra): void {
    $ra->ensureTables();
    $date  = $ra->getLatestSignalDate();
    $picks = $date ? $ra->getPicks($date) : [];
    $calib = $ra->getCalibration();
    $graded = $ra->getRecentGraded(30);
    $runs  = $ra->getRecentRuns(8);
    $retestCal = $ra->getRetestCalibration();
    $retested  = $ra->getRecentRetested(30);
    $rw        = RiseAnalyzer::RETEST_WINDOW;

    $pct  = fn($v) => $v === null ? '-' : number_format($v * 100, 1) . '%';
    $sg   = fn($v) => $v === null ? '-' : ($v > 0 ? '+' : '') . number_format($v, 1) . '%';
    $h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $ckfmt = function($ck) {                       // 'core:2-3' → '핵심 · 2-3배'
        $p = array_pad(explode(':', (string)$ck), 2, '');
        $t = $p[0] === 'core' ? '핵심' : ($p[0] === 'intra' ? '장중' : $p[0]);
        return $t . ' · ' . $p[1] . '배';
    };
    $rrLabel = function($r) {                       // 재테스트 결과 → [라벨, css클래스]
        switch ($r) {
            case 'runaway':    return ['이탈전진', 'up'];   // 안 돌아오고 상승지속
            case 'support':    return ['지지성공', 'up'];   // 되돌아왔다 선 위 회복
            case 'resistance': return ['저항전환', 'dn'];   // 선 아래로 실패
            default:           return ['미해결', 'muted'];
        }
    };
    // 클릭 가능한 종목명 셀 — 우측 그래프 도크(gOpen) 트리거. rate/price는 있을 때만 헤더에 표시(PC 전용, JS에서 가드)
    $gcell = function($name, $code, $rate = null, $price = null) use ($h) {
        $a = "data-code=\"{$h($code)}\" data-name=\"{$h($name)}\"";
        if ($rate  !== null && $rate  !== '') $a .= " data-rate=\"{$h($rate)}\"";
        if ($price !== null && $price !== '') $a .= " data-price=\"{$h($price)}\"";
        return "<td class='l nm gopen' {$a}>{$h($name)} <span class='muted'>{$h($code)}</span></td>";
    };

    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html lang='ko'><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    echo "<title>상승확률 분석</title>";
    echo "<script src='https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js'></script>";
    nav_css();
    echo "<style>
      :root{--bg:#0e1320;--panel:#141b2b;--panel-2:#1b2335;--line:#26304a;--ink:#dfe6f2;--ink-dim:#8893ab;--ink-mute:#5b6884;--up:#e8493f;--down:#2f7bd6;--accent:#d9a441}
      body{margin:0;font-family:Pretendard,-apple-system,BlinkMacSystemFont,'Malgun Gothic',sans-serif;background:var(--bg);color:var(--ink);font-size:14px}
      a{color:var(--accent)}
      .wrap{max-width:1280px;margin:0 auto;padding:18px}
      h2{margin:6px 0 2px} .sub{color:var(--ink-mute);font-size:13px;margin-bottom:14px}
      .runbtn{display:inline-block;background:var(--up);color:#fff;font-weight:700;padding:11px 22px;border-radius:8px;text-decoration:none;font-size:15px;box-shadow:0 0 12px rgba(232,73,63,.35)}
      .runbtn:hover{background:#c93b32}
      .card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:16px 0}
      .card h3{margin:0 0 10px;font-size:15px;color:var(--ink)}
      table{width:100%;border-collapse:collapse;font-size:13px}
      th,td{padding:8px 9px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}
      th{background:var(--panel-2);color:var(--ink-dim);font-weight:600;position:sticky;top:0}
      td.l,th.l{text-align:left}
      tr:hover td{background:rgba(38,48,74,.25)}
      .nm{font-weight:700;color:var(--ink)}
      .sig td{background:#1d2741}
      .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11.5px;font-weight:700}
      .b-sig{background:#3a1f1c;color:#ff8a7e} .b-vol{background:#3a3115;color:#f0c040}
      .up{color:var(--up);font-weight:700} .dn{color:var(--down);font-weight:700}
      .o{color:#34d058;font-weight:800} .x{color:var(--ink-mute)}
      .muted{color:var(--ink-mute)}
      .scroll{overflow-x:auto}
      /* ── PC 2단: 좌 분석표 / 우 대형 그래프 (stock_analysis.php?mode=updash 차트 이식) ── */
      td.gopen{cursor:pointer} td.gopen:hover{color:var(--accent)}
      @media(min-width:901px){
        .wrap{max-width:none;display:flex;gap:16px;align-items:flex-start;padding:14px 16px}
        .rleft{flex:1 1 40%;min-width:0}                      /* 좌: 분석표 (세로 스크롤) */
        #gdock{flex:1 1 60%;position:sticky;top:12px;height:calc(100vh - 24px);   /* 우: 최대한 큰 그래프 */
          display:flex;flex-direction:column;background:var(--panel);border:1px solid var(--line);
          border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.25)}
      }
      @media(max-width:900px){#gdock{display:none} td.gopen{cursor:default} td.gopen:hover{color:inherit}}
      #gdock .gsel{display:flex;align-items:baseline;gap:12px;padding:10px 14px;background:var(--panel-2);border-bottom:1px solid var(--line);flex:0 0 auto}
      #gdock .gsel .snm{font-size:16px;font-weight:800;letter-spacing:-.02em;color:var(--ink)}
      #gdock .gsel .scode{font-size:11px;color:var(--ink-mute)}
      #gdock .gsel .sprice{font-size:16px;font-weight:700;margin-left:auto;font-variant-numeric:tabular-nums}
      #gdock .gsel .schg{font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums}
      .gcharts{flex:1;display:flex;flex-direction:column;min-height:0;position:relative}
      .gblock{flex:1 1 64%;min-height:0;display:flex;flex-direction:column}       /* 일봉 크게 */
      .gblock.bottom{flex:1 1 36%;border-top:1px solid var(--line)}               /* 분봉 */
      .gbar{flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:6px 12px;background:var(--panel);flex-wrap:wrap}
      .gbar .lbl{font-size:11.5px;font-weight:700;color:var(--ink)}
      .gbar .px{font-size:11px;color:var(--ink-dim)}
      .cline-chip{background:var(--panel-2);color:var(--ink-mute);border:1px solid var(--line);border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;cursor:pointer}
      .cline-chip:hover{color:var(--ink)} .cline-chip.on{background:var(--accent);color:#0b1020;border-color:var(--accent)}
      .gseg{display:inline-flex;border:1px solid var(--line);border-radius:7px;overflow:hidden}
      .gseg button{background:var(--panel-2);color:var(--ink-mute);border:0;font-size:11px;font-weight:700;padding:2px 9px;cursor:pointer}
      .gseg button + button{border-left:1px solid var(--line)} .gseg button.on{background:var(--accent);color:#0b1020}
      .ghost{flex:1;min-height:0;position:relative}
      .gph-empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;color:var(--ink-mute);font-size:14px;background:var(--panel);z-index:6}
      .chip-layer{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:3}
      .dchip{position:absolute;background:#fff;border-radius:7px;padding:2px 7px;font-size:10px;font-weight:800;line-height:1.2;text-align:center;box-shadow:0 1px 5px rgba(0,0,0,.45);white-space:nowrap}
      .dchip .r{display:block;color:#c0241a} .dchip .w{display:block;color:#111;font-variant-numeric:tabular-nums}
      .dchip .w.real{color:#0a7d32} .dchip .w.est{color:#666;font-style:italic}
      .dchip.gold{box-shadow:0 1px 6px rgba(184,134,11,.6)} .dchip.gold .r{color:#b8860b}
    </style></head><body>";
    render_nav('riseanalysis');
    echo "<div class='wrap'>";
    echo "<div class='rleft'>";   // 좌: 분석표 (PC). 모바일은 flex 해제되어 단일 컬럼

    echo "<h2>상승확률 분석 <span class='muted' style='font-size:14px'>· 가격·거래량 신호</span></h2>";
    echo "<div class='sub'>후보=종가 3%↑ 또는 고가 전일대비 7%↑ (적응형, 상한 200). 핵심=종가 60일신고가돌파+거래량2배 · 장중=고가만 돌파/강세+거래량2배. 예측=in-sample, 실제=3거래일 채점 누적. <span style='color:var(--accent)'>· 종목명 클릭 시 우측 그래프(PC)</span></div>";
    echo "<a class='runbtn' href='rise_analysis.php?mode=run'>＋ 장 종료 후 분석 시작</a>";
    echo " <a class='runbtn' style='background:#2f7bd6;box-shadow:0 0 12px rgba(47,123,214,.35)' href='rise_analysis.php?mode=bt'>📉 매매 백테스트</a>";

    // ── 누적 적중률(칼리브레이션) ──
    echo "<div class='card'><h3>📈 누적 실제 적중률 (거래량 등급별 · out-of-sample)</h3>";
    if (!$calib) {
        echo "<div class='muted'>아직 채점된 데이터가 없습니다. 신호일로부터 3거래일이 지나면 자동 채점됩니다.</div>";
    } else {
        echo "<div class='scroll'><table><tr><th class='l'>신호·등급</th><th>표본</th><th>3일내 +3%터치</th><th>95% CI</th><th>3일내 종가상승</th><th>평균 최대상승</th><th>평균 최대하락</th></tr>";
        foreach ($calib as $c) {
            echo "<tr><td class='l nm'>{$ckfmt($c['cell_key'])}</td><td>{$c['n_samples']}</td>"
               . "<td class='up'>{$pct($c['hit3_rate'])}</td>"
               . "<td class='muted'>{$pct($c['ci_low'])}~{$pct($c['ci_high'])}</td>"
               . "<td>{$pct($c['up3_rate'])}</td>"
               . "<td class='up'>{$sg($c['avg_mfe'])}</td>"
               . "<td class='dn'>{$sg($c['avg_mae'])}</td></tr>";
        }
        echo "</table></div>";
    }
    echo "</div>";

    // ── 재테스트(지지/저항) 칼리브레이션 ──
    echo "<div class='card'><h3>🧲 돌파선 재테스트 결과 <span class='muted' style='font-size:13px'>· 지지·저항 전환 · {$rw}거래일 추적 (out-of-sample)</span></h3>";
    if (!$retestCal) {
        echo "<div class='muted'>아직 재테스트 채점 데이터가 없습니다. 신호일로부터 약 {$rw}거래일이 지나면 자동 채점됩니다.</div>";
    } else {
        $basisLabel = fn($b) => $b === 'H' ? '전고선' : '종가선';
        echo "<div class='scroll'><table><tr><th class='l'>기준선</th><th class='l'>신호</th><th class='l'>재테스트 결과</th><th>표본</th><th>평균 도달일</th><th>재테스트 후 수익</th><th>평균 최대상승({$rw}일)</th><th>평균 최대하락({$rw}일)</th></tr>";
        $prevBasis = null;
        foreach ($retestCal as $c) {
            [$lab, $lc] = $rrLabel($c['result']);
            $tname = $c['signal_type'] === 'core' ? '핵심' : '장중';
            $bl = $c['basis'] === $prevBasis ? '' : $basisLabel($c['basis']);
            $sep = ($prevBasis !== null && $c['basis'] !== $prevBasis) ? " style='border-top:2px solid #26304a'" : "";
            $prevBasis = $c['basis'];
            echo "<tr{$sep}><td class='l nm'>{$bl}</td><td class='l'>{$tname}</td><td class='l {$lc}'>{$lab}</td><td>{$c['n']}</td>"
               . "<td class='muted'>" . ($c['avg_day'] !== null ? 'D+' . number_format($c['avg_day'], 1) : '-') . "</td>"
               . "<td class='{$lc}'>{$sg($c['avg_post'])}</td>"
               . "<td class='up'>{$sg($c['avg_mfe'])}</td>"
               . "<td class='dn'>{$sg($c['avg_mae'])}</td></tr>";
        }
        echo "</table></div>";
        echo "<div class='muted' style='margin-top:8px;font-size:12px'>※ <b>A/B 두 기준선</b> 비교: <b>전고선</b>=돌파당한 60일 신고가, <b>종가선</b>=신호일 종가(첨부에서 그은 선). 가격이 해당 선 ±1.5% 밴드로 되돌아오면 <b>재테스트</b>, 이후 종가가 선 아래 1%↓ 마감=<b style='color:#2f7bd6'>저항전환(실패)</b> · 버티면=<b style='color:#e8493f'>지지성공</b> · 안 돌아오고 상승지속=<b>이탈전진</b>. <b>재테스트 후 수익</b>=재테스트일 종가→{$rw}일말 수익률. <b>어느 선이 더 유효한가</b>=지지성공의 수익이 높고 저항전환이 음수로 갈라지는 쪽(손절 기준선으로 적합).</div>";
    }
    echo "</div>";

    // ── 오늘 신호 종목 ──
    echo "<div class='card'><h3>🎯 신호 종목" . ($date ? " <span class='muted'>({$h($date)})</span>" : "") . "</h3>";
    if (!$picks) {
        echo "<div class='muted'>표시할 신호가 없습니다. ‘분석 시작’을 눌러 실행하세요.</div>";
    } else {
        echo "<div class='scroll'><table>";
        echo "<tr><th>순위</th><th class='l'>종목</th><th>구분</th><th>등락률</th><th>고가율</th><th>종가</th><th>돌파</th><th>전고대비</th><th>거래량배수</th><th>대량</th><th>예측+3%</th><th>예측종가↑</th></tr>";
        foreach ($picks as $p) {
            $cls = $p['is_signal'] ? " class='sig'" : "";
            $brk = $p['brk_period'] ? "{$p['brk_period']}일" : "-";
            $volBadge = $p['vol_ratio60'] !== null ? number_format($p['vol_ratio60'], 1) . "x" : "-";
            $tb  = $p['signal_type'] === 'core' ? "<span class='badge b-sig'>핵심</span>" : "<span class='badge b-vol'>장중</span>";
            $rc  = $p['today_rate'] >= 0 ? 'up' : 'dn';
            $rt  = ($p['today_rate'] >= 0 ? '+' : '') . number_format($p['today_rate'], 2) . '%';
            $hrt = $p['high_rate'] !== null ? '+' . number_format($p['high_rate'], 1) . '%' : '-';
            echo "<tr{$cls}>";
            echo "<td>{$p['rank_no']}</td>";
            echo $gcell($p['stock_name'], $p['stock_code'], $p['today_rate'], $p['close_price']);
            echo "<td>{$tb}</td>";
            echo "<td class='{$rc}'>{$rt}</td>";
            echo "<td class='up'>{$hrt}</td>";
            echo "<td>" . number_format($p['close_price']) . "</td>";
            echo "<td>{$brk}</td>";
            echo "<td>{$sg($p['brk_mag'])}</td>";
            echo "<td><span class='badge b-vol'>{$volBadge}</span></td>";
            echo "<td>" . ($p['is_signal'] ? "<span class='o'>✓</span>" : "<span class='muted'>-</span>") . ($p['new_vol60'] ? " 🔺" : "") . "</td>";
            echo "<td class='up'>{$pct($p['pred_hit3'])}</td>";
            echo "<td>{$pct($p['pred_up3'])}</td>";
            echo "</tr>";
        }
        echo "</table></div>";
        echo "<div class='muted' style='margin-top:8px;font-size:12px'>※ 예측확률은 그날 60종목 160일 과거의 in-sample 기준치(거래량 등급별). 실제 신뢰도는 위 누적 적중률로 판단.</div>";
    }
    echo "</div>";

    // ── 최근 채점 결과 ──
    echo "<div class='card'><h3>✅ 최근 채점 결과 (예측 vs 실제)</h3>";
    if (!$graded) {
        echo "<div class='muted'>아직 채점된 종목이 없습니다.</div>";
    } else {
        echo "<div class='scroll'><table>";
        $ndtag = function ($o, $c) {                  // 다음날 시가·종가 4분면
            if ($o === null || $c === null) return ['-', 'muted'];
            $o = (float)$o; $c = (float)$c;
            if ($o >= 0 && $c > 0)  return ['추세지속', 'up'];     // 갭상승 후 상승마감
            if ($o < 0  && $c > 0)  return ['눌림반전', 'up'];     // 갭하락인데 상승마감
            if ($o >= 0 && $c <= 0) return ['갭상승실패', 'dn'];   // 떴다 밀림
            return ['약세지속', 'dn'];                            // 갭하락+하락마감
        };
        $brkTag = function ($g) {                       // 전고(돌파레벨) 종가회복 판정 — 1순위
            $path = json_decode($g['fwd_path'] ?? '', true);
            $level = (float)($g['prior_high'] ?? 0);
            if (!is_array($path) || !$path || $level <= 0) return ['-', 'muted'];
            $closes = array_map(fn($b) => (float)($b['c'] ?? 0), $path);
            if ($g['signal_type'] === 'core') return min($closes) >= $level ? ['전고유지', 'up'] : ['전고이탈', 'dn'];
            return max($closes) >= $level ? ['돌파완성', 'up'] : ['돌파미완', 'dn'];
        };
        echo "<tr><th class='l'>신호일</th><th class='l'>종목</th><th>구분</th><th>예측+3%</th><th>+3%터치</th><th>다음날 시가</th><th>다음날 종가</th><th>추세</th><th>돌파(전고)</th><th>3일 최대↑</th><th>3일 최대↓</th></tr>";
        foreach ($graded as $g) {
            $hit = $g['hit3_3pct'] ? "<span class='o'>O</span>" : "<span class='x'>X</span>";
            [$tag, $tcls] = $ndtag($g['nx_open'], $g['nx_close']);
            $noCls = ($g['nx_open']  !== null && (float)$g['nx_open']  >= 0) ? 'up' : 'dn';
            $ncCls = ($g['nx_close'] !== null && (float)$g['nx_close'] >= 0) ? 'up' : 'dn';
            echo "<tr>";
            echo "<td class='l'>{$h($g['signal_date'])}</td>";
            echo $gcell($g['stock_name'], $g['stock_code']);
            echo "<td>{$ckfmt($g['cell_key'])}</td>";
            echo "<td class='muted'>{$pct($g['pred_hit3'])}</td>";
            echo "<td>{$hit}</td>";
            echo "<td class='{$noCls}'>{$sg($g['nx_open'])}</td>";
            echo "<td class='{$ncCls}'>{$sg($g['nx_close'])}</td>";
            echo "<td class='{$tcls}'>{$tag}</td>";
            [$btag, $bcls] = $brkTag($g);
            echo "<td class='{$bcls}'>{$btag}</td>";
            echo "<td class='up'>{$sg($g['fwd_high3'])}</td>";
            echo "<td class='dn'>{$sg($g['fwd_low3'])}</td>";
            echo "</tr>";
        }
        echo "</table></div>";
        echo "<div class='muted' style='margin-top:8px;font-size:12px'>※ <b>돌파(전고)</b>=1순위 판정: 핵심신호는 3일내 종가가 돌파레벨(전고) 위 유지=전고유지 / 아래 마감=<b style='color:#2f7bd6'>전고이탈(실패)</b>, 장중신호는 돌파완성/미완. 추세=다음날 시가·종가 4분면(추세지속·눌림반전·갭상승실패·약세지속). <b>D+1~3 원본 OHLCV(fwd_path)</b>가 저장돼 추후 어떤 실패 정의로도 재분석 가능.</div>";
    }
    echo "</div>";

    // ── 최근 재테스트 상세 ──
    if ($retested) {
        echo "<div class='card'><h3>🔎 최근 재테스트 상세 <span class='muted' style='font-size:13px'>· 전고선 vs 종가선</span></h3><div class='scroll'><table>";
        echo "<tr><th class='l'>신호일</th><th class='l'>종목</th><th>구분</th>"
           . "<th>전고선</th><th>전고 결과</th><th>전고 후</th>"
           . "<th>종가선</th><th>종가 결과</th><th>종가 후</th>"
           . "<th>최대↑({$rw})</th><th>최대↓({$rw})</th></tr>";
        foreach ($retested as $g) {
            [$labH, $lcH] = $rrLabel($g['retest_result']);
            [$labC, $lcC] = $rrLabel($g['retest_result_c']);
            $tname = $g['signal_type'] === 'core' ? '핵심' : '장중';
            $dayH = $g['retest_day']   !== null ? ' <span class="muted">D+' . (int)$g['retest_day']   . '</span>' : '';
            $dayC = $g['retest_day_c'] !== null ? ' <span class="muted">D+' . (int)$g['retest_day_c'] . '</span>' : '';
            echo "<tr>";
            echo "<td class='l'>{$h($g['signal_date'])}</td>";
            echo $gcell($g['stock_name'], $g['stock_code']);
            echo "<td>{$tname}</td>";
            echo "<td>" . number_format((int)$g['prior_high']) . "</td>";
            echo "<td class='{$lcH}'>{$labH}{$dayH}</td>";
            echo "<td class='{$lcH}'>{$sg($g['fwd_ret_post'])}</td>";
            echo "<td>" . number_format((int)$g['close_price']) . "</td>";
            echo "<td class='{$lcC}'>{$labC}{$dayC}</td>";
            echo "<td class='{$lcC}'>{$sg($g['fwd_ret_post_c'])}</td>";
            echo "<td class='up'>{$sg($g['fwd_max25'])}</td>";
            echo "<td class='dn'>{$sg($g['fwd_min25'])}</td>";
            echo "</tr>";
        }
        echo "</table></div></div>";
    }

    // ── 실행 이력 ──
    if ($runs) {
        echo "<div class='card'><h3>🕑 실행 이력</h3><div class='scroll'><table>";
        echo "<tr><th class='l'>분석일</th><th>실행시각</th><th>분석종목</th><th>핵심신호</th><th>채점</th></tr>";
        foreach ($runs as $r) {
            echo "<tr><td class='l'>{$h($r['run_date'])}</td><td class='muted'>{$h($r['run_dt'])}</td>"
               . "<td>{$r['universe_n']}</td><td class='up'>{$r['signal_n']}</td><td>{$r['graded_n']}</td></tr>";
        }
        echo "</table></div></div>";
    }

    echo "</div>";   // .rleft 닫기

    // ── 우측 대형 그래프 패널 (PC 전용·상시표시) — stock_analysis.php?mode=updash 차트 이식 ──
    echo <<<'DOCK'
<div id="gdock">
  <div class="gsel">
    <span class="snm" id="gName">종목을 선택하세요</span>
    <span class="scode" id="gCode"></span>
    <span class="sprice" id="gPrice"></span>
    <span class="schg" id="gChg"></span>
  </div>
  <div class="gcharts">
    <div class="gph-empty" id="gEmpty">◀ 왼쪽 표에서 종목명을 클릭하면<br>여기에 일봉·분봉 그래프가 표시됩니다</div>
    <div class="gblock">
      <div class="gbar"><span class="lbl">일봉</span>
        <button type="button" id="gToggleHigh" class="cline-chip on" title="신호일 전고점(돌파레벨) 수평선">전고점선</button>
        <button type="button" id="gToggleTodayHigh" class="cline-chip on" title="당일 기준 전고점(직전 60일 최고가) 수평선">당일전고</button>
        <button type="button" id="gToggleCur" class="cline-chip" title="현재가격선">현재가</button>
        <span class="gseg"><button type="button" id="gDay160" class="on">160</button><button type="button" id="gDay240">240</button></span>
        <span class="px">흰칩 ▲N.Nx=신고가+대량 · 아래=승률(초록=실측) · 금색=5배+</span>
      </div>
      <div class="ghost" id="gDaily"></div>
    </div>
    <div class="gblock bottom">
      <div class="gbar"><span class="lbl">분봉</span><span class="px">당일 09:00~15:30 · 1분</span></div>
      <div class="ghost" id="gMinute"></div>
    </div>
  </div>
</div>
</div><!-- .wrap 닫기 -->
<script>
(function(){
  const API='stock_analysis_api.php';
  const LWC=window.LightweightCharts;
  if(!LWC) return;
  const css=n=>getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  const UP=css('--up'), DOWN=css('--down');
  const G=id=>document.getElementById(id);
  const isPC=()=>window.innerWidth>900;

  const fmtPrice=p=>Math.round(Number(p)||0).toLocaleString();
  const fmtRate=r=>{r=Number(r)||0;return (r>=0?'+':'')+r.toFixed(2)+'%';};
  function fmtKDate(t){ if(typeof t==='string'){const p=t.split('-');return `${+p[0]}년 ${+p[1]}월 ${+p[2]}일`;} if(t&&typeof t==='object'&&'year' in t)return `${t.year}년 ${t.month}월 ${t.day}일`; const d=new Date((Number(t)||0)*1000); return `${d.getUTCFullYear()}년 ${d.getUTCMonth()+1}월 ${d.getUTCDate()}일`; }
  function fmtKDateShort(t){ if(typeof t==='string'){const p=t.split('-');return `${+p[1]}/${+p[2]}`;} if(t&&typeof t==='object'&&'year' in t)return `${t.month}/${t.day}`; const d=new Date((Number(t)||0)*1000); return `${d.getUTCMonth()+1}/${d.getUTCDate()}`; }
  function fmtHM(t){ const d=new Date((Number(t)||0)*1000); return String(d.getUTCHours()).padStart(2,'0')+':'+String(d.getUTCMinutes()).padStart(2,'0'); }

  const chartOpts={ layout:{background:{color:'transparent'},textColor:css('--ink-dim'),fontFamily:'Pretendard'}, grid:{vertLines:{color:'rgba(38,48,74,.4)'},horzLines:{color:'rgba(38,48,74,.4)'}}, rightPriceScale:{borderColor:css('--line')}, timeScale:{borderColor:css('--line'),timeVisible:true,secondsVisible:false}, crosshair:{mode:LWC.CrosshairMode.Normal} };
  const candleOpts={upColor:UP,downColor:DOWN,borderUpColor:UP,borderDownColor:DOWN,wickUpColor:UP,wickDownColor:DOWN};
  function makeChart(hostId,kind){ const host=G(hostId); const opt={...chartOpts,width:host.clientWidth,height:host.clientHeight}; if(kind==='daily'){opt.localization={timeFormatter:fmtKDate};opt.timeScale={borderColor:css('--line'),timeVisible:false,secondsVisible:false,tickMarkFormatter:fmtKDateShort};}else{opt.localization={timeFormatter:fmtHM};opt.timeScale={borderColor:css('--line'),timeVisible:true,secondsVisible:false,tickMarkFormatter:fmtHM};} const chart=LWC.createChart(host,opt); const candle=chart.addCandlestickSeries(candleOpts); const vol=chart.addHistogramSeries({priceFormat:{type:'volume'},priceScaleId:'vol'}); chart.priceScale('vol').applyOptions({scaleMargins:{top:0.8,bottom:0}}); return {chart,candle,vol,host}; }
  function paint(c,data){ c.candle.setData(data.map(d=>({time:d.time,open:d.open,high:d.high,low:d.low,close:d.close}))); c.vol.setData(data.map(d=>({time:d.time,value:d.vol,color:(d.close>=d.open?UP:DOWN)+'66'}))); if(data.length) c.chart.timeScale().fitContent(); }

  // 칼리브레이션 실측 승률 (stock_analysis_api.php?action=calib)
  let CALIB={}; const CALIB_MIN_N=8;
  const volTier=r=>r>=5?'5+':r>=3?'3-5':r>=2?'2-3':r>=1?'1-2':'0-1';
  const brkWinStatic=r=>r>=5?90:r>=3?82:78;
  function winInfo(ratio){ const c=CALIB['core:'+volTier(ratio)]; if(c&&c.n>=CALIB_MIN_N)return {pct:Math.round(c.rate),real:true,n:c.n,lo:c.lo,hi:c.hi}; return {pct:brkWinStatic(ratio),real:false}; }
  async function loadCalib(){ try{ const m=await fetch(API+'?action=calib').then(r=>r.json()); if(m&&typeof m==='object'){CALIB=m; renderDailyChips();} }catch(e){} }

  function computeDailySignals(data){ const out=[];const n=data.length; for(let i=60;i<n;i++){ let ph=-Infinity,vs=0; for(let k=i-60;k<i;k++){if(data[k].high>ph)ph=data[k].high; vs+=data[k].vol;} if(!(data[i].close>ph))continue; const ratio=vs>0?data[i].vol/(vs/60):0; if(ratio<2)continue; out.push({time:data[i].time,low:data[i].low,close:data[i].close,ph,ratio}); } return out; }

  let daily=null,minute=null,ready=false,dailyChipLayer=null;
  let DAILY_FULL=[],DAILY_SIGNALS=[],DAILY_DAYS=160,DAILY_PRICELINES=[],SHOW_HIGH_LINES=true,TODAY_HIGH_LINE=null,SHOW_TODAY_HIGH=true;

  function renderDailyChips(){ if(!ready)return; dailyChipLayer.innerHTML=''; const ts=daily.chart.timeScale(); for(const s of DAILY_SIGNALS){ const x=ts.timeToCoordinate(s.time); if(x==null)continue; const y=daily.candle.priceToCoordinate(s.low); if(y==null)continue; const w=winInfo(s.ratio); const c=document.createElement('div'); c.className='dchip'+(s.ratio>=5?' gold':''); c.style.left=x+'px'; c.style.top=(y+8)+'px'; c.title=w.real?`실측 적중률 ${w.pct}% · 3일내 +3% 터치 (표본 ${w.n}건, 95%CI ${w.lo}~${w.hi}%)`:`추정 승률 ${w.pct}% · 실측 표본 부족`; c.innerHTML=`<span class="r">▲ ${s.ratio.toFixed(1)}x</span><span class="w ${w.real?'real':'est'}">${w.pct}%</span>`; dailyChipLayer.appendChild(c); } }
  function drawSignalPriceLines(){ for(const pl of DAILY_PRICELINES)daily.candle.removePriceLine(pl); DAILY_PRICELINES=[]; if(!SHOW_HIGH_LINES)return; for(const s of DAILY_SIGNALS){ if(s.ph==null)continue; const gold=s.ratio>=5; DAILY_PRICELINES.push(daily.candle.createPriceLine({price:s.ph,color:gold?'#b8860b':'rgba(255,255,255,.45)',lineWidth:1,lineStyle:LWC.LineStyle.Dashed,axisLabelVisible:false})); } }
  function todayPriorHigh(){ const n=DAILY_FULL.length; if(n<2)return null; const end=n-1; let ph=-Infinity; for(let k=Math.max(0,end-60);k<end;k++){if(DAILY_FULL[k].high>ph)ph=DAILY_FULL[k].high;} return ph>-Infinity?ph:null; }
  function drawTodayHighLine(){ if(TODAY_HIGH_LINE){daily.candle.removePriceLine(TODAY_HIGH_LINE);TODAY_HIGH_LINE=null;} if(!SHOW_TODAY_HIGH)return; const ph=todayPriorHigh(); if(ph==null)return; TODAY_HIGH_LINE=daily.candle.createPriceLine({price:ph,color:'#22d3ee',lineWidth:1,lineStyle:LWC.LineStyle.Solid,axisLabelVisible:false}); }
  function repaintDaily(){ const view=DAILY_DAYS===160?DAILY_FULL.slice(-160):DAILY_FULL; paint(daily,view); drawSignalPriceLines(); drawTodayHighLine(); setTimeout(renderDailyChips,0); }
  function applyDaily(d){ DAILY_FULL=(Array.isArray(d)?d:[]).map(x=>({time:x.t,open:x.o,high:x.h,low:x.l,close:x.c,vol:x.v})); DAILY_SIGNALS=computeDailySignals(DAILY_FULL); repaintDaily(); }
  function setDailyDays(n){ if(DAILY_DAYS===n)return; DAILY_DAYS=n; G('gDay160').classList.toggle('on',n===160); G('gDay240').classList.toggle('on',n===240); repaintDaily(); }
  function resizeAll(){ if(!ready)return; for(const c of [daily,minute])c.chart.resize(c.host.clientWidth,c.host.clientHeight); renderDailyChips(); }

  function initCharts(){ if(ready)return;
    daily=makeChart('gDaily','daily'); minute=makeChart('gMinute','minute');
    daily.candle.applyOptions({priceLineVisible:false,priceLineStyle:LWC.LineStyle.Solid,priceLineColor:'#d9a441',priceLineWidth:1});
    dailyChipLayer=document.createElement('div'); dailyChipLayer.className='chip-layer'; G('gDaily').appendChild(dailyChipLayer);
    daily.chart.timeScale().subscribeVisibleLogicalRangeChange(renderDailyChips);
    ready=true;
    G('gToggleHigh').onclick=function(){SHOW_HIGH_LINES=!SHOW_HIGH_LINES;this.classList.toggle('on',SHOW_HIGH_LINES);drawSignalPriceLines();};
    G('gToggleTodayHigh').onclick=function(){SHOW_TODAY_HIGH=!SHOW_TODAY_HIGH;this.classList.toggle('on',SHOW_TODAY_HIGH);drawTodayHighLine();};
    G('gToggleCur').onclick=function(){const on=!this.classList.contains('on');this.classList.toggle('on',on);daily.candle.applyOptions({priceLineVisible:on});};
    G('gDay160').onclick=()=>setDailyDays(160); G('gDay240').onclick=()=>setDailyDays(240);
    new ResizeObserver(resizeAll).observe(G('gDaily')); window.addEventListener('resize',resizeAll);
  }
  async function gOpen(code,name,rate,price){
    if(!isPC()||!code)return;
    initCharts();
    const emp=G('gEmpty'); if(emp) emp.style.display='none';
    G('gName').textContent=name||code; G('gCode').textContent=code||'';
    const hasR=rate!==''&&rate!=null&&!isNaN(Number(rate));
    const up=hasR?Number(rate)>=0:true;
    G('gPrice').textContent=(price!==''&&price!=null&&!isNaN(Number(price)))?fmtPrice(price):'';
    const chg=G('gChg'); chg.textContent=hasR?fmtRate(rate):''; chg.className='schg '+(up?'up':'dn');
    const [d,m]=await Promise.all([
      fetch(`${API}?action=daily&code=${encodeURIComponent(code)}&days=240`).then(r=>r.json()).catch(()=>[]),
      fetch(`${API}?action=minute&code=${encodeURIComponent(code)}`).then(r=>r.json()).catch(()=>[])
    ]);
    applyDaily(d);
    paint(minute,(Array.isArray(m)?m:[]).map(x=>({time:Math.floor(Date.parse(x.t.replace(' ','T')+'Z')/1000),open:x.o,high:x.h,low:x.l,close:x.c,vol:x.v})));
  }

  document.addEventListener('click',e=>{ const cell=e.target.closest('.gopen'); if(!cell||!isPC())return; gOpen(cell.dataset.code||'',cell.dataset.name||'',cell.dataset.rate??'',cell.dataset.price??''); });
  // PC 상시 표시 패널: 로드 시 빈 차트를 미리 생성해 전체 크기로 렌더(클릭 전에도 축/그리드 표시)
  setTimeout(()=>{ if(isPC()){ initCharts(); resizeAll(); } }, 0);
  loadCalib();
})();
</script>
</body></html>
DOCK;
}

// ==========================================================
function rise_backtest(RiseAnalyzer $ra): void {
    $ra->ensureTables();
    $band = isset($_REQUEST['band']) ? max(0.1, min(10, (float)$_REQUEST['band'])) : 1.5;   // %
    $stop = isset($_REQUEST['stop']) ? max(0.1, min(15, (float)$_REQUEST['stop'])) : 1.5;   // %
    $bH = $ra->backtestRetest('H', $band / 100, $stop / 100);
    $bC = $ra->backtestRetest('C', $band / 100, $stop / 100);
    $rw = RiseAnalyzer::RETEST_WINDOW;
    $bands = [0.5, 1.0, 1.5, 2.0, 3.0];
    $stops = [1.0, 1.5, 2.0, 3.0, 5.0];
    $swH = $ra->sweepRetest('H', $bands, $stops);
    $swC = $ra->sweepRetest('C', $bands, $stops);

    $pct = fn($v) => $v === null ? '-' : number_format($v * 100, 1) . '%';
    $sg  = fn($v) => $v === null ? '-' : ($v > 0 ? '+' : '') . number_format($v, 2) . '%';
    $h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // 수익곡선 인라인 SVG (의존성 없음)
    $eqsvg = function(array $curve, string $color) {
        if (count($curve) < 2) return "<div class='muted' style='padding:30px 0;text-align:center'>거래 데이터 부족</div>";
        $w = 560; $hh = 130; $pad = 8;
        $min = min($curve); $max = max($curve); $rng = ($max - $min) ?: 1;
        $n = count($curve); $pts = [];
        foreach ($curve as $i => $v) {
            $x = $pad + $i * ($w - 2 * $pad) / ($n - 1);
            $y = $hh - $pad - ($v - $min) / $rng * ($hh - 2 * $pad);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $by = $hh - $pad - (1 - $min) / $rng * ($hh - 2 * $pad);                  // equity=1 기준선
        $by = max($pad, min($hh - $pad, $by));
        return "<svg width='100%' viewBox='0 0 {$w} {$hh}' preserveAspectRatio='none' style='background:#0e1320;border:1px solid #26304a;border-radius:6px'>"
             . "<line x1='{$pad}' y1='" . round($by, 1) . "' x2='" . ($w - $pad) . "' y2='" . round($by, 1) . "' stroke='#39425c' stroke-dasharray='3 3'/>"
             . "<polyline fill='none' stroke='{$color}' stroke-width='2' points='" . implode(' ', $pts) . "'/></svg>";
    };

    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html lang='ko'><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'><title>재테스트 매매 백테스트</title>";
    nav_css();
    echo "<style>
      :root{--bg:#0e1320;--panel:#141b2b;--panel-2:#1b2335;--line:#26304a;--ink:#dfe6f2;--ink-dim:#8893ab;--ink-mute:#5b6884;--up:#e8493f;--down:#2f7bd6;--accent:#d9a441}
      body{margin:0;font-family:Pretendard,-apple-system,'Malgun Gothic',sans-serif;background:var(--bg);color:var(--ink);font-size:14px}
      a{color:var(--accent)} .wrap{max-width:1280px;margin:0 auto;padding:18px}
      h2{margin:6px 0 2px} .sub{color:var(--ink-mute);font-size:13px;margin-bottom:14px}
      .card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:16px 0}
      .card h3{margin:0 0 10px;font-size:15px}
      .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px} @media(max-width:860px){.grid{grid-template-columns:1fr}}
      table{width:100%;border-collapse:collapse;font-size:13px}
      th,td{padding:7px 9px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}
      th{background:var(--panel-2);color:var(--ink-dim);font-weight:600} td.l,th.l{text-align:left}
      .up{color:var(--up);font-weight:700} .dn{color:var(--down);font-weight:700} .muted{color:var(--ink-mute)}
      .big{font-size:26px;font-weight:800} .kpi{display:flex;gap:18px;flex-wrap:wrap;margin:6px 0 12px}
      .kpi div{min-width:84px} .kpi b{display:block;font-size:11px;color:var(--ink-mute);font-weight:600}
      .scroll{overflow-x:auto} form.bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0}
      .heat td{text-align:center;min-width:66px;line-height:1.25} .heat th{text-align:center}
      input[type=number]{width:66px;background:#0e1320;border:1px solid var(--line);color:var(--ink);border-radius:6px;padding:6px}
      .gobtn{background:var(--accent);color:#1a1206;border:none;font-weight:700;padding:7px 16px;border-radius:7px;cursor:pointer}
    </style></head><body>";
    render_nav('riseanalysis');
    echo "<div class='wrap'>";
    echo "<h2>재테스트 매매 백테스트 <span class='muted' style='font-size:14px'>· 지지 진입 + 선 아래 손절</span></h2>";
    echo "<div class='sub'>규칙: 신호 후 가격이 기준선 ±{$band}% 밴드로 되돌아온 첫 봉에서 <b>그 봉 종가가 선 위면 종가 매수</b>(지지 확인) → 손절 <b>선 -{$stop}%</b>, 청산은 손절 또는 추적창({$rw}일) 끝 종가. 같은 규칙을 <b>전고선·종가선</b> 두 기준으로 각각 돌려 비교.</div>";

    echo "<form class='bar' method='get'><input type='hidden' name='mode' value='bt'>";
    echo "재테스트 밴드 <input type='number' step='0.1' name='band' value='{$band}'>% &nbsp; 손절 <input type='number' step='0.1' name='stop' value='{$stop}'>% ";
    echo "<button class='gobtn'>다시 계산</button> <a href='rise_analysis.php' style='margin-left:8px'>← 리포트로</a></form>";

    $renderCard = function(array $bt, string $title, string $color) use ($pct, $sg, $h, $eqsvg) {
        $s = $bt['summary'];
        echo "<div class='card'><h3>{$title} <span class='muted' style='font-size:12px'>· 후보 {$bt['candidates']}건 중 진입 {$s['n']}건</span></h3>";
        if (($s['n'] ?? 0) < 1) {
            echo "<div class='muted' style='padding:24px 0;text-align:center'>아직 진입 조건을 만족한 거래가 없습니다.<br>재테스트 채점이 쌓이면 채워집니다.</div></div>";
            return;
        }
        $tcls = $s['total'] >= 0 ? 'up' : 'dn';
        echo "<div class='kpi'>";
        echo "<div><b>총 누적수익</b><span class='big {$tcls}'>{$sg($s['total'])}</span></div>";
        echo "<div><b>승률</b><span class='big'>{$pct($s['winrate'])}</span></div>";
        echo "<div><b>기대값/거래</b><span class='big " . ($s['avg'] >= 0 ? 'up' : 'dn') . "'>{$sg($s['avg'])}</span></div>";
        echo "<div><b>평균 R</b><span class='big " . ($s['avgR'] >= 0 ? 'up' : 'dn') . "'>" . number_format($s['avgR'], 2) . "R</span></div>";
        echo "</div>";
        echo "<div class='kpi' style='font-size:13px'>";
        echo "<div><b>거래수</b>{$s['n']}건</div>";
        echo "<div><b>손절 청산</b>{$s['stops']}건</div>";
        echo "<div><b>평균수익(승)</b><span class='up'>{$sg($s['avgWin'])}</span></div>";
        echo "<div><b>평균손실(패)</b><span class='dn'>{$sg($s['avgLoss'])}</span></div>";
        echo "<div><b>손익비(PF)</b>" . ($s['pf'] === null ? '∞' : number_format($s['pf'], 2)) . "</div>";
        echo "<div><b>최대낙폭</b><span class='dn'>-" . number_format($s['maxdd'], 1) . "%</span></div>";
        echo "</div>";
        echo $eqsvg($s['curve'], $color);
        echo "</div>";
    };

    echo "<div class='grid'>";
    $renderCard($bH, '🟥 전고선 (prior_high)', '#e8493f');
    $renderCard($bC, '🟦 종가선 (signal close)', '#2f7bd6');
    echo "</div>";

    // 최근 거래 내역 (전고선 기준)
    $trades = array_slice(array_reverse($bH['trades']), 0, 40);
    if ($trades) {
        echo "<div class='card'><h3>📋 최근 거래 내역 <span class='muted' style='font-size:12px'>· 전고선 기준 최신 40건</span></h3><div class='scroll'><table>";
        echo "<tr><th class='l'>신호일</th><th class='l'>종목</th><th>구분</th><th>기준선</th><th>진입(일)</th><th>손절</th><th>청산</th><th>사유</th><th>수익</th><th>R</th></tr>";
        $rmap = ['stop'=>'손절', 'gap'=>'갭손절', 'time'=>'시간청산'];
        foreach ($trades as $t) {
            $rc = $t['ret'] >= 0 ? 'up' : 'dn';
            $tname = $t['signal_type'] === 'core' ? '핵심' : '장중';
            echo "<tr>";
            echo "<td class='l'>{$h($t['signal_date'])}</td>";
            echo "<td class='l'>{$h($t['stock_name'])} <span class='muted'>{$h($t['stock_code'])}</span></td>";
            echo "<td>{$tname}</td>";
            echo "<td>" . number_format($t['level']) . "</td>";
            echo "<td>" . number_format($t['entry']) . " <span class='muted'>D+{$t['entry_day']}</span></td>";
            echo "<td class='muted'>" . number_format($t['stop']) . "</td>";
            echo "<td>" . number_format($t['exit']) . "</td>";
            echo "<td class='muted'>" . ($rmap[$t['reason']] ?? $t['reason']) . "</td>";
            echo "<td class='{$rc}'>{$sg($t['ret'])}</td>";
            echo "<td class='{$rc}'>" . number_format($t['r'], 2) . "</td>";
            echo "</tr>";
        }
        echo "</table></div></div>";
    }

    // ── 파라미터 스윕 히트맵 (기대값 R) ──
    $heat = function(array $sw, string $title) {
        $best = null;
        foreach ($sw['grid'] as $line) foreach ($line as $c)
            if ($c['avgR'] !== null && $c['n'] >= 3 && ($best === null || $c['avgR'] > $best)) $best = $c['avgR'];
        echo "<div class='card'><h3>{$title} <span class='muted' style='font-size:12px'>· 셀=평균 R(기대값) · 작은수=거래수 · 후보 {$sw['candidates']}건</span></h3>";
        echo "<div class='scroll'><table class='heat'><tr><th class='l'>손절＼밴드</th>";
        foreach ($sw['bands'] as $b) echo "<th>±" . rtrim(rtrim(number_format($b, 1), '0'), '.') . "%</th>";
        echo "</tr>";
        foreach ($sw['grid'] as $si => $line) {
            echo "<tr><th class='l'>-" . rtrim(rtrim(number_format($sw['stops'][$si], 1), '0'), '.') . "%</th>";
            foreach ($line as $c) {
                $r = $c['avgR'];
                if ($r === null || $c['n'] < 1) { echo "<td style='background:#10151f;color:#566'>-</td>"; continue; }
                $x = max(-1.0, min(1.0, $r));
                $bg = $x >= 0 ? "rgba(52,208,88," . round(0.12 + 0.5 * $x, 3) . ")"
                              : "rgba(232,73,63," . round(0.12 + 0.5 * (-$x), 3) . ")";
                $mark = ($best !== null && $r === $best) ? "outline:2px solid #d9a441;outline-offset:-2px;" : "";
                $dim  = $c['n'] < 3 ? "opacity:.45;" : "";
                echo "<td style='background:{$bg};{$mark}{$dim}'><b>" . number_format($r, 2) . "R</b>"
                   . "<br><span style='font-size:10px;color:#9aa6bf'>{$c['n']}건</span></td>";
            }
            echo "</tr>";
        }
        echo "</table></div></div>";
    };
    echo "<h2 style='margin-top:24px;font-size:17px'>🔥 파라미터 스윕 <span class='muted' style='font-size:13px'>· 재테스트 밴드 × 손절폭별 기대값</span></h2>";
    echo "<div class='grid'>";
    $heat($swH, '🟥 전고선');
    $heat($swC, '🟦 종가선');
    echo "</div>";
    echo "<div class='muted' style='font-size:12px;margin:0 0 14px'>※ 진한 초록=기대값(평균 R) 높음, 빨강=음수. <b>금색 테두리</b>=거래 3건+ 중 기대값 최대 조합. 흐린 칸=거래수 3건 미만(신뢰도 낮음). 밴드가 좁으면 진입 기회↓·정밀↑, 손절이 좁으면 R당 손실↓이지만 잦은 손절. 전고선/종가선 중 <b>넓은 초록 영역이 안정적인 쪽</b>이 더 견고한 기준선.</div>";

    echo "<div class='muted' style='font-size:12px;margin-top:6px'>※ 수익곡선은 거래를 신호일 순서로 <b>전액 복리</b> 가정(같은 기간 중복 신호는 순차 처리로 단순화 — 실제 동시보유와 다름). 기대값·평균R·승률은 순서와 무관한 통계. 진입 못한 후보=되돌림 없이 상승(runaway)하거나 재테스트가 선 아래로 마감해 회피된 건. in-sample 성격이므로 절대수익보다 <b>두 기준선의 상대 우열</b>로 해석.</div>";
    echo "</div></body></html>";
}
?>
