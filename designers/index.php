<?php
/**
 * designers/index.php — 호텔 더 디자이너스 서울대 「오시는 길」 (공항버스 정류장 안내)
 *
 * 공개 페이지 — 로그인 없음(투숙객이 보는 자리). 네이버 Web Dynamic Map 으로
 * 호텔·승차 정류장·하차 정류장 셋을 마커로 찍는다(도보 경로 점선은 08-31 사용자 지시로 뺐다).
 *
 * - 지도 키는 env/maps.inc 의 NAVER_MAPS_CLIENT_ID (places.php·travel.php 와 같은 경로).
 *   키가 없거나 인증이 실패하면 지도 자리에 안내문 + 네이버 지도 외부 링크만 남긴다.
 * - ★좌표는 아래 $PTS 한 곳에서만 정한다(2026-08-31 사용자 실측 좌표).
 *   ①하차 낙성대동(21122) = 호텔과 «같은 편»(남측) 약 30m·도보 1분 · 횡단 불필요
 *   ②승차 낙성대입구(21751) = «길 건너»(북측) 동쪽 150m·도보 4분 + 횡단보도
 *   (표기는 2026-08-31 사용자 지시로 「호텔에서 약 30m, 도보 1분」처럼 심플하게 — 방위·가로변 설명 없음)
 *   ★PDF 안내도와 편이 반대다 — PDF 를 다시 보고 되돌리지 말 것.
 *   ?dev=1 로 열면 지도를 클릭할 때 그 자리의 좌표가 떠서 보정할 수 있다.
 * - ★번호 배지 색: ①도착(하차)=빨강 `--c1` · ②출발(승차)=파랑 `--c2` (2026-08-31 사용자 지시).
 *   마커·말풍선·카드 머리·범례가 `.c1/.c2` 한 클래스로 같은 색을 본다.
 * - ★언어는 ?lang=ko|en|zh|ja (기본 ko) — 문자열은 전부 $TR 번역표에서 온다. 지도 타일(네이버)은
 *   한국어 고정이라 마커 라벨·말풍선만 번역된다. PDF 는 언어별 access[_lang].pdf 로 따로 뽑는다.
 * - 운행 정보(시간·배차)는 2026-08-31 안내문 기준 — 바뀌면 $TR 의 해당 키를 네 언어 함께 고친다.
 */
$root = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
$clientId = '';
if (is_file($root . '/env/maps.inc')) {
    require_once $root . '/env/maps.inc';
    $clientId = defined('NAVER_MAPS_CLIENT_ID') ? (string)NAVER_MAPS_CLIENT_ID : '';
}
$dev   = !empty($_GET['dev']);
$print = !empty($_GET['print']);   // 인쇄용: 줌 버튼·드래그를 뺀다 (PDF 생성 시 ?print=1)

/* ── 번역표 (단일본 · 값은 신뢰하는 HTML) ───────────────────── */
$TR = [
'ko' => [
    'html_title'   => '오시는 길 — 호텔 더 디자이너스 서울대 · 공항버스 정류장 안내',
    'kicker'       => 'Airport Limousine Access',
    'title'        => '인천공항 리무진 버스(#6017) 이용 안내',
    'hotel'        => '호텔 더 디자이너스 서울대',
    'addr'         => '서울특별시 관악구 남부순환로 1876 (봉천동 1663-5)',
    'route'        => '인천공항 리무진 <strong>6017번</strong> (낙성대 ↔ 인천국제공항) — 호텔 앞 도보 1~4분',
    'map_title'    => '정류장 위치도',
    'map_hint'     => '마커를 누르면 정류장 정보가 열립니다',
    'pdf_link'     => 'PDF 한 장 내려받기',
    'bigmap'       => '네이버 지도에서 크게 보기 ↗',
    'fb_title'     => '지도를 불러오지 못했습니다.',
    'fb_link'      => '네이버 지도에서 위치 보기 ↗',
    'fb_note'      => '① 하차 「낙성대동」 — 호텔과 같은 편 약 30m · ② 승차 「낙성대입구」 — 길 건너 동쪽 150m',
    'lg_hotel'     => '호텔',
    'lg_stop'      => '공항버스 정류장 (①하차 ②승차)',
    'lg_note'      => '실제 지도 · 도보 거리는 안내문 기준',
    'card1'        => '공항에서 올 때 — 하차',
    'card2'        => '공항 갈 때 — 승차',
    'th_stop'      => '정류장',   'th_loc' => '위치',      'th_dep' => '공항 출발', 'th_int' => '배차',
    'th_hours'     => '운행시간', 'th_dur' => '소요시간',
    'stopno'       => '정류장번호',
    'stop1'        => '낙성대동',  'stop2' => '낙성대입구',
    'loc1'         => '호텔에서 <span class="em">약 30m</span>, 도보 1분',
    'loc2'         => '호텔에서 동쪽 <span class="em">약 150m</span>, 도보 4분',
    'dep1'         => '06:29 ~ 22:49',  'dep1_sub' => 'T1 지하 1층 26번 승강장',
    'interval'     => '약 25~35분 간격',
    'dur1'         => '인천공항에서 약 70~85분',
    'hours2'       => '04:35 ~ 20:05',  'hours2_sub' => '기점(호암교수회관) 04:30~20:00 기준',
    'dur2'         => '인천공항까지 약 70~85분',
    'n_gimpo'      => '<b>김포공항</b> — 6017번은 인천공항 전용 노선입니다. 김포공항은 서울대입구역(2호선) → 당산역 → 9호선 급행 환승이 가장 빠릅니다.',
    'n_contact'    => '<b>문의</b> — ㈜공항리무진 주간 02-2664-9898 / 야간 02-2665-1094',
    'asof'         => '2026. 8. 31. 기준',  'foot_var' => '운행시간 변동 가능',
    // 지도 위 (JS)
    'iw_stop'      => '정류장',
    'dir1'         => '인천공항 → 시내 하차 (호텔과 같은 편)',  'dist1' => '호텔에서 약 30m · 도보 1분',
    'dir2'         => '공항 방면 승차 (길 건너편)',             'dist2' => '호텔에서 동쪽 약 150m · 도보 4분',
    'iw_hotel'     => '① 하차 정류장 약 30m · ② 승차 정류장 길 건너 동쪽 150m',
    'tm1'          => '공항 출발 <b>06:29 ~ 22:49</b> · T1 지하 1층 26번 승강장',
    'tm2'          => '운행 <b>04:35 ~ 20:05</b> · 배차 25~35분 · 인천공항 70~85분',
],
'en' => [
    'html_title'   => 'Directions — Hotel The Designers SNU · Airport Bus Stops',
    'kicker'       => 'Airport Limousine Access',
    'title'        => 'Incheon Airport Limousine Bus (#6017) Guide',
    'hotel'        => 'Hotel The Designers SNU',
    'addr'         => '1876 Nambusunhwan-ro, Gwanak-gu, Seoul (1663-5 Bongcheon-dong)',
    'route'        => 'Incheon Airport Limousine <strong>Bus 6017</strong> (Nakseongdae ↔ Incheon Int’l Airport) — 1–4 min walk from the hotel',
    'map_title'    => 'Bus Stop Locations',
    'map_hint'     => 'Tap a marker for stop details',
    'pdf_link'     => 'Download 1-page PDF',
    'bigmap'       => 'Open in Naver Map ↗',
    'fb_title'     => 'The map could not be loaded.',
    'fb_link'      => 'View location on Naver Map ↗',
    'fb_note'      => '① Drop-off “Nakseongdae-dong” — same side as the hotel, approx. 30 m · ② Boarding “Nakseongdae Ipgu” — across the road, approx. 150 m east',
    'lg_hotel'     => 'Hotel',
    'lg_stop'      => 'Airport bus stops (① drop-off ② boarding)',
    'lg_note'      => 'Actual map · walking distances per notice',
    'card1'        => 'Arriving from the airport — Drop-off',
    'card2'        => 'Going to the airport — Boarding',
    'th_stop'      => 'Stop',     'th_loc' => 'Location',  'th_dep' => 'Departs airport', 'th_int' => 'Frequency',
    'th_hours'     => 'Hours',    'th_dur' => 'Travel time',
    'stopno'       => 'Stop No.',
    'stop1'        => 'Nakseongdae-dong',  'stop2' => 'Nakseongdae Ipgu',
    'loc1'         => 'Approx. <span class="em">30 m</span> from the hotel, 1 min walk',
    'loc2'         => 'Approx. <span class="em">150 m</span> east of the hotel, 4 min walk',
    'dep1'         => '06:29 – 22:49',  'dep1_sub' => 'Terminal 1, Basement 1, Bus Stop 26',
    'interval'     => 'Every 25–35 min',
    'dur1'         => 'Approx. 70–85 min from Incheon Airport',
    'hours2'       => '04:35 – 20:05',  'hours2_sub' => 'Based on first stop (Hoam Faculty House) 04:30–20:00',
    'dur2'         => 'Approx. 70–85 min to Incheon Airport',
    'n_gimpo'      => '<b>Gimpo Airport</b> — Bus 6017 serves Incheon Airport only. For Gimpo, the fastest way is Seoul Nat’l Univ. Station (Line 2) → Dangsan → Line 9 Express.',
    'n_contact'    => '<b>Contact</b> — Airport Limousine Co. Daytime 02-2664-9898 / Night 02-2665-1094',
    'asof'         => 'As of Aug 31, 2026',  'foot_var' => 'Schedules subject to change',
    'iw_stop'      => 'Stop',
    'dir1'         => 'Incheon Airport → City drop-off (same side as hotel)',  'dist1' => 'Approx. 30 m from the hotel · 1 min walk',
    'dir2'         => 'Boarding for the airport (across the road)',           'dist2' => 'Approx. 150 m east of the hotel · 4 min walk',
    'iw_hotel'     => '① Drop-off stop approx. 30 m · ② Boarding stop across the road, 150 m east',
    'tm1'          => 'Departs airport <b>06:29 – 22:49</b> · T1 B1 Bus Stop 26',
    'tm2'          => 'Runs <b>04:35 – 20:05</b> · every 25–35 min · 70–85 min to Incheon Airport',
],
'zh' => [
    'html_title'   => '交通指南 — Hotel The Designers 首尔大 · 机场巴士站',
    'kicker'       => 'Airport Limousine Access',
    'title'        => '仁川机场机场大巴（#6017）乘车指南',
    'hotel'        => 'Hotel The Designers 首尔大',
    'addr'         => '首尔特别市冠岳区南部循环路1876（奉天洞1663-5）',
    'route'        => '仁川机场机场大巴 <strong>6017路</strong>（落星垈 ↔ 仁川国际机场）— 从酒店步行1~4分钟',
    'map_title'    => '巴士站位置图',
    'map_hint'     => '点击标记查看站点信息',
    'pdf_link'     => '下载PDF（1页）',
    'bigmap'       => '在Naver地图中查看 ↗',
    'fb_title'     => '地图加载失败。',
    'fb_link'      => '在Naver地图中查看位置 ↗',
    'fb_note'      => '① 下车站「落星垈洞」— 与酒店同侧，约30米 · ② 乘车站「落星垈入口」— 马路对面，向东约150米',
    'lg_hotel'     => '酒店',
    'lg_stop'      => '机场巴士站（①下车 ②乘车）',
    'lg_note'      => '实际地图 · 步行距离以指南为准',
    'card1'        => '从机场抵达 — 下车',
    'card2'        => '前往机场 — 乘车',
    'th_stop'      => '站点',     'th_loc' => '位置',      'th_dep' => '机场发车', 'th_int' => '发车间隔',
    'th_hours'     => '运行时间', 'th_dur' => '所需时间',
    'stopno'       => '站点编号',
    'stop1'        => '落星垈洞',  'stop2' => '落星垈入口',
    'loc1'         => '距酒店约<span class="em">30米</span>，步行1分钟',
    'loc2'         => '酒店以东约<span class="em">150米</span>，步行4分钟',
    'dep1'         => '06:29 ~ 22:49',  'dep1_sub' => 'T1航站楼 地下1层 26号站台',
    'interval'     => '约25~35分钟一班',
    'dur1'         => '从仁川机场约70~85分钟',
    'hours2'       => '04:35 ~ 20:05',  'hours2_sub' => '以始发站（湖岩教授会馆）04:30~20:00为准',
    'dur2'         => '至仁川机场约70~85分钟',
    'n_gimpo'      => '<b>金浦机场</b> — 6017路仅往返仁川机场。前往金浦机场，最快的方式是首尔大入口站（2号线）→ 堂山站 → 换乘9号线快速列车。',
    'n_contact'    => '<b>咨询</b> — ㈜机场大巴 白天 02-2664-9898 / 夜间 02-2665-1094',
    'asof'         => '2026年8月31日 基准',  'foot_var' => '运行时间可能变动',
    'iw_stop'      => '站点',
    'dir1'         => '仁川机场 → 市区 下车（与酒店同侧）',  'dist1' => '距酒店约30米 · 步行1分钟',
    'dir2'         => '前往机场 乘车（马路对面）',           'dist2' => '酒店以东约150米 · 步行4分钟',
    'iw_hotel'     => '① 下车站约30米 · ② 乘车站在马路对面，向东150米',
    'tm1'          => '机场发车 <b>06:29 ~ 22:49</b> · T1地下1层26号站台',
    'tm2'          => '运行 <b>04:35 ~ 20:05</b> · 25~35分钟一班 · 至仁川机场70~85分钟',
],
'ja' => [
    'html_title'   => 'アクセス — ホテル ザ デザイナーズ ソウル大 · 空港バス停',
    'kicker'       => 'Airport Limousine Access',
    'title'        => '仁川空港リムジンバス（#6017）ご利用案内',
    'hotel'        => 'ホテル ザ デザイナーズ ソウル大',
    'addr'         => 'ソウル特別市冠岳区南部循環路1876（奉天洞1663-5）',
    'route'        => '仁川空港リムジンバス <strong>6017番</strong>（落星台 ↔ 仁川国際空港）— ホテルから徒歩1~4分',
    'map_title'    => 'バス停位置図',
    'map_hint'     => 'マーカーをタップするとバス停情報が開きます',
    'pdf_link'     => 'PDF（1枚）をダウンロード',
    'bigmap'       => 'NAVERマップで開く ↗',
    'fb_title'     => '地図を読み込めませんでした。',
    'fb_link'      => 'NAVERマップで位置を見る ↗',
    'fb_note'      => '① 降車「落星台洞」— ホテルと同じ側、約30m · ② 乗車「落星台入口」— 道路の向かい側、東へ約150m',
    'lg_hotel'     => 'ホテル',
    'lg_stop'      => '空港バス停（①降車 ②乗車）',
    'lg_note'      => '実際の地図 · 徒歩距離は案内基準',
    'card1'        => '空港から到着 — 降車',
    'card2'        => '空港へ — 乗車',
    'th_stop'      => 'バス停',   'th_loc' => '位置',      'th_dep' => '空港発',   'th_int' => '運行間隔',
    'th_hours'     => '運行時間', 'th_dur' => '所要時間',
    'stopno'       => '停留所番号',
    'stop1'        => '落星台洞',  'stop2' => '落星台入口',
    'loc1'         => 'ホテルから<span class="em">約30m</span>、徒歩1分',
    'loc2'         => 'ホテルから東へ<span class="em">約150m</span>、徒歩4分',
    'dep1'         => '06:29 ~ 22:49',  'dep1_sub' => '第1ターミナル 地下1階 26番乗り場',
    'interval'     => '約25~35分間隔',
    'dur1'         => '仁川空港から約70~85分',
    'hours2'       => '04:35 ~ 20:05',  'hours2_sub' => '始発（湖巌教授会館）04:30~20:00 基準',
    'dur2'         => '仁川空港まで約70~85分',
    'n_gimpo'      => '<b>金浦空港</b> — 6017番は仁川空港専用路線です。金浦空港へはソウル大入口駅（2号線）→ 堂山駅 → 9号線急行への乗り換えが最速です。',
    'n_contact'    => '<b>お問い合わせ</b> — ㈱空港リムジン 昼間 02-2664-9898 / 夜間 02-2665-1094',
    'asof'         => '2026年8月31日 現在',  'foot_var' => '運行時間は変更される場合があります',
    'iw_stop'      => '停留所',
    'dir1'         => '仁川空港 → 市内 降車（ホテルと同じ側）',  'dist1' => 'ホテルから約30m · 徒歩1分',
    'dir2'         => '空港方面 乗車（道路の向かい側）',         'dist2' => 'ホテルから東へ約150m · 徒歩4分',
    'iw_hotel'     => '① 降車バス停 約30m · ② 乗車バス停 道路の向かい側 東へ150m',
    'tm1'          => '空港発 <b>06:29 ~ 22:49</b> · T1 地下1階 26番乗り場',
    'tm2'          => '運行 <b>04:35 ~ 20:05</b> · 25~35分間隔 · 仁川空港まで70~85分',
],
];
/* 기본 언어는 영문(2026-08-31 사용자 「기본을 영문으로」) · 호텔 영문 공식 표기 Hotel The Designers SNU(명함) */
$LANGS = ['en' => 'English', 'ko' => '한국어', 'zh' => '中文', 'ja' => '日本語'];
$L = isset($_GET['lang']) && isset($TR[$_GET['lang']]) ? $_GET['lang'] : 'en';
$T = $TR[$L];
$pdfFile = $L === 'ko' ? 'access.pdf' : "access_{$L}.pdf";

/* ── 지점 (단일본) ─────────────────────────────────────────── */
$PTS = [
    // 남부순환로 남측(짝수번지 측)
    'hotel' => ['lat' => 37.478992, 'lng' => 126.957756, 'name' => $T['hotel'], 'sub' => $T['addr']],
    // ① 하차 — 인천공항 → 시내. 남부순환로 남측 가로변 · ★호텔과 같은 편 · 횡단 불필요
    'stop1' => ['lat' => 37.479313, 'lng' => 126.957394, 'no' => 1,
                'name' => $T['stop1'], 'stopNo' => '21122', 'dir' => $T['dir1'], 'dist' => $T['dist1']],
    // ② 승차 — 공항 방면. 남부순환로 북측 가로변 · ★길 건너편 · 횡단보도 이용
    'stop2' => ['lat' => 37.478904, 'lng' => 126.959477, 'no' => 2,
                'name' => $T['stop2'], 'stopNo' => '21751', 'dir' => $T['dir2'], 'dist' => $T['dist2']],
];
/* 중심은 세 지점의 가운데 · zoom 18 (2026-08-31 사용자 「너무 안 보임」 — 17 에서 한 단계 확대. 17 의 중심 37.478915,126.957947 로 두면 ② 라벨이 오른쪽에 걸린다) */
$CENTER = ['lat' => 37.479100, 'lng' => 126.958400, 'zoom' => 18];

/* ★도보 경로 점선·「횡단보도」 라벨은 2026-08-31 사용자 지시로 삭제했다 — 마커 셋만 찍는다. 되살리지 말 것. */

$naverBig = 'https://map.naver.com/v5/?c=' . $CENTER['lng'] . ',' . $CENTER['lat'] . ',' . $CENTER['zoom'] . ',0,0,0,dh';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$langUrl = function (string $lang) use ($print, $dev): string {
    $q = ['lang' => $lang];
    if ($print) $q['print'] = 1;
    if ($dev)   $q['dev'] = 1;
    return './?' . http_build_query($q);
};
?>
<!DOCTYPE html>
<html lang="<?= $h($L) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($T['html_title']) ?></title>
<style>
:root{--navy:#1B2A4A;--navy2:#2E3F63;--bronze:#B08D57;--bronze2:#8F7040;--ink:#22303F;--mute:#6B7480;--line:#E3E7EE;--bg:#F7F8FA;--card:#fff;
  /* 번호 배지 색 — ①도착(하차)=빨강 · ②출발(승차)=파랑 (2026-08-31 사용자 지시) · 마커·말풍선·카드·범례가 같은 변수를 본다 */
  --c1:#C8372D;--c2:#2B6CB0}
.c1{--cn:var(--c1)} .c2{--cn:var(--c2)}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font-family:"Pretendard","Apple SD Gothic Neo","Malgun Gothic","Noto Sans KR",system-ui,sans-serif;line-height:1.55;-webkit-font-smoothing:antialiased}
html[lang=en] body{font-family:"Segoe UI","Helvetica Neue",Arial,system-ui,sans-serif}
html[lang=zh] body{font-family:"Microsoft YaHei","PingFang SC","Noto Sans SC","Malgun Gothic",sans-serif}
html[lang=ja] body{font-family:"Yu Gothic UI","Yu Gothic","Meiryo","Hiragino Sans","Noto Sans JP",sans-serif}
a{color:inherit}
.wrap{max-width:920px;margin:0 auto;padding:40px 20px 56px}
/* ★인쇄 모드(?print=1)는 화면에서도 인쇄 폭(A4 210−20mm)으로 고정 — 지도를 화면 폭으로 깔았다가 인쇄 폭으로 줄이면
   네이버 지도가 왼쪽 가장자리를 기준으로 남겨 오른쪽이 잘린다(2026-08-31 실측). 리사이즈 자체를 없앤다. */
body.print .wrap{max-width:190mm;padding-top:0}
.top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.kicker{font-size:11px;letter-spacing:.32em;color:var(--bronze);font-weight:600;text-transform:uppercase}
.langs{display:flex;gap:4px;font-size:12px;margin-top:-4px}
.langs a{text-decoration:none;color:var(--mute);padding:4px 9px;border-radius:999px;border:1px solid transparent}
.langs a:hover{border-color:var(--line);background:#fff}
.langs a.on{color:#fff;background:var(--navy);border-color:var(--navy)}
h1{margin:10px 0 12px;font-size:30px;line-height:1.2;color:var(--navy);letter-spacing:-.01em}
.lead{margin:0;color:var(--mute);font-size:15px}
.lead b{color:var(--ink);font-weight:600}
.lead.route{margin-top:4px}
.lead.route strong{color:var(--navy);font-weight:700}

/* 지도 패널 */
.panel{margin-top:28px;border-radius:6px;overflow:hidden;box-shadow:0 1px 2px rgba(27,42,74,.08),0 8px 24px rgba(27,42,74,.08);background:var(--card)}
.panel-hd{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;background:var(--navy);color:#fff}
.panel-hd h2{margin:0;font-size:15px;font-weight:700;letter-spacing:.02em}
.panel-hd .r{display:flex;align-items:center;gap:14px;font-size:12px;color:#c9d1e0}
.panel-hd .r a{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.35);padding:4px 10px;border-radius:999px;white-space:nowrap}
.panel-hd .r a:hover{background:rgba(255,255,255,.1)}
#map{width:100%;height:460px;background:#e9edf3;position:relative}
#map-fallback{display:none;position:absolute;inset:0;place-items:center;text-align:center;padding:24px;color:var(--mute);font-size:14px;background:#eef1f6;z-index:5}
#map-fallback.on{display:grid}
#map-fallback a{color:var(--navy);font-weight:700}
.legend{display:flex;flex-wrap:wrap;gap:8px 20px;padding:10px 18px;border-top:1px solid var(--line);font-size:12.5px;color:var(--mute);background:#fbfbfd}
.legend span{display:inline-flex;align-items:center;gap:7px}
.legend i{display:inline-block}
.legend .dot{width:12px;height:12px;border-radius:50%;background:var(--cn,var(--bronze));border:2px solid #fff;box-shadow:0 0 0 1px var(--cn,var(--bronze))}
.legend .dot+.dot{margin-left:-3px}
.legend .hot{width:14px;height:14px;border-radius:3px;background:var(--navy) url(./logo.svg?v=2) center/10px 10px no-repeat;border:2px solid #fff;box-shadow:0 0 0 1px var(--navy)}
.legend .note{margin-left:auto;font-size:11.5px}

/* 지도 위 마커·말풍선 */
/* 마커 크기 — 2026-08-31 사용자 「150% 키워줘」: 호텔 36→54px · 정류장 30→45px (JS 의 size 상수와 함께 간다) */
/* 호텔 마커 = 네이비 바탕 + 금색 로고(logo.svg · 2026-08-31 사용자 「H 대신 로고」) · 이름 라벨은 마커 «아래» */
.mk-hotel{position:relative;width:54px;height:54px;border-radius:14px;background:var(--navy);border:4px solid #fff;box-shadow:0 3px 14px rgba(27,42,74,.45);display:grid;place-items:center}
.mk-hotel img{width:38px;height:38px;display:block}
.mk-hotel::after{content:"";position:absolute;left:50%;bottom:-16px;width:18px;height:18px;background:var(--navy);transform:translateX(-50%) rotate(45deg);border-right:4px solid #fff;border-bottom:4px solid #fff;border-radius:3px}
.mk-hotel .lbl,.mk-stop .lbl{position:absolute;top:50%;left:calc(100% + 14px);transform:translateY(-50%);white-space:nowrap;background:#fff;color:var(--navy);font-size:15px;font-weight:700;padding:4px 10px;border-radius:5px;box-shadow:0 1px 5px rgba(0,0,0,.22);font-family:inherit;pointer-events:none}
.mk-hotel .lbl{top:calc(100% + 22px);left:50%;transform:translateX(-50%)}
.mk-stop.c2 .lbl{left:auto;right:calc(100% + 14px)}   /* ②는 지도 오른쪽 끝이라 라벨을 왼쪽에 (영문이 길어 잘렸다) */
.mk-stop{position:relative;width:45px;height:45px;border-radius:50%;background:var(--cn,var(--bronze));border:4px solid #fff;box-shadow:0 3px 12px rgba(0,0,0,.35);display:grid;place-items:center;color:#fff;font-size:21px;font-weight:800;font-family:inherit}
.mk-stop::after{content:"";position:absolute;left:50%;bottom:-13px;width:13px;height:13px;background:var(--cn,var(--bronze));transform:translateX(-50%) rotate(45deg);border-right:4px solid #fff;border-bottom:4px solid #fff}
.mk-stop .lbl{color:var(--cn,var(--bronze2))}
.iw{position:relative;min-width:230px;max-width:280px;background:#fff;border-radius:8px;box-shadow:0 6px 22px rgba(27,42,74,.28);font-family:inherit}
.iw::after{content:"";position:absolute;left:50%;bottom:-7px;width:14px;height:14px;background:#fff;transform:translateX(-50%) rotate(45deg);box-shadow:3px 3px 6px rgba(27,42,74,.12)}
.iw .hd{display:flex;align-items:center;gap:8px;padding:9px 12px;background:var(--navy);color:#fff;font-weight:700;font-size:14px;border-radius:8px 8px 0 0}
.iw .hd .n{flex:none;width:20px;height:20px;border-radius:50%;background:var(--cn,var(--bronze));display:grid;place-items:center;font-size:12px}
.iw .hd .n.h{border-radius:5px;background:var(--navy2)}
.iw .hd .n.h img{width:16px;height:16px;display:block}
.iw .hd small{margin-left:auto;font-weight:500;font-size:11px;color:#c9d1e0;white-space:nowrap}
.iw .bd{padding:10px 12px 11px;font-size:13px;line-height:1.5;background:#fff;border-radius:0 0 8px 8px}
.iw .bd .dir{color:var(--ink);font-weight:600}
.iw .bd .dist{color:var(--bronze2);margin-top:3px}
.iw .bd .sub{color:var(--mute);margin-top:3px;font-size:12px}
.iw .bd .tm{margin-top:7px;padding-top:7px;border-top:1px dashed var(--line);color:var(--mute);font-size:12px}
.iw .bd .tm b{color:var(--ink)}

/* 카드 둘 */
.cards{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:28px}
.card{background:var(--card);border-radius:6px;overflow:hidden;box-shadow:0 1px 2px rgba(27,42,74,.06),0 4px 14px rgba(27,42,74,.06)}
.card .hd{display:flex;align-items:center;gap:10px;padding:12px 16px;color:#fff;font-weight:700;font-size:15px;background:var(--navy)}
.card.dk .hd{background:var(--navy2)}
.card .hd .n{flex:none;width:24px;height:24px;border-radius:50%;background:var(--cn,var(--bronze));display:grid;place-items:center;font-size:13px}
.card table{width:100%;border-collapse:collapse;font-size:14px}
.card th,.card td{padding:11px 16px;border-top:1px solid var(--line);vertical-align:top;text-align:left}
.card tr:first-child th,.card tr:first-child td{border-top:0}
.card th{width:84px;color:var(--mute);font-weight:500;font-size:13px;white-space:nowrap}
html[lang=en] .card th{width:118px;white-space:normal}
.card td{word-break:keep-all}
.card td b{font-weight:700}
.card td .em{color:var(--bronze2);font-weight:700}
.card td small{display:block;color:var(--mute);font-size:12px;margin-top:2px}

/* 안내문 */
.notes{margin:26px 0 0;padding:20px 0 0;border-top:1px solid var(--line);list-style:none}
.notes li{position:relative;padding-left:16px;margin:0 0 8px;font-size:13.5px;color:var(--ink)}
.notes li::before{content:"";position:absolute;left:0;top:9px;width:6px;height:6px;border-radius:50%;background:var(--bronze)}
.notes b{color:var(--navy)}
.foot{display:flex;justify-content:space-between;gap:12px;margin-top:40px;padding-top:14px;border-top:1px solid var(--line);font-size:11.5px;color:var(--mute)}

#devpos{display:none;position:fixed;left:12px;bottom:12px;z-index:9;background:rgba(27,42,74,.92);color:#fff;font:12px/1.4 ui-monospace,Consolas,monospace;padding:8px 10px;border-radius:6px}
#devpos.on{display:block}

@media (max-width:720px){
  .wrap{padding:26px 14px 40px}
  h1{font-size:24px}
  #map{height:380px}
  .cards{grid-template-columns:1fr}
  .panel-hd .r span{display:none}
  .legend .note{margin-left:0;flex-basis:100%}
  .foot{flex-direction:column;gap:4px}
}
/* 인쇄 = A4 세로 한 장 (헤드리스 Chrome --print-to-pdf 로 뽑는다 · 지도 타일은 이미지라 그대로 실린다) */
@media print{
  @page{size:A4 portrait;margin:9mm 10mm}
  *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  html,body{background:#fff}
  .wrap{max-width:none;padding:0}
  .langs{display:none!important}
  .kicker{font-size:10px}
  h1{font-size:24px;margin:6px 0 8px}
  .lead{font-size:13px}
  .panel{margin-top:14px;box-shadow:none;border:1px solid var(--line);break-inside:avoid}
  .panel-hd{padding:8px 14px}
  .panel-hd .r a,.panel-hd .r span,#devpos{display:none!important}
  #map{height:370px}
  #map .map_control,#map [class*="control"]{display:none!important}
  .legend{padding:7px 14px;font-size:11px}
  .cards{margin-top:14px;gap:12px}
  .card{box-shadow:none;border:1px solid var(--line);break-inside:avoid}
  .card .hd{padding:8px 14px;font-size:13.5px}
  .card th,.card td{padding:6px 14px;font-size:12.5px}
  .card th{font-size:11.5px}
  .card td small{font-size:11px}
  .notes{margin-top:12px;padding-top:10px}
  .notes li{font-size:11.5px;margin-bottom:4px}
  .foot{margin-top:12px;padding-top:8px;font-size:10.5px}
}
</style>
</head>
<body class="<?= $print ? 'print' : '' ?>">
<div class="wrap">
  <div class="top">
    <div class="kicker"><?= $T['kicker'] ?></div>
    <nav class="langs" aria-label="language">
      <?php foreach ($LANGS as $k => $label): ?><a href="<?= $h($langUrl($k)) ?>" class="<?= $k === $L ? 'on' : '' ?>" lang="<?= $k ?>"><?= $h($label) ?></a><?php endforeach; ?>
    </nav>
  </div>
  <h1><?= $T['title'] ?></h1>
  <p class="lead"><b><?= $h($T['hotel']) ?></b> &nbsp;|&nbsp; <?= $h($T['addr']) ?></p>
  <p class="lead route"><?= $T['route'] ?></p>

  <div class="panel">
    <div class="panel-hd">
      <h2><?= $T['map_title'] ?></h2>
      <div class="r"><span><?= $T['map_hint'] ?></span>
        <a href="./<?= $h($pdfFile) ?>" target="_blank" rel="noopener"><?= $T['pdf_link'] ?></a>
        <a href="<?= $h($naverBig) ?>" target="_blank" rel="noopener"><?= $T['bigmap'] ?></a></div>
    </div>
    <div id="map">
      <div id="map-fallback">
        <div><?= $T['fb_title'] ?><br>
          <a href="<?= $h($naverBig) ?>" target="_blank" rel="noopener"><?= $T['fb_link'] ?></a><br>
          <small><?= $T['fb_note'] ?></small></div>
      </div>
    </div>
    <div class="legend">
      <span><i class="hot"></i><?= $T['lg_hotel'] ?></span>
      <span><i class="dot c1"></i><i class="dot c2"></i><?= $T['lg_stop'] ?></span>
      <span class="note"><?= $T['lg_note'] ?></span>
    </div>
  </div>

  <div class="cards">
    <div class="card">
      <div class="hd"><span class="n c1">1</span><?= $T['card1'] ?></div>
      <table>
        <tr><th><?= $T['th_stop'] ?></th><td><b><?= $h($T['stop1']) ?></b> (<?= $T['stopno'] ?> 21122)</td></tr>
        <tr><th><?= $T['th_loc'] ?></th><td><?= $T['loc1'] ?></td></tr>
        <tr><th><?= $T['th_dep'] ?></th><td><span class="em"><?= $T['dep1'] ?></span><small><?= $T['dep1_sub'] ?></small></td></tr>
        <tr><th><?= $T['th_int'] ?></th><td><b><?= $T['interval'] ?></b></td></tr>
        <tr><th><?= $T['th_dur'] ?></th><td><b><?= $T['dur1'] ?></b></td></tr>
      </table>
    </div>
    <div class="card dk">
      <div class="hd"><span class="n c2">2</span><?= $T['card2'] ?></div>
      <table>
        <tr><th><?= $T['th_stop'] ?></th><td><b><?= $h($T['stop2']) ?></b> (<?= $T['stopno'] ?> 21751)</td></tr>
        <tr><th><?= $T['th_loc'] ?></th><td><?= $T['loc2'] ?></td></tr>
        <tr><th><?= $T['th_hours'] ?></th><td><span class="em"><?= $T['hours2'] ?></span><small><?= $T['hours2_sub'] ?></small></td></tr>
        <tr><th><?= $T['th_int'] ?></th><td><b><?= $T['interval'] ?></b></td></tr>
        <tr><th><?= $T['th_dur'] ?></th><td><b><?= $T['dur2'] ?></b></td></tr>
      </table>
    </div>
  </div>

  <ul class="notes">
    <li><?= $T['n_gimpo'] ?></li>
    <li><?= $T['n_contact'] ?></li>
  </ul>

  <div class="foot">
    <span><?= $h($T['hotel']) ?> · <?= $h($T['addr']) ?></span>
    <span><?= $h($T['asof']) ?> · <?= $h($T['foot_var']) ?></span>
  </div>
</div>
<div id="devpos"></div>

<script>
var NAVER_KEY = <?= json_encode($clientId) ?>;
var PTS    = <?= json_encode($PTS, JSON_UNESCAPED_UNICODE) ?>;
var CENTER = <?= json_encode($CENTER) ?>;
var T      = <?= json_encode(['iw_stop' => $T['iw_stop'], 'iw_hotel' => $T['iw_hotel'], 'tm1' => $T['tm1'], 'tm2' => $T['tm2']], JSON_UNESCAPED_UNICODE) ?>;
var DEV    = <?= $dev ? 'true' : 'false' ?>;
var PRINT  = <?= $print ? 'true' : 'false' ?>;
var NAVY = '#1B2A4A', BRONZE = '#B08D57';

function showFallback(){ document.getElementById('map-fallback').classList.add('on'); }
/* 네이버 SDK 는 키/도메인 인증에 실패하면 이 전역 함수를 부른다 */
window.navermap_authFailure = function(){ showFallback(); };

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function iwHtml(key, p){
  var isHotel = key === 'hotel';
  var badge = isHotel ? '<span class="n h"><img src="./logo.svg?v=2" alt=""></span>' : '<span class="n c' + p.no + '">' + p.no + '</span>';
  var h = '<div class="iw"><div class="hd">' + badge + esc(p.name)
        + (p.stopNo ? '<small>' + esc(T.iw_stop) + ' ' + esc(p.stopNo) + '</small>' : '') + '</div><div class="bd">';
  if (isHotel){
    h += '<div class="dir">' + esc(p.sub) + '</div><div class="sub">' + T.iw_hotel + '</div>';
  } else {
    h += '<div class="dir">' + esc(p.dir) + '</div><div class="dist">' + esc(p.dist) + '</div>';
    h += '<div class="tm">' + (key === 'stop1' ? T.tm1 : T.tm2) + '</div>';
  }
  return h + '</div></div>';
}

function initMap(){
  var N = naver.maps;
  var map = new N.Map('map', {
    center: new N.LatLng(CENTER.lat, CENTER.lng),
    zoom: CENTER.zoom, minZoom: 14,
    zoomControl: !PRINT, zoomControlOptions: { position: N.Position.TOP_RIGHT, style: N.ZoomControlStyle.SMALL },
    draggable: !PRINT, scrollWheel: !PRINT,
    mapTypeControl: false, scaleControl: true, logoControlOptions: { position: N.Position.BOTTOM_LEFT }
  });

  /* 마커 셋 — 호텔은 강조(네이비 사각), 정류장은 번호(브론즈 원) */
  var markers = {}, windows = {}, openKey = null;
  function closeAll(){ Object.keys(windows).forEach(function(k){ windows[k].close(); }); openKey = null; }

  Object.keys(PTS).forEach(function(key){
    var p = PTS[key], isHotel = key === 'hotel';
    var content = isHotel
      ? '<div class="mk-hotel"><img src="./logo.svg?v=2" alt=""><span class="lbl">' + esc(p.name) + '</span></div>'
      : '<div class="mk-stop c' + p.no + '">' + p.no + '<span class="lbl">' + esc(p.name) + '</span></div>';
    var size = isHotel ? 54 : 45, tip = isHotel ? 16 : 13;   // CSS .mk-hotel/.mk-stop 와 같은 값
    var mk = new N.Marker({
      map: map, position: new N.LatLng(p.lat, p.lng), title: p.name,
      icon: { content: content, size: new N.Size(size, size), anchor: new N.Point(size / 2, size + tip) },
      zIndex: isHotel ? 200 : 100
    });
    var iw = new N.InfoWindow({
      content: iwHtml(key, p), borderWidth: 0, backgroundColor: 'transparent',
      disableAnchor: true, anchorSize: new N.Size(0, 0),
      pixelOffset: new N.Point(0, -(size + tip + 4))
    });
    markers[key] = mk; windows[key] = iw;
    N.Event.addListener(mk, 'click', function(){
      if (openKey === key){ closeAll(); return; }
      closeAll(); iw.open(map, mk); openKey = key;
    });
  });
  N.Event.addListener(map, 'click', function(e){
    closeAll();
    if (DEV){
      var el = document.getElementById('devpos');
      el.textContent = e.coord.lat().toFixed(6) + ', ' + e.coord.lng().toFixed(6);
      el.classList.add('on');
    }
  });

  /* ★말풍선은 마커를 눌렀을 때만 연다 — 처음부터 호텔 말풍선을 띄우던 것은 2026-08-31 사용자 지시로 삭제(지도를 가렸다) */
}

(function(){
  if (!NAVER_KEY){ showFallback(); return; }
  var s = document.createElement('script');
  s.src = 'https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=' + encodeURIComponent(NAVER_KEY);
  s.onload = function(){ if (window.naver && naver.maps) initMap(); else showFallback(); };
  s.onerror = showFallback;
  document.head.appendChild(s);
})();
</script>
</body>
</html>
<?php
?>
