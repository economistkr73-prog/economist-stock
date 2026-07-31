# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 프로젝트 개요

**이코노미스트의 주식이야기** — PHP/MySQL 기반 개인 주식 분석 웹 애플리케이션.  
로그인 인증 후 ETF/주식 분석, 일간 뉴스 수집 기능을 제공한다.

## 기술 스택

- **백엔드**: PHP 8.4 (파일 단위 라우팅, 프레임워크 없음)
- **DB**: MariaDB 10.6.17, `economist73` 스키마
- **프론트**: Vanilla JS + ApexCharts/ECharts (`env/js/`, `style/`)
- **배포**: 웹 루트 직접 서빙 (Apache/Nginx 기준 `/` = `D:\Claude\www`)

## 부트스트랩 순서

모든 페이지는 맨 위에서 아래 순서를 따른다:

```php
require_once "./env/cnt.inc";      // DB 연결(mysqli + PDO) + 클래스 오토로더 + StockSummaryCache 초기화
require_once "./env/auth_fnc.php"; // 인증 함수
require_login();                   // 미로그인 시 /lg.php 리다이렉트
```

## 데이터베이스 연결 이중 구조

`env/cnt.inc`는 두 가지 연결 객체를 전역으로 제공한다.

| 변수 | 드라이버 | 용도 |
|------|---------|------|
| `$connect` | mysqli | 레거시 코드 |
| `$pdo` | PDO (utf8mb4, ERRMODE_EXCEPTION) | 신규 코드 — 반드시 이것 사용 |

새 쿼리는 항상 `$pdo` + Prepared Statement로 작성한다.

## 클래스 구조 (`classes/`)

확장자는 `.class`이며, `cnt.inc`의 `spl_autoload_register`가 자동 로드한다 (`/classes/{ClassName}.class`).

| 클래스 | 역할 |
|--------|------|
| `StockSummaryCache` | ETF 편입 요약 정보 전체를 최초 1회만 DB에서 읽어 메모리에 캐시 (Lazy Loading) |
| `StockRepository` | 종목 정보 / ETF holdings 조회 PDO 쿼리 집합 |
| `NaverFinanceAPI` | 네이버 금융 크롤링 |
| `UIHelper` | 정렬 아이콘 등 공통 HTML 생성 |
| `ChartHelper` | ECharts 트리맵 등 차트 HTML 렌더링 |
| `Stock_Analysis_Repository` | 주식 그래프/분석용 쿼리 |
| `data_processors` | 데이터 가공 로직 |

## 주요 페이지별 역할

| 파일 | 설명 |
|------|------|
| `etf_stock.php` | ETF/주식 분석 메인. `?mode=` 파라미터로 내부 라우팅 (`ef`, `si`, `eshl`, `elbs`, `slbe`, `gsnb` 등) |
| `stock_analysis.php` | 상승종목 분석 대시보드 (`?mode=updash`, `stock_analysis_api.php` 소비) |
| `analysis_model.php` | 분석 모델 정의 |
| `daily_news.php` | 일간 뉴스 수집 및 표시 |
| `stock_thema_news.php` | 종목 테마 뉴스 |
| `cron_job.php` | **모든 크론의 유일한 진입점** (레지스트리+디스패처). 구현은 `cron/` — 아래 「크론」 절 |
| `rss_feed.php` | RSS 피드 출력 |
| `lg.php` / `gi.php` | 로그인 / 회원가입 |
| `classes/data_upload.php` | 데이터 수동 입력 UI |

## 인증 흐름

- `require_login()` → 세션에 `$_SESSION['usr_name']` 없으면 `/lg.php?url=...` 리다이렉트
- 로그인 성공 시 `acc_log_on()` → `tbl_users` 조회 + `password_verify()` + `tbl_users_log` 기록
- 기본 로그인 후 랜딩: `etf_stock.php?mode=si`

## `etf_stock.php` 다중 창 패턴

행 클릭 시 `openCommonFrames(rowId, stockCode, params, targets, curPhp)`를 호출해 여러 named window를 열어 종목 상세를 표시한다. `urlMap`에서 target 이름(`etf_t1`, `etf_d1`, `etf_d2`, `etf_d5`)과 `mode`를 매핑한다.

## 크론(배치)

**`CRON.md` 를 먼저 읽는다.** 전체표·잡별 상세(역할·소스·쓰는 테이블·실측 소요·이어받기)·외부 API 한도·테이블 역인덱스·신규 크론 체크리스트가 들어 있다.

구조 — **크론 파일을 루트에 새로 만들지 않는다.**

| 위치 | 역할 |
|------|------|
| `cron_job.php` | 유일한 진입점. `TASKS` 레지스트리가 "어떤 파일을 어떤 인자로 부를지" 결정 |
| `cron/*.php` | 실제 구현. env 는 `$_SERVER['DOCUMENT_ROOT']` 기준으로 읽는다 |
| `cron/_boot.php` | CLI 실행 시 `DOCUMENT_ROOT` 세팅 (각 파일 맨 위에서 require) |
| `env/cronbg.inc` | 30초 타임아웃 우회(자기호출 bg) + 로그 + 시간예산 공용 헬퍼 |

- 크론 사이트(cron-job.org)에 등록되는 URL은 **`cron_job.php?task=<이름>&k=…` 하나뿐**이다.
  모드·옵션을 바꿀 때 **크론 사이트를 건드리지 않고 `TASKS` 만 고친다.**
- 목록 `?task=list&k=…` · 실행 전 확인 `&explain=1` · bg 로그 `&log=1`
- 핵심 제약: 응답 타임아웃 **30초**, SAPI 가 **apache2handler**(`fastcgi_finish_request` 없음)
  → 30초 넘는 잡은 **`env/cronbg.inc` 의 자기호출 bg** 만 통한다 (옛 `Connection: close` 패턴은 무효).

## 환경 설정 파일

| 파일 | 내용 |
|------|------|
| `env/cnt.inc` | DB 자격증명 (`.gitignore`로 보호) |
| `env/e.fnc` | 공통 유틸 함수 |
| `env/inf.fnc` | 기타 정보 함수 |
| `env/prj.fnc` | 프로젝트별 함수 |
| `env/header.php` | 공통 HTML 헤더 + 상단 네비게이션 바 |

`env/cnt.inc`에는 DB 비밀번호가 평문으로 있다. 절대 커밋하지 않는다 (`.gitignore` 적용됨).

## CSS / JS

- `style/economist.css`, `style/economist.js` — 사이트 공통 스타일·스크립트
- `env/js/apexcharts.js`, `env/js/calender.js` — 외부 라이브러리 로컬 복사본

## 서버 환경 (cafe24 웹호스팅)

| 항목 | 버전 |
|------|------|
| PHP | 8.4 |
| DB | MariaDB 10.6.17 |
| 서버 IP | 220.73.160.47 (uws8-wpm-151) |
| SSH/SFTP 접속 | `economist73@220.73.160.47` (port 22, 비밀번호 인증) |
| 배포 | `.vscode/sftp.json` SFTP 자동업로드. remotePath는 chroot 경로 `/economist73/www` |

## 모바일 감지

`cnt.inc`에서 `$mobile` 변수(0/1)를 전역 세팅한다. User-Agent 기반이며 페이지별로 레이아웃 분기에 사용한다.
