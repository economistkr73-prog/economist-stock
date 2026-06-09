<?php
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

$mode = $_GET['mode'] ?? 'list';

$routes = [
    'list' => 'contact_list',
];

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $routes[$mode]($pdo);
} else {
    contact_list($pdo);
}

// ##########################################################
function contact_list(PDO $pdo): void {
    (new Contact($pdo))->ensureTable();
    global $current_user, $expire_date;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>주소록 — 이코노미스트</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root {
    --fs-sm: clamp(11px,0.85vw,18px);
    --fs-base: clamp(12px,1vw,20px);
    --fs-lg: clamp(14px,1.2vw,24px);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html, body { overflow-x:hidden; }
body { font-family:'Pretendard','Malgun Gothic',sans-serif; background:#f0f2f5; color:#2c3e50; height:100vh; display:flex; flex-direction:column; overflow:hidden; }
/* nav */
.top-nav-bar { background:#2c3e50; color:#fff; height:60px; display:flex; justify-content:space-between; align-items:center; padding:0 20px; box-shadow:0 2px 8px rgba(0,0,0,.15); flex-shrink:0; }
.nav-burger { display:none; background:none; border:none; color:#fff; font-size:22px; cursor:pointer; padding:4px 8px; line-height:1; }
.nav-menu { display:flex; gap:10px; }
.nav-menu a { color:#ecf0f1; text-decoration:none; font-size:16px; font-weight:600; padding:10px 16px; border-radius:6px; }
.nav-menu a:hover, .nav-menu a.active { background:#34495e; color:#f1c40f; }
.nav-user-info { display:flex; align-items:center; gap:15px; font-size:14px; color:#bdc3c7; }
.nav-user-info .user-name { color:#f1c40f; font-weight:bold; }
.btn-logout { background:#e74c3c; color:#fff; text-decoration:none; padding:6px 14px; border-radius:4px; font-size:13px; font-weight:bold; }
.btn { border:none; cursor:pointer; border-radius:6px; font-size:var(--fs-sm); font-weight:600; padding:8px 14px; transition:.15s; }
.btn-primary { background:#3498db; color:#fff; } .btn-primary:hover { background:#2980b9; }
.btn-outline { background:#fff; border:1px solid #bdc3c7; color:#2c3e50; } .btn-outline:hover { background:#ecf0f1; }
.btn-danger { background:#e74c3c; color:#fff; } .btn-danger:hover { background:#c0392b; }
/* layout */
#wrap { flex:1; display:flex; overflow:hidden; padding:16px; gap:16px; }
/* 좌측 목록 */
#left { width:380px; flex-shrink:0; background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08); display:flex; flex-direction:column; overflow:hidden; }
.left-head { padding:14px 16px; border-bottom:1px solid #eee; }
.left-head h2 { font-size:var(--fs-lg); margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; }
.search-box { display:flex; gap:6px; }
.search-box input { flex:1; border:1px solid #dde; border-radius:6px; padding:7px 10px; font-size:var(--fs-sm); }
/* 그룹 패널 (1단) */
#groups-panel { width:200px; flex-shrink:0; background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08); display:flex; flex-direction:column; overflow:hidden; }
.gp-head { padding:14px 16px; border-bottom:1px solid #eee; font-size:var(--fs-lg); font-weight:700; }
.gp-list { flex:1; overflow-y:auto; padding:6px; }
.gp-item { display:flex; justify-content:space-between; align-items:center; padding:9px 12px; border-radius:6px; cursor:pointer; font-size:var(--fs-base); transition:.1s; }
.gp-item:hover { background:#f8f9fa; }
.gp-item.active { background:#3498db; color:#fff; font-weight:600; }
.gp-item .gp-cnt { font-size:12px; color:#aaa; }
.gp-item.active .gp-cnt { color:#eaf4ff; }
.gp-divider { height:1px; background:#eee; margin:6px 8px; }
.contact-list { flex:1; overflow-y:auto; }
.contact-item { padding:12px 16px; border-bottom:1px solid #f3f3f3; cursor:pointer; transition:.1s; }
.contact-item:hover { background:#f8f9fa; }
.contact-item.active { background:#eaf4ff; border-left:3px solid #3498db; }
.contact-item .c-name { font-size:var(--fs-base); font-weight:700; }
.contact-item .c-sub { font-size:var(--fs-sm); color:#888; margin-top:2px; }
.contact-item .c-group { display:inline-block; font-size:11px; padding:1px 7px; border-radius:10px; background:#eef3f8; color:#5a8; margin-left:6px; }
.contact-item .new-badge { display:inline-block; font-size:11px; font-weight:700; padding:1px 7px; border-radius:10px; background:#e74c3c; color:#fff; margin-left:6px; }
.contact-item.is-new { background:#fff8f5; }
/* 우측 상세 */
#right { flex:1; background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08); overflow-y:auto; padding:24px; }
.empty-state { color:#aaa; text-align:center; margin-top:80px; font-size:var(--fs-base); }
.detail-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px; padding-bottom:14px; border-bottom:2px solid #f0f0f0; }
.detail-head h2 { font-size:24px; }
.detail-row { display:flex; padding:8px 0; font-size:var(--fs-base); border-bottom:1px solid #f8f8f8; }
.detail-row .label { width:90px; color:#888; flex-shrink:0; }
.detail-row .value { flex:1; }
.history-sec { margin-top:24px; }
.history-sec h3 { font-size:var(--fs-lg); margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.history-item { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:6px; margin-bottom:4px; font-size:var(--fs-sm); }
.history-item:hover { background:#f8f9fa; }
.history-item .h-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.history-item .h-date { color:#888; min-width:130px; }
.history-item .h-title { flex:1; }
.history-item.done .h-title { text-decoration:line-through; color:#aaa; }
/* 기념일 섹션 */
.anniv-sec { margin-top:24px; }
.anniv-sec h3 { font-size:var(--fs-lg); margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.anniv-item { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:8px; margin-bottom:4px; font-size:var(--fs-sm); border:1px solid #f0f0f0; background:#fff; }
.anniv-item:hover { background:#fafbfc; }
.anniv-icon { font-size:20px; flex-shrink:0; width:28px; text-align:center; }
.anniv-category { font-size:11px; font-weight:700; padding:2px 7px; border-radius:10px; background:#eef3f8; color:#5a7a8a; white-space:nowrap; flex-shrink:0; }
.anniv-title { flex:1; font-weight:600; color:#2c3e50; }
.anniv-date { font-size:var(--fs-sm); color:#888; white-space:nowrap; }
.anniv-memo { font-size:11px; color:#aaa; max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.anniv-btns { display:flex; gap:4px; flex-shrink:0; }
.emoji-btn { font-size:20px; width:34px; height:34px; border:2px solid transparent; border-radius:7px; cursor:pointer; background:#f8f9fa; display:flex; align-items:center; justify-content:center; transition:.12s; }
.emoji-btn:hover { background:#eaf4ff; border-color:#3498db; }
.emoji-btn.selected { border-color:#3498db; background:#eaf4ff; box-shadow:0 0 0 2px #3498db40; }
/* 기념일 모달 */
.anniv-type-btns { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:4px; }
.anniv-type-btn { border:1px solid #dde; background:#f8f9fa; color:#555; border-radius:20px; padding:5px 12px; font-size:13px; cursor:pointer; transition:.15s; }
.anniv-type-btn:hover { background:#eaf4ff; border-color:#3498db; }
.anniv-type-btn.active { background:#3498db; color:#fff; border-color:#2980b9; }
/* form */
.form-row { display:flex; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
.form-row label { display:flex; flex-direction:column; gap:5px; font-size:var(--fs-sm); font-weight:600; flex:1; min-width:140px; }
.form-row input, .form-row select, .form-row textarea { border:1px solid #dde; border-radius:6px; padding:8px 10px; font-size:var(--fs-base); width:100%; }
.form-row textarea { resize:vertical; height:70px; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:3000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal { background:#fff; border-radius:10px; width:520px; max-width:95vw; padding:24px; box-shadow:0 8px 32px rgba(0,0,0,.2); }
.modal h3 { font-size:18px; margin-bottom:18px; }
.modal-footer { display:flex; gap:8px; justify-content:flex-end; margin-top:18px; }
/* 모바일 전용 버튼 (기본 숨김) */
.m-only { display:none !important; }
.drawer-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1400; }
/* ── 모바일 (≤768px): 드릴다운 레이아웃 ── */
@media (max-width:768px) {
    /* 헤더: 햄버거 메뉴 */
    .top-nav-bar { height:48px; padding:0 8px; }
    .nav-burger { display:block; }
    .nav-menu {
        position:absolute; top:48px; left:0; right:0; flex-direction:column;
        background:#2c3e50; gap:0; display:none; z-index:1501;
        box-shadow:0 6px 16px rgba(0,0,0,.25); max-height:70vh; overflow-y:auto;
    }
    body.nav-open .nav-menu { display:flex; }
    .nav-menu a { padding:13px 18px; border-bottom:1px solid rgba(255,255,255,.08); border-radius:0; }
    .nav-menu a[href*="condition_analysis"],
    .nav-menu a[href*="stock_analysis"],
    .nav-menu a[href*="data_upload"] { display:none; }
    .nav-user-info > span { display:none; }   /* 환영문구 숨김, 로그아웃만 */
    /* 모바일 글씨 키우기 */
    :root { --fs-sm:15px; --fs-base:17px; --fs-lg:20px; }
    .contact-item .c-name { font-size:18px; }
    .contact-item .c-group, .contact-item .new-badge { font-size:13px; }
    .detail-row { font-size:17px; }
    .detail-row .label { width:78px; }
    .btn { font-size:15px; padding:9px 14px; }
    #wrap { flex-direction:column; padding:8px; gap:8px; position:relative; }
    .m-only { display:inline-flex !important; }
    /* 1단 그룹 → 좌측 슬라이드 서랍 */
    #groups-panel {
        position:fixed; top:48px; left:0; bottom:0;
        width:78%; max-width:300px; border-radius:0 12px 12px 0;
        transform:translateX(-100%); transition:transform .25s ease;
        z-index:1500; box-shadow:4px 0 16px rgba(0,0,0,.18);
    }
    body.groups-open #groups-panel { transform:translateX(0); }
    body.groups-open .drawer-backdrop { display:block; }
    /* 2단 목록 → 전체 폭 메인 화면 */
    #left { width:100%; flex:1; }
    /* 3단 상세 → 전체화면 오버레이 (선택 시 슬라이드 인) */
    #right {
        position:fixed; top:60px; left:0; right:0; bottom:0;
        border-radius:0; transform:translateX(100%);
        transition:transform .25s ease; z-index:1600; padding:16px;
    }
    body.detail-open #right { transform:translateX(0); }
    body.detail-open { overflow:hidden; }
    .detail-head h2 { font-size:20px; }
    .history-item .h-date { min-width:auto; }
}
</style>
</head>
<body>
<div class="top-nav-bar">
    <button class="nav-burger" onclick="document.body.classList.toggle('nav-open')" aria-label="메뉴">☰</button>
    <div class="nav-menu">
        <a href="/etf_stock.php?mode=ef">주식ETF분석</a>
        <a href="/condition_analysis.php?mode=cf">조건검색분석</a>
        <a href="/stock_analysis.php?mode=si">주식그래프</a>
        <a href="/schedule.php?mode=calendar">스케줄러</a>
        <a href="/contacts.php" class="active">주소록</a>
        <a href="/anniversary.php">기념일</a>
    </div>
    <div class="nav-user-info">
        <span>환영합니다, <span class="user-name"><?php echo htmlspecialchars($current_user); ?></span>님</span>
        <a href="logout.php" class="btn-logout">로그아웃</a>
    </div>
</div>
<script>
(function(){ if (matchMedia('(max-width:768px)').matches || document.body.classList.contains('is-mobile')) {
    document.querySelectorAll('.nav-menu a[href*="etf_stock.php"]').forEach(a=>a.href='/etf_stock.php?mode=m'); } })();
</script>

<div id="wrap">
    <!-- 1단: 그룹 -->
    <div id="groups-panel">
        <div class="gp-head">그룹</div>
        <div class="gp-list" id="group-panel-list"></div>
    </div>
    <div class="drawer-backdrop" onclick="toggleGroups(false)"></div>

    <!-- 2단: 주소록 목록 -->
    <div id="left">
        <div class="left-head">
            <h2>주소록
                <span style="display:flex;gap:6px;">
                    <button class="btn btn-outline m-only" onclick="toggleGroups()" title="그룹 보기">☰ 그룹</button>
                    <button class="btn btn-outline" onclick="importDrive()" title="구글드라이브 명함 CSV 동기화">☁ 드라이브</button>
                    <button class="btn btn-primary" onclick="openContactModal()">+ 추가</button>
                </span>
            </h2>
            <div class="search-box">
                <input type="text" id="search" placeholder="이름, 전화, 이메일, 소속 검색" oninput="debounceSearch()">
            </div>
        </div>
        <div class="contact-list" id="contact-list"></div>
    </div>

    <!-- 3단: 내용 (상세 + 히스토리) -->
    <div id="right">
        <div class="empty-state" id="empty-state">← 인물을 선택하세요.</div>
        <div id="detail" style="display:none;"></div>
    </div>
</div>

<!-- 추가/수정 모달 -->
<div class="modal-overlay" id="contact-modal">
    <div class="modal">
        <h3 id="cm-title">인물 추가</h3>
        <div class="form-row">
            <label>이름 * <input type="text" id="cf-name" placeholder="이름"></label>
            <label>영문이름 <input type="text" id="cf-engname" placeholder="English name"></label>
            <label>그룹
                <input type="text" id="cf-group" list="group-list" placeholder="가족/직장/거래처...">
                <datalist id="group-list"></datalist>
            </label>
        </div>
        <div class="form-row">
            <label>휴대폰 <input type="text" id="cf-phone" placeholder="010-0000-0000"></label>
            <label>유선전화 <input type="text" id="cf-tel" placeholder="02-000-0000"></label>
            <label>이메일 <input type="email" id="cf-email" placeholder="email@example.com"></label>
        </div>
        <div class="form-row">
            <label>소속 <input type="text" id="cf-org" placeholder="회사/기관"></label>
            <label>부서 <input type="text" id="cf-dept" placeholder="소속부서"></label>
            <label>직책 <input type="text" id="cf-pos" placeholder="직책"></label>
        </div>
        <div class="form-row">
            <label>주소 <input type="text" id="cf-addr" placeholder="주소"></label>
        </div>
        <div class="form-row">
            <label>웹사이트 <input type="text" id="cf-web" placeholder="www.example.com"></label>
        </div>
        <div class="form-row">
            <label>회사전화 <input type="text" id="cf-cphone" placeholder="회사 대표번호"></label>
            <label>팩스 <input type="text" id="cf-fax" placeholder="팩스번호"></label>
        </div>
        <div class="form-row">
            <label>메모 <textarea id="cf-memo" placeholder="메모"></textarea></label>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger" id="cm-delete" style="display:none;margin-right:auto" onclick="deleteContact()">삭제</button>
            <button class="btn btn-outline" onclick="closeContactModal()">취소</button>
            <button class="btn btn-primary" onclick="saveContact()">저장</button>
        </div>
    </div>
</div>

<!-- 기념일 추가/수정 모달 -->
<div class="modal-overlay" id="anniv-modal">
    <div class="modal" style="width:480px;">
        <h3 id="am-title">기념일 추가</h3>
        <!-- 종류 선택 -->
        <div style="margin-bottom:14px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px;">종류</div>
            <div class="anniv-type-btns" id="am-type-btns"></div>
        </div>
        <!-- 제목 -->
        <div class="form-row">
            <label>제목 * <input type="text" id="am-title-input" placeholder="예: 홍길동 생일"></label>
        </div>
        <!-- 양력/음력 + 날짜 -->
        <div style="margin-bottom:14px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px;">날짜</div>
            <div style="display:flex;gap:16px;margin-bottom:8px;">
                <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="am-cal" value="solar" checked onchange="onAmCalChange()"> 양력
                </label>
                <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="am-cal" value="lunar" onchange="onAmCalChange()"> 음력
                </label>
            </div>
            <!-- 양력 날짜 -->
            <div id="am-solar-row">
                <input type="date" id="am-date" style="border:1px solid #dde;border-radius:6px;padding:7px 10px;font-size:13px;">
            </div>
            <!-- 음력 날짜 -->
            <div id="am-lunar-row" style="display:none;">
                <div style="display:flex;gap:8px;align-items:center;">
                    <select id="am-lunar-month" style="border:1px solid #dde;border-radius:6px;padding:7px 10px;font-size:13px;">
                        <?php for($m=1;$m<=12;$m++) echo "<option value='{$m}'>{$m}월</option>"; ?>
                    </select>
                    <select id="am-lunar-day" style="border:1px solid #dde;border-radius:6px;padding:7px 10px;font-size:13px;">
                        <?php for($d=1;$d<=30;$d++) echo "<option value='{$d}'>{$d}일</option>"; ?>
                    </select>
                    <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;">
                        <input type="checkbox" id="am-lunar-leap" style="width:auto;"> 윤달
                    </label>
                </div>
            </div>
            <div style="margin-top:5px;font-size:12px;color:#888;">★ 매년 자동 반복됩니다.</div>
        </div>
        <!-- 이모지 -->
        <div style="margin-bottom:14px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px;">아이콘</div>
            <div id="am-icon-picker" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            <input type="hidden" id="am-icon">
        </div>
        <!-- 색상 -->
        <div style="margin-bottom:14px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px;">색상</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;" id="am-color-swatches"></div>
            <input type="hidden" id="am-color" value="#e74c3c">
        </div>
        <!-- 메모 -->
        <div class="form-row">
            <label>메모 <textarea id="am-memo" placeholder="메모 (선택)" style="height:55px;"></textarea></label>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger" id="am-delete" style="display:none;margin-right:auto" onclick="deleteAnniv()">삭제</button>
            <button class="btn btn-outline" onclick="closeAnnivModal()">취소</button>
            <button class="btn btn-primary" onclick="saveAnniv()">저장</button>
        </div>
    </div>
</div>

<script>
let CONTACTS=[], CUR_GROUP='', CUR_ID=null, EDIT_ID=null;
const TYPE_ICON={timed:'⏰',allday:'📅',anniversary:'★',todo:'☑'};

let _contactsAbort = null; // 이전 요청 취소용

async function api(action, payload={}, method='GET', signal=null) {
    const base='/schedule_api.php?module=contacts';
    try {
        if (method==='GET') {
            const q=new URLSearchParams(Object.assign({action},payload)).toString();
            return (await fetch(`${base}&${q}`, signal ? {signal} : {})).json();
        }
        return (await fetch(`${base}&action=${action}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).json();
    } catch(e){ if (e.name==='AbortError') return null; console.error(e); return {ok:false}; }
}

async function loadGroups() {
    // 그룹 패널 (세로 리스트)
    const res=await api('groups');
    const panel=document.getElementById('group-panel-list');
    let totalCnt=0, newCnt=0, groupItems='';
    (res.data||[]).forEach(g=>{
        const gname=(g.group_name||'').trim();
        totalCnt += parseInt(g.cnt)||0;
        if (gname===''){ newCnt += parseInt(g.cnt)||0; return; } // 빈 그룹=신규
        groupItems += gpItem(gname, gname, g.cnt);
    });
    let h = gpItem('', '전체', totalCnt);
    if (newCnt>0) h += gpItem('__NEW__', '⭐ 신규 미분류', newCnt);
    h += '<div class="gp-divider"></div>' + groupItems;
    panel.innerHTML=h;

    // datalist: 주소록에 등록된 그룹 (자동완성용)
    document.getElementById('group-list').innerHTML=
        (res.data||[]).filter(g=>(g.group_name||'').trim()!=='')
            .map(g=>`<option value="${g.group_name}">`).join('');
}

function gpItem(value, label, cnt) {
    const active = (CUR_GROUP===value) ? ' active' : '';
    return `<div class="gp-item${active}" onclick="filterGroup('${value}')">
        <span>${label}</span><span class="gp-cnt">${cnt}</span></div>`;
}

async function loadContacts() {
    // 이전 진행 중인 요청 취소
    if (_contactsAbort) _contactsAbort.abort();
    _contactsAbort = new AbortController();
    const signal = _contactsAbort.signal;

    const search=document.getElementById('search').value;
    // __NEW__(신규 미분류)는 서버 그룹필터 대신 전체 조회 후 클라이언트 필터
    const grp = (CUR_GROUP==='__NEW__') ? '' : CUR_GROUP;
    const res=await api('list',{search,group:grp},'GET',signal);
    if (!res) return; // 요청이 취소된 경우
    let list=res.data||[];
    if (CUR_GROUP==='__NEW__') list=list.filter(isNewContact);
    CONTACTS=list;
    renderList();
}

function isNewContact(c){ return !c.group_name || c.group_name.trim()===''; }

function renderList() {
    const el=document.getElementById('contact-list');
    if (!CONTACTS.length){ el.innerHTML='<p style="padding:20px;color:#aaa">인물이 없습니다.</p>'; return; }
    // 신규(그룹 공란) 먼저, 그 다음 이름순
    const sorted=[...CONTACTS].sort((a,b)=>{
        const an=isNewContact(a), bn=isNewContact(b);
        if (an!==bn) return an?-1:1;
        return (a.name||'').localeCompare(b.name||'','ko');
    });
    el.innerHTML=sorted.map(c=>{
        const isNew=isNewContact(c);
        const badge=isNew?'<span class="new-badge">신규</span>':`<span class="c-group">${c.group_name}</span>`;
        // 신규는 클릭 시 바로 수정화면(그룹 지정), 일반은 상세보기
        const onclick=isNew?`openContactModal(${c.id})`:`selectContact(${c.id})`;
        return `<div class="contact-item${CUR_ID==c.id?' active':''}${isNew?' is-new':''}" onclick="${onclick}">
            <div class="c-name">${c.name}${badge}</div>
            <div class="c-sub">${[c.organization,c.position].filter(Boolean).join(' · ')||c.phone||c.email||''}</div>
        </div>`;
    }).join('');
}

function filterGroup(g){ CUR_GROUP=g; loadGroups(); loadContacts(); toggleGroups(false); }

let searchTimer=null;
function debounceSearch(){ clearTimeout(searchTimer); searchTimer=setTimeout(loadContacts,250); }

function isMobile(){ return window.matchMedia('(max-width:768px)').matches; }
function toggleGroups(force){
    const open = (force===undefined) ? !document.body.classList.contains('groups-open') : force;
    document.body.classList.toggle('groups-open', open);
}
function closeDetail(){ document.body.classList.remove('detail-open'); }

async function selectContact(id) {
    CUR_ID=id; renderList();
    const c=CONTACTS.find(x=>x.id==id);
    if (!c) return;
    document.getElementById('empty-state').style.display='none';
    const detail=document.getElementById('detail');
    detail.style.display='block';
    if (isMobile()) document.body.classList.add('detail-open');  // 모바일: 상세 슬라이드 인

    // 기념일 + 히스토리 병렬 로드
    const [annivRes, his] = await Promise.all([
        fetch(`/schedule_api.php?module=contacts&action=anniversaries&id=${id}`).then(r=>r.json()).catch(()=>({ok:false,data:[]})),
        api('history',{id})
    ]);
    ANNIVS = annivRes.data || [];

    const hisFiltered = (his.data||[]).filter(s => s.event_type !== 'anniversary');
    const hisItems = hisFiltered.map(s=>{
        const dt=s.start_dt?s.start_dt.slice(0,16).replace('T',' '):'';
        const icon=TYPE_ICON[s.event_type]||'';
        return `<div class="history-item${s.is_done=='1'?' done':''}">
            <span class="h-dot" style="background:${s.color}"></span>
            <span class="h-date">${dt}</span>
            <span class="h-title">${icon} ${s.title}</span>
        </div>`;
    }).join('') || '<p style="color:#aaa;font-size:13px;padding:8px">연결된 일정이 없습니다.</p>';

    detail.innerHTML=`
        <div class="detail-head">
            <div>
                <button class="btn btn-outline m-only" onclick="closeDetail()" style="margin-bottom:8px">← 목록</button>
                <h2>${c.name}</h2>
                <span class="c-group">${c.group_name||'기타'}</span>
            </div>
            <button class="btn btn-outline" onclick="openContactModal(${c.id})">✏ 수정</button>
        </div>
        <div class="detail-row"><span class="label">영문이름</span><span class="value">${c.eng_name||'-'}</span></div>
        <div class="detail-row"><span class="label">휴대폰</span><span class="value">${c.phone||'-'}</span></div>
        <div class="detail-row"><span class="label">유선전화</span><span class="value">${c.tel||'-'}</span></div>
        <div class="detail-row"><span class="label">이메일</span><span class="value">${c.email||'-'}</span></div>
        <div class="detail-row"><span class="label">소속</span><span class="value">${c.organization||'-'}</span></div>
        <div class="detail-row"><span class="label">부서</span><span class="value">${c.department||'-'}</span></div>
        <div class="detail-row"><span class="label">직책</span><span class="value">${c.position||'-'}</span></div>
        <div class="detail-row"><span class="label">주소</span><span class="value">${c.address||'-'}</span></div>
        <div class="detail-row"><span class="label">회사전화</span><span class="value">${c.corp_phone||'-'}</span></div>
        <div class="detail-row"><span class="label">팩스</span><span class="value">${c.fax||'-'}</span></div>
        <div class="detail-row"><span class="label">웹사이트</span><span class="value">${c.website?`<a href="${c.website.startsWith('http')?c.website:'//'+c.website}" target="_blank">${c.website}</a>`:'-'}</span></div>
        <div class="detail-row"><span class="label">메모</span><span class="value" style="white-space:pre-wrap">${c.memo||'-'}</span></div>
        <div class="anniv-sec">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <h3>★ 기념일 <span style="font-size:13px;color:#888;font-weight:400">(${ANNIVS.length}건)</span></h3>
                <button class="btn btn-outline" style="padding:5px 10px;font-size:12px" onclick="openAnnivModal(${c.id})">+ 기념일 추가</button>
            </div>
            <div id="anniv-list">${buildAnnivList(ANNIVS, c.id)}</div>
        </div>
        <div class="history-sec">
            <h3>📅 일정 히스토리 <span style="font-size:13px;color:#888;font-weight:400">(${hisFiltered.length}건)</span></h3>
            ${hisItems}
        </div>`;
}

function openContactModal(id=null) {
    EDIT_ID=id;
    const c=id?CONTACTS.find(x=>x.id==id):null;
    document.getElementById('cm-title').textContent=id?'인물 수정':'인물 추가';
    document.getElementById('cf-name').value=c?.name||'';
    document.getElementById('cf-engname').value=c?.eng_name||'';
    document.getElementById('cf-group').value=c?.group_name||'';
    document.getElementById('cf-phone').value=c?.phone||'';
    document.getElementById('cf-tel').value=c?.tel||'';
    document.getElementById('cf-email').value=c?.email||'';
    document.getElementById('cf-org').value=c?.organization||'';
    document.getElementById('cf-dept').value=c?.department||'';
    document.getElementById('cf-pos').value=c?.position||'';
    document.getElementById('cf-addr').value=c?.address||'';
    document.getElementById('cf-cphone').value=c?.corp_phone||'';
    document.getElementById('cf-fax').value=c?.fax||'';
    document.getElementById('cf-web').value=c?.website||'';
    document.getElementById('cf-memo').value=c?.memo||'';
    document.getElementById('cm-delete').style.display=id?'':'none';
    document.getElementById('contact-modal').classList.add('open');
    document.getElementById('cf-name').focus();
}
function closeContactModal(){ document.getElementById('contact-modal').classList.remove('open'); }

async function saveContact() {
    const name=document.getElementById('cf-name').value.trim();
    if (!name){ alert('이름을 입력하세요.'); return; }
    const payload={
        name,
        eng_name:document.getElementById('cf-engname').value.trim(),
        group_name:document.getElementById('cf-group').value.trim()||'기타',
        phone:document.getElementById('cf-phone').value.trim(),
        tel:document.getElementById('cf-tel').value.trim(),
        email:document.getElementById('cf-email').value.trim(),
        organization:document.getElementById('cf-org').value.trim(),
        department:document.getElementById('cf-dept').value.trim(),
        position:document.getElementById('cf-pos').value.trim(),
        address:document.getElementById('cf-addr').value.trim(),
        corp_phone:document.getElementById('cf-cphone').value.trim(),
        fax:document.getElementById('cf-fax').value.trim(),
        website:document.getElementById('cf-web').value.trim(),
        memo:document.getElementById('cf-memo').value,
    };
    let res;
    if (EDIT_ID){ payload.id=EDIT_ID; res=await api('update',payload,'POST'); }
    else { res=await api('create',payload,'POST'); }
    if (res && !res.ok){ alert('저장 실패: '+(res.msg||'')); return; }
    closeContactModal();
    await loadGroups(); await loadContacts();
    if (EDIT_ID) selectContact(EDIT_ID);
    else if (res.id){ CUR_ID=res.id; selectContact(res.id); }
}

async function deleteContact() {
    if (!EDIT_ID) return;
    if (!confirm('이 인물을 삭제하시겠습니까?\n(연결된 일정의 참석자 정보도 제거됩니다)')) return;
    await api('delete',{id:EDIT_ID});
    closeContactModal();
    CUR_ID=null;
    document.getElementById('detail').style.display='none';
    document.getElementById('empty-state').style.display='block';
    await loadGroups(); await loadContacts();
}

// ━━━ 구글드라이브 자동 동기화 ━━━
async function importDrive() {
    if (!confirm('구글드라이브의 명함 인명록 CSV를 가져옵니다.\n동일 인물(이름+모바일)은 최신 정보로 갱신됩니다. 진행할까요?')) return;
    const res = await api('import_drive', {}, 'POST');
    if (!res || !res.ok) { alert('드라이브 가져오기 실패:\n'+(res?.msg||'알 수 없는 오류')); return; }
    const d = res.data;
    alert(`드라이브 동기화 완료\n신규: ${d.imported}건 / 갱신: ${d.updated}건 (전체 ${d.total}건)`);
    await loadGroups(); await loadContacts();
}

document.getElementById('contact-modal').addEventListener('click',function(e){if(e.target===this)closeContactModal();});
document.getElementById('anniv-modal').addEventListener('click',function(e){if(e.target===this)closeAnnivModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeContactModal();closeAnnivModal();}});

// ━━━ 기념일 관련 ━━━
const ANNIV_TYPES = [
    {type:'생일',       icon:'🎂', color:'#e74c3c'},
    {type:'결혼기념일', icon:'💍', color:'#9b59b6'},
    {type:'사귄날',     icon:'💝', color:'#e91e63'},
    {type:'졸업일',     icon:'🎓', color:'#3498db'},
    {type:'입사일',     icon:'🏢', color:'#2ecc71'},
    {type:'기타',       icon:'⭐', color:'#f39c12'},
];
const ANNIV_ICONS = ['🎂','🎉','💍','💝','🎓','🏢','🌸','🎊','⭐','🥂','🎁','🌟'];
const ANNIV_COLORS = ['#e74c3c','#9b59b6','#e91e63','#3498db','#2ecc71','#f39c12','#1abc9c','#e67e22','#95a5a6'];

let ANNIVS = [], ANNIV_CONTACT_ID = null, ANNIV_EDIT_ID = null;

function buildAnnivList(annivs, contactId) {
    if (!annivs.length) return '<p style="color:#aaa;font-size:13px;padding:4px 0">등록된 기념일이 없습니다.</p>';
    return annivs.map(a => {
        const rr = typeof a.recur_rule === 'string' ? JSON.parse(a.recur_rule||'null') : a.recur_rule;
        let dateStr = '';
        if (rr && rr.calendar === 'lunar') {
            dateStr = `${String(rr.lunar_month).padStart(2,'0')}월 ${String(rr.lunar_day).padStart(2,'0')}일 (음력)`;
        } else if (a.start_dt) {
            const md = a.start_dt.slice(5,10);
            dateStr = md.replace('-','월 ') + '일 (양력)';
        }
        const memo = a.memo ? `<span class="anniv-memo" title="${a.memo}">${a.memo}</span>` : '';
        return `<div class="anniv-item">
            <span class="anniv-icon">${a.icon||'⭐'}</span>
            <span class="anniv-category">${a.category||''}</span>
            <span class="anniv-title">${a.title}</span>
            <span class="anniv-date">${dateStr}</span>
            ${memo}
            <div class="anniv-btns">
                <button class="btn btn-outline" style="padding:3px 8px;font-size:11px" onclick="openAnnivModal(${contactId},${a.id})">수정</button>
                <button class="btn btn-danger" style="padding:3px 8px;font-size:11px" onclick="confirmDeleteAnniv(${a.id})">삭제</button>
            </div>
        </div>`;
    }).join('');
}

function initAnnivModal() {
    // 종류 버튼
    document.getElementById('am-type-btns').innerHTML = ANNIV_TYPES.map(t =>
        `<button type="button" class="anniv-type-btn" data-atype="${t.type}" data-icon="${t.icon}" data-color="${t.color}"
            onclick="selectAnnivType('${t.type}','${t.icon}','${t.color}')">${t.icon} ${t.type}</button>`
    ).join('');
    // 아이콘 picker
    document.getElementById('am-icon-picker').innerHTML = ANNIV_ICONS.map(ic =>
        `<button type="button" class="emoji-btn" data-ic="${ic}" onclick="selectAnnivIcon('${ic}')">${ic}</button>`
    ).join('');
    // 색상 swatches
    document.getElementById('am-color-swatches').innerHTML = ANNIV_COLORS.map(c =>
        `<span class="color-swatch" data-color="${c}" style="background:${c};width:22px;height:22px;border-radius:50%;cursor:pointer;border:3px solid transparent;display:inline-block;transition:.1s"
            onclick="selectAnnivColor('${c}')"></span>`
    ).join('');
}

function selectAnnivType(type, icon, color) {
    document.querySelectorAll('.anniv-type-btn').forEach(b => b.classList.toggle('active', b.dataset.atype === type));
    // 제목이 비어있거나 이전 종류명이면 자동 채움
    const titleEl = document.getElementById('am-title-input');
    const c = CONTACTS.find(x => x.id == ANNIV_CONTACT_ID);
    const cname = c ? c.name : '';
    const prevTypes = ANNIV_TYPES.map(t=>t.type);
    const cur = titleEl.value.trim();
    if (!cur || prevTypes.some(t => cur.endsWith(t))) {
        titleEl.value = cname ? `${cname} ${type}` : type;
    }
    selectAnnivIcon(icon);
    selectAnnivColor(color);
}

function selectAnnivIcon(ic) {
    document.getElementById('am-icon').value = ic;
    document.querySelectorAll('#am-icon-picker .emoji-btn').forEach(b => {
        b.classList.toggle('selected', b.dataset.ic === ic);
    });
}

function selectAnnivColor(c) {
    document.getElementById('am-color').value = c;
    document.querySelectorAll('#am-color-swatches .color-swatch').forEach(el => {
        el.style.borderColor = el.dataset.color === c ? '#2c3e50' : 'transparent';
    });
}

function onAmCalChange() {
    const isLunar = document.querySelector('input[name="am-cal"]:checked').value === 'lunar';
    document.getElementById('am-solar-row').style.display = isLunar ? 'none' : '';
    document.getElementById('am-lunar-row').style.display = isLunar ? '' : 'none';
}

function openAnnivModal(contactId, annivId=null) {
    ANNIV_CONTACT_ID = contactId;
    ANNIV_EDIT_ID = annivId;
    initAnnivModal();
    document.getElementById('am-title').textContent = annivId ? '기념일 수정' : '기념일 추가';
    document.getElementById('am-delete').style.display = annivId ? '' : 'none';

    if (annivId) {
        const a = ANNIVS.find(x => x.id == annivId);
        if (a) {
            document.getElementById('am-title-input').value = a.title || '';
            document.getElementById('am-memo').value = a.memo || '';
            const rr = typeof a.recur_rule === 'string' ? JSON.parse(a.recur_rule||'null') : a.recur_rule;
            if (rr && rr.calendar === 'lunar') {
                document.querySelector('input[name="am-cal"][value="lunar"]').checked = true;
                document.getElementById('am-lunar-month').value = rr.lunar_month || 1;
                document.getElementById('am-lunar-day').value = rr.lunar_day || 1;
            } else {
                document.querySelector('input[name="am-cal"][value="solar"]').checked = true;
                if (a.start_dt) document.getElementById('am-date').value = a.start_dt.slice(0,10);
            }
            onAmCalChange();
            selectAnnivIcon(a.icon || '⭐');
            selectAnnivColor(a.color || '#e74c3c');
            // 종류 버튼 복원
            const matchType = ANNIV_TYPES.find(t => (a.title||'').endsWith(t.type));
            if (matchType) {
                document.querySelectorAll('.anniv-type-btn').forEach(b => b.classList.toggle('active', b.dataset.atype === matchType.type));
            }
        }
    } else {
        // 기본값
        document.getElementById('am-title-input').value = '';
        document.getElementById('am-memo').value = '';
        document.querySelector('input[name="am-cal"][value="solar"]').checked = true;
        onAmCalChange();
        document.getElementById('am-date').value = '';
        selectAnnivIcon('⭐');
        selectAnnivColor('#e74c3c');
    }
    document.getElementById('anniv-modal').classList.add('open');
    document.getElementById('am-title-input').focus();
}

function closeAnnivModal() { document.getElementById('anniv-modal').classList.remove('open'); }

async function saveAnniv() {
    const title = document.getElementById('am-title-input').value.trim();
    if (!title) { alert('제목을 입력하세요.'); return; }

    const isLunar = document.querySelector('input[name="am-cal"]:checked').value === 'lunar';
    const icon  = document.getElementById('am-icon').value || '⭐';
    const color = document.getElementById('am-color').value || '#e74c3c';
    const memo  = document.getElementById('am-memo').value;

    const payload = {
        event_type: 'anniversary',
        title, icon, color, memo,
        category: '개인',
        is_allday: 1,
        attendees: [ANNIV_CONTACT_ID],
    };

    if (isLunar) {
        const lm = parseInt(document.getElementById('am-lunar-month').value);
        const ld = parseInt(document.getElementById('am-lunar-day').value);
        if (!lm || !ld) { alert('음력 월/일을 선택하세요.'); return; }
        payload.is_lunar    = 1;
        payload.lunar_month = lm;
        payload.lunar_day   = ld;
        payload.start_dt    = new Date().getFullYear() + '-' + String(lm).padStart(2,'0') + '-' + String(ld).padStart(2,'0') + ' 00:00:00';
        payload.recur_rule  = null;
    } else {
        const sv = document.getElementById('am-date').value;
        if (!sv) { alert('날짜를 선택하세요.'); return; }
        payload.start_dt   = sv + ' 00:00:00';
        payload.is_lunar   = 0;
        payload.recur_rule = {type:'yearly', calendar:'solar', interval:1, end_type:'none'};
    }

    let res;
    if (ANNIV_EDIT_ID) {
        payload.id = ANNIV_EDIT_ID;
        res = await fetch('/schedule_api.php?module=calendar&action=update', {
            method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
        }).then(r=>r.json());
    } else {
        res = await fetch('/schedule_api.php?module=calendar&action=create', {
            method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
        }).then(r=>r.json());
    }
    if (res && !res.ok) { alert('저장 실패: ' + (res.msg||'')); return; }
    closeAnnivModal();
    await refreshAnnivSection();
}

async function confirmDeleteAnniv(id) {
    if (!confirm('이 기념일을 삭제하시겠습니까?')) return;
    ANNIV_EDIT_ID = id;
    await deleteAnniv();
}

async function deleteAnniv() {
    const id = ANNIV_EDIT_ID;
    if (!id) return;
    const res = await fetch(`/schedule_api.php?module=calendar&action=delete&id=${id}`).then(r=>r.json());
    if (res && !res.ok) { alert('삭제 실패'); return; }
    closeAnnivModal();
    await refreshAnnivSection();
}

async function refreshAnnivSection() {
    if (!CUR_ID) return;
    const res = await fetch(`/schedule_api.php?module=contacts&action=anniversaries&id=${CUR_ID}`).then(r=>r.json()).catch(()=>({ok:false,data:[]}));
    ANNIVS = res.data || [];
    const listEl = document.getElementById('anniv-list');
    if (listEl) listEl.innerHTML = buildAnnivList(ANNIVS, CUR_ID);
    // 건수 라벨 갱신
    const sec = listEl?.closest('.anniv-sec');
    if (sec) {
        const badge = sec.querySelector('h3 span');
        if (badge) badge.textContent = `(${ANNIVS.length}건)`;
    }
}

// 초기 로드
loadGroups(); loadContacts();
</script>
</body>
</html>
<?php
} // end contact_list
