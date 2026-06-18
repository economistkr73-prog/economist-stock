<?php
// naver_trend_api.php — 네이버 맛집/캠핑장/스테이 추이 데이터 API (module=naver)
//   공통 param: cat = food(맛집·기본) | stay(스테이) | camping(캠핑장)
//   action=cats      카테고리 목록             → {cats:[{key,label}]}
//   action=periods   수집 회차 목록(cat별)     → {periods:["2026-07","2026-06",...]}
//   action=list      한 회차 + 전월대비 델타   → {period,category,total,rows:[{naver_id,name,region,score,review,...,d_review,...}]}
//                       params: cat, period, region, min_review, sort, dir, limit, offset
//   action=series    한 곳 시계열              → {pid,series:[{period,category,score,review,visitor,blog,save}]}  (?pid= place_id)
//   action=mapcount  지도 기준 곳수            → {count}  (?mcat= place분류, ?min_review=)
//
// 규칙: 모든 응답 JSON. 빈/실패는 빈 구조 + 200, 진짜 에러만 {error}.
// 부트스트랩은 stock_analysis_api.php / place_api.php 패턴과 동일 (ob_start → 인증 → ob_clean → JSON).
ob_start();
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";

$action   = $_GET['action'] ?? $_POST['action'] ?? '';
$shareTok = $_GET['share'] ?? $_POST['share'] ?? '';

// ── 한시적 공개 공유: 유효 토큰이면 그 분류 읽기전용 액션만 비로그인 허용(쓰기·생성·취소는 로그인 필수) ──
$guestCat = null; $guestShare = null;
if ($shareTok !== '') {
    try {
        $col = new NaverPlaceCollector($pdo);
        $col->ensureTables();
        $sh = $col->getValidShare($shareTok);
        if ($sh) { $guestCat = $sh['cat']; $guestShare = $sh; }
    } catch (Throwable $e) { /* 무시 → 아래 로그인 게이트로 */ }
}
$readActions = ['cats', 'periods', 'list', 'series', 'regions', 'mapcount'];
$isGuest = ($guestCat !== null);
if (!($isGuest && in_array($action, $readActions, true))) require_login();

ob_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($col)) {
        $col = new NaverPlaceCollector($pdo);
        $col->ensureTables();
    }

    $category = $isGuest
        ? NaverPlaceCollector::cat((string)$guestCat)                 // 게스트는 토큰 분류로 고정
        : NaverPlaceCollector::cat((string)($_GET['cat'] ?? $_POST['cat'] ?? 'food'));

    switch ($action) {
        case 'cats': {
            $cats = [];
            foreach (NaverPlaceCollector::CATS as $k => $c) $cats[] = ['key' => $k, 'label' => $c['label']];
            echo json_encode(['cats' => $cats], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'periods': {
            echo json_encode(['periods' => $col->periods($category)], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'regions': {   // 지역창 자동완성용 — 수집된 시군구 목록(가나다순)
            echo json_encode(['regions' => $col->regions($category)], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'list': {
            $periods = $col->periods($category);
            $period  = (string)($_GET['period'] ?? '');
            if ($period === '' && $periods) $period = $periods[0];   // 기본 = 최신 회차
            $r = $col->trendList([
                'period'     => $period,
                'category'   => $category,
                // 게스트(공유)는 공유시점 지역으로 고정(클라이언트 변조 방지)
                'region'     => $isGuest ? (string)($guestShare['region'] ?? '') : trim((string)($_GET['region'] ?? '')),
                'min_review' => (int)($_GET['min_review'] ?? 0),
                'sort'       => (string)($_GET['sort'] ?? 'd_review'),
                'dir'        => (string)($_GET['dir'] ?? 'desc'),
                'limit'      => (int)($_GET['limit'] ?? 100),
                'offset'     => (int)($_GET['offset'] ?? 0),
            ]);
            $r['category'] = $category;
            $r['periods'] = $periods;
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'series': {
            $pid = (int)($_GET['pid'] ?? $_GET['place_id'] ?? 0);
            echo json_encode(['pid' => $pid, 'series' => $pid > 0 ? $col->series($pid) : []], JSON_UNESCAPED_UNICODE);
            break;
        }
        // 지도 표시 기준(분류+최소리뷰)에 해당하는 place 마커 총 곳수 — '지도 기준' 버튼 옆 고정 표시용.
        case 'mapcount': {
            $mcat = trim((string)($_GET['mcat'] ?? ''));
            $mr   = max(0, (int)($_GET['min_review'] ?? 0));
            $allowed = ['travel', 'stay', 'restaurant', 'camping', 'etc'];
            $w = ["is_active = 1", "geocode_status = 'ok'"];
            $a = [];
            if ($mcat !== '' && in_array($mcat, $allowed, true)) { $w[] = "category = :c"; $a[':c'] = $mcat; }
            if ($mr > 0) {
                $w[] = "(review_count IS NULL OR review_count >= :mr
                         OR EXISTS (SELECT 1 FROM place_guide g WHERE g.place_id = place.id))";
                $a[':mr'] = $mr;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM place WHERE " . implode(' AND ', $w));
            $st->execute($a);
            echo json_encode(['count' => (int)$st->fetchColumn()], JSON_UNESCAPED_UNICODE);
            break;
        }
        // ── 공유 링크 (소유자 전용 — 위 readActions 에 없어 require_login 통과 필요) ──
        case 'share_create': {   // 현재 분류 공유 링크 발급(공유시점 지역·필터 고정). ttl(초) 기본 7일.
            $ttl = (int)($_POST['ttl'] ?? $_GET['ttl'] ?? (7 * 24 * 3600));
            $sh  = $col->createShare($category, $ttl, [
                'region'     => (string)($_POST['region'] ?? $_GET['region'] ?? ''),
                'min_review' => (int)($_POST['min_review'] ?? $_GET['min_review'] ?? 0),
                'sort'       => (string)($_POST['sort'] ?? $_GET['sort'] ?? 'total_score'),
            ]);
            echo json_encode(['ok' => true] + $sh, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'share_status': {   // 현재 (분류,지역) 활성 공유 1건
            $reg = (string)($_POST['region'] ?? $_GET['region'] ?? '');
            $sh  = $col->getActiveShare($category, $reg);
            echo json_encode(['ok' => true, 'token' => $sh['token'] ?? null,
                              'expires_at' => $sh['expires_at'] ?? null,
                              'region' => $sh['region'] ?? '', 'min_review' => (int)($sh['min_review'] ?? 0),
                              'sort' => $sh['sort'] ?? 'total_score'], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'share_revoke': {   // 현재 (분류,지역) 공유만 즉시 중단(다른 지역 링크는 유지)
            $reg = (string)($_POST['region'] ?? $_GET['region'] ?? '');
            $col->revokeShares($category, $reg);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['error' => "unknown action: {$action}"], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
