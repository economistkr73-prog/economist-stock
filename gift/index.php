<?php
/**
 * gift/index.php — 선물 발송 관리 (라우터 + 화면)
 *
 * 요건정의서: 선물관리시스템_요건정의서.md
 *   mode=dash   대시보드
 *   mode=batch  메인 발송 리스트 (작업중 회차 편집)
 *   mode=cust   고객 명단 관리
 *   mode=prod   물품 관리
 *   mode=hist   이력 목록
 *   mode=histv  이력 상세 (읽기 전용)
 *   mode=import 현행 엑셀 가져오기
 *
 * DB 접근은 전부 classes/Gift.class 를 경유한다. 쓰기는 gift/api.php.
 * ★ PHP 태그는 파일 처음과 끝에만 둔다 (프로젝트 규약) — HTML 은 echo / nowdoc 으로 낸다.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/nav.inc';
require_login();

$gift = new Gift($pdo);
$gift->ensureTable();

if (empty($_SESSION['gift_csrf'])) $_SESSION['gift_csrf'] = bin2hex(random_bytes(16));

$mode   = (string)($_GET['mode'] ?? 'dash');
$routes = [
    'dash'   => 'gift_page_dash',
    'batch'  => 'gift_page_batch',
    'cust'   => 'gift_page_cust',
    'prod'   => 'gift_page_prod',
    'hist'   => 'gift_page_hist',
    'histv'  => 'gift_page_histv',
    'import' => 'gift_page_import',
];
$fn = $routes[$mode] ?? 'gift_page_dash';
$fn($gift);

// ==========================================================
// 공통 헬퍼
// ==========================================================
function gift_h($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 금액·수량 — 천단위 콤마, 정수 원 단위 */
function gift_won($n): string
{
    return number_format((int)$n);
}

function gift_head(string $title, string $tab): void
{
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
    echo '<title>' . gift_h($title) . ' — 선물 발송 관리</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    nav_css();
    gift_css();
    echo '</head><body data-page="' . gift_h($tab) . '">';
    render_nav('gift');
    gift_tabs($tab);
    echo '<div class="gf-body">';
}

function gift_foot(): void
{
    echo '</div>';
    echo '<script>const GF_CSRF=' . json_encode($_SESSION['gift_csrf'] ?? '') . ';</script>';
    gift_js();
    echo '</body></html>';
}

function gift_tabs(string $tab): void
{
    $menu = [
        'dash'   => ['대시보드',   '/gift/index.php?mode=dash'],
        'batch'  => ['발송 회차',  '/gift/index.php?mode=batch'],
        'cust'   => ['고객 명단',  '/gift/index.php?mode=cust'],
        'prod'   => ['물품',       '/gift/index.php?mode=prod'],
        'hist'   => ['이력',       '/gift/index.php?mode=hist'],
        'import' => ['가져오기',   '/gift/index.php?mode=import'],
    ];
    $active = ($tab === 'histv') ? 'hist' : $tab;
    echo '<div class="gf-tabs">';
    foreach ($menu as $k => $m) {
        $c = ($k === $active) ? ' class="on"' : '';
        echo '<a href="' . gift_h($m[1]) . '"' . $c . '>' . gift_h($m[0]) . '</a>';
    }
    echo '</div>';
}

/** 안내 문구 한 줄 */
function gift_note(string $msg, string $kind = ''): void
{
    echo '<div class="gf-note' . ($kind ? ' ' . $kind : '') . '">' . $msg . '</div>';
}

/** 회차 만들기 폼 (대시보드·회차 화면 공용) */
function gift_create_form(): void
{
    $y  = (int)date('Y');
    $m  = (int)date('n');
    $se = ($m <= 3) ? '설' : (($m >= 7 && $m <= 10) ? '추석' : '기타');
    $t  = Gift::suggestTitle($y, $se, date('Y-m-d'));

    echo '<div class="gf-card gf-newbatch">';
    echo '<h3>새 회차 만들기</h3>';
    echo '<div class="gf-form">';
    echo '<label>회차명<input type="text" id="nb-title" value="' . gift_h($t) . '"></label>';
    echo '<label>연도<input type="text" id="nb-year" value="' . $y . '" style="width:90px"></label>';
    echo '<label>명절<select id="nb-season">';
    foreach (Gift::SEASONS as $s) {
        echo '<option value="' . gift_h($s) . '"' . ($s === $se ? ' selected' : '') . '>' . gift_h($s) . '</option>';
    }
    echo '</select></label>';
    echo '<label>발송예정일<input type="date" id="nb-date"></label>';
    echo '<button class="gf-btn gf-primary" onclick="gfBatchCreate()">회차 만들기</button>';
    echo '</div>';
    echo '<p class="gf-hint">작업중 회차는 동시에 하나만 둘 수 있습니다. 확정하면 이력으로 넘어가고 새 회차를 만들 수 있습니다.</p>';
    echo '</div>';
}

// ==========================================================
// 대시보드
// ==========================================================
function gift_page_dash(Gift $gift): void
{
    gift_head('대시보드', 'dash');

    $draft = $gift->batchDraft();
    echo '<h2 class="gf-h2">선물 발송 관리</h2>';

    if ($draft) {
        $s  = $gift->summary((int)$draft['id']);
        $sd = $draft['send_date'] ? gift_h($draft['send_date']) : '미정';

        echo '<div class="gf-card">';
        echo '<div class="gf-cardhead"><h3>작업중 회차 — ' . gift_h($draft['title']) . '</h3>';
        echo '<a class="gf-btn gf-primary" href="/gift/index.php?mode=batch">회차 편집</a></div>';
        echo '<div class="gf-stats">';
        echo '<div class="gf-stat"><span>대상</span><b>' . gift_won($s['cnt']) . '명</b></div>';
        echo '<div class="gf-stat"><span>총 수량</span><b>' . gift_won($s['total_qty']) . '개</b></div>';
        echo '<div class="gf-stat"><span>총 금액</span><b>' . gift_won($s['total_amount']) . '원</b></div>';
        echo '<div class="gf-stat' . ($s['incomplete'] ? ' warn' : '') . '"><span>미완성 항목</span><b>' . gift_won($s['incomplete']) . '건</b></div>';
        echo '<div class="gf-stat"><span>발송예정일</span><b>' . $sd . '</b></div>';
        echo '</div>';
        if ($s['incomplete']) {
            gift_note('물품이 없거나 택배인데 주소·우편번호가 빈 행이 <b>' . $s['incomplete'] . '건</b> 있습니다. 확정 전에 채워야 합니다.', 'warn');
        }
        echo '</div>';
    } else {
        gift_create_form();
    }

    // 최근 이력 5건
    $recent = array_slice($gift->batchList(), 0, 5);
    echo '<div class="gf-card"><div class="gf-cardhead"><h3>최근 이력</h3>';
    echo '<a class="gf-btn" href="/gift/index.php?mode=hist">전체 보기</a></div>';
    if (!$recent) {
        echo '<p class="gf-empty">확정된 회차가 아직 없습니다.</p>';
    } else {
        echo '<table class="gf-table"><thead><tr><th>회차</th><th>발송일</th><th class="r">대상</th>'
           . '<th class="r">총 수량</th><th class="r">총 금액</th><th>확정일시</th></tr></thead><tbody>';
        foreach ($recent as $b) {
            $cnt = (int)$gift->summary((int)$b['id'])['cnt'];
            echo '<tr onclick="location.href=\'/gift/index.php?mode=histv&id=' . (int)$b['id'] . '\'" class="clickable">';
            echo '<td><b>' . gift_h($b['title']) . '</b></td>';
            echo '<td>' . gift_h($b['send_date'] ?: '-') . '</td>';
            echo '<td class="r">' . gift_won($cnt) . '명</td>';
            echo '<td class="r">' . gift_won($b['total_qty']) . '개</td>';
            echo '<td class="r">' . gift_won($b['total_amount']) . '원</td>';
            echo '<td>' . gift_h(substr((string)$b['confirmed_at'], 0, 16)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="gf-links">';
    echo '<a class="gf-btn" href="/gift/index.php?mode=cust">고객 명단 관리</a>';
    echo '<a class="gf-btn" href="/gift/index.php?mode=prod">물품 관리</a>';
    echo '<a class="gf-btn" href="/gift/index.php?mode=import">현행 엑셀 가져오기</a>';
    echo '</div>';

    gift_foot();
}

// ==========================================================
// 메인 발송 리스트 (작업중 회차 편집)
// ==========================================================
function gift_prod_select(array $prods, array $it): string
{
    $sel   = (int)$it['product_id'];
    $h     = '<select class="gf-in gf-sel" data-f="product_id">';
    $h    .= '<option value="0"' . ($sel ? '' : ' selected') . '>(미지정)</option>';
    $found = false;
    foreach ($prods as $p) {
        $on = ((int)$p['id'] === $sel);
        if ($on) $found = true;
        $h .= '<option value="' . (int)$p['id'] . '" data-price="' . (int)$p['unit_price'] . '"'
            . ($on ? ' selected' : '') . '>' . gift_h($p['name']) . '</option>';
    }
    // 비활성 물품을 쓰던 행이 조용히 초기화되지 않게 그 행에만 선택지를 남긴다
    if ($sel && !$found) {
        $h .= '<option value="' . $sel . '" data-price="' . (int)$it['unit_price'] . '" selected>'
            . gift_h($it['product_name']) . ' (비활성)</option>';
    }
    return $h . '</select>';
}

function gift_page_batch(Gift $gift): void
{
    gift_head('발송 회차', 'batch');

    $b = $gift->batchDraft();
    if (!$b) {
        gift_note('작업중인 회차가 없습니다. 새 회차를 만들어 발송 대상을 채우세요.');
        gift_create_form();
        gift_foot();
        return;
    }

    $bid   = (int)$b['id'];
    $items = $gift->items($bid);
    $prods = $gift->productList(true);
    $s     = $gift->summary($bid);
    $bad   = $gift->invalidItems($bid);

    echo '<div class="gf-bhead" data-batch="' . $bid . '">';
    echo '<div class="gf-bmeta">';
    echo '<label>회차명<input type="text" id="bm-title" value="' . gift_h($b['title']) . '"></label>';
    echo '<label>연도<input type="text" id="bm-year" value="' . (int)$b['year'] . '" style="width:80px"></label>';
    echo '<label>명절<select id="bm-season">';
    foreach (Gift::SEASONS as $sea) {
        echo '<option value="' . gift_h($sea) . '"' . ($sea === $b['season'] ? ' selected' : '') . '>' . gift_h($sea) . '</option>';
    }
    echo '</select></label>';
    echo '<label>발송예정일<input type="date" id="bm-date" value="' . gift_h($b['send_date']) . '"></label>';
    echo '<span class="gf-badge draft">작업중</span>';
    echo '<button class="gf-btn gf-sm" onclick="gfBatchMeta()">회차정보 저장</button>';
    echo '<button class="gf-btn gf-sm gf-danger" onclick="gfBatchDelete()">회차 버리기</button>';
    echo '</div>';

    echo '<div class="gf-stats" id="gf-sum">';
    gift_sum_html($s);
    echo '</div>';

    echo '<div class="gf-bbtns">';
    echo '<button class="gf-btn gf-primary" onclick="gfAddOpen()">대상 추가</button>';
    echo '<button class="gf-btn" onclick="gfSaveAll()">임시저장</button>';
    echo '<button class="gf-btn gf-ok" onclick="gfConfirm()">회차 확정 저장</button>';
    // 리스트 버튼은 미리보기 창(새 탭)을 연다 — 엑셀 내려받기는 그 안에서 (2026-08-31 사용자 지시 · 곧장 내려받는 버튼은 없다)
    echo '<a class="gf-btn" href="/gift/export.php?id=' . $bid . '&type=vendor&preview=1" target="_blank" title="택배 건만 · 창 안에서 엑셀 내려받기">업체 발송용 리스트</a>';
    echo '<a class="gf-btn" href="/gift/export.php?id=' . $bid . '&type=full&preview=1" target="_blank" title="발송구분별 시트 · 창 안에서 엑셀 내려받기">전체 내역 리스트</a>';
    echo '<a class="gf-btn" href="/gift/export.php?id=' . $bid . '&type=print" target="_blank">큰 글씨 인쇄</a>';
    echo '</div>';
    echo '</div>';

    // ── 필터 / 일괄 작업
    echo '<div class="gf-toolbar">';
    echo '<input type="text" id="f-name" class="gf-in" placeholder="고객명 검색" oninput="gfFilter()">';
    echo '<select id="f-dt" class="gf-in" onchange="gfFilter()"><option value="">발송구분 전체</option>';
    foreach (Gift::DELIVERY as $dt) echo '<option value="' . gift_h($dt) . '">' . gift_h($dt) . '</option>';
    echo '</select>';
    echo '<select id="f-addr" class="gf-in" onchange="gfFilter()"><option value="">주소 전체</option>'
       . '<option value="y">주소 있음</option><option value="n">주소 없음</option></select>';
    echo '<span class="gf-sep"></span>';
    echo '<span class="gf-bulklabel">선택 <b id="gf-nsel">0</b>행 —</span>';
    echo '<select id="bulk-prod" class="gf-in"><option value="0">물품 일괄 지정…</option>';
    foreach ($prods as $p) echo '<option value="' . (int)$p['id'] . '">' . gift_h($p['name']) . '</option>';
    echo '</select>';
    echo '<button class="gf-btn gf-sm" onclick="gfBulk(\'product\')">적용</button>';
    echo '<select id="bulk-dt" class="gf-in"><option value="">발송구분 일괄 변경…</option>';
    foreach (Gift::DELIVERY as $dt) echo '<option value="' . gift_h($dt) . '">' . gift_h($dt) . '</option>';
    echo '</select>';
    echo '<button class="gf-btn gf-sm" onclick="gfBulk(\'delivery\')">적용</button>';
    echo '<button class="gf-btn gf-sm" onclick="gfBulk(\'refresh\')" title="고객 명단의 지금 값으로 이름·연락처·주소를 다시 읽습니다. 오프라인 전달로 바뀐 고객은 택배에서 일괄로 되돌립니다">고객정보 새로고침</button>';
    echo '<button class="gf-btn gf-sm gf-danger" onclick="gfBulk(\'delete\')">선택 삭제</button>';
    echo '</div>';

    // ── 리스트
    echo '<div class="gf-scroll"><table class="gf-table gf-edit" id="gf-list"><thead><tr>';
    echo '<th class="chk"><input type="checkbox" id="gf-all" onclick="gfAllChk(this)"></th>';
    echo '<th>No</th><th>고객명</th><th>추가정보</th><th>연락처</th><th>우편번호</th><th>주소</th>';
    echo '<th>발송구분</th><th>물품</th><th class="r">단가</th><th class="r">추가</th>';
    echo '<th class="r">총지급</th><th class="r">금액</th><th>비고</th><th></th></tr></thead><tbody>';

    if (!$items) {
        echo '<tr class="gf-noitem"><td colspan="15" class="gf-empty">발송 대상이 없습니다. 「대상 추가」로 고객을 넣으세요.</td></tr>';
    }
    $gfNo = 0;   // No 는 보이는 순서(발송구분→이름) — 저장된 seq 는 넣은 순서라 여기선 쓰지 않는다
    foreach ($items as $it) {
        $id  = (int)$it['id'];
        $why = $bad[$id] ?? [];
        echo '<tr data-id="' . $id . '" data-name="' . gift_h($it['customer_name']) . '"'
           . ' data-dt="' . gift_h($it['delivery_type']) . '"'
           . ' data-addr="' . (trim((string)$it['address1']) !== '' ? 'y' : 'n') . '"'
           . ($why ? ' class="bad" title="' . gift_h(implode(' · ', $why)) . '"' : '') . '>';
        echo '<td class="chk"><input type="checkbox" class="gf-chk" onclick="gfCount()"></td>';
        echo '<td class="no">' . (++$gfNo) . '</td>';
        echo '<td><input class="gf-in w-name" data-f="customer_name" value="' . gift_h($it['customer_name']) . '"></td>';
        echo '<td><input class="gf-in w-memo" data-f="customer_memo" value="' . gift_h($it['customer_memo']) . '"></td>';
        echo '<td><input class="gf-in w-phone" data-f="phone" value="' . gift_h($it['phone']) . '"></td>';
        echo '<td class="zipcell"><input class="gf-in w-zip" data-f="zipcode" value="' . gift_h($it['zipcode']) . '">'
           . '<button class="gf-zip" onclick="gfZip(this)" title="우편번호 검색">🔍</button></td>';
        echo '<td><input class="gf-in w-addr" data-f="address1" value="' . gift_h($it['address1']) . '">'
           . '<input class="gf-in w-addr2" data-f="address2" value="' . gift_h($it['address2']) . '" placeholder="상세주소"></td>';
        echo '<td><select class="gf-in gf-sel" data-f="delivery_type">';
        foreach (Gift::DELIVERY as $dt) {
            echo '<option value="' . gift_h($dt) . '"' . ($dt === $it['delivery_type'] ? ' selected' : '') . '>' . gift_h($dt) . '</option>';
        }
        echo '</select></td>';
        echo '<td>' . gift_prod_select($prods, $it) . '</td>';
        echo '<td class="r"><input class="gf-in gf-num w-price" data-f="unit_price" value="' . gift_won($it['unit_price']) . '"></td>';
        echo '<td class="r"><input class="gf-in gf-num w-qty" data-f="extra_qty" value="' . (int)$it['extra_qty'] . '"></td>';
        echo '<td class="r"><span class="v-tq">' . (int)$it['total_qty'] . '</span></td>';
        echo '<td class="r"><span class="v-am">' . gift_won($it['amount']) . '</span></td>';
        echo '<td><input class="gf-in w-note" data-f="note" value="' . gift_h($it['note']) . '"></td>';
        echo '<td><button class="gf-x" onclick="gfDelRow(this)" title="삭제">✕</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // ── 대상 추가 모달
    echo '<div class="gf-modal" id="m-add"><div class="gf-mbox wide">';
    echo '<div class="gf-mhead"><h3>대상 추가</h3><button class="gf-x" onclick="gfClose(\'m-add\')">✕</button></div>';
    echo '<div class="gf-mtools">';
    echo '<input type="text" id="add-q" class="gf-in" placeholder="고객명·연락처·주소 검색" oninput="gfAddSearch()">';
    echo '<select id="add-grp" class="gf-in" onchange="gfAddSearch()"><option value="">그룹 전체</option>';
    echo '<option value="none">미분류</option>';
    foreach ($gift->groupList() as $g) {
        echo '<option value="' . (int)$g['id'] . '">' . gift_h($g['name']) . ' (' . (int)$g['cnt'] . ')</option>';
    }
    echo '</select>';
    echo '<label class="gf-inline"><input type="checkbox" id="add-all" onclick="gfAddAllChk(this)"> 전체 선택</label>';
    echo '<select id="add-prod" class="gf-in"><option value="0">물품 기본값 (미지정)</option>';
    foreach ($prods as $p) echo '<option value="' . (int)$p['id'] . '">' . gift_h($p['name']) . '</option>';
    echo '</select>';
    echo '<button class="gf-btn gf-sm" onclick="gfAddPrev()">이전 회차에서 복사</button>';
    echo '</div>';
    echo '<div class="gf-mlist" id="add-list"></div>';
    echo '<div class="gf-mfoot"><span id="add-cnt" class="gf-hint"></span>'
       . '<button class="gf-btn gf-primary" onclick="gfAddRun()">리스트에 추가</button></div>';
    echo '</div></div>';

    gift_foot();
}

/** 집계 칩 (Ajax 갱신과 서버 렌더가 같은 모양이어야 하므로 한 곳에서 만든다) */
function gift_sum_html(array $s): void
{
    echo '<div class="gf-stat"><span>대상</span><b>' . gift_won($s['cnt']) . '명</b></div>';
    echo '<div class="gf-stat"><span>총 수량</span><b>' . gift_won($s['total_qty']) . '개</b></div>';
    echo '<div class="gf-stat"><span>총 금액</span><b>' . gift_won($s['total_amount']) . '원</b></div>';
    foreach ($s['by_delivery'] as $d) {
        echo '<div class="gf-stat sub"><span>' . gift_h($d['delivery_type']) . '</span><b>'
           . gift_won($d['cnt']) . '명 · ' . gift_won($d['q']) . '개</b></div>';
    }
    echo '<div class="gf-stat' . ($s['incomplete'] ? ' warn' : '') . '"><span>미완성</span><b>'
       . gift_won($s['incomplete']) . '건</b></div>';
}

// ==========================================================
// 고객 명단
// ==========================================================
/** 지금 걸린 검색·필터를 유지한 채 일부만 바꾼 목록 URL (머리글 정렬·페이징 공용) */
function gift_cust_url(array $f, array $over = []): string
{
    $q = array_merge([
        'mode' => 'cust',
        'q'    => $f['q'],
        'grp'  => $f['grp'],
        'sort' => $f['sort'],
        'page' => $f['page'],
    ], $over);
    return '/gift/index.php?' . http_build_query(array_filter($q, fn($v) => (string)$v !== ''));
}

/**
 * 눌러서 정렬하는 표 머리글. 머리글 하나가 두 상태($a ▲ / $b ▼)를 오간다.
 * 지금 그 머리글로 정렬 중이면 반대 상태로, 아니면 $a 로 간다.
 */
function gift_sort_th(string $label, string $a, string $b, array $f): string
{
    $cur  = (string)$f['sort'];
    $next = ($cur === $a) ? $b : $a;
    $mark = ($cur === $a) ? ' <span class="gf-arrow">▲</span>'
          : (($cur === $b) ? ' <span class="gf-arrow">▼</span>' : '');
    return '<a class="gf-th" href="' . gift_h(gift_cust_url($f, ['sort' => $next, 'page' => 1])) . '">'
         . gift_h($label) . $mark . '</a>';
}

function gift_page_cust(Gift $gift): void
{
    gift_head('고객 명단', 'cust');

    $f = [
        'q'    => (string)($_GET['q'] ?? ''),
        'grp'  => (string)($_GET['grp'] ?? ''),
        'sort' => (string)($_GET['sort'] ?? 'ship'),
        'page' => max(1, (int)($_GET['page'] ?? 1)),
        'size' => 50,
    ];
    $r      = $gift->customerList($f);
    $pages  = max(1, (int)ceil($r['total'] / $f['size']));
    $noZip  = $gift->countMissingZip();
    $groups = $gift->groupList();

    echo '<div class="gf-cardhead"><h2 class="gf-h2">고객 명단 <span class="gf-cnt">' . gift_won($r['total']) . '명</span></h2>';
    echo '<div><button class="gf-btn gf-primary" onclick="gfCustOpen(0)">고객 추가</button> ';
    echo '<button class="gf-btn" onclick="gfDirOpen()">인명록에서 가져오기</button> ';
    echo '<button class="gf-btn" id="btn-zip" onclick="gfZipFill()"' . ($noZip ? '' : ' disabled')
       . '>우편번호 일괄 채우기' . ($noZip ? ' (' . $noZip . '명)' : '') . '</button></div></div>';
    echo '<script>const GF_NOZIP=' . $noZip . ';</script>';

    // 검색칸과 그룹 필터만 남긴다 — 나머지 정렬·구분은 표 머리글을 눌러 고른다
    echo '<form class="gf-toolbar" method="get" action="/gift/index.php" id="gc-form">';
    echo '<input type="hidden" name="mode" value="cust">';
    echo '<input type="hidden" name="sort" value="' . gift_h($f['sort']) . '">';
    echo '<input type="text" name="q" class="gf-in" style="min-width:240px" placeholder="고객명·연락처·주소 검색 (Enter)"'
       . ' value="' . gift_h($f['q']) . '" onkeydown="if(event.key===\'Enter\'){event.preventDefault();this.form.submit();}">';
    echo '<select name="grp" class="gf-in" onchange="this.form.submit()"><option value="">그룹 전체</option>';
    echo '<option value="none"' . ($f['grp'] === 'none' ? ' selected' : '') . '>미분류</option>';
    foreach ($groups as $g) {
        echo '<option value="' . (int)$g['id'] . '"' . ($f['grp'] === (string)$g['id'] ? ' selected' : '') . '>'
           . gift_h($g['name']) . ' (' . (int)$g['cnt'] . ')</option>';
    }
    echo '</select>';
    // 그룹을 손볼 일은 드물다 — 그룹 셀렉트 옆의 작은 톱니로만 남긴다 (버튼 한 자리를 안 차지한다)
    echo '<button type="button" class="gf-icon" onclick="gfOpen(\'m-grp\')" title="그룹 관리 (추가·이름·순서·삭제)">⚙</button>';
    echo '<span class="gf-hint">정렬은 표 머리글(고객명·그룹·주소·전달)을 누르세요.</span>';
    echo '</form>';

    // 선택 행 일괄 작업 — 행을 골랐을 때만 나타난다 (평소엔 줄 하나가 통째로 없다)
    echo '<div class="gf-toolbar" id="gc-bulk" style="display:none">';
    echo '<span class="gf-bulklabel">선택 <b id="gc-nsel">0</b>명 —</span>';
    echo '<select id="bulk-grp" class="gf-in"><option value="-1">그룹 일괄 지정…</option>';
    echo '<option value="0">미분류로</option>';
    foreach ($groups as $g) echo '<option value="' . (int)$g['id'] . '">' . gift_h($g['name']) . '</option>';
    echo '</select>';
    echo '<button class="gf-btn gf-sm" onclick="gfGroupAssign()">적용</button>';
    echo '</div>';

    echo '<div class="gf-scroll"><table class="gf-table" id="gc-list"><thead><tr>'
       . '<th class="chk"><input type="checkbox" id="gc-all" onclick="gfCustAllChk(this)"></th>'
       . '<th>' . gift_sort_th('고객명', 'name', 'namedesc', $f) . '</th>'
       . '<th>' . gift_sort_th('그룹', 'group', 'ungroup', $f) . '</th>'
       . '<th>추가정보</th><th class="r" title="회차에 넣을 때 「추가」 칸의 초기값">선물 추가</th><th>연락처</th><th>우편번호</th>'
       . '<th>' . gift_sort_th('주소', 'addr', 'noaddr', $f) . '</th>'
       . '<th>' . gift_sort_th('전달', 'ship', 'offline', $f) . '</th>'
       . '<th class="r">관리</th></tr></thead><tbody>';
    if (!$r['rows']) {
        echo '<tr><td colspan="10" class="gf-empty">해당하는 고객이 없습니다.</td></tr>';
    }
    foreach ($r['rows'] as $c) {
        $addr = trim(trim((string)$c['address1']) . ' ' . trim((string)$c['address2']));
        echo '<tr data-cid="' . (int)$c['id'] . '">';
        echo '<td class="chk"><input type="checkbox" class="gc-chk" onclick="gfCustCount()"></td>';
        echo '<td><b>' . gift_h($c['name']) . '</b></td>';
        echo '<td>' . (($c['group_name'] ?? '') !== ''
              ? '<span class="gf-badge grp">' . gift_h($c['group_name']) . '</span>'
              : '<span class="gf-dim">미분류</span>') . '</td>';
        echo '<td>' . gift_h($c['memo']) . '</td>';
        echo '<td class="r">' . ((int)($c['extra_qty'] ?? 0) > 0
              ? '<span class="gf-badge extra">+' . (int)$c['extra_qty'] . '</span>'
              : '<span class="gf-dim">-</span>') . '</td>';
        echo '<td>' . gift_h($c['phone']) . '</td>';
        // 주소는 있는데 우편번호가 없으면 택배로 못 보낸다 — 눈에 띄게 둔다
        echo '<td>' . ($c['zipcode']
              ? gift_h($c['zipcode'])
              : ($addr !== '' ? '<span class="gf-badge warn">없음</span>' : '<span class="gf-dim">-</span>')) . '</td>';
        echo '<td>' . ($addr !== '' ? gift_h($addr) : '<span class="gf-dim">주소 없음</span>') . '</td>';
        echo '<td>' . (!empty($c['is_offline'])
              ? '<span class="gf-badge off2">오프라인</span>'
              : '<span class="gf-dim">택배</span>') . '</td>';
        echo '<td class="r"><button class="gf-btn gf-sm" onclick="gfCustOpen(' . (int)$c['id'] . ')">수정</button> ';
        echo '<button class="gf-btn gf-sm gf-danger" onclick="gfCustDel(' . (int)$c['id'] . ',\'' . gift_h($c['name']) . '\')">삭제</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // 페이징
    if ($pages > 1) {
        echo '<div class="gf-paging">';
        for ($i = 1; $i <= $pages; $i++) {
            echo '<a href="' . gift_h(gift_cust_url($f, ['page' => $i])) . '"'
               . ($i === $f['page'] ? ' class="on"' : '') . '>' . $i . '</a>';
        }
        echo '</div>';
    }

    // ── 고객 등록/수정 모달
    echo '<div class="gf-modal" id="m-cust"><div class="gf-mbox">';
    echo '<div class="gf-mhead"><h3 id="cust-title">고객 추가</h3><button class="gf-x" onclick="gfClose(\'m-cust\')">✕</button></div>';
    echo '<div class="gf-mbody"><input type="hidden" id="c-id" value="0">';
    echo '<label>고객명 <span class="gf-req">*</span><input type="text" id="c-name"></label>';
    echo '<label>그룹<select id="c-grp" class="gf-fw"><option value="0">미분류</option>';
    foreach ($groups as $g) echo '<option value="' . (int)$g['id'] . '">' . gift_h($g['name']) . '</option>';
    echo '</select></label>';
    echo '<label>추가정보<input type="text" id="c-memo" placeholder="직함·관계 (예: 박회장, 서장님, 지인)"></label>';
    echo '<label>연락처<input type="text" id="c-phone" placeholder="010-0000-0000"></label>';
    echo '<label>선물 추가 수량<span class="gf-row2"><input type="text" id="c-extra" class="gf-num" value="0" style="width:90px;text-align:right">'
       . '<span class="gf-hint" style="margin:0">회차에 대상으로 넣을 때 「추가」 칸의 초기값 — 총지급 = 1 + 추가. 행에서 언제든 고칠 수 있습니다.</span></span></label>';
    echo '<label>주소<span class="gf-row2"><input type="text" id="c-zip" placeholder="우편번호" style="width:110px" readonly>';
    echo '<button class="gf-btn gf-sm" onclick="gfZipModal()">우편번호 검색</button></span></label>';
    echo '<label><input type="text" id="c-ad1" placeholder="기본주소" oninput="gfOffSync()"></label>';
    echo '<label><input type="text" id="c-ad2" placeholder="상세주소" oninput="gfOffSync()"></label>';
    echo '<label class="gf-check"><input type="checkbox" id="c-off" onchange="gfOffSync()"> <b>오프라인 전달</b>'
       . ' <span id="c-off-why">— 주소가 있어도 택배로 보내지 않고 직접 전달합니다</span></label>';
    echo '<p class="gf-hint">회차에 대상으로 넣을 때 발송구분이 자동으로 정해집니다 — '
       . '<b>오프라인이면 「일괄」</b>, 아니면 주소가 있으면 「택배」·없으면 「일괄」. 행에서 언제든 바꿀 수 있습니다.</p>';
    echo '</div>';
    echo '<div class="gf-mfoot"><button class="gf-btn" onclick="gfClose(\'m-cust\')">취소</button>';
    echo '<button class="gf-btn gf-primary" onclick="gfCustSave()">저장</button></div>';
    echo '</div></div>';

    // ── 인명록 모달
    echo '<div class="gf-modal" id="m-dir"><div class="gf-mbox wide">';
    echo '<div class="gf-mhead"><h3>인명록에서 가져오기</h3><button class="gf-x" onclick="gfClose(\'m-dir\')">✕</button></div>';
    echo '<div class="gf-mtools"><input type="text" id="dir-q" class="gf-in" placeholder="이름·연락처·소속·주소 검색" oninput="gfDirSearch()">';
    echo '<label class="gf-inline"><input type="checkbox" id="dir-all" onclick="gfDirAllChk(this)"> 전체 선택</label></div>';
    echo '<div class="gf-mlist" id="dir-list"></div>';
    echo '<div class="gf-mfoot"><span class="gf-hint">인명록에는 우편번호 칸이 없어 주소만 가져옵니다. 택배로 보내려면 우편번호를 채워야 합니다.</span>';
    echo '<button class="gf-btn gf-primary" onclick="gfDirRun()">선택 등록</button></div>';
    echo '</div></div>';

    // 그룹 관리 — 따로 메뉴를 두지 않고 여기서 모달로 연다
    gift_group_modal($groups, (int)$gift->customerList(['grp' => 'none', 'size' => 10])['total']);

    gift_foot();
}

// ==========================================================
// 고객 분류 그룹 — 고객 명단 화면의 모달 (별도 메뉴로 두지 않는다)
// ==========================================================
function gift_group_modal(array $groups, int $unfiled): void
{
    echo '<div class="gf-modal" id="m-grp"><div class="gf-mbox wide">';
    echo '<div class="gf-mhead"><h3>그룹 관리</h3><button class="gf-x" onclick="gfClose(\'m-grp\')">✕</button></div>';

    echo '<div class="gf-mtools">';
    echo '<input type="text" id="g-new-name" class="gf-in" placeholder="새 그룹명 (예: 지인, 경찰서, 구청, 거래처)"'
       . ' onkeydown="if(event.key===\'Enter\')gfGroupAdd()">';
    echo '<button class="gf-btn gf-primary gf-sm" onclick="gfGroupAdd()">추가</button>';
    echo '</div>';

    echo '<div class="gf-mbody nopad"><table class="gf-table" id="g-list"><thead><tr>'
       . '<th class="drag"></th><th class="r">순서</th><th>그룹명</th><th class="r">인원</th><th class="r">관리</th>'
       . '</tr></thead><tbody>';

    if (!$groups) {
        echo '<tr><td colspan="5" class="gf-empty">아직 그룹이 없습니다. 위에 이름을 적고 「추가」를 누르세요.</td></tr>';
    }
    $no = 0;
    foreach ($groups as $g) {
        $id = (int)$g['id'];
        echo '<tr data-gid="' . $id . '">';
        echo '<td class="drag"><span class="gf-drag" title="끌어서 순서를 바꿉니다">⠿</span></td>';
        echo '<td class="r no">' . (++$no) . '</td>';
        echo '<td><input class="gf-in g-name" value="' . gift_h($g['name']) . '" style="min-width:180px"></td>';
        echo '<td class="r"><a href="/gift/index.php?mode=cust&grp=' . $id . '">' . gift_won($g['cnt']) . '명</a></td>';
        echo '<td class="r"><button class="gf-btn gf-sm" onclick="gfGroupSave(this)">이름 저장</button> ';
        echo '<button class="gf-btn gf-sm gf-danger" onclick="gfGroupDel(' . $id . ',\'' . gift_h($g['name']) . '\',' . (int)$g['cnt'] . ')">삭제</button></td>';
        echo '</tr>';
    }
    echo '<tr class="gf-total"><td class="drag"></td><td class="r">-</td><td>미분류</td>';
    echo '<td class="r"><a href="/gift/index.php?mode=cust&grp=none">' . gift_won($unfiled) . '명</a></td><td></td></tr>';
    echo '</tbody></table></div>';

    echo '<div class="gf-mfoot"><span class="gf-hint">'
       . ($groups ? '왼쪽 <b>⠿</b> 을 끌어 순서를 바꿉니다 — 놓는 즉시 저장됩니다. ' : '')
       . '그룹을 지워도 <b>고객은 안 지워집니다</b>(미분류로 내려옵니다). <span id="g-saved" class="gf-savedmsg"></span></span>';
    echo '<button class="gf-btn" onclick="gfClose(\'m-grp\')">닫기</button></div>';
    echo '</div></div>';
}

// ==========================================================
// 물품
// ==========================================================
function gift_page_prod(Gift $gift): void
{
    gift_head('물품', 'prod');

    $rows = $gift->productList(false);

    echo '<div class="gf-cardhead"><h2 class="gf-h2">물품</h2>';
    echo '<button class="gf-btn gf-primary" onclick="gfProdOpen(0)">물품 추가</button></div>';
    gift_note('물품은 삭제하지 않고 <b>비활성</b>으로만 내립니다 — 지난 회차의 이력을 지키기 위해서입니다.');

    echo '<div class="gf-scroll"><table class="gf-table"><thead><tr><th class="r">순서</th><th>물품명</th>'
       . '<th class="r">단가</th><th>설명</th><th>판매자</th><th>세금계산서</th><th>사용여부</th><th class="r">관리</th></tr></thead><tbody>';
    foreach ($rows as $p) {
        echo '<tr' . ((int)$p['is_active'] ? '' : ' class="off"') . '>';
        echo '<td class="r">' . (int)$p['sort_order'] . '</td>';
        echo '<td><b>' . gift_h($p['name']) . '</b></td>';
        echo '<td class="r">' . gift_won($p['unit_price']) . '원'
           . ((int)$p['unit_price'] === 0 ? ' <span class="gf-badge warn">단가0</span>' : '') . '</td>';
        echo '<td>' . gift_h($p['description']) . '</td>';
        // 판매자 — 이름만 보이고, 누르면 상세 모달(연락처·주소·메모·세금계산서·이 판매자의 물품)
        $vid = (int)($p['vendor_id'] ?? 0);
        echo '<td>' . ($vid > 0
              ? '<a href="#" class="gf-vlink" onclick="gfVendView(' . $vid . ');return false">' . gift_h($p['vendor_name']) . '</a>'
              : '<span class="gf-dim">-</span>') . '</td>';
        echo '<td>' . (!empty($p['tax_invoice'])
              ? '<span class="gf-badge on">발행</span>'
              : '<span class="gf-dim">-</span>') . '</td>';
        echo '<td>' . ((int)$p['is_active']
              ? '<span class="gf-badge on">사용</span>'
              : '<span class="gf-badge off">미사용</span>') . '</td>';
        echo '<td class="r"><button class="gf-btn gf-sm" onclick="gfProdOpen(' . (int)$p['id'] . ')">수정</button> ';
        echo '<button class="gf-btn gf-sm" onclick="gfProdToggle(' . (int)$p['id'] . ',' . ((int)$p['is_active'] ? 0 : 1) . ')">'
           . ((int)$p['is_active'] ? '비활성' : '활성') . '</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    $vendors = $gift->vendorList();
    echo '<script>const GF_PRODS=' . json_encode($rows, JSON_UNESCAPED_UNICODE) . ';'
       . 'const GF_VENDORS=' . json_encode($vendors, JSON_UNESCAPED_UNICODE) . ';</script>';

    echo '<div class="gf-modal" id="m-prod"><div class="gf-mbox">';
    echo '<div class="gf-mhead"><h3 id="prod-title">물품 추가</h3><button class="gf-x" onclick="gfClose(\'m-prod\')">✕</button></div>';
    echo '<div class="gf-mbody"><input type="hidden" id="p-id" value="0">';
    echo '<label>물품명 <span class="gf-req">*</span><input type="text" id="p-name"></label>';
    echo '<label>단가(원)<input type="text" id="p-price" class="gf-num" value="0"></label>';
    echo '<label>설명<input type="text" id="p-desc"></label>';
    echo '<label>노출순서<input type="text" id="p-sort" value="0"></label>';
    echo '<div class="gf-msep">판매자 <span class="gf-dim">— 주문할 때 보는 것. 발송 명단엔 안 들어갑니다</span></div>';
    echo '<label>판매자<select id="p-vid" class="gf-fw" onchange="gfVendPick()"><option value="0">없음</option>';
    foreach ($vendors as $v) {
        echo '<option value="' . (int)$v['id'] . '">' . gift_h($v['name'])
           . ($v['phone'] !== '' && $v['phone'] !== null ? ' · ' . gift_h($v['phone']) : '') . '</option>';
    }
    echo '<option value="-1">＋ 새 판매자 입력…</option></select></label>';
    echo '<div id="p-vbox" style="display:none">';
    echo '<label>판매자(업체)명 <span class="gf-req">*</span><input type="text" id="p-vname" placeholder="예: ○○농원, ○○식품"></label>';
    echo '<label>연락처<input type="text" id="p-vphone" placeholder="010-0000-0000"></label>';
    echo '<label>주소<input type="text" id="p-vaddr"></label>';
    echo '<label>메모<input type="text" id="p-vmemo" placeholder="담당자·계좌·주문 방법·납기 등"></label>';
    echo '<label class="gf-check"><input type="checkbox" id="p-tax"> <b>세금계산서 발행</b> <span class="gf-dim">— 이 판매자가 세금계산서를 끊어 줍니다</span></label>';
    echo '<p class="gf-hint" id="p-vhint"></p>';
    echo '</div>';
    echo '<p class="gf-hint">단가를 바꿔도 <b>확정된 회차는 변하지 않습니다.</b> 작업중 회차가 이 물품을 쓰고 있으면 저장할 때 반영 여부를 묻습니다.</p>';
    echo '</div>';
    echo '<div class="gf-mfoot"><button class="gf-btn" onclick="gfClose(\'m-prod\')">취소</button>';
    echo '<button class="gf-btn gf-primary" onclick="gfProdSave()">저장</button></div>';
    echo '</div></div>';

    // ── 판매자 상세 모달 (보기 전용 — 고치는 곳은 물품 모달의 판매자 칸)
    echo '<div class="gf-modal" id="m-vend"><div class="gf-mbox">';
    echo '<div class="gf-mhead"><h3 id="vend-title">판매자</h3><button class="gf-x" onclick="gfClose(\'m-vend\')">✕</button></div>';
    echo '<div class="gf-mbody"><dl class="gf-kv" id="vend-body"></dl>';
    echo '<p class="gf-hint">고치려면 이 판매자를 쓰는 물품의 「수정」에서 판매자 칸을 고치세요 — 같은 판매자를 쓰는 모든 물품에 적용됩니다.</p>';
    echo '</div>';
    echo '<div class="gf-mfoot"><button class="gf-btn" onclick="gfClose(\'m-vend\')">닫기</button></div>';
    echo '</div></div>';

    gift_foot();
}

// ==========================================================
// 이력
// ==========================================================
function gift_page_hist(Gift $gift): void
{
    gift_head('이력', 'hist');

    $year = (int)($_GET['year'] ?? 0);
    $rows = $gift->batchList($year);

    echo '<div class="gf-cardhead"><h2 class="gf-h2">발송 이력</h2>';
    echo '<form method="get" action="/gift/index.php"><input type="hidden" name="mode" value="hist">';
    echo '<select name="year" class="gf-in" onchange="this.form.submit()"><option value="0">전체 연도</option>';
    foreach ($gift->batchYears() as $y) {
        echo '<option value="' . $y . '"' . ($y === $year ? ' selected' : '') . '>' . $y . '년</option>';
    }
    echo '</select></form></div>';

    if (!$rows) {
        echo '<p class="gf-empty">확정된 회차가 없습니다.</p>';
    } else {
        echo '<div class="gf-scroll"><table class="gf-table"><thead><tr><th>회차</th><th>명절</th><th>발송일</th>'
           . '<th class="r">대상</th><th class="r">총 수량</th><th class="r">총 금액</th><th>확정일시</th></tr></thead><tbody>';
        foreach ($rows as $b) {
            $cnt = (int)$gift->summary((int)$b['id'])['cnt'];
            echo '<tr class="clickable" onclick="location.href=\'/gift/index.php?mode=histv&id=' . (int)$b['id'] . '\'">';
            echo '<td><b>' . gift_h($b['title']) . '</b></td>';
            echo '<td>' . gift_h($b['season']) . '</td>';
            echo '<td>' . gift_h($b['send_date'] ?: '-') . '</td>';
            echo '<td class="r">' . gift_won($cnt) . '명</td>';
            echo '<td class="r">' . gift_won($b['total_qty']) . '개</td>';
            echo '<td class="r">' . gift_won($b['total_amount']) . '원</td>';
            echo '<td>' . gift_h(substr((string)$b['confirmed_at'], 0, 16)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    gift_foot();
}

function gift_page_histv(Gift $gift): void
{
    $id = (int)($_GET['id'] ?? 0);
    $b  = $gift->batchGet($id);

    gift_head($b ? (string)$b['title'] : '이력', 'histv');

    if (!$b) {
        gift_note('회차를 찾을 수 없습니다.', 'warn');
        gift_foot();
        return;
    }

    $items = $gift->items($id);
    $s     = $gift->summary($id);

    echo '<div class="gf-cardhead"><h2 class="gf-h2">' . gift_h($b['title']);
    echo ($b['status'] === 'confirmed')
        ? ' <span class="gf-badge ok">확정</span>'
        : ' <span class="gf-badge draft">작업중</span>';
    echo '</h2>';
    echo '<div class="gf-noprint">';
    echo '<a class="gf-btn gf-primary" href="/gift/export.php?id=' . $id . '&type=vendor&preview=1" target="_blank" title="택배 건만 · 창 안에서 엑셀 내려받기">업체 발송용 리스트</a> ';
    echo '<a class="gf-btn" href="/gift/export.php?id=' . $id . '&type=full&preview=1" target="_blank" title="발송구분별 시트 · 창 안에서 엑셀 내려받기">전체 내역 리스트</a> ';
    echo '<a class="gf-btn" href="/gift/export.php?id=' . $id . '&type=print" target="_blank">큰 글씨 인쇄</a> ';
    echo '<button class="gf-btn" onclick="window.print()">인쇄</button> ';
    echo '<a class="gf-btn" href="/gift/index.php?mode=hist">목록</a>';
    echo '</div></div>';

    echo '<div class="gf-metaline">';
    echo '<span>발송예정일 <b>' . gift_h($b['send_date'] ?: '-') . '</b></span>';
    echo '<span>확정일시 <b>' . gift_h(substr((string)$b['confirmed_at'], 0, 16) ?: '-') . '</b></span>';
    if (trim((string)$b['memo']) !== '') echo '<span>메모 <b>' . gift_h($b['memo']) . '</b></span>';
    echo '</div>';

    echo '<div class="gf-stats">';
    echo '<div class="gf-stat"><span>대상</span><b>' . gift_won($s['cnt']) . '명</b></div>';
    echo '<div class="gf-stat"><span>총 수량</span><b>' . gift_won($s['total_qty']) . '개</b></div>';
    echo '<div class="gf-stat"><span>총 금액</span><b>' . gift_won($s['total_amount']) . '원</b></div>';
    echo '</div>';

    echo '<div class="gf-two">';
    echo '<div class="gf-card"><h3>발송구분별</h3><table class="gf-table sm"><thead><tr><th>구분</th>'
       . '<th class="r">건수</th><th class="r">수량</th><th class="r">금액</th></tr></thead><tbody>';
    foreach ($s['by_delivery'] as $d) {
        echo '<tr><td>' . gift_h($d['delivery_type']) . '</td><td class="r">' . gift_won($d['cnt']) . '</td>'
           . '<td class="r">' . gift_won($d['q']) . '</td><td class="r">' . gift_won($d['a']) . '원</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="gf-card"><h3>물품별</h3><table class="gf-table sm"><thead><tr><th>물품</th>'
       . '<th class="r">건수</th><th class="r">수량</th><th class="r">금액</th></tr></thead><tbody>';
    foreach ($s['by_product'] as $p) {
        echo '<tr><td>' . gift_h($p['product_name'] ?: '(미지정)') . '</td><td class="r">' . gift_won($p['cnt']) . '</td>'
           . '<td class="r">' . gift_won($p['q']) . '</td><td class="r">' . gift_won($p['a']) . '원</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="gf-scroll"><table class="gf-table"><thead><tr><th>No</th><th>고객명</th><th>추가정보</th>'
       . '<th>연락처</th><th>우편번호</th><th>주소</th><th>발송구분</th><th>물품</th>'
       . '<th class="r">단가</th><th class="r">추가</th><th class="r">총지급</th><th class="r">금액</th><th>비고</th>'
       . '</tr></thead><tbody>';
    $gfNo = 0;
    foreach ($items as $it) {
        $addr = trim(trim((string)$it['address1']) . ' ' . trim((string)$it['address2']));
        echo '<tr>';
        echo '<td>' . (++$gfNo) . '</td>';
        echo '<td><b>' . gift_h($it['customer_name']) . '</b></td>';
        echo '<td>' . gift_h($it['customer_memo']) . '</td>';
        echo '<td>' . gift_h($it['phone']) . '</td>';
        echo '<td>' . gift_h($it['zipcode']) . '</td>';
        echo '<td>' . gift_h($addr) . '</td>';
        echo '<td>' . gift_h($it['delivery_type']) . '</td>';
        echo '<td>' . gift_h($it['product_name']) . '</td>';
        echo '<td class="r">' . gift_won($it['unit_price']) . '</td>';
        echo '<td class="r">' . (int)$it['extra_qty'] . '</td>';
        echo '<td class="r">' . (int)$it['total_qty'] . '</td>';
        echo '<td class="r">' . gift_won($it['amount']) . '</td>';
        echo '<td>' . gift_h($it['note']) . '</td>';
        echo '</tr>';
    }
    echo '<tr class="gf-total"><td colspan="10" class="r">합계</td><td class="r">' . gift_won($s['total_qty']) . '</td>';
    echo '<td class="r">' . gift_won($s['total_amount']) . '</td><td></td></tr>';
    echo '</tbody></table></div>';

    gift_foot();
}

// ==========================================================
// 현행 엑셀 가져오기
// ==========================================================
/** 머리글 이름 정규화 — 엑셀 셀에는 줄바꿈이 섞여 있다 ("총\n지급") */
function gift_norm_head(string $s): string
{
    return preg_replace('/\s+/u', '', $s);
}

/**
 * .xlsx 를 [머리글 => 값] 행 배열로. (ZipArchive + SimpleXML — composer 없이)
 * @return array{rows:array, heads:array, err:string}
 */
function gift_parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return ['rows' => [], 'heads' => [], 'err' => '이 서버에는 ZipArchive 가 없습니다. 아래 「붙여넣기」를 쓰세요.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['rows' => [], 'heads' => [], 'err' => '엑셀 파일을 열 수 없습니다.'];
    }

    // 공유 문자열
    $shared = [];
    $ssXml  = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $x = simplexml_load_string($ssXml);
        foreach ($x->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            } else {
                foreach ($si->r as $r) $t .= (string)$r->t;
            }
            $shared[] = $t;
        }
    }

    // 첫 시트
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        return ['rows' => [], 'heads' => [], 'err' => '시트를 찾을 수 없습니다.'];
    }

    $sheet = simplexml_load_string($sheetXml);
    $grid  = [];
    foreach ($sheet->sheetData->row as $row) {
        $rn = (int)$row['r'];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = $m[1] ?? '';
            $t   = (string)$c['t'];
            if ($t === 'inlineStr') {
                $v = (string)$c->is->t;
            } else {
                $v = isset($c->v) ? (string)$c->v : '';
                if ($t === 's') $v = $shared[(int)$v] ?? '';
            }
            if ($v !== '') $grid[$rn][$col] = $v;
        }
    }

    // 머리글 행 = '고객명' 이 있는 첫 행
    $headRow = 0;
    $map     = [];
    foreach ($grid as $rn => $cells) {
        foreach ($cells as $col => $v) {
            if (gift_norm_head($v) === '고객명') { $headRow = $rn; break 2; }
        }
    }
    if (!$headRow) {
        return ['rows' => [], 'heads' => [], 'err' => '「고객명」 머리글을 찾지 못했습니다. 아래 「붙여넣기」를 쓰세요.'];
    }
    foreach ($grid[$headRow] as $col => $v) $map[$col] = gift_norm_head($v);

    $rows = [];
    foreach ($grid as $rn => $cells) {
        if ($rn <= $headRow) continue;
        $r = [];
        foreach ($cells as $col => $v) {
            if (isset($map[$col])) $r[$map[$col]] = $v;
        }
        $name = trim((string)($r['고객명'] ?? ''));
        if ($name === '' || $name === '고객명') continue;   // 빈 행·머리글 반복 행
        $rows[] = $r;
    }
    return ['rows' => $rows, 'heads' => array_values($map), 'err' => ''];
}

/** 붙여넣은 TSV/CSV 를 [머리글 => 값] 행 배열로 (첫 줄이 머리글) */
function gift_parse_paste(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    if (count($lines) < 2) return ['rows' => [], 'heads' => [], 'err' => '머리글 줄과 자료 줄이 모두 필요합니다.'];

    $split = function (string $l): array {
        return (strpos($l, "\t") !== false) ? explode("\t", $l) : str_getcsv($l);
    };
    $heads = array_map(fn($h) => gift_norm_head($h), $split(array_shift($lines)));
    if (!in_array('고객명', $heads, true)) {
        return ['rows' => [], 'heads' => $heads, 'err' => '머리글 줄에 「고객명」이 있어야 합니다.'];
    }

    $rows = [];
    foreach ($lines as $l) {
        if (trim($l) === '') continue;
        $cells = $split($l);
        $r     = [];
        foreach ($heads as $i => $h) {
            if ($h !== '') $r[$h] = trim((string)($cells[$i] ?? ''));
        }
        if (trim((string)($r['고객명'] ?? '')) === '') continue;
        $rows[] = $r;
    }
    return ['rows' => $rows, 'heads' => $heads, 'err' => ''];
}

function gift_page_import(Gift $gift): void
{
    $step   = (string)($_POST['step'] ?? '');
    $parsed = ['rows' => [], 'heads' => [], 'err' => ''];
    $done   = null;
    $err    = '';

    if ($step === 'preview') {
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $parsed = gift_parse_xlsx($_FILES['file']['tmp_name']);
        } elseif (trim((string)($_POST['paste'] ?? '')) !== '') {
            $parsed = gift_parse_paste((string)$_POST['paste']);
        } else {
            $parsed['err'] = '엑셀 파일을 고르거나 표를 붙여넣으세요.';
        }
    } elseif ($step === 'run') {
        if (!hash_equals((string)($_SESSION['gift_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            $err = '요청이 만료되었습니다. 처음부터 다시 시도하세요.';
        } else {
            $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
            if (!is_array($rows) || !$rows) {
                $err = '가져올 자료가 없습니다.';
            } else {
                try {
                    $batchId = 0;
                    if (!empty($_POST['make_batch'])) {
                        $batchId = $gift->batchCreate([
                            'title'     => $_POST['b_title']  ?? '',
                            'year'      => Gift::num($_POST['b_year'] ?? date('Y')),
                            'season'    => $_POST['b_season'] ?? '기타',
                            'send_date' => $_POST['b_date']   ?? '',
                        ]);
                    }
                    $done = $gift->importRows($rows, [
                        'customers' => !empty($_POST['make_cust']),
                        'batch_id'  => $batchId,
                    ]);
                    $done['batch_id'] = $batchId;
                } catch (Throwable $e) {
                    $err = $e->getMessage();
                }
            }
        }
    }

    gift_head('가져오기', 'import');
    echo '<h2 class="gf-h2">현행 엑셀 가져오기</h2>';
    gift_note('현행 <b>선물.xlsx</b> 의 「단가」 칸에는 실제로 <b>합계 금액</b>이 들어 있습니다'
        . '(50,000 × 2 = 100,000). 가져오면서 <b>단가 = 합계 ÷ 총지급</b> 으로 되돌려 넣습니다.');
    gift_note('엑셀에는 오프라인 칸이 없어, <b>발송구분이 「택배」가 아닌 행</b>은 고객 명단에 '
        . '<b>「오프라인 전달」로 표시</b>합니다 — 주소가 있어도 그렇습니다(예: 주소가 있는 일괄 건). 나중에 고객 화면에서 고칠 수 있습니다.');

    if ($err !== '')          gift_note(gift_h($err), 'warn');
    if ($parsed['err'] !== '') gift_note(gift_h($parsed['err']), 'warn');

    // ── 결과
    if ($done) {
        echo '<div class="gf-card"><h3>가져오기 완료</h3><ul class="gf-ul">';
        echo '<li>고객 명단 신규 등록 <b>' . gift_won($done['customers']) . '명</b>'
           . ($done['skipped'] ? ' (이미 있어 건너뜀 ' . gift_won($done['skipped']) . '명)' : '') . '</li>';
        if ($done['items']) echo '<li>회차 발송 라인 <b>' . gift_won($done['items']) . '건</b></li>';
        echo '</ul>';
        if (!empty($done['batch_id'])) {
            echo '<a class="gf-btn gf-primary" href="/gift/index.php?mode=batch">회차 편집으로</a> ';
        }
        echo '<a class="gf-btn" href="/gift/index.php?mode=cust">고객 명단 보기</a>';
        echo '</div>';
        gift_foot();
        return;
    }

    // ── 미리보기 + 실행
    if ($parsed['rows']) {
        $rows  = $parsed['rows'];
        $noZip = 0;
        foreach ($rows as $r) if (trim((string)($r['우편번호'] ?? '')) === '') $noZip++;

        echo '<div class="gf-card"><h3>미리보기 <span class="gf-cnt">' . count($rows) . '행</span></h3>';
        if ($noZip) {
            gift_note('우편번호가 빈 행이 <b>' . $noZip . '건</b> 입니다. 택배 건은 우편번호가 있어야 회차를 확정할 수 있으니 가져온 뒤 채워 주세요.', 'warn');
        }

        $heads = ['고객명', '추가정보', '연락처', '우편번호', '주소', '발송구분', '상품명', '단가', '추가', '총지급'];
        echo '<div class="gf-scroll"><table class="gf-table sm"><thead><tr>';
        foreach ($heads as $h) echo '<th>' . gift_h($h) . '</th>';
        echo '<th class="r">→ 단가</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 15) as $r) {
            $tq = max(1, Gift::num($r['총지급'] ?? 0) ?: (1 + Gift::num($r['추가'] ?? 0)));
            $up = (int)round(Gift::num($r['단가'] ?? 0) / $tq);
            echo '<tr>';
            foreach ($heads as $h) echo '<td>' . gift_h($r[$h] ?? '') . '</td>';
            echo '<td class="r"><b>' . gift_won($up) . '</b></td></tr>';
        }
        echo '</tbody></table></div>';
        if (count($rows) > 15) echo '<p class="gf-hint">…앞 15행만 보여줍니다. 전체 ' . count($rows) . '행을 가져옵니다.</p>';

        $y  = (int)date('Y');
        $m  = (int)date('n');
        $se = ($m <= 3) ? '설' : (($m >= 7 && $m <= 10) ? '추석' : '기타');

        echo '<form method="post" action="/gift/index.php?mode=import" class="gf-form col">';
        echo '<input type="hidden" name="step" value="run">';
        echo '<input type="hidden" name="csrf" value="' . gift_h($_SESSION['gift_csrf']) . '">';
        echo '<input type="hidden" name="rows" value="' . gift_h(json_encode($rows, JSON_UNESCAPED_UNICODE)) . '">';
        echo '<label class="gf-inline"><input type="checkbox" name="make_cust" value="1" checked> 고객 명단에 등록 (이름+연락처가 같으면 건너뜀)</label>';
        echo '<label class="gf-inline"><input type="checkbox" name="make_batch" value="1" id="mk-batch" onchange="gfImpBatch()"> 이 내용으로 새 회차 만들기</label>';
        echo '<div id="imp-batch" style="display:none" class="gf-form">';
        echo '<label>회차명<input type="text" name="b_title" value="' . gift_h(Gift::suggestTitle($y, $se, date('Y-m-d'))) . '"></label>';
        echo '<label>연도<input type="text" name="b_year" value="' . $y . '" style="width:90px"></label>';
        echo '<label>명절<select name="b_season">';
        foreach (Gift::SEASONS as $s) echo '<option value="' . gift_h($s) . '"' . ($s === $se ? ' selected' : '') . '>' . gift_h($s) . '</option>';
        echo '</select></label>';
        echo '<label>발송예정일<input type="date" name="b_date"></label>';
        echo '</div>';
        echo '<div><button class="gf-btn gf-primary">가져오기 실행</button> '
           . '<a class="gf-btn" href="/gift/index.php?mode=import">다시 고르기</a></div>';
        echo '</form></div>';
        gift_foot();
        return;
    }

    // ── 입력 폼
    echo '<div class="gf-card"><h3>① 엑셀 파일 올리기</h3>';
    echo '<form method="post" action="/gift/index.php?mode=import" enctype="multipart/form-data" class="gf-form">';
    echo '<input type="hidden" name="step" value="preview">';
    echo '<input type="file" name="file" accept=".xlsx">';
    echo '<button class="gf-btn gf-primary">미리보기</button>';
    echo '</form>';
    echo '<p class="gf-hint">머리글에 <b>고객명</b> 이 있는 줄을 자동으로 찾습니다. 인식하는 칸: '
       . '고객명 · 추가정보 · 연락처 · 우편번호 · 주소 · 발송구분 · 상품명 · 단가 · 추가 · 총지급</p></div>';

    echo '<div class="gf-card"><h3>② 또는 표를 붙여넣기</h3>';
    echo '<form method="post" action="/gift/index.php?mode=import" class="gf-form col">';
    echo '<input type="hidden" name="step" value="preview">';
    echo '<textarea name="paste" rows="8" placeholder="엑셀에서 머리글 줄을 포함해 복사한 뒤 붙여넣으세요 (탭 구분)"></textarea>';
    echo '<div><button class="gf-btn gf-primary">미리보기</button></div>';
    echo '</form>';
    echo '<p class="gf-hint">구버전 .xls 파일은 엑셀에서 열어 복사·붙여넣기 하면 됩니다.</p></div>';

    gift_foot();
}

// ==========================================================
// CSS
// ==========================================================
function gift_css(): void
{
    echo <<<'CSS'
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font-family:'Pretendard','Malgun Gothic',sans-serif;background:#f0f2f5;color:#2c3e50;font-size:14px}
.gf-body{max-width:1680px;margin:0 auto;padding:18px 16px 70px}
.gf-h2{font-size:21px;margin:0 0 14px}
.gf-cnt{font-size:14px;color:#7f8c8d;font-weight:500}
h3{font-size:16px;margin:0 0 10px}

/* 하위 탭 */
.gf-tabs{background:#34495e;display:flex;gap:2px;padding:0 16px;overflow-x:auto}
.gf-tabs a{color:#cfd8e3;text-decoration:none;padding:11px 18px;font-weight:600;white-space:nowrap;border-bottom:3px solid transparent}
.gf-tabs a:hover{color:#fff;background:rgba(255,255,255,.07)}
.gf-tabs a.on{color:#f1c40f;border-bottom-color:#f1c40f}

.gf-card{background:#fff;border-radius:10px;padding:16px 18px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.gf-cardhead{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.gf-cardhead h2,.gf-cardhead h3{margin:0}
.gf-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:900px){.gf-two{grid-template-columns:1fr}}
.gf-links{display:flex;gap:8px;flex-wrap:wrap}
.gf-empty{color:#95a5a6;text-align:center;padding:26px 0}
.gf-dim{color:#b2bec3}
.gf-hint{color:#7f8c8d;font-size:12.5px;margin:8px 0 0}
.gf-req{color:#e74c3c}
.gf-ul{margin:0 0 12px;padding-left:20px;line-height:1.8}

.gf-note{background:#eaf4fd;border-left:4px solid #3498db;padding:10px 14px;border-radius:0 6px 6px 0;margin-bottom:14px;line-height:1.6}
.gf-note.warn{background:#fdf3e3;border-left-color:#e67e22}

/* 집계 */
.gf-stats{display:flex;gap:10px;flex-wrap:wrap}
.gf-stat{background:#f8f9fb;border:1px solid #e6e9ef;border-radius:8px;padding:9px 14px;min-width:104px}
.gf-stat span{display:block;color:#7f8c8d;font-size:12px;margin-bottom:3px}
.gf-stat b{font-size:17px}
.gf-stat.sub{background:#fff}
.gf-stat.sub b{font-size:14px}
.gf-stat.warn{background:#fdecea;border-color:#f5b7b1}
.gf-stat.warn b{color:#c0392b}

/* 버튼 */
.gf-btn{display:inline-block;border:1px solid #bdc3c7;background:#fff;color:#2c3e50;border-radius:6px;
        padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:.15s}
.gf-btn:hover{background:#ecf0f1}
.gf-btn.gf-primary{background:#3498db;border-color:#3498db;color:#fff}
.gf-btn.gf-primary:hover{background:#2980b9}
.gf-btn.gf-ok{background:#27ae60;border-color:#27ae60;color:#fff}
.gf-btn.gf-ok:hover{background:#1e8449}
.gf-btn.gf-danger{background:#fdecea;border-color:#f5b7b1;color:#c0392b}
.gf-btn.gf-danger:hover{background:#fadbd8}
.gf-btn.gf-sm{padding:5px 10px;font-size:12px}
.gf-x{border:none;background:none;color:#95a5a6;font-size:15px;cursor:pointer;padding:2px 6px}
.gf-x:hover{color:#e74c3c}
/* 자주 안 쓰는 부수 동작 — 아이콘 한 칸만 차지한다 */
.gf-icon{border:1px solid #dfe4ea;background:#fff;color:#7f8c8d;border-radius:6px;cursor:pointer;
         width:32px;height:32px;font-size:15px;line-height:1;padding:0}
.gf-icon:hover{background:#ecf0f1;color:#2c3e50;border-color:#bdc3c7}

/* 배지 */
.gf-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11.5px;font-weight:700}
.gf-badge.draft{background:#fef5e7;color:#b9770e}
.gf-badge.ok{background:#eafaf1;color:#1e8449}
.gf-badge.on{background:#eafaf1;color:#1e8449}
.gf-badge.off{background:#f4f6f7;color:#7f8c8d}
.gf-badge.extra{background:#fdf2e9;color:#ca6f1e}
.gf-badge.dir{background:#eaf2fb;color:#2471a3}
.gf-badge.man{background:#f4f6f7;color:#616a6b}
.gf-badge.warn{background:#fdecea;color:#c0392b}
.gf-badge.off2{background:#f0eafb;color:#6c3fa8}
.gf-badge.grp{background:#e8f6ef;color:#17795e}

/* 표 */
.gf-scroll{overflow-x:auto;background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.gf-table{width:100%;border-collapse:collapse;background:#fff;font-size:13.5px}
.gf-table th{background:#f4f6f8;color:#5d6d7e;font-weight:700;font-size:12.5px;padding:10px 8px;
             border-bottom:2px solid #e6e9ef;white-space:nowrap;text-align:left}
.gf-table td{padding:8px;border-bottom:1px solid #eef1f5;vertical-align:middle}
.gf-table .r{text-align:right}
.gf-table tr.clickable{cursor:pointer}
.gf-table tr.clickable:hover{background:#f8fbff}
.gf-table tr.off td{color:#aab4bd;background:#fbfbfc}
.gf-table tr.gf-total td{background:#f4f6f8;font-weight:700}
.gf-table.sm{font-size:12.8px}
.gf-table.sm td,.gf-table.sm th{padding:6px 8px}
.gf-paging{display:flex;gap:5px;justify-content:center;margin:16px 0}
.gf-paging a{padding:6px 11px;border:1px solid #dfe4ea;border-radius:5px;text-decoration:none;color:#2c3e50;background:#fff}
.gf-paging a.on{background:#3498db;border-color:#3498db;color:#fff;font-weight:700}

/* 회차 편집 */
.gf-bhead{background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.gf-bmeta{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px}
.gf-bmeta label{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#7f8c8d;font-weight:600}
.gf-bmeta input,.gf-bmeta select{border:1px solid #dfe4ea;border-radius:5px;padding:6px 8px;font-size:13.5px;color:#2c3e50}
.gf-bbtns{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.gf-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#fff;border-radius:10px;
            padding:10px 14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.gf-sep{width:1px;height:22px;background:#e6e9ef}
.gf-bulklabel{font-size:12.5px;color:#7f8c8d}
.gf-in{border:1px solid #dfe4ea;border-radius:5px;padding:6px 8px;font-size:13px;color:#2c3e50;background:#fff}
.gf-in:focus{outline:none;border-color:#3498db;box-shadow:0 0 0 2px rgba(52,152,219,.15)}
.gf-inline{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#2c3e50}

.gf-edit td{padding:4px 5px}
.gf-edit .chk{width:34px;text-align:center}
.gf-edit .no{width:40px;color:#7f8c8d;text-align:right}
.gf-edit input.gf-in{width:100%}
.gf-edit .w-name{min-width:96px}
.gf-edit .w-memo{min-width:110px}
.gf-edit .w-phone{min-width:118px}
.gf-edit .w-zip{width:74px}
.gf-edit .w-addr{min-width:280px}
.gf-edit .w-addr2{min-width:280px;margin-top:3px}
.gf-edit .w-price,.gf-edit .w-qty{text-align:right}
.gf-edit .w-price{width:82px}
.gf-edit .w-qty{width:52px}
.gf-edit .w-note{min-width:120px}
.gf-edit .gf-sel{min-width:86px}
.gf-edit .zipcell{white-space:nowrap}
.gf-zip{border:1px solid #dfe4ea;background:#fff;border-radius:5px;cursor:pointer;padding:5px 6px;margin-left:2px}
.gf-zip:hover{background:#ecf0f1}
.gf-edit tr.bad{background:#fdf3f2}
.gf-edit tr.bad td:first-child{box-shadow:inset 3px 0 0 #e74c3c}
.gf-edit tr.saving{background:#fffbea}
.gf-edit tr.saved{transition:background .8s;background:#eafaf1}

/* 모달 */
.gf-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;
          align-items:center;justify-content:center;padding:20px}
.gf-modal.on{display:flex}
.gf-mbox{background:#fff;border-radius:12px;width:100%;max-width:480px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden}
.gf-mbox.wide{max-width:820px}
.gf-mhead{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #eef1f5}
.gf-mhead h3{margin:0}
.gf-mbody{padding:16px 18px;overflow-y:auto}
.gf-mbody.nopad{padding:0}
.gf-mbody.nopad .gf-table{box-shadow:none}
.gf-mbody.nopad .gf-table th{position:sticky;top:0;z-index:1}
.gf-mbody label{display:block;margin-bottom:11px;font-size:12.5px;color:#7f8c8d;font-weight:600}
.gf-mbody input[type=text]{width:100%;border:1px solid #dfe4ea;border-radius:5px;padding:8px 10px;
                           font-size:14px;color:#2c3e50;margin-top:4px}
.gf-mbody select.gf-fw{width:100%;border:1px solid #dfe4ea;border-radius:5px;padding:8px 10px;
                       font-size:14px;color:#2c3e50;margin-top:4px;background:#fff}
.gf-table .chk{width:34px;text-align:center}

/* 끌어서 순서 바꾸기 */
.gf-table .drag{width:30px;text-align:center;padding-left:4px;padding-right:0}
.gf-table .no{width:46px;color:#7f8c8d}
.gf-drag{display:inline-block;cursor:grab;color:#b2bec3;font-size:16px;line-height:1;
         padding:4px 3px;border-radius:4px;touch-action:none;user-select:none}
.gf-drag:hover{color:#5d6d7e;background:#eef2f6}
.gf-drag:active{cursor:grabbing}
.gf-table tr.dragging{background:#eaf4fd;box-shadow:0 2px 8px rgba(0,0,0,.12);opacity:.95;user-select:none}
.gf-table tr.dragging .gf-drag{color:#3498db}
.gf-savedmsg{color:#1e8449;font-weight:700;opacity:0;transition:opacity .2s}
.gf-savedmsg.on{opacity:1}

/* 눌러서 정렬하는 머리글 */
.gf-th{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:3px;
       padding:2px 6px;margin:-2px -6px;border-radius:4px;white-space:nowrap}
.gf-th:hover{background:#e6ebf0;color:#2c3e50}
.gf-th .gf-arrow{color:#3498db;font-size:10px}
.gf-msep{margin:14px 0 8px;padding-top:10px;border-top:1px dashed #d5d8dc;font-weight:700;font-size:13px;color:#2c3e50}
.gf-msep .gf-dim{font-weight:400;font-size:12px}
.gf-vlink{color:#2c3e50;font-weight:700;text-decoration:none;border-bottom:1px dashed #95a5a6}
.gf-vlink:hover{color:#2980b9;border-bottom-color:#2980b9}
.gf-kv{display:grid;grid-template-columns:90px 1fr;gap:8px 12px;margin:0 0 6px;font-size:13.5px}
.gf-kv dt{color:#7f8c8d;font-weight:600;font-size:12.5px;padding-top:1px}
.gf-kv dd{margin:0;color:#2c3e50;word-break:break-all;line-height:1.5}
.gf-row2{display:flex;gap:6px;align-items:center;margin-top:4px}
.gf-row2 input{margin-top:0!important}
.gf-mbody label.gf-check{display:flex;align-items:center;gap:7px;background:#f8f9fb;border:1px solid #e6e9ef;
                         border-radius:7px;padding:10px 12px;color:#2c3e50;font-weight:500;cursor:pointer}
.gf-mbody label.gf-check b{color:#6c3fa8}
.gf-mbody label.gf-check span{color:#7f8c8d;font-size:12px}
.gf-mbody label.gf-check input{margin:0}
.gf-mtools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid #eef1f5}
.gf-mtools .gf-in{flex:1;min-width:170px}
.gf-mlist{overflow-y:auto;padding:6px 10px;flex:1;min-height:180px}
.gf-mlist label{display:flex;gap:9px;align-items:center;padding:7px 9px;border-radius:6px;cursor:pointer}
.gf-mlist label:hover{background:#f4f8fc}
.gf-mlist label.dis{opacity:.5;cursor:not-allowed}
.gf-mlist .nm{font-weight:700;min-width:96px}
.gf-mlist .sub{color:#7f8c8d;font-size:12.5px}
.gf-mfoot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 18px;border-top:1px solid #eef1f5}

.gf-form{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.gf-form.col{flex-direction:column;align-items:stretch}
.gf-form label{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#7f8c8d;font-weight:600}
.gf-form input[type=text],.gf-form input[type=date],.gf-form select,.gf-form textarea{
    border:1px solid #dfe4ea;border-radius:5px;padding:7px 9px;font-size:13.5px;color:#2c3e50;font-family:inherit}
.gf-newbatch{max-width:920px}
.gf-metaline{display:flex;gap:18px;flex-wrap:wrap;color:#7f8c8d;font-size:13px;margin-bottom:12px}

@media print{
  .top-nav-bar,.gf-tabs,.gf-noprint{display:none!important}
  body{background:#fff}
  .gf-body{max-width:none;padding:0}
  .gf-scroll{overflow:visible;box-shadow:none}
  .gf-table{font-size:11px}
}
</style>
CSS;
}

// ==========================================================
// JS
// ==========================================================
function gift_js(): void
{
    echo <<<'JS'
<script>
// ── 공통 ────────────────────────────────────────────────
const gfWon = n => (Number(n) || 0).toLocaleString('ko-KR');
const gfInt = v => parseInt(String(v ?? '').replace(/[^0-9-]/g, ''), 10) || 0;
const gfEsc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function gfApi(module, action, data) {
    const url = '/gift/api.php?module=' + module + '&action=' + action;
    let res;
    if (data === undefined) {
        res = await fetch(url, { credentials: 'same-origin' });
    } else {
        const fd = new FormData();
        fd.append('csrf', GF_CSRF);
        for (const k in data) {
            if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k + '[]', v));
            else fd.append(k, data[k] ?? '');
        }
        res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
    }
    let j;
    try { j = await res.json(); } catch (e) { j = { ok: false, msg: '서버 응답을 읽을 수 없습니다.' }; }
    if (!j.ok && j.msg) alert(j.msg);
    return j;
}
function gfQs(module, action, params) {
    const q = new URLSearchParams(params || {}).toString();
    return fetch('/gift/api.php?module=' + module + '&action=' + action + (q ? '&' + q : ''),
                 { credentials: 'same-origin' }).then(r => r.json());
}

function gfOpen(id)  { document.getElementById(id).classList.add('on'); }
function gfClose(id) { document.getElementById(id).classList.remove('on'); }

// 금액·수량 칸은 천단위 콤마 (편집 중에는 숫자만, 벗어나면 콤마)
document.addEventListener('focusin', e => {
    if (e.target.classList?.contains('gf-num')) e.target.value = String(gfInt(e.target.value) || '');
});
document.addEventListener('focusout', e => {
    if (e.target.classList?.contains('gf-num')) e.target.value = gfWon(gfInt(e.target.value));
});
document.querySelectorAll('.gf-num').forEach(el => { el.value = gfWon(gfInt(el.value)); });

// ── Daum 우편번호 (schedule.php 와 같은 패턴) ─────────────
function gfPostcode(cb) {
    const load = done => {
        if (window.daum?.Postcode) { done(); return; }
        const s = document.createElement('script');
        s.src = 'https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
        s.onload = done;
        document.head.appendChild(s);
    };
    load(() => new daum.Postcode({
        oncomplete(d) { cb(d.zonecode, d.roadAddress || d.jibunAddress); }
    }).open());
}

// ==========================================================
// 회차 편집
// ==========================================================
const gfBatchId = () => Number(document.querySelector('.gf-bhead')?.dataset.batch || 0);

function gfRowData(tr) {
    const d = { id: tr.dataset.id };
    tr.querySelectorAll('[data-f]').forEach(el => { d[el.dataset.f] = el.value; });
    d.unit_price = gfInt(d.unit_price);
    d.extra_qty  = gfInt(d.extra_qty);
    return d;
}
function gfRowPaint(tr, row) {
    tr.querySelector('.v-tq').textContent = row.total_qty;
    tr.querySelector('.v-am').textContent = gfWon(row.amount);
    const up = tr.querySelector('[data-f=unit_price]');
    if (document.activeElement !== up) up.value = gfWon(row.unit_price);
    tr.dataset.dt   = row.delivery_type;
    tr.dataset.name = row.customer_name;
    tr.dataset.addr = String(row.address1 || '').trim() ? 'y' : 'n';
}
function gfPaintSummary(sum, bad) {
    if (sum) {
        let h = '<div class="gf-stat"><span>대상</span><b>' + gfWon(sum.cnt) + '명</b></div>'
              + '<div class="gf-stat"><span>총 수량</span><b>' + gfWon(sum.total_qty) + '개</b></div>'
              + '<div class="gf-stat"><span>총 금액</span><b>' + gfWon(sum.total_amount) + '원</b></div>';
        (sum.by_delivery || []).forEach(d => {
            h += '<div class="gf-stat sub"><span>' + gfEsc(d.delivery_type) + '</span><b>'
               + gfWon(d.cnt) + '명 · ' + gfWon(d.q) + '개</b></div>';
        });
        h += '<div class="gf-stat' + (sum.incomplete ? ' warn' : '') + '"><span>미완성</span><b>'
           + gfWon(sum.incomplete) + '건</b></div>';
        document.getElementById('gf-sum').innerHTML = h;
    }
    if (bad) {
        document.querySelectorAll('#gf-list tbody tr[data-id]').forEach(tr => {
            const why = bad[tr.dataset.id];
            tr.classList.toggle('bad', !!why);
            if (why) tr.title = why.join(' · '); else tr.removeAttribute('title');
        });
    }
}

const gfTimers = {};
function gfRowChanged(tr) {
    const id = tr.dataset.id;
    clearTimeout(gfTimers[id]);
    gfTimers[id] = setTimeout(() => gfRowSave(tr), 500);
}
async function gfRowSave(tr) {
    tr.classList.add('saving');
    const j = await gfApi('item', 'save', gfRowData(tr));
    tr.classList.remove('saving');
    if (!j.ok) return;
    gfRowPaint(tr, j.row);
    gfPaintSummary(j.summary, j.bad);
    tr.classList.add('saved');
    setTimeout(() => tr.classList.remove('saved'), 900);
}

if (document.body.dataset.page === 'batch') {
    const list = document.getElementById('gf-list');
    if (list) {
        // 물품을 고르면 단가를 그 물품 값으로 미리 채운다 (확정은 서버 계산이 한다)
        list.addEventListener('change', e => {
            const tr = e.target.closest('tr[data-id]');
            if (!tr || !e.target.dataset.f) return;
            if (e.target.dataset.f === 'product_id') {
                const op = e.target.selectedOptions[0];
                const pr = op ? Number(op.dataset.price || 0) : 0;
                if (op && op.value !== '0') tr.querySelector('[data-f=unit_price]').value = gfWon(pr);
            }
            gfRowChanged(tr);
        });
        list.addEventListener('input', e => {
            const tr = e.target.closest('tr[data-id]');
            if (!tr || !e.target.dataset.f) return;
            if (['unit_price', 'extra_qty'].includes(e.target.dataset.f)) {
                const up = gfInt(tr.querySelector('[data-f=unit_price]').value);
                const tq = 1 + Math.max(0, gfInt(tr.querySelector('[data-f=extra_qty]').value));
                tr.querySelector('.v-tq').textContent = tq;
                tr.querySelector('.v-am').textContent = gfWon(up * tq);
            }
            gfRowChanged(tr);
        });
    }
}

function gfZip(btn) {
    const tr = btn.closest('tr[data-id]');
    gfPostcode((zip, addr) => {
        tr.querySelector('[data-f=zipcode]').value  = zip;
        tr.querySelector('[data-f=address1]').value = addr;
        gfRowSave(tr);
    });
}

function gfAllChk(el) {
    document.querySelectorAll('#gf-list tbody tr[data-id]').forEach(tr => {
        if (tr.style.display !== 'none') tr.querySelector('.gf-chk').checked = el.checked;
    });
    gfCount();
}
function gfSelIds() {
    return [...document.querySelectorAll('#gf-list tbody tr[data-id]')]
        .filter(tr => tr.querySelector('.gf-chk').checked)
        .map(tr => tr.dataset.id);
}
function gfCount() { document.getElementById('gf-nsel').textContent = gfSelIds().length; }

function gfFilter() {
    const q  = document.getElementById('f-name').value.trim();
    const dt = document.getElementById('f-dt').value;
    const ad = document.getElementById('f-addr').value;
    document.querySelectorAll('#gf-list tbody tr[data-id]').forEach(tr => {
        const ok = (!q  || tr.dataset.name.includes(q))
                && (!dt || tr.dataset.dt === dt)
                && (!ad || tr.dataset.addr === ad);
        tr.style.display = ok ? '' : 'none';
    });
    gfCount();
}

async function gfBulk(op) {
    const ids = gfSelIds();
    if (!ids.length) { alert('먼저 행을 선택하세요.'); return; }

    const d = { batch_id: gfBatchId(), ids, op };
    if (op === 'product') {
        d.product_id = document.getElementById('bulk-prod').value;
        if (d.product_id === '0') { alert('지정할 물품을 고르세요.'); return; }
    }
    if (op === 'delivery') {
        d.delivery_type = document.getElementById('bulk-dt').value;
        if (!d.delivery_type) { alert('바꿀 발송구분을 고르세요.'); return; }
    }
    if (op === 'delete' && !confirm(ids.length + '개 행을 삭제할까요?')) return;

    const j = await gfApi('item', 'bulk', d);
    if (j.ok) location.reload();
}

async function gfDelRow(btn) {
    const tr = btn.closest('tr[data-id]');
    if (!confirm(tr.dataset.name + ' 행을 삭제할까요?')) return;
    const j = await gfApi('item', 'bulk', { batch_id: gfBatchId(), ids: [tr.dataset.id], op: 'delete' });
    if (j.ok) { tr.remove(); gfPaintSummary(j.summary, j.bad); gfCount(); }
}

async function gfSaveAll() {
    const rows = [...document.querySelectorAll('#gf-list tbody tr[data-id]')].map(gfRowData);
    if (!rows.length) { alert('저장할 행이 없습니다.'); return; }
    const j = await gfApi('item', 'saveAll', { batch_id: gfBatchId(), rows: JSON.stringify(rows) });
    if (j.ok) { gfPaintSummary(j.summary, j.bad); location.reload(); }
}

async function gfBatchMeta() {
    const j = await gfApi('batch', 'saveMeta', {
        id:        gfBatchId(),
        title:     document.getElementById('bm-title').value,
        year:      gfInt(document.getElementById('bm-year').value),
        season:    document.getElementById('bm-season').value,
        send_date: document.getElementById('bm-date').value
    });
    if (j.ok) location.reload();
}

async function gfBatchCreate() {
    const j = await gfApi('batch', 'create', {
        title:     document.getElementById('nb-title').value,
        year:      gfInt(document.getElementById('nb-year').value),
        season:    document.getElementById('nb-season').value,
        send_date: document.getElementById('nb-date').value
    });
    if (j.ok) location.href = '/gift/index.php?mode=batch';
}

async function gfBatchDelete() {
    if (!confirm('작업중 회차를 버릴까요? 넣어 둔 발송 대상도 함께 사라집니다.')) return;
    const j = await gfApi('batch', 'delete', { id: gfBatchId() });
    if (j.ok) location.href = '/gift/index.php?mode=batch';
}

async function gfConfirm() {
    if (!confirm('회차를 확정합니다.\n확정 후에는 수정할 수 없습니다. 계속할까요?')) return;
    const id = gfBatchId();
    const j  = await gfApi('batch', 'confirm', { id });
    if (j.ok) { location.href = '/gift/index.php?mode=histv&id=' + id; return; }
    if (j.bad) {
        gfPaintSummary(null, j.bad);
        const first = document.querySelector('#gf-list tr.bad');
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// ── 대상 추가 모달 ───────────────────────────────────────
let gfAddRows = [];
function gfAddOpen() { gfOpen('m-add'); gfAddSearch(); }

async function gfAddSearch() {
    const q   = document.getElementById('add-q').value.trim();
    const grp = document.getElementById('add-grp')?.value || '';
    const j   = await gfQs('customer', 'search', { q, grp });
    gfAddRows = j.rows || [];

    const have = new Set([...document.querySelectorAll('#gf-list tbody tr[data-id]')].map(tr => tr.dataset.name));
    document.getElementById('add-list').innerHTML = gfAddRows.map(c => {
        const addr = [c.address1, c.address2].filter(Boolean).join(' ');
        const dup  = have.has(c.name);
        return '<label class="' + (dup ? 'dis' : '') + '">'
             + '<input type="checkbox" value="' + c.id + '"' + (dup ? ' disabled' : '') + ' onclick="gfAddCount()">'
             + '<span class="nm">' + gfEsc(c.name) + '</span>'
             + '<span class="sub">' + gfEsc(c.memo || '') + (c.phone ? ' · ' + gfEsc(c.phone) : '')
             + (addr ? ' · ' + gfEsc(addr) : ' · <i>주소 없음</i>') + '</span>'
             + (c.group_name ? '<span class="gf-badge grp">' + gfEsc(c.group_name) + '</span>' : '')
             + (Number(c.extra_qty) > 0 ? '<span class="gf-badge extra" title="기본 추가 수량">+' + Number(c.extra_qty) + '</span>' : '')
             + (Number(c.is_offline) === 1 ? '<span class="gf-badge off2">오프라인</span>' : '')
             + (dup ? '<span class="gf-badge off">이미 있음</span>' : '') + '</label>';
    }).join('') || '<p class="gf-empty">해당하는 고객이 없습니다.</p>';
    gfAddCount();
}
function gfAddAllChk(el) {
    document.querySelectorAll('#add-list input[type=checkbox]:not(:disabled)').forEach(c => c.checked = el.checked);
    gfAddCount();
}
function gfAddCount() {
    const n = document.querySelectorAll('#add-list input[type=checkbox]:checked').length;
    document.getElementById('add-cnt').textContent = n ? n + '명 선택됨' : '';
}
async function gfAddRun() {
    const ids = [...document.querySelectorAll('#add-list input[type=checkbox]:checked')].map(c => c.value);
    if (!ids.length) { alert('추가할 고객을 고르세요.'); return; }
    const j = await gfApi('item', 'add', {
        batch_id:   gfBatchId(),
        ids,
        product_id: document.getElementById('add-prod').value
    });
    if (j.ok) location.reload();
}
async function gfAddPrev() {
    if (!confirm('가장 최근에 확정한 회차의 대상을 그대로 불러옵니다.\n주소·연락처는 고객 명단의 지금 값으로 채웁니다.')) return;
    const j = await gfApi('item', 'addPrev', { batch_id: gfBatchId() });
    if (j.ok) { alert(j.msg); if (j.n) location.reload(); }
}

// ==========================================================
// 고객 명단
// ==========================================================
function gfCustOpen(id) {
    document.getElementById('cust-title').textContent = id ? '고객 수정' : '고객 추가';
    ['c-name', 'c-memo', 'c-phone', 'c-zip', 'c-ad1', 'c-ad2'].forEach(k => document.getElementById(k).value = '');
    document.getElementById('c-extra').value = '0';
    document.getElementById('c-off').checked = false;
    document.getElementById('c-grp').value   = '0';
    document.getElementById('c-id').value = id || 0;
    gfOffSync();
    gfOpen('m-cust');
    if (!id) { document.getElementById('c-name').focus(); return; }

    gfQs('customer', 'get', { id }).then(j => {
        if (!j.ok) return;
        const c = j.row;
        document.getElementById('c-name').value  = c.name || '';
        document.getElementById('c-memo').value  = c.memo || '';
        document.getElementById('c-phone').value = c.phone || '';
        document.getElementById('c-extra').value = String(Number(c.extra_qty) || 0);
        document.getElementById('c-zip').value   = c.zipcode || '';
        document.getElementById('c-ad1').value   = c.address1 || '';
        document.getElementById('c-ad2').value   = c.address2 || '';
        document.getElementById('c-off').checked = Number(c.is_offline) === 1;
        document.getElementById('c-grp').value   = String(c.group_id || 0);
        gfOffSync();
    });
}
/**
 * 주소가 없으면 오프라인 전달로 못박는다 (서버가 저장할 때 같은 판정을 한다 — 여기는 눈에 보이게 하는 것뿐).
 * 주소를 채우면 잠금을 풀고 사람이 정하게 둔다.
 */
function gfOffSync() {
    const noAddr = document.getElementById('c-ad1').value.trim() === '';
    const box    = document.getElementById('c-off');
    const why    = document.getElementById('c-off-why');
    if (noAddr) {
        box.checked  = true;
        box.disabled = true;
        why.textContent = '— 주소가 없어 택배로 보낼 수 없습니다 (자동 지정)';
    } else {
        box.disabled = false;
        why.textContent = '— 주소가 있어도 택배로 보내지 않고 직접 전달합니다';
    }
}

function gfZipModal() {
    gfPostcode((zip, addr) => {
        document.getElementById('c-zip').value = zip;
        document.getElementById('c-ad1').value = addr;
        gfOffSync();
        document.getElementById('c-ad2').focus();
    });
}
async function gfCustSave() {
    const name = document.getElementById('c-name').value.trim();
    if (!name) { alert('고객명을 입력하세요.'); return; }
    const j = await gfApi('customer', 'save', {
        id:       document.getElementById('c-id').value,
        name,
        memo:     document.getElementById('c-memo').value,
        phone:    document.getElementById('c-phone').value,
        extra_qty: gfInt(document.getElementById('c-extra').value),
        zipcode:  document.getElementById('c-zip').value,
        address1: document.getElementById('c-ad1').value,
        address2: document.getElementById('c-ad2').value,
        is_offline: document.getElementById('c-off').checked ? 1 : 0,
        group_id: document.getElementById('c-grp').value
    });
    if (j.ok) location.reload();
}
// 우편번호 일괄 채우기 — 외부 REST 라 느려서 몇 명씩 나눠 돈다 (응답 30초 제한 회피)
async function gfZipFill() {
    if (!confirm('주소는 있는데 우편번호가 빈 고객 ' + GF_NOZIP + '명의 우편번호를 찾아 채웁니다.\n'
               + '카카오·네이버 주소 API 를 씁니다. 계속할까요?')) return;

    const btn = document.getElementById('btn-zip');
    btn.disabled = true;
    let filled = 0, fails = [], guessed = [];

    for (let guard = 0; guard < 60; guard++) {
        // 못 찾은 사람은 다음 배치에서 빼야 같은 조회를 되풀이하지 않는다
        const j = await gfApi('customer', 'zipFill', { limit: 8, skip: fails.map(f => f.id) });
        if (!j.ok) break;
        filled  += j.filled;
        fails    = fails.concat(j.fail || []);
        guessed  = guessed.concat(j.guessed || []);
        btn.textContent = '채우는 중… ' + filled + '명 (남은 ' + Math.max(0, j.remain - fails.length) + ')';
        if (j.done === 0 || j.remain <= fails.length) break;
    }

    let msg = '우편번호 ' + filled + '명을 채웠습니다.';
    if (guessed.length) {
        msg += '\n\n[확인 필요] 지번이 없어 건물명으로 찾은 ' + guessed.length + '명 — 단지·동이 갈릴 수 있습니다:\n'
             + guessed.map(g => '· ' + g.name + ' → ' + g.zip + ' ' + g.road).join('\n');
    }
    if (fails.length) {
        msg += '\n\n[못 찾음] ' + fails.length + '명 — 직접 입력해 주세요:\n'
             + fails.map(f => '· ' + f.name + ' — ' + f.address).join('\n');
    }
    alert(msg);
    location.reload();
}

async function gfCustDel(id, name) {
    if (!confirm(name + ' 을(를) 명단에서 지울까요?\n지난 이력은 스냅샷이라 그대로 남습니다.')) return;
    const j = await gfApi('customer', 'delete', { id });
    if (j.ok) location.reload();
}

// ── 인명록 모달 ─────────────────────────────────────────
function gfDirOpen() { gfOpen('m-dir'); gfDirSearch(); }
async function gfDirSearch() {
    const q = document.getElementById('dir-q').value.trim();
    const j = await gfQs('customer', 'dirSearch', { q });
    document.getElementById('dir-list').innerHTML = (j.rows || []).map(c => {
        const dup = Number(c.already) > 0;
        const sub = [c.organization, c.position, c.phone || c.tel || c.corp_phone, c.address]
                    .filter(Boolean).join(' · ');
        return '<label class="' + (dup ? 'dis' : '') + '">'
             + '<input type="checkbox" value="' + c.id + '"' + (dup ? ' disabled' : '') + '>'
             + '<span class="nm">' + gfEsc(c.name) + '</span>'
             + '<span class="sub">' + gfEsc(sub) + '</span>'
             + (dup ? '<span class="gf-badge off">등록됨</span>' : '') + '</label>';
    }).join('') || '<p class="gf-empty">인명록에서 찾지 못했습니다.</p>';
}
function gfDirAllChk(el) {
    document.querySelectorAll('#dir-list input[type=checkbox]:not(:disabled)').forEach(c => c.checked = el.checked);
}
async function gfDirRun() {
    const ids = [...document.querySelectorAll('#dir-list input[type=checkbox]:checked')].map(c => c.value);
    if (!ids.length) { alert('가져올 사람을 고르세요.'); return; }
    const j = await gfApi('customer', 'dirImport', { ids });
    if (j.ok) { alert(j.msg); location.reload(); }
}

// ==========================================================
// 고객 분류 그룹
// ==========================================================
/**
 * 표의 행을 끌어서 순서를 바꾼다 (마우스·터치 공용 — pointer 이벤트).
 * key 를 가진 행끼리만 자리를 바꾸므로 합계 행 같은 건 끼어들지 않는다.
 * onOrder(ids) 는 놓는 순간 한 번 불린다.
 */
function gfSortable(tbody, key, onOrder) {
    if (!tbody) return;
    let row = null;

    tbody.addEventListener('pointerdown', e => {
        const h = e.target.closest('.gf-drag');
        if (!h) return;
        row = h.closest('tr');
        if (!row || row.dataset[key] === undefined) { row = null; return; }
        e.preventDefault();
        // ★캡처는 손잡이가 아니라 tbody 에 건다 — 끄는 동안 행이 DOM 에서 잠깐 떨어지는데,
        //   캡처 대상이 그 행 안에 있으면 브라우저가 캡처를 놓아 버려 드래그가 끊긴다.
        try { tbody.setPointerCapture(e.pointerId); } catch (err) { /* 이미 끝난 포인터 */ }
        row.classList.add('dragging');
    });

    tbody.addEventListener('pointermove', e => {
        if (!row) return;
        const el   = document.elementFromPoint(e.clientX, e.clientY);
        const over = el && el.closest ? el.closest('tr') : null;
        if (!over || over === row || over.parentNode !== tbody) return;
        if (over.dataset[key] === undefined) return;   // 합계·미분류 행 위로는 못 간다
        const r = over.getBoundingClientRect();
        tbody.insertBefore(row, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
    });

    const drop = e => {
        if (!row) return;
        try {
            if (e && e.pointerId !== undefined && tbody.hasPointerCapture(e.pointerId)) {
                tbody.releasePointerCapture(e.pointerId);
            }
        } catch (err) { /* 이미 놓였으면 그만 */ }
        row.classList.remove('dragging');
        row = null;
        const ids = [...tbody.querySelectorAll('tr')]
            .filter(tr => tr.dataset[key] !== undefined)
            .map(tr => tr.dataset[key]);
        // 「순서」 칸을 바로 다시 매겨 준다 (새로고침 없이 눈에 보이게)
        let n = 0;
        tbody.querySelectorAll('tr').forEach(tr => {
            if (tr.dataset[key] === undefined) return;
            const cell = tr.querySelector('.no');
            if (cell) cell.textContent = ++n;
        });
        onOrder(ids);
    };
    tbody.addEventListener('pointerup', drop);
    tbody.addEventListener('pointercancel', drop);
}

async function gfGroupAdd() {
    const name = document.getElementById('g-new-name').value.trim();
    if (!name) { alert('그룹명을 입력하세요.'); return; }
    const j = await gfApi('group', 'save', { id: 0, name });
    if (j.ok) location.reload();
}
async function gfGroupSave(btn) {
    const tr = btn.closest('tr[data-gid]');
    const j  = await gfApi('group', 'save', { id: tr.dataset.gid, name: tr.querySelector('.g-name').value.trim() });
    if (j.ok) location.reload();
}

if (document.body.dataset.page === 'cust') {
    // 모달 안의 표라 화면에 보이기 전부터 걸어 둬도 된다 (끌 때만 동작한다)
    gfSortable(document.querySelector('#g-list tbody'), 'gid', async ids => {
        const el = document.getElementById('g-saved');
        const j  = await gfApi('group', 'reorder', { ids });
        if (j.ok && el) {
            el.textContent = '순서 저장됨';
            el.classList.add('on');
            setTimeout(() => el.classList.remove('on'), 1200);
        }
    });
}
async function gfGroupDel(id, name, cnt) {
    const extra = cnt > 0 ? '\n소속된 ' + cnt + '명은 지워지지 않고 「미분류」로 내려옵니다.' : '';
    if (!confirm('그룹 「' + name + '」을(를) 지울까요?' + extra)) return;
    const j = await gfApi('group', 'delete', { id });
    if (j.ok) location.reload();
}

// 고객 명단 — 선택 행 그룹 일괄 지정
function gfCustAllChk(el) {
    document.querySelectorAll('#gc-list tbody tr[data-cid] .gc-chk').forEach(c => c.checked = el.checked);
    gfCustCount();
}
function gfCustSelIds() {
    return [...document.querySelectorAll('#gc-list tbody tr[data-cid]')]
        .filter(tr => tr.querySelector('.gc-chk').checked)
        .map(tr => tr.dataset.cid);
}
function gfCustCount() {
    const n  = gfCustSelIds().length;
    const el = document.getElementById('gc-nsel');
    if (el) el.textContent = n;
    // 일괄 작업 줄은 고른 행이 있을 때만 보인다
    const bar = document.getElementById('gc-bulk');
    if (bar) bar.style.display = n ? 'flex' : 'none';
}
async function gfGroupAssign() {
    const ids = gfCustSelIds();
    if (!ids.length) { alert('먼저 고객을 선택하세요.'); return; }
    const gid = document.getElementById('bulk-grp').value;
    if (gid === '-1') { alert('지정할 그룹을 고르세요.'); return; }
    const j = await gfApi('group', 'assign', { ids, group_id: gid });
    if (j.ok) location.reload();
}

// ==========================================================
// 물품
// ==========================================================
function gfProdOpen(id) {
    document.getElementById('prod-title').textContent = id ? '물품 수정' : '물품 추가';
    document.getElementById('p-id').value = id || 0;
    const p = (typeof GF_PRODS !== 'undefined' ? GF_PRODS : []).find(x => Number(x.id) === Number(id));
    document.getElementById('p-name').value  = p ? p.name : '';
    document.getElementById('p-price').value = gfWon(p ? p.unit_price : 0);
    document.getElementById('p-desc').value  = p ? (p.description || '') : '';
    document.getElementById('p-sort').value  = p ? p.sort_order : 0;
    document.getElementById('p-vid').value = String(p && p.vendor_id ? p.vendor_id : 0);
    gfVendPick();
    gfOpen('m-prod');
}
/** 판매자 상세 보기 — GF_VENDORS 에서 찾고, 이 판매자를 쓰는 물품은 GF_PRODS 에서 센다 */
function gfVendView(id) {
    const v = (typeof GF_VENDORS !== 'undefined' ? GF_VENDORS : []).find(x => Number(x.id) === Number(id));
    if (!v) return;
    const prods = (typeof GF_PRODS !== 'undefined' ? GF_PRODS : []).filter(p => Number(p.vendor_id) === Number(id));
    const row = (k, val, raw) => '<dt>' + k + '</dt><dd>' + (val ? (raw ? val : gfEsc(val)) : '<span class="gf-dim">-</span>') + '</dd>';
    document.getElementById('vend-title').textContent = v.name || '판매자';
    document.getElementById('vend-body').innerHTML =
          row('연락처', v.phone ? '<a href="tel:' + gfEsc(v.phone) + '">' + gfEsc(v.phone) + '</a>' : '', true)
        + row('주소', v.addr)
        + row('메모', v.memo)
        + row('세금계산서', Number(v.tax_invoice) === 1 ? '<span class="gf-badge on">발행</span>' : '<span class="gf-dim">안 함</span>', true)
        + row('물품', prods.length
              ? prods.map(p => gfEsc(p.name) + (Number(p.is_active) ? '' : ' <span class="gf-dim">(미사용)</span>')).join('<br>')
              : '', true);
    gfOpen('m-vend');
}
/** 판매자 셀렉트 → 칸 채우기. 기존 판매자면 그 값으로, 새 판매자면 빈 칸, 없음이면 접는다 */
function gfVendPick() {
    const vid  = Number(document.getElementById('p-vid').value);
    const box  = document.getElementById('p-vbox');
    const v    = vid > 0 ? (typeof GF_VENDORS !== 'undefined' ? GF_VENDORS : []).find(x => Number(x.id) === vid) : null;
    box.style.display = vid === 0 ? 'none' : 'block';
    document.getElementById('p-vname').value  = v ? (v.name  || '') : '';
    document.getElementById('p-vphone').value = v ? (v.phone || '') : '';
    document.getElementById('p-vaddr').value  = v ? (v.addr  || '') : '';
    document.getElementById('p-vmemo').value  = v ? (v.memo  || '') : '';
    document.getElementById('p-tax').checked  = v ? Number(v.tax_invoice) === 1 : false;
    document.getElementById('p-vhint').innerHTML = v
        ? '여기서 고치면 <b>「' + gfEsc(v.name) + '」을 쓰는 모든 물품</b>에 함께 적용됩니다.'
        : '저장하면 판매자 목록에 들어가 다른 물품에서도 고를 수 있습니다.';
    if (vid === -1) document.getElementById('p-vname').focus();
}
async function gfProdSave() {
    const id    = Number(document.getElementById('p-id').value);
    const name  = document.getElementById('p-name').value.trim();
    const price = gfInt(document.getElementById('p-price').value);
    if (!name) { alert('물품명을 입력하세요.'); return; }
    const vid = Number(document.getElementById('p-vid').value);
    if (vid !== 0 && !document.getElementById('p-vname').value.trim()) {
        alert('판매자명을 입력하세요. (판매자를 안 두려면 「없음」을 고르세요)'); return;
    }

    // 작업중 회차가 이 물품을 쓰고 있으면 단가 반영 여부를 묻는다 (확정 회차는 불변)
    let apply = 0;
    if (id) {
        const u = await gfQs('product', 'draftUse', { id });
        if (u.ok && u.n > 0) {
            apply = confirm('작업중 회차「' + u.batch + '」에서 이 물품을 쓰는 행이 ' + u.n + '건 있습니다.\n'
                          + '그 행들의 단가도 ' + gfWon(price) + '원으로 갱신할까요?') ? 1 : 0;
        }
    }
    const j = await gfApi('product', 'save', {
        id, name, unit_price: price,
        description: document.getElementById('p-desc').value,
        sort_order:  gfInt(document.getElementById('p-sort').value),
        vendor_id:    vid,
        vendor_name:  document.getElementById('p-vname').value,
        vendor_phone: document.getElementById('p-vphone').value,
        vendor_addr:  document.getElementById('p-vaddr').value,
        vendor_memo:  document.getElementById('p-vmemo').value,
        tax_invoice:  document.getElementById('p-tax').checked ? 1 : 0,
        apply_draft: apply
    });
    if (j.ok) location.reload();
}
async function gfProdToggle(id, active) {
    const j = await gfApi('product', 'toggle', { id, active });
    if (j.ok) location.reload();
}

// ==========================================================
// 가져오기
// ==========================================================
function gfImpBatch() {
    document.getElementById('imp-batch').style.display =
        document.getElementById('mk-batch').checked ? 'flex' : 'none';
}
</script>
JS;
}
?>
