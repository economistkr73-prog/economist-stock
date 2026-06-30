<?php
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_once "./env/nav.inc";
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
/* 상단 분류 칩 바 (places.php tb-chips 패턴) */
#group-chipbar { display:flex; align-items:center; gap:8px; padding:12px 16px 0; flex-shrink:0; }
.gc-chips { display:flex; gap:6px; overflow-x:auto; flex:1; scrollbar-width:thin; padding-bottom:2px; }
.gc-chips::-webkit-scrollbar { height:5px; }
.gc-chips::-webkit-scrollbar-thumb { background:#d8dde3; border-radius:3px; }
.gc-chip { flex-shrink:0; white-space:nowrap; font-size:13px; font-weight:600; padding:7px 14px; border-radius:16px; background:#fff; border:1px solid #e2e7ec; color:#5a6b7b; cursor:pointer; transition:.12s; }
.gc-chip:hover { background:#eaf3fb; border-color:#cfe4f7; color:#2471a3; }
.gc-chip.active { background:#3498db; border-color:#2980b9; color:#fff; }
.gc-chip .gc-cnt { font-size:11px; opacity:.6; margin-left:5px; font-weight:500; }
.gc-chip.active .gc-cnt { opacity:.9; }
.gc-chip.is-new { color:#e67e22; border-color:#ecdcc6; }
.gc-chip.is-new.active { background:#e67e22; border-color:#d35400; color:#fff; }
.gc-manage { flex-shrink:0; font-size:12px; padding:6px 11px; }
/* 그룹 관리 모달 행 */
.gm-row { display:flex; align-items:center; gap:8px; padding:8px 4px; border-bottom:1px solid #f3f3f3; }
.gm-name { flex:1; min-width:0; border:1px solid transparent; border-radius:6px; padding:6px 8px; font-size:14px; background:#f8f9fa; }
.gm-name:focus { border-color:#3498db; background:#fff; outline:none; }
.gm-cnt { font-size:12px; color:#999; white-space:nowrap; }
.gm-merge { border:1px solid #dde; border-radius:6px; padding:5px 6px; font-size:12px; max-width:110px; }
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
.edit-pencil { background:none; border:none; cursor:pointer; font-size:0.65em; line-height:1; padding:3px 6px; margin-left:8px; border-radius:6px; vertical-align:middle; opacity:.55; transition:.12s; }
.edit-pencil:hover { opacity:1; background:#f0f0f0; }
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
/* ── 모바일(UA, body.is-mobile): 드릴다운 레이아웃 — 폭 아닌 기기 기준(폴더블 와이드/세로 모두) ── */
body.is-mobile { --fs-sm:15px; --fs-base:17px; --fs-lg:20px; }   /* 모바일 글씨 키우기 */
body.is-mobile .contact-item .c-name { font-size:18px; }
body.is-mobile .contact-item .c-group, body.is-mobile .contact-item .new-badge { font-size:13px; }
body.is-mobile .detail-row { font-size:17px; }
body.is-mobile .detail-row .label { width:78px; }
body.is-mobile .btn { font-size:15px; padding:9px 14px; }
body.is-mobile #wrap { flex-direction:column; padding:8px; gap:8px; position:relative; }
body.is-mobile .m-only { display:inline-flex !important; }
body.is-mobile #group-chipbar { padding:8px 8px 0; }
/* 목록 → 전체 폭 메인 화면 */
body.is-mobile #left { width:100%; flex:1; }
/* 3단 상세 → 전체화면 오버레이 (선택 시 슬라이드 인) */
body.is-mobile #right {
    position:fixed; top:60px; left:0; right:0; bottom:0;
    border-radius:0; transform:translateX(100%);
    transition:transform .25s ease; z-index:1600; padding:16px;
}
body.is-mobile.detail-open #right { transform:translateX(0); }
body.is-mobile.detail-open { overflow:hidden; }
body.is-mobile .detail-head h2 { font-size:20px; }
body.is-mobile .history-item .h-date { min-width:auto; }
</style>
<?php nav_css(); ?>
</head>
<body class="<?= !empty($mobile) ? 'is-mobile' : '' ?>">
<?php render_nav('contacts'); ?>

<!-- 상단: 분류 칩 바 -->
<div id="group-chipbar">
    <div class="gc-chips" id="group-chips"></div>
    <button class="btn btn-outline gc-manage" onclick="openGroupModal()" title="그룹 추가/이름변경/통합/삭제">⚙ 관리</button>
</div>

<div id="wrap">
    <!-- 주소록 목록 -->
    <div id="left">
        <div class="left-head">
            <h2>주소록
                <span style="display:flex;gap:6px;">
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
                <select id="cf-group" onchange="onGroupSelectChange()"></select>
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

<!-- 그룹 관리 모달 -->
<div class="modal-overlay" id="group-modal">
    <div class="modal" style="width:460px;">
        <h3>그룹 관리</h3>
        <div style="display:flex;gap:6px;margin-bottom:14px;">
            <input type="text" id="gm-add-input" placeholder="새 그룹 이름" maxlength="50"
                   style="flex:1;border:1px solid #dde;border-radius:6px;padding:8px 10px;font-size:14px;"
                   onkeydown="if(event.key==='Enter')gmAdd()">
            <button class="btn btn-primary" onclick="gmAdd()">+ 추가</button>
        </div>
        <div id="gm-list" style="max-height:50vh;overflow-y:auto;"></div>
        <div style="margin-top:12px;font-size:12px;color:#888;line-height:1.6;">
            · 이름을 고치면 그 그룹의 모든 연락처에 반영됩니다.<br>
            · 기존 그룹명으로 바꾸거나 <b>통합→</b>을 고르면 두 그룹이 합쳐집니다.<br>
            · 삭제 시 소속 연락처는 <b>신규 미분류</b>로 이동합니다.
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeGroupModal()">닫기</button>
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
    // 상단 분류 칩 바 (가로 스크롤)
    const res=await api('groups');
    const box=document.getElementById('group-chips');
    let totalCnt=0, newCnt=0, groupChips='';
    (res.data||[]).forEach(g=>{
        const gname=(g.group_name||'').trim();
        totalCnt += parseInt(g.cnt)||0;
        if (gname===''){ newCnt += parseInt(g.cnt)||0; return; } // 빈 그룹=신규
        groupChips += gcChip(gname, gname, g.cnt, false);
    });
    let h = gcChip('', '전체', totalCnt, false);
    if (newCnt>0) h += gcChip('__NEW__', '⭐ 신규', newCnt, true);
    h += groupChips;
    box.innerHTML=h;

    // 수정 모달 그룹 드롭다운용 전체 그룹명 (빈 미분류 제외)
    GROUP_NAMES = (res.data||[]).filter(g=>(g.group_name||'').trim()!=='').map(g=>g.group_name);
    // 모달이 열려 있으면 선택값 유지하며 다시 채움
    if (document.getElementById('contact-modal').classList.contains('open'))
        fillGroupSelect(document.getElementById('cf-group').value);
}

// 수정 모달 그룹 <select> 채우기 (전체 그룹 + 미분류 + 새 그룹 추가)
let GROUP_NAMES = [];
function fillGroupSelect(selected) {
    selected = selected || '';
    const sel = document.getElementById('cf-group');
    const names = [...GROUP_NAMES];
    // 현재 연락처 그룹이 목록에 없으면(미등록 그룹) 맨 앞에 보존
    if (selected && !names.includes(selected)) names.unshift(selected);
    let html = '<option value="">(미분류)</option>';
    html += names.map(n=>`<option value="${escAttr(n)}">${escHtml(n)}</option>`).join('');
    html += '<option value="__NEW__">➕ 새 그룹 추가…</option>';
    sel.innerHTML = html;
    sel.value = selected;
}

async function onGroupSelectChange() {
    const sel = document.getElementById('cf-group');
    if (sel.value !== '__NEW__') return;
    const name = (prompt('새 그룹 이름을 입력하세요.')||'').trim();
    if (!name){ sel.value=''; return; }
    const res = await api('group_add', {name}, 'POST');
    if (res && !res.ok){ alert(res.msg||'추가 실패'); sel.value=''; return; }
    await loadGroups();        // GROUP_NAMES + 좌측 패널 갱신
    fillGroupSelect(name);     // 새 그룹 선택된 상태로 재구성
}

function gcChip(value, label, cnt, isNew) {
    const active = (CUR_GROUP===value) ? ' active' : '';
    const nw = isNew ? ' is-new' : '';
    const v = String(value).replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    return `<button class="gc-chip${nw}${active}" onclick="filterGroup('${v}')">${escHtml(label)}<span class="gc-cnt">${cnt}</span></button>`;
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

function filterGroup(g){ CUR_GROUP=g; loadGroups(); loadContacts(); }

let searchTimer=null;
function debounceSearch(){ clearTimeout(searchTimer); searchTimer=setTimeout(loadContacts,250); }

function isMobile(){ return document.body.classList.contains('is-mobile'); }   // UA(서버 $mobile) 단일 기준
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
                <h2>${c.name}<button class="edit-pencil" onclick="openContactModal(${c.id})" title="수정">✏️</button></h2>
                <span class="c-group">${c.group_name||'기타'}</span>
            </div>
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
    fillGroupSelect(c?.group_name||'');
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
        group_name:(g=>g==='__NEW__'?'':g)(document.getElementById('cf-group').value),
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

// ━━━ 그룹 관리 ━━━
let GROUPS_CACHE = [];
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s){ return escHtml(s).replace(/"/g,'&quot;'); }

async function openGroupModal() {
    document.getElementById('gm-add-input').value='';
    await loadGroupMgmt();
    document.getElementById('group-modal').classList.add('open');
    document.getElementById('gm-add-input').focus();
}
function closeGroupModal(){ document.getElementById('group-modal').classList.remove('open'); }

async function loadGroupMgmt() {
    const res = await api('groups');
    // 실제 그룹만 (빈 '미분류' 버킷 제외) — 인덱스로 참조하므로 순서 유지
    GROUPS_CACHE = (res.data||[]).filter(g=>(g.group_name||'').trim()!=='');
    renderGroupMgmt();
}

function renderGroupMgmt() {
    const el = document.getElementById('gm-list');
    if (!GROUPS_CACHE.length){ el.innerHTML='<p style="color:#aaa;padding:14px 4px">그룹이 없습니다. 위에서 추가하세요.</p>'; return; }
    el.innerHTML = GROUPS_CACHE.map((g,i)=>{
        const name=g.group_name;
        const others = GROUPS_CACHE.map((x,j)=>({n:x.group_name,j})).filter(x=>x.j!==i);
        const mergeSel = others.length
            ? `<select class="gm-merge" onchange="gmMerge(${i}, this.value); this.value='';">
                 <option value="">통합→</option>
                 ${others.map(o=>`<option value="${o.j}">${escHtml(o.n)}</option>`).join('')}
               </select>`
            : '';
        return `<div class="gm-row">
            <input class="gm-name" value="${escAttr(name)}" maxlength="50"
                   onkeydown="if(event.key==='Enter')this.blur()" onblur="gmRename(${i}, this.value)">
            <span class="gm-cnt">${g.cnt}명</span>
            ${mergeSel}
            <button class="btn btn-danger" style="padding:4px 9px;font-size:12px" onclick="gmDelete(${i})">삭제</button>
        </div>`;
    }).join('');
}

async function gmAdd() {
    const inp = document.getElementById('gm-add-input');
    const name = inp.value.trim();
    if (!name){ inp.focus(); return; }
    const res = await api('group_add', {name}, 'POST');
    if (res && !res.ok){ alert(res.msg||'추가 실패'); return; }
    if (res && res.added===false){ alert(`'${name}' 그룹은 이미 있습니다.`); return; }
    inp.value=''; inp.focus();
    await loadGroupMgmt();
    await loadGroups();
}

async function gmRename(idx, newName) {
    const from = GROUPS_CACHE[idx] && GROUPS_CACHE[idx].group_name;
    const to = (newName||'').trim();
    if (from===undefined || from===null) return;
    if (to==='' || to===from){ renderGroupMgmt(); return; }   // 빈값/무변경 → 원복
    const exists = GROUPS_CACHE.some((g,i)=>i!==idx && g.group_name===to);
    if (exists && !confirm(`'${to}' 그룹이 이미 있습니다.\n두 그룹을 통합할까요?`)){ renderGroupMgmt(); return; }
    const res = await api('group_rename', {from, to}, 'POST');
    if (res && !res.ok){ alert(res.msg||'변경 실패'); renderGroupMgmt(); return; }
    await loadGroupMgmt();
    await afterGroupChange(from, to);
}

async function gmMerge(idx, targetIdx) {
    if (targetIdx==='' || targetIdx===null || targetIdx===undefined) return;
    const from = GROUPS_CACHE[idx] && GROUPS_CACHE[idx].group_name;
    const to   = GROUPS_CACHE[parseInt(targetIdx)] && GROUPS_CACHE[parseInt(targetIdx)].group_name;
    if (!from || !to) return;
    if (!confirm(`'${from}' → '${to}' 로 통합하시겠습니까?\n'${from}'의 모든 연락처가 '${to}'로 이동합니다.`)){ renderGroupMgmt(); return; }
    const res = await api('group_rename', {from, to}, 'POST');
    if (res && !res.ok){ alert(res.msg||'통합 실패'); return; }
    await loadGroupMgmt();
    await afterGroupChange(from, to);
}

async function gmDelete(idx) {
    const g = GROUPS_CACHE[idx];
    if (!g) return;
    const name = g.group_name, cnt = parseInt(g.cnt)||0;
    const msg = cnt>0
        ? `'${name}' 그룹을 삭제하시겠습니까?\n소속 연락처 ${cnt}명은 '신규 미분류'로 이동합니다.`
        : `'${name}' 그룹을 삭제하시겠습니까?`;
    if (!confirm(msg)) return;
    const res = await api('group_delete', {name}, 'POST');
    if (res && !res.ok){ alert(res.msg||'삭제 실패'); return; }
    await loadGroupMgmt();
    await afterGroupChange(name, '');
}

// 그룹 변경 후: 현재 필터가 사라진 그룹이면 이동시키고 패널/목록 갱신
async function afterGroupChange(from, to) {
    if (CUR_GROUP===from) CUR_GROUP = to || '';
    await loadGroups();
    await loadContacts();
}

document.getElementById('contact-modal').addEventListener('click',function(e){if(e.target===this)closeContactModal();});
document.getElementById('anniv-modal').addEventListener('click',function(e){if(e.target===this)closeAnnivModal();});
document.getElementById('group-modal').addEventListener('click',function(e){if(e.target===this)closeGroupModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeContactModal();closeAnnivModal();closeGroupModal();}});

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
