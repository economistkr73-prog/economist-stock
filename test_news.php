<?php
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php"; 
require_login(); 


$api = new NaverFinanceAPI();
// =========================================================================
// URL 파라미터(?target_date=2026-05-30)가 없으면 '오늘 날짜'를 기본값으로 세팅
$input_date = $_GET['target_date'] ?? date('Y-m-d'); 

// 네이버 URL 형식(YYYYMMDD)에 맞게 하이픈(-) 제거: '2026-05-30' -> '20260530'
$target_date = str_replace('-', '', $input_date);

// =========================================================================
// 2. 뉴스 수집 및 키워드 분석
// =========================================================================
// =========================================================================
// 2. 뉴스 수집 및 키워드 분석
// =========================================================================
$all_news = getNaverFinanceSectionNews($target_date, 0, true);
$titles_only = array_column($all_news, 'title');

// 키워드 분석 (빈 배열일 때 에러 안 나도록 방어 로직 추가)
$hot_keywords = [];
if (!empty($titles_only)) {
    $hot_keywords = $api->get_market_keywords($titles_only, 8);
}

// 자바스크립트용 JSON 데이터 변환
$news_json = json_encode($all_news, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($news_json === false) { $news_json = '[]'; }


// =========================================================================
// 3. UI 출력 시작 (날짜 선택 폼 + 키워드 레이더 + 모달 팝업)
// =========================================================================

// ⏳ 로딩 화면 오버레이
$html = '
<div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(255,255,255,0.85); backdrop-filter: blur(5px); z-index: 9999; flex-direction: column; align-items: center; justify-content: center;">
    <div style="background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); text-align: center; width: 350px;">
        <div id="loadingEmoji" style="font-size: 3rem; margin-bottom: 10px;">⏳</div>
        <h3 style="margin: 0 0 8px 0; color: #1e293b; font-size: 1.2rem;">뉴스를 수집하고 있습니다</h3>
        <p style="margin: 0 0 25px 0; color: #64748b; font-size: 0.9rem;">전체 페이지를 스캔 중입니다. 잠시만 기다려주세요.</p>
        
        <div style="width: 100%; height: 10px; background: #e2e8f0; border-radius: 5px; overflow: hidden; margin-bottom: 12px;">
            <div id="progressBar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #3b82f6, #2563eb); transition: width 0.3s ease-out;"></div>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span id="progressStatus" style="color: #64748b; font-size: 0.85rem;">서버 통신 중...</span>
            <span id="progressText" style="color: #2563eb; font-weight: 900; font-size: 1.1rem;">0%</span>
        </div>
    </div>
</div>';

// 📅 상단 날짜 선택 UI
$html .= '
<div style="margin-bottom: 25px; background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
    <form method="GET" action="" onsubmit="return startLoading();" style="display: flex; align-items: center; gap: 15px; margin: 0;">
        <span style="font-size: 1.4rem;">📅</span>
        <div style="display: flex; flex-direction: column;">
            <label for="target_date" style="font-size: 0.85rem; color: #64748b; font-weight: bold; margin-bottom: 4px;">뉴스 수집 일자 선택</label>
            <div style="display: flex; gap: 10px;">
                <input type="date" id="target_date" name="target_date" value="' . htmlspecialchars($input_date) . '" 
                       style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; color: #334155; outline: none;">
                
                <button type="submit" style="background: #2563eb; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background=\'#1d4ed8\'" onmouseout="this.style.background=\'#2563eb\'">
                    불러오기
                </button>
            </div>
        </div>
        <div style="margin-left: auto; color: #64748b; font-size: 0.9rem;">
            총 <b style="color: #2563eb;">' . count($all_news) . '</b>개의 기사 수집됨
        </div>
    </form>
</div>
';


// 🎯 키워드 레이더 UI
if (!empty($hot_keywords)) {
    $html .= '
    <div style="margin-bottom: 25px; background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
            <span style="font-size: 1.4rem;">🎯</span>
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b;">시장 주도 키워드 레이더</h3>
                <p style="margin: 2px 0 0 0; color: #64748b; font-size: 0.85rem;">클릭하시면 해당 키워드가 포함된 뉴스를 확인할 수 있습니다.</p>
            </div>
        </div>
        
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">';
    
    $rank = 1;
    foreach ($hot_keywords as $keyword => $mentioned_count) {
        if ($rank <= 2) {
            $bg = '#ef4444'; $color = 'white'; $size = '1.05rem'; 
        } elseif ($rank <= 4) {
            $bg = '#fca5a5'; $color = '#7f1d1d'; $size = '0.95rem';   
        } else {
            $bg = '#f1f5f9'; $color = '#475569'; $size = '0.9rem'; 
        }

        $html .= "
        <div onclick=\"showRelatedNews('{$keyword}')\" onmouseover=\"this.style.opacity='0.8'\" onmouseout=\"this.style.opacity='1'\" style='cursor: pointer; transition: opacity 0.2s; display: inline-flex; align-items: center; background: {$bg}; color: {$color}; padding: 6px 14px; border-radius: 20px; font-weight: 800; font-size: {$size}; box-shadow: 0 1px 2px rgba(0,0,0,0.05);'>
            <span style='margin-right: 6px;'>#{$keyword}</span>
            <span style='background: rgba(255,255,255,0.4); border-radius: 10px; padding: 2px 6px; font-size: 0.75em;'>{$mentioned_count}건</span>
        </div>";
        $rank++;
    }
    
    $html .= '
        </div>
    </div>';

    // 📰 뉴스 리스트 영역 & 기사 원문 팝업창 HTML 추가
    $html .= "
    <div id='keywordNewsContainer' style='display: none; margin-bottom: 25px; background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 5px solid #3b82f6;'>
        <div style='display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 12px;'>
            <h4 id='keywordNewsTitle' style='margin: 0; font-size: 1.05rem; color: #1e293b;'></h4>
            <button onclick='closeNewsContainer()' style='background: #f1f5f9; border: none; padding: 6px 12px; border-radius: 8px; cursor: pointer; color: #475569; font-weight: bold; transition: background 0.2s;' onmouseover=\"this.style.background='#e2e8f0'\" onmouseout=\"this.style.background='#f1f5f9'\">닫기 ✕</button>
        </div>
        <ul id='keywordNewsList' style='list-style: none; padding: 0; margin: 0;'></ul>
    </div>

    <div id='articleModalOverlay' onclick='if(event.target===this) closeArticleModal()' style='display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); z-index: 99999; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box;'>
        <div style='background: white; width: 100%; max-width: 800px; height: 90vh; border-radius: 16px; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden;'>
            <div style='display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;'>
                <h3 style='margin: 0; font-size: 1.1rem; color: #1e293b;'>📰 뉴스 원문 보기</h3>
                <button onclick='closeArticleModal()' style='background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: bold;'>✕ 닫기</button>
            </div>
            <div id='articleContentArea' style='padding: 30px; overflow-y: auto; flex-grow: 1; font-size: 1.05rem; line-height: 1.8; color: #334155;'>
                </div>
        </div>
    </div>

    <script>
    const allNewsData = {$news_json};

    // 로딩 애니메이션
    function startLoading() {
        const overlay = document.getElementById('loadingOverlay');
        const bar = document.getElementById('progressBar');
        const text = document.getElementById('progressText');
        const status = document.getElementById('progressStatus');
        const emoji = document.getElementById('loadingEmoji');
        
        overlay.style.display = 'flex';
        let progress = 0;

        const interval = setInterval(() => {
            let increment = 0;
            if (progress < 50) {
                increment = Math.random() * 15;
                status.innerText = '네이버 금융 서버 연결 중...';
                emoji.innerText = '📡';
            } else if (progress < 85) {
                increment = Math.random() * 5;
                status.innerText = '기사 본문 및 키워드 추출 중...';
                emoji.innerText = '🔍';
            } else {
                increment = Math.random() * 1;
                status.innerText = '데이터 정제 및 UI 렌더링 중...';
                emoji.innerText = '⚙️';
            }

            progress += increment;
            if (progress > 98) progress = 98; 

            bar.style.width = progress + '%';
            text.innerText = Math.floor(progress) + '%';
        }, 300);

        return true; 
    }

    // 키워드 뉴스 필터링 출력
    function showRelatedNews(keyword) {
        const container = document.getElementById('keywordNewsContainer');
        const listContainer = document.getElementById('keywordNewsList');
        const titleElement = document.getElementById('keywordNewsTitle');
        const filteredNews = allNewsData.filter(news => news.title.includes(keyword));
        titleElement.innerHTML = `📌 <b style='color: #2563eb;'>#'\${keyword}'</b> 관련 뉴스 <span style='color: #64748b; font-size: 0.9rem;'>(\${filteredNews.length}건)</span>`;

        let listHtml = '';
        filteredNews.forEach(news => {
            listHtml += `
                <li style='padding: 10px 0; border-bottom: 1px dashed #e2e8f0; font-size: 0.95rem; line-height: 1.4;'>
                    <a href=\"javascript:void(0);\" onclick=\"openArticle('\${news.link}')\" style='text-decoration: none; color: #334155; transition: color 0.2s;' onmouseover=\"this.style.color='#2563eb'\" onmouseout=\"this.style.color='#334155'\">
                        \${news.title}
                    </a>
                </li>
            `;
        });
        listContainer.innerHTML = listHtml;
        container.style.display = 'block';
        container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

function openArticle(newsUrl) {

    window.open(newsUrl, 'news_naver','width=800,height=900,left=200,top=100,scrollbars=yes');
}



    </script>
    ";
} elseif ($_GET['target_date'] ?? false) {
    $html .= '
    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 15px; border-radius: 12px; text-align: center;">
        선택하신 날짜(' . htmlspecialchars($input_date) . ')에는 수집된 뉴스가 없습니다.
    </div>';
}

echo $html;


############################# 
    /**
     * 네이버 금융 분야별 뉴스 리스트 수집 (미리 전체 페이지 파악 후 수집)
     * @param string $date 수집할 날짜 (예: '20260530')
     * @param int $limit 가져올 최대 뉴스 개수 (기본 20개, $fetchAllPages가 true면 무시됨)
     * @param bool $fetchAllPages true로 설정 시 해당 일자의 '모든 페이지' 기사를 수집
     * @return array
     */
 function getNaverFinanceSectionNews(string $date, int $limit = 20, bool $fetchAllPages = false): array {   
        $newsList = [];
        $seenTitles = []; 
        $fetched_count = 0;
        
        $maxPage = 1; // 기본적으로 최소 1페이지는 있다고 가정

        // ====================================================================
        // 💡 [STEP 1] 1페이지를 먼저 열어서 '맨뒤' 버튼을 찾고 전체 페이지 수 알아내기
        // ====================================================================
        $page = 1;
        while ($page <= $maxPage) {
            $url = "https://finance.naver.com/news/news_list.naver?mode=LSS2D&section_id=101&section_id2=258&date={$date}&page={$page}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_REFERER => "https://finance.naver.com/news/news_list.naver",
                CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 10
            ]);
            $html = curl_exec($ch);
            curl_close($ch);

            if (!$html) break;

            $html = mb_convert_encoding($html, 'UTF-8', 'EUC-KR');
            $html = preg_replace('/<meta[^>]+charset=[\'"]?(euc-kr|EUC-KR)[\'"]?[^>]*>/i', '', $html);
            $html = '<?xml encoding="UTF-8">' . $html; 

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);

            // 🚀 [핵심 로직] 1페이지를 스캔할 때만 '총 페이지 수'를 계산합니다.
            if ($page === 1 && $fetchAllPages) {
                // 'pgRR' (맨뒤) 클래스를 가진 태그 안의 <a> 링크를 찾음
                $pgRRNode = $xpath->query("//td[contains(@class, 'pgRR')]/a")->item(0);
                
                if ($pgRRNode) {
                    $lastHref = $pgRRNode->getAttribute('href');
                    // 정규식(Regex)을 사용해 주소에서 'page=숫자' 부분만 완벽하게 추출
                    if (preg_match('/page=(\d+)/', $lastHref, $matches)) {
                        $maxPage = (int)$matches[1]; 
                    }
                }
            }

            // ====================================================================
            // 💡 [STEP 2] 알아낸 전체 페이지 수(maxPage)만큼 기사 파싱
            // ====================================================================
            $subjects = $xpath->query("//dt[contains(@class, 'articleSubject')] | //dd[contains(@class, 'articleSubject')]");

            if ($subjects->length === 0) break;

            foreach ($subjects as $subject) {
                if (!$fetchAllPages && $fetched_count >= $limit) {
                    break 2; // 전체 페이지 수집 모드가 아니면 한도 초과 시 완전 종료
                }

                $aTag = $xpath->query("./a", $subject)->item(0);
                if (!$aTag) continue;

                $title = trim($aTag->nodeValue);

                // 중복 기사 제거
                if (empty($title) || isset($seenTitles[$title])) {
                    continue;
                }
                $seenTitles[$title] = true;

                $href = $aTag->getAttribute('href');
                $link = (strpos($href, 'http') === 0) ? $href : "https://finance.naver.com" . $href;

                $summaryNode = $xpath->query("following-sibling::dd[contains(@class, 'articleSummary')][1]", $subject)->item(0);
                
                $summary = ''; $press = ''; $newsDate = '';
                if ($summaryNode) {
                    $pressNode = $xpath->query(".//span[contains(@class, 'press')]", $summaryNode)->item(0);
                    $dateNode  = $xpath->query(".//span[contains(@class, 'wdate')]", $summaryNode)->item(0);

                    $press = $pressNode ? trim($pressNode->nodeValue) : '';
                    $newsDate  = $dateNode ? trim($dateNode->nodeValue) : '';

                    $summaryText = str_replace([$press, $newsDate, '|'], '', $summaryNode->nodeValue);
                    $summary = trim(preg_replace('/\s+/', ' ', $summaryText)); 
                }

                $newsList[] = [
                    'title'   => $title,
                    'link'    => $link,
                    'summary' => $summary,
                    'press'   => $press,
                    'date'    => $newsDate
                ];
                
                $fetched_count++;
            }

            // 다음 페이지로 이동
            $page++;

            // 🚨 IP 차단 방지 휴식 (루프를 계속 돌 때만)
            if ($page <= $maxPage) {
                usleep(300000); 
            }
        }

        $xpath = null;
        $dom = null;

        return $newsList;
    } 
#############################



?>