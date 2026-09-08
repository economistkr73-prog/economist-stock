<?php
/**
 * stock/lib/value.php — 포트폴리오 일일 결산 시계열 (2026-09-07 신설)
 *
 * 현황 화면 합계 카드의 미니 그래프(총평가·수익률·KOSPI 대비)와 그 팝업(전체기간)이 읽는 표
 * `pf_value_daily` 를 만든다. 「매일 결산 데이터가 필요하겠다」는 사용자 물음에 답한 구조.
 *
 * ══ 지위 — 이 표는 «원장에서 언제든 다시 만들 수 있는 캐시»다 (krx_surge 와 같은 지위) ═══
 *   진실은 pf_trade · pf_principal_flow · pf_income_flow · krx_amt 넷이다. 과거 어느 날이든
 *   「그 날까지의 체결 + 그 날 종가 + 그 날까지의 원금·이월배당」으로 다시 계산되므로
 *   못 찍은 날을 나중에 메울 수 있고, 옛 체결을 고쳐도 그 날짜부터 다시 만들면 된다.
 *   그래서 「파생값을 컬럼으로 두지 않는다」(포트폴리오 규칙)와 충돌하지 않는다 — 재생성 가능한 캐시일 뿐이다.
 *
 * ══ 가격 원천 = krx_amt.c 하나 ═══
 *   당일은 15:50 dart_eod 의 잠정 적재(src='n'), 다음날 13:05 dart_krx 의 확정값. 종가가 없는 날은 직전 종가를 이월하고
 *   gaps 에 센다(거래정지일은 원장의 이월값이 그대로 맞다).
 *   ★★ pf_daily(네이버 수정주가)는 쓰지 않는다 — 체결가는 «그때 값»이라 수정주가와 곱하면 분할·무상증자 뒤 상수배로
 *      어긋난다(급등주 규칙 §11 과 같은 함정).
 *   거래일 달력 = 삼성전자(PF_VALUE_CAL_CODE)의 krx_amt 행 — 휴장일 표를 새로 만들지 않는다(사이트 규칙).
 *
 * ══ 계산 단일본 = pf_value_at() (lib/calc.php 순수함수) ═══
 *   pf_ledger(d 까지의 체결) → 보유·원가·현금흐름·실현손익 → eval = 보유 × 종가 → pf_folio_money.
 *   화면 합계 카드와 <b>같은 함수</b>를 지나므로 「오늘 15:50 결산 = 마감 뒤 화면 합계 카드」가 검증 기준이다.
 *
 * ══ 쓰는 때 넷 ═══
 *   ① 15:50 dart_eod — 잠정 적재 뒤 오늘 행(+ 지수 최근 20일)          src='e'
 *   ② 13:05 dart_krx — 확정값이 잠정치를 덮은 뒤 그 날짜부터 재계산     src='k'
 *   ③ 체결·원금·이월배당 저장 API — 그 날짜 이후 재계산(동기 · 실패 삼킴) src='a'
 *   ④ job=pfvalue&from= — 최초 백필·수동 재생성                          src='b'
 *   DDL 은 크론·수동 잡에서만(pf_value_ensure) — 요청 경로에서 부르지 않는다. 표가 없으면 화면은 빈 것으로 본다.
 *
 * ══ 세 지표의 정의 ═══
 *   총평가 = eval · 수익률 = asset ÷ principal − 1(카드와 같은 정의 — 원금을 넣은 날 계단이 생긴다) ·
 *   지수 대비 = 시간가중수익률(TWR · pf_twr) − 지수(창 시작일 리베이스). 단순 수익률로 견주면 원금을 넣은 날
 *   추정자산이 뛰어 지수와 견줄 수 없다. ★TWR 의 <b>외부 유입 = 원금 + 이월손익(carry)</b>이다 —
 *   이월은 담기 전에 이미 난 손익이라 운용 성과가 아니다(2026-09-08 실측으로 고침 · pf_twr 주석).
 *   배당은 그대로 이익이다.
 */
require_once __DIR__ . '/calc.php';

const PF_VALUE_CAL_CODE  = '005930';   // 거래일 달력 = 삼성전자의 krx_amt 행
const PF_VALUE_SEED_DAYS = 45;         // 종가 이월 시작값을 만들려고 from 앞에서 더 읽는 날수

function pf_value_ensure(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pf_value_daily (
          portfolio_id INT            NOT NULL,
          d            DATE           NOT NULL,
          principal    BIGINT         NOT NULL DEFAULT 0 COMMENT '그 날까지의 원금 합계',
          income       BIGINT         NOT NULL DEFAULT 0 COMMENT '그 날까지의 이월·배당 합계',
          carry        BIGINT         NOT NULL DEFAULT 0 COMMENT '그 중 이월손익만 — TWR 의 외부유입(2026-09-08)',
          cash         DECIMAL(16,2)  NOT NULL DEFAULT 0 COMMENT '예수금',
          cost         DECIMAL(16,2)  NOT NULL DEFAULT 0 COMMENT '총매입(보유분 원가)',
          `eval`       DECIMAL(16,2)  NOT NULL DEFAULT 0 COMMENT '총평가 = 보유 × 종가',
          pl           DECIMAL(16,2)  NOT NULL DEFAULT 0 COMMENT '평가손익',
          net          DECIMAL(16,2)  NOT NULL DEFAULT 0 COMMENT '현재가치(매도비용 뺌)',
          asset        DECIMAL(16,2)  NOT NULL DEFAULT 0 COMMENT '추정자산 = 예수금 + net',
          real_pl      DECIMAL(16,2)  NOT NULL DEFAULT 0 COMMENT '실현손익 누계(이월·배당 포함)',
          n_pos        SMALLINT       NOT NULL DEFAULT 0 COMMENT '보유 포지션 수',
          gaps         SMALLINT       NOT NULL DEFAULT 0 COMMENT '종가를 못 찾아 이월·누적단가로 잰 포지션 수',
          src          CHAR(1)        NOT NULL DEFAULT 'b' COMMENT 'e=eod잠정 k=krx확정 a=api재계산 b=백필',
          calc_at      DATETIME       NOT NULL,
          PRIMARY KEY (portfolio_id, d),
          KEY idx_pfv_d (d)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* 옛 표에 carry 를 더한다(2026-09-08) — 이미 있으면 조용히 지나간다. 값은 다음 재계산이 채운다. */
    try { $pdo->exec("ALTER TABLE pf_value_daily ADD COLUMN carry BIGINT NOT NULL DEFAULT 0 AFTER income"); }
    catch (Throwable $e) { /* 이미 있음 */ }
}

/**
 * from ~ to 의 거래일마다 포트폴리오별 결산 행을 다시 만든다 (UPSERT).
 *
 * @param int|null $fid  null 이면 전 포트폴리오
 * @return array ['from','to','days'(거래일 수),'rows'(쓴 행)]
 */
function pf_value_rebuild(PDO $pdo, ?int $fid, string $from, ?string $to = null, string $src = 'b'): array
{
    $to  = $to ?: date('Y-m-d');
    $res = ['from' => $from, 'to' => $to, 'days' => 0, 'rows' => 0];
    if ($from > $to) return $res;

    $pf = new Pf($pdo);
    $folios = [];
    foreach ($pf->portfolios() as $f) {
        if ($fid === null || (int)$f['id'] === $fid) $folios[(int)$f['id']] = $f;
    }
    if (!$folios) return $res;
    $fids = array_keys($folios);
    $ph   = implode(',', array_fill(0, count($fids), '?'));

    /* 포지션 — ★Pf::positions() 는 룰셋과 INNER JOIN 이라 룰셋이 빈 행이 사라진다(포트폴리오 규칙의 함정).
     * 돈 셈엔 룰셋이 필요 없으니 여기서 단일 조인으로 읽는다. 종료 포지션도 전부(청산분의 현금흐름·실현손익은 확정된 돈). */
    $st = $pdo->prepare("
        SELECT p.id, p.portfolio_id, p.stock_code, f.broker_id, b.buy_fee_rate, b.sell_fee_rate, m.tax_rate
          FROM pf_position p
          JOIN pf_portfolio f ON f.id = p.portfolio_id
          LEFT JOIN pf_stock  s ON s.code = p.stock_code
          LEFT JOIN pf_broker b ON b.id = f.broker_id
          LEFT JOIN pf_market m ON m.code = s.market
         WHERE p.portfolio_id IN ($ph)
    ");
    $st->execute($fids);
    $positions = $st->fetchAll(PDO::FETCH_ASSOC);
    $trades    = $pf->tradesMap(array_column($positions, 'id'));
    $fees      = $pf->brokerFeesMap(array_column($positions, 'broker_id'));

    $posByFolio = []; $codes = [];
    foreach ($positions as $p) {
        $rows = $trades[(int)$p['id']] ?? [];
        if (!$rows) continue;
        $code = (string)$p['stock_code'];
        $codes[$code] = true;
        $posByFolio[(int)$p['portfolio_id']][] = [
            'code' => $code,
            'rows' => $rows,
            'prm'  => pf_cost_params($p, $fees[(int)$p['broker_id']] ?? []),
        ];
    }

    // 원금·이월배당 흐름 (날짜순) — 결산일까지의 합계가 그 날의 원금·income
    /* c = 이월손익만(kind='carry') — TWR 의 외부 유입이라 따로 센다(2026-09-08 · pf_twr 주석). 배당은 이익이라 안 뺀다. */
    $flows = ['p' => [], 'i' => [], 'c' => []];
    foreach (['p' => 'pf_principal_flow', 'i' => 'pf_income_flow', 'c' => 'pf_income_flow'] as $k => $tbl) {
        $sql = "SELECT portfolio_id, flow_at, amount FROM {$tbl} WHERE portfolio_id IN ($ph)"
             . ($k === 'c' ? " AND kind = 'carry'" : '') . " ORDER BY flow_at, id";
        $st = $pdo->prepare($sql);
        $st->execute($fids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $flows[$k][(int)$r['portfolio_id']][] = [(string)$r['flow_at'], (float)$r['amount']];
        }
    }

    // 거래일 달력
    $st = $pdo->prepare("SELECT d FROM krx_amt WHERE code = ? AND d BETWEEN ? AND ? ORDER BY d");
    $st->execute([PF_VALUE_CAL_CODE, $from, $to]);
    $days = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$days) return $res;

    // 종가 — from 앞 45일부터 읽어 이월 시작값을 만든다. c 가 없는 날(결측)은 건너뛰어 직전값이 남는다.
    $byDay = [];
    if ($codes) {
        $cl  = array_keys($codes);
        $cph = implode(',', array_fill(0, count($cl), '?'));
        $st  = $pdo->prepare("
            SELECT code, d, c FROM krx_amt
             WHERE code IN ($cph) AND d BETWEEN DATE_SUB(?, INTERVAL " . PF_VALUE_SEED_DAYS . " DAY) AND ? AND c > 0
             ORDER BY d
        ");
        $st->execute(array_merge($cl, [$from, $to]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byDay[(string)$r['d']][(string)$r['code']] = (float)$r['c'];
    }
    $allDays = array_values(array_unique(array_merge(array_keys($byDay), $days)));
    sort($allDays);
    $daySet = array_flip($days);

    $up = $pdo->prepare("
        INSERT INTO pf_value_daily
            (portfolio_id, d, principal, income, carry, cash, cost, `eval`, pl, net, asset, real_pl, n_pos, gaps, src, calc_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            principal=VALUES(principal), income=VALUES(income), carry=VALUES(carry), cash=VALUES(cash), cost=VALUES(cost), `eval`=VALUES(`eval`),
            pl=VALUES(pl), net=VALUES(net), asset=VALUES(asset), real_pl=VALUES(real_pl), n_pos=VALUES(n_pos),
            gaps=VALUES(gaps), src=VALUES(src), calc_at=NOW()
    ");

    $last = []; $ptr = ['p' => [], 'i' => [], 'c' => []]; $acc = ['p' => [], 'i' => [], 'c' => []];
    $written = 0;
    $pdo->beginTransaction();
    try {
        foreach ($allDays as $d) {
            foreach ($byDay[$d] ?? [] as $code => $c) $last[$code] = $c;   // 종가 이월
            if (!isset($daySet[$d])) continue;                              // 시드 구간(from 앞)은 계산하지 않는다
            foreach ($fids as $id) {
                foreach (['p', 'i', 'c'] as $k) {
                    $ptr[$k][$id] = $ptr[$k][$id] ?? 0;
                    $acc[$k][$id] = $acc[$k][$id] ?? 0.0;
                    $fl = $flows[$k][$id] ?? [];
                    while ($ptr[$k][$id] < count($fl) && $fl[$ptr[$k][$id]][0] <= $d) {
                        $acc[$k][$id] += $fl[$ptr[$k][$id]][1];
                        $ptr[$k][$id]++;
                    }
                }
                $v = pf_value_at($posByFolio[$id] ?? [], $last, $d, $acc['p'][$id], $acc['i'][$id], $acc['c'][$id]);
                /* 아직 아무것도 없는 포트폴리오(원금 0 · 체결 0 · 이월 0)는 행을 만들지 않는다 —
                 * 생기기 전 날짜에 0 행이 깔리면 시계열의 시작점이 거짓이 된다. */
                if ($v['principal'] == 0 && $v['n_pos'] === 0 && $v['real'] == 0 && $v['cash'] == 0 && $v['income'] == 0) continue;
                $up->execute([
                    $id, $d, round($v['principal']), round($v['income']), round($v['carry']),
                    round($v['cash'], 2), round($v['cost'], 2), round($v['eval'], 2), round($v['pl'], 2),
                    round($v['net'], 2), round($v['asset'], 2), round($v['real'], 2),
                    $v['n_pos'], $v['gaps'], $src,
                ]);
                $written++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $res['days'] = count($days);
    $res['rows'] = $written;
    return $res;
}

/** API 저장 경로용 — 그 날짜부터 재계산. 표가 없거나 실패해도 저장은 끝난다(삼킨다). */
function pf_value_touch(PDO $pdo, ?int $fid, ?string $from): void
{
    if (!$from || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
    try { pf_value_rebuild($pdo, $fid, $from, null, 'a'); } catch (Throwable $e) { /* 캐시일 뿐 — 다음 크론이 다시 만든다 */ }
}

/** 포지션 id 로 — 포트폴리오를 단일 표에서 찾는다(positionGet 은 INNER JOIN 이라 룰셋이 비면 null). */
function pf_value_touch_pos(PDO $pdo, int $pid, ?string $from): void
{
    try {
        $st = $pdo->prepare("SELECT portfolio_id FROM pf_position WHERE id = ?");
        $st->execute([$pid]);
        $fid = (int)$st->fetchColumn();
        if ($fid) pf_value_touch($pdo, $fid, $from);
    } catch (Throwable $e) { }
}

/** 원금·이월배당 행의 날짜(지우기 «전»에 읽는다 — 지우고 나면 어느 날부터 다시 잴지 모른다) */
function pf_value_flow_date(PDO $pdo, string $table, int $id): ?string
{
    if (!in_array($table, ['pf_principal_flow', 'pf_income_flow'], true)) return null;
    try {
        $st = $pdo->prepare("SELECT flow_at FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v ? (string)$v : null;
    } catch (Throwable $e) { return null; }
}

/**
 * 시계열 읽기 — fid 가 null 이면 날짜별 합계(SUM). d 오름차순.
 * 각 행: d · principal · income · cash · cost · eval · pl · net · asset · real · n_pos · gaps · rate(asset÷principal−1 · 원금 0 이면 null)
 * @param int $last 0 이면 전부, N 이면 최근 N거래일
 */
function pf_value_series(PDO $pdo, ?int $fid, ?string $from = null, ?string $to = null, int $last = 0): array
{
    $cols = "d, SUM(principal) AS principal, SUM(income) AS income, SUM(carry) AS carry, SUM(cash) AS cash, SUM(cost) AS cost,
             SUM(`eval`) AS `eval`, SUM(pl) AS pl, SUM(net) AS net, SUM(asset) AS asset, SUM(real_pl) AS real_pl,
             SUM(n_pos) AS n_pos, SUM(gaps) AS gaps";
    $w = []; $args = [];
    if ($fid !== null) { $w[] = 'portfolio_id = ?'; $args[] = $fid; }
    if ($from)         { $w[] = 'd >= ?';           $args[] = $from; }
    if ($to)           { $w[] = 'd <= ?';           $args[] = $to; }
    $sql = "SELECT {$cols} FROM pf_value_daily" . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
         . " GROUP BY d ORDER BY d" . ($last > 0 ? " DESC LIMIT " . (int)$last : '');
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];   // 표가 아직 없다(첫 크론 전) — 화면은 빈 것으로 본다
    }
    if ($last > 0) $rows = array_reverse($rows);

    $out = [];
    foreach ($rows as $r) {
        $o = [];
        foreach ($r as $k => $v) $o[$k] = ($k === 'd') ? (string)$v : (float)$v;
        $o['real'] = $o['real_pl'];
        $o['rate'] = ($o['principal'] > 0) ? ($o['asset'] / $o['principal'] - 1) : null;
        $out[] = $o;
    }
    return $out;
}

/** 지수 시계열 — 표가 없으면 빈 배열(지수 없이도 총평가·수익률 그래프는 그린다) */
function pf_value_bench(PDO $pdo, string $from, ?string $to = null): array
{
    try { return (new KrxIndex($pdo))->series($from, $to); } catch (Throwable $e) { return ['K' => [], 'Q' => []]; }
}

/**
 * 카드 미니 그래프 재료 — 최근 N거래일의 곡선(pf_value_curves). 2행 미만이면 null(선을 그릴 수 없다).
 * 반환 ['rows'=>곡선 행들, 'from', 'to', 'n']
 */
function pf_value_minis(PDO $pdo, ?int $fid, int $n = 60): ?array
{
    $rows = pf_value_series($pdo, $fid, null, null, $n);
    if (count($rows) < 2) return null;
    $from = $rows[0]['d']; $to = end($rows)['d'];
    $cv = pf_value_curves($rows, pf_value_bench($pdo, $from, $to));
    return ['rows' => $cv, 'from' => $from, 'to' => $to, 'n' => count($cv)];
}
?>
