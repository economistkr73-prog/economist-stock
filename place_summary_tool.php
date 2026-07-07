<?php
/**
 * place_summary_tool.php — 요약 일괄 작업 도구(key 가드, 소유자용)
 *
 *   내보내기: ?key=econ-sumtool&export=1&cat=travel&page=1[&per=15&region=서울&all=1&fmt=text]
 *             → 요약 없는 장소를 리뷰순 page당 per(기본15)개씩. 응답에 total/total_pages/has_next.
 *             ★import 하면 '요약없는' 집합이 줄어 page 어긋남 → 전 page 먼저읽고 일괄 import, 또는 항상 page=1.
 *   경로목록: ?key=econ-sumtool&trips=1
 *             → 저장된 경로(travel_trip) 목록 [{id,name,stops,picks,updated_at}]
 *   경로내보내기: ?key=econ-sumtool&trip=<id>[&all=1&cat=restaurant&fmt=json]
 *             → 그 경로의 지점+찜 장소를 "#id 이름 / 주소" 텍스트로 출력(기본 요약없는 곳만)
 *   가져오기: ?key=econ-sumtool&import=1   (POST 본문 = [{id,summary,features[],months[],refs[]}] JSON)
 *             → id 기준으로 attributes.summary/features + 월태그 + 참고링크(place_ref) 일괄 저장.
 *             refs=[{title,url,source_type?}] (source_type 미지정=도메인 추정·url_hash 중복무시)
 *
 * 흐름: 서버 export(또는 trip) → (Claude 챗에서 웹검색 요약 JSON 생성) → 서버 import.
 * ★id 를 그대로 왕복시켜 이름매칭 없이 정확히 업데이트.
 */
require_once "./env/cnt.inc";

$TOKEN = 'econ-sumtool';
if (($_GET['key'] ?? '') !== $TOKEN) { http_response_code(403); exit('forbidden'); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');   // page 넘길 때 클라(챗) fetch 캐시로 같은 페이지 반환되는 것 방지
mb_internal_encoding('UTF-8');

// 참고링크 URL → place_ref.source_type 분류(챗이 명시 안 하면 도메인으로 추정)
function sumtool_src_type(string $url): string {
    $u = strtolower($url);
    if (strpos($u, 'youtube.com') !== false || strpos($u, 'youtu.be') !== false) return 'youtube';
    if (strpos($u, '.go.kr') !== false || strpos($u, '.or.kr') !== false
        || strpos($u, 'visitkorea') !== false || strpos($u, 'korean.visitkorea') !== false) return 'official';
    if (strpos($u, 'blog.naver') !== false || strpos($u, 'tistory') !== false || strpos($u, 'brunch')  !== false
        || strpos($u, 'blog.') !== false     || strpos($u, 'postype') !== false || strpos($u, 'egloos') !== false
        || strpos($u, 'm.blog') !== false    || strpos($u, 'in.naver') !== false) return 'blog';
    return 'article';
}

// ── 저장된 경로 목록 ──────────────────────────────────────
if (!empty($_GET['trips'])) {
    $st = $pdo->query("SELECT id, name, route_json, picks_json, updated_at
                       FROM travel_trip ORDER BY updated_at DESC");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $route = $r['route_json'] ? (json_decode($r['route_json'], true) ?: []) : [];
        $picks = $r['picks_json'] ? (json_decode($r['picks_json'], true) ?: []) : [];
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'],
                  'stops' => count($route), 'picks' => count($picks),
                  'updated_at' => $r['updated_at']];
    }
    echo json_encode(['ok' => true, 'count' => count($out), 'trips' => $out],
                     JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── 경로(트립) 장소 내보내기 ─────────────────────────────
//  경로 지점 + 찜한 곳을 모아 "#id 이름 / 주소" 목록.
//   · ?trip=<id> : 저장된 경로(travel_trip)의 route_json.placeId + picks_json.id
//   · ?ids=1,2,3 : 화면에서 바로 넘긴 id 목록('경로코드' 버튼)
//  기본은 요약 없는 곳만(all=1 이면 전부). fmt=json 이면 [{id,name,address}].
if (isset($_GET['trip']) || isset($_GET['ids'])) {
    $ids = [];        // placeId 순서 유지 dedup
    $title = '경로';
    if (isset($_GET['ids'])) {
        foreach (preg_split('/[,\s]+/', (string)$_GET['ids']) as $s) {
            $id = (int)$s; if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
        }
    } else {
        $tid = (int)$_GET['trip'];
        $st = $pdo->prepare("SELECT name, route_json, picks_json FROM travel_trip WHERE id = ?");
        $st->execute([$tid]);
        $trip = $st->fetch(PDO::FETCH_ASSOC);
        if (!$trip) { http_response_code(404); echo json_encode(['ok' => false, 'msg' => '경로를 찾을 수 없습니다.']); exit; }
        $title = $trip['name'];
        $route = $trip['route_json'] ? (json_decode($trip['route_json'], true) ?: []) : [];
        $picks = $trip['picks_json'] ? (json_decode($trip['picks_json'], true) ?: []) : [];
        foreach ($route as $w) { $id = (int)($w['placeId'] ?? 0); if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id; }
        foreach ($picks as $p) { $id = (int)($p['id'] ?? 0);       if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id; }
    }

    $items = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q  = $pdo->prepare("SELECT id, name, address, region_lv1, region_lv2, category,
                                    JSON_UNQUOTE(JSON_EXTRACT(attributes,'$.summary')) AS summary
                             FROM place WHERE id IN ($ph)");
        $q->execute($ids);
        $map = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['id']] = $r;

        $all  = !empty($_GET['all']);
        $catF = trim((string)($_GET['cat'] ?? ''));
        foreach ($ids as $id) {                       // 경로/찜 등장 순서대로
            if (!isset($map[$id])) continue;
            $r = $map[$id];
            if (!$all && trim((string)$r['summary']) !== '') continue;   // 이미 요약 있으면 제외
            if ($catF !== '' && $r['category'] !== $catF) continue;
            $addr = trim((string)$r['address']);
            if ($addr === '') $addr = trim(($r['region_lv1'] ?? '') . ' ' . ($r['region_lv2'] ?? ''));
            $items[] = ['id' => $id, 'name' => $r['name'], 'address' => $addr, 'refs' => []];
        }
        // 기존 참고링크 첨부(export 와 동일)
        $iids = array_column($items, 'id');
        if ($iids) {
            $ph2 = implode(',', array_fill(0, count($iids), '?'));
            $r2  = $pdo->prepare("SELECT place_id, source_type, title, url FROM place_ref WHERE place_id IN ($ph2) ORDER BY place_id, id");
            $r2->execute($iids);
            $m2 = [];
            foreach ($r2->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                $pid = (int)$rr['place_id'];
                if (!isset($m2[$pid])) $m2[$pid] = [];
                if (count($m2[$pid]) >= 5) continue;
                $m2[$pid][] = ['source_type' => $rr['source_type'], 'title' => $rr['title'], 'url' => $rr['url']];
            }
            foreach ($items as &$it2) { $it2['refs'] = $m2[$it2['id']] ?? []; }
            unset($it2);
        }
    }

    if (($_GET['fmt'] ?? 'text') === 'json') {
        echo json_encode(['ok' => true, 'trip' => $title, 'count' => count($items), 'items' => $items],
                         JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    // 기본 = 텍스트(챗이 읽기 쉬운 "#id 이름 / 주소")
    header('Content-Type: text/plain; charset=utf-8');
    echo '※ ' . $title . ' · 대상 ' . count($items) . '곳'
       . (empty($_GET['all']) ? ' (요약없는 곳만)' : '') . "\n\n";
    foreach ($items as $it) {
        echo '#' . $it['id'] . ' ' . $it['name'] . ' / ' . $it['address'] . "\n";
        foreach ($it['refs'] as $rf) echo '    ↳ [' . $rf['source_type'] . '] ' . $rf['url'] . "\n";
    }
    exit;
}

// ── 내보내기(페이지네이션) ────────────────────────────────
//  ?export=1&cat=travel&page=1[&per=15&region=서울&all=1&naver=1&fmt=text]
//  요약 없는(기본) 장소를 리뷰순으로 page당 per개씩. 응답에 total/total_pages/has_next 포함.
//  ★오프셋 시프트: '요약없는' 필터라 import 하면 집합이 줄어 page 번호가 어긋난다.
//   안전 워크플로 = (A) 모든 page 먼저 읽고 → 마지막에 일괄 import, 또는 (B) 항상 page=1 재요청(self-drain).
if (!empty($_GET['export'])) {
    $cat    = trim((string)($_GET['cat'] ?? 'restaurant'));   // restaurant|stay|camping|travel|all
    $region = trim((string)($_GET['region'] ?? ''));          // 시도 일부(예: 서울)
    $per    = max(1, min(100, (int)($_GET['per'] ?? $_GET['limit'] ?? 15)));  // 페이지당(기본 15)
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $onlyNaver = ($_GET['naver'] ?? '1') !== '0';             // 기본=네이버 수집분만
    $all    = !empty($_GET['all']);                          // 요약 있는 곳까지 전부(집합 고정)

    $where = []; $args = [];
    if (!$all)          { $where[] = "(JSON_EXTRACT(attributes,'$.summary') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(attributes,'$.summary'))='')"; }
    if ($onlyNaver)     { $where[] = "naver_id IS NOT NULL"; }
    if ($cat !== 'all') { $where[] = "category = ?"; $args[] = $cat; }
    if ($region !== '') { $where[] = "(region_lv1 LIKE ? OR region_lv2 LIKE ?)"; $args[] = "%{$region}%"; $args[] = "%{$region}%"; }
    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // 총 개수 → 페이지 수(클로드가 몇 페이지까지 넘길지 판단)
    $cst = $pdo->prepare("SELECT COUNT(*) FROM place {$wsql}"); $cst->execute($args);
    $total  = (int)$cst->fetchColumn();
    $pages  = $total > 0 ? (int)ceil($total / $per) : 0;
    $offset = ($page - 1) * $per;

    $sql = "SELECT id, name, address, region_lv1, region_lv2, review_count
            FROM place {$wsql}
            ORDER BY (review_count IS NOT NULL) DESC, review_count DESC, id
            LIMIT {$per} OFFSET {$offset}";
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $addr = trim((string)$r['address']);
        if ($addr === '') $addr = trim(($r['region_lv1'] ?? '') . ' ' . ($r['region_lv2'] ?? ''));
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'address' => $addr, 'refs' => []];
    }

    // ★각 장소의 기존 참고링크(아덴트뉴스 기사 등) 첨부 — 챗이 블라인드 웹검색 대신 실제 출처를 먼저 읽게.
    //   대부분의 여행지는 이미 기사 ref 가 있으므로, 그 원문을 근거로 요약하고 링크는 새로 안 만들어도 됨.
    if ($out) {
        $ids = array_column($out, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $rst = $pdo->prepare("SELECT place_id, source_type, title, url FROM place_ref WHERE place_id IN ($ph) ORDER BY place_id, id");
        $rst->execute($ids);
        $byId = [];
        foreach ($rst->fetchAll(PDO::FETCH_ASSOC) as $rr) {
            $pid = (int)$rr['place_id'];
            if (!isset($byId[$pid])) $byId[$pid] = [];
            if (count($byId[$pid]) >= 5) continue;   // 장소당 최대 5개
            $byId[$pid][] = ['source_type' => $rr['source_type'], 'title' => $rr['title'], 'url' => $rr['url']];
        }
        foreach ($out as &$o) { $o['refs'] = $byId[$o['id']] ?? []; }
        unset($o);
    }

    if (($_GET['fmt'] ?? 'json') === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "※ {$cat} · 총 {$total}곳 · {$page}/{$pages}페이지(page당 {$per})" . ($all ? '' : ' · 요약없는 곳만') . "\n\n";
        foreach ($out as $it) {
            echo '#' . $it['id'] . ' ' . $it['name'] . ' / ' . $it['address'] . "\n";
            foreach ($it['refs'] as $rf) echo '    ↳ [' . $rf['source_type'] . '] ' . $rf['url'] . "\n";
        }
        if ($page < $pages) echo "\n▶ 다음 페이지: &page=" . ($page + 1) . "\n";
        exit;
    }
    echo json_encode(['ok' => true, 'cat' => $cat, 'total' => $total,
                      'page' => $page, 'per_page' => $per, 'total_pages' => $pages,
                      'has_next' => ($page < $pages), 'count' => count($out), 'items' => $out],
                     JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── 가져오기 ──────────────────────────────────────────────
if (!empty($_GET['import'])) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (isset($data['items']) && is_array($data['items'])) $data = $data['items'];
    if (!is_array($data) || !$data) { echo json_encode(['ok' => false, 'msg' => 'JSON 배열이 아닙니다.']); exit; }

    $sel = $pdo->prepare("SELECT attributes FROM place WHERE id=?");
    $upd = $pdo->prepare("UPDATE place SET attributes=:a, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
    $tag = $pdo->prepare("INSERT IGNORE INTO place_tag (place_id,kind,tag) VALUES (?,?,?)");
    // 참고링크: url_hash(SHA1(url)) GENERATED + UNIQUE(place_id,url_hash) → 같은 링크 중복 무시
    $refIns = $pdo->prepare("INSERT IGNORE INTO place_ref (place_id, source_type, title, url) VALUES (?,?,?,?)");

    $done = []; $skip = []; $refsAdded = 0;
    foreach ($data as $it) {
        $id  = (int)($it['id'] ?? 0);
        $sum = trim((string)($it['summary'] ?? ''));
        if ($id <= 0 || $sum === '') { $skip[] = ['id' => $id, 'why' => 'id/summary 없음']; continue; }
        $sel->execute([$id]);
        $cur = $sel->fetchColumn();
        if ($cur === false) { $skip[] = ['id' => $id, 'why' => '장소 없음']; continue; }
        $at = $cur ? (json_decode((string)$cur, true) ?: []) : [];

        $feats = [];
        foreach ((array)($it['features'] ?? []) as $f) { $f = trim((string)$f); if ($f !== '') $feats[] = $f; }
        $at['summary']  = $sum;
        $at['features'] = array_values(array_unique(array_merge((array)($at['features'] ?? []), $feats)));
        $upd->execute([':a' => json_encode($at, JSON_UNESCAPED_UNICODE), ':id' => $id]);
        foreach ((array)($it['months'] ?? []) as $m) { $m = (int)$m; if ($m >= 1 && $m <= 12) $tag->execute([$id, 'month', (string)$m]); }

        // 참고링크(refs[] 또는 단수 ref) — http(s) URL 만, source_type 미지정이면 도메인 추정
        $refList = isset($it['refs']) && is_array($it['refs']) ? $it['refs']
                 : (isset($it['ref']) && is_array($it['ref']) ? [$it['ref']] : []);
        foreach ($refList as $rf) {
            if (!is_array($rf)) continue;
            $url = trim((string)($rf['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
            $title = trim((string)($rf['title'] ?? ''));
            $stype = (string)($rf['source_type'] ?? '');
            if (!in_array($stype, ['article','youtube','blog','official','manual'], true)) $stype = sumtool_src_type($url);
            $refIns->execute([$id, $stype, ($title !== '' ? mb_substr($title, 0, 300) : $url), $url]);
            if ($refIns->rowCount() > 0) $refsAdded++;
        }
        $done[] = $id;
    }
    echo json_encode(['ok' => true, 'updated' => count($done), 'skipped' => count($skip),
                      'refs_added' => $refsAdded, 'updated_ids' => $done, 'skips' => $skip], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 신규 장소 등록 + 기사(ref) 이동 ──────────────────────
//  레거시 오매칭 복구용: 잘못 붙은 기사가 실제로 다루는 장소를 신규 등록하고, 그 기사를 원래 장소에서 떼어 옮긴다.
//  POST 본문 = [{ name, category(travel|restaurant|stay|camping|etc), region("경기 오산"),
//                 summary, features[], months[], tags[],
//                 ref:{title,url,source_type?}, detach_from:<원래 place_id> }]
//  지오코딩=카카오 키워드(name[+region]). dedup=name+region_lv2(같은곳 재등록시 갱신).
if (!empty($_GET['add'])) {
    if (file_exists(__DIR__ . '/env/maps.inc'))  require_once __DIR__ . '/env/maps.inc';   // 네이버 지오코딩 키(주소→좌표)
    if (file_exists(__DIR__ . '/env/kakao.inc')) require_once __DIR__ . '/env/kakao.inc';  // 카카오 키워드(POI)
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (isset($data['items']) && is_array($data['items'])) $data = $data['items'];
    if (!is_array($data) || !$data) { echo json_encode(['ok' => false, 'msg' => 'JSON 배열이 아닙니다.']); exit; }

    $place  = new Place($pdo);
    $tagIns = $pdo->prepare("INSERT IGNORE INTO place_tag (place_id,kind,tag) VALUES (?,?,?)");
    $refIns = $pdo->prepare("INSERT IGNORE INTO place_ref (place_id, source_type, title, url) VALUES (?,?,?,?)");
    $refDel = $pdo->prepare("DELETE FROM place_ref WHERE place_id=? AND url=?");

    $res = [];
    foreach ($data as $it) {
        // to_id 가 있으면 신규 생성 없이 '기존 장소'에 작업: 기사 이동 + (선택)좌표·주소·요약 수정 + 태그/월 보강
        $toId = (int)($it['to_id'] ?? 0);
        if ($toId > 0) {
            $upd = [];
            // 이름 교정(제공 시) — 원본 DB에 리스티클 제목("12월여행" 등) 그대로 들어간 오염 케이스 정정용
            $newName = trim((string)($it['name'] ?? ''));
            if ($newName !== '' && $place->adminUpdate($toId, $newName, null, null, null, null)) $upd[] = 'name';
            // 좌표·주소 수정(address 또는 lat/lng 제공 시) — 레거시 오주소/오좌표 교정
            $lat = null; $lng = null; $addr = trim((string)($it['address'] ?? ''));
            if (isset($it['lat'], $it['lng']) && $it['lat'] !== null && $it['lng'] !== null) { $lat = (float)$it['lat']; $lng = (float)$it['lng']; }
            elseif ($addr !== '') { $g = GeoCoder::geocode('naver', $addr); if (!empty($g['ok']) && isset($g['lat'], $g['lng'])) { $lat = (float)$g['lat']; $lng = (float)$g['lng']; } }
            if ($lat !== null) {
                $lv1 = ''; $lv2 = '';
                if ($addr !== '') { $pp = preg_split('/\s+/', $addr); $lv1 = $pp[0] ?? ''; $lv2 = $pp[1] ?? ''; }
                $pdo->prepare("UPDATE place SET lat=?, lng=?, geocode_status='ok',
                                 address=COALESCE(NULLIF(?, ''), address),
                                 region_lv1=COALESCE(NULLIF(?, ''), region_lv1),
                                 region_lv2=COALESCE(NULLIF(?, ''), region_lv2) WHERE id=?")
                    ->execute([$lat, $lng, $addr, $lv1, $lv2, $toId]);
                $upd[] = 'coords';
            }
            // 요약 수정(제공 시)
            $sum = trim((string)($it['summary'] ?? ''));
            if ($sum !== '') {
                $sel0 = $pdo->prepare("SELECT attributes FROM place WHERE id=?"); $sel0->execute([$toId]);
                $at0 = ($c0 = $sel0->fetchColumn()) ? (json_decode((string)$c0, true) ?: []) : [];
                $at0['summary'] = $sum;
                $ff = []; foreach ((array)($it['features'] ?? []) as $f) { $f = trim((string)$f); if ($f !== '') $ff[] = $f; }
                if ($ff) $at0['features'] = array_values(array_unique(array_merge((array)($at0['features'] ?? []), $ff)));
                $pdo->prepare("UPDATE place SET attributes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([json_encode($at0, JSON_UNESCAPED_UNICODE), $toId]);
                $upd[] = 'summary';
            }
            $moved = false;
            $rf = $it['ref'] ?? null;
            if (is_array($rf) && !empty($rf['url'])) {
                $url   = trim((string)$rf['url']);
                $stype = in_array(($rf['source_type'] ?? ''), ['article','youtube','blog','official','manual'], true)
                       ? $rf['source_type'] : sumtool_src_type($url);
                // ★삭제 먼저(라이브 place_ref UNIQUE=url_hash 단독 → 기존이 그 url 을 쥐고 있으면 신규 INSERT 가 IGNORE 됨)
                if (!empty($it['detach_from'])) { $refDel->execute([(int)$it['detach_from'], $url]); $moved = true; }
                $refIns->execute([$toId, $stype, ($rf['title'] ?? $url), $url]);
            }
            foreach ((array)($it['tags'] ?? []) as $t) { $t = trim((string)$t); if ($t !== '') $tagIns->execute([$toId, 'theme', $t]); }
            foreach ((array)($it['months'] ?? []) as $m) { $m = (int)$m; if ($m >= 1 && $m <= 12) $tagIns->execute([$toId, 'month', (string)$m]); }
            $res[] = ['ok' => true, 'id' => $toId, 'to_existing' => true, 'updated' => $upd, 'ref_moved_from' => ($moved ? (int)$it['detach_from'] : null)];
            continue;
        }

        $name = trim((string)($it['name'] ?? ''));
        if ($name === '') { $res[] = ['ok' => false, 'why' => 'name 없음']; continue; }
        $cat = in_array(($it['category'] ?? ''), ['travel','restaurant','stay','camping','etc'], true) ? $it['category'] : 'travel';
        $region = trim((string)($it['region'] ?? ''));

        // 지오코딩 우선순위: ①명시 lat/lng  ②address(네이버 정밀·동명 다른장소 오매칭 방지)  ③이름+지역(카카오 POI)
        $lat = null; $lng = null; $addr = trim((string)($it['address'] ?? '')); $lv1 = ''; $lv2 = '';
        if (isset($it['lat'], $it['lng']) && $it['lat'] !== null && $it['lng'] !== null) {
            $lat = (float)$it['lat']; $lng = (float)$it['lng'];
        }
        if ($lat === null && $addr !== '') {
            $g = GeoCoder::geocode('naver', $addr);
            if (!empty($g['ok']) && isset($g['lat'], $g['lng'])) { $lat = (float)$g['lat']; $lng = (float)$g['lng']; }
        }
        if ($lat === null) {
            $kw = GeoCoder::searchKeyword(($region !== '' ? $region . ' ' : '') . $name, 3);
            if (!empty($kw['ok']) && !empty($kw['items'])) {
                $hit = $kw['items'][0];
                $lat = $hit['lat'] ?? null;   $lng = $hit['lng'] ?? null;
                if ($addr === '') $addr = trim((string)($hit['address'] ?? ''));
            }
        }
        if ($addr !== '') { $pp = preg_split('/\s+/', $addr); $lv1 = $pp[0] ?? ''; $lv2 = $pp[1] ?? ''; }

        // attributes(요약·특성) — ★dedup 이 기존 장소에 매칭될 수 있으므로, upsertPlace 가 attributes 를
        // 통째로 덮어쓰기 전에 기존 값을 먼저 읽어와 병합한다(신규 생성이면 조회결과 없음=빈 배열에서 시작).
        $dedupKey = Place::makeDedupKey($name, $lv2);
        $exSel = $pdo->prepare("SELECT attributes FROM place WHERE dedup_key = ?");
        $exSel->execute([$dedupKey]);
        $attrs = ($exAttr = $exSel->fetchColumn()) ? (json_decode((string)$exAttr, true) ?: []) : [];
        $attrs['source_site'] = $attrs['source_site'] ?? 'ardentnews';
        $sum   = trim((string)($it['summary'] ?? ''));
        if ($sum !== '') $attrs['summary'] = $sum;
        $feats = [];
        foreach ((array)($it['features'] ?? []) as $f) { $f = trim((string)$f); if ($f !== '') $feats[] = $f; }
        if ($feats) $attrs['features'] = array_values(array_unique(array_merge((array)($attrs['features'] ?? []), $feats)));

        $pid = $place->upsertPlace([
            'name' => $name, 'category' => $cat, 'address' => $addr,
            'region_lv1' => $lv1, 'region_lv2' => $lv2, 'lat' => $lat, 'lng' => $lng,
            'geocode_status' => ($lat !== null ? 'ok' : 'pending'), 'attributes' => $attrs,
        ]);

        // 기사(ref) 붙이고, detach_from 이 있으면 원래 장소에서 그 URL 제거(=이동)
        $moved = false;
        $rf = $it['ref'] ?? null;
        if (is_array($rf) && !empty($rf['url'])) {
            $url   = trim((string)$rf['url']);
            $stype = in_array(($rf['source_type'] ?? ''), ['article','youtube','blog','official','manual'], true)
                   ? $rf['source_type'] : sumtool_src_type($url);
            // ★삭제 먼저(라이브 place_ref UNIQUE=url_hash 단독 → 기존이 그 url 을 쥐고 있으면 신규 INSERT 가 IGNORE 됨)
            if (!empty($it['detach_from'])) { $refDel->execute([(int)$it['detach_from'], $url]); $moved = true; }
            $refIns->execute([$pid, $stype, ($rf['title'] ?? $url), $url]);
        }
        // 태그·월
        foreach ((array)($it['tags'] ?? []) as $t) { $t = trim((string)$t); if ($t !== '') $tagIns->execute([$pid, 'theme', $t]); }
        foreach ((array)($it['months'] ?? []) as $m) { $m = (int)$m; if ($m >= 1 && $m <= 12) $tagIns->execute([$pid, 'month', (string)$m]); }

        $res[] = ['ok' => true, 'id' => (int)$pid, 'name' => $name, 'category' => $cat,
                  'geocoded' => ($lat !== null), 'lat' => $lat, 'lng' => $lng, 'address' => $addr,
                  'ref_moved_from' => ($moved ? (int)$it['detach_from'] : null)];
    }
    echo json_encode(['ok' => true, 'results' => $res], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'export=1 또는 import=1 필요']);
