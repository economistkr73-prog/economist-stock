<?php

require_once "./env/cnt.inc";

# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 1 );
 ini_set("allow_url_fopen",1);

#변수정의
define('CUR_PHP', basename($_SERVER['PHP_SELF']));

require_once "./env/auth_fnc.php";
require_login(); 

?>



<style>
    /* 활성화 상태: 파란색 배경, 하얀색 글자, 클릭 가능한 손모양 */
    .btn-active { 
        background-color: #1a73e8 !important; 
        color: #ffffff !important; 
        cursor: pointer !important; 
    }
    
    /* 비활성화 상태: 회색 배경, 회색 글자, 금지 모양 */
    .btn-disabled { 
        background-color: #cccccc !important; 
        color: #888888 !important; 
        cursor: not-allowed !important; 
    }
</style>

<?php


// 1. 변수 안전하게 받기 (중복 선언 제거)
$mode = $_REQUEST["mode"] ?? ''; 

$routes = [
 'cf'                     => 'condition_iFrame',

'cdl'             => 'condition_dashboard_list'

];




// ==========================================================
// 2. 실행 엔진
// ==========================================================

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $func_name = $routes[$mode];

    // 1. 파라미터를 아예 받지 않는 함수들
    if (in_array($func_name, ['si'])) {
        $func_name();
    } 
      
    // 3. 기본값: 나머지 기존 레거시 함수들 
    else {
        $func_name($pdo);
    }

} else {
#    echo "<meta http-equiv=\"refresh\" content=\"0;url=lo.php\">";
    exit;
}


############################################
function condition_iFrame() {
###########################################
// 1. 공통 환경 및 권한/세션 설정 (header.php가 다 해줍니다!)
global $mobile;
require_once "./env/e.fnc";
require_once "./env/inf.fnc";

// 💡 header.php 안에서 <html>, <body> 태그를 열고 상단바를 그려줍니다.
require_once "./env/header.php";

$GR_Vals = Get_Vals('mode'); // 필요한 파라미터 받기

// 2. 이 페이지 전용 2단 분할 레이아웃 스타일 (테이블 완전 제거)
echo "
<style>
    /* 상단바 아래의 남은 공간을 꽉 채우는 컨테이너 */
    .dashboard-container { 
        flex: 1; 
        padding: 20px; 
        overflow: hidden; /* 페이지 전체 스크롤 방지 */
        display: flex;    /* 좌우 분할을 위한 Flexbox */
        gap: 20px;        /* 왼쪽 카드와 오른쪽 카드 사이의 예쁜 간격 */
        background-color: #f0f2f5; 
    }

    /* 왼쪽 사이드바 영역 (크기 고정) */
    .panel-left {
        width: 1000px; 
        flex-shrink: 0; /* 화면이 줄어도 700px 유지 */
    }

    /* 오른쪽 메인 영역 (남은 공간 100% 꽉 채움) */
    .panel-right {
        flex: 1; 
        min-width: 0; /* 창을 줄일 때 레이아웃이 뚫고 나가는 버그 방지 */
    }

    /* 예쁜 하얀색 카드 디자인 */
    .card { 
        background: #ffffff; 
        border-radius: 12px; 
        box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
        border: 1px solid #e2e8f0; 
        height: 100%; 
        display: flex; 
        flex-direction: column; 
        overflow: hidden; 
    }

    /* 아이프레임 리셋 (지저분한 테두리와 스크롤바 속성을 CSS로 깔끔하게 통합) */
    .card iframe { 
        width: 100%; 
        height: 100%; 
        border: none; 
        display: block; 
    }
</style>
";

// 3. 본문 렌더링 (지저분한 table 태그 없이 div 박스만으로 구현)
echo "
<div class='dashboard-container'>
    
    <div class='panel-left'>
        <div class='card'>
            <iframe src='" . CUR_PHP . "?mode=cdl' name='stock_d1'></iframe>
        </div>
    </div>

    <div class='panel-right'>
        <div class='card'>
            <iframe src='' name='stock_d2'></iframe>
        </div>
    </div>

</div>

</body>
</html>
";

 ################### end of  Pax_iFrame() #######################
}
################### end of  Pax_iFrame() #######################




############################################
function condition_dashboard_list($pdo) {
############################################
require "./env/e.fnc";
require_once "./classes/UI_Helper.class"; // UI 개선 (정렬)

global $TREND_CHECK_DAYS;
    $d1 = $TREND_CHECK_DAYS['t1'] ?? 7;
    $d2 = $TREND_CHECK_DAYS['t2'] ?? 14;
    $d3 = $TREND_CHECK_DAYS['t3'] ?? 30;

$is_mini = isset($_REQUEST['mini']) && $_REQUEST['mini'] === 'Y';

    // 1. Repository 패턴을 이용해 데이터 가져오기
    $stockRepo = new Stock_Analysis_Repository($pdo);
    $results = $stockRepo->getConditionDashboardList(100);


	##### 시작: 내부 함수 

// 1) 이 화면에서만 사용할 지역 도우미 함수 (전역 오염 X)
    $get_feedback_rate_html = function($base_cap, $target_cap) {
        if ($target_cap <= 0) return "<span style='color: #a0aec0; font-size: 13px;'>⏳ 대기중</span>";
        $rate = (($target_cap / $base_cap) - 1) * 100;
        
        if ($rate > 0)      { $color = "#e74c3c"; $sign = "+"; } 
        else if ($rate < 0) { $color = "#3498db"; $sign = ""; } 
        else                { $color = "#2d3748"; $sign = ""; }
        
        return "<span style='color: {$color}; font-weight: bold; font-size: 14px;'>" . $sign . number_format($rate, 2) . "%</span>";
    }; // 익명 함수는 끝에 세미콜론(;) 필수!



 // 2) 포착 횟수에 따른 디자인 진화 + 미니모드 클릭 이벤트 통합 함수
    $get_stock_name_html = function($name, $stock_code, $hit_count) use ($is_mini) {
        
        $icon = ""; $bg = ""; $color = "#ffffff"; $shadow = "none"; $size = 14;
        $icon_html = "";
        $hit_text = "";
        $font_weight = "bold";

        // 💡 진화 단계 설정
        if ($hit_count >= 2 && $hit_count <= 3) {
            $bg = ""; $icon = "🔥"; $size = 15; $font_weight = "800";
        } elseif ($hit_count >= 4 && $hit_count <= 6) {
            $bg = "#ff0000"; $icon = "🔥"; $size = 20; $font_weight = "900";
        } elseif ($hit_count >= 7) {
            $bg = "#ff0000"; $icon = "🚀"; $size = 30; $font_weight = "900";
            $shadow = "0 3px 6px rgba(255,0,0,0.4)";
        }

        // 2회 이상일 때만 아이콘과 횟수 텍스트 생성
        if ($hit_count > 1) {
            $icon_html = "<span style='background-color: {$bg}; color: {$color}; font-size: {$size}px; padding: 3px 6px; border-radius: 4px; box-shadow: {$shadow}; margin-right: 6px; vertical-align: middle; display: inline-block;'>{$icon}</span>";
            $hit_text = " <span style='font-size:12px; color:#718096; font-weight:normal;'>({$hit_count}회)</span>";
        }

        // 💡 [핵심] 미니 모드일 때만 클릭 이벤트와 손가락 커서 활성화
        $onclick = "";
        $cursor = "default"; 
        
        if ($is_mini) {
            $row_id = "row_" . $stock_code;
            $onclick = "onclick=\"clickStock('{$row_id}', '{$stock_code}', '{$name}')\"";
            $cursor = "pointer"; 
        }

        // 종목명 부분 조립 (클릭 이벤트 포함)
        $name_html = "<span {$onclick} style='
            cursor: {$cursor};
            font-size: {$size}px;
            font-weight: {$font_weight};
            color: #2d3748;
            vertical-align: middle;
            letter-spacing: -0.5px;
        '>{$name}{$hit_text}</span>";

        return $icon_html . $name_html;
    };



	##### 끝: 내부 함수 


    // 2. Pure PHP 스타일로 HTML 문자열 조립하기
    $html = "";
    
    // CSS 스타일 지정
    $html .= "<style>
        .dashboard-table { width: 100%; border-collapse: collapse; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .dashboard-table th { background: #f8fafc; padding: 14px; text-align: center; font-size: 14px; color: #4a5568; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
        .dashboard-table td { padding: 12px 14px; border-bottom: 1px solid #edf2f7; text-align: center; font-size: 14px; color: #2d3748; }
        .dashboard-table tr:hover { background: #fbfdff; }
        .badge-type { background: #edf2f7; color: #4a5568; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 12px; }
        .text-left { text-align: left !important; }
    </style>";


// 💡 2. [핵심] DB 데이터를 종목명 기준으로 그룹핑(묶기)
    $grouped_stocks = [];
    foreach ($results as $row) {
        $name = htmlspecialchars($row['stock_name']);
        
        // 처음 나오는 종목이면 배열 방을 만들어줍니다.
        if (!isset($grouped_stocks[$name])) {
            $grouped_stocks[$name] = [
				'stock_code' => $row['stock_code'], 
                'records' => [],      // 개별 포착일과 수익률 기록들
                'types'   => [],      // 어떤 조건들을 만족했는지 수집
                'rate'    => htmlspecialchars($row['stock_rate']), // 최신 등락률
                'cap'     => number_format($row['base_cap']/10000,2),   // 최신 시총
                'hit'     => (int)$row['hit_count'],
				'etf_count' => (int)$row['etf_count']
            ];
        }
        
        // 해당 종목의 조건타입과 개별 검증 데이터를 차곡차곡 쌓습니다.
        $grouped_stocks[$name]['types'][] = $row['analysis_type'];
        $grouped_stocks[$name]['records'][] = $row;
        
        // 포착 횟수는 가장 높은 숫자로 갱신 (디자인용)
        if ((int)$row['hit_count'] > $grouped_stocks[$name]['hit']) {
            $grouped_stocks[$name]['hit'] = (int)$row['hit_count'];
        }
    }

    if ($is_mini) {
// 💡 [신규 추가] 아이프레임 통신 및 클릭 하이라이트 스크립트
    $html .= "
    <script>
    function clickStock(rowId, stockCode, stockName) {
        // 1. 자식 창 내부의 하이라이트 처리 (클릭한 줄 색상 변경)
        var rows = document.querySelectorAll('tr');
        rows.forEach(function(r) { r.classList.remove('row-highlight'); });
        var current = document.getElementById(rowId);
        if (current) current.classList.add('row-highlight');

        // 2. 부모 창(etf_stock.php)의 함수 호출하여 다른 프레임들 변경!
        if (window.parent && window.parent.openCommonFrames) {
            var params = 'stock_code=' + stockCode + '&stock_name=' + encodeURIComponent(stockName);
            // 부모창의 함수 호출 (rowId는 null로 넘겨서 부모창에서 하이라이트 에러 방지)
            window.parent.openCommonFrames(null, stockCode, params, ['etf_t1','etf_d1', 'etf_d2', 'etf_d3','etf_d5'], 'etf_stock.php');
        }
    }
    </script>";
	}


# 상단 조건검색 설명
$conditionMeta = $stockRepo->getConditionTypesMeta(); 

// 2. 동적 안내 박스(Legend) HTML 생성
$type_indicators = "";
$legend_items = "";

foreach ($conditionMeta as $code => $meta) {
    $legend_items .= "
        <div style='flex: 1 1 calc(50% - 20px); min-width: 300px;'>
            <span style='background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: bold; margin-right: 5px; color: #2d3748;'>{$code}</span> 
            <strong style='color:{$meta['point_color']};'>{$meta['type_title']}</strong> : {$meta['type_desc']}
        </div>";
}

// 3. 동적 테이블 헤더(c1~c4) HTML 생성
$header_cols = "";
foreach ($conditionMeta as $code => $meta) {
    $header_cols .= "<th style='width: 28px; min-width: 28px; font-size:11px; padding:4px 0; background:#f8fafc; border-right:1px solid #edf2f7; text-align:center;' title='{$meta['type_title']}'>{$code}</th>";
}

// 💡 3. HTML 껍데기 및 헤더 (요청하신 디스플레이 순서 적용)
    // 💡 3. HTML 껍데기 및 헤더 (타이틀 + 매트릭스 범례 + 테이블 헤더)
    $html .= "
                <h2 style='color: #2c3e50; margin-bottom: 15px;'>📊 조건검색 성과 피드백 대시보드</h2>
                
                <div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; font-size: 13px; color: #4a5568; line-height: 1.6;'>
        <div style='font-weight: bold; margin-bottom: 10px; color: #2d3748; font-size: 14px;'>📌 조건검색 및 수급 지표 안내</div>
        <div style='display: flex; flex-wrap: wrap; gap: 10px 20px;'>
            {$legend_items}
        </div>
        <div style='margin-top: 12px; padding-top: 12px; border-top: 1px dashed #cbd5e1; display: flex; align-items: center;'>
            <span style='font-size: 11px; padding: 2px 6px; background: #ff0000;  border-radius: 4px; font-weight: bold; border: 0px solid #1e3a8a; vertical-align: middle; margin-right: 8px;'>💎</span> 
            <strong style='color:#ff0000; margin-right: 4px;'>ETF 수 : </strong> 해당 종목의 편입 비중이 상위 5 위 안에 있는 국내 ETF 갯수
        </div>

                </div>

                <table class='dashboard-table'>
                    <thead>
                        <tr>
                            <th class='text-left' rowspan='2' style='vertical-align: middle; border-right:1px solid #edf2f7;'>종목명							
							</th>
                            
                           <th colspan='" . count($conditionMeta) . "' style='background: #f1f5f9; border-bottom: 1px solid #cbd5e1; padding: 6px; letter-spacing: 2px; border-right:1px solid #edf2f7;'>조건타입</th>
                            
                            <th rowspan='2' style='vertical-align: middle; border-right:1px solid #edf2f7;'>등락률</th>
                            <th rowspan='2' style='vertical-align: middle; border-right:1px solid #edf2f7;'>시총(조)</th>";


							// 💡 미니 모드가 아닐 때만 우측 4개 헤더 출력
    if (!$is_mini) {
        $html .= "      <th rowspan='2' style='vertical-align: middle;'>최초 포착일</th>
                        <th rowspan='2' style='background:#fffaf0; line-height: 1.4; vertical-align: middle;'>검증 1차<br><span style='font-size:11px; color:#a0aec0; font-weight:normal;'>(+{$d1}일)</span></th>
                        <th rowspan='2' style='background:#f0f7ff; line-height: 1.4; vertical-align: middle;'>검증 2차<br><span style='font-size:11px; color:#a0aec0; font-weight:normal;'>(+{$d2}일)</span></th>
                        <th rowspan='2' style='background:#f0fff4; line-height: 1.4; vertical-align: middle;'>검증 3차<br><span style='font-size:11px; color:#a0aec0; font-weight:normal;'>(+{$d3}일)</span></th>";
         }


$html .= "  </tr>
            <tr>
                {$header_cols}
            </tr>
        </thead>
        <tbody>";


// 💡 4. 데이터 렌더링 (Rowspan 병합 로직)
    if (count($grouped_stocks) === 0) {
        $html .= "<tr><td colspan='11' style='padding: 40px; color: #a0aec0; text-align:center;'>조건검색 데이터가 없습니다.</td></tr>";
    } else {
        // 그룹핑된 종목별로 루프를 돕니다.
        foreach ($grouped_stocks as $name => $data) {

           
            // 이 종목이 몇 개의 검증 기록(행)을 가질지 계산 (이것이 rowspan 값이 됩니다!)
			$rowspan = $is_mini ? 1 : count($data['records']);

			$stock_code = $data['stock_code']; 
            $row_id = "row_" . $stock_code; // 종목코드 고유 ID 생성

            #$etf_count = (int)$data['etf_count']; 
				$stock_summary = StockSummaryCache::getInfo($data['stock_code']);
                $etf_badge = UIHelper::get_etf_badge_html($stock_summary['top_rank_count'],$stock_summary['etf_count']);




    #        $etf_badge = UIHelper::get_etf_badge_html($etf_count);


            
            // 종목명 뱃지 디자인 함수 호출
            $stock_html = $get_stock_name_html($name, $stock_code, $data['hit']) . $etf_badge;
            
$type_indicators = "";
$group_border = "";
    foreach ($conditionMeta as $code => $meta) {
        $is_active = in_array($meta['type_code'], $data['types']);
        $icon = $is_active ? "<span style='font-size:16px;'>🔥</span>" : "";

        $type_indicators .= "<td rowspan='{$rowspan}' style='width:28px; max-width:28px; padding:0; border-right:1px solid #edf2f7; background:#fafafa; text-align:center; {$group_border}'>{$icon}</td>";
    }


            // 기록(포착일자) 수만큼 <tr> 루프를 돕니다.
            foreach ($data['records'] as $index => $row) {
                
                $html .= "<tr style='border-bottom: 1px solid #edf2f7;'>";
                
                // 💡 [핵심] 첫 번째 줄일 때만 좌측(종목명~시총) 데이터를 rowspan으로 통째로 그려줍니다!
              if ($index === 0) {
            $group_border = "border-bottom: 2px solid #cbd5e1;"; 
            
            $html .= "
                <td class='text-left' rowspan='{$rowspan}' style='border-right:1px solid #edf2f7; {$group_border}'>{$stock_html}</td>
                {$type_indicators}  <td rowspan='{$rowspan}' style='border-right:1px solid #edf2f7; text-align:center; {$group_border}'>" . deco_txt($data['rate'], 111) . "</td>
                <td rowspan='{$rowspan}' style='border-right:1px solid #edf2f7; text-align:center; {$group_border}'>".$data['cap']."</td>
            ";
        }

                // 우측(포착일자, T1, T2, T3) 검증 데이터는 매 <tr>마다 계속 그려줍니다.

				// 💡 미니 모드가 아닐 때만 우측 데이터 출력
                if (!$is_mini) {
                    $ref_date = htmlspecialchars($row['ref_date']);
                    $t1_html = $get_feedback_rate_html($row['base_cap'], $row['market_cap_t1']);
                    $t2_html = $get_feedback_rate_html($row['base_cap'], $row['market_cap_t2']);
                    $t3_html = $get_feedback_rate_html($row['base_cap'], $row['market_cap_t3']);

                    $html .= "
                        <td style='color:#718096; font-size:13px; text-align:center;'>{$ref_date}</td>
                        <td style='background:#fffaf0; text-align:center;'>{$t1_html}</td>
                        <td style='background:#f0f7ff; text-align:center;'>{$t2_html}</td>
                        <td style='background:#f0fff4; text-align:center;'>{$t3_html}</td>
                    ";
                }

				$html .= "</tr>";

                // 💡 [핵심] 미니 모드일 때는 우측 데이터가 없으므로 루프를 1번만 돌고 탈출! (종목당 딱 1줄만 그려짐)
                if ($is_mini) {
                    break;
                }


              
            }
        }
    }

    $html .= "      </tbody>
                </table>
              </div>";

    // 5. 최종 조립된 HTML을 한 번에 출력
    echo $html;
}
############################################
