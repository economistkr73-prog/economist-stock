<?php
/**
 * market/lib.php — 수집·정규화·품질게이트 라이브러리 (외부 의존성 없음, DOMDocument 사용)
 *
 * 규칙 요약 (market-report-spec.md):
 *  - price: 전일비 %(소수), ctx = 1·6·12개월 수익률(소수)
 *  - rate : 금리 레벨 + 전일 bp, 커브 스프레드(bp)
 *  - flow : 전일 증감 + 잔액 레벨
 *  - 누락은 0으로 채우지 않고 quality=missing 으로 분리한다.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const MKT_DUMMY_EPS = 1e-9;
const MKT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

/* ───────────────────────── HTTP ───────────────────────── */

function mkt_http(string $url, array $o = []): ?string {
    // 기본 Accept 헤더를 강제하지 않는다. (한경 CDN은 'application/json' 포함 Accept 에 500 응답)
    $headers = $o['headers'] ?? [];
    $timeout = $o['timeout'] ?? 15;
    $body    = null;

    $post = $o['post'] ?? null;

    if (function_exists('curl_init')) {                       // 서버 기본 경로
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => MKT_UA,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',     // gzip/deflate/br 자동 수신·해제
            CURLOPT_SSL_VERIFYPEER => false,  // cafe24 CA번들이 한경 인증서체인 검증실패 → 끔(기존 connect_Http와 동일)
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch);
        if ($err === 0 && $code === 200 && is_string($res) && $res !== '') $body = $res;
    } else {                                                  // curl 없는 환경 폴백
        $ctx = stream_context_create(['http' => [
            'method'  => $post !== null ? 'POST' : 'GET',
            'header'  => "User-Agent: " . MKT_UA . "\r\n" . implode("\r\n", $headers),
            'content' => $post ?? '',
            'timeout' => $timeout,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if (is_string($res) && $res !== '') $body = $res;
    }
    if ($body === null) return null;
    if (!empty($o['euckr'])) $body = mkt_to_utf8($body);
    return $body;
}

/** EUC-KR → UTF-8 (mbstring 우선, 없으면 iconv) */
function mkt_to_utf8(string $s): string {
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-8', 'EUC-KR');
    if (function_exists('iconv')) return (string) iconv('EUC-KR', 'UTF-8//IGNORE', $s);
    return $s;
}

/* ──────────────────── 파싱 유틸 ──────────────────── */

/** "1,293,535" / "-1.32%" / "+0.66" → float (없으면 null) */
function mkt_num($s): ?float {
    if ($s === null) return null;
    $s = trim((string) $s);
    if ($s === '' || $s === '-' || $s === 'N/A') return null;
    $s = str_replace([',', '%', '+', ' ', "\xc2\xa0", '원', '달러'], '', $s);
    if (!is_numeric($s)) $s = preg_replace('/[^0-9.\-]/', '', $s);
    return ($s !== '' && is_numeric($s)) ? (float) $s : null;
}

function mkt_dom(string $html): DOMXPath {
    $html = preg_replace('/^\xEF\xBB\xBF/', '', $html);           // 선두 BOM 제거
    // 이미 UTF-8 로 변환했으므로 원문의 charset 메타(euc-kr)를 제거해 libxml 재디코딩 충돌 방지
    $html = preg_replace('/<meta[^>]*charset[^>]*>/i', '', $html);
    $dom  = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

/** "2026.06.22" → "2026-06-22" */
function mkt_date(?string $s): ?string {
    if ($s && preg_match('/(\d{4})\.(\d{2})\.(\d{2})/', $s, $m)) return "$m[1]-$m[2]-$m[3]";
    return null;
}
/** "26.06.19" → "2026-06-19" */
function mkt_yymmdd(?string $s): ?string {
    if ($s && preg_match('/(\d{2})\.(\d{2})\.(\d{2})/', $s, $m)) return "20$m[1]-$m[2]-$m[3]";
    return null;
}

/* ──────────────────── 품질 게이트 ──────────────────── */

function mkt_grade($value, $dayChg): string {
    if ($value === null || $value === '' || (is_numeric($value) && (float) $value === 0.0)) return 'missing';
    if ($dayChg !== null && abs(abs((float) $dayChg) - 1.00) < MKT_DUMMY_EPS) return 'dummy';
    return 'ok';
}

/* ──────────────────── 한경 데이터센터 ──────────────────── */

/**
 * 한경 데이터센터 한 페이지 파싱 → [한경표기명 => row].
 * row: name, close, chg, pct(%값), dir, asof, m1/m6/y1(%값)
 * table-stock-wrap 안에 일일표(0)·기간표(1) 두 개가 있고 모든 셀에 data-value 속성이 있다.
 */
function mkt_hankyung(string $url): array {
    $html = mkt_http($url);
    return $html === null ? [] : mkt_parse_hankyung($html);
}

function mkt_parse_hankyung(string $html): array {
    $xp = mkt_dom($html);
    $tables = $xp->query('//table[contains(@class,"table-stock")]');
    $out = [];

    $rowName = function (DOMNode $tr) use ($xp): ?string {
        $a = $xp->query('.//td[@scope="row"]//a', $tr)->item(0);
        if (!$a) return null;
        $n = trim($a->textContent);
        return ($n === '' || str_contains($n, '$') || str_contains($n, '{')) ? null : $n;
    };
    $rowVals = function (DOMNode $tr) use ($xp): array {
        $v = [];
        foreach ($xp->query('.//td[@data-value]', $tr) as $td) $v[] = $td->getAttribute('data-value');
        return $v;
    };

    // 일일표
    if ($daily = $tables->item(0)) {
        foreach ($xp->query('.//tbody/tr', $daily) as $tr) {
            if (!($name = $rowName($tr))) continue;
            $cls = $tr->getAttribute('class');
            $dir = str_contains($cls, 'down') ? 'down' : (str_contains($cls, 'up') ? 'up' : null);
            $v   = $rowVals($tr);                 // 0종가 1전일비 2전일비% 3시가 4고가 5저가
            $dn  = $xp->query('.//span[contains(@class,"txt-date")]', $tr)->item(0);
            $out[$name] = [
                'name'  => $name,
                'close' => mkt_num($v[0] ?? null),
                'chg'   => mkt_num($v[1] ?? null),
                'pct'   => mkt_num($v[2] ?? null),
                'dir'   => $dir,
                'asof'  => $dn ? mkt_date(trim($dn->textContent)) : null,
            ];
        }
    }
    // 기간표 (전일종가, 1개월, 6개월, 1년)
    if ($period = $tables->item(1)) {
        foreach ($xp->query('.//tbody/tr', $period) as $tr) {
            if (!($name = $rowName($tr)) || !isset($out[$name])) continue;
            $v = $rowVals($tr);                   // 0전일종가 1개월 2개월6 3년1
            $out[$name]['m1'] = mkt_num($v[1] ?? null);
            $out[$name]['m6'] = mkt_num($v[2] ?? null);
            $out[$name]['y1'] = mkt_num($v[3] ?? null);
        }
    }
    return $out;
}

/* ──────────────────── 정규화 ──────────────────── */

function mkt_pct_frac(?float $pctNum): ?float {
    return $pctNum === null ? null : round($pctNum / 100, 6);
}

/** price 항목 정규화 (한경/네이버 공통 row 구조 입력) */
function mkt_norm_price(string $id, string $name, array $row): array {
    $q   = mkt_grade($row['close'] ?? null, $row['chg'] ?? null);
    $pct = $row['pct'] ?? null;
    return [
        'id'      => $id,
        'name'    => $name,
        'type'    => 'price',
        'close'   => $q === 'missing' ? null : ($row['close'] ?? null),
        'day'     => [
            'chg' => $row['chg'] ?? null,
            'pct' => mkt_pct_frac($pct),
            'dir' => $q === 'dummy' ? null : ($row['dir'] ?? (($pct ?? 0) >= 0 ? 'up' : 'down')),
        ],
        'ctx'     => [
            'm1' => mkt_pct_frac($row['m1'] ?? null),
            'm6' => mkt_pct_frac($row['m6'] ?? null),
            'y1' => mkt_pct_frac($row['y1'] ?? null),
            'ytd'=> null,
        ],
        'asof'    => $row['asof'] ?? null,
        'quality' => $q,
    ];
}

/** rate 항목 정규화 (종가=금리 레벨, 전일비×100=bp) */
function mkt_norm_rate(string $id, string $name, int $tenor, array $row): array {
    $level = $row['close'] ?? null;
    $q     = mkt_grade($level, $row['chg'] ?? null);
    $bp    = ($row['chg'] ?? null) === null ? null : round($row['chg'] * 100, 1);
    return [
        'id'         => $id,
        'name'       => $name,
        'type'       => 'rate',
        'tenorYears' => $tenor,
        'level'      => $q === 'missing' ? null : $level,
        'day'        => [
            'bp'  => $bp,
            'dir' => $q === 'dummy' ? null : ($bp === null ? null : ($bp >= 0 ? 'up' : 'down')),
        ],
        'asof'       => $row['asof'] ?? null,
        'quality'    => $q,
    ];
}

/* ──────────────────── 네이버: 국내지수 (일별 시세 — 전 거래일 종가 기준) ──────────────────── */

/**
 * 네이버 일별 지수 시세(siseJson) → [YYYYMMDD => 종가] (오래된→최신 순서 유지).
 * 실시간 polling 은 장 시작 전(아침 크롤)에 전일 종가를 등락 0 으로 주므로 일봉을 쓴다.
 */
function mkt_naver_index_daily(string $symbol, int $days = 30): array {
    $end   = date('Ymd');
    $start = date('Ymd', strtotime("-$days days"));
    $raw   = mkt_http("https://api.finance.naver.com/siseJson.naver?symbol=$symbol&requestType=1&startTime=$start&endTime=$end&timeframe=day",
        ['headers' => ['Referer: https://finance.naver.com/']]);
    if ($raw === null) return [];
    // JS 배열(작은따옴표 헤더 문자열) → JSON. 데이터는 숫자뿐이라 ' → " 치환이 안전.
    $arr = json_decode(str_replace("'", '"', $raw), true);
    if (!is_array($arr)) return [];
    $closes = [];
    foreach ($arr as $r) {
        if (!is_array($r) || count($r) < 5) continue;
        $dt = (string) $r[0];
        if (!ctype_digit($dt)) continue;                 // 헤더 행('날짜'…) 건너뜀
        $closes[$dt] = (float) $r[4];                     // [날짜,시가,고가,저가,종가,…]
    }
    return $closes;
}

/**
 * 국내지수 hero — 기준일($targetDate, 보통 전 거래일)의 종가와 직전 거래일 대비 등락.
 */
function mkt_naver_indices(string $targetDate): array {
    $map = ['KOSPI' => '코스피', 'KOSDAQ' => '코스닥', 'KPI200' => '코스피200'];
    $tgt = str_replace('-', '', $targetDate);
    $out = [];
    foreach ($map as $symbol => $kname) {
        $id     = strtolower($symbol);
        $closes = mkt_naver_index_daily($symbol);
        $dates  = array_keys($closes);                    // 오래된→최신
        if (!$dates) { $out[$id] = mkt_norm_price($id, $kname, []); continue; }

        // 기준일 선택: 정확히 있으면 그 날, 없으면 기준일 이하 최신 거래일
        $pick = isset($closes[$tgt]) ? $tgt : null;
        if ($pick === null) {
            foreach (array_reverse($dates) as $dt) if ($dt <= $tgt) { $pick = $dt; break; }
        }
        if ($pick === null) { $out[$id] = mkt_norm_price($id, $kname, []); continue; }

        // 직전 거래일 = pick 보다 작은 마지막 날
        $prev = null;
        foreach (array_reverse($dates) as $dt) if ($dt < $pick) { $prev = $dt; break; }

        $close = $closes[$pick];
        $pclose = $prev !== null ? $closes[$prev] : null;
        $chg = $pclose !== null ? $close - $pclose : null;
        $pct = ($pclose && abs($pclose) > 1e-9) ? ($chg / $pclose * 100) : null;
        $dir = $chg === null ? 'flat' : ($chg > 0 ? 'up' : ($chg < 0 ? 'down' : 'flat'));

        $out[$id] = mkt_norm_price($id, $kname, [
            'close' => $close, 'chg' => $chg, 'pct' => $pct, 'dir' => $dir, 'asof' => null,
        ]);
    }
    return array_values($out);
}

/* ──────────────────── 네이버: 유가·금속 (marketindex 메인 카드) ──────────────────── */

function mkt_naver_marketindex(): array {
    $html = mkt_http('https://finance.naver.com/marketindex/', ['euckr' => true]);
    if ($html === null) return [];
    $xp   = mkt_dom($html);
    $want = MKT_NAVER_MI;
    $out  = [];
    foreach ($xp->query('//h3[contains(@class,"h_lst")]') as $h3) {
        $blind = $xp->query('.//span[contains(@class,"blind")]', $h3)->item(0);
        if (!$blind) continue;
        $label = trim($blind->textContent);
        if (!isset($want[$label])) continue;
        // h3 다음 형제 요소 중 head_info 블록
        $info = null;
        for ($n = $h3->nextSibling; $n; $n = $n->nextSibling) {
            if ($n->nodeType === XML_ELEMENT_NODE && str_contains($n->getAttribute('class'), 'head_info')) { $info = $n; break; }
        }
        if (!$info) continue;
        $cls   = $info->getAttribute('class');
        $dir   = str_contains($cls, 'point_dn') ? 'down' : (str_contains($cls, 'point_up') ? 'up' : 'flat');
        $valN  = $xp->query('.//span[contains(@class,"value")]', $info)->item(0);
        $chgN  = $xp->query('.//span[contains(@class,"change")]', $info)->item(0);
        $close = $valN ? mkt_num($valN->textContent) : null;
        $chg   = $chgN ? mkt_num($chgN->textContent) : null;
        if ($chg !== null && $dir === 'down') $chg = -$chg;
        $prev  = ($close !== null && $chg !== null) ? $close - $chg : null;
        $pct   = ($prev && abs($prev) > 1e-9) ? ($chg / $prev * 100) : null;
        [$id, $disp] = $want[$label];
        $out[$id] = mkt_norm_price($id, $disp, [
            'close' => $close, 'chg' => $chg, 'pct' => $pct, 'dir' => $dir, 'asof' => null,
        ]);
    }
    return $out;
}

/* ──────────────────── 네이버: 증시 자금동향 ──────────────────── */

function mkt_naver_deposit(): ?array {
    $html = mkt_http('https://finance.naver.com/sise/sise_deposit.naver', ['euckr' => true]);
    return $html === null ? null : mkt_parse_deposit($html);
}

function mkt_parse_deposit(string $html): ?array {
    $xp = mkt_dom($html);
    foreach ($xp->query('//table[contains(@class,"type_1")]//tr') as $tr) {
        $tds = $xp->query('.//td', $tr);
        if ($tds->length < 11) continue;
        $first = trim($tds->item(0)->textContent);
        if (!preg_match('/\d{2}\.\d{2}\.\d{2}/', $first)) continue;     // 데이터(날짜) 행
        $v = [];
        foreach ($tds as $td) $v[] = mkt_num($td->textContent);
        // 0날짜 1예탁금 2증감 3신용 4증감 5주식형 6증감 7혼합형 8증감 9채권형 10증감 (억원)
        return [
            'asof'    => mkt_yymmdd($first),
            'deposit' => ['level' => $v[1], 'chg' => $v[2]],
            'credit'  => ['level' => $v[3], 'chg' => $v[4]],
            'funds'   => [
                'equity' => $v[5], 'equityChg' => $v[6],
                'mixed'  => $v[7], 'mixedChg'  => $v[8],
                'bond'   => $v[9], 'bondChg'   => $v[10],
            ],
            'quality' => mkt_grade($v[1], null),
        ];
    }
    return null;
}

/* ──────────────────── 네이버: 투자자별 매매동향 ──────────────────── */

function mkt_naver_investors(string $bizdate): array {
    $out = [];
    foreach (['kospi' => '01', 'kosdaq' => '02'] as $key => $sosok) {
        $html = mkt_http(
            "https://finance.naver.com/sise/investorDealTrendDay.naver?bizdate={$bizdate}&sosok={$sosok}",
            ['euckr' => true, 'headers' => ['Referer: https://finance.naver.com/sise/']]
        );
        $out[$key] = $html === null ? null : mkt_parse_investors($html);
    }
    return $out;
}

function mkt_parse_investors(string $html): ?array {
    $rows = mkt_parse_investor_rows($html, 1);
    return $rows[0] ?? null;
}

/** 일자별 순매수 표에서 N개 행 파싱 → [{asof,individual,foreign,institution,finInvest,pension,etcCorp}] (최신순) */
function mkt_parse_investor_rows(string $html, int $days = 15): array {
    $xp = mkt_dom($html);
    $out = [];
    foreach ($xp->query('//table[contains(@class,"type_1")]//tr') as $tr) {
        $tds = $xp->query('.//td', $tr);
        if ($tds->length < 11) continue;
        $first = trim($tds->item(0)->textContent);
        if (!preg_match('/\d{2}\.\d{2}\.\d{2}/', $first)) continue;
        $v = [];
        foreach ($tds as $td) $v[] = mkt_num($td->textContent);
        // 0날짜 1개인 2외국인 3기관계 4금융투자 5보험 6투신 7은행 8기타금융 9연기금 10기타법인 (억원)
        $out[] = [
            'asof'        => mkt_yymmdd($first),
            'individual'  => $v[1],
            'foreign'     => $v[2],
            'institution' => $v[3],
            'finInvest'   => $v[4],
            'pension'     => $v[9],
            'etcCorp'     => $v[10],
        ];
        if (count($out) >= $days) break;
    }
    return $out;
}

/** 코스피/코스닥 일자별 순매수 추이 N일 (페이지네이션, 오래된→최신). 차트용 라이브 조회. */
function mkt_naver_investor_series(string $sosok = '01', int $days = 120): array {
    $sosok    = $sosok === '02' ? '02' : '01';
    $days     = max(10, min(260, $days));
    $bizdate  = date('Ymd');
    $maxPages = (int) ceil($days / 10) + 2;
    $all = []; $seen = [];
    for ($p = 1; $p <= $maxPages; $p++) {
        $html = mkt_http(
            "https://finance.naver.com/sise/investorDealTrendDay.naver?bizdate={$bizdate}&sosok={$sosok}&page={$p}",
            ['euckr' => true, 'headers' => ['Referer: https://finance.naver.com/sise/']]
        );
        if ($html === null) break;
        $rows = mkt_parse_investor_rows($html, 9999);
        if (!$rows) break;                                  // 빈 페이지 = 끝
        foreach ($rows as $r) {
            if (isset($seen[$r['asof']])) continue;
            $seen[$r['asof']] = 1;
            $all[] = ['date' => $r['asof'], 'individual' => $r['individual'], 'foreign' => $r['foreign'], 'institution' => $r['institution']];
        }
        if (count($all) >= $days) break;
    }
    $all = array_slice($all, 0, $days);
    return array_reverse($all);                              // x축 오래된→최신
}

/* ──────────────────── 네이버: 주요 뉴스 목록 ──────────────────── */

function mkt_cut(string $s, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/**
 * 네이버 증권 뉴스 섹션 목록 → [{title, summary, link}]
 * @param string     $dateYmd 'YYYYMMDD' 거래일 지정(빈값이면 최신)
 * @param string     $section 401=시황·전망(국내) / 403=해외증시
 * @param array|null $pos     시황 판정 POS 키워드(기본 MKT_NEWS_POS, 미증시는 MKT_NEWS_US_POS)
 */
function mkt_naver_news(string $dateYmd = '', int $limit = 8, string $section = '401', ?array $pos = null, bool $titleOnly = false, int $maxPages = 1): array {
    $pos  = $pos ?? MKT_NEWS_POS;
    $base = 'https://finance.naver.com/news/news_list.naver?mode=LSS3D&section_id=101&section_id2=258&section_id3=' . $section;
    if ($dateYmd !== '') $base .= '&date=' . $dateYmd;
    $all = []; $seen = [];
    for ($p = 1; $p <= $maxPages; $p++) {
        $html = mkt_http($base . '&page=' . $p, ['euckr' => true, 'headers' => ['Referer: https://finance.naver.com/']]);
        if ($html === null) break;
        $raw = mkt_parse_news_raw($html);
        if (!$raw) break;                                   // 빈 페이지 = 목록 끝
        foreach ($raw as $it) { if (isset($seen[$it['title']])) continue; $seen[$it['title']] = 1; $all[] = $it; }
        $hit = 0; foreach ($all as $a) if (mkt_is_market_news($a['title'], $a['summary'], $pos, $titleOnly)) $hit++;
        if ($hit >= $limit) break;                          // 충분히 모음
    }
    $market = array_values(array_filter($all, fn($a) => mkt_is_market_news($a['title'], $a['summary'], $pos, $titleOnly)));
    return array_slice($market, 0, $limit);
}

/* ──────────────────── 야후 파이낸스: 미국 시총 상위 ──────────────────── */

/** 미국 대형주(시총순) 상위 N → [{symbol,name,pct,marketCap,pe,w52}] */
function mkt_yahoo_largecap(int $n = 25): array {
    $html = mkt_http('https://finance.yahoo.com/markets/stocks/large-cap-stocks/', [
        'timeout' => 20,
        'headers' => ['Accept: text/html', 'Accept-Language: en-US,en;q=0.9'],
    ]);
    return $html === null ? [] : mkt_parse_yahoo_largecap($html, $n);
}

function mkt_parse_yahoo_largecap(string $html, int $n = 25): array {
    $xp = mkt_dom($html);
    $out = [];
    foreach ($xp->query('//tr[@data-testid="data-table-v2-row"]') as $tr) {
        $cell = function (string $name) use ($xp, $tr): string {
            $td = $xp->query('.//td[@data-testid-cell="' . $name . '"]', $tr)->item(0);
            return $td ? trim(preg_replace('/\s+/', ' ', $td->textContent)) : '';
        };
        $sym = $cell('ticker');
        if ($sym === '') continue;
        $pn = $xp->query('.//td[@data-testid-cell="intradayprice"]//span[@data-testid="change"]', $tr)->item(0);
        $out[] = [
            'symbol'    => $sym,
            'name'      => $cell('companyshortname.raw'),
            'pct'       => mkt_num($cell('percentchange')),            // % 값
            'price'     => $pn ? mkt_num($pn->textContent) : null,     // USD
            'marketCap' => $cell('intradaymarketcap'),                 // "5.06T" / "932.5B"
            'pe'        => $cell('peratio.lasttwelvemonths'),          // "32.27" / "--"
            'w52'       => mkt_num($cell('fiftytwowkpercentchange')),  // % 값
        ];
        if (count($out) >= $n) break;
    }
    return $out;
}

/** 네이버 종목 메인 페이지의 추정 PER(_cns_per 우선, 없으면 _per). 적자/없음이면 null */
function mkt_naver_stock_per(string $code): ?float {
    $html = mkt_http("https://finance.naver.com/item/main.naver?code={$code}",
        ['euckr' => true, 'headers' => ['Referer: https://finance.naver.com/']]);
    if ($html === null) return null;
    foreach (['_cns_per', '_per'] as $id) {                       // 추정 PER 우선
        if (preg_match('/id="' . $id . '"[^>]*>\s*([\d.,]+)\s*</', $html, $m)) {
            $v = (float) str_replace(',', '', $m[1]);
            if ($v > 0) return $v;
        }
    }
    return null;
}

/* ──────────────────── 야후 Quote API (crumb 인증) ──────────────────── */

/** 여러 심볼의 시세를 한 번에 → [symbol => {pct,w52,pe,marketCap,name}]. 쿠키→crumb→v7/quote */
function mkt_yahoo_quote(array $symbols): array {
    if (!$symbols || !function_exists('curl_init')) return [];
    $jar = tempnam(sys_get_temp_dir(), 'yq');
    $get = function (string $url) use ($jar): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => MKT_UA, CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        ]);
        $b = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($b) && $b !== '') ? $b : null;
    };
    $get('https://fc.yahoo.com');                                          // 쿠키 획득
    $crumb = $get('https://query1.finance.yahoo.com/v1/test/getcrumb');
    if ($crumb === null) { @unlink($jar); return []; }
    $url  = 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode(implode(',', $symbols)) . '&crumb=' . urlencode(trim($crumb));
    $body = $get($url);
    @unlink($jar);
    if ($body === null) return [];
    $j = json_decode($body, true);
    $out = [];
    foreach (($j['quoteResponse']['result'] ?? []) as $r) {
        if (empty($r['symbol'])) continue;
        $out[$r['symbol']] = [
            'pct'       => $r['regularMarketChangePercent'] ?? null,
            'w52'       => $r['fiftyTwoWeekChangePercent'] ?? null,
            'pe'        => $r['trailingPE'] ?? null,
            'marketCap' => $r['marketCap'] ?? null,
            'price'     => $r['regularMarketPrice'] ?? null,
            'name'      => $r['shortName'] ?? '',
        ];
    }
    return $out;
}

/* ──────────────────── 야후 재팬: 일본 시총 상위 ──────────────────── */

function mkt_jp_clean_name(string $s): string {
    return trim(str_replace(['(株)', '（株）', '(株)'], '', $s));
}

/** 야후재팬 時価총액 랭킹 → 상위 N [{code,name}] (시총순) */
function mkt_yahoo_jp_ranking(int $n = 25): array {
    $html = mkt_http('https://finance.yahoo.co.jp/stocks/ranking/marketCapitalHigh?market=all&term=daily',
        ['timeout' => 20, 'headers' => ['Accept-Language: ja']]);
    if ($html === null) return [];
    $xp = mkt_dom($html);
    $out = []; $seen = [];
    foreach ($xp->query('//tr[contains(@class,"RankingTable__row")]') as $tr) {
        $a  = $xp->query('.//td//a', $tr)->item(0);
        $li = $xp->query('.//li', $tr)->item(0);
        if (!$a || !$li) continue;
        $code = trim($li->textContent);
        if (!preg_match('/^[0-9][0-9A-Z]{3}$/', $code) || isset($seen[$code])) continue;
        $seen[$code] = 1;
        $out[] = ['code' => $code, 'name' => trim($a->textContent)];
        if (count($out) >= $n) break;
    }
    return $out;
}

/** 일본 시총 상위 N (랭킹 티커 + Quote API 시세) → [{code,name,pct,pe,w52,capJpy}] */
function mkt_yahoo_jp_largecap(int $n = 25): array {
    $rank = mkt_yahoo_jp_ranking($n);
    if (!$rank) return [];
    $symbols = array_map(fn($r) => $r['code'] . '.T', $rank);
    $q = mkt_yahoo_quote($symbols);
    $out = [];
    foreach ($rank as $r) {
        $qd = $q[$r['code'] . '.T'] ?? [];
        $out[] = [
            'code'   => $r['code'],
            'name'   => MKT_JP_NAME_KR[$r['code']] ?? mkt_jp_clean_name($r['name']),
            'pct'    => $qd['pct'] ?? null,
            'pe'     => isset($qd['pe']) && $qd['pe'] !== null ? round((float) $qd['pe'], 2) : null,
            'w52'    => $qd['w52'] ?? null,
            'capJpy' => $qd['marketCap'] ?? null,
            'priceJpy' => $qd['price'] ?? null,
        ];
    }
    return $out;
}

/** 'YYYY-MM-DD' → 직전 평일 'YYYY-MM-DD' (주말 건너뜀; 거래일 폴백용) */
function mkt_prev_weekday(string $ymd): string {
    $t = strtotime($ymd);
    do { $t = strtotime('-1 day', $t); } while (in_array((int) date('N', $t), [6, 7], true));
    return date('Y-m-d', $t);
}

/** 시황 기사 여부 판정 (NEG 우선 제외 → POS 있으면 채택). $titleOnly=true 면 제목만 검사(미증시용) */
function mkt_is_market_news(string $title, string $summary, array $pos, bool $titleOnly = false): bool {
    $t = $titleOnly ? $title : ($title . ' ' . $summary);
    foreach (MKT_NEWS_NEG as $k) if (strpos($t, $k) !== false) return false;
    foreach ($pos as $k) if (strpos($t, $k) !== false) return true;
    return false;
}

/** 한 페이지의 모든 기사 → [{title,summary,link}] (필터 없음) */
function mkt_parse_news_raw(string $html): array {
    $xp = mkt_dom($html);
    $all = []; $seen = [];
    foreach ($xp->query('//dd[contains(@class,"articleSubject")]') as $dd) {
        $a = $xp->query('.//a', $dd)->item(0);
        if (!$a) continue;
        $title = trim($a->getAttribute('title') !== '' ? $a->getAttribute('title') : $a->textContent);
        $href  = $a->getAttribute('href');
        if ($title === '' || $href === '' || isset($seen[$title])) continue;
        $seen[$title] = 1;
        // 다음 형제 dd.articleSummary 의 텍스트노드만(언론사·날짜 span 제외)
        $sum = '';
        for ($n = $dd->nextSibling; $n; $n = $n->nextSibling) {
            if ($n->nodeType === XML_ELEMENT_NODE && str_contains($n->getAttribute('class'), 'articleSummary')) {
                foreach ($n->childNodes as $c) if ($c->nodeType === XML_TEXT_NODE) $sum .= $c->textContent;
                break;
            }
        }
        $sum  = trim(preg_replace('/\s+/', ' ', $sum));
        $link = str_starts_with($href, 'http') ? $href : ('https://finance.naver.com' . $href);
        $all[] = ['title' => mkt_cut($title, 120), 'summary' => mkt_cut($sum, 180), 'link' => $link];
    }
    return $all;
}

/** 한 페이지 파싱 + 시황 필터 (단일 페이지용; 다중 페이지는 mkt_naver_news) */
function mkt_parse_news(string $html, int $limit = 8, ?array $pos = null, bool $titleOnly = false): array {
    $pos = $pos ?? MKT_NEWS_POS;
    $all = mkt_parse_news_raw($html);
    $market = array_values(array_filter($all, fn($a) => mkt_is_market_news($a['title'], $a['summary'], $pos, $titleOnly)));
    return array_slice($market, 0, $limit);
}
