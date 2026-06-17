<?php
require_once "./env/cnt.inc";

require_once "./env/auth_fnc.php";
require_login();
require_once "./classes/UI_Helper.class"; // UI 개선 (정렬)
require_once "./env/keyword_news.inc";    // highlight_keyword(), get_keyword_news_by_naver() — 공개 뷰어와 공유

# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 0 );
 ini_set("allow_url_fopen",1);

#변수정의
define('CUR_PHP', basename($_SERVER['PHP_SELF']));

// 1. 변수 안전하게 받기 (중복 선언 제거)
$mode = $_REQUEST["mode"] ?? ''; 

if ($mode !== 'api_find') {

?>

			
<!-- openCommonFrames 등 공통 함수는 style/economist_claude.js 에 정의 -->
<script src='/style/economist_claude.js'></script>

			
<style>
    /* 1. 기본 행 배경색은 무조건 '흰색'으로 강제 고정 */
    .title-board-table tbody tr { 
        background-color: #ffffff; 
        transition: background-color 0.2s ease-in-out; 
    }

    /* 2. 클릭된 행의 하이라이트 색상 (살짝 주황빛) */
    .title-board-table tbody tr.row-highlight { 
        background-color: #fff4ce !important; 
    }

    /* 3. 마우스 오버 효과 (마우스를 올린 '그 줄'만 노란색으로!) */
    .title-board-table tbody tr:not(.row-highlight):hover { 
        background-color: #ffff99 !important; 
    }

    /* --- 아래는 기존 타이틀 고정(Sticky) CSS 그대로 유지 --- */
    .sticky-title {
        background-color: #ffffff; 
        z-index: 12; 
        padding: 20px 0 10px 0; 
        margin: 0; 
    }

.sort-header-link {
        display: inline-flex;
        align-items: center;
        color: #4a5568;
        text-decoration: none;
        padding: 4px 8px;
        border-radius: 6px;
        transition: all 0.2s;
    }
    .sort-header-link:hover {
        background-color: #edf2f7; /* 마우스 올리면 연한 회색 배경 */
        color: #2b6cb0; /* 글자는 파란색으로 강조 */
    }
    .sort-header-link:hover span {
        color: #2b6cb0 !important; /* 아이콘 색상도 같이 변경 */
    }


.top-status-bar {
        background-color: #212529; /* 다크 모드 스타일의 세련된 배경 */
        color: #ffffff;
        padding: 10px 20px;
        display: flex;
        justify-content: flex-end; /* 오른쪽 정렬 */
        align-items: center;
        font-family: 'Malgun Gothic', sans-serif;
        font-size: 14px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 20px; /* 아래쪽 컨텐츠와의 여백 */
    }
    .top-status-bar .user-info {
        margin-right: 20px;
    }
    .top-status-bar .user-name {
        font-weight: bold;
        color: #ffc107; /* 눈에 띄는 포인트 컬러 (노란색) */
    }
    .top-status-bar .expire-time {
        color: #adb5bd; /* 덜 튀는 회색 */
        font-size: 12px;
        margin-left: 10px;
        letter-spacing: 0.5px;
    }
    .btn-logout {
        background-color: #dc3545;
        color: white;
        text-decoration: none;
        padding: 6px 14px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
        transition: background-color 0.2s;
    }
    .btn-logout:hover {
        background-color: #c82333;
    }

</style>

<?php
}

// ==========================================================
// 1. 라우팅 지도 (Mapping Table) : 모드명 => 실행할 함수명
// ==========================================================
$routes = [
    'ef'                     => 'etf_iFrame',
    'ef_r'                   => 'etf_iFrame_responsive', // 소형 모니터용 반응형 화면
    'm'                      => 'etf_mobile',          // 모바일 전용 화면
    'm_api'                  => 'etf_mobile_api',       // 모바일 화면용 JSON API
	'eshl'                 => 'etf_stock_holdings_list',

	'elbs'                => 'etf_list_by_stock',  
	
	'slbe'                => 'stock_list_by_etf',
	
	'ehbe'                => 'etf_holdings_by_etf',
	'ehbe_multi'          => 'etf_holdings_by_etf_multi',

	'tl'                => 'thema_list',

	'gsnb'                => 'get_stock_news_by_naver',
	'gknb'                => 'get_keyword_news_by_naver',

	'api_find'          => 'api_find_stock'


];

// ==========================================================
// 2. 실행 엔진
// ==========================================================

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $func_name = $routes[$mode];

    if (in_array($func_name, ['etf_iFrame', 'etf_iFrame_responsive', 'get_stock_news_by_naver', 'get_keyword_news_by_naver'])) {
        $func_name();
    } else {
        $func_name($pdo);
    }

} else {
    exit;
}

function iframe_base_css(int $container_height = 98, bool $with_padding = true): string {
    $padding = $with_padding ? "padding: 14px 20px; box-sizing: border-box;" : "";
    return "
    <style>
        html, body { height: 100%; margin: 0; overflow: hidden; }
        .board-container { height: {$container_height}%; {$padding} display: flex; flex-direction: column; }
        .table-scroll-wrapper { flex: 1; overflow-y: auto; }
    </style>";
}

############################################
function etf_iFrame() {
###########################################

   require_once "./env/header.php";
    global $mobile;

    // 비율 기반(fr) 그리드 레이아웃: 화면 해상도/배율과 무관하게 항상 뷰포트 폭의 100%에 맞춤.
    // (기존 table-layout:fixed + px 고정폭은 컬럼 합계가 화면보다 넓어지면 오른쪽이 잘리는 문제가 있었음)
    echo "
    <style>
        .dashboard-container { flex: 1; min-height: 0; padding: 12px; box-sizing: border-box; overflow: hidden; }
        .dashboard-grid {
            width: 100%; height: 100%;
            display: grid;
            grid-template-columns: 900fr 700fr 850fr 720fr 230fr;
            grid-template-rows: 3fr 7fr;
            gap: 12px;
        }
        .card { background: #ffffff; border-radius: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; min-height: 0; min-width: 0; display: flex; flex-direction: column; overflow: hidden; }
        iframe { width: 100%; height: 100%; border: none; display: block; }
    </style>";


    // 2. 하단 대시보드 아이프레임 영역 출력
    echo "
    <div class='dashboard-container'>
        <div class='dashboard-grid'>
            <div class='card' style='grid-column:1; grid-row:1;'>
                <iframe src='" . CUR_PHP . "?mode=eshl' name='etf_t1' scrolling='no'></iframe>
            </div>
            <div class='card' style='grid-column:1; grid-row:2;'>
                <iframe src='" . CUR_PHP . "?mode=elbs' name='etf_d1' scrolling='no'></iframe>
            </div>
            <div class='card' style='grid-column:2; grid-row:1 / span 2;'>
                <iframe name='etf_d2' scrolling='no'></iframe>
            </div>
            <div class='card' style='grid-column:3; grid-row:1 / span 2;'>
                <iframe src='analysis_model.php?mode=ar' name='etf_d3'></iframe>
            </div>
            <div class='card' style='grid-column:4; grid-row:1 / span 2;'>
                <iframe src='analysis_model.php?mode=daily&embed=1' name='etf_d4'></iframe>
            </div>
            <div class='card' style='grid-column:5; grid-row:1 / span 2;'>
                <iframe src='' name='etf_d5'></iframe>
            </div>
        </div>
    </div>
    </body>
    </html>";

#################################################################
}// 끝: etf_iFrame 함수
#################################################################

#################################################################
function etf_iFrame_responsive() {
#################################################################

    $cur_php = CUR_PHP;

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ETF 분석 (반응형)</title>
    </head>
    <body>
    <style>
        /* 반응형 3분할 레이아웃 */
        html, body { height:100%; margin:0; overflow:hidden; }

        .r-container {
            display: flex;
            flex-direction: row;      /* 기본: 가로 3등분 */
            height: 100vh;
            gap: 8px;
            padding: 8px;
            box-sizing: border-box;
            background: #f0f2f5;
        }

        .r-panel {
            flex: 1;                  /* 3등분 */
            min-width: 0;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .r-panel-title {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            padding: 6px 12px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .r-panel iframe {
            flex: 1;
            width: 100%;
            border: none;
        }

        /* 태블릿: 768px 이하 → 세로 스택 */
        @media (max-width: 768px) {
            html, body { overflow: auto; }
            .r-container {
                flex-direction: column;
                height: auto;
                min-height: calc(100vh - 60px);
            }
            .r-panel {
                flex: none;
                height: 420px;
            }
        }

        /* 모바일: 480px 이하 → 패널 높이 줄임 */
        @media (max-width: 480px) {
            .r-container { gap: 6px; padding: 6px; }
            .r-panel { height: 360px; }
        }
    </style>

    <script>
    /**
     * 반응형 레이아웃용 openCommonFrames
     * etf_t1, etf_d2, etf_d3 만 연동 (etf_d4, etf_d5 제외)
     */
    function openCommonFrames(rowId, stockCode, params, targets, curPhp) {
        // 행 하이라이트
        if (rowId) {
            document.querySelectorAll('tr').forEach(function(r) {
                r.classList.remove('row-highlight');
                r.style.backgroundColor = '#fff';
            });
            var cur = document.getElementById(rowId);
            if (cur) cur.classList.add('row-highlight');
        }

        var urlMap = {
            'etf_t1': curPhp + '?mode=eshl&' + params,
            'etf_d1': curPhp + '?mode=elbs&' + params,
            'etf_d2': curPhp + '?mode=slbe&' + params,
        };

        // etf_t1, etf_d1, etf_d2 만 연동
        var allowed = ['etf_t1', 'etf_d1', 'etf_d2'];
        targets.forEach(function(target) {
            if (allowed.indexOf(target) !== -1 && urlMap[target]) {
                window.open(urlMap[target], target);
            }
        });
    }
    </script>

    <!-- 숨김 프레임: 내부 window.open 호출이 새 창 대신 여기로 흡수됨 -->
    <iframe name="etf_d3" style="display:none;width:0;height:0;border:none;"></iframe>
    <iframe name="etf_d4" style="display:none;width:0;height:0;border:none;"></iframe>
    <iframe name="etf_d5" style="display:none;width:0;height:0;border:none;"></iframe>

    <div class="r-container">
        <div class="r-panel">
            <div class="r-panel-title">📋 ETF 편입 종목</div>
            <iframe id="fr_t1" name="etf_t1" scrolling="no"></iframe>
        </div>
        <div class="r-panel">
            <div class="r-panel-title">📊 ETF 리스트</div>
            <iframe id="fr_d1" name="etf_d1" scrolling="no"></iframe>
        </div>
        <div class="r-panel">
            <div class="r-panel-title">📊 종목별 ETF</div>
            <iframe name="etf_d2" scrolling="no"></iframe>
        </div>
    </div>
    <script>
    (function() {
        var php = '{$cur_php}';

        // 모바일 판단: 화면 폭 768px 이하 (viewport 메타가 있어 정확)
        function isMobile() { return window.innerWidth <= 768; }

        // 쿠키 설정: 모바일이면 hide_chart=1, 아니면 0
        // → 모든 iframe 요청(초기/행클릭/정렬)에 쿠키가 따라가 차트 일관 처리
        function applyChartCookie() {
            var val = isMobile() ? '1' : '0';
            document.cookie = 'hide_chart=' + val + '; path=/; max-age=86400';
        }

        // 쿠키를 먼저 세팅한 뒤 iframe 로드 (요청에 쿠키 포함되도록)
        applyChartCookie();
        document.getElementById('fr_t1').src = php + '?mode=eshl';
        document.getElementById('fr_d1').src = php + '?mode=elbs';

        // 화면 크기 변경(가로/세로 전환 등) 시 모바일 여부 바뀌면 쿠키 갱신 + 재로드
        var prevMobile = isMobile();
        window.addEventListener('resize', function() {
            var nowMobile = isMobile();
            if (nowMobile !== prevMobile) {
                prevMobile = nowMobile;
                applyChartCookie();
                document.getElementById('fr_t1').src = php + '?mode=eshl';
                document.getElementById('fr_d1').src = php + '?mode=elbs';
            }
        });
    })();
    </script>
    </body>
    </html>
HTML;

}// 끝: etf_iFrame_responsive 함수
#################################################################

#################################################################
function etf_stock_holdings_list($pdo) {
#################################################################
global $mobile;


require "./env/e.fnc";


$etfRepo = new StockRepository($pdo);  //주식관련 데이타

$GR_Vals=Get_Vals('mode');

$top_rank_num=$GR_Vals['top_rank'] ?? 5;

// 💡 등락률 토글 로직
$current_sort = $GR_Vals['sort'] ?? 'top_rank';
$next_rate_sort = ($current_sort === 'rise') ? 'fall' : 'rise'; 


// 2. 함수 호출을 통해 각 컬럼의 아이콘 변수 할당
$arrow_top_rank = UIHelper::getSortIcon($current_sort, 'top_rank');
$arrow_vol = UIHelper::getSortIcon($current_sort, 'vol');
$arrow_owner = UIHelper::getSortIcon($current_sort, 'owner');

$arrow_ratio = UIHelper::getSortIcon($current_sort, 'ratio');
$arrow_rate  = UIHelper::getSortIcon($current_sort, 'rate'); // 등락률 
$arrow_rot   = UIHelper::getSortIcon($current_sort, 'rot');  // 회전율



$target_html = ""; // 초기화
$target_stock_code = $GR_Vals['stock_code'] ?? '';

if($target_stock_code) {
    $target_stock = $etfRepo->getOneStockSummary($target_stock_code);

    if($target_stock) {
        // 💡 1. 상단 고정 종목도 등락률에 맞춰 색상을 계산해 줍니다.
        $t_color = "#333"; 
        if ($target_stock['stock_rate'] > 0) {
            $t_color = "#e1234a"; // 상승 (빨강)
        } elseif ($target_stock['stock_rate'] < 0) {
            $t_color = "#0052a4"; // 하락 (파랑)
        }

        // 🚨 주의: 이전 함수 정의에 맞게 파라미터 순서를 바로잡았습니다! (etf_count가 먼저 와야 합니다)
        if($target_stock['etf_count']) $etf_badge = UIHelper::get_etf_badge_html($target_stock['top_rank_count'], $target_stock['etf_count']);

        $row_id = "target_row_" . $target_stock['stock_code'];

        // 🚀 2. 세련된 하이라이트 스타일 (옅은 파란 배경 + 위아래 진한 테두리)
        $target_html .= "  <tr id='{$row_id}' style='background-color: #f0f7ff; border-top: 2px solid #2563eb; border-bottom: 2px solid #2563eb;'>";

        // 🚀 3. 단순 '선택' 텍스트 대신 눈에 띄는 뱃지 적용
        $target_html .= "    <td style='padding: 10px;'>";
        $target_html .= "      <span style='background-color: #2563eb; color: #fff; font-size: 11px; padding: 3px 6px; border-radius: 4px; font-weight: bold; letter-spacing:-0.5px;'>🎯</span>";
        $target_html .= "    </td>";
        
        // 🚀 4. 종목명 텍스트 강조 (폰트를 살짝 키우고 파란색으로 포인트)
        $target_html .= "    <td style='padding: 10px; text-align: left; font-weight: bold; font-size: 15px;'>";
        $target_html .= "        <span style='color: #2563eb;'>{$target_stock['stock_name']}</span>";  
        $target_html .= "    </td>";

        $target_html .= "    <td style='padding: 10px;'>".number_format($target_stock['total_etf_ownership_ratio'],2)." </td>";
        $target_html .= "    <td style='padding: 10px;'>{$etf_badge} </td>";
        
        // 💡 5. 등락률 텍스트도 굵게(bold) 처리하여 가시성 확보
        $target_html .= "    <td style='padding: 10px; color: {$t_color}; font-weight: bold;'>{$target_stock['stock_rate']}%</td>";
        
        $target_html .= "    <td style='padding: 10px;'>".number_format($target_stock['stock_rot'],2)."</td>";
        $target_html .= "    <td style='padding: 10px;'>" . formatMarketCap($target_stock['stock_vol_cap']) . "</td>";
        $target_html .= "    <td style='padding: 10px;'>" . formatMarketCap($target_stock['stock_cap']) . "</td>";
        $target_html .= "  </tr>";
    }
}


$top_etf_stocks = $etfRepo->getAllStocksInEtfs($current_sort, 20);

$stock_count = count($top_etf_stocks);






echo iframe_base_css(98, true);

// 최종 시세 업데이트 시간 가져오기
$last_update_time = data_upTime('all_data_from_naver', 'call', $pdo);

$html = <<<HTML
    <div class='board-container'>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9; flex-wrap: wrap; gap: 15px;">
            
            <div style="display: flex; align-items: center;">
                <div style="width: 4px; height: 20px; background-color: #2563eb; margin-right: 12px; border-radius: 4px;"></div>
                <h3 style="margin: 0; color: #1e293b; font-weight: 800; font-size: 19px; letter-spacing: -0.5px; display: flex; align-items: center;">
                    ETF 편입 종목 목록
                    <span style="font-size: 14px; color: #64748b; font-weight: 500; margin-left: 8px;">
                        (총 {$stock_count}개)
                    </span>
                </h3>
            </div>

            <div style="display: flex; align-items: center; gap: 10px; position: relative;">
                <span style="font-size: 14px; color: #475569; font-weight: 600;">🔍 종목 검색</span>
                
                <input type="text" id="stockSearchInput" placeholder="종목명 또는 코드 (예: cj)" 
                       oninput="liveSearch(this.value)" autocomplete="off"
                       style="padding: 9px 16px; border: 1px solid #cbd5e1; border-radius: 8px; width: 240px; outline: none; font-size: 13px; color: #0f172a; transition: all 0.2s ease; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);" 
                       onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" 
                       onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='inset 0 1px 2px rgba(0,0,0,0.02)';">
                
                <ul id="suggestionBox" style="display: none; position: absolute; top: 100%; right: 0; margin-top: 8px; width: 320px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); list-style: none; padding: 8px 0; z-index: 999; max-height: 400px; overflow-y: auto;"></ul>
            </div>

        </div>

        <div style="border-radius: 8px; padding: 1px 1px; margin-bottom: 16px; display: flex; align-items: center; font-size: 13px; color: #475569; line-height: 1.5; letter-spacing: -0.3px;">
            <span style='font-size: 11px; padding: 2px 6px; background: #ff0000;  border-radius: 4px; font-weight: bold; border: 0px solid #1e3a8a; vertical-align: middle; margin-right: 8px; color:#ffffff;'>💎 ETF 수 </span>
            <span>

                해당 종목의 편입 비중이 상위 {$top_rank_num} 위 안에 있는 국내 ETF 갯수. 숫자가 높은 수록 해당 종목을 집중적으로 사는 ETF 가 많다는 것임.
            </span>
        </div>

     <div style="display: flex; justify-content: flex-start; align-items: center; gap: 12px; margin-bottom: 24px; padding-top: 15px; border-top: 1px dashed #e2e8f0;">

		     <button id="btnPriceUpdate" onclick="triggerPriceUpdate()"
                    style="padding: 7px 14px; background-color: #2563eb; color: white; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                <svg id="updateIcon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                    <path d="M3 3v5h5"/>
                    <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                    <path d="M16 21v-5h5"/>
                </svg>
                <span id="btnUpdateText">시세업데이트</span>
            </button>
            
			    <button id="btnMarketStatus" onclick="toggleMarketStatus()"
                    style="padding: 7px 14px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; color: #fff;">
                <span id="marketStatusText">장마감</span>
            </button>

            <span style="font-size: 12px; color: #64748b; font-weight: 500; display: flex; align-items: center; gap: 4px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <b id="lastUpdateTime" style="color: #334155; letter-spacing: 0;">{$last_update_time}</b> 기준 (KRX 장중)
            </span>

        
        </div>

HTML;

// 💡 상수(CUR_PHP)는 Heredoc 안에서 직접 출력이 어려우므로, 미리 변수에 담아줍니다.
$cur_php = CUR_PHP;

$html .= <<<HTML
<script>
let debounceTimer;
let currentFocus = -1;

function liveSearch(keyword) {
    var box = document.getElementById('suggestionBox');
    keyword = keyword.trim();

    if (!keyword) {
        box.style.display = 'none';
        return;
    }

    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        // 💡 CUR_PHP 변수 적용
        fetch('{$cur_php}?mode=api_find&keyword=' + encodeURIComponent(keyword))
            .then(response => response.json())
            .then(res => {
                box.innerHTML = ''; 
                currentFocus = -1; 
                
                if (res.success && res.data.length > 0) {
                    res.data.forEach((item, index) => {
                        var li = document.createElement('li');
                        li.style.padding = '10px 12px';
                        li.style.borderBottom = '1px solid #f8f9fa';
                        li.style.cursor = 'pointer';
                        
                        // 💡 PHP 변수와 혼동되지 않도록 자바스크립트 변수 앞에 역슬래시(\) 추가!
                        li.innerHTML = `
                            <div style='display: flex; justify-content: space-between; align-items: center; width: 100%;'>
                                <div style='font-size: 14px;'>
                                    <strong>\${item.stock_name}</strong> 
                                    <span style='color: #888; font-size: 12px; margin-left: 4px;'>(\${item.stock_code})</span>
                                </div>
                                <div style='font-size: 12px; color: #e1234a; font-weight: bold; background: #fff4ce; padding: 2px 6px; border-radius: 4px;'>
                                    ETF \${item.etf_count}개
                                </div>
                            </div>
                        `;
                        
                        li.onmouseover = function() { 
                            removeActive(); 
                            currentFocus = index; 
                            this.style.backgroundColor = '#f1f3f5'; 
                        };
                        li.onmouseout = function() { this.style.backgroundColor = '#fff'; };
                        
                        li.onclick = function() {
                            openDirectFrames(item.stock_code, item.stock_name);
                            box.style.display = 'none'; 
                            document.getElementById('stockSearchInput').value = item.stock_name; 
                        };
                        
                        box.appendChild(li);
                    });
                    box.style.display = 'block'; 
                } else {
                    var errorMsg = res.msg ? res.msg : '검색 결과가 없습니다.';
                    var textColor = res.msg.includes('에러') ? '#e1234a' : '#888'; 
                    box.innerHTML = `<li style='padding: 10px 12px; color: \${textColor}; font-size: 14px; font-weight: bold;'>\${errorMsg}</li>`;
                    box.style.display = 'block';
                }
            })
            .catch(err => { console.error('AJAX Error:', err); });
    }, 200); 
}

document.getElementById('stockSearchInput').addEventListener('keydown', function(e) {
    var box = document.getElementById('suggestionBox');
    if (box.style.display === 'none') return;
    
    var items = box.getElementsByTagName('li');
    if (!items || items.length === 0 || items[0].innerText.includes('결과가 없습니다') || items[0].innerText.includes('에러')) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault(); 
        currentFocus++;
        addActive(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault(); 
        currentFocus--;
        addActive(items);
    } else if (e.key === 'Enter') {
        e.preventDefault(); 
        if (currentFocus > -1) {
            items[currentFocus].click();
        }
    }
});

function addActive(items) {
    if (!items) return false;
    removeActive(items); 
    
    if (currentFocus >= items.length) currentFocus = 0; 
    if (currentFocus < 0) currentFocus = (items.length - 1); 
    
    items[currentFocus].style.backgroundColor = '#e1f0fa'; 
    items[currentFocus].scrollIntoView({ block: 'nearest' });
}

function removeActive(items) {
    var list = items || document.getElementById('suggestionBox').getElementsByTagName('li');
    for (var i = 0; i < list.length; i++) {
        list[i].style.backgroundColor = '#fff';
    }
}

function openDirectFrames(stockCode, stockName) {
    // 💡 PHP 변수 {$top_rank_num} 와 {$cur_php} 가 Heredoc 안에서 안전하게 렌더링 됩니다.
    var params = 'stock_code=' + stockCode + '&stock_name=' + encodeURIComponent(stockName) + '&top_rank={$top_rank_num}';
    var curPhp = '{$cur_php}';
    
    window.open(curPhp + '?mode=eshl&' + params, 'etf_t1');
    window.open(curPhp + '?mode=elbs&' + params, 'etf_d1');
   
    window.open(curPhp + '?mode=slbe&' + params, 'etf_d2');
    window.open(curPhp + '?mode=gsnb&' + params, 'etf_d5');
}

document.addEventListener('click', function(e) {
    if (e.target.id !== 'stockSearchInput') {
        document.getElementById('suggestionBox').style.display = 'none';
    }
});


// 💡 시세 업데이트 버튼 클릭 시 실행되는 함수
function triggerPriceUpdate() {
    const btn = document.getElementById('btnPriceUpdate');
    const btnText = document.getElementById('btnUpdateText');
    const updateIcon = document.getElementById('updateIcon');
    const timeText = document.getElementById('lastUpdateTime');

    // 1. 통신 시작 전 UI 변경
    btn.disabled = true;
 if (progressTimer) clearInterval(progressTimer);   // 💡 진행바 일시정지
    btn.style.background = '#94a3b8';                   // 회색 고정
    btn.style.cursor = 'not-allowed';
    btnText.innerText = '업데이트 진행중...';
    
    updateIcon.style.transition = 'transform 2s linear';
    updateIcon.style.transform = 'rotate(360deg)';
    
    let rotation = 360;
    const spinInterval = setInterval(() => {
        rotation += 360;
        // 🚀 에러 방지: \$ 로 이스케이프 처리!
        updateIcon.style.transform = `rotate(\${rotation}deg)`;
    }, 2000);

    // 2. 백엔드로 Fetch(AJAX) 요청 전송
    fetch('classes/data_upload.php?mode=rtfn')
        .then(response => {
            if (!response.ok) throw new Error('네트워크 응답이 올바르지 않습니다.');
            return response.text(); 
        })
        .then(data => {
            console.log('업데이트 결과:', data);
            
            const now = new Date();
            const pad = (n) => n.toString().padStart(2, '0');
            
            // 🚀 에러 방지: 이전에 뻗었던 에러를 완전히 막기 위해 더하기(+) 연산자 사용
            const formattedTime = now.getFullYear() + '-' + 
                                  pad(now.getMonth() + 1) + '-' + 
                                  pad(now.getDate()) + ' ' + 
                                  pad(now.getHours()) + ':' + 
                                  pad(now.getMinutes()) + ':' + 
                                  pad(now.getSeconds());
            
            timeText.innerText = formattedTime;
			timeText.style.color = '#334155';

	setTimeout(() => {
                window.parent.location.reload(); 
            }, 100);


        })
        .catch(error => {
            console.error('Error:', error);
            alert('업데이트 중 오류가 발생했습니다. 관리자에게 문의하세요.');
        })


  .finally(() => {
            clearInterval(spinInterval);
            updateIcon.style.transition = 'none';
            updateIcon.style.transform = 'rotate(0deg)';

            btn.disabled = false;
            btn.style.cursor = 'pointer';
            btnText.innerText = getUpdateLabel(localStorage.getItem(STORAGE_KEY) === 'open');

            startAutoTimer();   // 💡 진행바·자동검사 타이머 재가동 (배경색도 여기서 다시 설정됨)
        });
}


// 마지막 시세 갱신 시각 (PHP에서 주입)
const LAST_UPDATE_STR = '{$last_update_time}';
const AUTO_THRESHOLD_MS = 2 * 60 * 1000;  // 2분
const STORAGE_KEY = 'ar_market_status';   // 'open'(장중) | 'closed'(장마감)

// NXT 장외 시간대 여부 (08:00~08:50, 15:30~20:00)
function isNxtWindow() {
    const now = new Date();
    const t = now.getHours() * 60 + now.getMinutes();
    return (t >= 480 && t < 530) || (t > 930 && t <= 1200);
}

// 업데이트 버튼 라벨 (NXT 시간대면 'NXT ' 접두사)
function getUpdateLabel(isAuto) {
    const prefix = isNxtWindow() ? 'NXT ' : '';
    return prefix + (isAuto ? '자동(2분) 업데이트중' : '시세업데이트');
}

// 버튼 표시 갱신
function renderMarketStatus(status) {
    const btn  = document.getElementById('btnMarketStatus');
    const text = document.getElementById('marketStatusText');
    const updateBtnText = document.getElementById('btnUpdateText');

    if (status === 'open') {
        text.innerText = '장중';
        btn.style.backgroundColor = '#16a34a';
        if (updateBtnText) updateBtnText.innerText = getUpdateLabel(true);
    } else {
        text.innerText = '장마감';
        btn.style.backgroundColor = '#94a3b8';
        if (updateBtnText) updateBtnText.innerText = getUpdateLabel(false);
    }
    updateProgressBar();   // 💡 진행바 즉시 반영
}

// 토글
function toggleMarketStatus() {
    let status = localStorage.getItem(STORAGE_KEY) === 'open' ? 'closed' : 'open';
    localStorage.setItem(STORAGE_KEY, status);
    renderMarketStatus(status);
    // 장중으로 막 켰으면 즉시 한 번 검사
    startAutoTimer();          // 타이머 재시작/중지
    if (status === 'open') checkAutoUpdate();
}

// 마지막 업데이트가 2분 넘었는지 검사 → 넘으면 자동 업데이트
function checkAutoUpdate() {
    if (localStorage.getItem(STORAGE_KEY) !== 'open') return;  // 장중일 때만
    if (!LAST_UPDATE_STR) return;

    const last = new Date(LAST_UPDATE_STR.replace(' ', 'T'));
    if (isNaN(last.getTime())) {
        console.warn('업데이트 시각 파싱 실패:', LAST_UPDATE_STR);
        return;
    }

    const diff = Date.now() - last.getTime();
    if (diff > AUTO_THRESHOLD_MS) {
        console.log('2분 경과 → 자동 시세 업데이트 실행');
        triggerPriceUpdate();   // 성공 시 부모 전체 새로고침 → 페이지 다시 로드됨
    }
}

// 마지막 업데이트로부터 경과 비율(0~100%)을 버튼 배경에 채움
function updateProgressBar() {
    const btn = document.getElementById('btnPriceUpdate');
    if (!btn) return;

    // 장중이 아니면 진행바 제거
    if (localStorage.getItem(STORAGE_KEY) !== 'open' || !LAST_UPDATE_STR) {
        btn.style.background = '#2563eb';
        return;
    }

    const last = new Date(LAST_UPDATE_STR.replace(' ', 'T'));
    if (isNaN(last.getTime())) return;

    let ratio = (Date.now() - last.getTime()) / AUTO_THRESHOLD_MS;  // 0 ~ 1
    if (ratio < 0) ratio = 0;
    if (ratio > 1) ratio = 1;

    const pct = (ratio * 100).toFixed(1);

    // 채워진 부분(진한 파랑) → 남은 부분(연한 파랑) 2색 그라데이션
    btn.style.background =
         `linear-gradient(to right, #1d4ed8 \${pct}%, #60a5fa \${pct}%)`;
}


let autoTimer = null;
let progressTimer = null;

function startAutoTimer() {
    if (autoTimer) clearInterval(autoTimer);
    if (progressTimer) clearInterval(progressTimer);

    if (localStorage.getItem(STORAGE_KEY) === 'open') {
        // 30초마다 "2분 넘었나" 검사
        autoTimer = setInterval(checkAutoUpdate, 30 * 1000);
        // 1초마다 진행바 채우기
        progressTimer = setInterval(updateProgressBar, 1000);
        updateProgressBar();  // 즉시 한 번
    } else {
        // 장마감이면 버튼 원래 색으로
        const btn = document.getElementById('btnPriceUpdate');
        if (btn) btn.style.background = '#2563eb';
    }
}


// 페이지 로드 시
(function initMarketStatus() {
    const status = localStorage.getItem(STORAGE_KEY) || 'closed';  // 기본 장마감
    renderMarketStatus(status);
    checkAutoUpdate();   // 로드 즉시 한 번 검사
    startAutoTimer();    // 이후 주기적 검사 시작
})();

</script>
HTML;




// 🚀 4. 비로소 테이블(Table) 태그 시작!
    $html .= "<div style='overflow-x: auto; overflow-y: auto; max-height: 90%; border-bottom: 1px solid #e3e6f0;'>"; 
    $html .= "<table class='title-board-table' style='width: 100%; border-collapse: collapse; text-align: center; font-size: 14px;'>";

$html .= "  <thead>";
$html .= "    <tr>"; // 💡 style은 CSS로 옮겼으므로 비워둡니다.

$html .= "      <th style='background-color: #f8f9fa; padding: 12px; width: 8%; position: sticky; top: 0 !important; z-index: 11;'>순위</th>";

$html .= "      <th style='background-color: #f8f9fa; padding: 12px; width: 20%; position: sticky; top: 0 !important; z-index: 11;'>종목명</th>";

$html .= "      <th style='background-color: #f8f9fa; padding: 12px; width: 10%; position: sticky; top: 0 !important; z-index: 11;'><a href='" . CUR_PHP . "?mode=eshl&sort=owner' style='color: #333; text-decoration: none;'>ETF<br>보유비중<span style='color:#e1234a;'>{$arrow_owner}</span></a></th>";

$html .= "      <th style='background-color: #f8f9fa; padding: 12px; width: 12%; position: sticky; top: 0 !important; z-index: 11;'><a href='" . CUR_PHP . "?mode=eshl&sort=top_rank' style='color: #333; text-decoration: none;'>ETF 수<br><span style='color:#e1234a;'>{$arrow_top_rank}</span></a></th>";

$html .= "      <th style='background-color: #f8f9fa; padding: 12px; width: 12%; position: sticky; top: 0 !important; z-index: 11;'><a href='" . CUR_PHP . "?mode=eshl&sort={$next_rate_sort}' style='color: #333; text-decoration: none;'>등락률<br><span style='color:#e1234a;'>{$arrow_rate}</span></a></th>";
$html .= "      <th style='background-color: #f8f9fa; padding: 12px; width: 10%; position: sticky; top: 0 !important; z-index: 11;'><a href='" . CUR_PHP . "?mode=eshl&sort=rot' style='color: #333; text-decoration: none;'>회전율<br><span style='color:#e1234a;'>{$arrow_rot}</span></a></th>";
$html .= "      <th style='background-color: #f8f9fa; padding: 12px; width: 17%; position: sticky; top: 0 !important; z-index: 11;'><a href='" . CUR_PHP . "?mode=eshl&sort=vol' style='color: #333; text-decoration: none;'>거래대금<br><span style='color:#e1234a;'>{$arrow_vol}</span></a></th>";
$html .= "      <th style='background-color: #f8f9fa; padding: 14px; width: 12%; position: sticky; top: 0 !important; z-index: 11;'>시가총액</th>";

$html .= "    </tr>";
$html .= "  </thead>";

$html .= "  <tbody>";

$html.= $target_html;

if (!empty($top_etf_stocks)) {
    foreach ($top_etf_stocks as $rank => $stock) {

        $color = "#333"; 
        if ($stock['stock_rate'] > 0) {
            $color = "#e1234a"; 
        } elseif ($stock['stock_rate'] < 0) {
            $color = "#0052a4"; 
        }


       $etf_badge = UIHelper::get_etf_badge_html($stock['top_rank_count'],$stock['etf_count']);

        $row_id = "stock_row_" . $stock['stock_code'];
		$html .= "  <tr id='{$row_id}' style='border-bottom: 1px solid #f2f2f2;'>";

        $html .= "    <td style='padding: 10px;'>" . ($rank + 1) . "</td>";
        $html .= "    <td style='padding: 10px; text-align: left; font-weight: bold;'>";
    
        $encoded_name = urlencode($stock['stock_name']);
        $params = "stock_code={$stock['stock_code']}&stock_name={$encoded_name}&top_rank={$top_rank_num}";

	$js_click = "openCommonFrames('{$row_id}', '{$stock['stock_code']}', '{$params}', ['etf_d1', 'etf_d2',  'etf_d5'], '" . CUR_PHP . "');";

        $html .= "<a href='javascript:void(0);' onclick=\"{$js_click}\" style='color: #333; text-decoration: none; cursor: pointer;'>";
        $html .= "        {$stock['stock_name']} ";
        $html .= "</a>";        
        $html .= "    </td>";

        $html .= "    <td style='padding: 10px;'>".number_format($stock['total_etf_ownership_ratio'],2)." </td>";
        $html .= "    <td style='padding: 10px;'>{$etf_badge} </td>";
        $html .= "    <td style='padding: 10px; color: {$color};'>{$stock['stock_rate']}%</td>";		
        $html .= "    <td style='padding: 10px; color: #dd6b20; font-weight: 600;'>" . number_format($stock['stock_rot'], 2) . "%</td>";
        $html .= "    <td style='padding: 10px;'>" .  formatMarketCap($stock['stock_vol_cap']) . "</td>";
        $html .= "    <td style='padding: 10px; text-align: right;'>" . formatMarketCap($stock['stock_cap']) . "</td>";

        $html .= "  </tr>";
    }
} else {
    $html .= "  <tr>";
    $html .= "    <td colspan='9' style='padding: 20px;'>해당하는 종목 데이터가 없습니다.</td>";
    $html .= "  </tr>";
}

$html .= "  </tbody>";
$html .= "</table>";
$html .= "</div></div>";

echo $html;

################### end of  etf_stock_holdings_list #######################
}
################### end of  etf_stock_holdings_list #######################

#################################################################
function etf_list_by_stock($pdo) { # 특정종목을 보유한 etf 리스트
#################################################################


global $mobile;


require "./env/e.fnc";


$GR_Vals=Get_Vals();

// 1. 넘어온 종목 코드 및 이름 받기
$stock_code = $GR_Vals['stock_code'] ?? '';
$stock_name = $GR_Vals['stock_name'] ?? '해당';
$encoded_name = urlencode($stock_name);
$current_mode = $GR_Vals['mode']; 

$top_rank_num= $GR_Vals['top_rank'] ?? '5';


// 🚀 주소창에 다중 종목 코드 파라미터(multi_stock_codes)가 넘어왔는지 확인합니다.
    $multi_stock_str = $GR_Vals['multi_stock_codes'] ?? '';
$etfRepo = new StockRepository($pdo);


// 최근 본 종목 기록 (단일 조회일 때만 기록)
    if (empty($multi_stock_str) && !empty($stock_code) && !empty($stock_name)) {
        $etfRepo->saveRecentStock($stock_code, $stock_name);
    }



// 💡 [핵심] 활성화 상태 유지를 위한 배열 구성
    // 다중 검색 모드면 넘어온 코드들을 활성화하고, 처음 진입했다면 현재 종목코드만 활성화합니다.
    $active_codes = $multi_stock_str ? explode(',', $multi_stock_str) : array_filter([$stock_code]);



// 🚀 추가: 검색어 받기
$search_keyword = $GR_Vals['search_keyword'] ?? '';






// 💡 등락률 토글 로직
$current_sort = $GR_Vals['sort'] ?? 'rank';
$next_rate_sort = ($current_sort === 'rise') ? 'fall' : 'rise'; 

// 2. 함수 호출을 통해 각 컬럼의 아이콘 변수 할당
$arrow_rank = UIHelper::getSortIcon($current_sort, 'rank');
$arrow_rate  = UIHelper::getSortIcon($current_sort, 'rate'); // 등락률
$arrow_vol = UIHelper::getSortIcon($current_sort, 'vol');
$arrow_rot   = UIHelper::getSortIcon($current_sort, 'rot');  // 회전율
$arrow_ratio = UIHelper::getSortIcon($current_sort, 'ratio'); // 비중



    // 🚀 데이터 불러오기 분기 처리
    if (!empty($multi_stock_str)) {
        // 1) 다중 종목 공통 ETF 모드일 때
        $etf_list = $etfRepo->getEtfsByMultiStocks($active_codes);
        $combined_names = $etfRepo->getStockNamesByCodes($active_codes);
        $title_display = "<span style='color:#e1234a;'>[{$combined_names}]</span> 공통 편입 ETF 목록";
        $etf_count = count($etf_list);
	
    } else {
        // 2) 기존 방식: 단일 종목 관련 ETF 모드일 때 (기존 쿼리 코드를 여기에 유지하세요)

	    $etf_list = $etfRepo->getEtfsHoldingSpecificStock($stock_code, $current_sort, $search_keyword);
            $etf_count = count($etf_list);
  
		   if ($stock_code)  $title_display = "<span style='color:#0052a4;'>[{$stock_name}]</span> 종목을 담고 있는 ETF 목록";
		   else $title_display = "<span style='color:#0052a4;'> 국내 ETF 목록";
    }
	


#if ($stock_code) {

    
    // 💡 3. 데이터 가져오기 (🚀 검색어 $search_keyword 같이 전달!)


    // 4. HTML 게시판 조립 시작
    $html = "<div class='board-container'>";

    $html .= "  <div style='font-size: 18px; color: #333; border-left: 4px solid #0052a4; padding-left: 10px; line-height: 1.2;'>";

    // 검색어가 있으면 타이틀에도 표시해줍니다
// 💡 검색어 유무에 따른 타이틀 분기 처리
    if ($search_keyword) {
        $safe_keyword = htmlspecialchars($search_keyword, ENT_QUOTES, 'UTF-8');
        $html .= "<h3 style='color:#e1234a;'>'{$safe_keyword}'  ETF 검색 결과 (#{$etf_count}개)</h3>";
    } else {
        $html .= "<h3 style='border-left: 5px solid #e1234a; padding-left: 12px; color: #2c3e50;'>$title_display (#{$etf_count}개)</h3>";
    }
    $html .= "  </div>";



    // 🚀 서버로 파라미터를 넘기는 진짜 검색 폼
    $html .= "
<div style='margin-bottom: 15px;'>
    <form method='GET' action='" . CUR_PHP . "' style='margin: 0; display: flex; justify-content: space-between; align-items: center; width: 100%;'>
        
        <input type='hidden' name='mode' value='{$current_mode}'>
        <input type='hidden' name='stock_code' value='{$stock_code}'>
        <input type='hidden' name='stock_name' value='".htmlspecialchars($stock_name)."'>
        <input type='hidden' name='sort' value='{$current_sort}'>
        <input type='hidden' name='top_rank' value='{$top_rank_num}'>
        
        <div style='display: flex; align-items: center;'>
            <span style='font-size: 14px; font-weight: bold; color: #555; margin-right: 10px;'>🔍 ETF명 검색</span>
            <input type='text' name='search_keyword' value='".htmlspecialchars($search_keyword)."' placeholder='예: 반도체, 레버리지' 
                   style='padding: 6px 12px; border: 1px solid #ccd0d5; border-radius: 4px; outline: none; font-size: 14px; width: 200px;' autocomplete='off'>
            <button type='submit' style='background: #0052a4; color: #fff; border: none; padding: 6px 12px; margin-left: 5px; border-radius: 4px; cursor: pointer; font-weight: bold;'>검색</button>
        </div>
       

            ";
            
            // 검색 중일 때 '초기화' 버튼 노출
            if ($search_keyword) {
                $html .= "<button type='button' onclick=\"location.href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort={$current_sort}&top_rank={$top_rank_num}'\" style='background: #858796; color: #fff; border: none; padding: 6px 12px; margin-left: 5px; border-radius: 4px; cursor: pointer; font-weight: bold;'>초기화</button>";
            }
            
    $html .= "
        </form>
    </div>
    ";

// 🚀 [추가/수정] 최근 검색한 종목 태그 리스트 출력 (다중 선택 기능)
$recent_stocks = $etfRepo->getRecentStocks(7); 

    if (!empty($recent_stocks)) {
        // 💡 버튼과 레이블을 감싸는 유연한 박스
        $html .= "<div style='margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;'>";
        $html .= "  <span style='font-size: 13px; color: #888; font-weight: bold; margin-right: 4px;'>🕒 최근 조회:</span>";

        foreach ($recent_stocks as $rs) {
            $rs_code = $rs['stock_code'];
            $rs_name = htmlspecialchars($rs['stock_name']);

            // 🚀 [업데이트] 새로고침이 되더라도 주소창 값을 기반으로 빨간색 상태를 완벽히 유지합니다!
            $is_active = in_array($rs_code, $active_codes);
            
            $bg_color   = $is_active ? "#e1234a" : "#f1f3f5";
            $text_color = $is_active ? "#ffffff" : "#6c757d";
            $border     = $is_active ? "1px solid #e1234a" : "1px solid #e9ecef";

            $html .= "  <button type='button' data-code='{$rs_code}' data-name='{$rs_name}' onclick='toggleRecentStock(this)'
                            style='background-color: {$bg_color}; color: {$text_color}; border: {$border}; padding: 5px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05); outline: none;'
                            onmouseover=\"this.style.filter='brightness(0.95)'\" onmouseout=\"this.style.filter='brightness(1)'\">";
            $html .= "    {$rs_name}";
            $html .= "  </button>";
        }
        
        $html .= "</div>";

        // 🚀 종목 칩 클릭 = 즉시 관련 ETF 갱신 (별도 버튼 없음)
        //    0개 선택 → 전체 ETF / 1개 → 단일 종목 ETF / 2~4개 → 공통 편입 ETF / 최대 4개
        // PHP의 현재 활성화 상태 배열을 자바스크립트 초기 배열로 실시간 이식합니다.
        $js_array = json_encode(array_values($active_codes));
        $name_map = [];
        foreach ($recent_stocks as $rs) { $name_map[$rs['stock_code']] = $rs['stock_name']; }

        $html .= "
        <script>
        let selectedRecentStocks = {$js_array};
        const stockNameMap = " . json_encode($name_map, JSON_UNESCAPED_UNICODE) . ";

        // 선택된 종목 수에 따라 하단 ETF 표를 즉시 갱신
        function reloadStocksEtf() {
            let base = '" . CUR_PHP . "?mode={$current_mode}&sort={$current_sort}&top_rank={$top_rank_num}';
            if (selectedRecentStocks.length === 0) {
                // 0개: 전체 ETF
                location.href = base;
            } else if (selectedRecentStocks.length === 1) {
                // 1개: 단일 종목 관련 ETF
                let code = selectedRecentStocks[0];
                let name = stockNameMap[code] || '';
                location.href = base + '&stock_code=' + encodeURIComponent(code) + '&stock_name=' + encodeURIComponent(name);
            } else {
                // 2~4개: 공통 편입 ETF
                location.href = base + '&multi_stock_codes=' + selectedRecentStocks.join(',');
            }
        }

        function toggleRecentStock(btn) {
            let code = btn.getAttribute('data-code');
            let index = selectedRecentStocks.indexOf(code);

            if (index > -1) {
                // 선택 해제
                selectedRecentStocks.splice(index, 1);
            } else {
                // 최대 4개 제한
                if (selectedRecentStocks.length >= 4) {
                    alert('종목은 최대 4개까지 선택할 수 있습니다.');
                    return;
                }
                selectedRecentStocks.push(code);
            }
            reloadStocksEtf();
        }
        </script>
        ";
    }
 


    // 🚀 최근 본 ETF 태그 리스트 (종목 '최근 조회' 바로 아래 줄 / 최신 5개, etf_name 유니크)
    //    - 한 줄 가로 스크롤(줄바꿈 금지) + 칩별 말줄임(…), 전체 이름은 hover 툴팁
    //    - ETF명 클릭은 다른 프레임(etf_d2)을 여는 동작이라 현재 프레임이 갱신 안 됨
    //      → 클릭 시 JS(pushRecentEtf)로 칩을 즉시 추가/이동(낙관적 업데이트)
    $recent_etfs = $etfRepo->getRecentEtfs(4);
    $etf_list_disp = empty($recent_etfs) ? 'none' : 'flex';
    $html .= "<style>
        #recent-etf-list { margin-bottom:20px; display:{$etf_list_disp}; flex-wrap:nowrap; gap:8px; align-items:center; }
        #recent-etf-label { flex:0 0 auto; font-size:13px; color:#888; font-weight:bold; margin-right:4px; }
        .recent-etf-chip { flex:0 1 auto; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
                           background-color:#eef4fb; color:#0052a4; border:1px solid #d6e4f5; padding:5px 12px; border-radius:20px;
                           font-size:13px; font-weight:600; cursor:pointer; transition:all 0.2s; box-shadow:0 1px 2px rgba(0,0,0,0.05); outline:none; }
        .recent-etf-chip:hover { filter:brightness(0.96); }
    </style>";
    $html .= "<div id='recent-etf-list'>";
    $html .= "  <span id='recent-etf-label'>📦 최근 ETF:</span>";
    foreach ($recent_etfs as $re) {
        $re_code = htmlspecialchars($re['etf_code']);
        $re_name = htmlspecialchars($re['etf_name']);                 // 풀네임(툴팁·dedup용)
        $re_short = preg_replace('/^\S+\s+/u', '', $re['etf_name']);  // 운용사(앞 토큰) 제거 → 실질이름만 표시
        if ($re_short === '' || $re_short === null) $re_short = $re['etf_name'];   // 공백 없으면 원본 유지
        $re_short = htmlspecialchars($re_short);
        $re_name_js = str_replace(["\\", "'"], ["\\\\", "\\'"], $re['etf_name']);   // JS 문자열 안전
        $html .= "  <button type='button' class='recent-etf-chip' data-ecode='{$re_code}' data-ename='{$re_name}' title='{$re_name}'
                        onclick=\"openRecentEtf('{$re_code}','{$re_name_js}')\">{$re_short}</button>";
    }
    $html .= "</div>";

    // 최근 ETF 칩 공통 동작(열기 + 클릭 시 즉시 추가/이동)
    $stock_code_js = str_replace(["\\", "'"], ["\\\\", "\\'"], $stock_code);
    $html .= "
    <script>
    function openRecentEtf(code, name) {
        window.open('" . CUR_PHP . "?mode=ehbe&etf_code=' + encodeURIComponent(code) + '&etf_name=' + encodeURIComponent(name) + '&stock_code=' + encodeURIComponent('{$stock_code_js}'), 'etf_d2');
    }
    // 테이블/칩에서 ETF를 열 때 호출 → 현재 프레임의 '최근 ETF' 줄을 새로고침 없이 갱신
    function pushRecentEtf(code, name) {
        var wrap = document.getElementById('recent-etf-list');
        if (!wrap) return;
        wrap.style.display = 'flex';
        // 같은 이름 기존 칩 제거(유니크 = etf_name 기준)
        wrap.querySelectorAll('.recent-etf-chip').forEach(function(b){ if (b.getAttribute('data-ename') === name) b.remove(); });
        // 새 칩 생성 후 라벨 바로 뒤(맨 앞)에 삽입
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'recent-etf-chip';
        b.setAttribute('data-ecode', code);
        b.setAttribute('data-ename', name);
        b.title = name;                                   // 툴팁=풀네임
        b.textContent = name.replace(/^\S+\s+/, '') || name;   // 운용사 제거 → 실질이름만 표시
        b.onclick = function(){ openRecentEtf(code, name); };
        var label = document.getElementById('recent-etf-label');
        wrap.insertBefore(b, label ? label.nextSibling : wrap.firstChild);
        // 최신 4개만 유지
        var chips = wrap.querySelectorAll('.recent-etf-chip');
        for (var i = 4; i < chips.length; i++) chips[i].remove();
    }
    </script>";

    $html .= "<div style='overflow-x: auto; overflow-y: auto; max-height: 90%; border-bottom: 1px solid #e3e6f0;'>";
    $html .= "<table class='title-board-table' style='width: 100%; border-collapse: separate; border-spacing: 0; text-align: center; font-size: 14px;'>";

    // 🚀 테이블 헤더 시작

	$html .= "  <thead>";
    // 💡 tr에 있던 배경색(background-color: #f8f9fa;)을 삭제했습니다.
    $html .= "    <tr style='color: #555; border-bottom: 2px solid #e9ecef;'>";   
    
    // 🚀 모든 th 태그에 고정 속성(sticky, top: 0, z-index, 배경색)을 강제로 추가합니다!
    $html .= "      <th style='background-color: #f8f9fa; padding: 12px; width: 40px; position: sticky; top: 0 !important; z-index: 11;'>선택</th>";    
    $html .= "      <th style='background-color: #f8f9fa; padding: 12px; text-align: left; position: sticky; top: 0 !important; z-index: 11;'>ETF명</th>";
    
    // 등락률 클릭 링크 (상승/하락 토글)
    $html .= "      <th style='background-color: #f8f9fa; padding: 12px; position: sticky; top: 0 !important; z-index: 11;' nowrap>
                        <a href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort={$next_rate_sort}' style='color: #333; text-decoration: none;'>등락률<span style='color:#e1234a;'>{$arrow_rate}</span></a>
                    </th>";
                    
    // 거래대금 클릭 링크 (내림차순)
    $html .= "      <th style='background-color: #f8f9fa; padding: 12px; text-align: right; position: sticky; top: 0 !important; z-index: 11;' nowrap>
                        <a href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort=vol' style='color: #333; text-decoration: none;'>대금(억)<span style='color:#e1234a;'>{$arrow_vol}</span></a>
                    </th>";
                    
    // 회전율 클릭 링크 (내림차순)
    $html .= "      <th style='background-color: #f8f9fa; padding: 12px; position: sticky; top: 0 !important; z-index: 11;'>
                        <a href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort=rot' style='color: #333; text-decoration: none;'>회전율<span style='color:#e1234a;'>{$arrow_rot}</span></a>
                    </th>";

    $html .= "      <th style='background-color: #f8f9fa; padding: 12px; position: sticky; top: 0 !important; z-index: 11;'>수익률<br>(1년)</th>";

    $html .= "      <th style='background-color: #f8f9fa; padding: 12px; position: sticky; top: 0 !important; z-index: 11;'>시총(억)</th>";

    // Top 비중순위 소트 링크 추가
    $html .= "      <th style='background-color: #f8f9fa; padding: 14px 12px; position: sticky; top: 0 !important; z-index: 11;'>
                        <a href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort=rank' style='color: #e1234a; text-decoration: none;'>Top".$top_rank_num."<span>{$arrow_rank}</span></a>
                    </th>";

    $html .= "      <th style='background-color: #f8f9fa; padding: 14px 12px; position: sticky; top: 0 !important; z-index: 11;'>
                        <a href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort=ratio' style='color: #333; text-decoration: none;'>비중<span style='color:#e1234a;'>{$arrow_ratio}</span></a>
                    </th>";

    $html .= "      <th style='background-color: #f8f9fa; padding: 12px; position: sticky; top: 0 !important; z-index: 11;' nowrap>편입수</th>";

    $html .= "    </tr>";
    $html .= "  </thead>";
   


    $html .= "  <tbody>";

    if (!empty($etf_list)) {
        $few_header_shown = false; // 집중형 그룹 헤더 1회 출력 플래그
        $few_group_open   = false; // 집중형 박스가 열려 있는지

        foreach ($etf_list as $etf) {

            // ── 편입수 30개 미만(집중형) 그룹 박스 처리 ──
            $is_few = ((int)$etf['holdings_count'] <= 30);

            // 집중형 그룹 시작: 헤더 행 출력
            if ($is_few && !$few_header_shown) {
                $html .= "<tr><td colspan='10' style='padding:10px 14px; background:#fff7ed; border:1px solid #f3b4bd; border-bottom:none; border-radius:12px 12px 0 0; box-shadow:0 -3px 8px rgba(225,35,74,0.06); font-weight:700; color:#e1234a; font-size:13px;'>📦 집중형 ETF (편입 30개 이하)</td></tr>";
                $few_header_shown = true;
                $few_group_open   = true;
            }

            // 집중형 → 일반 전환 시점: 박스 닫는 구분선
            if (!$is_few && $few_group_open) {
                $html .= "<tr><td colspan='10' style='padding:0; line-height:6px; font-size:0; background:#fffbef; border:1px solid #f3b4bd; border-top:none; border-radius:0 0 12px 12px; box-shadow:0 4px 8px rgba(0,0,0,0.07);'>&nbsp;</td></tr>";
                $few_group_open = false;
            }

// 다중 모드에는 rank가 없으므로 단일 모드일 때만 표시
            $rank_val = isset($etf['etf_stock_rank']) ? $etf['etf_stock_rank'] : 0;

            // 💡 누락되면 안 되는 필수 변수 세팅!
            $rate_color = ($etf['etf_rate'] > 0) ? '#e1234a' : (($etf['etf_rate'] < 0) ? '#0052a4' : '#666');
            $rate_sign  = ($etf['etf_rate'] > 0) ? '+' : '';

         // ── 직전 대비 변화량 계산 ──────────────────
            $rate      = (float)$etf['etf_rate'];
            $rate_prev = (float)($etf['etf_rate_prev'] ?? 0);
            $delta     = $rate - $rate_prev;   // 직전 대비 증감 (%포인트)

			    // 변화량 표시 HTML (직전값이 0이면 '신규/비교불가'로 처리)
            if ($rate_prev == 0) {
                $delta_html = "<span style='color:#94a3b8; font-size:11px; '>—</span>";
            } elseif ($delta > 0) {
                $delta_html = "<span style='color:#e1234a; font-size:11px; font-weight:bold;'>▲" . number_format(abs($delta), 2) . "</span>";
            } elseif ($delta < 0) {
                $delta_html = "<span style='color:#0052a4; font-size:11px; font-weight:bold; '>▼" . number_format(abs($delta), 2) . "</span>";
            } else {
                $delta_html = "<span style='color:#94a3b8; font-size:11px; '></span>";
            }


            $top_rank_display = "-";
			if($etf['etf_stock_rank'] >0) {
						if ($etf['etf_stock_rank'] < 6  ) {
							$top_rank_display = "<span style='background: #e1234a; color: #fff; padding: 2px 8px; border-radius: 4px; font-weight: bold; font-size: 12px;'>{$etf['etf_stock_rank']}위</span>";
						} else $top_rank_display = "<span style='color: #c0c0c0; padding: 2px 8px; border-radius: 4px; font-size: 10px;'>{$etf['etf_stock_rank']}위</span>";

			}



            $row_id = "stock_row_" . $etf['etf_code'];
            $encoded_etf_name = urlencode($etf['etf_name']);
            $etf_name_js = str_replace(["\\", "'"], ["\\\\", "\\'"], $etf['etf_name']);   // pushRecentEtf용 JS 문자열 안전

            // 💡 클릭 이벤트(js_click)도 반드시 루프 안에서 매번 만들어져야 합니다.
            // 멀티 선택 상태면 multi_stock_codes 를 함께 넘겨 etf_d2에서 선택 종목 표시
            $multi_param = $multi_stock_str ? "&multi_stock_codes=" . urlencode($multi_stock_str) : "";
            $js_click = "var rows=document.querySelectorAll('.title-board-table tbody tr'); rows.forEach(function(r){r.classList.remove('row-highlight');}); document.getElementById('{$row_id}').classList.add('row-highlight'); window.open('" . CUR_PHP . "?mode=ehbe&etf_code={$etf['etf_code']}&etf_name={$encoded_etf_name}&stock_code={$stock_code}{$multi_param}', 'etf_d2'); if(typeof pushRecentEtf==='function') pushRecentEtf('{$etf['etf_code']}','{$etf_name_js}');";

            // 집중형 행: 배경 + 좌우 테두리(첫/마지막 td에 적용 — border-collapse 대응)
            $few_bg_only = $is_few ? "background:#fffbef;" : "";
            $few_l = $is_few ? "border-left:1px solid #f3b4bd;"  : ""; // 첫 td
            $few_r = $is_few ? "border-right:1px solid #f3b4bd;" : ""; // 마지막 td
            $html .= "  <tr id='{$row_id}' style='border-bottom: 1px solid #f2f2f2; {$few_bg_only}'>";

            // 🚀 번호가 찍힐 커스텀 체크박스
            $html .= "    <td style='padding: 12px; text-align: center; {$few_l}'>";
            $html .= "      <div class='etf-checkbox' data-code='{$etf['etf_code']}' onclick=\"toggleEtfCheck(this, event)\" style='width: 20px; height: 20px; margin: 0 auto; border: 1px solid #ccc; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; cursor: pointer; background: #fff; color: #fff; user-select: none; transition: all 0.2s;'></div>";
            $html .= "    </td>";

            // 기존 데이터 출력
            $html .= "   <td style='padding: 12px; text-align: left; font-weight: bold;'>";
            $html .= "     <a href='javascript:void(0);' onclick=\"{$js_click}\" style='color: #333; text-decoration: none; cursor: pointer;'>{$etf['etf_name']}</a>";
            // ETF명 아래 회색 ETF 코드
            $html .= "     <div style='color:#999; font-size:11px; font-weight:normal; margin-top:2px;'>{$etf['etf_code']}</div>";
            $html .= "   </td>";

            $html .= "    <td style='padding: 12px; color: {$rate_color}; font-weight: bold;'>{$rate_sign}{$etf['etf_rate']}% <br>{$delta_html}</td>";
            $html .= "    <td style='padding: 12px; text-align: right;'>" . number_format($etf['trading_value']) . "</td>";
            $html .= "    <td style='padding: 12px; color: #e67e22; font-weight: bold;'>{$etf['turnover_ratio']}%</td>";
            $html .= "    <td style='padding: 12px;'>{$etf['return_rate']}%</td>";
            $html .= "    <td style='padding: 14px 12px;text-align: right;'>".number_format($etf['market_cap'])."</td>";
            $html .= "    <td style='padding: 14px 12px;'>{$top_rank_display}</td>";
            $html .= "    <td style='padding: 14px 12px; font-weight: 600; color: #0052a4;'>" . number_format($etf['holdings_ratio'], 2) . "%</td>";
            
            $html .= "    <td style='padding: 12px; {$few_r}'>";
            $html .= "        <a href='javascript:void(0);' onclick=\"{$js_click}\" style='color: #333; text-decoration: none; cursor: pointer;'>{$etf['holdings_count']}개</a>";
            $html .= "    </td>";
            
            $html .= "  </tr>";
        }
        // 전부 집중형(30개 이하)이라 전환이 없었던 경우 박스 하단선 마무리
        if ($few_group_open) {
            $html .= "<tr><td colspan='10' style='padding:0; line-height:6px; font-size:0; background:#fffbef; border:1px solid #f3b4bd; border-top:none; border-radius:0 0 12px 12px; box-shadow:0 4px 8px rgba(0,0,0,0.07);'>&nbsp;</td></tr>";
        }
    } else {
        // 💡 데이터가 없을 때 체크박스 컬럼이 추가되었으므로 colspan을 10으로 맞춰줍니다.
        $html .= "  <tr><td colspan='10' style='padding: 20px;'>편입된 ETF 정보가 없습니다.</td></tr>";
    }
	// 🚀 버튼 행을 <tbody>의 가장 마지막에 둡니다.
    $html .= "  <tr id='floating-combine-btn-row' style='display:none;'>
                    <td colspan='3' style='text-align:center; padding:15px; background: #fff5f5;'>
                        <button type='button' id='floating-combine-btn' onclick='openCombinedEtfHoldings()' 
                                style='background: #e1234a; color: #fff; border: none; padding: 10px 20px; border-radius: 5px; font-weight: bold; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2);'>
                            📊 선택한 ETF <span id='selected-count'>0</span>개 합쳐서 보기
                        </button>
                    </td>
                </tr>";

    $html .= "  </tbody></table></div></div>";

    // 🚀 번호를 1,2,3,4로 매겨주는 핵심 자바스크립트 (이게 없으면 작동 안 함!)
// 🚀 번호를 매기고 4개 초과 시 숨겨주는 핵심 자바스크립트
 $html .= "
   <script>
let selectedEtfs = []; 

function toggleEtfCheck(element, event) {
    event.stopPropagation(); 
    
    let code = element.getAttribute('data-code');
    let index = selectedEtfs.indexOf(code);
    let tr = element.closest('tr'); // 클릭한 체크박스의 행(tr)

    if (index > -1) {
        selectedEtfs.splice(index, 1); 
    } else {
        if (selectedEtfs.length >= 4) return; 
        selectedEtfs.push(code); 
    }

    // 🚀 체크박스 UI 업데이트 + 버튼 위치 이동
    updateCheckboxUI(tr);
}

function updateCheckboxUI(lastClickedTr) {
    let allCheckboxes = document.querySelectorAll('.etf-checkbox');
    let btnRow = document.getElementById('floating-combine-btn-row'); // 버튼이 담긴 tr
    let combineBtn = document.getElementById('floating-combine-btn'); // 실제 버튼
    let countSpan = document.getElementById('selected-count'); // 개수 표시 span
    let isMax = (selectedEtfs.length >= 4); // 🚀 [추가] 4개 제한 변수 선언

    // 체크박스 스타일 업데이트
    allCheckboxes.forEach(function(box) {
        let code = box.getAttribute('data-code');
        let idx = selectedEtfs.indexOf(code);

        if (idx > -1) {
            box.style.background = '#e1234a';
            box.style.borderColor = '#e1234a';
            box.innerHTML = (idx + 1);
            box.style.opacity = '1';
            box.style.pointerEvents = 'auto'; 
        } else {
            box.style.background = '#fff';
            box.style.borderColor = '#ccc';
            box.innerHTML = '';
            box.style.opacity = isMax ? '0' : '1';
            box.style.pointerEvents = isMax ? 'none' : 'auto';
        }
    });

 // 🔴 버튼 활성화 및 위치 이동 로직
    if (combineBtn && btnRow) {
        if (selectedEtfs.length >= 2) { // 🚀 2개 이상일 때만 버튼 표시
            combineBtn.disabled = false;
            combineBtn.style.background = '#e1234a';
            combineBtn.style.color = '#fff';
            combineBtn.style.cursor = 'pointer';
            
            btnRow.style.display = 'table-row'; // 버튼 행을 보여줌
            
            // 텍스트 업데이트
            if (countSpan) countSpan.innerText = selectedEtfs.length;

            // 🚀 핵심: 마지막에 체크한 행(tr) 아래로 버튼 행(btnRow)을 옮김
            if (lastClickedTr) {
                lastClickedTr.after(btnRow);
            }
        } else {
            combineBtn.disabled = true;
            combineBtn.style.background = '#cccccc';
            combineBtn.style.color = '#888';
            btnRow.style.display = 'none'; // 버튼 행을 숨김
        }
    }
}

function openCombinedEtfHoldings() {
    if (selectedEtfs.length < 1) return;
    let joinedCodes = selectedEtfs.join(',');
    window.open('" . CUR_PHP . "?mode=ehbe_multi&etf_codes=' + joinedCodes + '&stock_code={$stock_code}', 'etf_d2');
}
</script>
    ";

    echo $html;

################### end of  etf_list_by_stock #######################
}
################### end of  etf_list_by_stock #######################


#################################################################
function stock_list_by_etf($pdo) { #d3
#################################################################
    
    
    global $mobile;

    
    require "./env/e.fnc";

    require_once "./classes/Chart_Helper.class"; // 그래프를 그려주는 함수 모음
require_once "./classes/UI_Helper.class"; // UI 개선 (정렬)

    $GR_Vals = Get_Vals();

    // 1. 넘어온 종목 코드 및 파라미터 받기
    $stock_code = $GR_Vals['stock_code'] ?? '';
    $stock_name = $GR_Vals['stock_name'] ?? '해당';
    $encoded_name = urlencode($stock_name);
    $current_mode = $GR_Vals['mode'] ?? 'slbe'; // 현재 모드 유지

// 💡 등락률 토글 로직
$current_sort = $GR_Vals['sort'] ?? 'rank';
$next_rate_sort = ($current_sort === 'rise') ? 'fall' : 'rise'; 

// 2. 함수 호출을 통해 각 컬럼의 아이콘 변수 할당
$arrow_count = UIHelper::getSortIcon($current_sort, 'count');
$arrow_rate  = UIHelper::getSortIcon($current_sort, 'rate'); // 등락률 
$arrow_rot   = UIHelper::getSortIcon($current_sort, 'rot');  // 회전율




    if ($stock_code) {
        $etfRepo = new StockRepository($pdo);
        
        // 데이터 가져오기
        $related_stocks = $etfRepo->getRelatedStocksByStock($stock_code, $current_sort);
        $stock_count = count($related_stocks);

	    // 차트 표시 갯수 제어 ($chart_stocks 사용 전에 먼저 선언)
		$chart_limit = isset($GR_Vals['limit']) && $GR_Vals['limit'] !== '' ? (int)$GR_Vals['limit'] : 29;

        // 차트에 던져줄 배열만 limit 갯수만큼 자르기 (0이면 전체)
        $chart_stocks = ($chart_limit > 0) ? array_slice($related_stocks, 0, $chart_limit) : $related_stocks;

			$chart_lim_tag = "";
		if ($chart_limit > 0 && $stock_count > $chart_limit) {
			$chart_lim_tag = " <span style='font-size: 13px; color: #e11d48; font-weight: 500;'>| 상위 {$chart_limit}개 종목만 차트 표시</span>";
		}


// 2. HTML 조립 (디자인 개선)
$html = "<div class='board-container' style='padding: 24px; background: #fff; border: 1px solid #e3e6f0; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);'>";

// 타이틀 및 갯수 조절 버튼 컨테이너
$html .= "  <div style='display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px;'>";
$html .= "    <div style='display: flex; align-items: center; gap: 10px;'>";
$html .= "      <h3 style='margin: 0; color: #1e293b; font-size: 20px; letter-spacing: -0.5px;'>";
$html .= "        <span style='color:#e1234a;'>[{$stock_name}]</span> 관련 ETF에 같이 포함된 종목 리스트 ";
$html .= "        <span style='font-size: 14px; color: #7f8c8d; font-weight: 400;'>(총 {$stock_count}개)</span>";
$html .=            $chart_lim_tag;
$html .= "        </span>";
$html .= "      </h3>";
$html .= "    </div>";
$html .= "    </div>";
$html .= "  
<div style='border-radius: 8px; padding: 1px 1px; margin-bottom: 24px; display: flex; align-items: center; font-size: 13px; color: #475569; line-height: 1.5; letter-spacing: -0.3px;'>
                       <span style='font-size: 11px; padding: 2px 6px; background: #ff0000;  border-radius: 4px; font-weight: bold; border: 0px solid #1e3a8a; vertical-align: middle; margin-right: 8px; color:#ffffff;'>💎</span> 

            <span>
                <strong style='color:#1e293b; font-weight: 600; margin-right: 4px;'></strong> 
				ETF에 편입된 종목수가 30개 이하인 테마형/집중투자형 ETF에 포함된 연관 종목리스트
	
            </span>
        </div>";






                // 💡 타이틀 및 갯수 조절 버튼 UI 영역


// 🔴 [초간단 2] 차트를 그리는 헬퍼 함수 1줄 호출 (limit 변수만 넘기면 헬퍼가 자름)
        $treemap_keys = [
            'name'       => 'stock_name',
            'size'       => 'etf_count', 
            'color'      => 'stock_rate',         
            'code'       => 'stock_code',
            'row_prefix' => 'slbe_'               
        ];
        if (empty($_GET['hide_chart']) && empty($_COOKIE['hide_chart'])) {
            $html .= ChartHelper::renderTreemapChart("stock_treemap_{$stock_code}", $related_stocks, $treemap_keys, $chart_limit);
        }

        // 테이블 영역 시작 (테이블은 데이터 유실 방지를 위해 전체 항목을 보여줍니다)
        $html .= "<div style='overflow-x: auto; overflow-y: auto; max-height: 90%; border-bottom: 1px solid #e3e6f0;'>"; 
        $html .= "<table class='title-board-table' style='width: 100%; border-collapse: collapse; text-align: center; font-size: 14px;'>";
        
        $html .= "  <thead>";
        $html .= "    <tr style='color: #4a5568;'>";
        $html .= "      <th style='background-color: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 14px 12px; text-align: left; position: sticky; top: 0 !important; z-index: 11;'>종목명</th>";
        
        $html .= "      <th style='background-color: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 14px 12px; position: sticky; top: 0 !important; z-index: 11;'>
                            <a href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort=count&limit={$chart_limit}' style='color: #4a5568; text-decoration: none;'>ETF 갯수<span style='color:#e1234a;'>{$arrow_count}</span></a>
                        </th>";
        
        $html .= "      <th style='background-color: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 14px 12px; position: sticky; top: 0 !important; z-index: 11;'>
                            <a href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort={$next_rate_sort}&limit={$chart_limit}' style='color: #4a5568; text-decoration: none;'>등락률<span style='color:#e1234a;'>{$arrow_rate}</span></a>
                        </th>";
                        
        $html .= "      <th style='background-color: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 14px 12px; position: sticky; top: 0 !important; z-index: 11;'>
                            <a href='" . CUR_PHP . "?mode={$current_mode}&stock_code={$stock_code}&stock_name={$encoded_name}&sort=rot&limit={$chart_limit}' style='color: #4a5568; text-decoration: none;'>회전율<span style='color:#e1234a;'>{$arrow_rot}</span></a>
                        </th>";
                        
        $html .= "      <th style='background-color: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 14px 12px; text-align: right; position: sticky; top: 0 !important; z-index: 11;'>거래대금(억)</th>";
        
        $html .= "    </tr>";
        $html .= "  </thead>";
        $html .= "  <tbody>";

        if (!empty($related_stocks)) {
            $tt = 0;
            foreach ($related_stocks as $row) {

				$tt++;
				if ($chart_limit > 0 && $tt > $chart_limit)         break;

				$stock_summary = StockSummaryCache::getInfo($row['stock_code']);
                $etf_badge = UIHelper::get_etf_badge_html($stock_summary['top_rank_count'],$stock_summary['etf_count']);
	   
                $encoded_row_name = urlencode($row['stock_name']);
                $params = "stock_code={$row['stock_code']}&stock_name={$encoded_row_name}";

                $is_target = ($row['stock_code'] === $stock_code);

                $row_id = "slbe_" . $row['stock_code'];
                $html .= "  <tr id='{$row_id}' style='border-bottom: 1px solid #f2f2f2;'>";

                $js_click = "openCommonFrames('{$row_id}', '{$row['stock_code']}', '{$params}', ['etf_t1','etf_d1', 'etf_d5'], '" . CUR_PHP . "');";

                $html .= "    <td style='padding: 14px 12px; text-align: left; font-weight: bold;'>";
                $html .= "      <a href='javascript:void(0);' onclick=\"{$js_click}\" style='color: #333; text-decoration: none; cursor: pointer;'>";
                $html .= "        {$row['stock_name']}";
                
                if ($is_target) {
                    $html .= " <span style='color: #e1234a; font-size: 12px;'>★</span>";
                }
                
                $html .= "      </a>";        
                $html .= "    </td>";

                $html .= "    <td style='padding: 14px 12px; color: #4a5568;'>".$etf_badge."</td>";
                $html .= "    <td style='padding: 14px 12px;  font-weight: 700;'>".deco_txt($row['stock_rate'],111)."</td>";
                $html .= "    <td style='padding: 14px 12px; color: #dd6b20; font-weight: 600;'>" . number_format($row['stock_rot'], 2) . "%</td>";
                $html .= "    <td style='padding: 14px 12px; text-align: right; color: #2d3748; font-weight: 500;'>" . number_format($row['stock_vol_cap']) . "</td>";
                
                $html .= "  </tr>";
            }
        } else {
            $html .= "  <tr><td colspan='5' style='padding: 40px 20px; color: #a0aec0; text-align: center;'>함께 포함된 종목 정보가 없습니다.</td></tr>";
        }

        $html .= "  </tbody>";
        $html .= "</table>";
        $html .= "</div>"; 
        $html .= "</div>"; 

        echo $html;

    } # end if
################### end of  stock_list_by_etf #######################
}
################### end of  stock_list_by_etf #######################

#################################################################
function etf_holdings_by_etf_multi($pdo) { # 🚀 매트릭스(교차표) 형태의 ETF 통합 분석
#################################################################
    
    
    global $mobile;

    
    require "./env/e.fnc";
    require_once "./classes/Chart_Helper.class"; // 💡 공통 차트 헬퍼 추가
    
    $GR_Vals = Get_Vals();
    $etf_codes_str = $GR_Vals['etf_codes'] ?? '';
    $stock_code = $GR_Vals['stock_code'] ?? ''; // 부모창에서 넘어온 기준 종목코드
    $current_mode = $GR_Vals['mode'] ?? ''; 
    


    if (!$etf_codes_str) {
        echo "<div style='padding:20px;'>선택된 ETF가 없습니다.</div>";
        exit;
    }
    
    $etf_codes_array = explode(',', $etf_codes_str);
    $selected_count = count($etf_codes_array);

    $etfRepo = new StockRepository($pdo);
    
    $etf_names = $etfRepo->getEtfNamesByCodes($etf_codes_array);
    $raw_holdings = $etfRepo->getRawHoldingsForEtfs($etf_codes_array);

    // 데이터 피벗 조립
    $matrix = [];
    foreach ($raw_holdings as $row) {
        $sCode = $row['stock_code'];
        if (!isset($matrix[$sCode])) {
            $matrix[$sCode] = [
                'stock_code' => $row['stock_code'], 
                'stock_name' => $row['stock_name'],
                'stock_rate' => $row['stock_rate'],
                'total_ratio' => 0,
                'ratios' => []
            ];
        }
        $matrix[$sCode]['ratios'][$row['etf_code']] = $row['holdings_ratio'];
        $matrix[$sCode]['total_ratio'] += $row['holdings_ratio'];
    }

    // 비중 합계 내림차순 정렬
    usort($matrix, function($a, $b) {
        return $b['total_ratio'] <=> $a['total_ratio'];
    });

    $stock_count = count($matrix);

    // 🔴 [핵심 추가] 차트 표시 갯수 제어
    $chart_limit = isset($GR_Vals['limit']) && $GR_Vals['limit'] !== '' ? (int)$GR_Vals['limit'] : 30;

	$chart_lim_tag = "";
if ($chart_limit > 0 && $stock_count > $chart_limit) {
    $chart_lim_tag = " <span style='font-size: 13px; color: #e11d48; font-weight: 500;'>| 상위 {$chart_limit}개 종목만 차트 표시</span>";
}

// 2. HTML 조립 (디자인 개선)
$html = "<div class='board-container' style='padding: 24px; background: #fff; border: 1px solid #e3e6f0; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);'>";

// 타이틀 및 갯수 조절 버튼 컨테이너
$html .= "  <div style='display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px;'>";
$html .= "    <div style='display: flex; align-items: center; gap: 10px;'>";
$html .= "      <span style='font-size: 24px;'>📊</span>";
$html .= "      <h3 style='margin: 0; color: #1e293b; font-weight: 800; font-size: 20px; letter-spacing: -0.5px;'>";
$html .= "        ETF 비교 분석";
$html .= "        <span style='font-size: 15px; color: #64748b; font-weight: 400; margin-left: 8px;'>";
$html .= "          {$selected_count}개 ETF / 총 {$stock_count} 종목";
$html .=            $chart_lim_tag;
$html .= "        </span>";
$html .= "      </h3>";
$html .= "    </div>";
$html .= "    </div>";
    
    // 트리맵 렌더링 함수 호출
    $treemap_keys = [
        'name'       => 'stock_name',
        'size'       => 'total_ratio', // 📦 크기: 비중 합계
        'color'      => 'stock_rate',  // 🎨 색상: 종목 등락률
        'code'       => 'stock_code',
        'row_prefix' => 'multi_'       // 차트 클릭 시 테이블 행으로 이동하기 위한 ID 접두사
    ];
    
    if (empty($_GET['hide_chart']) && empty($_COOKIE['hide_chart'])) {
        $html .= ChartHelper::renderTreemapChart("multi_treemap", $matrix, $treemap_keys, $chart_limit);
    }




    // 🚀 테이블 출력 영역 (가로/세로 스크롤) - 테이블은 전체 데이터를 보여줍니다.
    $html .= "<div style='overflow-x: auto; overflow-y: auto; max-height: 90%; border-bottom: 1px solid #e3e6f0;'>"; 

    $html .= "<table style='width: 100%; border-collapse: collapse; text-align: center; font-size: 14px;'>";
    
    // 테이블 헤더
    $html .= "<thead>
                <tr>
                    <th style='background: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 14px 12px; text-align: left; min-width: 120px; position: sticky; top: 0 !important; z-index: 11;'>종목명</th>";

    $html .= "      <th style='background: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 14px 12px; text-align: left; position: sticky; top: 0 !important; z-index: 11;'>등락률</th>";
                    
    foreach ($etf_codes_array as $code) {
        $name = $etf_names[$code] ?? $code;
        $html .= "  <th style='background: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 10px 8px; width: 100px; vertical-align: middle; position: sticky; top: 0 !important; z-index: 11;' title='{$name}'>";
        $html .= "      <div style='display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis; white-space: normal; word-break: keep-all; line-height: 1.4; font-size: 13px; margin: 0 auto; max-width: 110px;'>{$name}</div>";
        $html .= "  </th>";
    }
    
    $html .= "      <th style='background: #f8f9fc; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0; padding: 14px 12px; color: #e1234a; min-width: 80px; position: sticky; top: 0 !important; z-index: 11;'>비중(합계)</th>
                </tr>
              </thead><tbody>";

    if (!empty($matrix)) {
        foreach ($matrix as $item) {
			$tt++;
			
			if ($chart_limit > 0 && $tt > $chart_limit)         break; 

            $is_target = ($item['stock_code'] === $stock_code);
            $target_class = $is_target ? "row-highlight" : "";
            
            // 트리맵 클릭 연동을 위해 tr 태그에 id 부여 (row_prefix와 동일하게)
            $row_id = "multi_" . $item['stock_code'];

            // 등락률 색상 계산
            $rate = (float)$item['stock_rate'];
            $rate_color = ($rate > 0) ? '#e1234a' : (($rate < 0) ? '#0052a4' : '#4a5568');

            $html .= "<tr id='{$row_id}' style='border-bottom: 1px solid #f2f2f2;' class='{$target_class}'>";
            
            // 종목명
            $html .= "  <td style='padding: 12px; text-align: left; font-weight: bold; color: #333;'>";
            $html .= "      {$item['stock_name']}";

            if ($is_target) {
                $html .= " <span style='background: #e1234a; color: #fff; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 11px; margin-left: 5px;'>기준</span>";
            }
            $html .= "  </td>";

            // 등락률
            $html .= "  <td style='padding: 12px; text-align: left; font-weight: bold; color: {$rate_color}'>";
            $html .=        deco_txt($item['stock_rate'],111) . "</td>";

            // ETF별 비중
            foreach ($etf_codes_array as $code) {
                $ratio = $item['ratios'][$code] ?? 0;
                if ($ratio > 0) {
                    $html .= "  <td style='padding: 12px; color: #4a5568; font-weight: 600;'>" . number_format($ratio, 2) . "%</td>";
                } else {
                    $html .= "  <td style='padding: 12px; color: #cbd5e0;'>0%</td>";
                }
            }
            
            // 합계
            $html .= "  <td style='padding: 12px; color: #e1234a; font-weight: bold;'>" . number_format($item['total_ratio'], 2) . "%</td>";
            $html .= "</tr>";
        }
    } else {
        $colspan = $selected_count + 3; 
        $html .= "<tr><td colspan='{$colspan}' style='padding: 40px; color: #888;'>분석할 데이터가 없습니다.</td></tr>";
    }

    $html .= "</tbody></table></div></div>";
    
    echo $html;
#################################################################
}
#################################################################


#################################################################
function etf_holdings_by_etf($pdo) {  # 특정 ETF에 편입된 종목 리스트
#################################################################
    
    
    global $mobile;

    
    require "./env/e.fnc";
    
require_once "./classes/Chart_Helper.class"; // 그래프를 그려주는 함수 모음
require_once "./classes/UI_Helper.class"; // UI 개선 (정렬)


    $GR_Vals = Get_Vals();

    // 1. 넘어온 ETF 코드 및 이름 받기
    $etf_code = $GR_Vals['etf_code'] ?? '';
    $etf_name = $GR_Vals['etf_name'] ?? '해당';
    $stock_code = $GR_Vals['stock_code'] ?? '';

    // 멀티 종목 선택 상태로 넘어온 경우 — 해당 종목들 표시용
    $multi_codes_str = $GR_Vals['multi_stock_codes'] ?? '';
    $multi_codes = $multi_codes_str ? array_flip(explode(',', $multi_codes_str)) : [];

    $encoded_etf_name = urlencode($etf_name);
    $current_mode = $GR_Vals['mode'] ?? 'slbe';

$current_sort = $GR_Vals['sort'] ?? 'ratio';
$next_rate_sort = ($current_sort === 'rise') ? 'fall' : 'rise'; // 등락률 토글용

// 2. 함수 호출을 통해 각 컬럼의 아이콘 변수 할당
$arrow_ratio = UIHelper::getSortIcon($current_sort, 'ratio');
$arrow_rate  = UIHelper::getSortIcon($current_sort, 'rate'); // 등락률 
$arrow_rot   = UIHelper::getSortIcon($current_sort, 'rot');  // 회전율





    if ($etf_code) {

        $etfRepo = new StockRepository($pdo);

        // 🚀 최근 본 ETF 기록 (종목 최근조회와 동일 패턴 / etf_code UNIQUE)
        $etfRepo->saveRecentEtf($etf_code, $etf_name);

        // 데이터 가져오기
        $related_stocks = $etfRepo->getStocksInSpecificEtf($etf_code, $current_sort);
        $stock_count = count($related_stocks);


       $chart_limit = isset($GR_Vals['limit']) && $GR_Vals['limit'] !== '' ? (int)$GR_Vals['limit'] : 29;

	$chart_lim_tag = "";
if ($chart_limit > 0 && $stock_count > $chart_limit) {
    $chart_lim_tag = " <span style='font-size: 13px; color: #e11d48; font-weight: 500;'>| 상위 {$chart_limit}개 종목만 차트 표시</span>";
}


echo iframe_base_css(99, false);


        // 4. HTML 게시판 조립 시작 
        $html = "<div class='board-container' style='margin: 5px 5px; margin-bottom: 10px;background: #ffffff; border: 0px solid #e3e6f0; border-radius: 8px; overflow: hidden; padding: 10px;'>";
        
       // [상단 고정 영역 시작]
    $html .= "<div style='flex-shrink: 0;'>";
    $html .= "<h3 style='font-size: 18px; color: #2c3e50; margin-top: 0; margin-bottom: 10px; border-left: 5px solid #e1234a; padding-left: 12px; font-weight: 700;'>";
    $html .= "  <span style='color:#e1234a;'>[{$etf_name}({$etf_code}) </span> 구성 종목 리스트 ";
    $html .= "  <span style='font-size: 14px; color: #7f8c8d; font-weight: 400;'>(총 {$stock_count}개)</span>";
    $html .=    $chart_lim_tag;
    $html .= "</h3>";
    
    $treemap_keys = [
        'name'       => 'stock_name',
        'size'       => 'holdings_ratio', 
        'color'      => 'stock_rate',     
        'code'       => 'stock_code',
        'row_prefix' => 'ehbe_'            
    ];
    
    if (empty($_GET['hide_chart']) && empty($_COOKIE['hide_chart'])) {
        $html .= ChartHelper::renderTreemapChart("stock_treemap_{$etf_code}", $related_stocks, $treemap_keys, $chart_limit);
    }
$html .= "</div>";
// [상단 고정 영역 끝]

// [하단 스크롤 영역 시작]
    $html .= "<div style='overflow-x: auto; overflow-y: auto; max-height: 100%; border-bottom: 1px solid #e3e6f0;'>"; 
    $html .= "<table class='title-board-table' style='width: 100%; border-collapse: collapse; font-size: 14px; text-align: center;'>";
    
    // 테이블 헤더 (고정)
    $html .= "  <thead style='position: sticky; top: 0; z-index: 10; background: #fff;'>";
    $html .= "    <tr style='background-color: #f8f9fc; color: #4a5568; border-bottom: 2px solid #cbd5e0; border-top: 1px solid #e2e8f0;'>";
    $html .= "      <th style='padding: 14px 12px; text-align: left;'>종목명</th>";
    
	$html .= "      <th style='padding: 14px 12px;'><a href='" . CUR_PHP . "?mode={$current_mode}&etf_code={$etf_code}&etf_name={$encoded_etf_name}&sort={$next_rate_sort}' style='color: #4a5568; text-decoration: none;' class='sort-header-link'>등락률<span style='color:#e1234a;'>{$arrow_rate}</span></a></th>";

    // 💡 [수정된 부분] 보유금액, 보유비중을 하나로 합치고 2줄로 표현
    $html .= "      <th style='padding: 10px 12px; line-height: 1.4;'>ETF보유<br><span style='font-size: 12px; font-weight: normal; color: #718096;'>금액(억), 비중</span></th>";
    
    $html .= "      <th style='padding: 14px 12px;'><a href='" . CUR_PHP . "?mode={$current_mode}&etf_code={$etf_code}&etf_name={$encoded_etf_name}&sort=ratio' style='color: #4a5568; text-decoration: none;'>편입비중<span style='color:#e1234a;'>{$arrow_ratio}</span></a></th>";

    $html .= "      <th style='padding: 14px 12px;'><a href='" . CUR_PHP . "?mode={$current_mode}&etf_code={$etf_code}&etf_name={$encoded_etf_name}&sort=rot' style='color: #4a5568; text-decoration: none;'>회전율<span style='color:#e1234a;'>{$arrow_rot}</span></a></th>";
    $html .= "      <th style='padding: 14px 12px; text-align: right;'>거래대금</th>";
    $html .= "    </tr>";
    $html .= "  </thead>";
    $html .= "  <tbody>";


        if (!empty($related_stocks)) {
            $tt = 0; 
            foreach ($related_stocks as $row) {
                $tt++;

				if ($chart_limit > 0 && $tt > $chart_limit)         break; 
                   
                $rate_color = ($row['stock_rate'] > 0) ? '#e1234a' : (($row['stock_rate'] < 0) ? '#0052a4' : '#4a5568');
                $rate_sign  = ($row['stock_rate'] > 0) ? '+' : '';

				$etf_badge = UIHelper::get_etf_badge_html($row['top_rank_count'],$row['etf_count']);

                $encoded_name = urlencode($row['stock_name']);
                $params = "stock_code={$row['stock_code']}&stock_name={$encoded_name}";

                $is_target   = ($row['stock_code'] === $stock_code);
                $is_selected = isset($multi_codes[$row['stock_code']]); // 멀티 선택 종목 여부
                $row_id = "ehbe_" . $row['stock_code'];
                $target_class = ($is_target || $is_selected) ? "row-highlight" : "";

                // 멀티 선택 종목은 행 배경을 옅게 강조
                $row_bg = $is_selected ? "background:#fff7ed;" : "";
                $html .= "  <tr id='{$row_id}' class='{$target_class}' style='border-bottom: 1px solid #edf2f7; {$row_bg}'>";

                $js_click = "var rows=document.querySelectorAll('.title-board-table tbody tr'); rows.forEach(function(r){r.classList.remove('row-highlight');}); document.getElementById('{$row_id}').classList.add('row-highlight'); openCommonFrames('{$row_id}', '{$row['stock_code']}', '{$params}', ['etf_t1','etf_d1','etf_d2', 'etf_d5'], '" . CUR_PHP . "');";

                $html .= "    <td style='padding: 14px 12px; text-align: left; white-space: nowrap;'>";
                $html .= "      <a href='javascript:void(0);'
                                   onclick=\"{$js_click}\"
                                   style='color: #2d3748; font-weight: 600; text-decoration: none; cursor: pointer;'
                                   onmouseover=\"this.style.textDecoration='underline'; this.style.color='#0052a4';\"
                                   onmouseout=\"this.style.textDecoration='none'; this.style.color='#2d3748';\">";

                $html .= "       {$row['stock_name']}";

                // ETF 편입수 배지 (기존 컬럼 → 종목명 옆으로 이동)
                $html .= " {$etf_badge}";

                // 강조 종목(원점 타겟 또는 멀티선택)은 편입비중 순위를 빨간 원형 배지로 표시
                if ($is_target || $is_selected) {
                    $html .= " <span style='display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 5px; box-sizing:border-box; background:#e1234a; color:#fff; border-radius:10px; font-weight:bold; font-size:11px; margin-left:6px; vertical-align:middle;'>{$tt}</span>";
                }

                $html .= "      </a>";
                $html .= "    </td>";

                $html .= "    <td style='padding: 14px 12px; color: {$rate_color}; font-weight: 700;'>{$rate_sign}" . number_format((float)$row['stock_rate'], 2) . "%</td>";

				$html .= "<td style='line-height: 1.4;'>".number_format($row['etf_ownership_cap'])."<br><span style='font-size: 12px; color: #718096;'>" . number_format($row['etf_ownership_ratio'], 2) . "%</span></td>";

                $html .= "    <td style='padding: 14px 12px; color: #4e73df; font-weight: bold;'>" . number_format($row['holdings_ratio'], 2) . "</td>";

                $html .= "    <td style='padding: 14px 12px; color: #dd6b20; font-weight: 600;'>" . number_format($row['stock_rot'], 2) . "%</td>";
                
                $html .= "    <td style='padding: 14px 12px; text-align: right; color: #2d3748; font-weight: 500;'>" . number_format($row['stock_vol_cap']) . "</td>";
                
                $html .= "  </tr>";
            }
        } else {
            $html .= "  <tr><td colspan='6' style='padding: 40px 20px; color: #a0aec0; text-align: center;'>편입된 종목 정보가 없습니다.</td></tr>";
        }

        $html .= "  </tbody>";
        $html .= "</table>";
        $html .= "</div>";
$html .= "</div>"; // [하단 스크롤 영역 끝]

        echo $html;
    }
}
// 끝: etf_holdings_by_etf 함수
#################################################################


#################################################################
function thema_list($pdo) {
#################################################################
    
    
    global $mobile;

    
    require "./env/e.fnc";
    
    $GR_Vals = Get_Vals();

    // 1. 화면 출력을 위한 전체 데이터 먼저 로드 (삭제 전 위치 탐색을 위해 필요)
    $target_stock_code = $_REQUEST['stock_code'] ?? '';
    $themaRepo = new StockRepository($pdo);
    $theme_list = $themaRepo->getThemaListWithStocks($target_stock_code);

    // 🔴 테마 삭제 가로채기 로직 (순서 고정 + 해시태그 이동 방식)
    if (isset($_REQUEST['mode']) && $_REQUEST['mode'] === 'tl' && isset($_REQUEST['mode_del']) && $_REQUEST['mode_del'] === 'yes' && isset($_REQUEST['thema_no'])) {
        $del_thema_no = (int)$_REQUEST['thema_no'];
        
        // 💡 [핵심 UX] 삭제 후 "이동만" 할 대상 테마의 ID 찾기
        $target_hash_no = '';
        
        $keys = array_keys($theme_list);
        $total_themes = count($keys);
        
        for ($i = 0; $i < $total_themes; $i++) {
            $current_theme_name = $keys[$i];
            
            if ((int)$theme_list[$current_theme_name]['thema_no'] === $del_thema_no) {
                // 1순위: 내 바로 위(이전) 테마의 번호를 타겟 해시로 저장
                if ($i > 0) {
                    $prev_theme_name = $keys[$i - 1];
                    $target_hash_no = $theme_list[$prev_theme_name]['thema_no'];
                } 
                // 2순위: 위가 없으면(내가 첫번째면) 아래 테마 번호를 저장
                elseif ($i < $total_themes - 1) {
                    $next_theme_name = $keys[$i + 1];
                    $target_hash_no = $theme_list[$next_theme_name]['thema_no'];
                }
                break;
            }
        }

        // 실제 DB에서 삭제 수행
        if ($themaRepo->deleteThema($del_thema_no)) {
            // 💡 중요: 리스트가 뒤바뀌지 않도록 stock_code 파라미터를 절대 붙이지 않고 기본 주소로 갑니다.
            $redirect_url = "" . CUR_PHP . "?mode=tl";
            
            // 💡 오직 화면 위치만 바로 전 테마로 점프하도록 해시태그만 결합합니다.
            if ($target_hash_no !== '') {
                $redirect_url .= "#theme_card_" . $target_hash_no;
            }
            
            echo "<script>
                location.href='{$redirect_url}';
            </script>";
        } else {
            echo "<script>alert('삭제 중 오류가 발생했습니다.'); location.href='" . CUR_PHP . "?mode=tl';</script>";
        }
        exit;
    }



    // 2. 화면 출력 시작
    echo "<div style='display: flex; flex-direction: column; gap: 15px; padding:20px; background-color: #f8f9fc; font-family: \"Pretendard\", \"Malgun Gothic\", sans-serif; min-height: 100vh;'>";

    if (empty($theme_list)) {
        echo "<div style='text-align: center; padding: 50px; color: #a0aec0; font-size: 16px; background: #fff; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.02);'>텅~ 등록된 테마 정보가 없습니다.</div>";
    } else {
        
        // 💡 만약 일반적인 클릭으로 들어온 경우에만 우측 프레임 자동 실행을 유지합니다.
        if (isset($_REQUEST['auto_click']) && $_REQUEST['auto_click'] === 'yes' && $target_stock_code !== '') {
            $row_id = "row_" . $target_stock_code;
            $params = "stock_code=" . $target_stock_code;
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof openCommonFrames === 'function') {
                        openCommonFrames('{$row_id}', '{$target_stock_code}', '{$params}', ['etf_t1','etf_d1', 'etf_d2',  'etf_d5'], '" . CUR_PHP . "');
                    }
                });
            </script>";
        }

        foreach ($theme_list as $theme_name => $theme_data) {
            
            $avg_rate = $theme_data['avg_rate'];
            
            if ($avg_rate > 0) {
                $avg_color = '#e1234a';
                $avg_sign = '+';
            } elseif ($avg_rate < 0) {
                $avg_color = '#1a73e8';
                $avg_sign = '';
            } else {
                $avg_color = '#64748b';
                $avg_sign = '';
            }

            $card_border = $theme_data['has_target'] ? "border: 2px solid #1a73e8;" : "border: none;";

            $thema_no = $theme_data['thema_no'];
            $delete_js = "if(confirm('테마를 삭제하시겠습니까?')) { location.href='" . CUR_PHP . "?mode=tl&mode_del=yes&thema_no={$thema_no}'; }";

            $etf_included = [];
            $etf_excluded = [];
            foreach ($theme_data['stocks'] as $stock) {
                if ($stock['etf_count'] > 0) {
                    $etf_included[] = $stock;
                } else {
                    $etf_excluded[] = $stock;
                }
            }

            // 💡 해시태그가 정확히 타겟팅할 수 있도록 고유 ID를 부여합니다.
            echo "
            <div id='theme_card_{$thema_no}' style='background: #ffffff; {$card_border} border-radius: 16px; padding: 24px 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.03);'>
                
                <div style='display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9;'>
                    <div style='display: flex; align-items: center;'>
                        <span onclick=\"{$delete_js}\" title='테마 삭제' 
                              style='font-size: 22px; margin-right: 10px; cursor: pointer; transition: transform 0.2s;' 
                              onmouseover=\"this.style.transform='scale(1.2)'\" 
                              onmouseout=\"this.style.transform='scale(1)'\">🔥</span>
                              
                        <h3 style='margin: 0; color: #1e293b; font-size: 19px; font-weight: 800; letter-spacing: -0.5px;'>
                            {$theme_name}
                        </h3>
                    </div>
                    
                    <div style='background: #f8f9fa; padding: 6px 14px; border-radius: 20px; font-weight: 800; font-size: 16px; color: {$avg_color}; box-shadow: inset 0 1px 3px rgba(0,0,0,0.05);'>
                        AVG {$avg_sign}{$avg_rate}%
                    </div>
                </div>
                
                <div style='display: flex; flex-wrap: wrap; gap: 20px; align-items: stretch;'>
                    
                    <div style='flex: 1; min-width: 320px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;'>
                        <div style='font-size: 13px; font-weight: 800; color: #3b82f6; margin-bottom: 12px; display: flex; align-items: center; gap: 5px;'>
                            <span>🔵 ETF 편입 종목</span>
                            <span style='background: #dbeafe; color: #1d4ed8; padding: 2px 8px; border-radius: 10px; font-size: 11px;'>".count($etf_included)."</span>
                        </div>
                        <div style='display: flex; flex-wrap: wrap; gap: 10px;'>
            ";

            if (count($etf_included) > 0) {
                foreach ($etf_included as $stock) {
                    $row_id = "row_" . $stock['stock_code'];
                    $params = "stock_code=" . $stock['stock_code']."&stock_name=" . $stock['stock_name']; 
                    $js_click = "openCommonFrames('{$row_id}', '{$stock['stock_code']}', '{$params}', ['etf_t1','etf_d1', 'etf_d2',  'etf_d5'], '" . CUR_PHP . "');";

                    // 💡 [히트맵 추가] 수익률(0~30%)에 따른 붉은색 배경 투명도 계산
                    $rate = (float)$stock['stock_rate'];
                    $intensity = max(0, min(30, $rate)) / 30; // 0.0 (0%) ~ 1.0 (30% 이상)

                    if ($rate > 0) {
                        $alpha = 0.05 + ($intensity * 0.45); // 최소 0.05 ~ 최대 0.5 투명도의 붉은색
                        $chip_bg = "rgba(239, 68, 68, {$alpha})";
                        $chip_border = "rgba(239, 68, 68, 0.5)"; // 살짝 진한 붉은 테두리
                    } else {
                        $chip_bg = '#ffffff'; // 하락/보합 종목은 흰색 유지
                        $chip_border = '#cbd5e1';
                    }

                    // 선택된 타겟 종목 강조 처리 (테두리를 두껍게 파란색으로 덮어씀)
                    $is_target = ($target_stock_code !== '' && $stock['stock_code'] === $target_stock_code);
                    $border_css = $is_target ? "12px solid red" : "1px solid {$chip_border}";

                    // ETF 갯수에 비례하여 폰트 크기 동적 계산 (최소 13px ~ 최대 22px)
                    $etf_c = $stock['etf_count'];
                    $dynamic_font_size = max(13, min(22, 12 + ceil($etf_c / 3))); 

                    echo "
                        <div style='background: {$chip_bg}; border: {$border_css}; border-radius: 30px; padding: 6px 14px; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: all 0.2s ease;'
                             onmouseover=\"this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 10px rgba(0,0,0,0.08)';\"
                             onmouseout=\"this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.02)';\">
                            
                            <a href='javascript:void(0);' onclick=\"{$js_click}\" style='text-decoration: none; display: flex; align-items: center; gap: 4px;'>
                                <span style='font-weight: 800; font-size: {$dynamic_font_size}px; color: #1e293b; cursor: pointer; letter-spacing: -0.5px;'>{$stock['stock_name']}</span>
                                <span style='font-size: 11px; color: #64748b;'>({$etf_c})</span>
                            </a>
                            <span style='font-weight: 800; font-size: 13px; margin-left: 4px;'>".deco_txt($stock['stock_rate'],111)."</span>
                        </div>
                    ";
                }
            } else {
                echo "<div style='color: #94a3b8; font-size: 13px; padding: 10px;'>편입된 종목이 없습니다.</div>";
            }

            echo "
                        </div>
                    </div>

                    <div style='flex: 1; min-width: 320px; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 16px;'>
                        <div style='font-size: 13px; font-weight: 800; color: #94a3b8; margin-bottom: 12px; display: flex; align-items: center; gap: 5px;'>
                            <span>⚪ 미편입 종목</span>
                            <span style='background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 10px; font-size: 11px;'>".count($etf_excluded)."</span>
                        </div>
                        <div style='display: flex; flex-wrap: wrap; gap: 10px;'>
            ";

            if (count($etf_excluded) > 0) {
                foreach ($etf_excluded as $stock) {
                    $is_target = ($target_stock_code !== '' && $stock['stock_code'] === $target_stock_code);
                    $chip_bg = $is_target ? '#eff6ff' : '#f8fafc';
                    $chip_border = $is_target ? '#3b82f6' : '#e2e8f0';
                    
                    $row_id = "row_" . $stock['stock_code'];
                    $params = "stock_code=" . $stock['stock_code']; 
                    $js_click = "openCommonFrames('{$row_id}', '{$stock['stock_code']}', '{$params}', ['etf_t1','etf_d1', 'etf_d2',  'etf_d5'], '" . CUR_PHP . "');";

                    echo "
                        <div style='background: {$chip_bg}; border: 1px solid {$chip_border}; border-radius: 30px; padding: 6px 14px; font-size: 13px; display: flex; align-items: center; gap: 6px; transition: all 0.2s ease;'
                             onmouseover=\"this.style.transform='translateY(-2px)'; this.style.borderColor='#cbd5e1';\"
                             onmouseout=\"this.style.transform='translateY(0)'; this.style.borderColor='{$chip_border}';\">
                            
                            <a href='javascript:void(0);' onclick=\"{$js_click}\" style='text-decoration: none; display: flex; align-items: center;'>
                                <span style='font-weight: 700; color: #94a3b8; cursor: pointer;'>{$stock['stock_name']}</span>
                            </a>
                            <span style='font-weight: 800; font-size: 13px; margin-left: 4px; filter: grayscale(40%); opacity: 0.8;'>".deco_txt($stock['stock_rate'],111)."</span>
                        </div>
                    ";
                }
            } else {
                echo "<div style='color: #cbd5e1; font-size: 13px; padding: 10px;'>미편입 종목이 없습니다.</div>";
            }

            echo "
                        </div>
                    </div>
                    
                </div>
            </div>
            ";
        }
    }

    echo "</div>";

#################################################################
}
// 끝: Thema_List 함수
#################################################################





#################################################################
// highlight_keyword() / get_keyword_news_by_naver() 는 env/keyword_news.inc 로 이동
// (공개 뷰어 keyword_news.php 와 공유). 상단에서 require_once 됨.
#################################################################
function get_stock_news_by_naver() {
#################################################################



global $mobile;
require "./env/e.fnc";

$api = new NaverFinanceAPI();
$GR_Vals = Get_Vals();
$stock_code = $GR_Vals['stock_code'] ?? '';
$stock_name = $GR_Vals['stock_name'] ?? '';

$news_data = $api->getNaverFinanceNews($stock_code);



// 🚀 1. 뉴스 전용 CSS 스타일 (화면 상단이나 <head>에 한 번만 선언되면 좋습니다)
$html_news = "<style>
    .news-widget { background: #ffffff; border: 1px solid #e3e6f0; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); padding: 20px; font-family: 'Malgun Gothic', 'Apple SD Gothic Neo', sans-serif; margin: 20px 0; }
    .news-header { font-size: 18px; color: #2c3e50; margin-top: 0; margin-bottom: 20px; border-left: 5px solid #0052a4; padding-left: 12px; font-weight: 700; }
    
    /* 기사 박스 전체를 클릭할 수 있게 만듭니다 */
    .news-item { display: flex; flex-direction: column; padding: 14px 10px; border-bottom: 1px solid #edf2f7; transition: background-color 0.2s ease; text-decoration: none; cursor: pointer; }
    .news-item:last-child { border-bottom: none; }
    
    /* 마우스 호버 시 아이스 블루 배경색 */
    .news-item:hover { background-color: #f4f6f9; }
    
    .news-meta { display: flex; align-items: center; font-size: 12px; color: #7f8c8d; margin-bottom: 8px; }
    
    /* 언론사 이름 뱃지(Badge) 스타일 */
    .news-publisher { font-weight: 600; color: #4e73df; background: #eaecf4; padding: 3px 8px; border-radius: 4px; margin-right: 10px; font-size: 11px; letter-spacing: -0.5px; }
    
    /* 뉴스 제목 스타일 */
    .news-title { font-size: 15px; color: #2d3748; font-weight: 600; line-height: 1.4; word-break: break-all; transition: color 0.2s ease; }
    
    /* 마우스 호버 시 제목 글자색을 파란색으로 변경 */
    .news-item:hover .news-title { color: #0052a4; text-decoration: underline; }
</style>";

// 🚀 2. HTML 조립 시작
$html_news .= "<div class='news-widget'>";

// 타이틀
// (만약 $stock_name 변수가 없다면 $stock_code로 대체하셔도 됩니다)
$display_name = $stock_name ?? $stock_code; 
$html_news .= "<h3 class='news-header'><span style='color:#0052a4;'>[{$display_name}]</span> 실시간 증권 속보</h3>";

$html_news .= "<div class='news-list'>";

if (!empty($news_data)) {
    foreach ($news_data as $news) {



$html_news .= "<a href='{$news['link']}'
                  onclick=\"window.open('{$news['link']}', 'news_popup', 'width=800,height=900,left=200,top=100,scrollbars=yes'); return false;\" 
                  class='news-item'>";
        
        // 날짜와 언론사 정보 (위쪽)
        $html_news .= "  <div class='news-meta'>";
        $html_news .= "    <span class='news-publisher'>{$news['info']}</span>";
        $html_news .= "    <span class='news-date'>{$news['date']}</span>";
        $html_news .= "  </div>";
        
        // 뉴스 제목 (아래쪽)
        $html_news .= "  <div class='news-title'>" . highlight_keyword($news['title'], $stock_name) . "</div>";
        
        $html_news .= "</a>";
    }
} else {
    // 뉴스가 없을 경우의 안전장치
    $html_news .= "<div style='padding: 40px 20px; text-align: center; color: #a0aec0; font-size: 14px;'>최근 관련된 뉴스가 없습니다.</div>";
}

$html_news .= "</div>"; // end of news-list
$html_news .= "</div>"; // end of news-widget

// 최종 출력
echo $html_news;


#################################################################
}
#################################################################


#################################################################
function api_find_stock($pdo) {
#################################################################
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json; charset=utf-8');

    $keyword = $_REQUEST['keyword'] ?? '';
    
    if (!$keyword) {
        echo json_encode(['success' => false, 'msg' => '검색어를 입력해주세요.']);
        exit;
    }

    try {
        // 🚀 수정: 편입된 ETF 개수(etf_count)를 같이 가져오는 서브쿼리 추가!
        $sql = "SELECT 
                    s.stock_code, 
                    s.stock_name,
                    (SELECT COUNT(etf_code) FROM all_etf_holdings_info h WHERE h.stock_code = s.stock_code) AS etf_count
                FROM all_stock_info s
                WHERE s.stock_code LIKE :kw_like1 OR s.stock_name LIKE :kw_like2 
                ORDER BY CASE WHEN s.stock_name = :kw_exact THEN 1 ELSE 2 END, s.stock_name ASC
                LIMIT 12";
                
        $stmt = $pdo->prepare($sql);
        
        $stmt->execute([
            'kw_like1' => '%' . $keyword . '%',
            'kw_like2' => '%' . $keyword . '%',
            'kw_exact' => $keyword
        ]);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 0) {
            echo json_encode(['success' => true, 'data' => $rows]);
        } else {
            echo json_encode(['success' => false, 'msg' => '검색 결과가 없습니다.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'msg' => 'DB 에러: ' . $e->getMessage()]);
    }

    exit;
}


#################################################################
// 📱 모바일 전용 화면용 JSON API
//   action=recent | related | multi | stocks
#################################################################
function etf_mobile_api($pdo) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $repo   = new StockRepository($pdo);
    $action = $_REQUEST['action'] ?? '';

    try {
        if ($action === 'recent') {
            echo json_encode(['success'=>true, 'data'=>$repo->getRecentStocks(8)]);
        }
        elseif ($action === 'related') {
            $code = $_REQUEST['stock_code'] ?? '';
            $name = $_REQUEST['stock_name'] ?? '';
            $sort = $_REQUEST['sort'] ?? 'rank';
            if ($code !== '' && $name !== '') $repo->saveRecentStock($code, $name);
            echo json_encode(['success'=>true, 'data'=>$repo->getEtfsHoldingSpecificStock($code, $sort, '', 0)]);
        }
        elseif ($action === 'multi') {
            $codes = array_filter(explode(',', $_REQUEST['codes'] ?? ''));
            echo json_encode(['success'=>true, 'data'=>$repo->getEtfsByMultiStocks($codes)]);
        }
        elseif ($action === 'stocks') {
            $etf  = $_REQUEST['etf_code'] ?? '';
            $sort = $_REQUEST['sort'] ?? 'ratio';
            echo json_encode(['success'=>true, 'data'=>$repo->getStocksInSpecificEtf($etf, $sort)]);
        }
        else {
            echo json_encode(['success'=>false, 'msg'=>'unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['success'=>false, 'msg'=>$e->getMessage()]);
    }
    exit;
}


#################################################################
// 📱 모바일 전용 ETF 분석 화면 (mode=m) — iframe/새창 없음, 단일 스크롤
#################################################################
function etf_mobile($pdo) {
    global $mobile, $current_user, $expire_date;
    require_once "./env/header.php";   // 공통 햄버거 헤더(<html><head>…<body><nav>)

    echo <<<'PAGE'
    <style>
        body { overflow:auto !important; height:auto !important; min-height:100vh; display:block !important; background:#f0f2f5; }
        .m-wrap { padding:10px; max-width:680px; margin:0 auto; }
        .m-box { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.05); padding:12px; margin-bottom:10px; }
        .srch-row { display:flex; align-items:center; gap:8px; }
        .srch-row .ic { font-size:16px; }
        .m-wrap input[type=text] { flex:1; padding:11px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:16px; outline:none; width:100%; }
        .sug-box { position:relative; }
        .sug-list { position:absolute; left:0; right:0; top:4px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 8px 16px rgba(0,0,0,.12); z-index:50; max-height:320px; overflow-y:auto; }
        .sug { padding:12px 14px; font-size:15px; border-bottom:1px solid #f3f3f3; cursor:pointer; }
        .sug:active { background:#eef4ff; }
        .chips { display:flex; flex-wrap:wrap; gap:7px; margin-top:10px; }
        .chip { background:#f1f3f5; color:#555; border:1px solid #e9ecef; border-radius:20px; padding:7px 13px; font-size:14px; font-weight:600; cursor:pointer; }
        .chip.on { background:#e1234a; color:#fff; border-color:#e1234a; }
        .empty-s { color:#aaa; font-size:13px; }
        .combine { display:block; width:100%; margin-top:10px; background:#0052a4; color:#fff; border:none; border-radius:8px; padding:11px; font-size:15px; font-weight:700; cursor:pointer; }
        .etfsearch { display:flex; align-items:center; gap:8px; }
        .etfsearch button { background:#0052a4; color:#fff; border:none; border-radius:8px; padding:11px 16px; font-size:15px; font-weight:700; cursor:pointer; flex-shrink:0; }
        .list-title { font-size:16px; font-weight:800; color:#1e293b; margin:6px 2px 10px; padding-left:10px; border-left:4px solid #0052a4; }
        .etf-list .empty, .loading { color:#aaa; font-size:14px; text-align:center; padding:24px; }
        .card { background:#fff; border:1px solid #e8edf2; border-radius:10px; margin-bottom:8px; overflow:hidden; }
        .card-main { display:flex; align-items:baseline; flex-wrap:wrap; gap:5px 13px; padding:13px 14px; cursor:pointer; }
        .c-name { font-size:16px; font-weight:800; color:#1e293b; display:inline-flex; align-items:center; gap:6px; }
        .rk { padding:1px 7px; border-radius:4px; font-size:11px; font-weight:700; flex-shrink:0; }
        .rk-top { background:#e1234a; color:#fff; }
        .rk-low { background:#eef0f2; color:#9aa3ad; }
        .c-metrics { display:flex; align-items:baseline; gap:13px; margin-left:auto; }
        .m-item { font-size:16px; font-weight:800; color:#1e293b; line-height:1.05; white-space:nowrap; }
        .m-item small { font-size:11px; font-weight:700; color:#0052a4; margin-right:2px; vertical-align:1px; }
        .m-rate { font-size:16px; font-weight:800; line-height:1.05; white-space:nowrap; min-width:62px; text-align:right; }
        .m-rate .chev { color:#b8c2cc; margin-left:4px; font-size:12px; }
        .c-sub { flex-basis:100%; font-size:12px; color:#94a3b8; margin-top:2px; }
        .card-detail { border-top:1px solid #eef1f4; background:#fafbfc; padding:8px 12px 12px; }
        .d-head { font-size:12px; color:#64748b; font-weight:700; padding:6px 2px; }
        .d-rank { margin-left:5px; color:#e1234a; font-size:16px; font-weight:800; vertical-align:-1px; }
        .d-row { display:grid; grid-template-columns:1fr auto auto; gap:10px; align-items:center; padding:8px 4px; border-bottom:1px solid #f0f2f5; font-size:14px; border-radius:6px; }
        .d-row.hl { background:#fff7e6; border-bottom-color:#ffe2b0; box-shadow:inset 3px 0 0 #f59e0b; padding-left:8px; }
        .d-row.hl .d-name { color:#b45309; font-weight:800; }
        .d-name { font-weight:600; color:#334155; }
        .d-ratio { color:#0052a4; font-weight:600; font-size:13px; min-width:54px; text-align:right; }
        .d-rate { min-width:66px; text-align:right; }

        /* 폴더블/와이드(≥561px): 폭 여유 → ETF명·비중·편입·등락률 4개를 크게 (여행Story 3단 반응형과 동일 경계) */
        @media (min-width:561px) {
            .m-wrap { max-width:720px; }
            .card-main { gap:6px 18px; padding:15px 18px; }
            .c-metrics { gap:18px; }
            .c-name { font-size:22px; }
            .m-item { font-size:22px; }
            .m-rate { min-width:80px; }
            .m-item small { font-size:12px; vertical-align:2px; }
            .m-rate { font-size:22px; }
            .m-rate .chev { font-size:14px; }
            .c-sub { font-size:13px; margin-top:4px; }
        }
    </style>

    <div class="m-wrap">
        <div class="m-box">
            <div class="srch-row"><span class="ic">🔍</span><input type="text" id="stockSearch" placeholder="종목명 또는 코드 (예: 삼성전자)" autocomplete="off"></div>
            <div class="sug-box"><div id="sugList" class="sug-list" style="display:none"></div></div>
            <div class="chips" id="recentChips"></div>
            <button class="combine" id="combineBtn" style="display:none"></button>
        </div>

        <div class="m-box etfsearch">
            <span class="ic">🔍</span>
            <input type="text" id="etfSearch" placeholder="ETF명 검색 (예: 반도체)" autocomplete="off">
            <button id="etfSearchBtn">검색</button>
        </div>

        <div class="list-title" id="listTitle">종목을 검색하거나 최근조회에서 선택하세요</div>
        <div class="etf-list" id="etfList"><div class="empty">종목을 선택하면 관련 ETF가 표시됩니다.</div></div>
    </div>

    <script>
    const API='etf_stock.php?mode=m_api';
    let stock=null, activeRecent=new Set(), curEtfs=[], etfFilter='', nameByCode={};

    function esc(s){return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
    function fmtRate(v){ v=parseFloat(v)||0; const c=v>0?'#e1234a':v<0?'#0052a4':'#666'; return `<span style="color:${c};font-weight:700">${(v>0?'+':'')+v.toFixed(2)}%</span>`; }
    function num(v){ return (Math.round(parseFloat(v)||0)).toLocaleString(); }
    function rankBadge(r){ r=parseInt(r)||0; if(r<=0) return ''; return `<span class="rk ${r<6?'rk-top':'rk-low'}">${r}위</span>`; }
    function circledNum(n){ n=parseInt(n)||0;
        if(n>=1&&n<=20) return String.fromCodePoint(0x2460+n-1);   // ①~⑳
        if(n>=21&&n<=35) return String.fromCodePoint(0x3251+n-21); // ㉑~㉟
        if(n>=36&&n<=50) return String.fromCodePoint(0x32B1+n-36); // ㊱~㊿
        return n+'위';
    }
    async function api(p){ return (await fetch(API+'&'+p)).json(); }

    // ── 종목 검색 자동완성 ──
    const si=document.getElementById('stockSearch'); let st;
    si.addEventListener('input',()=>{ clearTimeout(st); st=setTimeout(doSuggest,250); });
    async function doSuggest(){
        const kw=si.value.trim(); const box=document.getElementById('sugList');
        if(!kw){ box.style.display='none'; return; }
        const r=await fetch('etf_stock.php?mode=api_find&keyword='+encodeURIComponent(kw)).then(r=>r.json());
        if(!r.success){ box.innerHTML='<div class="sug" style="color:#aaa">결과 없음</div>'; box.style.display='block'; return; }
        box.innerHTML=r.data.map(s=>`<div class="sug" onclick="pickStock('${s.stock_code}','${esc(s.stock_name)}')">${esc(s.stock_name)} <span style="color:#aaa;font-size:13px">${s.stock_code} · ETF ${s.etf_count}</span></div>`).join('');
        box.style.display='block';
    }
    function pickStock(code,name){
        stock={code,name}; activeRecent=new Set([code]); nameByCode[code]=name;
        si.value=''; document.getElementById('sugList').style.display='none';
        loadRelated(); loadRecent();
    }
    document.addEventListener('click',e=>{ if(!e.target.closest('.srch-row')&&!e.target.closest('.sug-list')) document.getElementById('sugList').style.display='none'; });

    // ── 관련 ETF / 합쳐보기 ──
    async function loadRelated(){
        if(!stock) return;
        setTitle(`[${stock.name}] 관련 ETF`);
        const r=await api('action=related&stock_code='+stock.code+'&stock_name='+encodeURIComponent(stock.name)+'&sort=rank');
        curEtfs=r.data||[]; renderEtfs();
    }
    async function loadMulti(){
        const codes=[...activeRecent]; if(codes.length<2) return;
        setTitle(`공통 편입 ETF (${codes.length}종목)`);
        const r=await api('action=multi&codes='+codes.join(',')); curEtfs=r.data||[]; renderEtfs();
    }
    function setTitle(t){ document.getElementById('listTitle').textContent=t; }

    function renderEtfs(){
        const box=document.getElementById('etfList');
        let list=curEtfs;
        if(etfFilter){ const f=etfFilter.toLowerCase(); list=list.filter(e=>(e.etf_name||'').toLowerCase().includes(f)); }
        if(activeRecent.size===0 && !stock){ box.innerHTML='<div class="empty">종목을 선택하면 관련 ETF가 표시됩니다.</div>'; return; }
        if(list.length===0){ box.innerHTML='<div class="empty">표시할 ETF가 없습니다.</div>'; return; }
        box.innerHTML=list.map(etfCard).join('');
    }
    function etfCard(e){
        const hc=parseInt(e.holdings_count)||0;
        return `<div class="card">
            <div class="card-main" onclick="toggleEtf(this,'${e.etf_code}')">
                <div class="c-name">${rankBadge(e.etf_stock_rank)}<span>${esc(e.etf_name)}</span></div>
                <div class="c-metrics">
                    <span class="m-item"><small>비중</small>${(parseFloat(e.holdings_ratio)||0).toFixed(2)}%</span>
                    ${hc?`<span class="m-item"><small>편입</small>${hc}개</span>`:''}
                    <span class="m-rate">${fmtRate(e.etf_rate)}<span class="chev">▾</span></span>
                </div>
                <div class="c-sub">대금 ${num(e.trading_value)}억 · 회전 ${(parseFloat(e.turnover_ratio)||0).toFixed(1)}</div>
            </div>
            <div class="card-detail" style="display:none"></div>
        </div>`;
    }
    async function toggleEtf(el, etfCode){
        const d=el.parentElement.querySelector('.card-detail'); const chev=el.querySelector('.chev');
        if(d.style.display!=='none'){ d.style.display='none'; chev.textContent='▾'; return; }
        chev.textContent='▴'; d.style.display='block'; d.innerHTML='<div class="loading">불러오는 중…</div>';
        const r=await api('action=stocks&etf_code='+etfCode+'&sort=ratio'); const rows=r.data||[];
        // 선택(넘어온) 종목 = 코드 또는 종목명 매칭
        const aNames=new Set([...activeRecent].map(c=>nameByCode[c]).filter(Boolean));
        const isPicked=s=> activeRecent.has(s.stock_code) || aNames.has(s.stock_name);
        // 비중순 리스트의 위치(1-based) = 편입비중 순위 → 선택 종목명 옆에 배지
        d.innerHTML='<div class="d-head">구성 종목 (비중순)</div>'+rows.slice(0,30).map((s,i)=>`
            <div class="d-row${isPicked(s)?' hl':''}"><span class="d-name">${esc(s.stock_name)}${isPicked(s)?`<span class="d-rank">${circledNum(i+1)}</span>`:''}</span><span class="d-ratio">${(parseFloat(s.holdings_ratio)||0).toFixed(2)}%</span><span class="d-rate">${fmtRate(s.stock_rate)}</span></div>`).join('');
    }

    // ── ETF명 검색 = 현재 목록 클라이언트 필터 ──
    const ef=document.getElementById('etfSearch');
    document.getElementById('etfSearchBtn').onclick=()=>{ etfFilter=ef.value.trim(); renderEtfs(); };
    ef.addEventListener('keydown',e=>{ if(e.key==='Enter'){ etfFilter=ef.value.trim(); renderEtfs(); } });

    // ── 최근조회 칩 ──
    async function loadRecent(){
        const r=await api('action=recent'); const box=document.getElementById('recentChips'); const rows=r.data||[];
        rows.forEach(s=>{ nameByCode[s.stock_code]=s.stock_name; });
        box.innerHTML=rows.length? rows.map(s=>`<button class="chip${activeRecent.has(s.stock_code)?' on':''}" onclick="toggleRecent('${s.stock_code}','${esc(s.stock_name)}')">${esc(s.stock_name)}</button>`).join('')
                                 : '<span class="empty-s">최근 조회한 종목이 없습니다</span>';
        const b=document.getElementById('combineBtn');
        if(activeRecent.size>=2){ b.style.display='block'; b.textContent=`＋ ${activeRecent.size}개 종목 공통 ETF 보기`; } else b.style.display='none';
    }
    function toggleRecent(code,name){
        nameByCode[code]=name;
        if(activeRecent.has(code)) activeRecent.delete(code); else activeRecent.add(code);
        if(activeRecent.size===1){ const c=[...activeRecent][0]; stock={code:c, name:(c===code?name:(stock?stock.name:name))}; if(c===code) stock={code,name}; loadRelated(); }
        else if(activeRecent.size===0){ stock=null; curEtfs=[]; renderEtfs(); setTitle('종목을 선택하세요'); }
        loadRecent();
    }
    document.getElementById('combineBtn').onclick=loadMulti;

    // ── 초기화 ──
    (function(){
        const q=new URLSearchParams(location.search);
        const code=q.get('stock_code'), name=q.get('stock_name');
        if(code&&name){ stock={code,name}; activeRecent=new Set([code]); nameByCode[code]=name; loadRelated(); }
        loadRecent();
    })();
    </script>
    </body>
    </html>
PAGE;
}


?>