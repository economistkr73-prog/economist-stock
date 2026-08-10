<?php
/**
 * stock/lib/boxbrk.php — 「박스 상향돌파」 판정 <b>단일본</b> (2026-08-09 신설)
 *
 * ═══ 이 파일이 왜 있나 ═══════════════════════════════════════════════
 * 사용자가 패턴분석(불꽃형) 갤러리에서 관심차트 그룹 「박스_상향돌파」에 365건을 담았다.
 * 「이 패턴을 매일 찾아 알려 줄 수 있나 — 그러려면 정의가 필요하다」가 출발점이다.
 *
 * 정의는 <b>담긴 365건을 되맞춰</b> 얻었다(사전 피처만 · 로지스틱 회귀 · 교차검증 AUC 0.750).
 * 손으로 그린 규칙이 아니라 <b>실제로 무엇을 고르셨나</b>를 잰 것이다.
 *
 * ★★그리고 여기 <b>반드시 함께 적어 둘 사실</b>이 있다 (2026-08-09 실측 · 세 갈래로 확인).
 *   <b>이 패턴에는 측정된 성과 우위가 없다.</b>
 *     ① 모델이 재현한 상위 24%: 승률 43.6% — 전체 불꽃형(49.1%)보다 «나쁘다»
 *     ② 8년 기간 밖 22,010건: 상위 10% +0.40% vs 기준선 −0.25% (승률은 44.1% vs 42.8%로 사실상 같다)
 *     ③ 같은 «모양» 칸 안에서 담은 것 − 안 담은 것 승률 차가 +1.7~+22.2%p 로 붙어 있고,
 *        <b>모양이 가장 «안» 닮은 4분위에서 담은 것이 가장 좋았다</b>(68.4%).
 *   ③ 이 결정적이다 — 눈이 «사전 모양»을 보고 골랐다면 우위는 모양이 강한 쪽에 몰려야 한다.
 *   불꽃형 카드는 신호일 <b>이후</b> 55거래일 차트와 결과 배지를 함께 보여 준다.
 *   ⇒ 담긴 것의 승률 우위(55.9% vs 46.9%)는 «모양»이 아니라 <b>«결과»를 본 데서 왔다</b>.
 *
 * ★2026-08-10 — <b>최종 5조건 정의로 8년 재측정</b>(`bx_scan.php job=bt8y` · 30초 · 검증 탭 ⑨).
 *   위 ②는 조건 ③④⑤를 조이기 «전» 정의의 측정이었다. 조인 뒤에도 <b>결론 불변</b>:
 *   5조건 4,426건 — 비용 뒤 평균 +0.01% vs 기준선 −0.20% · 승률 41.7 vs 41.4% ·
 *   중앙 −3.30 vs −2.28%(오히려 나쁨 — 더 크게 움직이는 무리라 평균만 끌린다). 양(+)인 해 2/8.
 *
 * ⇒ 처음 설계는 「화면이 결과를 가린다」였으나 2026-08-09 사용자 지시로 <b>화면 하나로 합치며
 *   결과를 담게 됐다</b>(bx_scan.php 머리말). 오염 방지는 이제 다른 자리가 맡는다 —
 *   오늘 뜬 것은 사후 5거래일이 안 차 «자연히» 결과가 없고, 나중에 깨끗한 표본만 가를 자는
 *   `chart_fav_item.made_at` 과 신호일 `d` 의 간격이다. 결과 «판정»은 bx_scan.php 소관이고
 *   이 파일은 여전히 사전(事前) 판정만 한다 — `boxbrk_feat()` 는 $i 뒤를 한 칸도 읽지 않는다.
 *
 * ═══ 층 ═════════════════════════════════════════════════════════════
 *   판정(여기) → 적재 `cron/bx_scan.php` → 화면 `pf_page_boxbrk()` → 담기 `ChartFav`(src='boxbrk')
 *   화면은 «고르기만» 한다 — 임계·계수·어휘를 화면에 다시 적지 않는다(Thr.class·ChartFeat 와 같은 패턴).
 */

/**
 * 축 카탈로그 — <b>화면·문서가 이것을 읽는다</b>. 축을 늘리면 여기 한 줄.
 *
 * dir: +1 이면 「클수록 담고 싶어진다」 · −1 이면 「작을수록」.
 * 계수(W)의 부호와 <b>반드시 같다</b> — 다르면 화면이 거짓을 말한다.
 */
function boxbrk_axes(): array
{
    return [
        'vola20'     => ['n' => '직전 변동성',   'u' => '%',  'dir' => -1, 'w' => '20거래일 일간 수익률 표준편차 — <b>조용했나</b>'],
        'brkHi120'   => ['n' => '120일 고가 대비', 'u' => '%', 'dir' => +1, 'w' => '종가가 직전 120거래일 고가를 <b>넘었나</b> (박스 천장)'],
        'dYHigh'     => ['n' => '52주 고가 대비', 'u' => '%',  'dir' => +1, 'w' => '전일 종가가 250거래일 최고가에서 얼마나 아래인가 — <b>고가권인가</b>'],
        'posPrev60'  => ['n' => '60일 레인지 위치', 'u' => '%', 'dir' => +1, 'w' => '전일 종가가 직전 60일 고·저 사이 <b>어디</b>인가'],
        'clopos'     => ['n' => '종가 위치',     'u' => '%',  'dir' => +1, 'w' => '당일 고·저 사이에서 종가가 <b>위쪽</b>인가'],
        'brkHi60'    => ['n' => '60일 고가 대비', 'u' => '%',  'dir' => -1, 'w' => '60일 고가를 <b>«막»</b> 넘었나 — 크게 뛴 것은 안 고르셨다'],
        'stepsAbove' => ['n' => '머리 위 계단',  'u' => '개', 'dir' => -1, 'w' => '전일 종가 위에 남은 <b>과거 박스 저항</b>의 수'],
        'lAmt'       => ['n' => '거래대금',      'u' => 'log', 'dir' => +1, 'w' => '그 날 거래대금(억, 로그) — <b>클수록</b>'],
        'lw60'       => ['n' => '60일 박스 폭',  'u' => 'log', 'dir' => +1, 'w' => '직전 60일 고가÷저가(로그)'],
        'mom60'      => ['n' => '60일 모멘텀',   'u' => '%',  'dir' => -1, 'w' => '전일 종가 / 60거래일 전 종가'],
        'upperW'     => ['n' => '윗꼬리',        'u' => '%',  'dir' => +1, 'w' => '당일 봉의 윗꼬리 비중'],
    ];
}

/**
 * 판정 상수 — <b>여기 말고 어디에도 적지 않는다</b>.
 *
 * ★계수·평균·표준편차는 2026-08-09 학습 결과 그대로다(학습 표본 1,225건 · 담긴 것 348).
 *   다시 학습하면 이 블록만 갈아 끼운다. 손으로 고치지 않는다 — 세 숫자가 한 벌이라
 *   하나만 만지면 점수가 조용히 다른 뜻이 된다.
 */
class BoxBrk
{
    /** 모집단 — 「최고 거래대금 신호」. `dbrk`·8년 백테스트와 <b>글자 그대로 같은 자</b>다. */
    public const WIN     = 120;      // 직전 (WIN-1)=119 거래일과 견준다 — ★바꾸면 백테스트와 못 견준다
    public const MINAMT  = 10000000000;   // 거래대금 하한 100억 (원)

    /**
     * 박스(계단) 창 — 직전 (STEPWIN-1)=<b>120</b> 거래일.
     *
     * ★★신호 창(119)과 <b>일부러 다르다</b>. 이 값은 <b>차트에 그려지는 계단</b>과 맞춰야 하는 것이라
     *   `chart_indicator` id=4 「최고_거래대금선(#1)」의 수식을 그대로 따른다(2026-08-09 실측 대조):
     *       조건 = 현재_거래대금 > highest(거래대금, 120)[1] && 현재_거래대금 > 기본_거래대금×1억
     *   `highest(x,120)[1]` 은 <b>직전 120봉</b>의 최고다. 여기를 119 로 두면 화면에 안 그려진 계단을
     *   세거나 그 반대가 되어 <b>화면과 판정이 다른 말을 한다</b>.
     * ★대가: 점수 학습은 119 로 했다. `stepsAbove` 가 최대 ±1 흔들리는데 계수 −0.205·표준편차 5.23 이라
     *   z 변화는 ±0.04 수준(잡음 안)이다. 화면과 맞추는 쪽이 값어치가 크다고 보았다.
     */
    public const STEPWIN = 121;
    public const STEPAMT =  1000000000;   // 계단으로 칠 최소 거래대금 10억 — 지표의 «기본_거래대금»과 같다

    /**
     * ★<b>이전 박스가 없는 신호는 담지 않는다</b>(2026-08-09 사용자 지시).
     *
     * 「박스 상향돌파」인데 <b>뚫을 박스가 없으면</b> 이름이 성립하지 않는다. 실제로 그런 건이
     * 섞여 있었다 — 달바글로벌 2026-05-12(2025-05-22 상장 · 이력 297봉 동안 첫 폭발)처럼
     * 차트에 계단이 <b>신호일 자신 하나뿐</b>인 것들이다(실측 auto 10건).
     */
    public const MIN_PRIOR_BOX = 1;

    public const HIST_MIN = 130;     // 앞이 이만큼 없으면 판정하지 않는다(신규상장)

    /**
     * ★하드 배제 — 점수 «밖»에서 먼저 자른다. <b>이제 등락 하나뿐</b>이다.
     *
     * 2026-08-09 사용자 지시로 바뀌었다:
     *   - 등락 상한 25% → <b>28%</b> (「상한가 «근처»만 뺀다」)
     *   - <b>「종가위치 ≥95%」 배제는 없앴다</b> — 그 규칙은 「담은 적 없다」에서 뽑은 것인데,
     *     정작 사용자가 <b>박스로 인정하는 날</b>을 걸러내고 있었다(하나마이크론 2026-01-27 ·
     *     거래대금 4,197억 · 종가위치 99%). 「안 담았다」가 「나쁘다」는 뜻은 아니다 —
     *     그 화면에서 그 날을 아예 안 봤을 수도 있다. 실측 783건(박스일의 12.0%)이 여기 걸려 있었다.
     */
    public const CHG_MAX = 28.0;     // 등락률 상한(%) — 상한가 근처

    /**
     * ★<b>이전 박스는 100억 이상이어야 센다</b>(2026-08-09 사용자 지시).
     *
     * 박스를 «그리는» 하한은 차트 지표에 맞춰 10억(STEPAMT)인데, 그러면 「이전 박스」의 문턱이
     * 신호 문턱(100억)보다 <b>열 배 낮아진다</b>. 사용자가 말하는 규칙은
     * 「<b>100억 이상의 박스가 이전에 있고</b>, 해당일 박스가 새로 생긴다」이므로 여기서 갈라 둔다.
     * ★실측 영향은 작다 — auto 1,093건 중 3건만 빠진다(99.7%가 이미 만족).
     * ★`stepsAbove`(점수 축)와 `m_box_n`(화면의 「박스 N벌」)은 <b>10억 그대로</b>다 —
     *   저건 «화면에 그려지는 계단»을 세는 것이고, 점수는 10억 기준으로 학습했다.
     */
    public const PRIOR_BOX_AMT = 10000000000;   // 100억

    /**
     * ★<b>기존 박스에서 «너무 멀리 떠서» 생긴 박스는 담지 않는다</b>(2026-08-09 사용자 지시).
     *
     * 조건: <b>현재 박스 저가 ≤ 기존 박스 고가 × (1 + BOX_GAP_MAX)</b>
     * 「박스 상향돌파」라면 새 박스가 기존 박스에 <b>붙어</b> 있어야 한다. 실제로 그렇지 않은 것이
     * 섞여 있었다 — 두산 2026-05-29 는 현재 박스 저가 1,689,000 인데 기존 박스 고가가 573,000 이라
     * <b>+194.8%</b> 위였다. 돌파가 아니라 «완전히 다른 자리에서 새로 생긴 것»이다.
     *
     * ★실측(1,600건)이 취향과 맞았다 — 직접 고른 359건 중 <b>52%가 −20~0%</b>(현재 저가가 기존 박스
     *   «안»)이고 +50% 넘게 뜬 것은 5건(1.4%)뿐이다. 자동은 85건(6.9%)이었다.
     * ★기준을 0.10 으로 고른 것은 사용자 선택이다. 더 조이면(현재L < 기존H, 즉 박스 «안») 취향엔
     *   더 맞지만 자동 표본이 1,241→474 로 62% 줄고, 자동분 성적은 오히려 나빠졌다(42.2→39.3%).
     * ★「기존 박스」는 <b>가장 최근 이전 박스</b>(10억↑ · 차트에 선이 그려지는 그것)다 —
     *   눈에 가장 가까운 계단이라 화면과 말이 맞는다.
     */
    public const BOX_GAP_MAX = 0.10;

    /**
     * ★<b>신규 박스가 기존 박스보다 «위»여야 한다</b>(2026-08-09 사용자 지시).
     *   조건: <b>신규 L > 직전 박스 L</b> 그리고 <b>신규 H > 직전 박스 N개(ABOVE_LAST_N_BOX)의 H 전부</b>
     *
     * `BOX_GAP_MAX` 는 «너무 높이 뜬 것»만 막았지 <b>아래로 내려간 것은 못 막았다</b> —
     * 실측으로 그런 것이 섞여 있었다: 에스씨엠 109610 은 기존박스 대비 −23.5%(등락 −2.15%),
     * 광무 029480 은 −41.8%. 둘 다 <b>박스가 아래로 한 단 내려선</b> 그림이라 「상향돌파」가 아니다.
     *
     * ★★그런데 <b>「가장 최근 박스 하나」만 보면 또 샌다</b>(같은 날 다시 잡혔다) — 박스가 한 단
     *   «내려선 뒤» 그 위에 생기면 통과한다. 야스 255440 이 그랬다: 직전 박스보다는 위(−5.2%)인데
     *   그 앞 박스들의 H(≈12,300 · ≈9,900)보다 <b>한참 아래</b>(신규 H ≈5,800)였다.
     * ⇒ <b>직전 박스 3개의 H 전부</b>와 견준다. 3 인 것은 <b>차트가 옛 단계를 정확히 3개 연장해
     *   그리기 때문</b>이다(`chart_indicator` id=4 의 `ext:3`) — 「화면에 보이는 계단 전부보다 위」와
     *   같은 말이 된다. 화면과 판정이 같은 것을 본다.
     */
    public const REQUIRE_HIGHER_BOX = true;
    /** 신규 박스 H 가 넘어야 할 «옛 박스» 수 — 차트의 `ext:3` 과 같은 값 */
    public const ABOVE_LAST_N_BOX = 3;

    /**
     * ★<b>그 날 종가가 시가보다 이만큼(%) 위여야 한다</b>(2026-08-09 사용자 지시).
     *   조건: <b>(종가 ÷ 시가 − 1) × 100 ≥ OC_MIN</b>
     *
     * 「등락(전일 종가 대비)」과 <b>다른 자</b>다 — 갭으로 뜬 뒤 하루 종일 흘러내린 봉은
     * 등락이 플러스여도 <b>시가→종가가 음수</b>다. 돌파의 «그 날»은 장중에 밀어올린 봉이어야 한다.
     */
    public const OC_MIN = 7.0;

    /** 절편 */
    public const B0 = -1.165095;

    /** 축 => [계수, 학습표본 평균, 학습표본 표준편차] — 표준화가 계수와 <b>한 벌</b>이다 */
    public const W = [
        'lw60'       => [ 0.135963,   0.421059,   0.340859],
        'mom60'      => [-0.077743,  23.689504, 114.251137],
        'brkHi60'    => [-0.260055,  -3.830963,  13.774273],
        'vola20'     => [-0.679039,   5.487553,  11.648033],
        'posPrev60'  => [ 0.365651,  59.945655,  32.002346],
        'brkHi120'   => [ 0.524367,  -9.560534,  16.567944],
        'dYHigh'     => [ 0.391665, -26.739720,  19.556193],
        'lAmt'       => [ 0.180779,   5.981493,   0.911476],
        'stepsAbove' => [-0.205439,   9.644898,   5.230826],
        'clopos'     => [ 0.331395,  39.107629,  24.008158],
        'upperW'     => [ 0.028701,  48.239495,  23.102492],
    ];

    /**
     * 점수 분위 경계 — 8년 모집단 25,856건의 실측 백분위. <b>화면의 「등급」이 이것만 본다.</b>
     *
     * ★★<b>2026-08-09: 「적재 컷」을 없앴다</b>(사용자 지시 — 「z값은 이상해, 빼자」).
     *   점수는 이제 <b>표시·정렬·알림 등급</b>에만 쓰고 <b>담는 기준에서는 뺐다</b>.
     *
     *   왜 — 점수는 「이 날이 담긴 365건과 얼마나 닮았나」이지 「오를까」가 아니다. 그런데
     *   학습은 <b>불꽃형 1,225건 «안에서»</b> 했는데 지금은 박스 조건으로 걸러진 <b>전혀 다른
     *   모집단</b>에 그 자를 대고 있었다. 그 결과가 실측으로 드러났다:
     *     - 직접 고른 294건의 z <b>중앙값이 −0.40</b> — 컷 0.223 을 넘는 것은 <b>21%뿐</b>
     *     - 씨어랩 189330 은 <b>같은 종목의 두 박스 돌파가 갈렸다</b> — 2026-05-29(z −0.27)는
     *       조건을 다 통과하고도 컷에서 빠졌고, 화면에 있는 2026-07-09 도 z +0.129 로 «컷 아래»인데
     *       오직 «사용자가 담았다»(src='pick')는 이유로 남아 있었다. 화면이 앞뒤가 안 맞았다.
     *   ⇒ 「박스 상향돌파」의 정의는 <b>박스 조건 셋</b>이 한다. 점수가 그 위에서 79%를 버릴 이유가 없다.
     */
    public const Z_Q75 = 0.223;   // 상위 25%
    public const Z_Q90 = 0.763;   // 상위 10%
    public const Z_Q95 = 1.032;   // 상위  5%
}

/**
 * 그 날이 어떤 날인가 — `qm_day_kind()` 와 <b>같은 판정</b>이다.
 * (급등주 잡의 단일본을 크론 밖에서도 써야 해 같은 규칙을 여기 둔다.
 *  ★규칙이 갈리면 안 되므로 «정지 → 건너뛴다 · 결측 → 판정 불가»를 그대로 지킨다.)
 */
function boxbrk_day_kind(?array $r): string
{
    if ($r === null) return 'gap';
    if (!array_key_exists('vol', $r)) {
        throw new RuntimeException('boxbrk_day_kind: SELECT 에 vol 을 함께 넣어야 한다 (halt/gap 을 가르는 자)');
    }
    $o = (int)$r['o']; $h = (int)$r['h']; $l = (int)$r['l'];
    if ($o > 0 && $h > 0 && $l > 0) return 'ok';
    return ((int)$r['vol'] === 0) ? 'halt' : 'gap';
}

/**
 * 한 종목의 시계열에서 <b>i번째 봉</b>을 판정한다.
 *
 * @param array $s   krx_amt 행 배열 (d,o,h,l,c,vol,amt,mktcap · <b>날짜 오름차순</b> · c>0 만)
 * @param int   $i   판정할 위치
 * @param array $boxes 미리 구한 박스(계단)일 목록 [['i'=>, 'H'=>, 'L'=>], …] — boxbrk_boxes()
 * @return ?array ['x'=>축값, 'meta'=>사람이 읽는 값] · 판정 불가면 null
 *
 * ★사후를 <b>한 칸도</b> 읽지 않는다 — 이 함수가 $i 보다 뒤를 보면 이 화면 전체가 뜻을 잃는다.
 */
function boxbrk_feat(array $s, int $i, array $boxes): ?array
{
    $n = count($s);
    if ($i < 1 || $i >= $n) return null;
    if (boxbrk_day_kind($s[$i]) !== 'ok') return null;

    $prevC = (float)$s[$i - 1]['c'];
    $close = (float)$s[$i]['c'];
    $open  = (float)$s[$i]['o'];
    $high  = (float)$s[$i]['h'];
    $low   = (float)$s[$i]['l'];
    $amt   = (float)$s[$i]['amt'];
    if ($prevC <= 0 || $close <= 0 || $high <= $low) return null;

    /* ── 고·저 창 (정상일만 센다 — 거래정지일의 0 을 최저가로 읽으면 −100% 가 된다) */
    $hi60 = 0.0; $lo60 = INF; $hi120 = 0.0; $hi250 = 0.0;
    $c20 = null; $c60 = null; $nOk = 0;
    for ($k = $i - 1; $k >= 0 && $nOk < 250; $k--) {
        if (boxbrk_day_kind($s[$k]) !== 'ok') continue;
        $nOk++;
        $h = (float)$s[$k]['h']; $l = (float)$s[$k]['l'];
        if ($nOk <= 60)  { $hi60  = max($hi60, $h); $lo60 = min($lo60, $l); }
        if ($nOk <= 120) { $hi120 = max($hi120, $h); }
        $hi250 = max($hi250, $h);
        if ($nOk === 20) $c20 = (float)$s[$k]['c'];
        if ($nOk === 60) $c60 = (float)$s[$k]['c'];
    }
    if ($nOk < BoxBrk::HIST_MIN || $hi120 <= 0 || $hi250 <= 0 || !is_finite($lo60)
        || $lo60 <= 0 || $hi60 <= $lo60) return null;

    /* ── 20거래일 변동성 (일간 수익률 표준편차 %) */
    $rets = []; $cnt = 0; $pv = null;
    for ($k = $i - 1; $k >= 0 && $cnt < 21; $k--) {
        if (boxbrk_day_kind($s[$k]) !== 'ok') continue;
        $c = (float)$s[$k]['c'];
        if ($pv !== null && $c > 0) $rets[] = $pv / $c - 1;
        $pv = $c; $cnt++;
    }
    $m = $rets ? array_sum($rets) / count($rets) : 0.0;
    $v = 0.0; foreach ($rets as $r) $v += ($r - $m) ** 2;
    $vola = count($rets) > 1 ? sqrt($v / (count($rets) - 1)) * 100 : 0.0;

    /* ── 20일 평균 거래대금 (불꽃형 여부 = 20평비 · 점수엔 안 쓰고 «배지»로만 쓴다) */
    $a20 = 0.0; $c20n = 0;
    for ($k = max(0, $i - 20); $k < $i; $k++) { $a20 += (float)$s[$k]['amt']; $c20n++; }
    $a20 = $c20n ? $a20 / $c20n : 0.0;
    $mult = $a20 > 0 ? $amt / $a20 : 0.0;

    /* ── 계단 — 신호일 «이전»의 과거 박스들
     *   above    : 전일 종가 «위»에 남은 저항의 수 (점수에 쓴다)
     *   boxN     : 이전 박스의 «총» 개수 — 「차트에 계단이 몇 벌 그려지나」의 재료다.
     *              ★지표는 옛 단계를 3개까지 «연장»해 그린다(lines_json 의 ext:3) —
     *                박스 봉이 화면 밖이어도 «선»은 보인다. 그래서 창으로 세면 안 된다. */
    $above = 0; $nearAbove = null; $lastBoxI = null; $boxN = 0; $boxN100 = 0;
    $prevH = null; $prevL = null;      // ★가장 최근 이전 박스 — 「너무 멀리 떴나」의 잣대
    $lastHs = [];                      // ★옛 박스들의 H — 뒤에서 N개만 남긴다(ABOVE_LAST_N_BOX)
    foreach ($boxes as $bx) {
        if ($bx['i'] >= $i) break;
        $lastBoxI = $bx['i']; $boxN++;
        $prevH = $bx['H']; $prevL = $bx['L'];
        $lastHs[] = $bx['H'];
        if (count($lastHs) > BoxBrk::ABOVE_LAST_N_BOX) array_shift($lastHs);
        /* ★100억↑ 박스만 따로 센다 — 「이전에 100억 박스가 있나」 게이트용(PRIOR_BOX_AMT) */
        if (($bx['amt'] ?? 0) >= BoxBrk::PRIOR_BOX_AMT) $boxN100++;
        if ($bx['H'] > $prevC) {
            $above++;
            if ($nearAbove === null || $bx['H'] < $nearAbove) $nearAbove = $bx['H'];
        }
    }

    $x = [
        'lw60'       => log(max(1.0, $hi60 / $lo60)),
        'mom60'      => $c60 > 0 ? ($prevC / $c60 - 1) * 100 : 0.0,
        'brkHi60'    => ($close / $hi60 - 1) * 100,
        'vola20'     => $vola,
        'posPrev60'  => ($prevC - $lo60) / ($hi60 - $lo60) * 100,
        'brkHi120'   => ($close / $hi120 - 1) * 100,
        'dYHigh'     => ($prevC / $hi250 - 1) * 100,
        'lAmt'       => log(max(1e-9, $amt / 1e8)),
        'stepsAbove' => min(15, $above),
        'clopos'     => ($close - $low) / ($high - $low) * 100,
        'upperW'     => ($high - max($open, $close)) / ($high - $low) * 100,
    ];
    $meta = [
        'chg'      => ($close / $prevC - 1) * 100,
        'amt'      => $amt,
        'mult'     => $mult,
        'mktcap'   => (float)($s[$i]['mktcap'] ?? 0),
        'close'    => $close,
        'prev'     => $prevC,
        'hi60'     => $hi60, 'lo60' => $lo60, 'hi120' => $hi120, 'hi250' => $hi250,
        'mom20'    => $c20 > 0 ? ($prevC / $c20 - 1) * 100 : null,
        'nearAbove'=> $nearAbove,
        'boxGap'   => $lastBoxI !== null ? ($i - $lastBoxI) : null,
        'boxN'     => $boxN,      // 이전 박스 총 개수(10억↑) — 「차트에 계단 몇 벌」 = 1 + min(3, boxN)
        'boxN100'  => $boxN100,   // 그중 100억↑ — 「이전에 100억 박스가 있나」 게이트
        'prevH'    => $prevH,     // 가장 최근 이전 박스의 고가·저가
        'prevL'    => $prevL,
        /* ★직전 박스 N개(ABOVE_LAST_N_BOX)의 H 중 «가장 높은» 것 — 신규 H 가 이것을 넘어야 한다.
         *   차트가 옛 단계를 그만큼 연장해 그리므로 「보이는 계단 전부보다 위」와 같은 말이다. */
        'prevHmax' => $lastHs ? max($lastHs) : null,
        'prevHn'   => count($lastHs),
        'curH'     => $high,      // 신규 박스(=신호일 봉)의 고가·저가
        'curL'     => $low,
        /* ★현재 박스 저가가 기존 박스 고가보다 몇 % 위인가 — 음수면 기존 박스 «안»에서 뚫은 것 */
        'gapPct'   => ($prevH !== null && $prevH > 0) ? ($low / $prevH - 1) * 100 : null,
        /* ★시가→종가 — 「등락(전일 종가 대비)」과 다른 자다(OC_MIN 주석) */
        'ocPct'    => $open > 0 ? ($close / $open - 1) * 100 : null,
    ];
    return ['x' => $x, 'meta' => $meta];
}

/**
 * 계단(박스)일 — 그 날 거래대금이 <b>직전 120봉</b> 최고이고 10억↑ 인 날.
 * ★차트 지표 id=4 와 <b>같은 창</b>을 쓴다(BoxBrk::STEPWIN 주석) — 화면에 그려지는 계단 그대로다.
 */
function boxbrk_boxes(array $s): array
{
    $n = count($s);
    $dq = [];              // 단조 덱 (거래대금 내림차순 인덱스) — O(n)
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        while ($dq && $dq[0] < $i - (BoxBrk::STEPWIN - 1)) array_shift($dq);
        $rollMax = $dq ? (float)$s[$dq[0]]['amt'] : 0.0;      // ★i «이전» 120봉의 최고
        $a = (float)$s[$i]['amt'];
        if ($i >= BoxBrk::STEPWIN && $a >= BoxBrk::STEPAMT && $a > $rollMax
            && boxbrk_day_kind($s[$i]) === 'ok') {
            /* ★amt 를 함께 싣는다 — 「이전 박스 100억↑」 게이트가 이 값을 본다(PRIOR_BOX_AMT) */
            $out[] = ['i' => $i, 'H' => (float)$s[$i]['h'], 'L' => (float)$s[$i]['l'], 'amt' => $a];
        }
        while ($dq && (float)$s[end($dq)]['amt'] <= $a) array_pop($dq);
        $dq[] = $i;
    }
    return $out;
}

/** 그 봉이 «최고 거래대금 신호»인가 — 모집단 판정 (점수 이전) */
function boxbrk_is_signal(array $s, int $i): bool
{
    if ($i < BoxBrk::WIN) return false;
    $a = (float)$s[$i]['amt'];
    if ($a < BoxBrk::MINAMT) return false;
    $mx = 0.0;
    for ($k = $i - (BoxBrk::WIN - 1); $k < $i; $k++) $mx = max($mx, (float)$s[$k]['amt']);
    return $a > $mx;
}

/**
 * 하드 배제 — 통과해야 점수를 매긴다. <b>이제 등락 하나뿐</b>이다(BoxBrk::CHG_MAX 주석).
 * ★`$x` 를 안 쓰지만 인자로 남긴다 — 부르는 쪽 셋이 이미 그렇게 부르고 있고,
 *   조건이 다시 늘어날 때 서명을 또 바꾸지 않으려는 것이다.
 */
function boxbrk_hard_ok(array $x, array $meta): bool
{
    return $meta['chg'] < BoxBrk::CHG_MAX;
}

/**
 * 신규 박스가 기존 박스보다 «위»인가 — 저가·고가 <b>둘 다</b>(BoxBrk::REQUIRE_HIGHER_BOX 주석).
 * ★판정을 한 곳에만 둔다 — 스캔과 정리 잡(`job=boxn`)이 같은 것을 봐야 한다.
 */
function boxbrk_higher_box(array $meta): bool
{
    if (!BoxBrk::REQUIRE_HIGHER_BOX) return true;
    if ($meta['prevL'] === null || ($meta['prevHmax'] ?? null) === null) return false;
    /* ★H 는 «직전 박스 N개 전부»와 견준다 — 하나만 보면 「한 단 내려선 뒤 그 위」가 샌다(야스 255440) */
    return $meta['curL'] > $meta['prevL'] && $meta['curH'] > $meta['prevHmax'];
}

/** 점수(로그오즈). 클수록 「담고 싶어지는 모양」. */
function boxbrk_score(array $x): float
{
    $z = BoxBrk::B0;
    foreach (BoxBrk::W as $k => [$w, $mu, $sd]) {
        if (!isset($x[$k])) throw new RuntimeException("boxbrk_score: 축 {$k} 이 없다");
        $z += $w * (($x[$k] - $mu) / max(1e-9, $sd));
    }
    return $z;
}

/** 점수 → 「그 날이었다면 담으셨을 확률」 */
function boxbrk_prob(float $z): float { return 1 / (1 + exp(-max(-30, min(30, $z)))); }

/** 등급 — 화면의 어휘도 여기서만 정한다 */
function boxbrk_grade(float $z): array
{
    if ($z >= BoxBrk::Z_Q95) return ['A', '상위 5%'];
    if ($z >= BoxBrk::Z_Q90) return ['B', '상위 10%'];
    if ($z >= BoxBrk::Z_Q75) return ['C', '상위 25%'];
    return ['D', '컷 아래'];
}

/**
 * 하루치 스캔 — 그 날의 후보를 전부 돌려준다.
 *
 * ★값싼 조건으로 먼저 좁힌다 — 그 날 거래대금 100억↑ 인 종목만 원장을 끌어온다
 *   (전종목 250일치를 끌면 75만 행이다. 실제로 필요한 것은 수백 종목뿐).
 *
 * @param ?float $minZ 점수 하한 — <b>기본은 null(=컷 없음)</b>이다. 담는 기준은 «박스 조건 셋»이고
 *   점수는 표시·정렬용이다(BoxBrk::Z_Q75 주석). 진단할 때만 값을 넘겨 좁혀 본다.
 * @return array [['code','d','x','meta','z','prob','grade'], …] — 점수 내림차순
 */
function boxbrk_scan(PDO $pdo, string $d, ?float $minZ = null): array
{
    // ① 그 날 거래대금이 하한을 넘은 종목 (인덱스 하나로 끝난다)
    $st = $pdo->prepare("SELECT code FROM krx_amt WHERE d = ? AND amt >= ? AND c > 0");
    $st->execute([$d, BoxBrk::MINAMT]);
    $codes = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$codes) return [];

    // ② 배제 — 우선주(끝자리≠0)·ETF·스팩·ETN. `dbrk`·`dbuild` 와 같은 자.
    $etf = [];
    try {
        foreach ($pdo->query("SELECT DISTINCT etf_code FROM all_etf_price")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $etf[$c] = 1;
        }
    } catch (Throwable $e) { /* ETF 표가 없어도 계속 — 조용히 생략 */ }
    $names = [];
    foreach ($pdo->query("SELECT stock_code, stock_name FROM all_stock_info")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $names[$r['stock_code']] = $r['stock_name'];
    }
    $keep = [];
    foreach ($codes as $c) {
        if (substr($c, -1) !== '0' || isset($etf[$c])) continue;
        $nm = (string)($names[$c] ?? '');
        if ($nm !== '' && (mb_strpos($nm, '스팩') !== false || stripos($nm, 'ETN') !== false)) continue;
        $keep[] = $c;
    }
    if (!$keep) return [];

    /* ③ 그 종목들만 원장을 끌어온다 — <b>이력 전체</b>다.
     *
     * ★★기간을 자르지 않는다. 「머리 위 계단」(stepsAbove)은 <b>그 종목의 모든 과거 박스</b>를
     *   세는 축이고, 학습도 그렇게 했다(krx_amt 는 2019-01-02 부터다). 여기서 400일로 자르면
     *   계단이 평균 9.6개에서 2~3개로 조용히 줄어, <b>같은 종목·같은 날인데 점수가 달라진다</b>.
     *   계수·평균·표준편차가 한 벌인 이유가 이것이다(BoxBrk::W 주석).
     * ★그래서 종목 묶음을 작게 쪼갠다 — 한 종목이 ~1,900봉이라 200개씩 끌면 메모리를 크게 먹는다. */
    $out = [];
    foreach (array_chunk($keep, 80) as $ck) {
        $in = implode(',', array_fill(0, count($ck), '?'));
        $q = $pdo->prepare("SELECT code,d,o,h,l,c,vol,amt,mktcap FROM krx_amt
                             WHERE code IN ($in) AND d <= ? AND c > 0
                             ORDER BY code, d");
        $q->execute(array_merge($ck, [$d]));
        $bars = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) $bars[$b['code']][] = $b;

        foreach ($ck as $code) {
            $s = $bars[$code] ?? [];
            $n = count($s);
            if ($n < BoxBrk::HIST_MIN + 2) continue;
            $i = $n - 1;
            if ($s[$i]['d'] !== $d) continue;            // 그 날 봉이 마지막이어야 한다
            if (!boxbrk_is_signal($s, $i)) continue;

            $f = boxbrk_feat($s, $i, boxbrk_boxes($s));
            if ($f === null) continue;
            if (!boxbrk_hard_ok($f['x'], $f['meta'])) continue;
            /* ★뚫을 박스가 없으면 「박스 상향돌파」가 아니다 — 그 박스는 <b>100억↑</b>여야 한다
             *   (MIN_PRIOR_BOX · PRIOR_BOX_AMT 주석) */
            if ($f['meta']['boxN100'] < BoxBrk::MIN_PRIOR_BOX) continue;
            /* ★기존 박스에서 너무 멀리 떠 있으면 「돌파」가 아니다 (BOX_GAP_MAX 주석) */
            if ($f['meta']['gapPct'] === null
                || $f['meta']['gapPct'] > BoxBrk::BOX_GAP_MAX * 100) continue;
            /* ★신규 박스가 기존 박스보다 «위»여야 한다 — 아래로 내려선 것은 상향돌파가 아니다 */
            if (!boxbrk_higher_box($f['meta'])) continue;
            /* ★그 날 봉이 «밀어올린» 봉이어야 한다 — 시가→종가 (OC_MIN 주석) */
            if ($f['meta']['ocPct'] === null || $f['meta']['ocPct'] < BoxBrk::OC_MIN) continue;

            $z = boxbrk_score($f['x']);
            /* ★기본은 컷이 «없다» — 담는 기준은 박스 조건 셋이다(BoxBrk::Z_Q75 주석) */
            if ($minZ !== null && $z < $minZ) continue;
            [$g, $gl] = boxbrk_grade($z);
            $out[] = ['code' => $code, 'd' => $d, 'name' => (string)($names[$code] ?? ''),
                      'x' => $f['x'], 'meta' => $f['meta'],
                      'z' => $z, 'prob' => boxbrk_prob($z), 'grade' => $g, 'grade_label' => $gl];
        }
    }
    usort($out, fn($a, $b) => $b['z'] <=> $a['z']);
    return $out;
}
?>
