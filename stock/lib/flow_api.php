<?php
/**
 * stock/lib/flow_api.php — 외인비 API (stock/api.php?module=flow&action=…) · 2026-10-08
 *
 * 응답은 전부 JSON {ok, data, error}. DB 는 classes/KwFlow.class 가 전담한다.
 *
 *   get   (읽기) code [years]   — 쌓여 있는 외인비 시계열 + 적재 상태 + 「받아도 되는 종목인가」
 *   pull  (POST) code [cursor]  — 키움에서 받아 쌓는다. 한 번에 KwFlow::PULL_CALLS 콜까지 — 화면이 done 이 올 때까지 이어 부른다
 *
 * ★받는 문은 여기 하나다(크론도, 차트를 여는 경로도 받지 않는다). 어느 종목이든 받을 수 있다(2026-10-08 사용자 결정 —
 *   처음엔 Top-Pick ∪ 보유만이었다). 다만 서버가 «실제 종목인가»는 본다 — 안 보면 이 문이 「아무 글자나 키움에 60콜씩 묻는 문」이 된다.
 * ★pull 은 POST 만 · 같은 출처에서 온 것만 받는다(관심그룹 API 와 같은 규칙).
 * ★DDL 을 하지 않는다 — 표가 없으면 get 은 빈 것을, pull 은 「표가 없다」를 돌려준다(만드는 것은 cron/dt_min.php job=flow).
 */
function api_flow(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $out = static function (bool $ok, $data = null, string $error = '') {
        echo json_encode(['ok' => $ok, 'data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    };
    $in  = $_POST + $_GET;
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '' && $raw[0] === '{') {
        $j = json_decode($raw, true);
        if (is_array($j)) $in = $j + $in;
    }
    $code = Dt::cleanCode(is_scalar($in['code'] ?? '') ? (string)($in['code'] ?? '') : '');
    if (strlen($code) !== 6) $out(false, null, '종목코드가 올바르지 않습니다.');

    $fl = new KwFlow($pdo);
    try {
        switch ($action) {
            case 'get': {
                $years = max(1, min(6, (int)($in['years'] ?? 5)));
                $from  = date('Y-m-d', strtotime("-{$years} years"));
                $rows  = [];
                foreach ($fl->series($code, $from) as $r) {
                    if ($r['for_rt'] === null) continue;
                    $rows[] = ['t' => (string)$r['d'], 'r' => (float)$r['for_rt'], 'n' => (int)$r['for_net']];
                }
                $out(true, ['code' => $code, 'allowed' => $fl->allowed($code), 'key' => (new Kiwoom($pdo))->hasKey(),
                            'status' => $fl->status($code), 'target' => KwFlow::TARGET_DAYS, 'rows' => $rows]);
            }

            case 'pull': {
                if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(false, null, 'POST 로 보내 주세요.');
                $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
                if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) {
                    $out(false, null, '다른 출처의 요청은 받지 않습니다.');
                }
                if (!$fl->allowed($code)) $out(false, null, '종목을 찾지 못했습니다 — 받을 수 없는 코드입니다.');
                if (!$fl->ready())        $out(false, null, '외인비 표(kw_flow)가 아직 없습니다.');
                $kw = new Kiwoom($pdo);
                if (!$kw->hasKey())       $out(false, null, '키움 인증키가 없어 받을 수 없습니다.');
                @set_time_limit(60);
                $cursor = is_scalar($in['cursor'] ?? '') ? (string)($in['cursor'] ?? '') : '';
                $out(true, $fl->pull($kw, $code, $cursor) + ['target' => KwFlow::TARGET_DAYS]);
            }

            default:
                $out(false, null, '알 수 없는 action 입니다.');
        }
    } catch (Throwable $e) {
        $out(false, null, '처리하지 못했습니다: ' . $e->getMessage());
    }
}
?>
