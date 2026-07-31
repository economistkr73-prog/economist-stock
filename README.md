# 이코노미스트의 주식이야기

주식 분석 및 일간 뉴스 정리 웹 애플리케이션입니다.

## 주요 기능

- 월별 달력 기반 일정 관리 (`index.php`)
- 일간 뉴스 수집 및 정리 (`daily_news.php`)
- ETF/주식 분석 (`etf_stock.php`, `stock_analysis.php`)
- 종목 테마 뉴스 (`stock_thema_news.php`)
- 분석 모델 / 증권 뉴스 리포트 (`analysis_model.php`)
- RSS 피드 제공 (`rss_feed.php`)
- 크론 일체 — 진입점 `cron_job.php`, 구현 `cron/`, 전체 명세 `CRON.md`

## 환경 설정

`env/` 폴더에 DB 연결 정보 등 환경 설정 파일을 위치시킵니다.

## 기술 스택

- PHP
- MySQL
- JavaScript / CSS (`style/` 폴더)
