<?php
/**
 * stock/lib/note.php — 종목 개요·태그 단일본 (2026-08-30)
 *
 * 「이 종목이 뭐 하는 회사인가」(개요)와 「어느 무리인가」(#HBM #반도체 태그)를 사용자가
 * 직접 적어 두는 층. 주인은 <b>종목</b>이다(chart_hline 과 같은 자리) — 화면·탭 어디에도
 * 매이지 않아 재무 상세에서 적은 것이 관심종목·어닝 탭에도 그대로 뜬다.
 *
 * ★ 왜 새 표인가 — all_stock_info 는 네이버 크론의 DELETE NOT IN 과 두 곳의 TRUNCATE 가
 *   사용자 입력을 증발시키고(utf8mb3 조인 15배 사고의 그 표), stock_financial 은 연도×보고서
 *   행이라 종목 하나의 개요를 넣을 자리가 없다. 어느 크론도 안 건드리는 자기 표가 있어야
 *   「다른 곳에서 쓴다」가 성립한다.
 * ★ 태그를 문자열 컬럼 하나로 담지 않는다 — 주소록 그룹(문자열 연결)에서 치른 값:
 *   이름을 바꾸면 전 행 UPDATE, 집계는 LIKE 스캔. gift_group 처럼 사전(id) + 매핑으로 둔다.
 * ★ DDL 은 요청 경로에 넣지 않는다(재무분석 느린 렌더의 용의자가 매 요청 ensureTables 다) —
 *   표 3개는 pf_note_schema() 를 서버에서 한 번 불러 만든다.
 * ★ 표가 아직 없어도 화면은 죽지 않는다 — 읽기는 전부 빈 값 폴백(BadgeFeat::vals 와 같은 규칙).
 *
 * 표: stock_note(종목당 1행 · headline/summary) · stock_tag(사전) · stock_tag_map(매핑 · FK CASCADE)
 * 소비처: 재무 상세 개요 카드 · 스크리너 &tag= 필터 · 관심종목/어닝 탭 칩(BadgeFeat 'tag')
 */

/** 표 생성 — 서버에서 1회만 부른다 (요청 경로 금지). */
function pf_note_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_note (
        stock_code VARCHAR(10) NOT NULL PRIMARY KEY COMMENT '6자리 종목코드 (우선주는 본주로 옮겨 온 뒤라 본주 코드)',
        headline   VARCHAR(200) NOT NULL DEFAULT '' COMMENT '한 줄 요약',
        summary    TEXT NOT NULL COMMENT '사업 개요 — 사용자 직접 입력',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='종목 개요 (stock/lib/note.php)'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_tag (
        id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(30) NOT NULL COMMENT '태그 이름 (# 없이)',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='종목 태그 사전 (stock/lib/note.php)'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_tag_map (
        stock_code VARCHAR(10) NOT NULL,
        tag_id     INT NOT NULL,
        PRIMARY KEY (stock_code, tag_id),
        KEY ix_tag (tag_id),
        CONSTRAINT fk_stm_tag FOREIGN KEY (tag_id) REFERENCES stock_tag (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='종목-태그 매핑 (stock/lib/note.php)'");
    /* 히스토리 — append-only (krx_surge 캐시 / krx_surge_event 기록의 분리와 같은 패턴).
     * stock_note 는 「지금 개요」(화면·툴팁이 읽는 곳), 여기는 「그때 내가 뭐라고 이해했나」의 장부다.
     * ★태그는 사전(id)을 참조하지 않고 «그때의 글자»로 동결한다(gift_item 값 복사 패턴) —
     *   참조로 두면 태그 이름변경·고아 정리가 지난 기록을 함께 바꾼다. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_note_hist (
        id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        stock_code  VARCHAR(10) NOT NULL,
        headline    VARCHAR(200) NOT NULL DEFAULT '',
        summary     TEXT NOT NULL,
        tags_text   VARCHAR(400) NOT NULL DEFAULT '' COMMENT '그때의 태그 스냅샷 — #HBM #반도체',
        noted_at    DATETIME NULL COMMENT '그 판을 마지막으로 고친 시각 (옛 updated_at)',
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '새 판으로 교체된 시각',
        KEY ix_code (stock_code, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='종목 개요 히스토리 — append-only (stock/lib/note.php)'");
}

/** 한 종목의 개요 — 없으면 null. */
function pf_note_get(PDO $pdo, string $code): ?array
{
    try {
        $st = $pdo->prepare("SELECT headline, summary, updated_at FROM stock_note WHERE stock_code = ?");
        $st->execute([$code]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }   // 표 없음(첫 배포) — 카드가 빈 폼으로 뜬다
}

/** 여러 종목의 헤드라인 — [code => headline]. 목록 툴팁용(빈 헤드라인은 뺀다). */
function pf_note_head_map(PDO $pdo, array $codes): array
{
    $codes = array_values(array_unique(array_filter($codes)));
    if (!$codes) return [];
    try {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT stock_code, headline FROM stock_note
                              WHERE stock_code IN ($in) AND headline <> ''");
        $st->execute($codes);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['stock_code']] = $r['headline'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/** 저장 — 둘 다 비면 행을 지운다(빈 행이 「개요 있음」으로 세지면 안 된다). */
function pf_note_save(PDO $pdo, string $code, string $headline, string $summary): void
{
    $headline = trim($headline);
    $summary  = trim($summary);
    if ($headline === '' && $summary === '') {
        $pdo->prepare("DELETE FROM stock_note WHERE stock_code = ?")->execute([$code]);
        return;
    }
    /* ★updated_at 을 명시로 찍는다 — 태그만 바뀐 저장은 headline·summary 가 같은 값이라
     *   MySQL 이 UPDATE 를 건너뛰어 ON UPDATE CURRENT_TIMESTAMP 가 안 돈다(job=quotes 의
     *   uDate 함정과 같은 자리). updated_at 은 「값이 바뀐 시각」이 아니라 「마지막 저장 시각」이다. */
    $st = $pdo->prepare("INSERT INTO stock_note (stock_code, headline, summary) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE headline = VALUES(headline), summary = VALUES(summary),
                                                 updated_at = NOW()");
    $st->execute([$code, mb_substr($headline, 0, 200), $summary]);
}

/**
 * 태그 입력 파싱 — '#HBM #반도체' · 'HBM, 반도체' 둘 다 받는다.
 * # · 쉼표 · 공백이 구분자. 30자 초과는 자르고, 12개 초과는 버린다(목록 칩이 줄을 넘긴다).
 * 중복은 대소문자 무시로 접는다(사전 UNIQUE 가 utf8mb4_general_ci 라 DB 도 같은 판정).
 */
function pf_tag_parse(string $raw): array
{
    $out  = [];
    $seen = [];
    foreach (preg_split('/[#,\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $t = mb_substr(trim($t), 0, 30);
        if ($t === '') continue;
        $k = mb_strtolower($t);
        if (isset($seen[$k])) continue;
        $seen[$k] = 1;
        $out[] = $t;
        if (count($out) >= 12) break;
    }
    return $out;
}

/**
 * 한 종목의 태그를 통째로 갈아 끼운다 — 사전 upsert + 매핑 재구성 + 고아 태그 정리.
 * ★고아(어느 종목에도 안 달린) 태그는 사전에서 지운다 — 태그 목록·자동완성에 남으면
 *   「결과가 없는 제안」이 된다(acnav 의 「제안은 반드시 결과가 있는 것」과 같은 규칙).
 */
function pf_tag_save(PDO $pdo, string $code, array $names): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM stock_tag_map WHERE stock_code = ?")->execute([$code]);
        if ($names) {
            $ins = $pdo->prepare("INSERT IGNORE INTO stock_tag (name) VALUES (?)");
            $sel = $pdo->prepare("SELECT id FROM stock_tag WHERE name = ?");
            $map = $pdo->prepare("INSERT IGNORE INTO stock_tag_map (stock_code, tag_id) VALUES (?, ?)");
            foreach ($names as $n) {
                $ins->execute([$n]);
                $sel->execute([$n]);                       // IGNORE 라 lastInsertId 를 못 믿는다
                $id = (int)$sel->fetchColumn();
                if ($id > 0) $map->execute([$code, $id]);
            }
        }
        $pdo->exec("DELETE t FROM stock_tag t LEFT JOIN stock_tag_map m ON m.tag_id = t.id
                     WHERE m.tag_id IS NULL");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 저장 + 히스토리 — 내용이 실제로 바뀔 때만 옛 판을 stock_note_hist 에 먼저 남긴다.
 * API(module=note)가 부르는 유일한 저장 입구. 같은 내용을 다시 저장하면 아무 행도 안 생긴다
 * (「저장」을 습관처럼 눌러도 기록이 소음으로 차지 않게).
 * ★hist 는 append-only — 앱 코드 어디서도 UPDATE·DELETE 하지 않는다(CLAUDE.md 규칙 4 와 같은 자리).
 * ★개요를 비워 지워도 옛 판은 hist 에 남는다 — 「지우기」와 「기록 지우기」를 섞지 않는다.
 */
function pf_note_save_with_hist(PDO $pdo, string $code, string $headline, string $summary, array $tags): void
{
    $headline = trim($headline);
    $summary  = trim($summary);

    $old     = pf_note_get($pdo, $code);
    $oldTags = pf_tags_of($pdo, [$code])[$code] ?? [];

    // 바뀌었나 — 태그는 순서·대소문자 무시로 견준다(사전이 utf8mb4_general_ci 라 DB 판정과 같게)
    $a = array_map('mb_strtolower', $oldTags);
    $b = array_map('mb_strtolower', $tags);
    sort($a);
    sort($b);
    $hadOld  = ($old !== null) || $oldTags;
    $changed = $hadOld
        && (trim((string)($old['headline'] ?? '')) !== $headline
         || trim((string)($old['summary']  ?? '')) !== $summary
         || $a !== $b);

    /* ★같은 날 수정은 히스토리에 안 남긴다(2026-08-30 사용자 — 「오늘 쓰고 오늘 수정해도
     * 히스토리가 남는 건 아닌 것 같아」). 하루 안의 재저장은 «다른 판»이 아니라 같은 판을
     * 다듬는 것이다 — 히스토리의 물음(「그때는 뭐라고 이해했나」)에서 '그때'는 다른 날이다.
     * 옛 판의 마지막 저장일이 오늘보다 전날일 때만 남긴다. 날을 모르는 판(태그만 있던
     * 종목 — stock_note 행이 없어 updated_at 이 없다)도 안 남긴다 — 소음 쪽으로 틀리지 않게. */
    $oldDay  = substr((string)($old['updated_at'] ?? ''), 0, 10);
    $archive = $changed && $oldDay !== '' && $oldDay < date('Y-m-d');

    if ($archive) {
        $st = $pdo->prepare("INSERT INTO stock_note_hist (stock_code, headline, summary, tags_text, noted_at)
                             VALUES (?, ?, ?, ?, ?)");
        $st->execute([
            $code,
            trim((string)($old['headline'] ?? '')),
            trim((string)($old['summary'] ?? '')),
            $oldTags ? '#' . implode(' #', $oldTags) : '',
            $old['updated_at'] ?? null,
        ]);
    }
    pf_note_save($pdo, $code, $headline, $summary);
    pf_tag_save($pdo, $code, $tags);
}

/** 지난 개요 — 최신 교체분부터. [['headline','summary','tags_text','noted_at','archived_at'],…] */
function pf_note_hist(PDO $pdo, string $code, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    try {
        $st = $pdo->prepare("SELECT headline, summary, tags_text, noted_at, archived_at
                               FROM stock_note_hist WHERE stock_code = ? ORDER BY id DESC LIMIT $limit");
        $st->execute([$code]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 여러 종목의 태그 — [code => ['HBM','반도체',…]] (이름 가나다순). */
function pf_tags_of(PDO $pdo, array $codes): array
{
    $codes = array_values(array_unique(array_filter($codes)));
    if (!$codes) return [];
    try {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT m.stock_code, t.name FROM stock_tag_map m
                              JOIN stock_tag t ON t.id = m.tag_id
                             WHERE m.stock_code IN ($in) ORDER BY t.name");
        $st->execute($codes);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['stock_code']][] = $r['name'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/** 태그 전체 — [['name','cnt'],…] 건수 내림차순. 스크리너 태그 줄이 쓴다. */
function pf_tag_all(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT t.name, COUNT(*) cnt FROM stock_tag t
                              JOIN stock_tag_map m ON m.tag_id = t.id
                             GROUP BY t.id, t.name ORDER BY cnt DESC, t.name")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 그 태그가 달린 종목코드들 — 스크리너 &tag= 필터의 모집단. */
function pf_tag_codes(PDO $pdo, string $name): array
{
    try {
        $st = $pdo->prepare("SELECT m.stock_code FROM stock_tag t
                              JOIN stock_tag_map m ON m.tag_id = t.id WHERE t.name = ?");
        $st->execute([$name]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
}

/** 칩 CSS — 처음 한 번만 심는다 (모듈 소유 · 화면마다 복제하지 않는다). */
function pf_tag_css(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    echo '<style>
.stag{display:inline-block;padding:1px 7px;margin:0 2px 2px 0;border-radius:9px;background:#eef3f8;
  color:#33557a;font-size:11px;font-weight:600;text-decoration:none;white-space:nowrap;line-height:1.6}
.stag:hover{background:#dde8f3}
.stag.on{background:#33557a;color:#fff}
/* ★목록 종목 칸 안 — table.pf td.stk a 가 display:block·14.5px·볼드라(특이도 우위) 칩이
 * 종목명 크기로 한 칩 한 줄이 된다(2026-08-30 사용자 지적). a.stag 로 되눌러 작게 인라인.
 * 태그는 «셋째 줄»(.stagln) — 코드 줄에 섞으면 칸이 옆으로 밀린다(같은 날 사용자 지시). */
table.pf td.stk .stag,table.pf td.stk a.stag{display:inline-block;font-size:10px;font-weight:600;
  color:#33557a;padding:0 6px;margin:0 3px 0 0;line-height:1.7;vertical-align:middle;
  max-width:76px;overflow:hidden;text-overflow:ellipsis}
table.pf td.stk a.stag:hover{color:#1d5c93;background:#dde8f3}
table.pf td.stk .stagln{display:block;margin-top:2px;line-height:1.4}
</style>';
}

/**
 * 태그 칩 HTML — 클릭하면 재무 스크리너를 그 태그로 거른다(어느 화면에서든 같은 문).
 * $cur 는 지금 걸려 있는 태그(스크리너 태그 줄의 강조).
 * $max > 0 이면 그 개수까지만 그리고 나머지는 「+N」(툴팁에 전부) — 목록 종목 칸은 좁아서
 * 태그가 4개 이상이면 줄을 잡아먹는다(2026-08-30 사용자). 전부 보는 자리는 상세 카드다.
 */
function pf_tag_chips(array $names, string $cur = '', int $max = 0): string
{
    $rest = [];
    if ($max > 0 && count($names) > $max) {
        $rest  = array_slice($names, $max);
        $names = array_slice($names, 0, $max);
    }
    $h = '';
    foreach ($names as $n) {
        $h .= '<a class="stag' . ($n === $cur ? ' on' : '') . '" href="/stock/index.php?mode=fund&go=1&tag='
            . rawurlencode($n) . '" title="' . pf_h('태그 #' . $n . ' — 이 태그가 달린 종목을 재무 스크리너에서 봅니다')
            . '">#' . pf_h($n) . '</a>';
    }
    if ($rest) {
        $h .= '<span class="stag" style="cursor:default" title="'
            . pf_h('나머지 태그: #' . implode(' #', $rest) . ' — 전부는 종목 상세에서 봅니다')
            . '">+' . count($rest) . '</span>';
    }
    return $h;
}
?>
