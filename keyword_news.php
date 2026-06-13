<?php
// ===========================================================================
// 키워드 뉴스 공개 뷰어 — 로그인 없이 네이버 키워드 뉴스를 보여준다.
// 데일리 리포트 공개 공유(analysis_model.php?mode=daily&k=...)의 키워드 링크가 호출한다.
// DB를 쓰지 않으므로 cnt.inc(무거운 DB 부트스트랩) 없이 동작한다.
//   호출 형식: keyword_news.php?k=<토큰>&keyword=<키워드>
// ===========================================================================
require_once "./env/share_key.inc";      // DAILY_SHARE_KEY
require_once "./env/keyword_news.inc";    // highlight_keyword(), get_keyword_news_by_naver()

// 공개 공유 토큰 검증 — 데일리 리포트와 동일 키만 허용(크롤 프록시 남용 방지)
if (!isset($_GET['k']) || !hash_equals(DAILY_SHARE_KEY, (string)$_GET['k'])) {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/html; charset=utf-8');
get_keyword_news_by_naver();
?>
