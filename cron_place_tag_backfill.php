<?php
/**
 * cron_place_tag_backfill.php — 기존 장소 자동 태깅 (통제 키워드 사전 기반)
 *
 * 크롤러가 이미 뽑아둔 keywords(attributes) + 장소명 + 기사 제목을 훑어,
 * 아래 사전(TAG_DICT)에 정의된 '대표 태그'만 부여한다. 사전 기반이라 태그 난립이 없다.
 * (※ 크롤러가 행사 기간을 저장하지 않아 '월' 자동부여는 불가 — 월은 수정모달에서 수동)
 *
 * 테마·부분류 태그(사전) + 월 태그(텍스트에서 'N월'·날짜범위 추출)를 함께 부여한다.
 *
 * 사용:
 *   ?key=econ-place-tag                 전체 스캔하며 태그 부여(테마/부분류 + 월)
 *   ?key=econ-place-tag&dry=1           실제 저장 없이 미리보기(분포만)
 *   ?key=econ-place-tag&only_ok=1       좌표 등록된(ok) 장소만
 *   ?key=econ-place-tag&season=1        시즌 추론까지(벚꽃→3·4월, 단풍→10·11월 등)
 *   ?key=econ-place-tag&months=0        월 추출 끄고 테마/부분류만
 *   ?key=econ-place-tag&reset_months=1  기존 월 태그 전체 삭제 후 재적재(노이즈 정리)
 *   ?key=econ-place-tag&after=12345     id 이후부터(이어받기) / &limit=2000
 * INSERT IGNORE 라 여러 번 돌려도 중복/수동태그를 건드리지 않는다.
 */
require_once "./env/cnt.inc";
header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-place-tag';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}
@set_time_limit(0);

$dry    = (int)($_GET['dry'] ?? 0) === 1;
$onlyOk = (int)($_GET['only_ok'] ?? 0) === 1;
$after  = (int)($_GET['after'] ?? 0);
$limit  = max(1, min(20000, (int)($_GET['limit'] ?? 20000)));
$doMonths    = (int)($_GET['months'] ?? 1) === 1;        // 텍스트에서 월 추출 (기본 on)
$doSeason    = (int)($_GET['season'] ?? 0) === 1;        // 시즌 추론(벚꽃→봄 등, 기본 off)
$resetMonths = (int)($_GET['reset_months'] ?? 0) === 1;  // 월 태그 전체 삭제 후 재적재(노이즈 정리)

// ── 통제 키워드 사전: 대표태그 => [kind, [트리거 부분문자열…]] ──
// 트리거는 2글자 이상·구체적으로(짧고 흔한 '절·섬·굴' 등은 오탐 위험이라 제외)
$TAG_DICT = [
    // 테마(시즌·볼거리)
    '벚꽃'     => ['theme', ['벚꽃', '벗꽃']],
    '단풍'     => ['theme', ['단풍']],
    '억새'     => ['theme', ['억새']],
    '유채꽃'   => ['theme', ['유채']],
    '장미'     => ['theme', ['장미']],
    '튤립'     => ['theme', ['튤립']],
    '연꽃'     => ['theme', ['연꽃']],
    '수국'     => ['theme', ['수국']],
    '코스모스' => ['theme', ['코스모스']],
    '청보리'   => ['theme', ['청보리', '보리밭']],
    '메밀꽃'   => ['theme', ['메밀꽃', '메밀']],
    '핑크뮬리' => ['theme', ['핑크뮬리', '뮬리']],
    '야경'     => ['theme', ['야경']],
    '일몰'     => ['theme', ['일몰', '노을', '낙조', '석양']],
    '일출'     => ['theme', ['일출', '해돋이']],
    '설경'     => ['theme', ['설경', '눈꽃']],
    '단풍길'   => ['theme', ['단풍길', '은행나무길']],
    // 부분류(장소 종류)
    '해수욕장' => ['type', ['해수욕장', '해변']],
    '계곡'     => ['type', ['계곡']],
    '폭포'     => ['type', ['폭포']],
    '수목원'   => ['type', ['수목원', '식물원']],
    '정원'     => ['type', ['정원', '가든']],
    '둘레길'   => ['type', ['둘레길', '산책로', '트레킹', '트레일']],
    '출렁다리' => ['type', ['출렁다리', '구름다리', '흔들다리']],
    '캠핑장'   => ['type', ['캠핑', '글램핑', '야영']],
    '전망대'   => ['type', ['전망대']],
    '동굴'     => ['type', ['동굴']],
    '온천'     => ['type', ['온천', '스파']],
    '전통시장' => ['type', ['전통시장', '재래시장']],
    '호수'     => ['type', ['호수', '저수지']],
    '휴양림'   => ['type', ['휴양림']],
    '사찰'     => ['type', ['사찰', '템플스테이']],
    '박물관'   => ['type', ['박물관']],
    '미술관'   => ['type', ['미술관', '갤러리']],
    '수족관'   => ['type', ['아쿠아리움', '수족관']],
];

// 시즌 추론(season=1 일 때만): 테마 대표태그 => 대표 방문월
$SEASON = [
    '벚꽃' => [3, 4], '유채꽃' => [3, 4], '튤립' => [4], '청보리' => [4, 5],
    '장미' => [5, 6], '수국' => [6, 7], '연꽃' => [7, 8], '메밀꽃' => [9],
    '코스모스' => [9, 10], '핑크뮬리' => [10, 11], '억새' => [10, 11],
    '단풍' => [10, 11], '단풍길' => [10, 11], '설경' => [12, 1, 2],
];

/** 텍스트에서 방문월(1~12) 추출: 명시적 'N월' + 숫자 날짜범위(M.D~M.D → 시작~끝월 전부) */
function extractMonths(string $text): array
{
    $set = [];
    // 명시적 'N월' — 앞에 숫자 없게(12월 ok). '6개월'은 '개' 때문에 매칭 안 됨
    if (preg_match_all('/(?<![0-9])(1[0-2]|[1-9])\s*월/u', $text, $mm)) {
        foreach ($mm[1] as $m) { $i = (int)$m; if ($i >= 1 && $i <= 12) $set[$i] = true; }
    }
    // 숫자 날짜범위: 4.1~5.10, 4/1-5/10
    if (preg_match_all('#(?<![0-9])(1[0-2]|[1-9])[./](?:3[01]|[12][0-9]|[1-9])\s*[~\-–]\s*(1[0-2]|[1-9])[./](?:3[01]|[12][0-9]|[1-9])#u', $text, $rg, PREG_SET_ORDER)) {
        foreach ($rg as $r) {
            $a = (int)$r[1]; $b = (int)$r[2];
            if ($a < 1 || $a > 12 || $b < 1 || $b > 12) continue;
            if ($b >= $a) { for ($k = $a; $k <= $b; $k++) $set[$k] = true; }
            else { for ($k = $a; $k <= 12; $k++) $set[$k] = true; for ($k = 1; $k <= $b; $k++) $set[$k] = true; }
        }
    }
    return array_keys($set);
}

$place = new Place($pdo);
$place->ensureTable();

// 월 태그 노이즈 정리: 기존 월 태그 전체 삭제 후 재적재 (1회용)
if ($resetMonths && !$dry) {
    $n = $pdo->exec("DELETE FROM place_tag WHERE kind = 'month'");
    echo "기존 월 태그 {$n}개 삭제 후 재적재합니다.\n\n";
}

// 여러 ref 제목·요약을 한 번에 모아 월/테마 단서 최대 확보
$pdo->exec("SET SESSION group_concat_max_len = 200000");

// 좌표 등록(ok) 우선 — 지도에 뜨는 것부터
$where = $onlyOk ? "AND p.geocode_status = 'ok'" : "";
$sql = "SELECT p.id, p.name, p.attributes,
               (SELECT GROUP_CONCAT(CONCAT_WS(' ', r.title, r.summary) SEPARATOR ' ')
                  FROM place_ref r WHERE r.place_id = p.id) AS ref_text
          FROM place p
         WHERE p.id > :after {$where}
         ORDER BY p.id
         LIMIT {$limit}";
$st = $pdo->prepare($sql);
$st->execute([':after' => $after]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$scanned = 0; $tagged = 0; $tagsAdded = 0; $lastId = $after;
$dist = [];         // 테마/부분류 태그 분포
$mdist = [];        // 월(1~12) 분포
$monthPlaces = 0;   // 월 태그가 붙은 장소 수

foreach ($rows as $r) {
    $scanned++;
    $lastId = (int)$r['id'];

    // 검색 텍스트: 이름 + 모든 ref 제목·요약 + keywords
    $hay = ' ' . (string)$r['name'] . ' ' . (string)$r['ref_text'];
    $attr = $r['attributes'] ? json_decode($r['attributes'], true) : null;
    if (is_array($attr) && !empty($attr['keywords']) && is_array($attr['keywords'])) {
        $hay .= ' ' . implode(' ', $attr['keywords']);
    }

    $hits = [];
    $hitTags = [];
    foreach ($TAG_DICT as $canon => $def) {
        [$kind, $triggers] = $def;
        foreach ($triggers as $t) {
            if (mb_stripos($hay, $t) !== false) {
                $hits[] = ['kind' => $kind, 'tag' => $canon];
                $hitTags[$canon] = true;
                $dist[$canon] = ($dist[$canon] ?? 0) + 1;
                break;
            }
        }
    }

    // 월 태그: 텍스트 추출 + (옵션) 시즌 추론
    $months = [];
    if ($doMonths) foreach (extractMonths($hay) as $mi) $months[$mi] = true;
    if ($doSeason) {
        foreach (array_keys($hitTags) as $canon) {
            if (!empty($SEASON[$canon])) foreach ($SEASON[$canon] as $mi) $months[$mi] = true;
        }
    }
    foreach (array_keys($months) as $mi) {
        $hits[] = ['kind' => 'month', 'tag' => $mi . '월'];
        $mdist[$mi] = ($mdist[$mi] ?? 0) + 1;
    }
    if ($months) $monthPlaces++;

    if (!$hits) continue;
    if ($dry) { $tagged++; continue; }

    $added = $place->addTags((int)$r['id'], $hits);
    if ($added > 0) { $tagged++; $tagsAdded += $added; }
}

arsort($dist);

echo ($dry ? "[DRY-RUN 미리보기]\n" : "[적용 완료]\n");
echo "스캔: {$scanned}건" . ($onlyOk ? " (ok만)" : "")
   . " | 월추출:" . ($doMonths ? "on" : "off") . " 시즌추론:" . ($doSeason ? "on" : "off") . "\n";
echo ($dry ? "태그가 붙을 장소: {$tagged}건\n" : "태그 부여 장소: {$tagged}건\n");
if (!$dry) echo "새로 추가된 태그 행: {$tagsAdded}\n";
echo "마지막 id: {$lastId}  → 더 있으면 &after={$lastId} 로 이어서\n";
echo "\n[테마·부분류 분포]\n";
foreach ($dist as $tag => $cnt) echo sprintf("  %-10s %d\n", $tag, $cnt);
if (!$dist) echo "  (매칭 없음)\n";
echo "\n[월 분포]  (월 태그 붙은 장소 {$monthPlaces}건)\n";
ksort($mdist);
foreach ($mdist as $m => $cnt) echo sprintf("  %2d월  %d\n", $m, $cnt);
if (!$mdist) echo "  (월 매칭 없음)\n";
echo "\n" . ($dry ? "실제 적용하려면 dry=1 빼고 다시 실행.\n" : "완료. 재실행 안전(중복 무시). 월이 지저분하면 &reset_months=1 로 월만 정리 후 재적재.\n");
?>
