<?php
// cron_rise_analysis.php — 상승확률 분석 일일 자동 실행 (매일 저녁 20시 크론)
//   호출 예시: https://economist.kr/cron_rise_analysis.php?ssk=mysn1973!
//   휴장일/장 마감 전(최신 일봉이 오늘이 아님)에는 RiseAnalyzer 가드가 자동 스킵.
//   하루 두 번 호출돼도 멱등(당일 신호 DELETE 후 재삽입, 채점은 graded 플래그로 1회).

$secret_key = "mysn1973!";

if (!isset($_GET['ssk']) || $_GET['ssk'] !== $secret_key) {
    die("접근 권한이 없습니다.");
}

require_once "env/cnt.inc";
if (file_exists("env/kakao.inc")) require_once "env/kakao.inc"; // 카카오 알림(선택)

error_reporting(E_ALL & ~E_NOTICE);
ini_set("display_errors", 1);
set_time_limit(0);
ignore_user_abort(true);

// 즉시 200 OK 전송 후 연결 종료 (cron-job.org 타임아웃 회피) → 이후 백그라운드로 분석 진행
ob_start();
echo "OK";
$size = ob_get_length();
header("Content-Length: $size");
header("Connection: close");
ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

try {
    $ra  = new RiseAnalyzer($pdo);
    $res = $ra->runDaily(false);   // echo=false (크론은 화면 출력 불필요)

    // 휴장일/장 마감 전 — 저장 스킵
    if (!empty($res['skipped'])) {
        if (class_exists('Notify')) {
            Notify::send("[상승확률] {$res['date']} 스킵 — {$res['reason']}");
        }
        exit;
    }

    $msg = "📈 상승확률 분석 완료 ({$res['date']})\n"
         . "🎯 신호 종목 {$res['breakouts']}개 (대량동반 핵심 {$res['signals']}개)\n"
         . "전종목 스캔 {$res['scanned']} · 후보 {$res['universe']}\n"
         . "과거 채점: 3일 {$res['graded']} · 재테스트 {$res['retested']}\n"
         . "👉 https://economist.kr/rise_analysis.php";

    if (class_exists('Notify')) {
        Notify::send($msg, "https://economist.kr/rise_analysis.php");
    }

} catch (Throwable $e) {
    if (class_exists('Notify')) {
        Notify::send("⚠️ 상승확률 분석 실패\n" . $e->getMessage());
    }
}
?>
