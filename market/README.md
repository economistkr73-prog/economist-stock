# 시장동향 데일리 브리핑 (market/)

매일 새벽 시장 데이터를 크롤 → 정규화 → 품질게이트 → 스냅샷 저장하고,
아침에 전일대비·이상치·Claude 브리핑을 얹어 HTML 리포트를 생성한다.

설계 문서: `daily-brief-mockup.html`, `market-report-spec.md`, `market-crawl-handoff.md`

## 파일

| 파일 | 역할 |
|------|------|
| `config.php` | 소스 선택목록·키·모델 |
| `lib.php`    | HTTP·DOM파싱·정규화·품질게이트 (외부 의존성 없음, DOMDocument) |
| `db.php`     | 저장소(DB). `market_snapshot` 테이블 자동생성·저장·로드 |
| `crawl.php`  | 7개 소스 수집 → `market_snapshot.data`(JSON) upsert |
| `report.php` | 스냅샷 → 이상치탐지 → Claude 브리핑 → `market_snapshot.html` 캐시 |

## 저장 구조 (DB — A안: 날짜 + JSON 컬럼)

```
market_snapshot(
  snap_date DATE PK,   -- 하루 1행
  data      LONGTEXT,  -- 스냅샷 JSON (전일대비·추이는 날짜 비교)
  html      LONGTEXT,  -- 생성된 리포트 HTML (캐시)
  html_at, created_at, updated_at )
```

- 자격증명은 `env/cnt.inc`(gitignore)에서 읽어 직접 PDO 연결(StockSummaryCache 등 부작용 회피).
- 테이블은 `CREATE TABLE IF NOT EXISTS` 로 첫 실행 시 자동 생성(별도 마이그레이션 불필요).
- `report.php` 는 `html` 이 있으면 즉시 서빙(캐시), `?force=1` 또는 크론에서만 재생성.

## 데이터 소스 (실측 확인 2026-06)

| 섹션 | 소스 | 상태 |
|------|------|------|
| 해외지수·환율·채권금리·원자재 | 한경 데이터센터 (1·6·12개월 ctx 포함) | ✅ |
| 국내지수(코스피/코스닥/200) | 네이버 polling JSON | ✅ |
| 유가·금(WTI·국제금) | 네이버 marketindex 메인 카드 | ✅ |
| 증시 자금동향(예탁금·신용·펀드) | 네이버 sise_deposit | ✅ |
| 투자자별 매매동향 | 네이버 investorDealTrendDay | ✅ |
| 은·백금, 외국인 순매매 종목상위 | — | ⏳ 함께 채울 항목 (0이 아닌 누락) |

## 실행

```bash
# CLI
php market/crawl.php          # 오늘 스냅샷 생성
php market/report.php         # 오늘 리포트 생성

# WEB (키 필요)
/market/crawl.php?key=econ-mkt-7x3k
/market/report.php?key=econ-mkt-7x3k        # 화면에 리포트 출력 + 파일 저장
```

## 크론 (cafe24 자체크론 없음 → cron-job.org 등 외부 URL 크론)

```
06:00  https://economist.kr/market/crawl.php?key=econ-mkt-7x3k
07:00  https://economist.kr/market/report.php?key=econ-mkt-7x3k
```

## Claude 브리핑 키

`report.php`는 키가 있을 때만 Claude(`claude-sonnet-4-6`)로 데스크 코멘트를 생성하고,
없거나 실패하면 이상치 요약으로 자동 폴백한다(리포트는 항상 생성됨). 키 지정 방법(둘 중 하나):

1. 환경변수 `ANTHROPIC_API_KEY`
2. `env/anthropic.inc` 파일에 키 문자열(`sk-...`) 한 줄

## 규칙 (spec 요약)

- 주축 = **전일비**, 항목당 컨텍스트 1개(1년 또는 커브 스프레드).
- 채권 = **bp 표기**(레벨 + 전일 bp), 커브 10Y−1Y·30Y−20Y·BBB−스프레드.
- 색: 상승 red(▲) / 하락 blue(▼) (한국 관습).
- **누락은 0%가 아니라 `missing`** 으로 분리 → 리포트 하단 "미수집·함께 채울 항목".
