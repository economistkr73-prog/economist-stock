<?php
/**
 * stock/lib/fmt.php — 숫자/색상 포맷터 (순수 함수)
 *
 * 색상 규칙은 한국식: 상승·이익 = 빨강 ▲ / 하락·손실 = 파랑 ▼  (요건정의서 §4.1)
 */

/** HTML 이스케이프 */
function pf_h($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 정수 콤마. null 이면 대시 */
function pf_n($v, string $null = '-'): string
{
    if ($v === null || $v === '') return $null;
    return number_format((float)$v);
}

/** 소수 n자리 콤마 */
function pf_n2($v, int $dec = 2, string $null = '-'): string
{
    if ($v === null || $v === '') return $null;
    return number_format((float)$v, $dec);
}

/** 백만원 단위 (대시보드 금액 압축 표시) */
function pf_mil($v, int $dec = 1, string $null = '-'): string
{
    if ($v === null || $v === '') return $null;
    return number_format((float)$v / 1e6, $dec);
}

/**
 * 원 단위 금액 → 억원. 재무제표는 조 단위까지 가서 원으로 찍으면 자릿수를 셀 수 없다.
 * 적자는 파란색(한국식)으로 구분한다.
 */
function pf_eok($v, string $null = '-'): string
{
    if ($v === null || $v === '') return $null;
    $e = (float)$v / 100000000;
    $s = number_format($e, abs($e) < 100 ? 1 : 0);
    return ($e < 0) ? '<span class="down">' . $s . '</span>' : $s;
}

/** 비율 → % 문자열. 부호 포함 */
function pf_pct($v, int $dec = 2, string $null = '-'): string
{
    if ($v === null || $v === '') return $null;
    return sprintf('%+.' . $dec . 'f%%', (float)$v * 100);
}

/** 부호 없는 % (비중·하락률 입력 표시용) */
function pf_pct0($v, int $dec = 2, string $null = '-'): string
{
    if ($v === null || $v === '') return $null;
    return number_format((float)$v * 100, $dec) . '%';
}

/** 한국식 등락 색상 클래스: 양수 up(빨강) / 음수 down(파랑) / 0·null flat */
function pf_updown($v): string
{
    if ($v === null || $v === '') return 'flat';
    if ((float)$v > 0) return 'up';
    if ((float)$v < 0) return 'down';
    return 'flat';
}

/** 등락 화살표 */
function pf_arrow($v): string
{
    if ($v === null || $v === '') return '';
    if ((float)$v > 0) return '▲';
    if ((float)$v < 0) return '▼';
    return '';
}

/** 색상 클래스가 붙은 금액 셀 내용 */
function pf_signed($v, bool $mil = false): string
{
    if ($v === null || $v === '') return '<span class="flat">-</span>';
    $txt = $mil ? pf_mil($v) : pf_n(round((float)$v));
    return '<span class="' . pf_updown($v) . '">' . pf_h($txt) . '</span>';
}

/**
 * 색상 클래스가 붙은 수익률 셀 내용.
 *
 * ★ 화살표를 붙이지 않는다. `+`/`−` 부호가 이미 방향을 말하고 색(빨강/파랑)까지 더하면
 *   같은 정보를 세 번 말하는 셈이라 눈만 시끄러워진다. 부호 하나면 충분하다.
 *   변화량을 강조해야 하는 자리(증감률)는 pf_delta_pct() 가 <b>화살표 + 절대값</b>으로 따로 맡는다 —
 *   거기선 화살표가 부호를 <b>대신</b>하므로 중복이 아니다.
 */
function pf_signed_pct($v, int $dec = 2): string
{
    if ($v === null || $v === '') return '<span class="flat">-</span>';
    return '<span class="' . pf_updown($v) . '">' . pf_h(pf_pct($v, $dec)) . '</span>';
}

/**
 * <b>비율 그 자체</b>를 보여 주는 셀 — 영업이익률·순이익률·ROE 처럼 "얼마인가"를 읽는 값.
 *
 * 화살표와 + 부호를 붙이지 않는다. 영업이익률 25.74% 는 오른 것이 아니라 그냥 그 값이라,
 * ▲ 를 붙이면 "올랐다"로 잘못 읽힌다. 굵기도 빼서 옆의 금액과 같은 무게로 둔다.
 * 색(적자면 파랑)은 남긴다 — 음수 여부는 한눈에 보여야 한다.
 */
function pf_ratio_pct($v, int $dec = 2): string
{
    if ($v === null || $v === '') return '<span class="flat">-</span>';
    return '<span class="' . pf_updown($v) . '" style="font-weight:400">'
         . pf_h(number_format((float)$v * 100, $dec)) . '</span>';
}

/**
 * <b>증감률</b> 셀 — 전년(전년동기) 대비 얼마나 늘었나.
 *
 * 여기서는 화살표가 부호 노릇을 하므로 숫자는 <b>절대값</b>으로 쓴다 (▼ -44.68% 처럼 겹치지 않게).
 */
function pf_delta_pct($v, int $dec = 1): string
{
    if ($v === null || $v === '') return '<span class="flat">-</span>';
    return '<span class="' . pf_updown($v) . '">' . pf_arrow($v) . ' '
         . pf_h(number_format(abs((float)$v) * 100, $dec)) . '%</span>';
}

/**
 * <b>손익 항목</b>의 증감 셀 — 영업이익·순이익처럼 <b>음수가 될 수 있는</b> 값 전용.
 *
 * 부호가 뒤집히면 백분율이 뜻을 잃는다. 6.1억 → -59.7억을 "▼ 1,072%" 로 적으면
 * 숫자는 맞아도 읽는 사람은 아무것도 못 얻는다. 그래서 말로 바꾼다:
 *
 *   +  →  −   적자전환      −  →  +   흑자전환
 *   −  →  −   적자확대 / 적자축소     +  →  +   ▲▼ 00.0%
 *
 * ★ 적자끼리는 <b>크기를 견줘</b> 확대/축소를 가른다. −268 → −100 은 적자가 줄어든 것인데
 *   뭉뚱그려 "적자확대" 라 적으면 사실과 반대가 된다.
 * 색은 좋아졌으면 up(빨강), 나빠졌으면 down(파랑) 으로 통일한다.
 */
function pf_profit_delta($prev, $cur, int $dec = 1): string
{
    if ($prev === null || $prev === '' || $cur === null || $cur === '') {
        return '<span class="flat">-</span>';
    }
    $p = (float)$prev;
    $c = (float)$cur;

    $tag = static fn(string $cls, string $txt) => '<span class="' . $cls . '">' . $txt . '</span>';

    if ($p > 0 && $c > 0) {
        $g = $c / $p - 1;
        return '<span class="' . pf_updown($g) . '">' . pf_arrow($g) . ' '
             . pf_h(number_format(abs($g) * 100, $dec)) . '%</span>';
    }
    if ($p > 0 && $c <= 0) return $tag('down', '적자전환');
    if ($p <= 0 && $c > 0) return $tag('up',   '흑자전환');

    // 둘 다 흑자가 아니다 — 적자 크기로 가른다
    if ($c < $p) return $tag('down', '적자확대');
    if ($c > $p) return $tag('up',   '적자축소');
    return '<span class="flat">-</span>';          // 0 → 0 처럼 견줄 것이 없는 경우
}

/**
 * 「최초 진입가 = 100」 기준 <b>지수</b> + 괄호에 등락률.
 *
 * `-73.4%` 보다 `26.6 (-73.4%)` 가 읽기 쉽다 — 100 을 넣었으면 얼마가 되는지가 바로 보이고,
 * 실제 진입가에 곱해 보기도 쉽다(10만원이면 2.66만원). 룰셋의 가격·평균단가·탈출가가 이 꼴이다.
 *
 * @param float|null $factor 1차 진입가를 1.0 으로 본 배수 (0.266 → 26.6)
 */
function pf_idx($factor, int $dec = 1): string
{
    if ($factor === null || $factor === '') return '<span class="flat">-</span>';

    $f = (float)$factor;
    return '<b>' . number_format($f * 100, $dec) . '</b>'
         . ' <span class="muted" style="font-size:11px">('
         . sprintf('%+.1f%%', ($f - 1) * 100) . ')</span>';
}

/**
 * 사이클 나이 — 1년 미만은 일수로, 그 뒤는 년으로.
 * 「67일」과 「3.2년」이 한 열에 섞여야 짧은 것과 물린 것이 한눈에 갈린다.
 */
function pf_age_txt(?int $days): string
{
    if ($days === null) return '-';
    if ($days < 365)    return number_format($days) . '일';
    return number_format($days / 365.25, 1) . '년';
}

/** 포지션 상태 배지 */
function pf_status_badge(string $status): string
{
    $map = [
        'watch'  => ['관심', 'st-watch'],
        'open'   => ['보유', 'st-open'],
        'closed' => ['종료', 'st-closed'],
    ];
    [$label, $cls] = $map[$status] ?? ['?', 'st-watch'];
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}
?>
