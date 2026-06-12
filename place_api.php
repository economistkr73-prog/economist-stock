<?php
// place_api.php — 전국 여행지 DB API (module=place)
//   action=search   좌표 반경 검색 → GeoJSON FeatureCollection
//   action=refs     마커 클릭 시 그 장소의 출처자료 링크 목록
//   action=geocode  주소 → 좌표 (지도 검색창용, GeoCoder 네이버 forward 재사용)
//   action=seed_demo 테스트용 더미 장소 적재 (개발 확인용)
//
// 부트스트랩은 schedule_api.php 패턴과 동일 (ob_start → 인증 → ob_clean → JSON).
ob_start(); // included 파일의 stray output 방지
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";

// 지오코딩 키 (없으면 GeoCoder 가 안내 메시지 반환)
if (file_exists("./env/maps.inc"))  require_once "./env/maps.inc";
if (file_exists("./env/kakao.inc")) require_once "./env/kakao.inc";

$placeBoot = new Place($pdo);
$placeBoot->ensureTable();

// 인증: 유효한 공유 토큰이면 게스트(읽기전용), 아니면 로그인 필요
$shareToken = trim((string)($_GET['share'] ?? $_POST['share'] ?? ''));
$isGuest = $shareToken !== '' && $placeBoot->getValidShare($shareToken) !== null;
if (!$isGuest) require_login();

ob_clean(); // stray output 제거 후 JSON 헤더 출력
header('Content-Type: application/json; charset=utf-8');

$module = $_GET['module'] ?? $_POST['module'] ?? 'place';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($module) {
        case 'place': api_place($action, $pdo, $isGuest); break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown module: {$module}"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ==========================================================
// place 모듈
// ==========================================================
function api_place(string $action, PDO $pdo, bool $isGuest = false): void
{
    $place = new Place($pdo);

    // 게스트(공유 링크)는 읽기전용 — 검색/출처/지오코딩/자동완성/태그검색만 허용
    if ($isGuest && !in_array($action, ['search', 'refs', 'geocode', 'suggest', 'tag_list', 'tag_search'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'forbidden']);
        return;
    }

    switch ($action) {
        // ── 반경 검색 → GeoJSON ──────────────────────────
        case 'search': {
            if (!isset($_GET['lat'], $_GET['lng'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'lat, lng 가 필요합니다.']);
                return;
            }
            $lat      = (float)$_GET['lat'];
            $lng      = (float)$_GET['lng'];
            $radius   = (float)($_GET['radius'] ?? 5);
            $radius   = max(0.1, min(50.0, $radius));          // 0.1~50km 클램프
            $category = trim((string)($_GET['category'] ?? ''));
            $keyword  = trim((string)($_GET['keyword'] ?? ''));

            $allowed = ['travel', 'event', 'restaurant', 'etc'];
            if ($category !== '' && !in_array($category, $allowed, true)) {
                $category = '';
            }

            $geojson = $place->searchNearby(
                $lat, $lng, $radius,
                $category !== '' ? $category : null,
                $keyword  !== '' ? $keyword  : null
            );
            echo json_encode($geojson, JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 마커 클릭 → 출처자료 링크 목록 ───────────────
        case 'refs': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'id 가 필요합니다.']);
                return;
            }
            echo json_encode(['ok' => true, 'refs' => $place->getRefs($id)], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 미좌표(좌표 못 찾은) 장소 목록 — 지도에 안 뜨는 건 열람용 ──
        case 'ungeocoded': {
            $status = (string)($_GET['status'] ?? 'failed');
            $items  = $place->listUngeocoded($status, 3000);
            echo json_encode(['ok' => true, 'total' => count($items), 'status' => $status, 'items' => $items], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 미좌표 장소 수정 (이름/분류 변경 + 좌표 직접 지정) ──
        case 'place_update': {
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'id 가 필요합니다.']);
                return;
            }
            $name     = trim((string)($_GET['name']     ?? $_POST['name']     ?? ''));
            $category = trim((string)($_GET['category'] ?? $_POST['category'] ?? ''));
            $hasCoord = (isset($_GET['lat']) || isset($_POST['lat'])) && (isset($_GET['lng']) || isset($_POST['lng']));
            $lat = $hasCoord ? (float)($_GET['lat'] ?? $_POST['lat']) : null;
            $lng = $hasCoord ? (float)($_GET['lng'] ?? $_POST['lng']) : null;
            $ok = $place->adminUpdate(
                $id,
                $name     !== '' ? $name     : null,
                $category !== '' ? $category : null,
                $lat, $lng
            );
            echo json_encode(['ok' => $ok, 'geocoded' => $hasCoord], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 멀티 등록: 같은 기사를 공유하는 새 장소 추가 (소유자 전용) ──
        //  한 기사가 여러 장소를 다룰 때, 원본(src_id)의 출처자료를 복제 연결한 새 place 생성
        case 'place_add': {
            $srcId    = (int)($_GET['src_id'] ?? $_POST['src_id'] ?? 0);
            $name     = trim((string)($_GET['name']     ?? $_POST['name']     ?? ''));
            $category = trim((string)($_GET['category'] ?? $_POST['category'] ?? 'travel'));
            $hasCoord = (isset($_GET['lat']) || isset($_POST['lat'])) && (isset($_GET['lng']) || isset($_POST['lng']));
            if ($name === '' || !$hasCoord) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => '이름과 좌표가 필요합니다.']);
                return;
            }
            $lat   = (float)($_GET['lat'] ?? $_POST['lat']);
            $lng   = (float)($_GET['lng'] ?? $_POST['lng']);
            $newId = $place->addLinkedPlace($srcId, $name, $category, $lat, $lng);
            echo json_encode(['ok' => $newId > 0, 'id' => $newId], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 장소 삭제 (소유자 전용) — place_ref 는 FK CASCADE 로 함께 삭제 ──
        case 'place_delete': {
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'id 가 필요합니다.']);
                return;
            }
            $n = $place->deletePlace($id);
            echo json_encode(['ok' => $n > 0, 'deleted' => $n], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 태그 조회/저장/자동완성 (소유자 전용) ──────────
        case 'place_tags': {            // 한 장소의 태그 {kind,tag}[]
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            echo json_encode(['ok' => true, 'items' => $place->getTags($id)], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'tag_set': {               // 장소 태그 전체 교체. tags = JSON [{kind,tag}]
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'id 가 필요합니다.']);
                return;
            }
            $raw  = (string)($_POST['tags'] ?? $_GET['tags'] ?? '[]');
            $tags = json_decode($raw, true);
            if (!is_array($tags)) $tags = [];
            $place->setTags($id, $tags);
            echo json_encode(['ok' => true, 'items' => $place->getTags($id)], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'tag_list': {              // 태그 목록(자동완성·상단 칩바). {tag,kind,cnt} 빈도순
            echo json_encode(['ok' => true, 'items' => $place->listTags(500)], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'tag_search': {            // 태그(들) AND 검색 → GeoJSON. tags=콤마구분, 또는 단일 tag
            $raw  = (string)($_GET['tags'] ?? $_POST['tags'] ?? $_GET['tag'] ?? $_POST['tag'] ?? '');
            $tags = array_filter(array_map('trim', explode(',', $raw)), fn($t) => $t !== '');
            echo json_encode($place->searchByTags($tags), JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'tag_rename': {            // 태그 이름변경=병합. from → to
            $from = trim((string)($_POST['from'] ?? $_GET['from'] ?? ''));
            $to   = trim((string)($_POST['to']   ?? $_GET['to']   ?? ''));
            if ($from === '' || $to === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'from/to 가 필요합니다.']);
                return;
            }
            $n = $place->renameTag($from, $to);
            echo json_encode(['ok' => true, 'moved' => $n, 'items' => $place->listTags(500)], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'tag_delete': {            // 태그 완전 삭제
            $tag = trim((string)($_POST['tag'] ?? $_GET['tag'] ?? ''));
            if ($tag === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'tag 가 필요합니다.']);
                return;
            }
            $n = $place->deleteTag($tag);
            echo json_encode(['ok' => true, 'deleted' => $n, 'items' => $place->listTags(500)], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 자동완성 후보 (카카오 키워드 장소검색) ────────
        //  지도앱식 검색창: 입력 중 장소명/POI 후보 목록을 드롭다운에 표시
        case 'suggest': {
            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'items' => []]); return; }
            $kw = GeoCoder::searchKeyword($q, 7);
            echo json_encode([
                'ok'    => !empty($kw['ok']),
                'items' => $kw['items'] ?? [],
                'msg'   => $kw['msg'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 주소/장소명 → 좌표 (검색창 Enter/버튼 시 지도 이동) ──
        //  cascade: ① 카카오 키워드(장소명·POI) ② 네이버 주소 지오코딩 ③ 우리 DB 장소명
        case 'geocode': {
            $address = trim((string)($_GET['address'] ?? $_POST['address'] ?? ''));
            $r = ['ok' => false, 'msg' => '주소를 찾을 수 없습니다'];

            $kw = GeoCoder::searchKeyword($address, 1);          // ① 장소명 우선
            if (!empty($kw['ok']) && !empty($kw['items'])) {
                $top = $kw['items'][0];
                $r = ['ok' => true, 'lat' => $top['lat'], 'lng' => $top['lng'],
                      'address' => $top['name'], 'source' => 'kakao', 'msg' => ''];
            } else {
                $r = GeoCoder::geocode('naver', $address);       // ② 정식 주소
                if (!empty($r['ok'])) {
                    $r['source'] = 'geocode';
                } else {
                    $hit = $place->findByName($address);         // ③ 우리 DB 장소명
                    if ($hit) {
                        $r = ['ok' => true, 'lat' => (float)$hit['lat'], 'lng' => (float)$hit['lng'],
                              'address' => $hit['name'], 'source' => 'db', 'msg' => ''];
                    }
                }
            }
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 한시적 공개 공유 링크 (소유자 전용) ──────────
        case 'share_create': {
            $ttl = (int)($_GET['ttl'] ?? $_POST['ttl'] ?? 86400); // 기본 1일
            $sh  = $place->createShare($ttl);
            echo json_encode(['ok' => true, 'token' => $sh['token'], 'expires_at' => $sh['expires_at']], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'share_status': {
            $sh = $place->getActiveShare();
            echo json_encode(['ok' => true, 'token' => $sh['token'] ?? null, 'expires_at' => $sh['expires_at'] ?? null], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'share_revoke': {
            $place->revokeShares();
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 개발용 더미 적재 ─────────────────────────────
        case 'seed_demo': {
            echo json_encode(seed_demo($place), JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 개발용: 좌표 없는(pending) 더미 — 지오코딩 배치 테스트용 ─
        case 'seed_pending': {
            echo json_encode(seed_pending($place), JSON_UNESCAPED_UNICODE);
            break;
        }

        // ── 데모/시드 더미 정리 (아덴트뉴스 크롤 데이터는 보존) ─
        case 'seed_clear': {
            $n = $pdo->exec(
                "DELETE FROM place
                  WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.source_site')), '') <> 'ardentnews'"
            );
            echo json_encode(['ok' => true, 'deleted' => (int)$n, 'msg' => '데모/시드 더미 삭제(아덴트뉴스 보존)'], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
    }
}

// ==========================================================
// 개발용 더미 데이터 (좌표 포함 → 바로 지도에 보임)
// dedup_key·url_hash 덕에 여러 번 호출해도 중복 안 쌓임
// ==========================================================
function seed_demo(Place $place): array
{
    $items = [
        [
            'name' => '경포대', 'category' => 'travel',
            'address' => '강원 강릉시 경포로 365', 'region_lv1' => '강원', 'region_lv2' => '강릉시',
            'lat' => 37.7956, 'lng' => 128.8966,
            'attributes' => ['tags' => ['해변', '일출', '드라이브']],
            'ref' => ['source_type' => 'article', 'title' => '강릉 경포대 가볼만한 곳',
                      'url' => 'https://example.com/gyeongpo', 'summary' => '동해 대표 명소',
                      'published_at' => '2025-04-10', 'extra' => ['press' => 'OO일보']],
        ],
        [
            'name' => '강릉 OO막국수', 'category' => 'restaurant',
            'address' => '강원 강릉시 난설헌로 1', 'region_lv1' => '강원', 'region_lv2' => '강릉시',
            'lat' => 37.7512, 'lng' => 128.8761, 'phone' => '033-000-0000',
            'attributes' => ['price' => '1만원대', 'tags' => ['막국수', '로컬']],
            'ref' => ['source_type' => 'youtube', 'title' => '강릉 먹방 - 막국수편',
                      'url' => 'https://youtu.be/demo-makguksu', 'summary' => '조회수 30만',
                      'published_at' => '2024-08-10', 'extra' => ['channel' => 'OO먹방', 'views' => 300000]],
        ],
        [
            'name' => '설악산 국립공원', 'category' => 'travel',
            'address' => '강원 속초시 설악산로 833', 'region_lv1' => '강원', 'region_lv2' => '속초시',
            'lat' => 38.1190, 'lng' => 128.4655,
            'attributes' => ['tags' => ['등산', '단풍', '케이블카']],
            'ref' => ['source_type' => 'blog', 'title' => '설악산 단풍 명소',
                      'url' => 'https://example.com/seorak', 'published_at' => '2024-10-20'],
        ],
        [
            'name' => '여의도 봄꽃축제', 'category' => 'event',
            'address' => '서울 영등포구 여의동로 330', 'region_lv1' => '서울', 'region_lv2' => '영등포구',
            'lat' => 37.5283, 'lng' => 126.9320,
            'period_start' => '2026-04-04', 'period_end' => '2026-04-12',
            'attributes' => ['tags' => ['벚꽃', '봄축제']],
            'ref' => ['source_type' => 'official', 'title' => '여의도 봄꽃축제 안내',
                      'url' => 'https://example.com/yeouido-festival', 'published_at' => '2026-03-15'],
        ],
        [
            'name' => '전주 한옥마을', 'category' => 'travel',
            'address' => '전북 전주시 완산구 기린대로 99', 'region_lv1' => '전북', 'region_lv2' => '전주시',
            'lat' => 35.8150, 'lng' => 127.1530,
            'attributes' => ['tags' => ['한옥', '비빔밥', '야경']],
            'ref' => ['source_type' => 'article', 'title' => '전주 한옥마을 1박2일',
                      'url' => 'https://example.com/jeonju-hanok', 'published_at' => '2025-05-01'],
        ],
        [
            'name' => '해운대 해수욕장', 'category' => 'travel',
            'address' => '부산 해운대구 해운대해변로 264', 'region_lv1' => '부산', 'region_lv2' => '해운대구',
            'lat' => 35.1587, 'lng' => 129.1604,
            'attributes' => ['tags' => ['해변', '야경', '회']],
            'ref' => ['source_type' => 'youtube', 'title' => '부산 해운대 브이로그',
                      'url' => 'https://youtu.be/demo-haeundae', 'published_at' => '2025-07-22',
                      'extra' => ['channel' => '여행유튜버']],
        ],
    ];

    $placeCnt = 0; $refCnt = 0; $makguksuId = 0;
    foreach ($items as $it) {
        $r = $place->ingest($it);
        if ($r['place_id'] > 0) $placeCnt++;
        if ($r['ref_added'])    $refCnt++;
        if ($it['name'] === '강릉 OO막국수') $makguksuId = $r['place_id'];
    }
    // 같은 장소(막국수)에 출처 1건 더 — place 1:N 데모.
    // 루프에서 받은 id 를 재사용(재upsert 하지 않음 → category 등 기존값 안 건드림)
    if ($makguksuId > 0 && $place->addRef($makguksuId, [
        'source_type' => 'article', 'title' => '강릉 막국수 맛집 베스트',
        'url' => 'https://example.com/makguksu-best', 'summary' => '현지인 추천 1순위',
        'published_at' => '2025-05-01',
    ])) $refCnt++;

    return ['ok' => true, 'msg' => "더미 적재 완료", 'places' => $placeCnt, 'refs_added' => $refCnt];
}

// ==========================================================
// 개발용: 좌표 없이 주소만 있는 더미 (geocode_status=pending)
// → cron_place_geocode.php 로 좌표화 테스트. 적재 직후엔 지도에 안 뜸(ok 아님)
// ==========================================================
function seed_pending(Place $place): array
{
    $items = [
        ['name' => '남이섬', 'category' => 'travel',
         'address' => '강원특별자치도 춘천시 남산면 남이섬길 1', 'region_lv1' => '강원', 'region_lv2' => '춘천시',
         'ref' => ['source_type' => 'blog', 'title' => '남이섬 당일치기',
                   'url' => 'https://example.com/namiseom', 'published_at' => '2025-04-01']],
        ['name' => '오대산 월정사', 'category' => 'travel',
         'address' => '강원특별자치도 평창군 진부면 오대산로 374-8', 'region_lv1' => '강원', 'region_lv2' => '평창군',
         'ref' => ['source_type' => 'article', 'title' => '월정사 전나무숲길',
                   'url' => 'https://example.com/woljeongsa', 'published_at' => '2024-11-05']],
        ['name' => '안목해변 커피거리', 'category' => 'travel',
         'address' => '강원특별자치도 강릉시 창해로14번길 7', 'region_lv1' => '강원', 'region_lv2' => '강릉시',
         'ref' => ['source_type' => 'youtube', 'title' => '강릉 안목 카페투어',
                   'url' => 'https://example.com/anmok', 'published_at' => '2025-06-01']],
    ];
    $cnt = 0;
    foreach ($items as $it) {
        // 좌표 미지정 → upsertPlace 가 geocode_status='pending' 으로 적재
        $r = $place->ingest($it);
        if ($r['place_id'] > 0) $cnt++;
    }
    return ['ok' => true, 'msg' => "pending 더미 적재 완료 (좌표X) — cron_place_geocode.php 로 좌표화하세요",
            'places' => $cnt];
}
?>
