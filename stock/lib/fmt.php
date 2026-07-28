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

/** 색상 클래스가 붙은 수익률 셀 내용 */
function pf_signed_pct($v, int $dec = 2): string
{
    if ($v === null || $v === '') return '<span class="flat">-</span>';
    return '<span class="' . pf_updown($v) . '">' . pf_arrow($v) . ' ' . pf_h(pf_pct($v, $dec)) . '</span>';
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
