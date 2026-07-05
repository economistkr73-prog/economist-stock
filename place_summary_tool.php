<?php
/**
 * place_summary_tool.php — 요약 일괄 작업 도구(key 가드, 소유자용)
 *
 *   내보내기: ?key=econ-sumtool&export=1[&cat=restaurant&region=서울&limit=30]
 *             → 요약 없는 (네이버) 장소를 [{id,name,address}] JSON 으로 출력
 *   가져오기: ?key=econ-sumtool&import=1   (POST 본문 = [{id,summary,features[],months[]}] JSON)
 *             → id 기준으로 attributes.summary/features + 월태그 일괄 저장
 *
 * 흐름: 서버 export → (Claude 챗에서 웹검색 요약 JSON 생성) → 서버 import.
 * ★id 를 그대로 왕복시켜 이름매칭 없이 정확히 업데이트.
 */
require_once "./env/cnt.inc";

$TOKEN = 'econ-sumtool';
if (($_GET['key'] ?? '') !== $TOKEN) { http_response_code(403); exit('forbidden'); }
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');

// ── 내보내기 ──────────────────────────────────────────────
if (!empty($_GET['export'])) {
    $cat    = trim((string)($_GET['cat'] ?? 'restaurant'));   // restaurant|stay|camping|travel|all
    $region = trim((string)($_GET['region'] ?? ''));          // 시도 일부(예: 서울)
    $limit  = max(1, min(200, (int)($_GET['limit'] ?? 30)));
    $onlyNaver = ($_GET['naver'] ?? '1') !== '0';             // 기본=네이버 수집분만

    $where = ["(JSON_EXTRACT(attributes,'$.summary') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(attributes,'$.summary'))='')"];
    $args  = [];
    if ($onlyNaver)       { $where[] = "naver_id IS NOT NULL"; }
    if ($cat !== 'all')   { $where[] = "category = ?"; $args[] = $cat; }
    if ($region !== '')   { $where[] = "(region_lv1 LIKE ? OR region_lv2 LIKE ?)"; $args[] = "%{$region}%"; $args[] = "%{$region}%"; }

    $sql = "SELECT id, name, address, region_lv1, region_lv2, review_count
            FROM place
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (review_count IS NOT NULL) DESC, review_count DESC, id
            LIMIT {$limit}";
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $addr = trim((string)$r['address']);
        if ($addr === '') $addr = trim(($r['region_lv1'] ?? '') . ' ' . ($r['region_lv2'] ?? ''));
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'address' => $addr];
    }
    echo json_encode(['ok' => true, 'count' => count($out), 'items' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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

    $done = []; $skip = [];
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
        $done[] = $id;
    }
    echo json_encode(['ok' => true, 'updated' => count($done), 'skipped' => count($skip),
                      'updated_ids' => $done, 'skips' => $skip], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'export=1 또는 import=1 필요']);
