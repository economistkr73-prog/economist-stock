<?php
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

(new Schedule($pdo))->ensureTable();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>기념일 관리 — 이코노미스트</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --fs-xs:   clamp(10px, 0.70vw, 16px);
    --fs-sm:   clamp(11px, 0.85vw, 18px);
    --fs-base: clamp(12px, 1.00vw, 20px);
    --fs-lg:   clamp(14px, 1.20vw, 24px);
    --fs-xl:   clamp(18px, 1.50vw, 30px);
}
html, body { overflow-x: hidden; }
body { font-family: 'Pretendard','Malgun Gothic',sans-serif; background: #f0f2f5; color: #2c3e50; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
/* ── nav ── */
.top-nav-bar { background: #2c3e50; color: #fff; height: 60px; display: flex; justify-content: space-between; align-items: center; padding: 0 20px; box-shadow: 0 2px 8px rgba(0,0,0,.15); flex-shrink: 0; }
.nav-burger { display: none; background: none; border: none; color: #fff; font-size: 22px; cursor: pointer; padding: 4px 8px; line-height: 1; }
.nav-menu { display: flex; gap: 10px; }
.nav-menu a { color: #ecf0f1; text-decoration: none; font-size: 16px; font-weight: 600; padding: 10px 16px; border-radius: 6px; }
.nav-menu a:hover, .nav-menu a.active { background: #34495e; color: #f1c40f; }
.nav-user-info { display: flex; align-items: center; gap: 15px; font-size: 14px; color: #bdc3c7; }
.nav-user-info .user-name { color: #f1c40f; font-weight: bold; }
.btn-logout { background: #e74c3c; color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: bold; }
/* ── layout ── */
#wrap { flex: 1; display: flex; flex-direction: column; overflow: hidden; padding: 16px; gap: 12px; }
.toolbar { display: flex; align-items: center; gap: 10px; }
.toolbar h2 { font-size: var(--fs-xl); font-weight: 700; }
.toolbar .spacer { flex: 1; }
.btn { border: none; cursor: pointer; border-radius: 6px; font-size: var(--fs-sm); font-weight: 600; padding: 7px 14px; transition: .15s; }
.btn-primary { background: #3498db; color: #fff; }
.btn-primary:hover { background: #2980b9; }
.btn-outline { background: #fff; border: 1px solid #bdc3c7; color: #2c3e50; }
.btn-outline:hover { background: #ecf0f1; }
.btn-danger { background: #e74c3c; color: #fff; }
.btn-danger:hover { background: #c0392b; }
/* ── 3 columns ── */
.cols { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; flex: 1; overflow: hidden; }
.col { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; flex-direction: column; overflow: hidden; }
.col-head { padding: 14px 16px 12px; border-bottom: 2px solid #f0f0f0; }
.col-head h3 { font-size: var(--fs-lg); font-weight: 700; display: flex; align-items: center; gap: 8px; }
.col-head h3 .cnt { font-size: var(--fs-sm); color: #aaa; font-weight: 400; }
.col-head .col-desc { font-size: var(--fs-xs); color: #999; margin-top: 3px; }
.col-body { flex: 1; overflow-y: auto; padding: 8px; }
/* ── 컬럼 헤더 색상 ── */
.col-general .col-head { border-top: 4px solid #3498db; }
.col-family  .col-head { border-top: 4px solid #e74c3c; }
.col-contact .col-head { border-top: 4px solid #2ecc71; }
/* ── 기념일 아이템 ── */
.anniv-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 10px; border-radius: 8px; margin-bottom: 6px; border: 1px solid #f0f0f0; cursor: pointer; transition: background .1s; }
.anniv-item:hover { background: #f8f9fa; }
.dday { font-size: var(--fs-xs); font-weight: 700; padding: 2px 7px; border-radius: 10px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; }
.dday-today  { background: #e74c3c; color: #fff; }
.dday-soon   { background: #f39c12; color: #fff; }
.dday-near   { background: #3498db; color: #fff; }
.dday-far    { background: #ecf0f1; color: #7f8c8d; }
.dday-past   { background: #f8f9fa; color: #bdc3c7; }
.dday-lunar  { background: #9b59b6; color: #fff; }
.item-icon { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
.item-body { flex: 1; min-width: 0; }
.item-title { font-size: var(--fs-base); font-weight: 600; color: #2c3e50; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.item-meta  { font-size: var(--fs-xs); color: #888; margin-top: 3px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.cat-badge  { font-size: 11px; padding: 1px 6px; border-radius: 9px; background: #eef3f8; color: #5a7a8a; font-weight: 600; }
.cat-family { background: #fde8e8; color: #c0392b; }
.contact-tag { font-size: 11px; color: #2ecc71; font-weight: 600; }
.item-edit { font-size: 13px; padding: 2px 7px; border: 1px solid #dde; border-radius: 4px; background: #fff; cursor: pointer; color: #888; flex-shrink: 0; transition: .1s; margin-top: 1px; }
.item-edit:hover { background: #eaf4ff; border-color: #3498db; color: #3498db; }
.empty-msg { padding: 20px; text-align: center; color: #ccc; font-size: var(--fs-sm); }
/* ── 월 구분선 ── */
.month-divider { font-size: var(--fs-xs); font-weight: 700; color: #aaa; padding: 6px 4px 3px; letter-spacing: 1px; }
/* ── 수정 모달 ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9000; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal-box { background: #fff; border-radius: 12px; width: 440px; max-width: 96vw; box-shadow: 0 8px 32px rgba(0,0,0,.22); display: flex; flex-direction: column; max-height: 90vh; }
.modal-header { padding: 18px 20px 14px; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; justify-content: space-between; }
.modal-header h3 { font-size: 16px; font-weight: 700; }
.modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: #888; line-height: 1; padding: 0 4px; }
.modal-close:hover { color: #333; }
.modal-body { flex: 1; overflow-y: auto; padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; }
.modal-footer { padding: 14px 20px; border-top: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
.class-row { display: flex; gap: 8px; }
.class-btn { flex: 1; padding: 9px 0; border: 2px solid #e0e0e0; border-radius: 8px; background: #fff; cursor: pointer; font-size: 13px; font-weight: 600; color: #888; transition: .15s; }
.class-btn.active-general { border-color: #3498db; background: #eaf4ff; color: #2980b9; }
.class-btn.active-family  { border-color: #e74c3c; background: #fdecea; color: #c0392b; }
.cat-row { display: flex; gap: 8px; }
.cat-btn { flex: 1; padding: 8px 4px; border: 2px solid #e0e0e0; border-radius: 8px; background: #fff; cursor: pointer; font-size: 12px; font-weight: 600; color: #888; text-align: center; transition: .15s; }
.cat-btn.active { border-color: #3498db; background: #eaf4ff; color: #2980b9; }
.date-row { display: flex; gap: 8px; align-items: center; }
.cal-toggle { display: flex; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden; flex-shrink: 0; }
.cal-btn { padding: 6px 12px; background: #fff; border: none; cursor: pointer; font-size: 12px; font-weight: 600; color: #888; }
.cal-btn.active { background: #3498db; color: #fff; }
.date-input { flex: 1; border: 1px solid #ddd; border-radius: 6px; padding: 7px 10px; font-size: 13px; }
.emoji-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.emoji-pick { font-size: 22px; cursor: pointer; padding: 3px 6px; border-radius: 6px; border: 2px solid transparent; transition: .1s; }
.emoji-pick:hover, .emoji-pick.active { border-color: #3498db; background: #eaf4ff; }
.color-row { display: flex; gap: 8px; flex-wrap: wrap; }
.color-sw { width: 26px; height: 26px; border-radius: 50%; cursor: pointer; border: 3px solid transparent; transition: .1s; }
.color-sw.active { border-color: #333; }
.field-label { font-size: 12px; font-weight: 600; color: #888; margin-bottom: 4px; }
.field-block { display: flex; flex-direction: column; }
input[type=text].f-title { border: 1px solid #ddd; border-radius: 6px; padding: 9px 12px; font-size: 14px; width: 100%; }
textarea.f-memo { border: 1px solid #ddd; border-radius: 6px; padding: 8px 12px; font-size: 13px; width: 100%; resize: vertical; min-height: 64px; }
/* ── 모바일 (≤768px): 햄버거 헤더 ── */
@media (max-width:768px) {
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
    .nav-user-info > span { display:none; }   /* 환영문구·자동연장 숨김, 로그아웃만 */
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
        <a href="/contacts.php">주소록</a>
        <a href="/anniversary.php" class="active">기념일</a>
    </div>
    <div class="nav-user-info">
        <span>환영합니다, <span class="user-name"><?php echo htmlspecialchars($current_user); ?></span>님</span>
        <span style="font-size:12px;opacity:.7">(자동연장: <?php echo $expire_date; ?>)</span>
        <a href="logout.php" class="btn-logout">로그아웃</a>
    </div>
</div>
<script>
(function(){ if (matchMedia('(max-width:768px)').matches || document.body.classList.contains('is-mobile')) {
    document.querySelectorAll('.nav-menu a[href*="etf_stock.php"]').forEach(a=>a.href='/etf_stock.php?mode=m'); } })();
</script>

<div id="wrap">
    <div class="toolbar">
        <h2>★ 기념일 관리</h2>
        <span style="font-size:var(--fs-sm);color:#888;" id="today-label"></span>
        <span class="spacer"></span>
        <button class="btn btn-outline" onclick="load()">↺ 새로고침</button>
        <button class="btn btn-primary" onclick="goScheduler()">+ 기념일 등록</button>
    </div>

    <div class="cols">
        <!-- 스케줄러 일반 -->
        <div class="col col-general">
            <div class="col-head">
                <h3>📅 스케줄러 일반 <span class="cnt" id="cnt-general"></span></h3>
                <div class="col-desc">카테고리: 생일·기념일·제사·기타</div>
            </div>
            <div class="col-body" id="list-general"><div class="empty-msg">불러오는 중...</div></div>
        </div>
        <!-- 스케줄러 가족 -->
        <div class="col col-family">
            <div class="col-head">
                <h3>🏠 스케줄러 가족 <span class="cnt" id="cnt-family"></span></h3>
                <div class="col-desc">카테고리: 가족</div>
            </div>
            <div class="col-body" id="list-family"><div class="empty-msg">불러오는 중...</div></div>
        </div>
        <!-- 인명록 기념일 -->
        <div class="col col-contact">
            <div class="col-head">
                <h3>👤 인명록 기념일 <span class="cnt" id="cnt-contact"></span></h3>
                <div class="col-desc">주소록 인물과 연결된 기념일</div>
            </div>
            <div class="col-body" id="list-contact"><div class="empty-msg">불러오는 중...</div></div>
        </div>
    </div>
</div>

<!-- ── 수정 모달 ── -->
<div class="modal-overlay" id="edit-modal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>✏ 기념일 수정</h3>
            <button class="modal-close" onclick="closeEditModal()">×</button>
        </div>
        <div class="modal-body">
            <div id="row-class">
                <div class="field-label">분류</div>
                <div class="class-row">
                    <button class="class-btn" id="cb-general" onclick="setClass(0)">📅 일반</button>
                    <button class="class-btn" id="cb-family"  onclick="setClass(1)">🏠 가족</button>
                </div>
            </div>
            <div>
                <div class="field-label">카테고리</div>
                <div class="cat-row" id="e-cat-row"></div>
            </div>
            <div class="field-block">
                <div class="field-label">제목</div>
                <input type="text" class="f-title" id="e-title" placeholder="기념일 제목">
            </div>
            <div>
                <div class="field-label">날짜</div>
                <div class="date-row">
                    <div class="cal-toggle">
                        <button class="cal-btn" id="cal-solar" onclick="setCalendar('solar')">양력</button>
                        <button class="cal-btn" id="cal-lunar" onclick="setCalendar('lunar')">음력</button>
                    </div>
                    <input type="date" class="date-input" id="e-solar-date">
                    <input type="text" class="date-input" id="e-lunar-month" placeholder="월" style="display:none;width:60px;flex:none;">
                    <input type="text" class="date-input" id="e-lunar-day"   placeholder="일" style="display:none;width:60px;flex:none;">
                </div>
            </div>
            <div>
                <div class="field-label">아이콘</div>
                <div class="emoji-row" id="e-emoji-row"></div>
            </div>
            <div>
                <div class="field-label">색상</div>
                <div class="color-row" id="e-color-row"></div>
            </div>
            <div class="field-block">
                <div class="field-label">메모</div>
                <textarea class="f-memo" id="e-memo" placeholder="메모 (선택)"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger" onclick="deleteAnniv()">삭제</button>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-outline" onclick="closeEditModal()">취소</button>
                <button class="btn btn-primary" onclick="saveEdit()">저장</button>
            </div>
        </div>
    </div>
</div>

<script>
const TODAY = new Date(); TODAY.setHours(0,0,0,0);
const YEAR  = TODAY.getFullYear();

document.getElementById('today-label').textContent =
    `오늘: ${YEAR}년 ${TODAY.getMonth()+1}월 ${TODAY.getDate()}일`;

function pad(n) { return String(n).padStart(2,'0'); }

// ── D-day 계산 (양력) ─────────────────────────────────────
function getLunarSolarDate(rr) {
    if (!rr || !rr.lunar_month || !rr.lunar_day) return null;
    return LUNAR_SOLAR[`${rr.lunar_month},${rr.lunar_day}`] || null;
}

function calcDday(anniv) {
    const rr = anniv.recur_rule;
    let month, day;
    if (rr && rr.calendar === 'lunar') {
        const sd = getLunarSolarDate(rr);
        if (!sd) return null;
        month = parseInt(sd.slice(5,7));
        day   = parseInt(sd.slice(8,10));
    } else {
        const dt = anniv.start_dt;
        if (!dt) return null;
        month = parseInt(dt.slice(5,7));
        day   = parseInt(dt.slice(8,10));
    }
    if (!month || !day) return null;
    let next = new Date(YEAR, month-1, day);
    if (next < TODAY) next = new Date(YEAR+1, month-1, day);
    return Math.round((next - TODAY) / 86400000);
}

function ddayBadge(d, isLunar) {
    const lunarMark = isLunar ? `<span class="dday dday-lunar">음력</span> ` : '';
    if (d === null) return lunarMark;
    let cls;
    if (d === 0)      cls = 'dday-today';
    else if (d <= 7)  cls = 'dday-soon';
    else if (d <= 30) cls = 'dday-near';
    else              cls = 'dday-far';
    const label = d === 0 ? 'D-DAY' : `D-${d}`;
    return `${lunarMark}<span class="dday ${cls}">${label}</span>`;
}

function fmtDate(anniv) {
    const rr = anniv.recur_rule;
    if (rr && rr.calendar === 'lunar') {
        const sd = getLunarSolarDate(rr);
        const solarStr = sd ? `${sd.slice(5,7)}월 ${sd.slice(8,10)}일` : '?월 ?일';
        const lunarStr = `<span style="color:#9b59b6;font-size:10px;">(음력 ${pad(rr.lunar_month)}/${pad(rr.lunar_day)})</span>`;
        return `${solarStr} ${lunarStr}`;
    }
    const dt = anniv.start_dt;
    if (!dt) return '';
    return `${dt.slice(5,7)}월 ${dt.slice(8,10)}일`;
}

function catBadge(cat) {
    const isFam = cat === '가족';
    return `<span class="cat-badge${isFam?' cat-family':''}">${cat||''}</span>`;
}

// ── 정렬: D-day 오름차순 (음력도 변환 후 포함) ───────────
function sortByDday(list) {
    return list.map(a => {
        const rr = a.recur_rule;
        const isLunar = rr && rr.calendar === 'lunar';
        const d = calcDday(a);
        return { ...a, _dday: d, _lunar: isLunar };
    }).sort((a, b) => {
        if (a._dday === null && b._dday === null) return 0;
        if (a._dday === null) return 1;
        if (b._dday === null) return -1;
        return a._dday - b._dday;
    });
}

// ── 아이템 HTML ────────────────────────────────────────────
function buildItem(a) {
    const badge   = ddayBadge(a._dday, false);
    const dateStr = fmtDate(a);
    const cat     = catBadge(a.category || '');
    const lunarTag = a._lunar ? `<span class="dday dday-lunar">음력</span>` : '';
    const contact = a.source === 'contact' && a.contact_name_list
        ? `<span class="contact-tag">👤 ${a.contact_name_list.join(', ')}</span>` : '';
    return `<div class="anniv-item">
        ${badge}
        <span class="item-icon">${a.icon||'⭐'}</span>
        <div class="item-body">
            <div class="item-title">${a.title} ${lunarTag}</div>
            <div class="item-meta">${cat} ${dateStr} ${contact}</div>
        </div>
        <button class="item-edit" onclick="openEditModal(${a.id})">✏</button>
    </div>`;
}

// ── 월 구분선 포함 렌더 ───────────────────────────────────
function renderList(list, targetId, cntId) {
    const el = document.getElementById(targetId);
    document.getElementById(cntId).textContent = `(${list.length}건)`;
    if (!list.length) { el.innerHTML = '<div class="empty-msg">등록된 기념일이 없습니다.</div>'; return; }

    const sorted = sortByDday(list);
    let html = '', lastMonth = -1;
    sorted.forEach(a => {
        // 월 구분선: 양력 기준 월 (음력은 변환된 양력 월, 변환 불가시 마지막으로)
        let m = null;
        if (a._lunar) {
            const rr = a.recur_rule;
            const sd = getLunarSolarDate(rr);
            if (sd) m = parseInt(sd.slice(5,7));
        } else if (a.start_dt) {
            m = parseInt(a.start_dt.slice(5,7));
        }
        if (m !== null && m !== lastMonth) {
            html += `<div class="month-divider">── ${m}월 ──</div>`;
            lastMonth = m;
        } else if (m === null && lastMonth !== 99) {
            html += `<div class="month-divider">── 음력(미확인) ──</div>`;
            lastMonth = 99;
        }
        html += buildItem(a);
    });
    el.innerHTML = html;
}

// ── 전체 기념일 캐시 + 음력→양력 변환 결과 ───────────────
let ALL_ANNIVS  = [];
let LUNAR_SOLAR = {}; // key: "m,d" → "YYYY-MM-DD"

// ── 데이터 로드 ───────────────────────────────────────────
async function load() {
    ['list-general','list-family','list-contact'].forEach(id => {
        document.getElementById(id).innerHTML = '<div class="empty-msg">불러오는 중...</div>';
    });
    try {
        const res = await fetch('/schedule_api.php?module=calendar&action=all_anniversaries').then(r=>r.json());
        ALL_ANNIVS = res.data || [];

        // 음력 항목 일괄 변환
        const lunarItems = [];
        ALL_ANNIVS.forEach(a => {
            const rr = a.recur_rule;
            if (rr && rr.calendar === 'lunar' && rr.lunar_month && rr.lunar_day) {
                lunarItems.push([rr.lunar_month, rr.lunar_day]);
            }
        });
        if (lunarItems.length) {
            const lres = await fetch(
                `/schedule_api.php?module=calendar&action=lunar_to_solar&year=${YEAR}&items=${encodeURIComponent(JSON.stringify(lunarItems))}`
            ).then(r => r.json());
            LUNAR_SOLAR = lres.data || {};
        }

        renderList(ALL_ANNIVS.filter(a => a.source === 'scheduler_general'), 'list-general', 'cnt-general');
        renderList(ALL_ANNIVS.filter(a => a.source === 'scheduler_family'),  'list-family',  'cnt-family');
        renderList(ALL_ANNIVS.filter(a => a.source === 'contact'),           'list-contact', 'cnt-contact');
    } catch(e) {
        ['list-general','list-family','list-contact'].forEach(id => {
            document.getElementById(id).innerHTML = '<div class="empty-msg">불러오기 실패</div>';
        });
    }
}

function goScheduler() {
    window.open('/schedule.php?mode=calendar', '_blank');
}

// ── 수정 모달 ─────────────────────────────────────────────
const ANNIV_CATS  = [
    {cat:'생일',icon:'🎂'},{cat:'제사',icon:'🕯'},{cat:'기념일',icon:'🎉'},{cat:'기타',icon:'⭐'},
];
const ANNIV_ICONS  = ['🎂','🕯','🎉','⭐','💐','🌸','🎁','❤️','🌺','🏆','🎊','👶','💍','🌹','🙏'];
const ANNIV_COLORS = ['#e74c3c','#e67e22','#f1c40f','#2ecc71','#1abc9c','#3498db','#9b59b6','#34495e','#e91e63','#ff5722'];

let E = {};

function openEditModal(id) {
    const a = ALL_ANNIVS.find(x => x.id == id);
    if (!a) return;
    E = JSON.parse(JSON.stringify(a));

    // 분류 행: 스케줄러 기념일만 표시
    const isScheduler = (a.source === 'scheduler_general' || a.source === 'scheduler_family');
    document.getElementById('row-class').style.display = isScheduler ? '' : 'none';
    setClass(isScheduler ? (+(a.is_family||0)) : 0);

    // 카테고리
    renderCatBtns(a.category || '생일');

    // 제목
    document.getElementById('e-title').value = a.title || '';

    // 날짜
    const rr = a.recur_rule;
    const isLunar = rr && rr.calendar === 'lunar';
    setCalendar(isLunar ? 'lunar' : 'solar');
    if (isLunar) {
        document.getElementById('e-lunar-month').value = rr.lunar_month || '';
        document.getElementById('e-lunar-day').value   = rr.lunar_day   || '';
    } else {
        const dt = (a.start_dt || '').slice(0,10);
        const parts = dt.split('-');
        if (parts.length === 3) {
            document.getElementById('e-solar-date').value = `${YEAR}-${parts[1]}-${parts[2]}`;
        }
    }

    // 이모지/색상/메모
    renderEmojiPicker(a.icon  || '⭐');
    renderColorPicker(a.color || '#e74c3c');
    document.getElementById('e-memo').value = a.memo || '';

    document.getElementById('edit-modal').classList.add('open');
}

function closeEditModal() {
    document.getElementById('edit-modal').classList.remove('open');
}

document.getElementById('edit-modal').addEventListener('click', function(ev) {
    if (ev.target === this) closeEditModal();
});

function setClass(val) {
    E.is_family = val;
    document.getElementById('cb-general').className = 'class-btn' + (val === 0 ? ' active-general' : '');
    document.getElementById('cb-family').className  = 'class-btn' + (val === 1 ? ' active-family'  : '');
}

function renderCatBtns(selected) {
    E.category = selected;
    document.getElementById('e-cat-row').innerHTML = ANNIV_CATS.map(c =>
        `<button class="cat-btn${c.cat===selected?' active':''}" onclick="selectCat('${c.cat}')">${c.icon} ${c.cat}</button>`
    ).join('');
}

function selectCat(cat) { renderCatBtns(cat); }

function setCalendar(type) {
    E._calType = type;
    const lunar = type === 'lunar';
    document.getElementById('cal-solar').className = 'cal-btn' + (!lunar ? ' active' : '');
    document.getElementById('cal-lunar').className = 'cal-btn' + ( lunar ? ' active' : '');
    document.getElementById('e-solar-date').style.display  = lunar ? 'none' : '';
    document.getElementById('e-lunar-month').style.display = lunar ? '' : 'none';
    document.getElementById('e-lunar-day').style.display   = lunar ? '' : 'none';
}

function renderEmojiPicker(selected) {
    E.icon = selected;
    document.getElementById('e-emoji-row').innerHTML = ANNIV_ICONS.map(e =>
        `<span class="emoji-pick${e===selected?' active':''}" onclick="selectEmoji('${e}')">${e}</span>`
    ).join('');
}

function selectEmoji(e) { renderEmojiPicker(e); }

function renderColorPicker(selected) {
    E.color = selected;
    document.getElementById('e-color-row').innerHTML = ANNIV_COLORS.map(c =>
        `<div class="color-sw${c===selected?' active':''}" style="background:${c}" onclick="selectColor('${c}')"></div>`
    ).join('');
}

function selectColor(c) { renderColorPicker(c); }

async function saveEdit() {
    const title = document.getElementById('e-title').value.trim();
    if (!title) { alert('제목을 입력하세요.'); return; }

    const isLunar = E._calType === 'lunar';
    let startDt, recurRule;

    if (isLunar) {
        const lm = parseInt(document.getElementById('e-lunar-month').value) || 1;
        const ld = parseInt(document.getElementById('e-lunar-day').value)   || 1;
        recurRule = { type:'yearly', interval:1, calendar:'lunar', lunar_month:lm, lunar_day:ld };
        startDt   = E.start_dt || `${YEAR}-01-01`;
    } else {
        const sd = document.getElementById('e-solar-date').value;
        if (!sd) { alert('날짜를 선택하세요.'); return; }
        startDt   = sd;
        recurRule = { type:'yearly', interval:1, calendar:'solar' };
    }

    const activeCat = document.getElementById('e-cat-row').querySelector('.cat-btn.active');
    const cat = activeCat ? activeCat.textContent.trim().replace(/^\S+\s/,'') : (E.category || '기타');

    const payload = {
        id:         E.id,
        event_type: 'anniversary',
        title,
        start_dt:   startDt,
        end_dt:     startDt,
        category:   cat,
        is_family:  E.is_family ?? 0,
        icon:       E.icon  || '⭐',
        color:      E.color || '#e74c3c',
        memo:       document.getElementById('e-memo').value,
        recur_rule: recurRule,
        is_allday:  1,
    };

    try {
        const res = await fetch('/schedule_api.php?module=calendar&action=update', {
            method:  'POST',
            headers: {'Content-Type':'application/json'},
            body:    JSON.stringify(payload),
        }).then(r => r.json());
        if (!res.ok) throw new Error(res.msg || '저장 실패');
        closeEditModal();
        await load();
    } catch(e) {
        alert('저장 중 오류: ' + e.message);
    }
}

async function deleteAnniv() {
    if (!confirm(`"${E.title}" 기념일을 삭제하시겠습니까?`)) return;
    try {
        const res = await fetch(`/schedule_api.php?module=calendar&action=delete&id=${E.id}`).then(r=>r.json());
        if (!res.ok) throw new Error(res.msg || '삭제 실패');
        closeEditModal();
        await load();
    } catch(e) {
        alert('삭제 중 오류: ' + e.message);
    }
}

load();
</script>
</body>
</html>
<?php
