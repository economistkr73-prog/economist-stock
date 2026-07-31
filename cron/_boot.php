<?php
/**
 * cron/_boot.php — /cron/ 하위 스크립트 공통 부트스트랩. <b>env/cnt.inc 보다 먼저</b> 읽는다.
 *
 * 왜 필요한가
 *   이 폴더의 스크립트는 env 를 <b>$_SERVER['DOCUMENT_ROOT'] 기준</b>으로 읽는다
 *   (하위 폴더라 상대경로가 안 통한다 — stock/api.php · nw/api.php 와 같은 방식).
 *   그런데 <b>CLI 로 돌리면 DOCUMENT_ROOT 가 비어 있다</b>. 그대로 두면
 *   cnt.inc 를 못 찾고, 찾더라도 cnt.inc 의 클래스 오토로더가 같은 값으로
 *   /classes/*.class 를 찾으므로 첫 클래스에서 죽는다.
 *
 *   웹 루트는 이 파일의 <b>부모 디렉터리</b>다 (/cron 의 한 단계 위).
 *   ★ dirname(__DIR__) 이다 — __DIR__ 로 두면 DOCUMENT_ROOT 가 /cron 이 되어
 *     오토로더가 /cron/classes/ 를 뒤지다 죽는다.
 *
 * SSH 실행 예
 *   php cron/dart_collect.php job=quarter from=2016 to=2026
 *   php cron/place_geocode.php limit=500
 */
if (PHP_SAPI === 'cli') {
    if (empty($_SERVER['DOCUMENT_ROOT']))   $_SERVER['DOCUMENT_ROOT']   = dirname(__DIR__);
    if (empty($_SERVER['HTTP_USER_AGENT'])) $_SERVER['HTTP_USER_AGENT'] = 'cli';
    chdir(dirname(__DIR__));   // 웹 루트에서 돌린 것처럼 (남아 있을 수 있는 상대경로 대비)
}
?>
