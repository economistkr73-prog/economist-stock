<?php
/**
 * _seed_bluer_busan.php — 블루리본 서베이(부산) 맛집 샘플 적재 (1회용 throwaway)
 *
 *   place(category=restaurant) + place_ref(공식 출처) + place_guide(bluer)
 *   + place_tag(kind=cuisine, 음식 종류) 로 적재하고 주소를 즉시 지오코딩한다.
 *
 * 실행:  https://economist.kr/_seed_bluer_busan.php?key=econ-seed-bluer
 *        &dry=1  → 적재 없이 지오코딩 결과만 미리보기
 * 실행 후 서버에서 삭제(커밋 제외).
 */
require_once "./env/cnt.inc";
if (file_exists("./env/maps.inc"))  require_once "./env/maps.inc";
if (file_exists("./env/kakao.inc")) require_once "./env/kakao.inc";

header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'econ-seed-bluer') { http_response_code(403); exit("forbidden\n"); }

$dry  = ((int)($_GET['dry'] ?? 0) === 1);
$SRC  = 'https://bluer.co.kr/magazine/637';

// 이름, 음식종류, 주소 (블루리본 서베이: 전국의 맛집 2026년판)
$rows = [
    ['거대갈비',      '소갈비',   '부산광역시 해운대구 달맞이길 22'],
    ['거대곰탕',      '곰탕',     '부산광역시 부산진구 중앙대로 672'],
    ['남풍',          '일반중식', '부산광역시 해운대구 해운대해변로 296'],
    ['내당',          '한정식',   '부산광역시 동래구 금강공원로20번길 23'],
    ['동경밥상',      '일식장어', '부산광역시 수영구 남천바다로 34-6'],
    ['동남횟집',      '생선회',   '부산광역시 기장군 기장읍 공수해안길 15'],
    ['동래할매파전',  '파전',     '부산광역시 동래구 명륜로94번길 43-10'],
    ['동백섬횟집',    '생선회',   '부산광역시 해운대구 해운대해변로209번나길 17'],
    ['램지',          '프랑스식', '부산광역시 수영구 광안해변로284번길 38'],
    ['랩24바이쿠무다','프랑스식', '부산광역시 해운대구 송정광어골로 41'],
    ['레썽스',        '프랑스식', '부산광역시 수영구 광남로22번길 17'],
    ['르도헤',        '모던한식', '부산광역시 해운대구 마린시티3로 37'],
    ['머스트루',      '유럽식',   '부산광역시 해운대구 좌동순환로433번길 29'],
];

$place = new Place($pdo);
$place->ensureTable();

/** 주소 → 좌표: 네이버 주소 지오코딩 → 실패 시 카카오 키워드("부산 {이름}") */
function geo_one(string $address, string $name): array
{
    $r = GeoCoder::geocode('naver', $address);
    if (!empty($r['ok'])) return ['lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'via' => 'naver'];
    $kw = GeoCoder::searchKeyword('부산 ' . $name, 1);
    if (!empty($kw['ok']) && !empty($kw['items'])) {
        $t = $kw['items'][0];
        return ['lat' => (float)$t['lat'], 'lng' => (float)$t['lng'], 'via' => 'kakao'];
    }
    return ['lat' => null, 'lng' => null, 'via' => 'fail'];
}

$log = []; $ok = 0; $pending = 0;
foreach ($rows as $r) {
    [$name, $cuisine, $addr] = $r;
    $parts = preg_split('/\s+/', $addr);
    $r1 = '부산'; $r2 = $parts[1] ?? '';   // region_lv2 = 구/군

    $g = geo_one($addr, $name);
    $coordTxt = $g['lat'] !== null ? sprintf('%.5f, %.5f (%s)', $g['lat'], $g['lng'], $g['via']) : '좌표실패';
    $log[] = sprintf("%-14s | %-8s | %s | %s", $name, $cuisine, $addr, $coordTxt);

    if ($dry) continue;

    $res = $place->ingest([
        'name'       => $name,
        'category'   => 'restaurant',
        'address'    => $addr,
        'region_lv1' => $r1,
        'region_lv2' => $r2,
        'lat'        => $g['lat'],
        'lng'        => $g['lng'],
        'attributes' => ['source_site' => 'bluer'],
        'ref'        => [
            'source_type'  => 'official',
            'title'        => $name . ' — 블루리본 서베이(부산)',
            'url'          => $SRC,
            'published_at' => '2026-01-01',
        ],
    ]);
    $pid = $res['place_id'];
    if ($pid > 0) {
        $place->addGuide($pid, 'bluer', null);                       // 블루리본 등재(마커색)
        $place->addTags($pid, [
            ['kind' => 'cuisine', 'tag' => $cuisine],                 // 음식 종류
            ['kind' => 'grade',   'tag' => '리본2'],                   // 등급 = #리본2 태그
        ]);
        if ($g['lat'] !== null) $ok++; else $pending++;
    }
}

$log[] = "";
if ($dry) {
    $log[] = "=== dry-run: 적재 안 함 (지오코딩 결과만) ===";
} else {
    $log[] = "=== 적재 완료: 좌표 ok {$ok}곳 / pending {$pending}곳 (pending 은 cron_place_geocode.php 로 좌표화) ===";
    $log[] = "지도 확인: https://economist.kr/places.php (분류=맛집)";
    $log[] = "이 파일을 서버에서 삭제하세요.";
}
echo implode("\n", $log) . "\n";
