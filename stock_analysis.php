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
    'si'                     => 'stock_iFrame',
	'sal'                     => 'stock_analysis_list',
	'attach_up'              => 'attach_file_update',
	'compare_chart'              => 'compare_make_chart',
	
	
	'api_find'                  => 'api_find_stock'



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
    echo "<meta http-equiv=\"refresh\" content=\"0;url=lo.php\">";
    exit;
}





############################################
function stock_iFrame() {
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
        width: 700px; 
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
            <iframe src='" . CUR_PHP . "?mode=sal' name='stock_d1'></iframe>
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




#################################################################
function stock_analysis_list ($pdo) {
#################################################################



require "./env/e.fnc";

    $repo = new Stock_Analysis_Repository($pdo);

// PHP 에러를 방지하면서 JS 변수(${})를 쓰기 위해 이스케이프(\)를 잘 적용하셨습니다!
echo "
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
        fetch('" . CUR_PHP . "?mode=api_find&keyword=' + encodeURIComponent(keyword))
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
                        
                        li.innerHTML = `
                            <div style='display: flex; justify-content: space-between; align-items: center; width: 100%;'>
                                <div style='font-size: 14px;'>
                                    <strong>\${item.stock_name}</strong> 
                                    <span style='color: #888; font-size: 12px; margin-left: 4px;'>(\${item.stock_code})</span>
                                </div>
                              
                            </div>
                        `;
                        
                        li.onmouseover = function() { 
                            removeActive(); 
                            currentFocus = index; 
                            this.style.backgroundColor = '#f1f3f5'; 
                        };
                        li.onmouseout = function() { this.style.backgroundColor = '#fff'; };
                        
                        // 🔴 [수정된 부분] 여기가 가장 중요합니다!
                        li.onclick = function() {
                           
                            // 2. 검색창에 선택한 종목명 표시
                            document.getElementById('stockSearchInput').value = item.stock_name; 
                            
                            // 3. 폼 전송을 위해 숨겨진 input에 종목코드 세팅 (추가됨!)
                            document.getElementById('real_stock_code').value = item.stock_code;
                            
                            // 4. 자동완성 창 닫기
                            box.style.display = 'none'; 
                        };
                        
                        box.appendChild(li);
                    });
                    box.style.display = 'block'; 
                } else {
                    var errorMsg = res.msg ? res.msg : '검색 결과가 없습니다.';
                    var textColor = res.msg && res.msg.includes('에러') ? '#e1234a' : '#888'; 
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

document.addEventListener('click', function(e) {
    if (e.target.id !== 'stockSearchInput') {
        document.getElementById('suggestionBox').style.display = 'none';
    }
});
</script>
";

echo "
    <tr height='70'>
        <td style='padding-bottom: 20px;'>
            <div style='background: #ffffff; border: 1px solid #e1e5e9; border-radius: 8px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.04); padding: 15px 20px;'>
                
                <form method='post' action='" . CUR_PHP . "' enctype='multipart/form-data' name='myform' style='margin: 0; display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 15px;'>
                    <input type='hidden' name='mode' value='attach_up'>
                    
                    <div style='display: flex; align-items: center; gap: 12px;'>
                        <div style='color: #343a40; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 5px;'>
                            <span style='font-size: 16px;'>🔍</span> 데이터 등록
                        </div>
                        
                        <div style='position: relative; display: flex; align-items: center;'>
                            <input type='text' id='stockSearchInput' placeholder='종목명 또는 코드 입력' autocomplete='off' oninput='liveSearch(this.value)' required
                                   style='width: 160px; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 6px; outline: none; font-size: 13px; color: #495057; transition: border-color 0.2s; box-sizing: border-box;'
                                   onfocus=\"this.style.borderColor='#1a73e8';\" onblur=\"this.style.borderColor='#ced4da';\">
                            
                            <ul id='suggestionBox' style='display: none; position: absolute; z-index: 1000; top: 40px; left: 0; width: 100%; background: #fff; border: 1px solid #e1e5e9; border-radius: 6px; list-style: none; padding: 0; margin: 0; max-height: 250px; overflow-y: auto; box-shadow: 0 8px 16px rgba(0,0,0,0.08); overflow-x: hidden;'>
                            </ul>
                        </div>
                    </div>
                    
                    <input type='hidden' id='real_stock_code' name='stock_code' value=''>
                    
                    <div style='display: flex; align-items: center; gap: 10px;'>
                        
                    <div style='display: flex; align-items: center; justify-content: center; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 6px; padding: 4px; width: 240px; box-sizing: border-box; height: 38px;'>
                            
                            <label id='fileBtn' for='customFileUpload' 
                                   style='width: 100%; text-align: center; padding: 5px 0; background: #ffffff; border: 1px solid #d2d6da; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; color: #495057; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s; margin: 0; box-sizing: border-box;'
                                   onmouseover=\"this.style.backgroundColor='#f1f3f5';\" 
                                   onmouseout=\"this.style.backgroundColor='#ffffff';\">
                                📁 파일 찾기
                            </label>
                            
                            <label id='fileNameDisplay' for='customFileUpload' title='클릭하여 다른 파일 선택'
                                   style='display: none; width: 100%; text-align: center; font-size: 13px; color: #1a73e8; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; margin: 0; padding: 0 10px; box-sizing: border-box;'>
                            </label>
                            
                            <input type='file' id='customFileUpload' name='upfile' required style='display: none;'
                                   onchange=\"
                                       let btn = document.getElementById('fileBtn');
                                       let disp = document.getElementById('fileNameDisplay');
                                       if(this.files && this.files[0]) {
                                           btn.style.display = 'none';
                                           disp.style.display = 'block';
                                           disp.textContent = '📄 ' + this.files[0].name;
                                       } else {
                                           btn.style.display = 'block';
                                           disp.style.display = 'none';
                                       }
                                   \">
                        </div>
                        
                        <button type='submit' 
                                style='padding: 8px 20px; background: #e1234a; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: bold; box-shadow: 0 3px 6px rgba(225, 35, 74, 0.25); transition: all 0.2s ease;'
                                onmouseover=\"this.style.backgroundColor='#c91c3f'; this.style.transform='translateY(-1px)';\" 
                                onmouseout=\"this.style.backgroundColor='#e1234a'; this.style.transform='translateY(0)';\">
                            업로드 🚀
                        </button>
                    </div>
                </form>

            </div>
        </td>
    </tr>
";


try {

#$start_time = microtime(true);
// 1. Repository를 통해 요약 데이터 가져오기
$summary_list = $repo->getSummaryData();

#$mid_time = microtime(true);
#echo ("SummaryData 실행 시간: " . ($mid_time - $start_time) . "초");

// 2. 💡 방어 코드: 데이터가 없을 경우를 대비해 빈 배열을 기본값으로 설정
$all_stock_codes = !empty($summary_list) ? array_column($summary_list, 'stock_code') : [];


// 3. 💡 방어 코드: 종목이 하나라도 있을 때만 호출, 없으면 빈 결과 반환
if (!empty($all_stock_codes)) {
    $performance = $repo->getPerformanceData($all_stock_codes, $_REQUEST['s_date'] ?? '', $_REQUEST['e_date'] ?? '');
} else {
    // 데이터가 없을 때 기본값 구조를 잡아줌
    $performance = ['period' => ['calc_start' => date('Y-m-d'), 'calc_end' => date('Y-m-d')], 'data' => []];
}


#$end_time = microtime(true);
#echo ("PerformanceData 실행 시간: " . ($end_time - $mid_time) . "초");



// 4. 최종 날짜 변수 설정
$s_date = $performance['period']['calc_start'];
$e_date = $performance['period']['calc_end'];


// 수익률 계산 시 인자 전달
#$performance_data = $repo->getPerformanceData($s_date, $e_date);


    // 💡 HTML 테이블 구조 렌더링 시작 (선택 헤더 추가)
echo "
    <tr>
        <td style='padding-top: 30px;'>
            <div style='display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 15px;'>
                <h4 style='margin: 0; color: #333;'>📈 등록된 데이터 요약 정보</h4>
                <span style='font-size: 12px; color: #e1234a; font-weight: bold;'>※ 최대 4개까지 선택 가능</span>
            </div>

            <div style='display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; margin-bottom: 15px; width: 100%; background: #ffffff; border: 1px solid #e1e5e9; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); box-sizing: border-box;'>
                
                <div>
                    <button type='button' id='drawChartBtn' disabled 
                            style='padding: 8px 18px; background-color: #cccccc; color: #888; border: none; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: not-allowed; transition: all 0.3s;'>
                        👉 선택된 종목 비교
                    </button> 
                </div>

                <div>
  
					<form method='GET' action='" . CUR_PHP . "' style='margin:0; display: flex; align-items: center; gap: 12px;'>
                        <input type='hidden' name='mode' value='sal'>
						<input type='hidden' name='selected_codes' id='hiddenSelectedCodes' value=''>
                        
                        <div style='display: flex; align-items: center; gap: 6px; color: #343a40; font-size: 13px; font-weight: 700; cursor: pointer; transition: color 0.2s;'
                             title='클릭 시 올해 1월 2일로 자동 설정됩니다'
                             onclick=\"document.getElementById('sDateInput').value = new Date().getFullYear() + '-01-02';\"
                             onmouseover=\"this.style.color='#1a73e8';\" 
                             onmouseout=\"this.style.color='#343a40';\">
                            <span style='font-size: 15px;'>🗓️</span> 기간 설정
                        </div>
                        
                        <div style='display: flex; align-items: center; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 6px; padding: 3px 8px; transition: all 0.2s ease;'>

								   <input type='date' id='sDateInput' name='s_date' value='{$s_date}' 
                                   style='padding: 2px; border: none; background: transparent; font-size: 13px; color: #495057; outline: none; cursor: pointer; font-family: inherit;'>
                            
                            <span style='color: #adb5bd; font-weight: bold; margin: 0 6px;'>~</span>
                            
                            <input type='date' id='eDateInput' name='e_date' value='{$e_date}' 
                                   style='padding: 2px; border: none; background: transparent; font-size: 13px; color: #495057; outline: none; cursor: pointer; font-family: inherit;'>
                        </div>
                        
                        <input type='submit' value='수익률 재계산' 
                               style='padding: 7px 16px; background: #1a73e8; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: bold; box-shadow: 0 2px 5px rgba(26, 115, 232, 0.2); transition: all 0.2s ease;'
                               onmouseover=\"this.style.backgroundColor='#1557b0'; this.style.transform='translateY(-1px)';\" 
                               onmouseout=\"this.style.backgroundColor='#1a73e8'; this.style.transform='translateY(0)';\">
                    </form>
                </div>
            </div> <table border='1' cellspacing='0' cellpadding='8' style='width: 100%; border-collapse: collapse; text-align: center; font-size: 14px; border: 1px solid #ddd;'>
                <thead>
                    <tr style='background-color: #f8f9fa; border-bottom: 2px solid #ccc;'>
                        <th rowspan='2' style='width: 8%;'>선택</th>
                        <th rowspan='2' style='width: 32%;'>종목명</th>
                        <th rowspan='2' style='width: 30%;'>수익률<br>
                            <span style='font-size: 12px; color: #e1234a; font-weight: normal;'>({$s_date} ~ {$e_date})</span>
                        </th> 
                        <th colspan='2' style='width: 30%;'>데이터 기간</th>
                    </tr>
                    <tr style='background-color: #f8f9fa;'>
                        <th>시작</th>
                        <th>끝</th>
                    </tr>
                </thead>
                <tbody>
    ";

// 데이터 루프 출력
    if (count($summary_list) > 0) {
        foreach ($summary_list as $row) {
            			
	$formatted_count = number_format($row['data_count']); 
        
        // 💡 수정: $performance['data'] 내의 종목코드를 참조하도록 변경
#		$yield = $performance_data['data'][$row['stock_code']] ?? 0;
		$yield = $performance['data'][$row['stock_code']] ?? 0;
        $color = $yield >= 0 ? '#e1234a' : '#1a73e8'; // 양수 빨강, 음수 파랑

            echo "
                    <tr style='border-bottom: 1px solid #eee;'>
                        <td style='width: 8%; text-align: center; vertical-align: middle;'>
                            <div class='chk-container' style='display: flex; justify-content: center; align-items: center; min-height: 24px;'>
                                <input type='checkbox' class='stock-checkbox' value='{$row['stock_code']}' style='cursor: pointer; width: 16px; height: 16px; margin: 0;'>
                                <span class='chk-badge' style='display: none; width: 22px; height: 22px; line-height: 22px; text-align: center; background-color: #1a73e8; color: #fff; font-size: 12px; font-weight: bold; border-radius:3px;  cursor: pointer;'></span>
                            </div>
                        </td>
                        <td style='font-weight: bold; color: #1a73e8; text-align: left;'>{$row['stock_name']} ({$row['stock_code']})</td>
                        <td style='color: {$color}; font-weight:bold;'>{$yield}%</td>
                        <td style='color: #555;'>{$row['start_date']}</td>
                        <td style='color: #555;'>{$row['end_date']}</td>
                    </tr>
            ";
        }
    } else {
        echo "<tr><td colspan='5' style='padding: 20px; color: #888;'>등록된 데이터가 없습니다.</td></tr>";
    }
    
    echo "
                </tbody>
            </table>
        </td>
    </tr>
    
<form id='compareChartForm' target='stock_d2' method='POST' action='" . CUR_PHP . "' style='display:none;'>
        <input type='hidden' name='mode' value='compare_chart'>
        <input type='hidden' name='codes' id='compareCodes' value=''>
        <input type='hidden' name='s_date' id='compareSDate' value=''>
        <input type='hidden' name='e_date' id='compareEDate' value=''>
    </form>
";


echo "
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.stock-checkbox');
    const maxLimit = 4;
    let selectedOrder = []; // 체크된 순서를 저장할 배열

    // 1. [초기화] URL 파라미터에서 기존 선택값 복원
    const urlParams = new URLSearchParams(window.location.search);
    const savedCodes = urlParams.get('selected_codes');
    if (savedCodes) {
        selectedOrder = savedCodes.split(',');
        // hidden input에 값 반영
        const hiddenInput = document.getElementById('hiddenSelectedCodes');
        if (hiddenInput) hiddenInput.value = savedCodes;
        
        // 체크박스 상태 복원
        selectedOrder.forEach(function(code) {
            checkboxes.forEach(function(box) {
                if (box.value === code) box.checked = true;
            });
        });
        updateSelectionUI();
    }

    checkboxes.forEach(function(checkbox) {
        // 2. 체크박스 상태 변경 이벤트
        checkbox.addEventListener('change', function() {
            const val = this.value;

            if (this.checked) {
                if (selectedOrder.length < maxLimit) {
                    selectedOrder.push(val);
                } else {
                    this.checked = false;
                    alert('최대 4개까지만 선택 가능합니다.');
                    return;
                }
            } else {
                selectedOrder = selectedOrder.filter(code => code !== val);
            }

            // 💡 핵심: 변경될 때마다 기간 설정 폼의 hidden 값 업데이트
            const hiddenInput = document.getElementById('hiddenSelectedCodes');
            if (hiddenInput) hiddenInput.value = selectedOrder.join(',');

            updateSelectionUI();
        });

        // 3. 숫자 배지 클릭 시 해제
        const badge = checkbox.nextElementSibling;
        if (badge) {
            badge.addEventListener('click', function() {
                checkbox.checked = false;
                checkbox.dispatchEvent(new Event('change'));
            });
        }
    });

    // 4. UI 갱신 함수
    function updateSelectionUI() {
        checkboxes.forEach(function(box) {
            const badge = box.nextElementSibling;
            const isChecked = box.checked;

            if (isChecked) {
                box.style.display = 'none';
                if (badge) {
                    badge.style.display = 'inline-block';
                    badge.style.visibility = 'visible';
                    badge.style.background = '#e1234a';
                    badge.innerHTML = selectedOrder.indexOf(box.value) + 1;
                    badge.style.pointerEvents = 'auto';
                }
            } else {
                box.style.display = 'inline-block';
                if (badge) badge.style.display = 'none';
                box.style.visibility = (selectedOrder.length >= maxLimit && !isChecked) ? 'hidden' : 'visible';
            }
        });

	const drawBtn = document.getElementById('drawChartBtn');
            if (drawBtn) {
                if (selectedOrder.length > 0) {
                    drawBtn.disabled = false;
                    drawBtn.className = 'btn-active';
                } else {
                    drawBtn.disabled = true;
                    drawBtn.className = 'btn-disabled';
                }
            }


    }

    // 5. 전송 이벤트
    document.getElementById('drawChartBtn').addEventListener('click', function() {
        if (selectedOrder.length === 0) return;
        
        document.getElementById('compareCodes').value = selectedOrder.join(',');
        document.getElementById('compareSDate').value = document.getElementById('sDateInput').value;
        document.getElementById('compareEDate').value = document.getElementById('eDateInput').value;
        
        document.getElementById('compareChartForm').submit();
    });
});
</script>

    ";

} catch (Exception $e) { // PDOException 대신 Exception으로 넓게 잡습니다.
    // 💡 화면에 진짜 에러 메시지를 빨간 글씨로 띄워줍니다.
    echo "
    <tr>
        <td colspan='5' style='padding: 20px;'>
            <div style='background-color: #fee2e2; border: 1px solid #ef4444; color: #b91c1c; padding: 15px; border-radius: 5px; font-weight: bold;'>
                🚨 DB 에러 상세 원인:<br><br>
                " . htmlspecialchars($e->getMessage()) . "
            </div>
        </td>
    </tr>";
}

#################################################################
} // stock_analysis_list 함수 끝
#################################################################


#################################################################
function compare_make_chart($pdo) {
#################################################################
    if (ob_get_length()) ob_clean(); 
    
    // 1. 프론트에서 보낸 종목 코드와 날짜를 모두 받습니다!
    $codes_str = $_POST['codes'] ?? '';
    $s_date = $_POST['s_date'] ?? '';
    $e_date = $_POST['e_date'] ?? '';
    
    // 프론트에서 넘어온 순서(배열 인덱스)를 그대로 유지
    $stockCodes = array_filter(explode(',', $codes_str));

    if (empty($stockCodes)) {
        echo "<!DOCTYPE html><html><body style='background:#f8f9fa; display:flex; justify-content:center; align-items:center; height:100vh; margin:0;'><h3 style='color:#888;'>비교할 종목을 선택해주세요.</h3></body></html>";
        exit;
    }

    try {
        $repo = new Stock_Analysis_Repository($pdo);
        // 2. 💡 날짜 파라미터도 함께 넘겨줍니다!
        $chartData = $repo->getChartData($stockCodes, $s_date, $e_date);
        $chartDataJson = json_encode($chartData, JSON_UNESCAPED_UNICODE);

        echo "
        <!DOCTYPE html>
        <html lang='ko'>
        <head>
            <meta charset='utf-8'>
            <title>비교 대시보드</title>
            <script src='https://cdn.jsdelivr.net/npm/apexcharts'></script>
            <style>
                body { margin:0; padding:15px; background:#f4f6f9; font-family: 'Malgun Gothic', sans-serif; display: flex; flex-direction: column; gap: 20px; }
                .chart-container { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #eef2f5; }
            </style>
        </head>
        <body>
            <div class='chart-container'>
                <div id='chart1'></div>
            </div>
            
            <div class='chart-container' id='ratioContainer' style='display:none;'>
                <div id='chart2'></div>
            </div>
            
            
<script>
                var rawData = {$chartDataJson};
                
                if (rawData.length > 0) {
                    
                    // 💡 [신규] 차트에 찍히는 '진짜' 첫 날짜와 마지막 날짜를 추출합니다.
                    var actualStartDate = '';
                    var actualEndDate = '';
                    if (rawData[0].data.length > 0) {
                        var firstTs = rawData[0].data[0][0];
                        var lastTs = rawData[0].data[rawData[0].data.length - 1][0];
                        
                        var d1 = new Date(firstTs);
                        actualStartDate = d1.getFullYear() + '-' + String(d1.getMonth() + 1).padStart(2, '0') + '-' + String(d1.getDate()).padStart(2, '0');
                        
                        var d2 = new Date(lastTs);
                        actualEndDate = d2.getFullYear() + '-' + String(d2.getMonth() + 1).padStart(2, '0') + '-' + String(d2.getDate()).padStart(2, '0');
                    }
                    var dateRangeText = actualStartDate ? ('🗓️ 기간: ' + actualStartDate + ' ~ ' + actualEndDate) : '';


                    // ==========================================
                    // 1. 첫 번째 차트 (100 기준 상대지수)
                    // ==========================================
                    var seriesData1 = [];
                    rawData.forEach(function(stock) {
                        var sData = [];
                        stock.data.forEach(function(item) {
                            sData.push([item[0], item[1]]); 
                        });
                        seriesData1.push({ name: stock.name, data: sData });
                    });

                    var options1 = {
                        series: seriesData1,
                        chart: { type: 'line', height: 550, toolbar: { show: true } },
                        stroke: { width: 2, curve: 'smooth' },
                        // 💡 타이틀은 왼쪽, 서브타이틀(날짜)은 오른쪽으로 정렬합니다!
                        
						title: { text: '📈 1. 상대수익률 비교 (시작일 = 100 기준)', style: { fontSize: '20px', fontWeight: 'bold', color: '#333' } },

						subtitle: { text: dateRangeText, align: 'center', margin: 0, offsetX: -10, offsetY: 5, style: { fontSize: '30px', fontWeight: 'bold', color: '#1a73e8' } },
                        xaxis: { type: 'datetime', labels: { datetimeUTC: false } },

                        yaxis: { labels: { formatter: function (v) { return v.toFixed(2); } } },
                        tooltip: { style: {fontSize: '19px' }, x: { format: 'yyyy-MM-dd' }, y: { formatter: function(v) { return v.toFixed(2) + ' pt'; } } },
                        colors: ['#1a73e8', '#e1234a', '#34a853', '#fbbc05'],
                        legend: {
                            position: 'bottom',
                            fontSize: '30px',
                            fontWeight: 'bold',
                            formatter: function(seriesName, opts) {
                                var dataArr = seriesData1[opts.seriesIndex].data;
                                if(dataArr.length === 0) return seriesName;
                                var lastVal = dataArr[dataArr.length - 1][1];
                                return seriesName + ' [ ' + lastVal.toFixed(2) + ' %] ';
                            }
                        }
                    };
                    var chart1 = new ApexCharts(document.querySelector('#chart1'), options1);
                    chart1.render();

                    // ==========================================
                    // 2. 두 번째 차트 (실제가격 비율 계산)
                    // ==========================================
                    if (rawData.length > 1) {
                        document.getElementById('ratioContainer').style.display = 'block';
                        
                        var baseStock = rawData[0];
                        var baseMap = {};
                        baseStock.data.forEach(function(item) {
                            baseMap[item[0]] = item[2]; 
                        });

                        var ratioSeries = [];
                        
                        for (var i = 1; i < rawData.length; i++) {
                            var currentStock = rawData[i];
                            var ratioData = [];

                            currentStock.data.forEach(function(item) {
                                var timestamp = item[0];
                                var currentPrice = item[2]; 
                                
                                if (baseMap[timestamp] && baseMap[timestamp] !== 0) {
                                    var ratio = parseFloat((currentPrice / baseMap[timestamp]).toFixed(4));
                                    ratioData.push([timestamp, ratio]);
                                }
                            });

                            ratioSeries.push({
                                name: currentStock.name + ' / ' + baseStock.name,
                                data: ratioData
                            });
                        }

                        var initialRatio = ratioSeries[0].data[0] ? ratioSeries[0].data[0][1] : 1.0;

                        var options2 = {
                            series: ratioSeries,
                            chart: { type: 'line', height: 550, toolbar: { show: true } },
                            stroke: { width: 2, curve: 'smooth' },
                            // 💡 두 번째 차트에도 동일하게 날짜를 오른쪽에 배치합니다.
                            title: { text: '📊 2. 실제 가격 비율 (페어 비율, 분모: ' + baseStock.name + ')', style: { fontSize: '25px', fontWeight: 'bold', color: '#333' } },

								subtitle: { text: dateRangeText, align: 'center', margin: 0, offsetX: -10, offsetY: 5, style: { fontSize: '30px', fontWeight: 'bold' } },
                        xaxis: { type: 'datetime', labels: { datetimeUTC: false } },

                            yaxis: { 
                                labels: { formatter: function (v) { return v.toFixed(4); } },
                                title: { text: '가격 비율' }
                            },
                            annotations: {
                                yaxis: [{ 
                                    y: initialRatio, 
                                    borderColor: '#888', 
                                    strokeDashArray: 4, 
                                    label: { text: '시작 비율 (' + initialRatio.toFixed(3) + ')', style: { color: '#888', background: '#fff' } } 
                                }]
                            },
                            tooltip: { style: {fontSize: '19px' },x: { format: 'yyyy-MM-dd' }, y: { formatter: function(v) { return v.toFixed(2); } } },
                            colors: ['#e1234a', '#34a853', '#fbbc05'],
                            legend: {
                                position: 'bottom',
                                fontSize: '14px',
                                fontWeight: 'bold',
                                formatter: function(seriesName, opts) {
                                    var dataArr = ratioSeries[opts.seriesIndex].data;
                                    if(dataArr.length === 0) return seriesName;
                                    var lastVal = dataArr[dataArr.length - 1][1];
                                    return seriesName + ' [' + lastVal.toFixed(4) + ']';
                                }
                            }
                        };
                        var chart2 = new ApexCharts(document.querySelector('#chart2'), options2);
                        chart2.render();
                    }
                }
            </script>

        </body>
        </html>
        ";
    } catch (Exception $e) {
        echo "<!DOCTYPE html><html><body><h3 style='color:red;'>DB 에러: " . htmlspecialchars($e->getMessage()) . "</h3></body></html>";
    }
    exit; 
}
// compare_make_chart 함수 끝
#################################################################


#################################################################
function attach_file_update ($pdo) {
#################################################################


require "./env/e.fnc";

    $GR_Vals = Get_Vals('mode');

// 1. 폼 데이터 받기
// 공백이 들어오는 것을 방지하기 위해 trim() 추가
$stock_code = trim($_POST['stock_code'] ?? ''); 
$uploaded_file = $_FILES['upfile'];

// 🔴 [핵심 보완] 종목코드 누락(빈 값) 원천 차단
if (empty($stock_code)) {
    die("<script>alert('종목코드가 누락되었습니다.\\n검색창에서 종목을 검색한 후 반드시 목록에서 클릭(선택)해주세요.'); history.back();</script>");
}


// 2. 업로드 에러 및 확장자 체크
if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
    die("<script>alert('파일 업로드 중 에러가 발생했습니다.'); history.back();</script>");
}

$ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    die("<script>alert('CSV 파일만 업로드 가능합니다.'); history.back();</script>");
}

// $table_name 은 클래스 내부에서 처리하므로 여기서는 선언할 필요가 없습니다.

    try {
        $pdo->beginTransaction();
        
        // 💡 클래스 인스턴스화
        $repo = new Stock_Analysis_Repository($pdo);

        // 1. 기존 데이터 삭제 (💡 string으로 강제 형변환하여 에러 원천 차단)
        $deleted_count = $repo->deleteDataByStockCode((string)$stock_code);

        // 2. INSERT 준비 (클래스에서 Statement만 받아옴)
        $stmt = $repo->getInsertStatement();

        // 3. 파일 파싱 및 적재
        $tmp_file = $uploaded_file['tmp_name'];
        if (($handle = fopen($tmp_file, "r")) !== FALSE) {
            fgetcsv($handle); // 헤더 스킵
            $insert_count = 0;

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 5) continue; 
                
                // 🔴 [수정] 주석으로 생략되었던 데이터 정제 로직 복구
                $trade_date  = str_replace('/', '-', trim($data[0]));             
                $open_price  = (int)str_replace(',', '', $data[1]);
                $high_price  = (int)str_replace(',', '', $data[2]);
                $low_price   = (int)str_replace(',', '', $data[3]);
                $close_price = (int)str_replace(',', '', $data[4]);
                
                $trade_vol     = isset($data[8]) ? (int)str_replace(',', '', $data[8]) : 0;
                $trade_vol_cap = isset($data[9]) ? (int)str_replace(',', '', $data[9]) : 0;
                
                // 실행
                $stmt->execute([
                    $trade_date, $stock_code, $open_price, $high_price, 
                    $low_price, $close_price, $trade_vol, $trade_vol_cap
                ]);
                $insert_count++;
            }
            fclose($handle);
        }

        $pdo->commit();

         header("Location: " . CUR_PHP . "?mode=sal");
        //echo "<script>location.href='" . CUR_PHP . "?mode=sal';</script>";

    } catch (Exception $e) {
        $pdo->rollBack();
        // 💡 실무 팁: 에러 발생 시 개발자가 원인을 바로 알 수 있도록 $e->getMessage() 출력
        error_log("CSV Upload Error: " . $e->getMessage()); 
        echo "<script>alert('DB 저장 오류: " . addslashes($e->getMessage()) . "'); history.back();</script>";
    }

#################################################################
} # end of 
#################################################################



#################################################################
function api_find_stock($pdo) {
#################################################################
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json; charset=utf-8');

    $keyword = trim($_REQUEST['keyword'] ?? '');
    if (!$keyword) {
        echo json_encode(['success' => false, 'msg' => '검색어를 입력해주세요.']);
        exit;
    }

    try {
        // 💡 클래스 인스턴스화 및 메서드 호출 (단 2줄로 끝!)
        $repo = new Stock_Analysis_Repository($pdo);
        $rows = $repo->searchStocks($keyword);

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







?>