<?php
/**
 * stock/lib/slowlog.php — 느린 렌더 계측 (2026-08-19)
 *
 * 「관심종목 첫 로딩 20~30초」처럼 재현이 안 되는 느림은 추측으로 못 잡는다 —
 * 정상일 때(실측 140ms)는 아무것도 안 남기고, 임계(PF_SLOWLOG_MS)를 넘은 요청만
 * 한 줄씩 파일에 남겨 다음 발생 때 「서버가 느렸나 / 어느 구간이었나」를 기록으로 답한다.
 *
 * 쓰는 법 — 부트스트랩에서 pf_slowlog_boot() 한 줄, 의심 구간 뒤에 pf_slowlog_mark('이름').
 * 마크는 「그 지점까지의 누적 ms」다 — 총 25,000ms 인데 ddl 마크가 24,800 이면 범인은 DDL 이다.
 *
 * ★로그 파일은 웹 루트 «밖»(chroot /economist73/slow_render.log)에 둔다 —
 *   env/ 는 .inc 만 403 이고 다른 확장자는 평문 서빙되는 것이 실측이라 www 안에는 두지 않는다.
 * ★DB 에 쓰지 않는다 — DB 잠금이 느림의 원인일 때 기록마저 같이 매달린다. 파일 append 가 안전하다.
 * ★t0 은 REQUEST_TIME_FLOAT — require·오토로더 등 이 파일이 실리기 «전» 시간까지 잡힌다.
 */

/** 이보다 오래 걸린 요청만 남긴다 (ms). 정상 140ms 와 사고 20,000ms 사이라 오탐이 없다. */
const PF_SLOWLOG_MS = 2000;

/** 로그 파일 — dirname(DOCUMENT_ROOT) 기준(웹 루트 밖). 이 상한을 넘으면 .old 로 밀고 새로 시작. */
const PF_SLOWLOG_FILE   = '/slow_render.log';
const PF_SLOWLOG_MAX_B  = 4194304;   // 4MB — 느린 요청만 남으므로 사실상 닿을 일이 없는 안전판

/**
 * 계측 시작 — 부트스트랩(require_login 직후)에서 한 번 부른다.
 * @param ?int $thresholdMs 임계 덮어쓰기(검증용). null 이면 PF_SLOWLOG_MS.
 */
function pf_slowlog_boot(?int $thresholdMs = null): void
{
    $GLOBALS['_pf_slowlog'] = [
        't0'    => (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
        'thr'   => $thresholdMs ?? PF_SLOWLOG_MS,
        'marks' => [],
    ];
    register_shutdown_function('pf_slowlog_flush');
}

/** 구간 마크 — 이름과 「여기까지 누적 ms」를 남긴다. boot 전이면 조용히 무시. */
function pf_slowlog_mark(string $name): void
{
    if (!isset($GLOBALS['_pf_slowlog'])) return;
    $GLOBALS['_pf_slowlog']['marks'][] = $name . ':'
        . (int)round((microtime(true) - $GLOBALS['_pf_slowlog']['t0']) * 1000);
}

/** shutdown 훅 — 임계를 넘었을 때만 한 줄 기록. 직접 부르지 않는다. */
function pf_slowlog_flush(): void
{
    $sl = $GLOBALS['_pf_slowlog'] ?? null;
    if (!$sl) return;
    $ms = (int)round((microtime(true) - $sl['t0']) * 1000);
    if ($ms < $sl['thr']) return;

    // 요청 식별 — 페이지는 mode, API 는 module·action 이 말해 준다
    $who = [];
    foreach (['mode', 'module', 'action'] as $k) {
        $v = $_GET[$k] ?? $_POST[$k] ?? '';
        if ($v !== '') $who[] = $k . '=' . preg_replace('/[^\w.-]/', '', (string)$v);
    }
    $line = sprintf("[%s] %dms %s %s marks=%s mem=%.1fMB uri=%s\n",
        date('Y-m-d H:i:s'),
        $ms,
        basename((string)($_SERVER['SCRIPT_NAME'] ?? (PHP_SAPI === 'cli' ? 'cli' : '?'))),
        $who ? implode(' ', $who) : '-',
        $sl['marks'] ? implode(',', $sl['marks']) : '-',
        memory_get_peak_usage(true) / 1048576,
        substr((string)($_SERVER['REQUEST_URI'] ?? '-'), 0, 200));

    $file = dirname((string)($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__, 2))) . PF_SLOWLOG_FILE;
    if (is_file($file) && filesize($file) > PF_SLOWLOG_MAX_B) @rename($file, $file . '.old');
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
?>
