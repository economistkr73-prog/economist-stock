<?php
// place_review.php — 장소 정보 리뷰/정정 도구 (Claude 코워크용)
//
//  ▸ 사람용 화면 :  place_review.php?start_id=1&end_id=100[&live=1]
//                  id 순으로 이름·전체주소·월·분류·관련기사 표시, 리뷰완료(reviewed)면 ✅완료 배지.
//                  (로그인 사용자 접근 / 또는 ?key=)
//  ▸ Claude용 API (key 인증 → 로그인 없이 호출 가능, curl 가능):
//      action=fetch   &start_id=&end_id=         → 범위 장소+관련기사 JSON (리뷰 판단용)
//      action=apply   &id=&name=&address=&category=&months=10,11&tags=둘레길,단풍[&delete=1]
//                       → 정정 반영 + reviewed=1(완료) 마킹. 변경 없어도 호출하면 '확인완료' 처리됨.
//      action=add_linked &src_id=&name=&address=&category=&months=&tags=
//                       → 한 기사가 여러 장소를 다룰 때: src 의 기사를 공유하는 새 장소 생성(+좌표)
//      action=detach  &id=&ref_id=               → 잘못 묶인 기사를 미분류 보관함으로 이동
//
//  교정 흐름(예): "place_review.php?start_id=1 부터 100까지 리뷰" →
//    Claude 가 fetch 로 1~100 읽고, 각 항목을 apply(필요시 add_linked/detach) 로 정정+완료.
//    사람은 ?start_id=1&end_id=100&live=1 화면에서 ✅완료가 차오르는 것을 지켜봄.

require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
if (file_exists("./env/maps.inc"))  require_once "./env/maps.inc";
if (file_exists("./env/kakao.inc")) require_once "./env/kakao.inc";

// ── 인증: 키(Claude용) 또는 로그인(사람용) ─────────────────
const REVIEW_KEY = 'econ-rev-9x2k';            // ⚠️ 노출되면 바꾸세요(쓰기 권한)
$keyOk = isset($_GET['key']) && hash_equals(REVIEW_KEY, (string)$_GET['key']);
if (!$keyOk) require_login();

$place = new Place($pdo);
$place->ensureTable();

// 변경 내역 로그 테이블 (어떻게 고쳐졌는지 before→after 기록)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS place_review_log (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        place_id   BIGINT UNSIGNED NOT NULL,
        kind       VARCHAR(20)     NOT NULL,
        changes    JSON            NULL,
        note       VARCHAR(255)    NULL,
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_place (place_id), KEY idx_at (created_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$CAT    = ['travel' => '여행지', 'stay' => '숙소', 'restaurant' => '맛집', 'etc' => '기타'];
$ALLOW  = ['travel', 'stay', 'restaurant', 'etc'];
$NOTSYS = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.attributes,'$.system')),'') <> 'uncategorized'";

// 한 행(place) → 표준 항목(월·태그·기사·reviewed 포함)
function mapPlaceRow(Place $place, array $CAT, array $r): array {
    $id = (int)$r['id'];
    $months = []; $themes = [];
    foreach ($place->getTags($id) as $t) {
        if ($t['kind'] === 'month') { $months[] = (int)preg_replace('/\D/', '', $t['tag']); }
        elseif ($t['kind'] === 'theme') { $themes[] = $t['tag']; }
    }
    sort($months);
    $refs = [];
    foreach ($place->getRefs($id) as $rf) {
        $refs[] = [
            'id' => (int)$rf['id'], 'type' => $rf['source_type'],
            'title' => $rf['title'], 'summary' => $rf['summary'],
            'url' => $rf['url'], 'published_at' => $rf['published_at'],
        ];
    }
    $attr = $r['attributes'] ? json_decode($r['attributes'], true) : [];
    return [
        'id' => $id, 'name' => $r['name'],
        'category' => $r['category'], 'category_ko' => $CAT[$r['category']] ?? $r['category'],
        'address' => $r['address'],
        'region' => trim(($r['region_lv1'] ?? '') . ' ' . ($r['region_lv2'] ?? '')),
        'lat' => $r['lat'] !== null ? (float)$r['lat'] : null,
        'lng' => $r['lng'] !== null ? (float)$r['lng'] : null,
        'geocode_status' => $r['geocode_status'],
        'months' => $months, 'tags' => $themes,
        'reviewed' => !empty($attr['reviewed']),
        'refs' => $refs,
    ];
}
const PLACE_COLS = "p.id, p.name, p.category, p.address, p.region_lv1, p.region_lv2, p.lat, p.lng, p.geocode_status, p.attributes";

// 정확한 id 범위(주로 단건 id..id)
function loadRange(PDO $pdo, Place $place, array $CAT, int $start, int $end): array {
    global $NOTSYS;
    $st = $pdo->prepare("SELECT " . PLACE_COLS . " FROM place p
          WHERE p.id BETWEEN :a AND :b AND p.is_active = 1 AND {$NOTSYS} ORDER BY p.id");
    $st->execute([':a' => $start, ':b' => $end]);
    return array_map(fn($r) => mapPlaceRow($place, $CAT, $r), $st->fetchAll(PDO::FETCH_ASSOC));
}

// 커서 방식: start_id 이상에서 N개(빈 id 구간 건너뜀). id 는 듬성듬성하므로 이게 기본.
function loadFrom(PDO $pdo, Place $place, array $CAT, int $startId, int $limit): array {
    global $NOTSYS;
    $limit = max(1, min(500, $limit));
    $st = $pdo->prepare("SELECT " . PLACE_COLS . " FROM place p
          WHERE p.id >= :a AND p.is_active = 1 AND {$NOTSYS} ORDER BY p.id LIMIT {$limit}");
    $st->execute([':a' => $startId]);
    return array_map(fn($r) => mapPlaceRow($place, $CAT, $r), $st->fetchAll(PDO::FETCH_ASSOC));
}

// 주소/장소명 → 좌표 (카카오 키워드 → 네이버 주소 cascade)
function geocodeAddr(string $addr): ?array {
    $addr = trim($addr); if ($addr === '') return null;
    if (class_exists('GeoCoder')) {
        $kw = GeoCoder::searchKeyword($addr, 1);
        if (!empty($kw['ok']) && !empty($kw['items'])) {
            $t = $kw['items'][0];
            return ['lat' => (float)$t['lat'], 'lng' => (float)$t['lng']];
        }
        $r = GeoCoder::geocode('naver', $addr);
        if (!empty($r['ok'])) return ['lat' => (float)$r['lat'], 'lng' => (float)$r['lng']];
    }
    return null;
}

// 이름 정규화(공백·구분기호 제거 + 소문자) — 중복 이름 비교용
function dupNorm(string $s): string {
    return preg_replace('/[\s\-·,()\[\]]+/u', '', mb_strtolower(trim($s)));
}

// 좌표 근접 + 이름 유사로 '기존 중복 장소' 탐지(가장 가까운 것 반환, 없으면 null).
//   매칭 = 250m 이내 AND 이름이 같거나 한쪽이 다른쪽을 포함(3글자↑). 보수적(애매하면 신규로 추가)
function findDuplicate(Place $place, string $name, float $lat, float $lng): ?array {
    $tn = dupNorm($name);
    if ($tn === '') return null;
    $fc = $place->searchNearby($lat, $lng, 0.3);   // 300m 박스 후보
    $best = null;
    foreach (($fc['features'] ?? []) as $f) {
        $p = $f['properties'];
        $distM = (float)($p['dist_km'] ?? 9) * 1000;
        if ($distM > 250) continue;
        $en = dupNorm((string)($p['name'] ?? ''));
        if ($en === '') continue;
        $match = ($tn === $en) ||
                 (mb_strlen($tn) >= 3 && mb_strlen($en) >= 3 &&
                  (mb_strpos($en, $tn) !== false || mb_strpos($tn, $en) !== false));
        if (!$match) continue;
        if ($best === null || $distM < $best['dist_m']) {
            $best = ['id' => (int)$p['id'], 'name' => $p['name'], 'dist_m' => (int)round($distM)];
        }
    }
    return $best;
}

// 월·테마 태그 재설정(제공된 종류만 교체, 나머지 종류는 보존)
function setKindTags(Place $place, int $id, ?array $months, ?array $themes): void {
    if ($months === null && $themes === null) return;
    $keep = [];
    foreach ($place->getTags($id) as $t) {
        if ($months !== null && $t['kind'] === 'month') continue;
        if ($themes !== null && $t['kind'] === 'theme') continue;
        $keep[] = ['kind' => $t['kind'], 'tag' => $t['tag']];
    }
    if ($months !== null) foreach ($months as $m) { $m = (int)$m; if ($m >= 1 && $m <= 12) $keep[] = ['kind' => 'month', 'tag' => $m . '월']; }
    if ($themes !== null) foreach ($themes as $tg) { $tg = trim($tg); if ($tg !== '') $keep[] = ['kind' => 'theme', 'tag' => mb_substr($tg, 0, 40)]; }
    $place->setTags($id, $keep);
}

function csvToList(?string $s): ?array {
    if ($s === null) return null;                       // 미제공 = 변경 안 함
    $s = trim($s);
    if ($s === '') return [];                            // 빈 문자열 = 비우기
    return array_values(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== ''));
}

// before/after 스냅샷 비교 → 바뀐 필드만 {field:[old,new]}
function diffPlace(array $b, array $a): array {
    $ch = [];
    foreach (['name', 'address', 'category'] as $f) {
        if ((string)($b[$f] ?? '') !== (string)($a[$f] ?? '')) $ch[$f] = [$b[$f] ?? null, $a[$f] ?? null];
    }
    $rl = fn($v) => $v === null ? null : round((float)$v, 6);
    if ($rl($b['lat'] ?? null) !== $rl($a['lat'] ?? null) || $rl($b['lng'] ?? null) !== $rl($a['lng'] ?? null)) {
        $ch['coord'] = [
            $b['lat'] !== null ? round($b['lat'], 5) . ',' . round($b['lng'], 5) : null,
            $a['lat'] !== null ? round($a['lat'], 5) . ',' . round($a['lng'], 5) : null,
        ];
    }
    if (($b['months'] ?? []) != ($a['months'] ?? [])) $ch['months'] = [$b['months'] ?? [], $a['months'] ?? []];
    if (($b['tags'] ?? [])   != ($a['tags'] ?? []))   $ch['tags']   = [$b['tags'] ?? [],   $a['tags'] ?? []];
    return $ch;
}
function logChange(PDO $pdo, int $placeId, string $kind, array $changes, string $note = ''): void {
    if ($kind === 'apply' && !$changes) return;          // 변경 없으면 기록 안 함(완료 표시만)
    $st = $pdo->prepare("INSERT INTO place_review_log (place_id, kind, changes, note) VALUES (?,?,?,?)");
    $st->execute([$placeId, $kind, json_encode($changes, JSON_UNESCAPED_UNICODE), mb_substr($note, 0, 255)]);
}

// 정정안 한 건 처리(미리보기=write false / 적용=write true). 항목 종류:
//   업데이트/확인: {id, name?, address?, category?, months?, tags?, detach?[refId]}
//   삭제: {id, delete:true}   /   멀티장소 추가: {src_id, add:{name,address,category,months,tags}}
function batchOne(PDO $pdo, Place $place, array $CAT, array $it, bool $write): array {
    global $ALLOW;
    // ── 멀티장소 추가(같은 기사 공유) ──
    if (!empty($it['src_id']) && !empty($it['add']) && is_array($it['add'])) {
        $src = (int)$it['src_id']; $a = $it['add'];
        $name = trim((string)($a['name'] ?? '')); $addr = trim((string)($a['address'] ?? ''));
        if ($name === '' || $addr === '') return ['ok' => false, 'op' => 'add', 'msg' => 'add.name/address 필요'];
        $geo = geocodeAddr($addr);
        $cat = in_array(($a['category'] ?? ''), $ALLOW, true) ? $a['category'] : 'travel';
        if (!$write) return ['ok' => $geo !== null, 'op' => 'add', 'src_id' => $src, 'preview' => "새 장소 '{$name}' ({$addr}) " . ($geo ? '좌표 OK' : '⚠️주소 좌표 실패')];
        if (!$geo) return ['ok' => false, 'op' => 'add', 'msg' => "주소 좌표 실패: {$addr}"];
        $newId = $place->addLinkedPlace($src, $name, $cat, $geo['lat'], $geo['lng']);
        if ($newId > 0) {
            $pdo->prepare("UPDATE place SET address=:a, attributes=JSON_SET(COALESCE(attributes,JSON_OBJECT()),'$.reviewed',1) WHERE id=:id")
                ->execute([':a' => mb_substr($addr, 0, 500), ':id' => $newId]);
            setKindTags($place, $newId, isset($a['months']) ? array_map('strval', (array)$a['months']) : null, isset($a['tags']) ? array_map('strval', (array)$a['tags']) : null);
            logChange($pdo, $newId, 'add_linked', ['name' => [null, $name], 'address' => [null, $addr]], "#{$src} 기사 공유 새 장소");
        }
        return ['ok' => $newId > 0, 'op' => 'add', 'id' => $newId];
    }

    $id = (int)($it['id'] ?? 0);
    if ($id <= 0) return ['ok' => false, 'msg' => 'id 없음'];
    $before = loadRange($pdo, $place, $CAT, $id, $id)[0] ?? null;
    if (!$before) return ['id' => $id, 'ok' => false, 'msg' => '장소 없음(이미 삭제/병합?)'];

    // ── 삭제 ──
    if (!empty($it['delete'])) {
        if (!$write) return ['id' => $id, 'ok' => true, 'op' => 'delete', 'preview' => "삭제: {$before['name']}"];
        $place->deletePlace($id);
        logChange($pdo, $id, 'delete', ['name' => [$before['name'], null]], "삭제: {$before['name']}");
        return ['id' => $id, 'ok' => true, 'op' => 'delete'];
    }

    // ── 기사 분리(detach) ──
    $detached = [];
    if (!empty($it['detach']) && is_array($it['detach'])) {
        foreach ($it['detach'] as $rid) {
            $rid = (int)$rid; if ($rid <= 0) continue; $detached[] = $rid;
            if ($write) { $place->detachRef($rid, $id); logChange($pdo, $id, 'detach', [], "기사 #{$rid} → 미분류"); }
        }
    }

    // ── 필드 정정 → 적용 후 상태(after) 구성 ──
    $after = $before; $sets = []; $params = [':id' => $id];
    if (isset($it['name']) && trim((string)$it['name']) !== '') { $after['name'] = trim((string)$it['name']); $sets[] = 'name=:nm'; $params[':nm'] = mb_substr($after['name'], 0, 255); }
    if (isset($it['category']) && in_array($it['category'], $ALLOW, true)) { $after['category'] = $it['category']; $sets[] = 'category=:cat'; $params[':cat'] = $it['category']; }
    $geo = null;
    if (isset($it['address']) && trim((string)$it['address']) !== '') {
        $addr = trim((string)$it['address']); $after['address'] = $addr; $sets[] = 'address=:addr'; $params[':addr'] = mb_substr($addr, 0, 500);
        $geo = geocodeAddr($addr);
        if ($geo) { $after['lat'] = $geo['lat']; $after['lng'] = $geo['lng']; $sets[] = 'lat=:la'; $sets[] = 'lng=:ln'; $sets[] = "geocode_status='ok'"; $params[':la'] = $geo['lat']; $params[':ln'] = $geo['lng']; }
    }
    $months = isset($it['months']) ? array_map('intval', (array)$it['months']) : null;
    $themes = isset($it['tags'])   ? array_map('strval', (array)$it['tags'])   : null;
    if ($months !== null) { sort($months); $after['months'] = $months; }
    if ($themes !== null) { $after['tags'] = $themes; }

    $changes = diffPlace($before, $after);
    if (!$write) {
        return ['id' => $id, 'ok' => true, 'op' => 'update', 'name' => $before['name'], 'changes' => $changes, 'detach' => $detached, 'addr_fail' => (isset($it['address']) && $it['address'] !== '' && $geo === null)];
    }
    $sets[] = 'updated_at=NOW()';
    $sets[] = "attributes=JSON_SET(COALESCE(attributes,JSON_OBJECT()),'$.reviewed',1)";
    $pdo->prepare('UPDATE place SET ' . implode(', ', $sets) . ' WHERE id=:id')->execute($params);
    if ($months !== null || $themes !== null) setKindTags($place, $id, $months !== null ? array_map('strval', $months) : null, $themes);
    logChange($pdo, $id, 'apply', $changes);
    return ['id' => $id, 'ok' => true, 'op' => 'update', 'name' => $after['name'], 'changes' => $changes, 'detach' => $detached];
}

// 한 기사(src)에서 추출한 여러 장소를 dedup 후 신규등록/기존연결/제자리해결.
//   $job = {src_id, finalize:'delete'|'reviewed'|'none', places:[{name,address,category?,months?,tags?}]}
//   각 장소: 지오코딩 → ①기존 ok장소 근접+이름일치면 기사만 연결 ②src 대표장소면 제자리 좌표화 ③그 외 신규등록
function ingestJob(PDO $pdo, Place $place, array $CAT, array $ALLOW, array $job, bool $write): array {
    $src = (int)($job['src_id'] ?? 0);
    if ($src <= 0 || empty($job['places']) || !is_array($job['places'])) {
        return ['ok' => false, 'src_id' => $src, 'msg' => 'src_id, places[] 필요'];
    }
    $finalize = in_array(($job['finalize'] ?? 'delete'), ['delete', 'reviewed', 'none'], true) ? $job['finalize'] : 'delete';
    $srcRow   = loadRange($pdo, $place, $CAT, $src, $src)[0] ?? null;
    if (!$srcRow) return ['ok' => false, 'src_id' => $src, 'msg' => "원본 #{$src} 없음(이미 삭제?)"];
    $srcNorm = dupNorm($srcRow['name']);
    $results = []; $allHandled = true; $srcResolvedInPlace = false;
    foreach ($job['places'] as $pl) {
        if (!is_array($pl)) continue;
        $name = trim((string)($pl['name'] ?? '')); $addr = trim((string)($pl['address'] ?? ''));
        $cat  = in_array(($pl['category'] ?? ''), $ALLOW, true) ? $pl['category'] : 'travel';
        $months = isset($pl['months']) ? array_map('strval', (array)$pl['months']) : null;
        $tags   = isset($pl['tags'])   ? array_map('strval', (array)$pl['tags'])   : null;
        if ($name === '' || $addr === '') { $results[] = ['name' => $name, 'ok' => false, 'action' => 'invalid', 'msg' => 'name/address 필요']; $allHandled = false; continue; }
        $geo = geocodeAddr($addr);
        if (!$geo) { $results[] = ['name' => $name, 'ok' => false, 'action' => 'geocode_fail', 'msg' => "좌표 실패: {$addr}"]; $allHandled = false; continue; }
        $isSrcName = ($srcNorm !== '' && dupNorm($name) === $srcNorm);

        $dup = findDuplicate($place, $name, $geo['lat'], $geo['lng']);
        if ($dup) {                                     // ① 이미 지도에 있는 장소 → 기사만 관련기사로 연결
            if ($write && $src !== $dup['id']) { $place->copyRefs($src, $dup['id']); logChange($pdo, $dup['id'], 'add_linked', [], "#{$src} 기사 → 기존장소 연결({$name})"); }
            $results[] = ['name' => $name, 'ok' => true, 'action' => 'link_existing', 'to_id' => $dup['id'], 'to_name' => $dup['name'], 'dist_m' => $dup['dist_m'], 'is_src' => $isSrcName];
            continue;
        }
        if ($isSrcName && !$srcResolvedInPlace) {       // ② src 대표장소 → 제자리 좌표화(id·태그·기사 보존)
            if ($write) {
                $place->adminUpdate($src, $name, $cat, $geo['lat'], $geo['lng']);
                $pdo->prepare("UPDATE place SET address=:a WHERE id=:id")->execute([':a' => mb_substr($addr, 0, 500), ':id' => $src]);
                setKindTags($place, $src, $months, $tags);
                logChange($pdo, $src, 'apply', ['coord' => [null, round($geo['lat'], 5) . ',' . round($geo['lng'], 5)]], '미좌표 → 본문주소 제자리 지오코딩');
            }
            $results[] = ['name' => $name, 'ok' => true, 'action' => 'resolve_src', 'id' => $src, 'coord' => round($geo['lat'], 5) . ',' . round($geo['lng'], 5)];
            $srcResolvedInPlace = true;
            continue;
        }
        if (!$write) { $results[] = ['name' => $name, 'ok' => true, 'action' => 'add_new', 'coord' => round($geo['lat'], 5) . ',' . round($geo['lng'], 5)]; continue; }
        $newId = $place->addLinkedPlace($src, $name, $cat, $geo['lat'], $geo['lng']);  // ③ 신규 추가(기사 공유)
        if ($newId > 0) {
            $pdo->prepare("UPDATE place SET address=:a, attributes=JSON_SET(COALESCE(attributes,JSON_OBJECT()),'$.reviewed',1) WHERE id=:id")
                ->execute([':a' => mb_substr($addr, 0, 500), ':id' => $newId]);
            setKindTags($place, $newId, $months, $tags);
            logChange($pdo, $newId, 'add_linked', ['name' => [null, $name], 'address' => [null, $addr]], "#{$src} 멀티추출 새 장소");
        } else { $allHandled = false; }
        $results[] = ['name' => $name, 'ok' => $newId > 0, 'action' => 'add_new', 'id' => $newId, 'coord' => round($geo['lat'], 5) . ',' . round($geo['lng'], 5)];
    }
    $srcDone = null;
    if ($write) {
        if ($srcResolvedInPlace) {
            $srcDone = 'resolved_inplace';
        } elseif ($finalize === 'delete' && $allHandled) {
            $place->deletePlace($src); $srcDone = 'deleted';
            logChange($pdo, $src, 'delete', ['name' => [$srcRow['name'], null]], '멀티추출 후 placeholder 삭제(기사 배분 완료)');
        } elseif ($finalize === 'none') {
            $srcDone = 'untouched';
        } else {
            $place->setReviewed($src, true);
            $srcDone = ($finalize === 'delete') ? 'kept_reviewed(일부 미처리)' : 'reviewed';
        }
    }
    return ['ok' => true, 'src_id' => $src, 'src_name' => $srcRow['name'], 'src' => $srcDone,
            'all_handled' => $allHandled, 'count' => count($results), 'results' => $results];
}

// 미분류 보관함의 기사(ref) 1건에서 추출한 여러 장소를 dedup 후 신규등록/기존연결. src place 개념 없음(ref 단위).
//   $job = {ref_id, finalize:'purge'|'keep', places:[{name,address,category?,months?,tags?}]}
//   각 장소: 지오코딩 → 근접+이름 중복이면 기존장소에 '이 기사'만 연결, 아니면 신규등록+'이 기사' 연결.
//   finalize=purge + 전부 처리 → 보관함에서 그 기사 삭제(내용이 실제 장소들로 배분됨).
function ingestRefJob(PDO $pdo, Place $place, array $CAT, array $ALLOW, array $job, bool $write): array {
    $refId = (int)($job['ref_id'] ?? 0);
    if ($refId <= 0 || empty($job['places']) || !is_array($job['places'])) {
        return ['ok' => false, 'ref_id' => $refId, 'msg' => 'ref_id, places[] 필요'];
    }
    $finalize = in_array(($job['finalize'] ?? 'purge'), ['purge', 'keep'], true) ? $job['finalize'] : 'purge';
    $bucket = $place->getUncategorizedId();
    $rf = $pdo->prepare("SELECT id, source_type, title, url, summary, published_at, extra FROM place_ref WHERE id = ? AND place_id = ?");
    $rf->execute([$refId, $bucket]);
    $refRow = $rf->fetch(PDO::FETCH_ASSOC);
    if (!$refRow) return ['ok' => false, 'ref_id' => $refId, 'msg' => '보관함에 그 기사 없음(이미 처리/이동?)'];
    $refData = ['source_type' => $refRow['source_type'] ?: 'article', 'title' => $refRow['title'],
                'url' => $refRow['url'], 'summary' => $refRow['summary'], 'published_at' => $refRow['published_at'],
                'extra' => $refRow['extra'] ? json_decode($refRow['extra'], true) : null];
    $results = []; $allHandled = true;
    foreach ($job['places'] as $pl) {
        if (!is_array($pl)) continue;
        $name = trim((string)($pl['name'] ?? '')); $addr = trim((string)($pl['address'] ?? ''));
        $cat  = in_array(($pl['category'] ?? ''), $ALLOW, true) ? $pl['category'] : 'travel';
        $months = isset($pl['months']) ? array_map('strval', (array)$pl['months']) : null;
        $tags   = isset($pl['tags'])   ? array_map('strval', (array)$pl['tags'])   : null;
        if ($name === '' || $addr === '') { $results[] = ['name' => $name, 'ok' => false, 'action' => 'invalid', 'msg' => 'name/address 필요']; $allHandled = false; continue; }
        $geo = geocodeAddr($addr);
        if (!$geo) { $results[] = ['name' => $name, 'ok' => false, 'action' => 'geocode_fail', 'msg' => "좌표 실패: {$addr}"]; $allHandled = false; continue; }
        $dup = findDuplicate($place, $name, $geo['lat'], $geo['lng']);
        if ($dup) {                                     // 기존 장소 → 이 기사만 연결
            if ($write) { $place->addRef($dup['id'], $refData); logChange($pdo, $dup['id'], 'add_linked', [], "미분류 기사 #{$refId} → 기존장소 연결({$name})"); }
            $results[] = ['name' => $name, 'ok' => true, 'action' => 'link_existing', 'to_id' => $dup['id'], 'to_name' => $dup['name'], 'dist_m' => $dup['dist_m']];
            continue;
        }
        if (!$write) { $results[] = ['name' => $name, 'ok' => true, 'action' => 'add_new', 'coord' => round($geo['lat'], 5) . ',' . round($geo['lng'], 5)]; continue; }
        $newId = $place->upsertPlace(['name' => $name, 'category' => $cat, 'lat' => $geo['lat'], 'lng' => $geo['lng'], 'geocode_status' => 'ok']);  // 신규(또는 이름+지역 dedup 병합)
        if ($newId > 0) {
            $pdo->prepare("UPDATE place SET address=:a, attributes=JSON_SET(COALESCE(attributes,JSON_OBJECT()),'$.reviewed',1) WHERE id=:id")
                ->execute([':a' => mb_substr($addr, 0, 500), ':id' => $newId]);
            $place->addRef($newId, $refData);
            setKindTags($place, $newId, $months, $tags);
            logChange($pdo, $newId, 'add_linked', ['name' => [null, $name], 'address' => [null, $addr]], "미분류 기사 #{$refId} 추출 새 장소");
        } else { $allHandled = false; }
        $results[] = ['name' => $name, 'ok' => $newId > 0, 'action' => 'add_new', 'id' => $newId, 'coord' => round($geo['lat'], 5) . ',' . round($geo['lng'], 5)];
    }
    $done = null;
    if ($write) {
        if ($finalize === 'purge' && $allHandled) { $place->purgeRef($refId); $done = 'purged'; }
        else { $done = ($finalize === 'purge') ? 'kept(일부 미처리)' : 'kept'; }
    }
    return ['ok' => true, 'ref_id' => $refId, 'title' => $refRow['title'], 'ref' => $done,
            'all_handled' => $allHandled, 'count' => count($results), 'results' => $results];
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'view';

// ============================================================
//  API (JSON)
// ============================================================
if ($action !== 'view') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($action) {
            case 'fetch': {                                 // 커서 방식: start_id 이상에서 limit 개(빈 구간 건너뜀)
                $start = max(1, (int)($_GET['start_id'] ?? 1));
                $limit = (int)($_GET['limit'] ?? 100);
                if (isset($_GET['end_id'])) $limit = (int)$_GET['end_id'] - $start + 1;   // 레거시 호환
                $limit = max(1, min(500, $limit));
                $items = loadFrom($pdo, $place, $CAT, $start, $limit);
                $lastId = $items ? $items[count($items) - 1]['id'] : null;
                $nextId = (count($items) === $limit && $lastId !== null) ? $lastId + 1 : null;
                echo json_encode(['ok' => true, 'start_id' => $start, 'limit' => $limit,
                                  'count' => count($items),
                                  'first_id' => $items[0]['id'] ?? null, 'last_id' => $lastId, 'next_id' => $nextId,
                                  'items' => $items], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'apply': {
                $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
                if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'id 필요']); break; }

                if (!empty($_GET['delete'])) {                  // 잘못 등록된 장소 삭제
                    $snap = loadRange($pdo, $place, $CAT, $id, $id);
                    $nm = $snap[0]['name'] ?? '';
                    $n = $place->deletePlace($id);
                    if ($n > 0) logChange($pdo, $id, 'delete', ['name' => [$nm, null]], '장소 삭제: ' . $nm);
                    echo json_encode(['ok' => $n > 0, 'deleted' => $n, 'id' => $id], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $before = loadRange($pdo, $place, $CAT, $id, $id)[0] ?? null;
                $G = fn($k) => isset($_GET[$k]) ? trim((string)$_GET[$k]) : (isset($_POST[$k]) ? trim((string)$_POST[$k]) : null);
                $name = $G('name'); $address = $G('address'); $category = $G('category');
                $months = csvToList($G('months')); $themes = csvToList($G('tags'));

                $sets = []; $params = [':id' => $id];
                if ($name !== null && $name !== '') { $sets[] = 'name = :nm'; $params[':nm'] = mb_substr($name, 0, 255); }
                if ($category !== null && in_array($category, $GLOBALS['ALLOW'], true)) { $sets[] = 'category = :cat'; $params[':cat'] = $category; }
                $geo = null;
                if ($address !== null && $address !== '') {
                    $sets[] = 'address = :addr'; $params[':addr'] = mb_substr($address, 0, 500);
                    $geo = geocodeAddr($address);
                    if ($geo) { $sets[] = 'lat = :la'; $sets[] = 'lng = :ln'; $sets[] = "geocode_status = 'ok'"; $params[':la'] = $geo['lat']; $params[':ln'] = $geo['lng']; }
                }
                // 항상 reviewed=1(완료) + updated_at 갱신
                $sets[] = 'updated_at = NOW()';
                $sets[] = "attributes = JSON_SET(COALESCE(attributes, JSON_OBJECT()), '$.reviewed', 1)";
                $up = $pdo->prepare('UPDATE place SET ' . implode(', ', $sets) . ' WHERE id = :id');
                $up->execute($params);

                setKindTags($place, $id, $months, $themes);

                $one = loadRange($pdo, $place, $CAT, $id, $id);
                $changes = ($before && isset($one[0])) ? diffPlace($before, $one[0]) : [];
                logChange($pdo, $id, 'apply', $changes);
                echo json_encode(['ok' => true, 'id' => $id, 'geocoded' => $geo !== null,
                                  'changes' => $changes, 'place' => $one[0] ?? null], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'add_linked': {                                // 멀티 장소 분리(같은 기사 공유 새 장소)
                $srcId = (int)($_GET['src_id'] ?? 0);
                $G = fn($k) => isset($_GET[$k]) ? trim((string)$_GET[$k]) : null;
                $name = $G('name'); $address = $G('address');
                $category = in_array($G('category'), $GLOBALS['ALLOW'], true) ? $G('category') : 'travel';
                if ($srcId <= 0 || !$name || !$address) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'src_id,name,address 필요']); break; }
                $geo = geocodeAddr($address);
                if (!$geo) { echo json_encode(['ok' => false, 'msg' => '주소 좌표를 못 찾음: ' . $address]); break; }
                $newId = $place->addLinkedPlace($srcId, $name, $category, $geo['lat'], $geo['lng']);
                if ($newId > 0) {
                    $pdo->prepare("UPDATE place SET address=:a, attributes=JSON_SET(COALESCE(attributes,JSON_OBJECT()),'$.reviewed',1) WHERE id=:id")
                        ->execute([':a' => mb_substr($address, 0, 500), ':id' => $newId]);
                    setKindTags($place, $newId, csvToList($G('months')), csvToList($G('tags')));
                    logChange($pdo, $newId, 'add_linked', ['name' => [null, $name], 'address' => [null, $address]], "#{$srcId} 기사 공유 새 장소");
                }
                $one = $newId > 0 ? loadRange($pdo, $place, $CAT, $newId, $newId) : [];
                echo json_encode(['ok' => $newId > 0, 'id' => $newId, 'place' => $one[0] ?? null], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'detach': {                                    // 잘못 묶인 기사 → 미분류
                $id = (int)($_GET['id'] ?? 0); $refId = (int)($_GET['ref_id'] ?? 0);
                if ($id <= 0 || $refId <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'id,ref_id 필요']); break; }
                $r = $place->detachRef($refId, $id);
                if (!empty($r['ok'])) logChange($pdo, $id, 'detach', [], "기사 #{$refId} → 미분류 보관함");
                $r['refs'] = $place->getRefs($id);
                echo json_encode($r, JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'apply_batch': {                           // 정정안 JSON 배열 일괄 미리보기/적용
                $raw  = (string)($_POST['payload'] ?? $_GET['payload'] ?? '');
                $mode = (string)($_POST['mode'] ?? $_GET['mode'] ?? 'preview');
                $list = json_decode($raw, true);
                if (!is_array($list)) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'payload(JSON 배열) 필요']); break; }
                $write = ($mode === 'apply');
                $results = [];
                foreach ($list as $it) { if (is_array($it)) $results[] = batchOne($pdo, $place, $CAT, $it, $write); }
                echo json_encode(['ok' => true, 'mode' => ($write ? 'apply' : 'preview'), 'count' => count($results), 'results' => $results], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'uncat_stats': {                           // 미검토(reviewed=false) 장소를 이름 패턴으로 분류 집계
                $st = $pdo->query(
                    "SELECT name FROM place
                      WHERE is_active = 1
                        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(attributes,'$.system')),'') <> 'uncategorized'
                        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(attributes,'$.reviewed')),'0') <> '1'"
                );
                $names = $st->fetchAll(PDO::FETCH_COLUMN);
                // 우선순위 순으로 첫 매칭 버킷에 집계
                $buckets = [
                    '시즌/리스티클(여행·명소·N월·가볼만한곳·코스 등)' => '/여행|가볼만한|명소|추천|코스|핫플|당일치기|나들이|피서|휴가지?|휴양지|힐링|이색|숨은|데이트|봄꽃|여름|가을|겨울|[0-9]월|단풍|벚꽃|억새|봄 |봄$|겨울철|연휴/u',
                    '카페/맛집/빵집' => '/카페|맛집|빵집|빵|떡집|국수|라멘|디저트|식당|먹거리|맛|미쉐린|노포|찻집|커피/u',
                    '숙소/호텔/온천' => '/호텔|숙소|리조트|풀빌라|료칸|민박|펜션|호캉스|스파|찜질|온천|글램핑|캠핑|스테이|료칸/u',
                    '뷰/풍경 묘사' => '/뷰|풍경|야경|일출|일몰|노을|포토|인생샷|오션|전망$/u',
                    '축제/행사/체험' => '/축제|페스티벌|행사|체험|클래스|투어|시티/u',
                    '일반명/지역명(호수·계곡·동굴·해변·OO여행 등)' => '/^(호수|계곡|동굴|폭포|해변|해수욕장|저수지|온천|사찰|정원|수목원|공원|전망대|섬|산|마을|시장)$|^[가-힣]{2,4}( 여행| 명소)?$/u',
                ];
                $counts = []; $samplesOther = [];
                foreach (array_keys($buckets) as $k) $counts[$k] = 0;
                $counts['기타(특정명·중복·불일치)'] = 0;
                foreach ($names as $nm) {
                    $hit = null;
                    foreach ($buckets as $k => $re) { if (preg_match($re, $nm)) { $hit = $k; break; } }
                    if ($hit === null) { $counts['기타(특정명·중복·불일치)']++; if (count($samplesOther) < 40) $samplesOther[] = $nm; }
                    else $counts[$hit]++;
                }
                echo json_encode(['ok' => true, 'total_unreviewed' => count($names), 'buckets' => $counts, 'samples_기타' => $samplesOther], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'unreviewed_list': {                       // 미검토(reviewed=false) 장소 상세 목록(이름순)
                $st = $pdo->query(
                    "SELECT p.id, p.name, p.region_lv1, p.region_lv2, p.lat, p.lng, p.geocode_status,
                            (SELECT COUNT(*) FROM place_ref r WHERE r.place_id = p.id) AS ref_cnt
                       FROM place p
                      WHERE p.is_active = 1
                        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.attributes,'$.system')),'') <> 'uncategorized'
                        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.attributes,'$.reviewed')),'0') <> '1'
                      ORDER BY p.name, p.id"
                );
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['ok' => true, 'count' => count($rows), 'items' => $rows], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'ph_clean': {                              // placeholder 일괄 정리 (preview/delete/deactivate)
                $reList = '여행|가볼만한|명소|추천|코스|핫플|당일치기|나들이|피서|휴가|휴양지|힐링|이색|숨은|데이트|봄꽃|여름|가을|겨울|[0-9]월|단풍|벚꽃|억새|겨울철|연휴|불꽃|봄꽃';
                $reGen  = '^(호수|계곡|동굴|폭포|해변|해수욕장|저수지|온천|사찰|정원|수목원|공원|전망대|섬|산|마을|시장|숲길|둘레길|출렁다리|해안|카페|맛집|숙소|호텔|찜질방|체험|야경|뷰)$|^[가-힣]{2,4}$';
                $base = "is_active=1 AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(attributes,'$.system')),'')<>'uncategorized' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(attributes,'$.reviewed')),'0')<>'1'";
                $mode  = $_GET['mode'] ?? $_POST['mode'] ?? 'preview';
                $scope = $_GET['scope'] ?? $_POST['scope'] ?? 'listicle';
                $re = ($scope === 'generic') ? $reGen : $reList;
                if ($mode === 'delete') {
                    $del = $pdo->prepare("DELETE FROM place WHERE {$base} AND name REGEXP :re");
                    $del->execute([':re' => $re]);
                    echo json_encode(['ok' => true, 'mode' => 'delete', 'scope' => $scope, 'deleted' => $del->rowCount()], JSON_UNESCAPED_UNICODE);
                } elseif ($mode === 'deactivate') {
                    $up = $pdo->prepare("UPDATE place SET is_active=0 WHERE {$base} AND name REGEXP :re");
                    $up->execute([':re' => $re]);
                    echo json_encode(['ok' => true, 'mode' => 'deactivate', 'scope' => $scope, 'deactivated' => $up->rowCount()], JSON_UNESCAPED_UNICODE);
                } else { // preview
                    $c = $pdo->prepare("SELECT COUNT(*) FROM place WHERE {$base} AND name REGEXP :re"); $c->execute([':re' => $re]);
                    $n = (int)$c->fetchColumn();
                    $s = $pdo->prepare("SELECT name FROM place WHERE {$base} AND name REGEXP :re LIMIT 60"); $s->execute([':re' => $re]);
                    echo json_encode(['ok' => true, 'mode' => 'preview', 'scope' => $scope, 'count' => $n, 'sample' => $s->fetchAll(PDO::FETCH_COLUMN)], JSON_UNESCAPED_UNICODE);
                }
                break;
            }
            case 'ungeocoded_list': {                       // 미좌표(failed/pending) 장소 + 관련기사(url/제목/요약) — 멀티추출 대상
                $status = in_array(($_GET['status'] ?? 'failed'), ['failed', 'pending'], true) ? $_GET['status'] : 'failed';
                $limit  = max(1, min(2000, (int)($_GET['limit'] ?? 500)));
                $items  = [];
                foreach ($place->listUngeocoded($status, $limit) as $r) {
                    $id = (int)$r['id'];
                    $refs = [];
                    foreach ($place->getRefs($id) as $rf) {
                        $refs[] = ['id' => (int)$rf['id'], 'type' => $rf['source_type'], 'title' => $rf['title'],
                                   'url' => $rf['url'], 'summary' => $rf['summary'], 'published_at' => $rf['published_at']];
                    }
                    $items[] = ['id' => $id, 'name' => $r['name'], 'category' => $r['category'],
                                'region' => trim(($r['region_lv1'] ?? '') . ' ' . ($r['region_lv2'] ?? '')),
                                'ref_cnt' => (int)$r['ref_cnt'], 'refs' => $refs];
                }
                echo json_encode(['ok' => true, 'status' => $status, 'count' => count($items), 'items' => $items], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'ingest_multi': {                          // 단건: payload = {src_id, finalize?, places:[{name,address,...}]}
                $write = (($_POST['mode'] ?? $_GET['mode'] ?? 'preview') === 'apply');
                $job   = json_decode((string)($_POST['payload'] ?? $_GET['payload'] ?? ''), true);
                if (!is_array($job)) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'payload {src_id, places:[...]} 필요']); break; }
                $r = ingestJob($pdo, $place, $CAT, $ALLOW, $job, $write);
                echo json_encode(['mode' => $write ? 'apply' : 'preview'] + $r, JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'ingest_batch': {                          // 일괄: payload = [{src_id, finalize?, places:[...]}, ...]
                $write = (($_POST['mode'] ?? $_GET['mode'] ?? 'preview') === 'apply');
                $jobs  = json_decode((string)($_POST['payload'] ?? $_GET['payload'] ?? ''), true);
                if (!is_array($jobs)) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'payload(JSON 배열 [{src_id,places}]) 필요']); break; }
                $out = []; $sum = ['resolve_src' => 0, 'add_new' => 0, 'link_existing' => 0, 'geocode_fail' => 0, 'deleted' => 0];
                foreach ($jobs as $job) {
                    if (!is_array($job)) continue;
                    $r = ingestJob($pdo, $place, $CAT, $ALLOW, $job, $write);
                    foreach (($r['results'] ?? []) as $x) { $a = $x['action'] ?? ''; if (isset($sum[$a])) $sum[$a]++; }
                    if (($r['src'] ?? '') === 'deleted') $sum['deleted']++;
                    $out[] = $r;
                }
                echo json_encode(['ok' => true, 'mode' => $write ? 'apply' : 'preview', 'jobs' => count($out), 'summary' => $sum, 'results' => $out], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'uncat_refs': {                            // 미분류 보관함 기사 목록(멀티추출 대상)
                $limit = max(1, min(3000, (int)($_GET['limit'] ?? 500)));
                $rows  = $place->listUncategorizedRefs($limit);
                $items = [];
                foreach ($rows as $r) {
                    $items[] = ['ref_id' => (int)$r['id'], 'type' => $r['source_type'], 'title' => $r['title'],
                                'url' => $r['url'], 'summary' => $r['summary'], 'published_at' => $r['published_at']];
                }
                echo json_encode(['ok' => true, 'bucket' => $place->getUncategorizedId(), 'count' => count($items), 'items' => $items], JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'ingest_ref': {                            // 단건: payload = {ref_id, finalize?, places:[...]}
                $write = (($_POST['mode'] ?? $_GET['mode'] ?? 'preview') === 'apply');
                $job   = json_decode((string)($_POST['payload'] ?? $_GET['payload'] ?? ''), true);
                if (!is_array($job)) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'payload {ref_id, places:[...]} 필요']); break; }
                $r = ingestRefJob($pdo, $place, $CAT, $ALLOW, $job, $write);
                echo json_encode(['mode' => $write ? 'apply' : 'preview'] + $r, JSON_UNESCAPED_UNICODE);
                break;
            }
            case 'ingest_ref_batch': {                      // 일괄: payload = [{ref_id, finalize?, places:[...]}, ...]
                $write = (($_POST['mode'] ?? $_GET['mode'] ?? 'preview') === 'apply');
                $jobs  = json_decode((string)($_POST['payload'] ?? $_GET['payload'] ?? ''), true);
                if (!is_array($jobs)) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'payload(JSON 배열 [{ref_id,places}]) 필요']); break; }
                $out = []; $sum = ['add_new' => 0, 'link_existing' => 0, 'geocode_fail' => 0, 'purged' => 0];
                foreach ($jobs as $job) {
                    if (!is_array($job)) continue;
                    $r = ingestRefJob($pdo, $place, $CAT, $ALLOW, $job, $write);
                    foreach (($r['results'] ?? []) as $x) { $a = $x['action'] ?? ''; if (isset($sum[$a])) $sum[$a]++; }
                    if (($r['ref'] ?? '') === 'purged') $sum['purged']++;
                    $out[] = $r;
                }
                echo json_encode(['ok' => true, 'mode' => $write ? 'apply' : 'preview', 'jobs' => count($out), 'summary' => $sum, 'results' => $out], JSON_UNESCAPED_UNICODE);
                break;
            }
            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => "unknown action: {$action}"]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================
//  변경 내역 화면 (?log=1) — 어떻게 고쳐졌는지 before→after
// ============================================================
function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$kq = $keyOk ? ('&key=' . urlencode((string)$_GET['key'])) : '';
if (!empty($_GET['log'])) {
    $KIND_KO = ['apply' => '수정', 'add_linked' => '장소추가', 'detach' => '기사분리', 'delete' => '삭제'];
    $FLD_KO  = ['name' => '이름', 'address' => '주소', 'category' => '분류', 'coord' => '좌표', 'months' => '월', 'tags' => '태그'];
    $pid = (int)($_GET['place_id'] ?? 0);
    if ($pid > 0) {
        $st = $pdo->prepare("SELECT l.*, p.name AS pname FROM place_review_log l LEFT JOIN place p ON p.id=l.place_id WHERE l.place_id=? ORDER BY l.id DESC LIMIT 500");
        $st->execute([$pid]);
    } else {
        $st = $pdo->query("SELECT l.*, p.name AS pname FROM place_review_log l LEFT JOIN place p ON p.id=l.place_id ORDER BY l.id DESC LIMIT 500");
    }
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);
    $fmtV = function ($v) {
        if ($v === null || $v === '' || $v === []) return '<i>(없음)</i>';
        if (is_array($v)) return he(implode('·', array_map(fn($x) => is_int($x) ? $x . '월' : $x, $v)));
        return he((string)$v);
    };
    ?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>변경 내역</title><style>
      body{font-family:system-ui,"Apple SD Gothic Neo",sans-serif;max-width:920px;margin:0 auto;padding:14px;color:#2c3e50;line-height:1.5}
      .bar{position:sticky;top:0;background:#fff;padding:10px 0;border-bottom:2px solid #2980b9;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      .bar a{font-size:12.5px;padding:5px 10px;border:1px solid #cdd6df;border-radius:7px;text-decoration:none;color:#34495e;background:#f7f9fb}
      .lg{border:1px solid #e8edf2;border-radius:9px;padding:8px 11px;margin:7px 0}
      .lg-hd{font-size:13px;color:#7f8c8d}
      .lg-hd b{color:#2c3e50}
      .kk{font-size:11px;font-weight:700;color:#fff;background:#2980b9;border-radius:5px;padding:1px 7px;margin-left:5px}
      .kk.delete{background:#c0392b}.kk.add_linked{background:#16a085}.kk.detach{background:#e67e22}
      .chg{font-size:13px;margin-top:4px}
      .chg .f{display:inline-block;min-width:42px;color:#8a97a3;font-size:12px}
      .o{color:#c0392b;text-decoration:line-through}.n{color:#1e8449;font-weight:600}
      .note{font-size:12px;color:#95a5a6;margin-top:3px}
      .empty{color:#aaa;text-align:center;padding:40px}
    </style></head><body>
    <div class="bar"><b>📝 변경 내역</b>
      <span style="color:#95a5a6;font-size:12.5px"><?= count($logs) ?>건<?= $pid ? ' (#' . $pid . ')' : '' ?></span>
      <a href="?start_id=1&end_id=100<?= he($kq) ?>">← 리뷰 화면</a>
      <?php if ($pid): ?><a href="?log=1<?= he($kq) ?>">전체 내역</a><?php endif; ?>
    </div>
    <?php if (!$logs): ?><div class="empty">아직 변경 내역이 없습니다.</div><?php endif; ?>
    <?php foreach ($logs as $lg):
        $ch = $lg['changes'] ? json_decode($lg['changes'], true) : []; ?>
      <div class="lg">
        <div class="lg-hd"><?= he(substr($lg['created_at'], 0, 16)) ?> · <b>#<?= (int)$lg['place_id'] ?> <?= he($lg['pname'] ?? '') ?></b>
          <span class="kk <?= he($lg['kind']) ?>"><?= he($KIND_KO[$lg['kind']] ?? $lg['kind']) ?></span></div>
        <?php foreach (($ch ?: []) as $f => $pair): ?>
          <div class="chg"><span class="f"><?= he($FLD_KO[$f] ?? $f) ?></span>
            <span class="o"><?= $fmtV($pair[0]) ?></span> → <span class="n"><?= $fmtV($pair[1]) ?></span></div>
        <?php endforeach; ?>
        <?php if ($lg['note']): ?><div class="note"><?= he($lg['note']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </body></html><?php
    exit;
}

// ============================================================
//  붙여넣기 적용 화면 (?form=1) — Claude가 만든 정정안 JSON 을 미리보기 후 적용
// ============================================================
if (!empty($_GET['form'])) {
    $sample = "[\n  {\"id\":5,\"name\":\"해운대 해수욕장\",\"address\":\"부산 해운대구 해운대해변로 264\",\"category\":\"travel\",\"months\":[7,8],\"tags\":[\"해변\",\"야경\"]},\n  {\"id\":7,\"name\":\"전주 한옥마을\",\"category\":\"travel\"},\n  {\"id\":9,\"detach\":[88]},\n  {\"id\":12,\"delete\":true},\n  {\"src_id\":5,\"add\":{\"name\":\"동백섬\",\"address\":\"부산 해운대구 우동\",\"category\":\"travel\"}}\n]";
    ?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>정정안 적용</title><style>
      body{font-family:system-ui,"Apple SD Gothic Neo",sans-serif;max-width:920px;margin:0 auto;padding:14px;color:#2c3e50;line-height:1.5}
      h2{margin:0 0 6px}
      .hint{font-size:12.5px;color:#7f8c8d;background:#f7f9fb;border:1px solid #e8edf2;border-radius:8px;padding:8px 11px;margin-bottom:10px}
      textarea{width:100%;box-sizing:border-box;height:240px;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;border:1px solid #cdd6df;border-radius:8px;padding:10px}
      .btns{display:flex;gap:8px;margin:10px 0}
      button{font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px;border:1px solid #cdd6df;cursor:pointer;background:#eef2f6;color:#34495e}
      button.go{background:#27ae60;color:#fff;border-color:#229954}
      button.pv{background:#2980b9;color:#fff;border-color:#2471a3}
      #out{margin-top:12px}
      #out p{font-weight:700}
      .row{border:1px solid #eaeef2;border-radius:7px;padding:6px 10px;margin:5px 0;font-size:13px}
      .row.err{border-color:#f5c6c0;background:#fdecea;color:#c0392b}
      a.bk{font-size:12.5px;color:#34495e}
    </style></head><body>
    <h2>📥 정정안 적용</h2>
    <div class="hint">Claude가 만든 <b>정정안 JSON 배열</b>을 붙여넣고 <b>미리보기</b>로 확인한 뒤 <b>적용</b>하세요. 적용 시 DB 반영 + 변경내역 기록.<br>
    항목: <code>{id, name?, address?, category?(travel/stay/restaurant/etc), months?[], tags?[], detach?[refId], delete?}</code> / 멀티장소: <code>{src_id, add:{name,address,...}}</code><br>
    <a class="bk" href="?log=1<?= he($kq) ?>">📝 변경내역</a> · <a class="bk" href="?start_id=1&end_id=100<?= he($kq) ?>">← 리뷰 화면</a>
    <details style="margin-top:6px"><summary style="cursor:pointer;color:#2980b9">형식 예시 보기</summary><pre style="font-size:11.5px;background:#fff;border:1px solid #e8edf2;border-radius:6px;padding:8px;overflow:auto"><?= he($sample) ?></pre></details></div>
    <textarea id="pl" placeholder="여기에 정정안 JSON 배열을 붙여넣으세요 (예시는 위 '형식 예시 보기' 참고)"></textarea>
    <div class="btns">
      <button class="pv" onclick="run('preview')">👀 미리보기</button>
      <button class="go" onclick="run('apply')">✅ 적용</button>
    </div>
    <div id="out"></div>
    <script>
    function fmt(v){ if(v==null||v===''||(Array.isArray(v)&&!v.length))return'(없음)'; if(Array.isArray(v))return v.join('·'); return v; }
    function render(d){
      var out=document.getElementById('out');
      if(!d||!d.ok){ out.innerHTML='<p style="color:#c0392b">'+((d&&d.msg)||'오류')+'</p>'; return; }
      var rows=(d.results||[]).map(function(r){
        var who='#'+(r.id||r.src_id||'?')+(r.name?(' '+r.name):'');
        var s='';
        if(!r.ok){ s='❌ '+(r.msg||'실패'); }
        else if(r.op==='delete'){ s=(d.mode==='apply'?'🗑 삭제됨':'🗑 삭제 예정'); }
        else if(r.op==='add'){ s='➕ '+(r.preview||('새 장소 #'+r.id+' 생성')); }
        else if(r.changes){ var ks=Object.keys(r.changes); s=ks.length? ks.map(function(k){return k+': '+fmt(r.changes[k][0])+' → '+fmt(r.changes[k][1]);}).join(' / ') : '변경 없음(완료 표시)'; }
        if(r.detach&&r.detach.length) s+=' · 기사분리['+r.detach.join(',')+']';
        if(r.addr_fail) s+=' ⚠️주소 좌표 실패';
        return '<div class="row '+(r.ok?'':'err')+'"><b>'+who+'</b> '+s+'</div>';
      }).join('');
      out.innerHTML='<p>'+(d.mode==='apply'?'✅ 적용 완료':'👀 미리보기')+' ('+d.count+'건)</p>'+rows;
    }
    function run(mode){
      var p=document.getElementById('pl').value.trim();
      try{ var j=JSON.parse(p); if(!Array.isArray(j)) throw new Error('최상위는 배열이어야 합니다'); }catch(e){ alert('JSON 형식 오류: '+e.message); return; }
      if(mode==='apply' && !confirm('실제로 DB에 적용합니다(되돌리기 어려움). 진행할까요?')) return;
      var fd=new FormData(); fd.append('action','apply_batch'); fd.append('mode',mode); fd.append('payload',p);
      <?php if ($keyOk): ?>fd.append('key', <?= json_encode((string)$_GET['key']) ?>);<?php endif; ?>
      document.getElementById('out').textContent='처리 중…';
      fetch(location.pathname, {method:'POST', body:fd})
        .then(function(r){return r.json();}).then(render)
        .catch(function(e){ document.getElementById('out').innerHTML='<p style="color:#c0392b">실패: '+e+'</p>'; });
    }
    </script></body></html><?php
    exit;
}

// ============================================================
//  사람용 화면 (HTML)
// ============================================================
$start = max(1, (int)($_GET['start_id'] ?? 1));
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
if (isset($_GET['end_id'])) $limit = max(1, min(500, (int)$_GET['end_id'] - $start + 1));   // 레거시
$live  = !empty($_GET['live']);
$items = loadFrom($pdo, $place, $CAT, $start, $limit);
$doneN = count(array_filter($items, fn($i) => $i['reviewed']));
$firstId = $items[0]['id'] ?? $start;
$lastId  = $items ? $items[count($items) - 1]['id'] : $start;
$nextId  = (count($items) === $limit) ? $lastId + 1 : null;
?><!doctype html><html lang="ko"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php if ($live): ?><meta http-equiv="refresh" content="6"><?php endif; ?>
<title>장소 리뷰 #<?= $firstId ?>~<?= $lastId ?></title>
<style>
  body { font-family: system-ui, "Apple SD Gothic Neo", sans-serif; max-width: 920px; margin: 0 auto; padding: 14px; color: #2c3e50; line-height: 1.5; }
  .bar { position: sticky; top: 0; background: #fff; padding: 10px 0; border-bottom: 2px solid #2980b9; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; z-index: 5; }
  .bar h2 { font-size: 17px; margin: 0; }
  .prog { font-size: 13px; color: #1e8449; font-weight: 700; }
  .bar a, .live { font-size: 12.5px; padding: 5px 10px; border: 1px solid #cdd6df; border-radius: 7px; text-decoration: none; color: #34495e; background: #f7f9fb; }
  .live.on { background: #27ae60; color: #fff; border-color: #229954; }
  .it { border: 1px solid #e3e8ee; border-radius: 10px; padding: 10px 12px; margin: 9px 0; }
  .it.done { border-color: #cfe6d6; background: #f6fbf8; }
  .it-hd { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .pid { color: #95a5a6; font-weight: 700; font-size: 13px; }
  .nm { font-weight: 700; font-size: 15px; }
  .cat { font-size: 11px; background: #eef; border-radius: 8px; padding: 1px 7px; }
  .badge { margin-left: auto; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 10px; }
  .badge.y { background: #27ae60; color: #fff; }
  .badge.n { background: #ecf0f1; color: #95a5a6; }
  .hlink { text-decoration: none; font-size: 13px; }
  .addr { font-size: 13px; color: #34495e; margin-top: 4px; }
  .addr.no { color: #e67e22; }
  .meta { font-size: 12px; color: #8a97a3; margin-top: 3px; }
  .mon { color: #2980b9; font-weight: 600; }
  .tags em { font-style: normal; background: #eef3f7; border-radius: 7px; padding: 1px 6px; font-size: 11px; margin-right: 4px; }
  .refs { margin-top: 7px; border-top: 1px dashed #eee; padding-top: 6px; }
  .ref { font-size: 12.5px; padding: 3px 0; }
  .ref .rt { font-size: 10px; font-weight: 700; color: #fff; background: #3498db; border-radius: 4px; padding: 1px 5px; margin-right: 5px; }
  .ref a { color: #2c3e50; text-decoration: none; }
  .ref a:hover { text-decoration: underline; color: #2980b9; }
  .ref .rsum { color: #95a5a6; font-size: 11.5px; }
  .empty { color: #aaa; text-align: center; padding: 40px; }
</style></head><body>

<div class="bar">
  <h2>🧭 장소 리뷰</h2>
  <span class="prog">#<?= $firstId ?>~<?= $lastId ?> · ✅완료 <?= $doneN ?>/<?= count($items) ?></span>
  <a href="?start_id=1&limit=<?= $limit ?><?= he($kq) ?><?= $live ? '&live=1' : '' ?>">⏮ 처음</a>
  <?php if ($nextId): ?><a href="?start_id=<?= $nextId ?>&limit=<?= $limit ?><?= he($kq) ?><?= $live ? '&live=1' : '' ?>">다음 <?= $limit ?> →</a><?php endif; ?>
  <a class="live <?= $live ? 'on' : '' ?>" href="?start_id=<?= $start ?>&limit=<?= $limit ?><?= he($kq) ?><?= $live ? '' : '&live=1' ?>"><?= $live ? '🔄 자동새로고침 ON' : '자동새로고침' ?></a>
  <a href="?log=1<?= he($kq) ?>">📝 변경내역</a>
</div>

<?php if (!$items): ?>
  <div class="empty">#<?= $start ?> 이상에 장소가 없습니다. (장소 id 는 1부터가 아니라 듬성듬성합니다 — <a href="?start_id=1&limit=<?= $limit ?><?= he($kq) ?>">처음으로</a>)</div>
<?php else: foreach ($items as $it): ?>
  <div class="it <?= $it['reviewed'] ? 'done' : '' ?>">
    <div class="it-hd">
      <span class="pid">#<?= $it['id'] ?></span>
      <span class="nm"><?= he($it['name']) ?></span>
      <span class="cat"><?= he($it['category_ko']) ?></span>
      <span class="badge <?= $it['reviewed'] ? 'y' : 'n' ?>"><?= $it['reviewed'] ? '✅ 완료' : '미검토' ?></span>
      <a class="hlink" href="?log=1&place_id=<?= $it['id'] ?><?= he($kq) ?>" title="이 장소 변경내역">📝</a>
    </div>
    <?php if ($it['address']): ?>
      <div class="addr">📍 <?= he($it['address']) ?> <span style="color:#b3bcc4">(<?= $it['geocode_status'] ?>)</span></div>
    <?php else: ?>
      <div class="addr no">📍 주소 없음 <span style="color:#b3bcc4">(<?= $it['geocode_status'] ?>)</span></div>
    <?php endif; ?>
    <div class="meta">
      <?php if ($it['months']): ?><span class="mon">🗓️ <?= he(implode('·', array_map(fn($m) => $m . '월', $it['months']))) ?></span> · <?php endif; ?>
      <?php if ($it['region']): ?><?= he($it['region']) ?> · <?php endif; ?>좌표 <?= $it['lat'] !== null ? round($it['lat'], 5) . ', ' . round($it['lng'], 5) : '없음' ?>
    </div>
    <?php if ($it['tags']): ?><div class="tags meta"><?php foreach ($it['tags'] as $t): ?><em><?= he($t) ?></em><?php endforeach; ?></div><?php endif; ?>
    <?php if ($it['refs']): ?>
      <div class="refs">
        <?php foreach ($it['refs'] as $rf): ?>
          <div class="ref"><span class="rt"><?= he($rf['type']) ?></span>
            <?php if ($rf['url']): ?><a href="<?= he($rf['url']) ?>" target="_blank" rel="noopener"><?= he($rf['title'] ?: '(제목 없음)') ?></a><?php else: ?><?= he($rf['title'] ?: '(제목 없음)') ?><?php endif; ?>
            <?php if ($rf['summary']): ?><div class="rsum"><?= he(mb_substr($rf['summary'], 0, 120)) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>

</body></html>
