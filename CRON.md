# CRON.md — 크론(배치) 전체 명세

**작성 2026-07-30** · 코드 실측 기준(`cron_*.php` 10개 + `market/crawl.php`) + cron-job.org 등록화면 대조

이 문서의 목적: 새 기능을 만들 때 **"이미 있는 크론에 얹을까 / 고칠까 / 새로 만들까"** 를 이 파일만 보고 판단한다.

---

## 0. 30초 안에 알아야 할 것

| 사실 | 내용 |
|------|------|
| ★ 진입점 | **`cron_job.php?task=<이름>&k=…` 하나뿐.** 구현은 `cron/`, 무엇을 부를지는 `TASKS` 레지스트리 (§2.0) |
| 잡을 바꿀 때 | **크론 사이트를 건드리지 않는다** — `cron_job.php` 의 `TASKS` 만 고친다 |
| 크론 실행 주체 | **cron-job.org** (외부 URL 호출). cafe24 웹호스팅은 자체 크론이 없다 |
| ★ 응답 타임아웃 | **30초**. 넘으면 매일 `Failed (timeout)` + flush 순간 PHP가 끊긴 연결을 알아채고 죽는다 |
| ★ 서버 SAPI | **apache2handler** → `fastcgi_finish_request()` **함수 자체가 없다**. 레포의 옛 bg 패턴은 이 서버에서 안 먹는다 |
| 유일하게 검증된 30초 우회 | **자기 자신에게 비동기 소켓 요청**. 던지기 0.07초. 공용 헬퍼 → **`env/cronbg.inc`** |
| 인증 | URL 쿼리 토큰. 파일마다 다르다(§2.1) — 통일되어 있지 않다 |
| 알림 | `Notify::send()` → Pushover (구 카카오톡에서 교체). `env/pushover.inc` |
| 등록 항목 수 | **15개** (파일 7개를 mode/job 파라미터로 나눠 쓴다) |
| 현재 빨간불 | 5개였다 → **3개 해결 완료**, 2개는 **크론 URL에 `&bg=1` 추가만 남음**(#9·#10) (§7 P1·P2) |

---

## 1. 등록된 크론 14개 — 시각순 전체표

시각은 KST(cron-job.org 잡별 타임존 `Asia/Seoul`). `소요`·`상태`는 2026-07-30 화면 실측. crontab 식은 EDIT 화면에서 그대로 옮긴 것.

| # | 주기 (crontab) | 등록 제목 | 실체 (파일 · job/mode) | 소요 | 상태 |
|---|----------------|-----------|------------------------|------|------|
| 1 | 매 10분 | 알림: 사전 알람 | `cron/schedule_alert.php` `mode=check_alerts` | 1.2s | ✅ |
| 2 | 매일 07:00 | 알림: 당일 일정 | `cron/schedule_alert.php` `mode=daily_summary` | 1.9s | ✅ |
| 3 | **평일** 08:05 `5 8 * * 1-5` | 다트 상장주식 재무보고서 | `cron/dart_collect.php` `job=fresh&bg=1` | 0.07s | ✅ **2026-07-30 bg=1 적용** |
| 4 | **월~토** 08:10 `10 8 * * 1-6` | 마켓 모닝 브리핑(데이터수집) | `market/crawl.php` `&report=1&notify=1&bg=1` | 15.6s | ✅ |
| 5 | 매일 10회 `5 6,8-10,12,14,16,18,20,22 * * *` | 네이버뉴스 가져오기 | `cron/keyword_collector.php` `mode=news` | 4.5s | ✅ |
| 6 | **평일 9회** `5 6-9,11,13,15,17,19 * * 1-5` | get_news_keyword (제목 `7회` 는 낡음) | `cron/keyword_collector.php` `mode=stock_etf_news` | 14.3s | ✅ |
| 7 | 매일 13:05 | krx 상장주식수 | `cron/dart_collect.php` `job=krx` | 19.8s | ✅ **오후 1회로 확정** · 2026-07-31 **거래대금 장기보관 이관 추가**(krx_daily→krx_amt · API 0회) |
| 8 | **평일** 15:50 `50 15 * * 1-5` | 당일종가 | `cron/dart_collect.php` `job=eod` | ~3s (2026-08-02 실측 9.1s — 잠정적재·신호 추가분) | ✅ 2026-08-02 EDIT 화면 실확인 |
| 9 | **평일** 16:20 `20 16 * * 1-5` | ETF 편입종목 가져오기 | `cron/keyword_collector.php` `mode=etf_update` | **901.7s** (bg · 2026-08-04 로그 실측 · ETF 435개 × sleep 2초 → **16:35 종료**) | ✅ 2026-08-02 EDIT 화면 실확인 · bg 는 레지스트리가 붙임 |
| 16 | **평일** 16:45 `45 16 * * 1-5` | 키움단타데이터(일1회) | `cron/dt_min.php` `job=daily&bg=1` | 종목당 ~1s (20종목 ≈ 40s · bg) | ✅ **2026-08-04 신설·등록 완료** (Save responses ON · 실패 알림 3종 ON) |
| 11 | 매월 1일 새벽(10분 간격 반복) | 네이버 맛집 | `cron/naver_collect.php` `cat=food&bg=1` | 1.9s* | ✅ |
| 12 | 매월 2일 새벽 03:01 | 네이버 스테이 | `cron/naver_collect.php` `cat=stay&bg=1` | 30s+ | ✅ **완료**(배포됨 · URL 이미 bg=1) |
| 13 | 매월 3일 새벽 02:02 | 네이버 캠핑장 | `cron/naver_collect.php` `cat=camping&bg=1` | 30s+ | ✅ **완료**(배포됨 · URL 이미 bg=1) |
| ~~14~~ | ~~매월 4일 새벽 30회~~ | ~~아덴트뉴스~~ | `cron/ardent_crawl.php` `run_ai=1&bg=1` | 회당 180s | ⛔ **2026-08-04 등록 해제** (§3.6) |
| 15 | 매년 01/01 | 공휴일 가져오기 | `cron/schedule_alert.php` `mode=sync_holidays` | – | ✅ |

\* 맛집 1.9s = 그 회차가 이미 완료돼 **no-op** 였던 것. 실제 수집 중에는 30초를 넘긴다.

⛔ **#10 「상승확률 분석」은 2026-07-31 에 폐기했다** — 전략 자체를 접었다. 코드(`rise_analysis.php`·`RiseAnalyzer.class`·`cron/rise_analysis.php`)·테이블(`rise_*`)·메뉴를 모두 지웠고, **크론 사이트에서도 #10 을 삭제해야 한다.** 번호는 사이트 목록과 어긋나지 않게 그대로 두었다(10번이 비어 있음).

**크론 사이트에 등록할 URL — 전부 `cron_job.php?task=<이름>&k=…` 한 형태다** (§2.0)
```
#1  ?task=alert_check&k=econ-cron-j7k2       #9   …&task=etf_update
#2  ?task=alert_daily&k=econ-cron-j7k2       #10  (폐기 — 상승확률 분석)
#3  ?task=dart_fresh&k=econ-cron-j7k2        #11  …&task=naver_food
#4  ?task=market&k=econ-cron-j7k2            #12  …&task=naver_stay
#5  ?task=news&k=econ-cron-j7k2              #13  …&task=naver_camping
#6  ?task=stock_news&k=econ-cron-j7k2        #14  (해제 — 아덴트뉴스 · 수동 전용)
#7  ?task=dart_krx&k=econ-cron-j7k2          #15  …&task=holidays
#8  ?task=dart_eod&k=econ-cron-j7k2          #16  …&task=dt_min      ← 신설(평일 16:45)
```
앞에 `https://economist.kr/cron_job.php` 를 붙인다. 전체 목록은 `?task=list&k=…`.

### 주기에서 읽어야 할 것

- **#7 `job=krx` 는 오후 13:05 단발이 정답이다.** KRX는 T+1인데다 **다음 날 오전에야** 올라온다(실측: 01:19엔 전일치가 없고 11:58엔 있다). 13:05면 이미 올라온 뒤라 1회로 충분하다. 코드 주석의 "오전·오후 2회"는 올라오는 시각이 확실하지 않을 때의 안전장치였고, **오후 단발로 확정**했다. 새벽으로 옮기면 하루 묵은 값을 받는다 — 절대 앞으로 당기지 말 것.
- **평일(`1-5`) 전용은 #3·#6 둘이다.**
  - #6 → **`all_stock_info` 는 주말에 갱신되지 않는다.** 주말에 화면을 열면 금요일 값이다.
  - #3 → DART 공시 접수가 영업일 기준이라 평일만으로 **무해**하다. 단 금요일 야간~일요일에 정정공시가 올라오면 월요일 08:05까지 안 들어온다(주말 스크리너 열람 시 감안).
  - **#7 `job=krx` 는 매일(`* * *`)**, **#8 `job=eod` 는 평일(`1-5`)** 이다(2026-08-02 EDIT 화면 실확인 — 옛 기록 "eod 매일"은 낡음). 휴장일·주말에는 받을 게 없어 "받을 거래일이 없습니다" / "저장할 값이 없습니다" 로 조용히 빠지므로 어느 쪽이든 무해하다.
- **#4 는 일요일 제외(`1-6`)**. 월요일 브리핑은 금요일 거래일을 기준일로 잡는다(기준일=투자자 매매동향 최신일).
- **★#9 와 #16 은 시각이 붙어 있다 — 겹치지 않게 벌려 뒀다.** #9(16:20)가 실측 **901.7초**로 16:35 까지 도는데,
  #16 을 처음 계획대로 16:25 에 두면 그 한복판에서 시작해 10분을 겹친다(둘 다 bg = 별도 프로세스가 나란히 산다).
  외부 API 도 테이블도 달라 관측된 피해는 없었지만, 늦춰서 잃는 것이 없어 **16:45** 로 물렸다.
  ETF 수가 늘면 #9 의 종료도 뒤로 밀리므로(대상 × 2초), 언젠가 다시 벌려야 한다면 **#16 을 더 뒤로** 민다.
- **#4 의 `bg=1` 은 사실상 무효**다(패턴 A · apache2handler). 다만 전체가 15.6초라 30초 안에 끝나 문제가 되지 않는다.

### SSH/수동 전용 (크론 미등록)
`cron_` 접두어지만 **등록하지 않는다.** 1회성 시딩·백필 도구다.

| 파일 | 용도 | 왜 크론이 아닌가 |
|------|------|------------------|
| `cron/place_geocode.php` | `place` 좌표화 배치 | 크롤러가 좌표 없는 place를 만들 때만 필요. 지금 AI 파이프라인은 적재 시점에 좌표를 찍는다 |
| `cron/waste_geocode.php` | `waste_companies` 좌표화 (위 파일 복제판) | 공제조합 데이터 1회 적재용 |
| `cron/place_tag_backfill.php` | 장소 자동 태깅(사전 기반) | 사전을 고칠 때만 1회 |
| `cron/lunar_seed.php` | 음양력 변환표 1990~2050 (22,300일) | 1회 적재로 끝. `meta refresh` 로 스스로 이어실행 |
| `cron/dart_collect.php` `job=range&full=1` / `quarter` / `shares` | 최초 씨뿌리기(6분~54분) | 30초에 어떻게 쪼개도 안 들어감 → SSH |

---

## 2. 공통 규약

### 2.0 구조 — `cron_job.php` 하나가 전부의 입구다 (2026-07-30 개편)

```
cron_job.php        ← 크론 사이트가 부르는 유일한 URL. TASKS 레지스트리 + 디스패처
cron/               ← 실제 구현 9개
    _boot.php       ← CLI 실행 시 DOCUMENT_ROOT 세팅 (각 파일 맨 위에서 require)
    dart_collect.php  keyword_collector.php  naver_collect.php
    schedule_alert.php  ardent_crawl.php  place_geocode.php  waste_geocode.php
    place_tag_backfill.php  lunar_seed.php
env/cronbg.inc      ← 30초 우회(자기호출 bg) + 로그 + 시간예산 공용 헬퍼
market/crawl.php    ← market 모듈 소속이라 파일은 그대로 두고 레지스트리에만 등록
```

**왜 이렇게 바꿨나** — 크론 사이트(cron-job.org)는 사람만 고칠 수 있다. 예전에는 잡의 모드·옵션을 바꾸려면 매번 그 화면에 들어가야 했다. 이제 등록되는 URL은 **영원히 `cron_job.php?task=<이름>&k=…` 하나**고, 무엇을 어떤 옵션으로 부를지는 **코드(=git)** 인 `TASKS` 레지스트리가 정한다.

**어떻게 도나** — HTTP로 다시 부르지 않고 **같은 요청 안에서 `require`** 한다. `$_GET` 을 레지스트리 값으로 갈아끼우면 타깃은 자기가 직접 호출된 것처럼 돈다(자체 토큰 검사도 레지스트리가 넣어 준 값으로 통과).

★ **bg 가 저절로 맞아떨어진다.** `env/cronbg.inc` 는 자기호출 URL을 `SCRIPT_NAME + 현재 $_GET` 으로 만드는데, `SCRIPT_NAME` 이 `/cron_job.php` 라 되돌아오는 요청도 디스패처를 거쳐 같은 task 로 라우팅된다:
```
cron-job.org → cron_job.php?task=etf_update&k=…      (레지스트리 주입 → require)
                 → cron/keyword_collector.php         cron_bg_begin() 이 소켓을 던짐
                    → cron_job.php?task=etf_update&k=…&run=1   ← 다시 디스패처로
                       → cron/keyword_collector.php   run 모드로 완주
```
그래서 `$_GET` 에 **`k` 와 `task` 를 반드시 남긴다.** 하나라도 빠지면 되돌아온 요청이 토큰 검사에 막히거나 라우팅되지 못해 **bg 작업이 조용히 사라진다.** `&explain=1` 이 그 URL을 미리 보여 준다.

**레지스트리 한 줄의 모양** (`cron_job.php` 의 `TASKS`)
```php
'etf_update' => [
    'file' => 'cron/keyword_collector.php', 'bg' => true, 'cron' => '20 16 * * *',
    'get'  => ['ssk' => KWC_KEY, 'mode' => 'etf_update'],
    'desc' => 'ETF 편입종목 갱신 (ETF당 sleep 2초 · bg 필수)',
],
```
- `get` 이 **사용자 인자를 이긴다** → 토큰·모드는 URL로 못 덮는다(실측 확인).
- 레지스트리에 없는 인자는 **그대로 통과** → `&status=1`·`&log=1`·`&sec=`·`&full=1` 같은 운영·디버그용이 그대로 먹는다.
- `cron` 열은 **사람이 읽는 기록**이다. 진실은 크론 사이트에 있으니, 시각을 바꿨으면 여기도 같이 고친다(§1 표가 이걸 본다).

### 2.1 인증 토큰

**크론 URL에 나오는 토큰은 이제 하나다.**

| 토큰 | 값 | 어디 |
|------|-----|------|
| `?k=` (디스패처) | `econ-cron-j7k2` | `cron_job.php` 의 `CRON_JOB_KEY` — **크론 사이트에 등록되는 유일한 토큰** |

타깃들의 자체 토큰은 그대로 살아 있지만 **레지스트리가 넣어 주므로 URL에는 안 나온다.** 각 파일을 직접 호출할 때만 필요하다(포워더 스텁·SSH).

| 방식 | 파일 | 값 |
|------|------|-----|
| `?ssk=` | `keyword_collector` · `schedule_alert` | `mysn1973!` (값에 `!` 포함) |
| `?key=` | `dart_collect` | `econ-dart-collect` |
| `?key=` | `naver_collect` | `econ-naver-9x2k` |
| `?key=` | `ardent_crawl` | `econ-ardent` |
| `?key=` | `place_geocode` / `waste_geocode` / `place_tag_backfill` / `lunar_seed` | `econ-place-geo` / `econ-waste-geo` / `econ-place-tag` / `econ-lunar-seed` |
| `?key=` | `market/crawl.php` | `econ-mkt-7x3k` (`market/config.php` 의 `MKT_KEY`) |

- 전부 **평문 하드코딩**. 노출되면 파일을 고쳐야 한다.
- `PHP_SAPI === 'cli'` 면 토큰을 보지 않는다(SSH로 들어온 것 자체가 자격). `cron_job.php` 도 CLI는 면제 — `php cron_job.php task=dart_quarter from=2016`.
- 로그인 세션은 안 본다. 즉 **URL만 알면 누구나 실행**된다.
- 로그 머리글에서는 `k`·`ssk`·`key` 가 전부 `***` 로 마스킹된다.

### 2.2 30초 타임아웃 우회 — 두 패턴, 하나만 작동

**패턴 A (구식 · 이 서버에서 안 먹는다)** — 남은 곳: `ardent_crawl`, `market/crawl`
(`keyword_collector`·`naver_collect` 는 2026-07-30 에 패턴 B로 교체했다)
```php
ob_start(); echo "OK";
header("Content-Length: ".ob_get_length());
header("Connection: close");
ob_end_flush(); flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
```
- 실측(2026-07-30): SAPI가 **apache2handler** 라 `fastcgi_finish_request` 가 **없다**. Content-Type을 html·plain·미지정으로 바꿔 봐도 응답은 12초를 전부 기다렸다.
- 그래서 이 패턴을 쓰는 크론은 **cron-job.org에서 빨간불**이 뜬다.
- 단 `ignore_user_abort(true)` 는 먹으므로 **작업은 끝까지 돈다.** 즉 "빨간불 = 미측정"일 뿐 손실은 아니다.

**패턴 B (검증됨 · 유일한 해법)** — 공용 헬퍼 **`env/cronbg.inc`**
```php
// bg=1 로 들어오면 같은 인자에서 bg→run 으로 바꿔 자기 자신에게 비동기 요청을 던지고 즉시 exit
$fp = stream_socket_client("ssl://{$host}:443", ...);
fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\n...Connection: Close\r\n\r\n");
stream_set_blocking($fp, false);   // ← 응답을 안 읽는 것이 요점
fclose($fp);
exit("queued");                     // 던지기 0.07초 → 크론은 즉시 성공
```
- 받은 쪽(`run=1`)은 `ignore_user_abort(true)` + 시간예산으로 완주하고 좀비가 안 된다.
- 화면이 없으므로 진행은 **로그 파일**로 간다(웹 루트 밖이라 URL로 직접 안 열림).
- 소켓 실패 시 **그 요청에서 그냥 처리**하는 폴백이 있다.

**`env/cronbg.inc` 쓰는 법 — 기존 `echo` 를 고치지 않아도 된다**
run 모드에선 출력을 버퍼로 받아 로그로 옮긴다. HTML(`<pre>`·`<br>`)은 평문으로 바꿔 넣고, `exit`·`die`·치명오류에도 종료 훅이 버퍼를 흘려보낸다.
```php
require_once "./env/cronbg.inc";
define('MY_LOG', sys_get_temp_dir() . '/my_cron.log');

if (!empty($_GET['log'])) cron_bg_show_log(MY_LOG, (int)($_GET['n'] ?? 60));
cron_bg_begin(MY_LOG, 900);          // ?bg=1 이면 여기서 던지고 exit

cron_bg_log('시작');                  // run=로그 / 수동=화면
foreach ($items as $it) {
    …
    if (cron_bg_over()) { cron_bg_log('예산 도달 — 나머지는 다음 실행'); break; }
}
```
| 함수 | 하는 일 |
|------|---------|
| `cron_bg_begin($log, $maxSec)` | bg/run 판정 · 던지기 · run 모드 준비. `true` = 이 요청이 본작업을 한다 |
| `cron_bg_log($msg)` | 타임스탬프 한 줄. **앞서 쌓인 `echo` 를 먼저 흘려보내 순서가 안 뒤섞인다** |
| `cron_bg_over()` / `cron_bg_elapsed()` | 예산 초과 판정 / 경과 초 |
| `cron_bg_show_log($log, $n)` | 로그 tail 출력 후 종료(본작업 안 건드림) |

주의할 점
- **예산은 `cron_bg_over()` 를 보는 루프가 있을 때만 효력이 있다.** 단일 호출로 끝나 중간에 끊을 지점이 없는 잡은 `0`(무제한)을 넘기고 실제 소요를 로그의 `총 N초` 로 확인한다 — 예산만 걸어 두면 거짓 안심이 된다.
- **예산은 run 모드에만 적용**된다. 수동 호출은 브라우저를 닫으면 종료(`ignore_user_abort(false)`)되므로 굳이 걸지 않는다.
- 로그 머리글의 `key`·`ssk`·`k` 는 `***` 로 **마스킹**된다(로그 파일에 토큰을 남기지 않는다).
- 로그는 **실행마다 새로 쓴다**(직전 실행만 남음 — 파일 무한 증가 방지). 여러 모드를 한 파일이 처리하면 **모드별로 로그 경로를 나눈다**(`keyword_collector` 가 그렇게 한다 — 하루 10회 도는 `news` 가 `etf_update` 로그를 덮으면 안 된다).

⚠️ **`env/` 는 `.gitignore` 대상이다**(자격증명 보호). 즉 `env/cronbg.inc` 는 **git 으로 따라오지 않는다** —
PC↔노트북은 구글 드라이브 정션으로 동기화되고, 서버는 **수동 SFTP 업로드**여야 한다.
배포를 잊으면 3개 크론이 `require` 실패로 통째로 죽으므로, 각 파일에 **`is_file()` 가드**를 넣어
`env/cronbg.inc 없음 — env/ 는 git 제외라 수동 배포가 필요합니다` 라는 진단 메시지가 나오게 했다.

> **새 장기 크론은 `env/cronbg.inc` 를 쓴다.** 패턴 A는 쓰지 말 것.
> `cron/dart_collect.php` 는 같은 기법을 **파일 안에 직접** 갖고 있다(먼저 만들어져 검증된 쪽 · `?job=log`). 운영 확인이 끝나면 헬퍼로 합치는 것이 맞다.

### 2.3 시간예산 + 이어받기(resume)

30초를 못 넘기는 대신, 긴 작업은 전부 **"이번 호출에서 할 만큼만 하고 상태를 남긴다"** 로 설계돼 있다.

| 크론 | 예산 파라미터 | 이어받기 상태 저장 위치 |
|------|---------------|------------------------|
| `dart_collect` | `sec=`(초), `budget=`(DART 호출 수) | 이미 채운 행은 SQL에서 빠진다(멱등) |
| `naver_collect` | `max_sec=`(기본 480), `max=`(지역 수) | `naver_collect_log.status` = pending/done/error (지역 단위 즉시 커밋) |
| `ardent_crawl` | `budget=`(초, 30~600 / AI는 30~3000) | `tbl_ardent_state.last_page` + `tbl_ardent_crawl.idxno` |
| `place_geocode` / `waste_geocode` | `TIME_BUDGET=25`(코드 상수), `limit=` | `geocode_status` = pending/ok/failed |
| `lunar_seed` | `TIME_BUDGET=20`, `limit=` | 이미 저장된 `solar_date` 는 건너뜀 + `meta refresh` 자동 이어실행 |
| `place_tag_backfill` | `after=`, `limit=` | 마지막 id 를 화면에 출력 → 다음 호출에 `&after=` |

**모든 크론은 재실행 안전(멱등)** 하게 짜여 있다. 하루 여러 번 들어와도 같은 값 덮어쓰기다.

### 2.4 상태·진단 URL (크론 안 건드리고 현황만 보는 모드)

앞에 `https://economist.kr/cron_job.php` 를 붙이고 뒤에 `&k=econ-cron-j7k2` 를 붙인다.

목록·설명
```
?task=list                       등록된 잡 27개 전체 (크론식·bg·파일·인자·설명)
?task=<이름>&explain=1           실행하지 않고 "무엇을 어떻게 부를지"만 (부작용 0)
```
현황 (수집 상태 · DB 기준)
```
?task=dart_status
?task=dart_slots&today=2026-08-15
?task=naver_stay&status=1
?task=ardent_status
?task=ardent_new          (아직 안 본 아덴트 기사 목록 · 무료)
?task=place_geo
```
bg 실행 로그 (뒤에서 돈 잡이 무엇을 했는지 — **bg 잡은 이걸로만 확인된다**)
```
?task=etf_update&log=1
?task=naver_stay&log=1
?task=naver_camping&log=1
?task=dart_log&n=80              ← dart 만 job=log 라 전용 task 로 뺐다
```
- `&n=` 으로 줄 수 조절.
- **`log=1`·`status=1` 은 bg 를 붙이지 않는다** — 조회까지 뒤로 넘기면 결과가 화면이 아니라 로그로 가서 아무것도 못 본다. 디스패처가 읽기 전용으로 판정해 즉시·동기로 돌린다.

### 2.5 알림 경로 (2026-08-02 전면 정비)

`Notify::send($text, $link, $opts)` → `PushoverNotify` (+선택적 `NotionArchive`).

**원칙: 성공은 조용히, 실패는 크게.** 정비 전에는 하루 최대 13~14건(뉴스 키워드 10건이 노이즈의 75%)이 오면서 정작 핵심 파이프라인(dart_collect)의 실패는 무음이었다. 지금은 반대다.

**① 중앙 실패 알림 — `cron_job.php` 디스패처가 전 task 를 감싼다.**
- 잡히지 않은 예외(try/catch) + 치명 오류(shutdown 훅 + `error_get_last`) → `⚠️ 크론 실패: <task>` **priority 1**(방해금지 무시).
- bg 잡은 0.07초 만에 `queued` 라 **cron-job.org 눈에는 항상 성공** — run 모드 실패는 여기가 유일한 감지 지점이다(되돌아온 요청도 디스패처를 거친다).
- 같은 task 는 **하루 1회만**(연속 실패 폭탄 방지 · `/tmp/cron_fail_<task>.flag`).
- 읽기 전용 조회(`log=1`·`status=1`)는 화면에서 바로 보이므로 안 감싼다.
- 타깃이 오토로더(cnt.inc)를 로드하기 전에 죽어도 되도록 Notify 직접 로드 폴백이 있다.
- ⚠️ 타깃이 **자체 catch 로 삼키는 예외는 중앙에서 안 보인다** — 그런 곳(keyword_collector 3개 모드·etf_update)은 catch 안에서 직접 priority 1 로 쏜다.

**② 데이터 레벨 감시 — "크론은 성공했는데 데이터가 안 들어온" 케이스용. 두 곳에 있다.**

| 자리 | 재는 것 | 무음 조건 | 경고 |
|------|---------|-----------|------|
| `dart_collect job=eod` 끝 | `all_stock_info` 당일 갱신률 | 갱신 0건 = 휴장일 | 0 < 갱신률 < 80% → `⚠️ 종가 갱신률 저조` priority 1 |
| `dt_min job=daily` 끝(⑤) | **수집 대상**(풀 ∪ 보유 · `Dt::targetCodes`) 중 당일 봉이 남은 종목 수(`Dt::collectedCount` · 치유 뒤 기준) | 거래일 아님 · 대상 0종목 | 적재 < 절반 → `cron_fail_notify('dt_min', …)` priority 1 |

★둘 다 **자체 catch 로 예외를 삼키는 크론**이라 중앙 알림이 못 본다(규칙 3). 삼키는 이유가 있는 코드는
(eod=부분 실패 허용 · dt_min=네이버 폴백) 반드시 이렇게 «결과를 세어» 스스로 쏴야 한다.

**③ 정기(성공) 알림 — 남긴 것만:**

| 알림 | 빈도 | 조건 |
|------|------|------|
| 일정 사전 알림 (`check_alerts`) | 이벤트성 | 사용자가 건 알람만 |
| 오늘 일정 요약 (`daily_summary` 07:00) | 1건/일 | 일정 없어도 발송 — **heartbeat 역할**로 유지 결정 |
| 모닝브리핑 (`market` 08:10) | 1건/일 (월~토) | 매 성공 시 |
| 뉴스 키워드 (`news`) | **2건/일 (08·18시 fire 만)** | 10회/일 발송을 축소 — 데이터는 매회 쌓인다 |
| ETF 편입종목 (`etf_update`) | **조건부** | 신규 ETF 발견 · 예산 도달(이어받기)일 때만. 평상시 완주=무음 |
| **📊 실적 신호** (`dart_fresh` 끝 · 08:05) | **조건부** | 보유 어닝쇼크(SUE≤−1)·관심 서프라이즈(SUE≥1) **신규만**. 구현 `stock/lib/alert.php` `pf_alert_fresh` · 중복방지 `pf_alert_log` · 새 신호 없으면 무음 (2026-08-02 신설) |
| **📈 마감 신호** (`dart_eod` 끝 · 15:50) | **조건부** | 관심 트리거(돌파확인·계단지지)·보유 계단관통↓·오늘 매집형(잠정) **신규만**. 구현 `pf_alert_eod` — 판정은 화면과 같은 단일본(boxStatusMany·SUE lib) (2026-08-02 신설) |
| 네이버 수집/정합 완료 | ≤2건/카테고리/월 | 회차 마지막 fire 1회만 |
| 아덴트 AI 수집 완료 | 드묾 | 완주+실적 있을 때만 |
| 공휴일 동기화 | 1건/년 | – |

- **전 발송에 `title` 을 단다**(앱 알림 목록에서 한눈에 구분). priority: 실패/경고=1, 일상 정보=0(기본).
- `class_exists('Notify')` 가드로 감싸여 있어 `env/pushover.inc` 가 없으면 조용히 통과한다.
- 카카오 알림 잔재는 삭제됐다(`KakaoNotify.class`·`kakao_oauth.php` 제거, `kakao.inc` 는 지오코딩·공휴일 키 때문에 유지).

**④ 새 푸시를 추가할 때 — 이 절이 유일한 참고처다.**

```php
// 클래스는 오토로더가 찾고, 자격증명은 PushoverNotify 가 env/pushover.inc 를 스스로 읽는다.
// 별도 require 불필요 (cnt.inc 를 안 쓰는 파일만 classes/ 2개를 직접 require — market/report.php 참조)
if (class_exists('Notify')) {
    Notify::send(
        "본문 (1024자 한도 — 내부에서 자름)",
        "https://economist.kr/화면.php",              // 원탭 이동 — 본문에 평문 URL 넣지 말 것
        ['title' => '짧은 제목', 'priority' => 0]     // 실패/경고=1 · 일상=0 · 그 외 옵션은 PushoverNotify.class 헤더
    );
}
```

지켜야 할 규칙 (이번 정비에서 확정):
1. **실패는 크게(priority 1), 성공은 조용히** — "평소처럼 잘 됨" 은 보내지 않는다. 알릴 것(신규 발견·이어받기·경고)이 있을 때만.
2. **title 필수** — 앱 목록에서 한눈에 구분되게.
3. **크론 실행 실패 알림은 새로 만들지 않는다** — `cron_job.php` 중앙 실패 알림이 전 task 를 커버한다. 단 **자체 try/catch 로 예외를 삼키는 크론**은 그 catch 안에서 직접 priority 1 로 쏴야 한다(중앙에서 안 보인다).
4. **완료 알림은 드레인 완료 순간 1회만** — 반복 fire 잡에서 no-op 회차마다 보내지 않는다(`naver_collect` 패턴).
5. 반복 실패 가능성이 있으면 **스로틀**(하루 1회 등 · `/tmp` 플래그 — `cron_fail_notify()` 참조).

---

## 3. 파일별 상세

### 3.1 `cron/dart_collect.php` — 주식 재무·시세 파이프라인 (가장 최신·가장 잘 설계됨)

> **/stock 재무분석 스크리너**가 먹는 데이터를 전부 여기서 만든다. `job=` 라우터 하나에 9개 잡이 들어 있다.

호출: `/cron/dart_collect.php?key=econ-dart-collect&job={job}`

| job | 주기 | 하는 일 | 외부 소스 | 쓰는 테이블 | 실측 |
|-----|------|---------|-----------|-------------|------|
| `fresh` | 매일 1회 | **최신 5슬롯**(사업연도×보고서) 재무제표를 통째로 재수집 | DART OpenAPI `opendart.fss.or.kr` (100종목 묶음) | `stock_financial` | **78초** → `bg=1` 필수 |
| `krx` | **매일 13:05 (오후 1회)** | 최근 거래일 전종목 **상장주식수**+시세, ★`krx_amt` **전종목 이관**(확정 src='k' · 잠정치 괴리 로그 · 2026-07-31~) + 신고가 신호 재계산(`krx_surge` 캐시), 오래된 기준일 정리 | KRX 오픈API `data-dbg.krx.co.kr` (시장 2개 = 2콜) | `krx_daily` → `krx_amt`, `krx_surge` | 8.9~19.8초 (+신호 수초) |
| `eod` | 평일 15:50 | `quotes` + `range`(오늘분) + `daily`(일봉) + ★`krx_amt` **당일 잠정 적재**(src='n' · 퀀트 거래대금 신고가 T+0 · 2026-07-31~) + 오늘 신호 계산(`krx_surge` 캐시 — 화면이 8초 안 내게) **묶음** | 네이버 폴링API | `all_stock_info`, `stock_daily_range`, `stock_price_range`, `pf_daily`, `krx_amt`, `krx_surge` | 2.5~3초 (+신호 수초) |
| `quotes` | (eod에 포함) | NXT 크론이 못 훑은 종목의 낡은 시세 메우기 | `polling.finance.naver.com/api/realtime/domestic/stock/{코드,…}` 100종목/회 = 28회 | `all_stock_info` | 1.1초 |
| `range` | (eod에 포함) | 오늘 고·저 1줄 추가 + 6개월 집계 재계산 + 오래된 행 정리 | 위와 같은 폴링API | `stock_daily_range`, `stock_price_range` | 1.5초 |
| `range&full=1` | **SSH 1회** | 일별 고·저 **씨 뿌리기**(종목마다 일봉 1회) | `api.finance.naver.com/siseJson.naver` × 2,766 | `stock_daily_range` | **340초** |
| `quarter` | SSH 최초 | 분기 재무제표 전량 적재 (2016~) | DART | `stock_financial` | ~8분 |
| `shares` | SSH 최초 | 연도별 주식수 (종목마다 1콜) | DART | `stock_fundamental` | ~54분 |
| `nifix` | SSH 1회 | **순이익 결측 보수** — 매출은 있는데 `net_income` 만 빈 행을 전체재무제표로 메움 (행마다 1콜 · `&y= &rc= &budget=`) | DART `fnlttSinglAcntAll` | `stock_financial.net_income` | 90행 ≈ 24초 |
| `slots` | 드라이런 | `fresh` 가 어떤 슬롯을 고를지 미리보기 (DART 호출 없음) | – | – | 즉시 |
| `log` / `status` | 확인 | 지난 bg 로그 / 적재 현황 | – | – | 즉시 |

**설계 핵심 (여기 손대기 전에 반드시 읽을 것)**

1. **일봉을 다시 받지 않는다.** 일봉 API는 묶음 호출이 없어 전종목 2,766회·6분. 30초에 못 들어간다.
   → **일별 원본을 쌓고**(`stock_daily_range` 332,593행·24MB) 6개월 집계는 SQL로 접는다(`stock_price_range`). **창이 스스로 흐르므로 주기적 재수집이 아예 없다.**
   집계만 저장하면 창 밖으로 나간 옛 고점이 안 빠져서 전종목 재수집이 주기적으로 필요해진다 — 그게 30초에 안 들어가는 게 문제의 뿌리였다.
2. **오늘 한 줄은 폴링 API가 100종목씩 준다.** 같은 날 일봉과 값이 완전히 일치(실측). 전종목 28회·1초.
3. **호출 간격 100ms 고정.** 20ms(초당 25건)로 줄이면 전종목이 111초에 되지만, **전 사이트의 시세가 네이버 단일 소스**라 차단되면 포트폴리오·시뮬레이터·장중 크론이 통째로 멈춘다. `gap=` 을 줄이지 말 것.
4. **거래정지일은 시·고·저·거래량이 0이고 종가만 온다** → 종가를 고·저로 쓴다. (이걸 몰라서 첫 수집 5.4% 실패를 "네이버 차단"으로 오판했다.)
5. **`fresh` 는 창을 계산하지 않고 그냥 매일 통째로 받는다.** 5슬롯×40콜=200콜 = 하루 한도(20,000)의 1%. 공시는 마감일까지 누적 97%뿐이고 나머지 2~3%가 그 뒤 2주에 들어오며, 비12월결산사는 연중 아무 때나 낸다.
6. **KRX 크론은 새벽 금지.** T+1인데다 **다음 날 오전에야** 올라온다(01:19엔 없고 11:58엔 있음). → **13:05 오후 1회로 확정**(11:58에 이미 있으므로 단발로 충분). 코드 주석의 "오전·오후 2회"는 올라오는 시각이 불확실할 때의 안전장치였다. `collectLatest()` 가 "그 시점의 최신 거래일"을 찾아오므로 여러 번 돌려도 안전하긴 하다.
7. **상장주식수는 매일 필요하다.** 1거래일에 11종목·1개월 203종목이 변한다(액면병합·감자·무상증자). 1개월 묵히면 **PER이 10배 틀어진다.**
8. `job=range` 는 마감 후에 돌려야 한다. 개장 전 `PREOPEN` 에는 o/h/l/v가 **전부 0** 으로 온다.
9. **주요계정 API 는 순이익을 빠뜨린다** (2026-08-04 규명). `fnlttMultiAcnt` 는 회사가 **표준계정으로 태깅한 것만** 준다 —
   현대차는 「연결당기순이익」(표준계정코드 미사용)이라 그 행이 응답에 **아예 없다**. 계정명 매핑을 늘려도 소용없다.
   → `fnlttSinglAcntAll`(전체 재무제표)로 메운다. 실측 897행·233종목 → **137행·59종목**(거래종목분 완료).
   매일 몫은 **`fresh` 안에 들어 있다**(슬롯당 15행 상한 = 최대 75콜). 옛 연도는 `job=nifix` 로 한 번.
   ★★ **별도(OFS) 값으로 연결(CFS) 행을 메우지 않는다** — 연결 매출에 별도 순이익을 붙이면 순이익률·ROE 가
   조용히 틀린다. 빈칸이 틀린 값보다 낫다.

**CLI 실행**: `php cron/dart_collect.php job=quarter from=2016 to=2026 budget=1200`
(CLI는 `DOCUMENT_ROOT` 가 비어 오토로더가 죽으므로 파일 상단에서 `__DIR__` 로 세팅한다.)

---

### 3.2 `cron/keyword_collector.php` — 시세 갱신 + 뉴스 키워드 + ETF 편입종목

호출: `/cron/keyword_collector.php?ssk=mysn1973!&mode={mode}`
공통: 패턴 A(안 먹는 bg) + `ignore_user_abort(true)`.

#### `mode=stock_etf_news` (기본) — **장중 시세 갱신의 본체**
등록: `5 6-9,11,13,15,17,19 * * 1-5` → **평일 06·07·08·09·11·13·15·17·19시 05분 (9회)**

1. `NaverFinanceAPI::get_real_time_data_from_naver()` — 전종목/ETF 시세 갱신
2. 대형주 급등 TOP 9 → 종목별 네이버 금융 뉴스 10건씩
3. 제목에서 키워드 TOP 20 추출
4. `market_trend_snapshots` + `market_trend_keywords` 저장

> ## ★★ NXT 는 껐다 (2026-08-06 · `NaverFinanceAPI::USE_NXT = false`)
>
> 아래 「NXT 경로」 서술은 **끄기 전 동작의 기록**이다. 지금은 NXT 창(08:00~08:49 · 15:31~20:00) fire 가
> 시세 갱신을 **건너뛴다** — 즉 **08:05 · 17:05 · 19:05 는 시세를 안 건드린다**(그 회차의 뉴스·키워드는 그대로 돈다).
>
> **왜** — `all_stock_info` 는 사이트 전 화면이 읽는 단일 원천인데, 시간외 값이 들어오면 마감 뒤
> **목록과 차트가 다른 말을 한다**(실측 목록 231,500 vs 분봉·일봉 230,500). 기준을 **정규장 하나**로 뒀다.
>
> **그래서 달라지는 것** — 마감 뒤 종가는 **`job=eod`(15:50)** 가 넣는 값으로 굳고, 그 뒤로는 안 움직인다.
> 즉 `job=eod` 는 이제 「NXT 가 못 훑는 구멍을 메우는」 잡이 아니라 **종가를 확정하는 유일한 잡**이다 —
> 지우거나 15:30 이전으로 당기면 전종목 종가가 통째로 틀어진다.
>
> 되살리려면 상수 하나(`USE_NXT`)만 true 로 바꾼다. `update_stock_price_from_nxt()`·`getAllNxtSise()` 는 지우지 않았다.

**★ 시각에 따라 소스가 갈린다** (`get_real_time_data_from_naver` 내부, 분 단위 `$t` 비교 · **끄기 전 기록**):
- `$t >= 480 && $t < 530` = **08:00~08:49** / `$t > 930 && $t <= 1200` = **15:31~20:00**
  → `update_stock_price_from_nxt()` = NXT 장외 시세(`nxt_sise_market_sum.naver`), **UPDATE 만** 함
- 그 밖의 시각 → 정규 `getAllNaverStocks(KOSPI/KOSDAQ)` + `getAllNaverEtfs()`, **INSERT…ON DUP + DELETE NOT IN**

**★★ 등록 시각을 위 경계에 겹쳐 보면 "15:05 정지"의 원인이 그대로 나온다.**

| fire | 분(`$t`) | 경로 | 커버 |
|------|---------|------|------|
| 06:05 · 07:05 | 365 · 425 | 정규 | 전종목 (장 시작 전이라 전일 종가) |
| **08:05** | 485 | **NXT** | 604종목만 |
| 09:05 · 11:05 · 13:05 · **15:05** | 545 · 665 · 785 · **905** | 정규 | 전종목 |
| 17:05 · 19:05 | 1025 · 1145 | **NXT** | 604종목만 |

**정규 경로의 마지막 fire가 15:05** 다. 정규장 마감은 15:30인데, 그 뒤로 도는 17:05·19:05는 NXT라 **604종목(전체 2,758의 21.9%)만** 갱신한다.
→ 나머지 **2,154종목은 15:30 종가를 영원히 못 받고 15:05 값에 멈춘다.** (실측 2026-07-29: 2,022종목이 15:05에 멈춰 있었고 현대차우는 실제 종가와 2% 어긋나 있었다.)
→ 그 구멍을 메우는 것이 §3.1의 **`job=eod`(15:50)** 이다. **eod 를 지우거나 시각을 15:30 이전으로 당기면 전종목 종가가 다시 틀어진다.**

> 대안으로 이 크론에 `15,16` 시 fire를 추가해도 15:31~20:00은 NXT 창이라 604종목만 받는다. **NXT 창 판정 자체를 고치지 않는 한 `job=eod` 가 유일한 해법**이다.

**★ 위 표에는 마감 문제만 적혀 있었는데, 같은 원인이 «장중»에도 있다 (2026-08-06).**
정규 경로의 장중 fire 는 **09:05 · 11:05 · 13:05 · 15:05 넷뿐**이다 — 즉 **14:50 에 화면을 열면 13:05 값**이고,
장중 시세가 최대 **약 2시간** 낡는다. 화면이 자기 타이머로 새로고침해도 **DB를 다시 읽을 뿐**이라 같은 값이 온다.
단타에서 「차트와 옆 목록의 현재가가 다른 말을 한다」로 드러났다.

→ **크론을 늘려 풀지 않았다.** 전종목을 촘촘히 받으면 28콜 × 10초 = 하루 1만 콜이라 §4 의 IP 차단 위험에 걸린다.
대신 **「보는 종목만 촘촘히 · 전종목은 성기게」** 로 갈랐다:

| 층 | 대상 | 어떻게 | 주기 |
|----|------|--------|------|
| 화면 | `Dt::targetCodes()`(단타 풀 ∪ 보유 · 실측 34종목) | **키움 `ka10095`** — `Dt::refreshQuotesLive()` · 실패 시 네이버 폴백 | `Dt::TICK_SEC`(10초) |
| 크론 | 전종목 2,751 | `stock_news`(평일 9회) + `dart_eod`(마감 메우기) | 그대로 |

- 구현은 크론이 아니다 — `stock/api.php` `module=dt` 의 `pool_list`/`held_list` 가 응답 전에 지난다
  (cron-job.org 는 최소 주기가 1분이라 10초를 크론으로 만들 수 없고, **안 보는 종목을 받을 이유도 없다**).
- ★신선도 판정은 `NaverFinanceAPI::staleCutoff()` **단일본**을 키움·네이버 경로가 둘 다 본다.
- ★휴장일·장 밖에서는 서버가 아예 안 나간다(`Dt::isTradingDay()`) — 없으면 **토요일에 화면을 열어 둔 것만으로**
  10초마다 콜이 나간다.
- ⚠**나머지 2,700여 종목은 여전히 크론 주기다.** 탐색 화면(상위 종목·퀀트·스크리너)의 현재가를 초 단위로 믿지 않는다.
- ⚠**ETF(`all_etf_price`)에는 이 경로가 없다** — 아래 §5 의 ETF 항목 참조.

**★ 위험**: 정규 경로는 저장 후 `DELETE FROM all_stock_info WHERE stock_code NOT IN (받은 코드들)` 를 실행한다. 네이버가 부분 응답을 주면 **종목이 통째로 사라진다.** `all_stock_info` 를 새 기능의 원천으로 쓸 때 반드시 기억할 것.

**★ 평일 전용(`1-5`)** — 주말에는 `all_stock_info` 가 갱신되지 않는다(금요일 값 유지). 주말 화면·주말에 도는 다른 배치가 "낡음"을 판정한다면 이 사실을 감안해야 한다.

쓰는 테이블: `all_stock_info`, `all_etf_price`, `market_trend_snapshots`, `market_trend_keywords`

#### `mode=news` — 네이버 금융 섹션 뉴스 키워드
등록: `5 6,8-10,12,14,16,18,20,22 * * *` → **매일(주말 포함) 06·08·09·10·12·14·16·18·20·22시 05분 (10회)**

- `getSectionNews($YYYYMMDD, true, 20, $since)` — `$since` = 오늘 마지막 배치의 최신 기사 시각. **그 시각 이하 기사를 만나면 즉시 중단** → 중복 방지.
- `news_stopwords` 테이블의 제외단어 반영, **2회 미만 키워드 버림**, 유효 키워드 0이면 스냅샷도 안 만든다.
- 테이블을 `CREATE TABLE IF NOT EXISTS` + `ALTER … ADD COLUMN IF NOT EXISTS` 로 **크론 안에서 자동 마이그레이션**한다.
- 쓰는 테이블: `news_trend_snapshots`, `news_trend_keywords` / 읽기: `news_stopwords`
- 소비 화면: `analysis_model.php?mode=daily`
- Pushover 알림 발송(키워드 TOP 10).

#### `mode=etf_update` — ETF 편입종목 (bg 필수)
등록: **평일 16:20** (`20 16 * * 1-5` · 2026-08-02 실확인). bg 는 새 진입점의 레지스트리(`'bg' => true`)가 붙여 주므로 URL에 쓸 필요 없다.

1. `discover_new_domestic_etfs()` — 네이버에서 신규 국내 ETF(`etfTabCode` 1,2,3) 자동 등록
2. `all_etf_info` 중 `skip_update=0` 이고 holdings 의 `MAX(uDate) < CURDATE()` 인 ETF를 골라
3. `StockRepository::updateEtfHoldings()` — **ETF 1개당 `sleep(2)`**
4. `data_upTime('auto_etf_naver_update','update')` 기록 + Pushover(신규 ETF 목록, 카카오 200자 제한 잔재로 180자에서 자름)

**timeout 원인이 명확하다**: `sleep(2)` × 갱신 대상 ETF 수. 30초에 들어갈 수가 없다.

**★ 정렬을 "오래 안 받은 것부터"로 바꿨다** (2026-07-30, 예산 도입과 한 묶음)
```sql
ORDER BY (MAX(h.uDate) IS NULL) DESC, MAX(h.uDate) ASC, i.etf_code ASC
```
`etf_code ASC` 로 두면 예산에 걸려 중간에 끊길 때 **뒷쪽 코드가 영원히 안 받아진다** — ETF당 2초라 대상이 많으면 매일 같은 자리에서 끊기기 때문이다. 낡은 것부터 받으면 끊겨도 다음 실행이 그 뒤를 이어받아 전체가 순환한다.
**예산을 도입하면 반드시 공정한 정렬이 따라와야 한다** — 이건 새 크론을 만들 때도 같다.

**예산 기본 3600초**(`&sec=` 로 조절). 지금까지 무제한으로 돌려 완주했으므로 **현행 동작을 바꾸지 않는 선**에서 폭주만 막는 값이다. 로그의 `총 N초` 로 실제 소요를 확인한 뒤 줄인다(대상 수 × 2초가 하한).
쓰는 테이블: `all_etf_info`, `all_etf_holdings_info`
외부: `finance.naver.com/api/sise/etfItemList.nhn`, `navercomp.wisereport.co.kr/v2/ETF/index.aspx`

---

### 3.3 `cron/schedule_alert.php` — 업무캘린더 알림 (유일하게 전부 초록불)

호출: `/cron/schedule_alert.php?ssk=mysn1973!&mode={mode}` · `&debug=1` 이면 즉시 출력 모드

| mode | 주기 | 하는 일 |
|------|------|---------|
| `check_alerts` | **매 10분** | `Schedule::getPendingAlerts(15)` — 15분 윈도우의 미발송 알림 → Pushover → `markAlertSent()` |
| `daily_summary` | 매일 07:00 | 당일 일정을 기념일/할일/종일/시간 4그룹으로 묶어 1건 발송 |
| `sync_holidays` | 매년 01/01 | `HolidayAPI::syncYear()` **올해+내년** 동기화 → `tbl_holiday` |

- `set_time_limit(60)` — 다른 크론과 달리 무제한이 아니다(짧은 작업이라 문제 없음).
- `sync_holidays` 는 `HOLIDAY_API_KEY`(data.go.kr, `env/kakao.inc`) 필요. 미설정이면 `error_log` 남기고 조용히 리턴.
- `&year=2026` 으로 수동 재동기화 가능.
- **10분 윈도우 크론에 15분 조회창** = 겹침 허용. 중복 발송은 `markAlertSent` 로 막는다.
- 외부: `apis.data.go.kr/B090041/openapi/service/SpcdeInfoService`
- 테이블: `tbl_schedule_alert`(읽기·플래그), `tbl_schedule`, `tbl_holiday`

---

### 3.4 (폐기) `cron/rise_analysis.php` — 상승확률 분석

**2026-07-31 삭제.** 전략을 접었다. 파일·테이블(`rise_run`·`rise_pick`·`rise_pattern_stats`)·메뉴·`TASKS['rise']` 를 전부 지웠다.
남은 흔적은 없다. 크론 사이트의 #10 등록만 사람이 지우면 끝이다.

---

### 3.5 `cron/naver_collect.php` — 네이버 맛집/스테이/캠핑장 월1회 전국 수집

호출: `/cron/naver_collect.php?key=econ-naver-9x2k&cat={food|stay|camping}&bg=1&max=10&delay=4&max_sec=120`

**구조**: 전국 시군구(229개)를 `naver_collect_region` 마스터에 시드하고, 회차(`period=YYYY-MM`)마다 지역별 pending 큐를 만들어 **한 호출에서 예산까지만 처리하고 종료**한다. 다음 호출이 이어받는다. 회차 전 지역이 done 이면 이후 호출은 no-op.

**크론 등록 방식이 독특하다**: 카테고리별로 **날짜를 나눠** 3개 등록(IP 부하 분산) + 각 날짜에 **10분 간격 30회 fire**.
```
매월 1일 03~07시 10분간격 → cat=food     (max=10, max_sec=120)
매월 2일 03~07시 10분간격 → cat=stay     (max=10, max_sec=120)
매월 3일 02~07시 10분간격 → cat=camping  (max=8,  max_sec=180)
```
회당 지역수 × 30회 ≥ 229 면 커버. 완료 후 fire는 no-op이라 남는 슬롯은 공짜다.

| 파라미터 | 기본 | 의미 |
|----------|------|------|
| `cat` | food | 한 호출은 **한 카테고리만** |
| `period` | 이번 달 | 회차 |
| `max` | 30 | 이번 호출 최대 지역 수 |
| `max_sec` | 480 | 시간예산(초) |
| `delay` | 3 | 지역 간·접미사 간 휴식(초) |
| `status=1` | – | 진행 현황만 출력(쓰기 없음) |
| `purge=1` | – | 이 cat·period 의 추이 스냅샷+로그만 삭제(★cat·period 둘 다 필수, `place` 는 보존) |
| `reconcile=1` | – | 소멸 유명점 재분류(수동/디버깅용 — 평소엔 자동) |
| `retry_err=1` | – | error → pending 되돌림 |
| `reseed=1` / `dry=1` | – | 지역 재시드 / 미리보기 |

**핵심 설계**
- **접미사 합집합**: `camping` = `캠핑장`+`오토캠핑`, `stay` = `스테이`+`펜션`. 두 검색은 결과가 절반만 겹치는 **다른 슬라이스**(각 100개 컷·랭킹 상이)라 둘 다 훑어 `naver_id` 기준 합집합으로 적재한다. → 지역당 시간 2배 → `camping` 만 `max=8, max_sec=180`.
- **429 = IP당 누적 예산형.** 429를 만나면 그 지역은 pending 유지하고 **이번 호출을 즉시 중단**(다음 fire 재시도). 몰아치기 금지 — `max_sec` 로 회당 시간 제한이 필수.
- **자동 정합(reconcile)**: 회차 수집이 완료된 뒤의 fire들이 남는 예산으로 소멸 유명점을 재분류한다. 별도 크론 등록 불필요. 직접검색 나옴=실값 복구(100컷 누락 되살림) / 안 나옴 1차=감시 등록 / 다음 회차에도 안 나옴 2차=폐업 `place` 삭제(FK CASCADE).
- **완료 알림 1회만**: "이번 호출이 마지막 pending을 처리해 완료가 된 순간"에만 Pushover. 월 30회 fire에도 카테고리당 1건.
- 지역마다 **처리 즉시 done 커밋** → 호출이 끊겨도 진행분 보존.

외부: `pcmap.place.naver.com` (완전 브라우저 헤더 필수)
테이블: `naver_collect_region`, `naver_collect_log`, `place_naver_stat`, `naver_reconcile_watch`, `place`(+`place_ref`/`place_tag`)
소비 화면: `naver_trend.php?cat=…`, `places.php`

---

### 3.6 `cron/ardent_crawl.php` — 아덴트뉴스 국내여행 → 여행지 DB (AI 추출)

⛔ **2026-08-04 크론 등록 해제 — 지금은 수동 전용이다** (사용자 지시: 여행지 DB 가 이미 3만 곳이라 상시 수집 중단).
레지스트리에는 `ardent` 로 남아 있으나 `cron` 이 비어 있다. 호출: `?task=ardent&k=…`

★ **다시 크론에 올리려면 콜 상한을 먼저 넣는다.** 2026-08-04 실측 사고 기록:

| 사실 | 값 |
|------|-----|
| 옛 스케줄 | `1,11,21,31,41,52 3-7 4 * *` = 매월 4일 새벽 **30회** (30초 타임아웃 우회용 조각내기 — 중복 처리는 없다) |
| 1회 실행 | 시간예산 180초 → 기사 ~17건 |
| 그날 총계 | 기사 **491건** 처리 · Claude 콜 **411회** · 신규 place **192곳** / 보강 133곳 |
| 비용 | **≈$6** (기사 1건 = 콜 1회 ≈$0.015 · 본문 전문 전송 · 프롬프트 캐싱 없음) |
| 피해 | `ANTHROPIC_API_KEY` **잔액 소진**. 그 키를 **모닝브리핑·명함스캔·음성일정등록·계약서판독·여행지추천이 공유**한다 → 07:22 소진 → 08:10 브리핑이 폴백으로 나감 |
| 왜 그날 491건이었나 | 옛 크롤이 `last_run=2026-06-11` 에 멈춰 **54일치 백로그**를 한 번에 태웠다. 평상시 한 달치는 ~270건 |

- **횟수를 줄이는 건 해법이 아니다** — 매 발사가 `tbl_ardent_crawl` 를 보고 *안 본 기사만* 집으므로 30회는 한 작업의 30조각이다. 줄이면 비용이 아니라 수집량이 준다(백로그가 쌓인다).
- 필요한 건 **① 1회 실행 콜 상한 + 일일 누적 상한**(지금은 시간예산 180초뿐) **② 잔액 소진 시 즉시 중단 + Pushover**(그날 80건을 계속 때리며 조용히 실패했다) **③ 크론 사이트 실패 알림 ON**(당시 OFF).
- ⚠️ **일시적 API 실패도 `skip` 으로 영구 기록된다**([ardent_crawl.php:541](cron/ardent_crawl.php#L541)) → 충전해도 재시도되지 않는다.
  되살리기는 이제 모드가 있다: **`revive=1&kind=all&dry=1`** 로 세어 보고 `&dry` 를 빼면 그 행만 지운다(정당한 skip `AI:0곳` 은 보존).
  **2026-08-04 실측 232건**(사용한도 도달 143 · 잔액소진 80 · JSON 파싱실패 9) — CRON.md 가 적어 뒀던 「82건」은 잔액소진분만 센 값이었다.
- 손으로 돌릴 때는 `&budget=` 을 작게 줘서 조금씩 돈다.

### ★유료 API 를 안 쓰는 길 — 사람(Claude Code)이 읽어서 담는다 (2026-08-04 신설)

기사 1건이 곧 콜 1회라 API 로 돌리면 한 달치가 $4~6 이다. 그래서 **월 1회 세션에서 사람이 기사를 읽어 담고,
서버는 「목록을 주고 · 판정을 해 주고 · 결과를 기록」만 하는** 경로를 뚫었다. 셋 다 **Claude 콜 0회**다.

| 모드 | 저장 | 하는 일 |
|------|------|---------|
| `new_list=1[&pages=30&limit=200&fmt=json]` | ✗ | 목록 AJAX 만 훑어 **아직 안 본 기사**(idxno·제목·발행일·URL). `?task=ardent_new` 으로도 부른다 |
| `match_check=1` (POST) | ✗ | `[{name,region}]` → **run_ai 과 같은** 지오코딩(지역검증)+엄격매칭(250m·이름 완전일치) 결과. 기존 장소면 `has_summary` 까지 |
| `log_run=1` (POST) | ✅ | 처리 결과를 **① `tbl_ardent_crawl` 처리이력**(reason `CC:N곳`) **② `tbl_ardent_run(_item)` 회차 기록** 둘 다에 남긴다 |

- 적재 자체는 `place_summary_tool.php?add=1`(신규는 `name`, 기존 보강은 `to_id`)을 쓴다 — 여행지 요약 배치에서 쓰던 그 통로다.
- **왜 `match_check` 이 따로 필요한가**: `add` 의 중복판정은 `dedup_key(name+region_lv2)` 뿐이라 이름이 조금만 달라도 옆에 새 마커를 만든다.
  `run_ai` 가 쓰던 좌표 250m + 정규화 이름 완전일치를 **같은 함수로** 부르려고 뚫었다(판정 단일본).
- **회차 기록은 화면이 있다 → [`/ardent_log.php`](../ardent_log.php)** (상단 네비 「아덴트수집」). 날짜별로 기사·등록장소·요약 전문을 본다.
  ★요약을 `tbl_ardent_run_item` 에 **복사**해 둔다: `place.attributes.summary` 는 다음 기사가 덮을 수 있어 「그날의 기록」이 못 된다.
- 기존 장소 보강 때 **요약은 최초 1회만 고정**(run_ai 과 같은 규칙) — `match_check` 의 `has_summary` 가 참이면 `add` 에 summary 를 안 보낸다.
  특성(features)은 그때도 병합된다(예전엔 `place_summary_tool` 의 `to_id` 분기에서 features 가 summary 조건 안에 갇혀 함께 누락됐다 · 2026-08-04 수정).

**모드가 12개다** — 대부분 검증/진단용이다.

| 모드 | 저장 | 용도 |
|------|------|------|
| `status=1` | – | 진행 상태(다음 시작 페이지 / done·skip / place 좌표 현황) |
| `new_list=1` | ✗ | **미처리 기사 목록**(무료) — 위 절 |
| `match_check=1` | ✗ | **지오코딩+기존장소 매칭**(무료·POST) — 위 절 |
| `log_run=1` | ✅ | **회차 기록**(무료·POST) — 위 절 |
| `revive=1[&kind=api\|body\|all][&dry=1]` | ✅ | 일시적 API 실패로 굳은 `skip` 삭제 → 미처리로 되돌림 |
| `run_ai=1` | ✅ | **(유료)** 기사 본문 → Claude 추출 → 카카오 지오코딩 → 엄격 매칭 → 적재 |
| `dry_ai=8` | ✗ | **(유료)** 위 파이프라인 리포트만 + **토큰·비용 추정** 출력 |
| `run=1` | ✅ | 구(regex) 파서 적재. AI 없이 meta/본문 정규식 |
| `dry=1&pages=1` | ✗ | 구 파서 덤프 |
| `test=15288` | ✗ | 단일 기사 파싱 검증(본문·주소·전화·시간·입장료 개별 확인) |
| `list=1` | ✗ | 목록 AJAX 에서 idxno 추출 검증 |
| `listraw=N` / `diag=N` | ✗ | 목록 HTML 구조 / 페이지네이션 진단(쿠키·Referer·XHR 헤더 5가지 비교) |
| `reset_crawl=1[&purge=1]` | ✅ | 처리이력 리셋(+아덴트 적재 place 삭제) — 이름 로직 변경 후 중복정리 |

**AI 파이프라인 (`run_ai`)**
1. 목록 page 1~30 순회, `tbl_ardent_crawl` 에 있는 idxno 는 skip (**조기중단 금지** — 앞쪽 done이 커져도 뒤쪽 신규에 도달해야 함)
2. `ArdentNews::extractPlacesAI($title,$body,$AI_KEY)` → `api.anthropic.com/v1/messages`
3. `ai_geocode()` — 카카오 POI 검색 + **지역 검증**(주소에 시군구 포함 1순위 / 시도 포함 2순위 / 불일치면 보류) ← 오도시 방지
4. `ai_match()` — **좌표 250m + 정규화 이름 완전일치** 엄격 중복판정(분류 무관 → 네이버 맛집/캠핑과도 겹침 검사)
5. 매칭됨 → 기존 마커 보강(기사 ref 링크 + features/월 태그 누적). **요약은 최초 1회만 고정**(같은 장소 반복 기사로 재요약 churn 방지)
   매칭 없음 → 신규 `place` upsert(`attributes.source_site='ardentnews'`)
6. 기사 간 `sleep(2)`, 페이지 간 `usleep(300ms)` — 매너/블록 회피

- `place` enum 에 `cafe` 가 없어 `cafe → restaurant` 매핑.
- 예산 미도달 완주 + 실적 있으면 Pushover 1회.
- **현재 4종(travel·restaurant·stay·camping) 전부 소진(0곳)** 상태 → 매월 fire는 신규 기사만 훑는다.
- 상태: `tbl_ardent_state`(`last_page`, `last_run`, `ai_last_run`, `cc_last_run`), `tbl_ardent_crawl`(idxno PK),
  회차 기록 `tbl_ardent_run` / `tbl_ardent_run_item`(`classes/ArdentLog.class` 전담 · 화면 `ardent_log.php`)
- HTML 캐시: `sys_get_temp_dir()/ardent_cache`
- 외부: `ardentnews.co.kr`(목록 AJAX `ajaxArticlePaging.php`), `api.anthropic.com`, `dapi.kakao.com`
- 필요 키: `env/anthropic.inc` 또는 `ANTHROPIC_API_KEY`, `env/kakao.inc`

---

### 3.7 `market/crawl.php` — 시장동향 모닝 브리핑

등록(확정): `10 8 * * 1-6` = **월~토 08:10**
```
https://economist.kr/market/crawl.php?key=econ-mkt-7x3k&report=1&notify=1&bg=1
```
그 외 파라미터: `&date=YYYY-MM-DD`(기준일 강제) · CLI: `php market/crawl.php --report --notify`

**7개 소스를 수집 → 정규화 → 품질게이트 → `market_snapshot` 1행 upsert.**

| 섹션 | 소스 |
|------|------|
| 해외지수·환율·채권금리·원자재 | 한경 데이터센터 |
| 유가·금(WTI·국제금) | 네이버 marketindex |
| 국내지수(코스피/코스닥/200) | 네이버 일봉 (실시간 polling 의 0% 문제 회피용으로 일봉 채택) |
| 증시 자금동향(예탁금·신용·펀드) | 네이버 `sise_deposit` |
| 투자자별 매매동향 | 네이버 `investorDealTrendDay` |
| 시황 뉴스(국내 401 / 뉴욕 403) | 네이버 금융 뉴스 |
| 미국·일본 시총 상위 25 | 야후 파이낸스 / 야후 재팬 |
| 국내 대형주(삼성전자·SK하이닉스) 시총 | `all_stock_info` **직접 조회** ← 유일한 DB 의존 |

**설계 포인트**
- **거래일(기준일) = 투자자 매매동향의 최신일.** 없으면 직전 평일. 아침 크롤이면 전일이 기준일이 된다. 스냅샷 PK가 이 날짜다.
- **누락은 0%가 아니라 `missing`** 으로 분리 → 리포트 하단 "미수집·함께 채울 항목". `integrity.missingCount`/`dummyCount` 로 품질 집계.
- `report=1` 이면 같은 요청에서 `report.php` 를 이어 실행(`rebrief=true`) → **크론 1개로 수집+리포트 일괄**. 실제 등록이 그렇게 돼 있고, README의 "06:00 crawl / 07:00 report 2개" 는 낡았다.
- **일요일 제외(`1-6`)** → 월요일 브리핑의 기준일은 금요일 거래일이 된다.
- `bg=1` 이 붙어 있지만 패턴 A라 **실제로는 무효**다. 전체가 15.6초라 30초 안에 끝나 문제되지 않는다(나중에 소스가 늘어 30초를 넘기면 패턴 B로 바꿔야 한다).
- 브리핑 문구는 Claude(`env/anthropic.inc`). 키 없거나 실패하면 **이상치 요약으로 자동 폴백** — 리포트는 항상 생성된다.
- `env/cnt.inc` 를 안 쓰고 **직접 PDO 연결**(`market/db.php`) — `StockSummaryCache` 등 부작용 회피.
- 테이블: `market_snapshot(snap_date PK, data LONGTEXT, html LONGTEXT, …)` — `html` 은 리포트 캐시.
- 별도 파일: `market/api.php`(라이브 투자자 추이 조회), `market/diag.php`(소스 진단)

---

### 3.8 수동 배치 4종

#### `cron/place_geocode.php` — place 좌표화
`?key=econ-place-geo[&limit=300][&reset_failed=1]` · 예산 25초·간격 120ms·배치 50

1단계) 주소 있는 pending → **네이버 주소 지오코딩** → 실패 시 **카카오 키워드(주소)** 폴백
2단계) 주소 없는 pending → **카카오 키워드(이름)** + 점수 검증

**점수 규칙**: 이름 정확일치 +5 / 이름 5자↑ 부분일치 +3 / 진짜 행정구역이 주소에 포함 +2 / 상위결과 tie-break +0.01·순위. **임계 3 미만은 실패 처리.**
★ `region_lv1/lv2` 는 크롤러가 키워드로 오염시킨 경우가 많아("충주여행","팜파스") **쿼리에 붙이지 않고 검증 가산점으로만** 쓴다.
필요 키: `env/maps.inc`(NAVER_MAPS_KEY_ID/SECRET), `env/kakao.inc`(KAKAO_REST_API_KEY)

#### `cron/waste_geocode.php` — 공제조합 좌표화
위 파일의 **완전 복제판**(함수 접두어 `wgeo_`, 대상 `waste_companies`). 로직을 고칠 땐 **두 파일을 같이** 고쳐야 한다.

#### `cron/place_tag_backfill.php` — 장소 자동 태깅
`?key=econ-place-tag[&dry=1][&only_ok=1][&season=1][&months=0][&reset_months=1][&after=N][&limit=N]`
- **통제 키워드 사전**(`TAG_DICT` 38개 대표태그) 기반 → 태그 난립 없음. 트리거는 2글자 이상·구체적으로(짧고 흔한 '절·섬·굴' 제외).
- 월 태그: 텍스트에서 `N월` + 숫자 날짜범위(`4.1~5.10`) 추출. `season=1` 이면 테마→월 추론(벚꽃→3·4월, 단풍→10·11월…).
- 검색 대상 텍스트 = 장소명 + **모든 `place_ref` 의 제목·요약 GROUP_CONCAT** + `attributes.keywords`. (`group_concat_max_len = 200000` 세션 설정)
- `INSERT IGNORE` 라 재실행 안전 · 수동 태그를 안 건드린다.

#### `cron/lunar_seed.php` — 음양력 변환표
`?key=econ-lunar-seed[&from=1990-01-01&to=2050-12-31][&limit=9000][&auto=0]`
- 한국천문연구원 `getLunCalInfo` **일자별 1콜** × 22,300일. JSON 우선 → XML 폴백.
- 예산 20초 초과 시 종료하고 **`<meta http-equiv=refresh>` 로 2초 뒤 스스로 이어실행** (브라우저 창을 열어두는 방식).
- **연속 실패 20건이면 중단** — ① data.go.kr 일일 한도(1만) 초과 ② 인증키가 `LrsrCldInfoService` 에 미승인.
- 테이블: `tbl_lunar_solar` (이미 있는 `solar_date` 는 건너뜀)

---

### 3.9 `cron/dt_min.php` — 단타 1분봉 원장 (2026-08-04 신설)

`?task=dt_min&k=…` (레지스트리가 `key=econ-dt-min&job=daily&bg=1` 주입) · **평일 16:45** · 예산 900초

**왜 있나** — 네이버 분봉은 **최근 7거래일**만 준다(실측). 그 뒤로는 어떤 파라미터로도 못 받는다.
단타 화면의 보관 창이 10거래일이라, 오늘부터 **우리가 쌓아야만** 성립한다. 저장처는 `dt_min`(1분봉 원장).

**★수집 대상은 「단타 풀」이 아니라 「풀 ∪ 보유」다** (2026-08-05).
단일본은 **`Dt::targetCodes()`** = `dt_pool`(active=1) **∪** 살아있는 포지션(`pf_position.status <> 'closed'`).
아래 ①③④⑤ 가 **전부 그 하나**를 본다 — 한 곳만 넓히면 「어떤 단계는 보유를 받고 어떤 단계는 안 받는」 어긋남이 난다.
★**보유 종목을 `dt_pool` 에 «담지» 않는다** — 그 표는 상한 20 을 FIFO 로 지우는 표라
(`poolEvictLast` → `poolRemove` → `DELETE dt_min`) 담아 두면 탐색하다 「＋」 한 번 누른 것이
**내가 산 종목의 봉을 통째로 지운다**. 원본은 `pf_position` 이고 여기엔 **참조만** 한다.
★**`prune()` 의 해지잔여 삭제에서 보유는 뺀다** — 옛날 해지한 종목을 나중에 사면 `dt_pool` 에
`active=0` 으로 남아 **수집 대상이면서 매일 지워지는** 밑 빠진 독이 된다(로그엔 성공으로 보인다).
★**보유의 초기 10거래일은 단타 화면이 열릴 때 화면이 당겨 온다**(api `held_init` · 한 번에 하나씩).
크론의 ③ 구멍 치유는 **못 받은 것을 받아 주는 뒷받침**이다 — 편입한 날 분봉을 보려면 화면 쪽이 필요했다.
실측 2026-08-05: **11종목 → 22종목**(풀 11 + 보유 14 − 겹침 3). 11종목이 66.5초였으니 예산 900초에 여유가 크다.

**한 태스크 안의 4단계** — 요건 초안은 크론 3개였지만, 같은 시점에 매달린 순서 있는 일이라 `dart_eod` 처럼 묶었다.

| 단계 | 하는 일 | 함정 |
|---|---|---|
| ① 수집 | **대상 종목**(풀 ∪ 보유)의 **당일분** (키움 ka10080 → 실패 시 네이버) | 종목당 1초 간격 = 20종목 40초 → **bg 필수** |
| ② 만료 삭제 | 보관 창(최근 10거래일) 밖의 봉 · 60일 지난 로그 | ★**반드시 수집 다음** — 순서가 뒤집히면 수집 실패한 날 보관 일수만 줄어든다 |
| ③ 구멍 치유 | `dt_min_log` 가 `partial/none/fail` 인 (종목,날짜) 재수집 (`tries<5`) | 키움이 1년을 보관하므로 며칠 놓쳐도 복구된다. 네이버 폴백은 7거래일까지만 |
| ④ 분할 감지 | `krx_amt.list_shrs` 가 전일 대비 ±20% → **전량 삭제 후 재수집** | 계수를 곱하지 않는다 — 10거래일이면 5콜이라 다시 받는 편이 싸고 확실하다 |
| ⑤ 적재율 감시 | 치유까지 끝난 뒤 **실제로 남은** 종목 수(`Dt::collectedCount`)가 절반 미만이면 `cron_fail_notify` priority 1 | ★이 파일은 실패를 **전부 자체 catch 로 삼킨다**(네이버 폴백을 위해) → 중앙 실패 알림이 못 본다. §2.5 규칙 3 대로 스스로 쏜다 |

**★마감 뒤여야 하는 이유 (타이밍 의존)** — 「오늘이 거래일인가」를 `krx_amt` 의 오늘 행으로 판정하는데,
그 행은 **15:50 `dart_eod`** 가 `all_stock_info` 스냅샷에서 넣는다(`src='n'`). 앞에 두면 매일 「휴장일」로
오판해 그날 분봉을 조용히 건너뛴다. 폴백으로 `all_stock_info.uDate` 도 보지만, 순서를 지키는 편이 낫다.
(15:30 종가 단일가 체결 반영도 16:00 이후여야 안전하다)
**★16:25 가 아니라 16:45 인 이유** — 바로 앞 `etf_update`(16:20)가 실측 901.7초라 16:35 까지 돈다(§1).
데이터 조건은 15:50 이후면 똑같이 만족하므로, 겹치지 않게 뒤로 물린 것이다.

**진단·수동 job**
```
php cron/dt_min.php job=diag code=005930    ★키움 시각 기준(V-1)·거래량 기준(V-4)을 네이버와 대조
php cron/dt_min.php job=status              보관 창·종목별 적재 현황
php cron/dt_min.php job=init code=005930    한 종목 10거래일 초기 적재
php cron/dt_min.php job=heal                구멍만 치유
```

**✅ `job=diag` 실측 완료 (2026-08-04 · 005930)**
```
대조 381분 · 같은 분 일치 381 · 1분 당겨 일치 94 · 거래량 일치 379
★ TS_BASE='start' 가 맞습니다   ★ 거래량 기준 일치
```
- **봉 시각은 「시작」** — `Kiwoom::TS_BASE='start'` 확정. 구조로도 같다(09:00 있고 08:59 없음 · 15:19 있고 15:20 없음 · 15:30 단일가 · 시간외 마지막 19:59).
- **`_AL`(SOR)은 쓰지 않는다** — 거래량 기준이 네이버와 달라(KRX 29,246,067 · NXT 14,315,959 · _AL 43,555,914)
  폴백·장중 실시간과 **한 종목의 하루 봉에 두 기준이 섞인다**. `env/kiwoom.inc` 의 `KIWOOM_NO_SOR=true`(KRX 단독).

**★키움은 IP 화이트리스트다.** `[8050:지정단말기 인증에 실패했습니다]` 는 키 문제가 아니라 **등록 IP 제한**이다
(키움 「계좌 API KEY 관리 > IP 등록 및 현황」 · 최대 10개). 서버 IP `220.73.160.47` 이 등록돼 있어야 한다
— 웹 서비스 IP 와 나가는 IP 가 같다(실측). 작업 PC 의 IP 도 함께 두면 서버가 막혔을 때 같은 요청을 PC 에서 재현해
진단할 수 있다(V-1·V-4 를 그렇게 답했다). **키 만료 2027-08-04**(1년) · App Key/Secret 은 1회만 다운로드된다.

**★잡 이력(Save responses)에는 `queued …` 한 줄만 남는다** — bg 라 0.07초에 응답하기 때문이다.
「아무것도 안 했네」로 읽지 말 것. **실제로 무엇을 했는지는 `?task=dt_min&k=…&log=1`** 에 있다.
같은 이유로 cron-job.org 의 실패 알림은 HTTP 레벨(500·DNS·인증서)만 잡는다 — 수집 실패는 ⑤가 Pushover 로 쏜다.

**쓰는 표** — `dt_pool`(종목 풀) · `dt_min`(1분봉) · `dt_min_log`(일자별 상태) · `dt_token`(접근토큰 캐시)
**읽는 표** — `pf_position`·`pf_portfolio`(보유 종목 · **읽기만** 한다) · `krx_amt`(거래일·창·분할 감지) · `all_stock_info`
**필요 키** — `env/kiwoom.inc` (`KIWOOM_APP_KEY`·`KIWOOM_SECRET_KEY`). 없으면 네이버 폴백으로 **당일만** 쌓인다.
**소비 화면** — `/stock/index.php?mode=short` (단타 · 다크 3분할)

---

## 4. 외부 의존성 총람 — 어디가 끊기면 무엇이 멈추나

| 소스 | 쓰는 크론 | 한도/차단 특성 | 끊기면 |
|------|-----------|----------------|--------|
| **네이버 금융** (`finance.naver.com`, `m.stock.naver.com`, `polling.finance.naver.com`, `api.finance.naver.com`, `fchart`) | `keyword_collector`(전 mode), `dart_collect`(quotes·range), `market/crawl`, 포트폴리오·시뮬레이터 화면 | 공식 한도 없음. **IP 차단 위험** → 간격 100ms 고정 | ★ **사이트 전 주가가 멈춘다.** 단일 원천 |
| **네이버 지도** (`pcmap.place.naver.com`) | `naver_collect` | **429 = IP당 누적 예산형**. 3초 간격 OK | 맛집/스테이/캠핑 수집만 |
| **DART OpenAPI** (`opendart.fss.or.kr`) | `dart_collect`(fresh·quarter·shares) | **하루 20,000회**. `fresh` 는 200회(1%) | 재무 스크리너 갱신 |
| **KRX 오픈API** (`data-dbg.krx.co.kr`) | `dart_collect`(krx) | 인증키≠서비스권한(서비스별 신청). **PER/PBR 서비스 없음**. T+1·**보관 11년 이상**(2026-07-31 실측 — 옛 「730일」은 2년까지만 찍어 본 오기) | **상장주식수 → PER 분모** |
| **키움 REST** (`api.kiwoom.com`) | `dt_min`(단타 분봉) | 접근토큰(만료 10분 전 재발급) · TR별 **1 req/s**·429 백오프 · 분봉 **1년 보관** · ★코드는 `_AL`(SOR) | 단타 분봉이 **네이버 폴백(7거래일·당일 위주)** 으로 떨어진다 |
| **카카오 로컬** (`dapi.kakao.com`) | `place_geocode`, `waste_geocode`, `ardent_crawl` | 일 한도 있음 | 좌표화 |
| **네이버 지오코딩** (`maps.apigw.ntruss.com`) | `place_geocode`, `waste_geocode` | 유료 쿼터 | 주소 좌표화(카카오 폴백 있음) |
| **data.go.kr** (공휴일 `SpcdeInfoService` / 음양력 `LrsrCldInfoService`) | `schedule_alert`(sync_holidays), `lunar_seed` | **일 1만** · 서비스별 승인 필요 | 공휴일·음력 |
| **Anthropic** (`api.anthropic.com`) | `ardent_crawl`(run_ai), `market/report` | 유료 토큰 | AI 추출 / 브리핑 문구(폴백 있음) |
| **한경 데이터센터** | `market/crawl` | – | 해외지수·환율·채권 |
| **야후 / 야후재팬** | `market/crawl` | – | 미·일 시총 상위 |
| **아덴트뉴스** | `ardent_crawl` | 서버 크롤 가능(아덴트는 403 아님) | 여행지 신규 발굴 |
| **Pushover** | 전 크론 알림 | 월 메시지 한도 | 알림만 |

---

## 5. 테이블 → 쓰는 크론 (역인덱스)

새 기능이 어떤 테이블을 읽을 때, **누가 그 값을 바꾸는지** 확인하는 표.

| 테이블 | 쓰는 크론 | 주의 |
|--------|-----------|------|
| `all_stock_info` | `keyword_collector`(stock_etf_news, **평일 6회** — NXT 창 3회는 건너뜀) / `dart_collect`(quotes·eod) / **단타 화면**(`Dt::refreshQuotesLive` · 대상 종목만 · 키움) | ★ **주가의 단일 원천.** ① 정규 경로에 `DELETE … NOT IN` 있음 ② **정규 fire 마지막이 15:05** → **종가는 `job=eod`(15:50)가 확정한다**(NXT 를 껐으므로 그 뒤로는 안 움직인다) ③ **주말 미갱신** ④ `uDate=NOW()` 명시 필수(`ON UPDATE CURRENT_TIMESTAMP` 는 값이 안 바뀌면 발동 안 해 거래정지 종목이 영원히 "낡음") ⑤ ★**15:30~15:35 에 `uDate` 를 찍지 말 것** — `staleCutoff()` 의 「오늘 15:30 이전」 조건 밖이라 영원히 신선으로 판정돼 `job=eod` 가 건너뛴다(실측으로 33종목 종가가 굳었다) |
| `all_etf_price` / `all_etf_info` / `all_etf_holdings_info` | `keyword_collector`(stock_etf_news / etf_update) | – |
| `krx_daily` | `dart_collect`(krx) | **상장주식수 + 거래종목 판정** 전담. `close_prc` 는 폴백 전용 |
| `stock_financial` | `dart_collect`(fresh·quarter) | 분기는 **누적(YTD)** 저장. 규칙 `thstrm_add ?? thstrm` |
| `stock_fundamental` | `dart_collect`(shares) | 연도별 주식수 |
| `stock_daily_range` | `dart_collect`(range·eod) | **원본**(332,593행). 여기서 집계를 접는다 |
| `stock_price_range` | `dart_collect`(range·eod, syncPriceRange) | **6개월로 접은 캐시** |
| `market_trend_snapshots` / `_keywords` | `keyword_collector`(stock_etf_news) | 종목 급등 키워드 |
| `news_trend_snapshots` / `_keywords` | `keyword_collector`(news) | 섹션 뉴스 키워드. `news_stopwords` 읽기 |
| `market_snapshot` | `market/crawl` | `snap_date` PK, JSON + HTML 캐시 |
| `dt_min` / `dt_min_log` / `dt_pool` / `dt_token` | `dt_min`(단타) | ★**보관 창 = 최근 10거래일**. 매일 그 밖을 지운다 — 옛 분봉을 여기서 찾지 말 것. 거래일 판정·창 계산은 `krx_amt` 를 읽는다(별도 휴장일 표 금지) |
| `place` / `place_ref` / `place_tag` | `naver_collect`, `ardent_crawl`, `place_geocode`, `place_tag_backfill` | **4개 크론이 공유.** 삭제는 FK CASCADE |
| `place_naver_stat` | `naver_collect` | 회차 추이 스냅샷 |
| `naver_collect_region` / `_log` | `naver_collect` | 진행 큐 |
| `naver_reconcile_watch` | `naver_collect`(reconcile) | 소멸 감시 1차 |
| `tbl_ardent_crawl` / `tbl_ardent_state` | `ardent_crawl` | idxno 처리이력 / resume 포인터 |
| `waste_companies` | `waste_geocode` | – |
| `tbl_schedule*` / `tbl_holiday` | `schedule_alert` | – |
| `tbl_lunar_solar` | `lunar_seed` | 1회 적재 |
| `dart_corp_code` | `Dart::ensureTables` | DART 고유번호 매핑 |

---

## 6. 화면 → 데이터를 만드는 크론 (소비자 관점)

| 화면 | 먹는 데이터 | 만드는 크론 |
|------|-------------|-------------|
| `stock/index.php?mode=fund` (재무 스크리너) | `stock_financial`, `stock_fundamental`, `krx_daily`, `all_stock_info`, `stock_price_range` | `dart_collect` **전 job** |
| `stock/index.php` (포트폴리오·시뮬레이터) | `all_stock_info`, 네이버 일봉 | `keyword_collector`(stock_etf_news), `dart_collect`(quotes) |
| `etf_stock.php` | `all_etf_*` | `keyword_collector`(etf_update) |
| `stock/index.php?mode=short` (단타) | `dt_min`(1분봉 · 10거래일), `dt_pool`, `all_stock_info`(목록 시세), 기존 `action=daily`(일봉 패널) | **`dt_min`** · `keyword_collector`(stock_etf_news) · `dart_eod`(거래일 판정용 `krx_amt` 오늘 행) |
| `analysis_model.php?mode=daily` | `news_trend_*` | `keyword_collector`(news) |
| `market/report.php` | `market_snapshot` | `market/crawl` |
| `places.php` | `place*` | `naver_collect`, `ardent_crawl`, `place_geocode`, `place_tag_backfill` |
| `naver_trend.php` | `place_naver_stat` | `naver_collect` |
| `schedule.php` | `tbl_schedule*`, `tbl_holiday`, `tbl_lunar_solar` | `schedule_alert`, `lunar_seed` |
| `coop.php` | `waste_companies` | `waste_geocode` |

---

## 7. 현재 문제 (2026-07-30 기준)

### ✅ P1 — 해결됨 (2026-07-30): `job=fresh` 에 `bg=1` 적용
**증상이었던 것**: 등록 #3(08:05)이 매일 `Failed (timeout) 30s`. 78초 작업인데 `bg=1` 이 없었다.
`cron/dart_collect.php` 는 bg/run 이 아닌 요청에 `ignore_user_abort(false)` 를 걸어 좀비를 막으므로 — 연결이 끊기면 **정말로 죽었다.** 5슬롯 중 앞 1~2개만 갱신되고 나머지는 매일 안 받았다. (다른 빨간불들과 달리 이건 실제 데이터 손실이었다.)

**조치 완료**: URL을 `…&job=fresh&bg=1` 로 교체 → 던지기 0.07초로 크론은 즉시 성공, 본작업은 뒤에서 완주.

**다음 실행(평일 08:05) 뒤 반드시 한 번 확인할 것** — bg는 화면이 없어 성공 여부가 크론 UI에 안 나온다:
```
https://economist.kr/cron/dart_collect.php?key=econ-dart-collect&job=log&n=80
```
정상이면 `최신 5개 슬롯 갱신` → 슬롯 5줄(`종목 …· 저장 …`) → `갱신 완료.` → `총 78.x초` 가 보인다.
`★ 자기호출 실패(…)` 가 보이면 소켓 폴백으로 떨어진 것이고, 로그가 아예 비어 있으면 bg 요청이 도달하지 못한 것이다.
같이 볼 것:
```
https://economist.kr/cron/dart_collect.php?key=econ-dart-collect&job=status
```

### 🟠 P2 — 빨간불 4개 → **코드 교체 완료(2026-07-30) · 배포 + URL 변경 대기**
#9 `etf_update` / #10 `rise_analysis` / #12 `cat=stay` / #13 `cat=camping` 은 전부 **패턴 A**라 응답이 30초를 넘어 상시 실패였다(작업은 `ignore_user_abort(true)` 로 완주).

**한 것** — 공용 헬퍼 `env/cronbg.inc` 를 만들고 3개 파일의 옛 bg 블록을 교체했다.

| 파일 | 바뀐 것 | 로그 |
|------|---------|------|
| `env/cronbg.inc` | **신규** — 자기호출 bg + 로그 + 예산 + 폴백 + 종료훅 | – |
| `cron/rise_analysis.php` | 패턴 A 제거 → `cron_bg_begin(…, 0)`. 시작/완료/스킵/실패를 로그에 남김 | `rise_analysis.log` |
| `cron/keyword_collector.php` | 패턴 A 제거 → `cron_bg_begin(…, sec ?? 3600)`. **모드별 로그 분리.** `etf_update` 에 예산 중단 + 진행로그(25개마다) + **정렬을 낡은 것부터로 교체** | `keyword_collector_{mode}.log` |
| `cron/naver_collect.php` | 패턴 A 제거 → `cron_bg_begin(…, max_sec+60)` | `naver_collect_{cat}.log` |

**배포 완료 (2026-07-30 11:25, Posh-SSH 수동 SFTP)** — 4개 파일, 크기 일치 확인.
올리기 전 서버 원본을 백업하고 **git HEAD 와 동일함을 확인**했다(개행만 CRLF 차이). BOM 없음 확인.

**실라이브 검증 결과 (economist.kr 실호출)** — 3개 파일 모두 정상.

| 잡 | 응답 | 로그가 증명한 것 |
|----|------|------------------|
| `rise_analysis` (실작업) | `queued` 즉시 | 뒤에서 **44.2초** 완주 · 신호 3(핵심 2) · 스캔 3,738 · 재테스트 8 |
| `keyword_collector` (`mode=zztest` — 부작용 0) | `queued` 즉시 | **모드별 로그 분리**(`keyword_collector_zztest.log`) · 예산 3600초 표기 · 출력이 로그로 |
| `naver_collect` (`status=1` — DB 쓰기 0) | `queued` 즉시 | **HTML→평문 변환**(`<pre>`·이모지·罫線) · 예산 540초(=max_sec+60) · 스테이 2026-07 250지역 100% |

★ **`rise_analysis` 실측 44.2초** — 크론 타임아웃 30초를 14초 넘긴다. 상시 빨간불의 원인이 숫자로 확정됐다.
★ 세 로그 모두 머리글이 `ssk=***` / `key=***` 로 **마스킹**됐다.
★ 로그는 서버 `/tmp/` 에 있다 — 웹 루트 밖이라 URL 로 직접 안 열린다.

**남은 조치 — §9 의 URL 교체에 흡수됐다.** 새 진입점으로 바꾸면 `bg` 는 레지스트리(`'bg' => true`)가 붙여 주므로 URL에 따로 쓸 필요가 없다.
```
#9   https://economist.kr/cron_job.php?task=etf_update&k=econ-cron-j7k2
#10  (폐기 — 2026-07-31 상승확률 분석 삭제. 크론 사이트에서 등록도 지운다)
```

**아직 실측 안 된 것**: `etf_update` 의 실제 소요. 부작용(ETF 갱신·Pushover) 때문에 지금 강제로 돌리지 않았다.
16:20 실행 뒤 아래 로그의 `총 N초` 를 보고 필요하면 레지스트리에 `'sec' => …` 를 넣는다.
```
https://economist.kr/cron_job.php?task=etf_update&k=econ-cron-j7k2&log=1
```
(로컬 격리 테스트로 예산 중단·`exit`·치명오류 경로는 이미 확인했다 — 1초 예산에서 정확히 중단, 종료훅이 오류 메시지까지 로그에 남김.)

### 🟡 P3 — 코드 주석·제목이 등록 상태보다 낡은 곳 (동작엔 문제 없음)
2026-07-30 EDIT 화면으로 전부 확인 완료. 남은 건 **문서/제목만 고치면 되는 것들**이다.

| 항목 | 실제 등록 (확정) | 낡은 기록 |
|------|------------------|-----------|
| `job=krx` | 13:05 **오후 1회** | `cron/dart_collect.php` 주석의 "오전·오후 두 번" |
| `job=fresh` | 08:05 | 주석의 "0 7 * * *" 권장 시각 |
| `job=eod` | 15:50 | ~~크론 제목 15:40~~ → **2026-08-02 확인: 제목 이미 15:50 으로 수정돼 있음. 해소** |
| `market/crawl` | 08:10 **1개**로 `&report=1&notify=1&bg=1` 묶음 (월~토) | `market/README.md` 의 "06:00 crawl + 07:00 report 2줄" |
| #6 회수 | **평일 9회**(`6-9,11,13,15,17,19`) | **크론 제목이 `get_news_keyword (1일 7회)`** |

**조치(선택)**: ~~크론 제목 2개~~ **2026-08-02 확인: 크론 제목들(`당일종가 15:50`·`get_news_keyword 1일 9회`)은 이미 수정돼 있다.** 남은 것은 `market/README.md` 의 크론 절뿐.

### 🟠 P3.5 — cron-job.org 모니터링 설정이 비어 있다 (코드 수정 없이 조치 가능)
EDIT 화면 실측(2026-07-30) — 확인한 잡 전부 아래 상태였다.

| 설정 | 현재 | 문제 |
|------|------|------|
| `Save responses in job history` | **OFF** | 실패했을 때 **응답 본문이 안 남는다.** 빨간불 4개의 실제 오류를 사후에 볼 수 없다 |
| `Notify me when: execution of the cronjob fails` | **OFF** | 지금 초록불인 잡이 **조용히 깨져도 아무도 모른다.** P1이 오래 방치된 이유가 이것 |
| `Notify me when: succeeds after it failed before` | OFF | 복구 감지 없음 |
| `Notify me when: will be disabled because of too many failures` | **ON** | 유일하게 켜진 것 |

**조치 권고**
1. **실패 알림 ON — 단, 초록불 잡에만.** #3 `fresh` · #7 `krx` · #8 `eod` · #4 `market` · #1·#2 알림.
   빨간불 4개(#9·#10·#12·#13)는 **매번 실패하므로 켜면 알림 폭탄**이다. P2를 고친 뒤에 켠다.
2. **`Save responses` 는 빨간불 4개에 먼저 ON.** P2를 고칠 때 실제 응답을 봐야 한다.
   (단 `Notify after 1 failure` 기본값이면 하루 1건씩 오므로 `fail` 알림과 혼동하지 말 것.)
3. ⚠️ **cron-job.org 는 실패가 계속되는 잡을 비활성화할 수 있다.** 그 알림 토글이 ON이니 메일이 왔는지 확인해 볼 것 — 빨간불 4개는 **매 실행 실패**라 후보다. 비활성화되면 `Enable job` 이 꺼져 **작업 자체가 안 돈다**(지금은 "빨간불이지만 완주" 상태이므로 이 선이 넘어가면 성격이 달라진다).

### ℹ️ P4 — 구조적 부채
- **토큰 3가지 방식**(`ssk`/`key`, 값 5종). 하나로 모으면 관리가 쉬워진다.
- `cron/place_geocode.php` ↔ `cron/waste_geocode.php` **로직 완전 중복**. 공용 클래스로 뽑을 후보.
- `cron/keyword_collector.php` 는 **성격이 다른 3개**(장중 시세 / 뉴스 키워드 / ETF 편입종목)를 한 파일에 담고 있다. ETF만 떼면 timeout 관리가 쉬워진다.
- 크론 안에서 `CREATE TABLE` / `ALTER TABLE ADD COLUMN IF NOT EXISTS` 로 스키마를 만드는 곳이 여럿(`keyword_collector` news, `ardent_crawl`, `lunar_seed`). 편하지만 스키마 소스가 흩어진다.
- **`job=range` 에 종목 지정 수단이 없다.** `NaverFinanceAPI::refreshPriceRange($pdo, $codes, …)` 는 `$codes` 를 받는데 `cron/dart_collect.php` 의 `job_range()` 가 **`[]` 로 하드코딩**해 넘긴다. 그래서 `stock_daily_range` 에 구멍이 난 몇 종목만 다시 받을 방법이 없고, `full=1` 전량 재수집(340초·SSH)뿐이다. `&codes=005930,000660` 파라미터를 하나 뚫어 두면 편해진다.
- **씨뿌리기 이후 상장한 종목은 6개월 창이 상장일부터만 잡힌다.** `appendTodayRange` 는 "오늘 한 줄"만 붙이므로 과거를 못 채운다. 신규 상장은 원래 6개월 이력이 없으니 정상이지만, **씨뿌리기 당시 실패한 종목은 구멍이 영구적**이다(첫 수집 때 5.4%·150종목이 실패했고 원인은 "거래정지일=종가만 옴"이었다). 스크리너의 고점대비·저점대비가 특정 종목만 이상하면 이걸 의심하고 `job=range&full=1&force=1` 로 전량 재수집한다.

---

## 8. 새 크론 만들 때 체크리스트

**먼저 판단**: 아래에 해당하면 **새 파일을 만들지 말고 기존에 얹는다.**
- 주식 시세·재무·고저 → `cron/dart_collect.php` 에 `job=` 추가 → `TASKS` 에 한 줄
- 네이버 장소 수집 → `cron/naver_collect.php` 의 `CATS` 에 카테고리 추가 → `TASKS` 에 한 줄
- 일정·알림 → `cron/schedule_alert.php` 에 `mode=` 추가 → `TASKS` 에 한 줄
- 시장 지표 1개 추가 → `market/config.php` 의 `MKT_SRC` 에 항목 추가
- **같은 파일·다른 옵션이면 파일은 그대로 두고 `TASKS` 에만 추가한다** (예: `dart_status`·`dart_log`·`dart_slots` 는 전부 `dart_collect.php` 다)

**새로 만들 때**
0. ★ **루트에 만들지 않는다. `cron/` 밑에 만든다.** 크론 사이트에는 `?task=<이름>&k=…` 만 등록한다
1. **파일 구조**: 라우터(`job=`/`mode=`) + 기능함수 단일 파일 (레포 관례)
2. **경로**: env 는 `$_SERVER['DOCUMENT_ROOT'] . '/env/…'` 로 읽고(상대경로는 하위 폴더에서 안 통한다),
   맨 위에서 `require_once __DIR__ . '/_boot.php'` (CLI 에서 DOCUMENT_ROOT 가 비는 것 대비)
4. **30초 판정**: 실측 30초를 넘길 수 있으면 **`env/cronbg.inc`** 를 쓴다(§2.2). 패턴 A는 쓰지 말 것
5. **bg를 쓰면 3종 세트가 따라온다**(헬퍼가 ①②③을 다 해 준다): ① 로그 파일(웹 루트 밖) + `&log=1` ② 시간예산 ③ 소켓 실패 시 그 요청에서 처리하는 폴백
   - 예산은 **`cron_bg_over()` 를 보는 루프가 있어야** 실효가 있다. 없으면 `0` 을 넘기고 로그로 소요만 재라
   - **예산을 넣으면 정렬도 공정하게** — 매번 같은 자리에서 끊겨 뒤쪽이 굶는 일을 막는다(`etf_update` §3.2 참조)
   - 한 파일이 여러 모드를 처리하면 **로그 경로를 모드별로 나눈다**(잦은 모드가 드문 모드의 로그를 덮는다)
6. **이어받기**: 상태를 DB에 남겨 다음 호출이 이어받게 한다. 크론을 여러 번 걸면 알아서 드레인되고, 끝난 뒤 fire는 no-op이라 공짜
7. **멱등**: 하루 여러 번 들어와도 같은 결과여야 한다
8. **외부 호출 간격**: 네이버는 **100ms 이상**, 네이버 지도는 **3초**. 줄이지 말 것
9. **`DELETE … NOT IN` 금지**: 부분 응답이 데이터를 지운다
10. **`.class` 파일은 no-BOM**, 수정 후 `php -l` (단 `.class` 는 lint 시 Segfault 사례 있음)
11. **상태 모드**(`status=1`)를 같이 만든다 — 쓰기 없이 현황만 보는 URL이 있으면 운영이 편하다
12. **완료 알림은 1회만**: "이번 호출이 마지막 작업을 처리해 완료가 된 순간"에만 발송(no-op fire에서 반복 발송 금지)
13. **`TASKS` 에 등록**하고 `&explain=1` 로 조립 결과를 확인한 뒤, 크론 사이트에 `?task=<이름>&k=…` 만 넣는다.
    `'cron'`·`'desc'` 도 같이 채운다 — `task=list` 와 §1 표가 그걸 읽는다

**환경 제약 요약**
- cafe24 웹호스팅 · PHP 8.4 · **apache2handler** · MariaDB 10.6.17
- 서버/PHP 실행 한도는 넉넉하다(웹 요청 실측 106초, 340초짜리도 완주). **막는 것은 크론이 기다려 주는 30초뿐** → 해법은 "쪼개기"가 아니라 bg
- 서버 셸에 **`ps`·`sleep`·`stat` 이 없다.** 없는 명령은 에러만 내고 진행하므로 대기 루프가 즉시 다 돈다 → 진행 판정은 **DB로** 한다
- **nohup 백그라운드를 호스팅이 조용히 죽인다**(34분/6분·불규칙) → 긴 SSH 작업은 budget 조각 + 포그라운드 반복

---

## 9. 이행(migration) — 지금 어디까지 왔나

**2026-07-30 개편**: 루트에 흩어져 있던 `cron_*.php` 10개를 `cron/` 로 옮기고, 진입점을 `cron_job.php` 하나로 모았다.

### 끝난 것
- `cron/` 생성 + 10개 이동(`git mv` 로 이력 보존) + 파일명에서 `cron_` 접두어 제거
- **include 24곳**을 `$_SERVER['DOCUMENT_ROOT']` 기준으로 교체 (하위 폴더에선 상대경로가 안 통한다)
- `cron/_boot.php` — CLI 에서 `DOCUMENT_ROOT` 를 **`dirname(__DIR__)`** 로 세팅.
  ★ `__DIR__` 로 두면 `/cron` 이 되어 오토로더가 `/cron/classes/` 를 뒤지다 죽는다
- `cron_job.php` — 레지스트리 27개 + 디스패처 + `task=list` + `explain=1`
- 배포 완료(`/cron` 원격 폴더 생성 포함) · 실라이브 검증 통과 (아래)
- 안내 문자열 참조 갱신: `db_waste_setup` · `naver_trend` · `place_api` · `stock/index` ×3 · `stock/api` · `README` · `CLAUDE.md` · `market/README`

### 실라이브 검증 (economist.kr 실호출)
| 확인 | 결과 |
|------|------|
| `task=list` | 27개 정상 출력 · 토큰 없으면 **403** |
| `task=dart_status` | **하위 폴더에서 DB·오토로더 정상** (0.1초) — 경로 교체가 맞았다는 증거 |
| `task=rise` (bg 왕복) | `queued` → 되돌아온 요청이 디스패처를 거쳐 **42.5초 완주**. 로그 머리글에 `k=*** task=rise` |
| `task=rise&log=1` | 즉시 응답 (읽기 전용이라 bg 안 붙음) |
| `&explain=1` | 자기호출 URL에 `k`·`task` 둘 다 존재 확인 |
| 토큰 마스킹 | 로그에 `k=***` (개편 중 `k` 가 평문으로 남는 것을 발견해 수정) |
| 교체 후 프로덕션 | `알림: 사전 알람` 12:20:07 성공(1.21초) — 10분 주기라 **새 경로로 실제 실행 확인** |

### ✅ 이행 완료 (2026-07-30)
- 크론 사이트 **URL 15개 전부 교체**됨 (`cron_job.php?task=…&k=…`). 스케줄은 그대로.
  - `task=` 를 `k=` **앞에** 둔다 — 목록 화면이 URL을 47자쯤에서 자르는데, `k` 가 앞이면 전부
    `…cron_job.php?k=econ-cron-j7…` 로 똑같이 보여 **어느 잡인지 구분이 안 된다**.
- **루트 포워더 스텁 10개 삭제 완료** (로컬·서버). 참조가 남아 있지 않음을 grep 으로 확인 후 지웠다.
  옛 URL(`/cron_rise_analysis.php?…`)은 이제 **404** 다 — 모든 크론이 새 경로를 가리키므로 정상이다.

**최종 배치**
```
cron_job.php          ← 루트에 남는 유일한 크론 파일 (진입점)
cron/                 ← 구현 11개 (_boot.php 포함)
env/cronbg.inc        ← bg 헬퍼
market/crawl.php      ← 예외: market 모듈 소속, 레지스트리에만 등록
```

**롤백이 필요하면** 스텁 없이도 각 파일을 직접 부를 수 있다(파일은 `cron/` 에 그대로 있다):
```
https://economist.kr/cron/keyword_collector.php?ssk=mysn1973!&mode=etf_update&bg=1
https://economist.kr/cron/dart_collect.php?key=econ-dart-collect&job=fresh&bg=1
```

### 이 개편의 함정 (다음에 폴더를 또 옮긴다면)
1. **상대경로 include** — 웹 요청의 CWD는 스크립트 디렉터리다. 하위로 내리는 순간 전부 깨진다
2. **CLI 의 빈 `DOCUMENT_ROOT`** — 오토로더가 그 값을 쓰므로 첫 클래스에서 죽는다. `_boot.php` 가 막는다
3. **옛 URL 동시 사망** — 파일을 옮기는 순간 크론 15개가 한꺼번에 404. **포워더 스텁을 먼저 깔고** 옮긴 뒤,
   등록 URL을 전부 바꾼 것을 눈으로 확인하고 스텁을 지운다(이번에 그렇게 했다 — 중간에 깨진 구간이 없었다)
4. **bg 자기호출의 `k`·`task`** — `$_GET` 에서 하나라도 빠지면 되돌아온 요청이 막혀 **작업이 조용히 사라진다**
5. **단일 장애점** — `cron_job.php` 문법 오류 하나면 15개가 동시에 멈춘다. 배포 전 `php -l`, 배포 후 `task=list`
