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

## /stock 포트폴리오 — ★불변 규칙 (2026-08-03)

**`요건정의서_v0.4_주식포트폴리오.md` 를 먼저 읽는다.** 아래는 그 문서가 낳은 여섯 규칙이며,
어기면 「화면끼리 다른 말을 하는」 상태로 되돌아간다. 전부 실측으로 값을 치른 것들이다.

1. **포트폴리오 화면에 탐색 판정(퀀트신호·퀀트경로·트리거)을 세우지 않는다.**
   여기는 *이미 산 종목*을 보는 자리라 오늘의 퀀트 판정은 편입 판단과 무관하다.
   예외는 둘뿐 — 「계단관통↓」(경보 = 판정의 *결과*)과 「편입 당시」(`pf_entry_snapshot` = *기록*).
   ★원장에서 **오늘 기준으로 계산한 시장 지표**(모멘텀 칩 등)는 공통층이라 허용. 금지 기준은
   「시장을 읽었나」가 아니라 「**판정을 가져왔나**」다.
2. **`krx_surge` 를 포트폴리오 로직에서 읽을 때는 반드시 `pf_position.surge_event_d` 를 경유한다.**
   `SELECT MAX(d) FROM krx_surge …` 패턴을 새로 만들지 않는다 — 그러면 경보가
   「내가 산 이유였던 박스」가 아니라 「가장 최근에 뜬 아무 박스」를 뜻하게 된다.
3. **임계값 리터럴을 새로 적지 않는다 — `classes/Thr.class` 를 경유한다.**
   문서 화면(검증·신호분석)의 *설명 문구*도 상수에서 보간한다. 손으로 적으면 상수를 고칠 때
   글이 조용히 거짓이 된다(실제로 「임계값은 전부 실측에서 왔다」가 틀린 문장으로 남아 있었다).
4. **`KrxAmt::invalidateSurge()` 에 `krx_surge_event` 삭제를 넣지 않는다.** 그 표는 append-only 다.
   `krx_surge` 는 크론이 매일 범위 삭제하는 **캐시**이고, 이벤트 표는 그걸 견디라고 따로 있다.
5. **차수 지연 판정 적용 3곳(`pf_load_calc` · 종목상세 · api payload)은 함께 고친다.**
   하나만 고치면 같은 종목이 화면마다 다른 판정을 낸다. `pf_calc_closed` 도 같은 3곳이다.
6. **새 테이블은 접두어로 소속을 밝힌다** — 포트폴리오 `pf_` · KRX 원장 계열 `krx_`.
   (economist73 은 127 테이블을 공유하는 스키마다.)

★ 자주 밟는 함정: `Pf::positionGet()` 은 `pf_stock`·`pf_portfolio`·`pf_rule_set` 과 **INNER JOIN** 이라
어느 하나라도 비면 **행 전체가 null** 이다. 컬럼 하나가 필요하면 단일 테이블로 읽는다.

## 차트 (일봉/주봉/분봉) — ★업무 규칙

**사이트의 모든 시세 차트는 `style/dailychart.js` 공용모듈로만 만들고, 반드시 `chart_gallery.php`(차트설정)에 정의된 구성 5종 중 하나를 기준으로 한다.** 임의 옵션 조합의 "맨몸 차트"를 새로 만들지 않는다.

| 구성 | 테마 | 특징 | 사용처 |
|------|------|------|--------|
| ① 기본형 | light | 캔들 + 거래량 | 재무분석(fund) |
| ② 포트폴리오형 | light | 가격선 + 체결 칩 | 포지션 상세·종목추가(박스 사다리) |
| ③ 시뮬레이터형 | light | 계단선 + 마커 텍스트(`chips:false`) | 시뮬레이터(sim) |
| ④ 분석형 | dark | 당일전고(`todayHigh`) + 현재가 | updash |
| ⑤ 분봉 | dark | `kind:'minute'` | 분봉 뷰 |

**차트에는 축이 둘이다 — 위 표는 「스타일」이고, 「기능」(도구모음·레이어)은 `classes/ChartFeat.class` 가 원본이다.**

| 축 | 무엇 | 원본 | 화면 |
|----|------|------|------|
| 스타일 | 어떻게 그리나 (색·마커 모양·테마) | `style/dailychart.js` + 위 구성 5종 | 차트설정 > 스타일 5종 |
| 기능 | 무엇을 보여주고 조작하게 하나 | `classes/ChartFeat.class` (카탈로그) | 차트설정 > **화면별 구성** |

- 기능 목록을 **화면·JS 어디에도 다시 적지 않는다** — 늘리려면 `ChartFeat::FEATURES` 에 한 줄, 화면에 열어 주려면 `SCREENS` 에 한 글자. (Thr.class 와 같은 「원본 → 화면 표시」 패턴)
- 화면은 `ChartFeat::vals('<화면키>')` 로 구성을 읽고 `ChartFeat::script()` 로 JS 에 심는다 → JS 는 `DailyChart.feats('키')`. 안 심은 화면은 전부 켜진 것으로 본다.
- 저장은 `chart_pref.features_json` (카탈로그와의 **차분만**). ★이 표의 행은 지우지 않는다 — 축 행(`fund_day`)과 화면 행(`fund`)이 한 표에 살아서, `prefSave`/`presetDelete` 가 DELETE 하면 기능 구성이 함께 날아간다 (그래서 둘 다 `preset_id=0` UPDATE 로 바꿔 뒀다).
- 데이터 의존성이 없는 화면에서는 켤 수 없다(체크칸 비활성 + 「데이터 없음」). 런타임에 데이터가 없으면 **조용히 생략** — 빈 패널·오류 금지.
- **시간축·가격축은 「데이터 안」에 가둔다**(2026-08-03). 시간축 `fixLeftEdge`+`fixRightEdge` — 최신 봉이 항상 오른쪽 끝이고 휠 줌아웃은 **왼쪽(과거)만** 늘어나며 전부 보이면 거기서 멎는다(빈 여백 금지). 가격축은 **보이는 봉의 고·저 ±10%**(`padPct`)로 꽉 채우고, 라이브러리의 비율 여백은 되빼서 눈금에 영향이 없게 한다. **지표선은 가격축 스케일에서 제외**(멀리 있는 레벨 하나가 캔들을 눌러 납작하게 만든다) · **가격선(누적단가·자동매도가·다음매수가)은 범위를 넓혀서라도 포함**(내가 산 자리가 사라지면 안 된다).
- **★기간(조회 구간)의 주인은 「화면」이지 「차트틀」이 아니다**(2026-08-03). 차트틀은 여러 화면이 공유하므로 거기에 기간을 넣으면 재무분석에서 맞춘 240일이 시뮬레이터까지 따라간다 — 화면마다 차트 폭이 다른데. 우선순위 **①화면 기억(`chart_pref.view_json` 의 축별 `{index,bars}` · 축 전환하면 그 축의 기억으로)** → ②차트틀의 `view`(그 차트틀을 **처음 쓰는** 화면의 시작값) → ③화면 기본값 `defaultIndex`. 기간을 만지면 ①만 갱신되고 차트틀은 안 건드린다. 차트틀을 **고르는** 것은 명시적 행동이라 그때만 ②가 적용된다(그리고 ①이 된다).
- **차트 높이는 사용자가 끌어서 정한다**(2026-08-03). 차트 아래 가장자리의 손잡이(`.dc-rsz`)를 끌면 바뀌고 놓는 순간 **그 화면**이 기억한다(`chart_pref.view_json` 의 `{"h":…}` · 화면 행). 차트틀에 넣지 않는 이유 — 차트틀은 여러 화면이 공유하는 물건이라 한 화면에서 늘리면 다른 화면까지 늘어난다. 화면은 `ChartFeat::boot('<화면>', $pdo)` 한 줄로 `DC_SCREEN`·`DC_FEATS`·`DC_VIEW` 를 심는다(높이를 나중에 받아 오면 화면이 튄다). flex 로 자리를 나눠 갖는 배치(updash)는 `resizeTarget` 으로 늘릴 요소를 지정한다.
- **SUE 공시 마커는 모듈 소유다**(2026-08-03, 모든 차트). 화면은 `create({code})` 또는 `dc.setCode(code)` 로 **종목코드만** 넘기고, 받아오기(`?module=sue`)·「공시 다음 거래일」 스냅·기간 바의 「SUE 공시 N」 토글은 전부 `dailychart.js` 가 한다. 화면에서 마커를 직접 만들지 않는다. 계산 단일본 = `stock/lib/sue.php`(`pf_sue_marks`).

- 새 화면에 차트를 넣을 때: 위 표에서 구성을 고르고 **같은 create() 옵션 세트**를 쓴다. 기존 구성으로 안 되면 **갤러리에 구성을 먼저 추가**한 뒤 사용한다 (갤러리 = 살아있는 스타일 가이드 — 실제 사용처와 어긋나면 안 된다).
- 화면 키(`key`)를 지정해 **사용자 지표·차트틀(차트저장)이 적용**되게 한다. 같은 업무 계열 화면은 키를 공유한다 (예: 포트폴리오 계열 = `position`).
- `dailychart.js` 수정 시 캐시버스트 `?v=` 를 **grep으로 현재 값 확인 후** 전 참조를 함께 올린다.

## /gift 선물 발송 관리 — ★불변 규칙 (2026-08-03)

**`선물관리시스템_요건정의서.md` 를 먼저 읽는다.** 명절(설/추석) 선물 발송을 회차 단위로 관리한다.
구성은 `gift/index.php`(라우터+화면) · `gift/api.php`(module+action) · `gift/export.php`(엑셀) · `classes/Gift.class`(DB 전담).

1. **`gift_item` 은 스냅샷 표다.** 고객명·연락처·주소·물품명·단가를 **값으로 복사**해 둔다.
   확정된 회차는 이후 고객·물품이 바뀌어도 변하면 안 된다. `customer_id`/`product_id` 는 참조용일 뿐이다.
2. **파생값(`total_qty`·`amount`)은 항상 서버가 다시 계산한다.** 화면 계산은 미리보기다.
   `total_qty = 1 + extra_qty` · `amount = unit_price × total_qty`.
3. **물품은 삭제하지 않는다 — `is_active=0` 으로만 내린다.** 고객도 소프트 삭제다(이력 보호).
   단가를 고쳐도 **확정 회차는 불변**이고, 작업중 회차만 물어본 뒤 일괄 반영한다.
4. **작업중(`draft`) 회차는 동시에 하나뿐이다.** 연도·명절 중복은 `uniq_key` 로 막고 `기타`만 꼬리를 붙여 여러 번 허용한다.
5. **「오프라인 전달」은 고객의 성질이지 주소의 결과가 아니다**(2026-08-03 · `gift_customer.is_offline`).
   주소가 있어도 직접 전달하는 고객이 있다. 다만 **주소가 없으면 택배가 불가능하므로 `customerSave()` 가 저장 시점에
   `is_offline=1` 로 못박는다**(화면이 0 을 보내와도 서버 판정이 이긴다). 사람이 켜고 끄는 것은 **주소가 있는 고객뿐**이다.
   발송구분 기본값은 `Gift::defaultDelivery($addr, $offline)`
   — **오프라인이면 「일괄」**, 아니면 주소 있으면 「택배」·없으면 「일괄」. 이 판정을 화면에 다시 적지 않는다.
   되돌리기(`fixDelivery`)는 **「택배」인 행만** 건드린다 — 사용자가 고른 「별도」를 덮어쓰면 안 된다.
6. **인명록(`tbl_contact`) 접근은 `Gift` 의 「인명록 연동」 구역 한 곳뿐이다.** 다른 데서 그 표를 읽지 않는다.
   인명록에는 **우편번호 칸이 없어** 주소만 온다 → 택배로 보내려면 화면에서 우편번호를 채워야 한다.
7. **고객 분류 그룹(`gift_group`)은 이름이 아니라 `gift_customer.group_id` 로 잇는다**(2026-08-03).
   인명록(`tbl_contact.group_name`)은 문자열이라 이름을 바꾸면 전 행을 UPDATE 해야 했다 — 같은 실수를 반복하지 않는다.
   그룹 삭제는 **`ON DELETE SET NULL`** — 그룹만 사라지고 고객은 미분류로 남는다. 미분류는 별도 행이 아니라 `group_id IS NULL` 이다.
   **그룹 관리는 별도 메뉴가 아니라 고객 명단 화면의 모달**(`#m-grp`)이다 — 쓰는 일이 드물어 탭을 차지할 값어치가 없다.
   순서는 **끌어서 옮긴다**(`gfSortable()` · 놓는 즉시 `group/reorder` 로 1..N 재부여). 숫자를 손으로 적는 칸은 없앴다.
   ★드래그 **포인터 캡처는 움직이는 행이 아니라 `tbody` 에 건다** — `insertBefore` 로 행이 DOM 에서 잠깐 떨어지면
   그 안의 캡처가 풀려 드래그가 끊긴다. `gfSortable(tbody, key, onOrder)` 는 범용이라 다른 표에도 그대로 쓴다.
   ★그룹은 **고객 명단에만 있고 회차·엑셀에는 아직 없다**. 회차에 넣으려면 `gift_item` 에 `group_name` 스냅샷을 더해야 한다(규칙 1).
8. **확정 차단 조건 3가지**(물품 미지정 · 택배인데 주소/우편번호 없음 · 대상 0건)는 `Gift::invalidItems()` 단일본이다.
   화면 하이라이트·집계 「미완성」·확정 검사가 모두 이 함수를 본다 — 조건을 화면에 다시 적지 않는다.
9. **새 표는 `gift_` 접두어.** (economist73 은 127 표를 공유하는 스키마다.)

★ 함정: **엑셀 원본의 「단가」 칸에는 합계 금액이 들어 있다**(50,000×2=100,000).
가져오기가 `단가 = 합계 ÷ 총지급` 으로 되돌린다 — 이 규칙을 지우면 금액이 제곱으로 부푼다.

- **엑셀은 PhpSpreadsheet 가 아니다.** 이 서버에 composer 가 없어 `nw/report.php` 의 순수 PHP OOXML 라이터를
  `gift/export.php` 에 가져왔다(`gx_` 접두어 · ZipArchive 없으면 SpreadsheetML 폴백). 라이터를 고칠 일이 생기면 둘 다 본다.
- 우편번호는 길이 둘이다. **화면에서 사람이 고를 때는 Daum Postcode**(무료·키 불필요·`schedule.php` 와 같은 지연 로딩),
  **서버가 주소만 갖고 찾을 때는 `classes/Zipcode.class`**(카카오 로컬 → 카카오 키워드 → 네이버 지오코딩 3단 폴백).
  실측 49건 중 48건 성공. ★건물명으로 찾은 건(`src=kakao-keyword`)은 단지·동이 갈릴 수 있어 **「확인 필요」로 따로 보고**한다 —
  못 찾은 건은 **빈 칸으로 남긴다**(틀린 우편번호를 채우는 것보다 낫다).
  ★배치로 돌 때 **실패한 id 를 `skip` 으로 넘긴다** — 안 그러면 그 사람이 목록 맨 앞에 남아 같은 조회를 되풀이한다.
- 고객 명단의 **정렬은 전부 표 머리글을 눌러 고른다**(2026-08-03 · 셀렉트·필터 셀렉트를 걷어냄).
  머리글 하나가 두 상태를 오간다 — 고객명 `name`/`namedesc` · 그룹 `group`/`ungroup` · 주소 `addr`/`noaddr` · 전달 `ship`/`offline`.
  `gift_sort_th()` 로만 만들고 `gift_cust_url()` 이 **검색·그룹 필터를 유지한 채 sort 만 바꾼다**. 기본은 `ship`.
  ★전달 기준은 **주소 유무가 아니라 오프라인 체크**다 — 주소가 있어도 직접 전달하는 고객은 아래로 내려가야 한다.
  툴바에 남은 것은 **검색칸(Enter)과 그룹 필터** 둘뿐이다.
- **`gift_customer.source_type` 은 삭제했다**(2026-08-03 사용자 지시). ★**`source_ref_id` 는 지우면 안 된다** —
  인명록 가져오기의 중복 방지(「등록됨」 배지)가 그 값을 쓰고, 채워져 있다는 것 자체가 「인명록에서 왔다」는 뜻이라 출처도 남는다.

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
