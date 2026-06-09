/**
 * economist_claude.js
 * Claude 작업으로 추가된 공통 JS 함수 모음 (기존 economist.js 와 분리 관리)
 */

/**
 * 다중 프레임 호출 및 행 하이라이트 공통 함수
 * etf_stock.php / analysis_model.php 공용
 * @param {string} rowId     클릭한 행 ID (하이라이트용, 없으면 null)
 * @param {string} stockCode 종목코드
 * @param {string} params    공통 전달 파라미터 (쿼리스트링)
 * @param {Array}  targets   열 타겟 프레임 배열 (예: ['etf_t1','etf_d2','etf_d5'])
 * @param {string} curPhp    모드가 정의된 PHP 파일 (예: 'etf_stock.php')
 */
function openCommonFrames(rowId, stockCode, params, targets, curPhp) {
    // 1. 행 하이라이트
    if (rowId) {
        document.querySelectorAll('tr').forEach(function(r) {
            r.classList.remove('row-highlight');
            r.style.backgroundColor = '#fff';
        });
        var current = document.getElementById(rowId);
        if (current) current.classList.add('row-highlight');
    }

    // 2. 타겟별 URL 매핑
    var urlMap = {
        'etf_t1': curPhp + '?mode=eshl&' + params,
        'etf_d1': curPhp + '?mode=elbs&' + params,
        'etf_d2': curPhp + '?mode=slbe&' + params,
        'etf_d5': curPhp + '?mode=gsnb&' + params
    };

    // 3. 전달받은 타겟만 열기
    targets.forEach(function(target) {
        if (urlMap[target]) {
            window.open(urlMap[target], target);
        }
    });
}