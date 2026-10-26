# 관심그룹 테마 md 가져오기 — 구현 지시서 v1

> 작성: claude.ai 채팅의 Claude · 2026-10-26 · 근거: `관심그룹_현황_답변.md`(이하 답변서)
> 함께 받는 파일: `테마md_형식_v1.md`(형식 스펙) · `아틀라스테마_2026-10-26.md`(첫 실물 = 테스트 견본)

## 0. 진행 방식
1. 이 문서와 두 파일을 읽고 **계획을 먼저 보고**한다. 계획에는 바꿀 파일·함수, 새 DDL, 단계, 위험을 담는다. 구현은 사용자 승인 뒤에 한다.
2. 운영 DB에 바로 쓰는 구조다. 쓰기 시험은 트랜잭션 롤백(SAVEPOINT 래퍼)으로만 한다.
3. 커밋은 사용자 지시가 있을 때만 한다.
4. 스펙·견본은 레포 `docs/wg-theme/`에 둔다. CLAUDE.md 「/stock 관심그룹」 절에 규칙 17(테마 md 가져오기)을 추가한다.

## 1. 목표
채팅 Claude가 만든 테마 md(`wg: theme/1`)를 붙여넣으면 「미리보기 → 확인 → 적용」으로 관심그룹 하나가 만들어지거나 갱신된다.
- **들어가는 것**: 그룹, 그룹 변수 연결(이미 등록된 것만), 구분행(방향·변수 포함), 종목, 메모, 기준가(asof 종가)
- **보관하는 것**: md 원문(테마 노트 표시와 되돌리기용)

## 2. 정해진 설계

| # | 결정 | 답변서 근거 |
|---|---|---|
| 1 | **위치**: 기존 「그룹 관리 · 일괄등록」 모달의 붙여넣기 칸을 확장한다. 감지 순서는 `parseThemeMd()` → `parseTable()` → `parse()`. 머리말에 `wg: theme/1`이 있을 때만 테마로 본다 | Q17, Q26 |
| 2 | **API**: `module=wg&action=theme_parse`(읽기지만 POST) · `theme_apply` · `theme_undo`. 화면은 원문만 보내고 서버가 다시 읽는다 | table_add 규칙 |
| 3 | **메서드**: `Wg::themeParse(string $md, array $opt): array` · `Wg::themeApply(string $md, array $opt): array` · `Wg::themeUndo(int $importId): array`. 기존 검증을 재사용한다: `cleanName`·`cleanLabel`·`cleanBase`·`StockName::many`·`ITEM_MAX`·고정 그룹 가드 | Q26 |
| 4 | **원자성**: `themeApply`가 트랜잭션을 **혼자** 쥔다. 자기 트랜잭션을 여는 메서드(`groupReorder`·`renumber`·`bulkBase`·`transfer`·`Mv::groupSet`)는 부르지 않는다. 부르려면 `inTransaction()`일 때 자기 트랜잭션을 건너뛰게 고친다 | Q16 |
| 5 | **기준가 `base: close`**: asof 이하 마지막 거래일 종가를 쓴다. `base_date` = 찾은 거래일이다. `research.php`의 `rs_close_on()`은 미커밋이므로 같은 로직을 Wg 안에 둔다 | Q8, Q13 |
| 6 | **변수**: `vars`는 **이미 등록된 `mv_var.name`**만 연결한다(그룹당 4개, 첫째 = 주 변수). 없는 이름은 오류(「화면에서 먼저 등록」)로 처리한다. v1은 네이버 등록 콜을 하지 않아 30초 벽을 피한다 | Q10, Q26-3 |
| 7 | **구분행 방향**: 제목 끝 토큰 `{↑ 변수}`/`{↓ 변수}`를 `dir` 1/−1 + `var_id`로 바꾼다. 라벨에는 토큰을 뺀 제목만 넣는다. 변수 없이 방향만 있으면 오류다 | 자유기재 3, Q26-8 |
| 8 | **메모 조합**: `근거 · 트리거: 값 · 무효: 값 · 메모: 값` (빈칸은 생략). 기존 `parseTable`이 만드는 모양과 같다. 500자를 넘으면 그 행은 오류이고, 자르지 않는다 | Q17, 규칙 14 |
| 9 | **종목 확인**: 코드가 필수다. `StockName::many`의 이름과 md 이름을 공백 제거·대소문자 무시로 비교한다. 다르면 그 행을 빼고 「이름 불일치 md=… / 마스터=…」를 보인다. 마스터에 없거나 ETF인 종목도 뺀다 | Q12, Q14 |
| 10 | **상한**: 원문은 30,000자까지 받는다. 넘으면 거절 메시지를 내고 조용히 자르지 않는다. 전송은 리서치 선례대로 multipart 파일 조각을 권장한다(openresty 128KB 제한) | Q2, Q26 |
| 11 | **확인 버튼**: `confirm()`은 쓰지 않는다. 적용·갈아끼우기·되돌리기는 `arm()` 3초 확인 방식이다 | 규칙 7 |
| 12 | **손대지 않는 곳**: `quant.php`·`stockrow.js`·`quantbadge.js`·`chart_hline`·`pf_watchlist`(Top-Pick) | Q18, 규칙 1·5 |

**기준가 세부 (5번)**
```sql
SELECT d, c, src, list_shrs FROM krx_amt
 WHERE code = ? AND d <= ? AND vol > 0 ORDER BY d DESC LIMIT 1
```
- `src='n'`이면 미리보기에 「잠정」을 표시하고, 적용은 허용한다
- 그 날 이후 `list_shrs`가 1.5배 이상 또는 0.67배 이하로 바뀌었으면 분할·병합을 의심한다. 그 종목은 기준가를 비우고 경고한다. 작은 변화(증자)는 경고만 한다
- asof가 30일보다 오래됐으면 경고한다
- 종가가 아직 없으면(15:50 전) 기준가를 비우고 경고한다
- `base: now` = `all_stock_info` 현재가·오늘 (기존 `bulk_base current`와 같은 값)
- `base: none` = 기준가를 비운다

**파서 규칙 (스펙 4장을 코드로)**
- 머리말: 첫 줄 `---`부터 다음 `---`까지. 각 줄은 `/^([a-z_]+):\s*(.*)$/u`. 모르는 키는 경고만 한다
- 등록 영역: `## 등록` 줄부터 다음 `## ` 줄(또는 끝)까지
  - `### 제목` = 구분행
  - `|`로 시작하는 줄 = 표. 구분줄(`|---|`)과 머리줄(첫 칸이 「종목」으로 시작)은 건너뛴다
  - 빈 줄은 무시한다
  - 그 밖의 줄은 오류다: 「등록 영역에 표·제목 외 줄 (n행)」
- 칸은 머리줄 이름으로 매핑한다. 모르는 칸은 메모에 「칸이름: 값」으로 덧붙인다
- 종목 칸: 코드 `/\(([0-9]{4}[0-9A-Za-z]{2})\)/u` → 대문자. 이름 = `(` 앞 글자
- 숫자 칸: 콤마·「원」을 떼고 정수 1~100,000,000. 아니면 오류
- 첫 구분행 위의 종목은 소속 없이 맨 위에 둔다(기존 동작과 같음)
- 함정: 유니코드는 `/u` 정규식으로 다룬다(`trim` 바이트 함정). 종목코드 배열 키는 `strval`로 되돌린다

## 3. 가져오기 모드 (미리보기에서 고른다)

| 모드 | 조건 | 동작 |
|---|---|---|
| 새 그룹 | 같은 이름 그룹이 없을 때. 미리보기에서 이름을 바꿔 새로 만들 수도 있다 | 그룹 생성(맨 아래) → 변수 연결 → 구분행·종목을 md 순서대로 넣는다 |
| 덧붙이기 | 같은 이름 그룹이 있을 때 | 새 종목만 추가한다. 이미 있는 종목은 메모·기준가·위치를 건드리지 않는다. md 구분행 제목이 그룹에 이미 있으면 그 섹션 끝에 넣고, 없으면 그룹 끝에 새 구분행을 만든다. 변수는 없는 것만 덧붙인다(4개 상한) |
| 갈아끼우기 | 같은 이름 그룹이 있을 때 | 아래 4단계 |

**갈아끼우기 4단계**
1. 그룹의 `wg_item`·`wg_group_var` 전체를 JSON으로 `wg_import.backup_json`에 저장한다
2. 행을 전부 지우고 md대로 다시 만든다
3. 이미 있던 종목은 기준가·기준일을 이어받는다(md에 `rebase: yes`면 새로 계산). 메모는 md 것을 쓴다
4. 그룹 변수는 md `vars`로 교체한다

**미리보기에 반드시 보일 것**: 지워질 종목(보유 중이면 표시), 메모가 바뀌는 종목, 이어받는 기준가, 30종목 초과분, 제외된 행과 이유.

## 4. 새 표 (`ensureTables` · `action=schema`)

```sql
CREATE TABLE IF NOT EXISTS `wg_import` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned DEFAULT NULL COMMENT '그룹이 지워지면 NULL — 기록과 원문은 남긴다',
  `group_name` varchar(30) NOT NULL COMMENT '가져올 때의 그룹명',
  `mode` enum('new','append','replace','undo') NOT NULL,
  `asof` date DEFAULT NULL,
  `review_on` date DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `md` mediumtext NOT NULL COMMENT '원문 — 테마 노트 표시',
  `summary_json` text DEFAULT NULL COMMENT '적용 결과 요약',
  `backup_json` mediumtext DEFAULT NULL COMMENT 'replace 직전 wg_item·wg_group_var',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_group` (`group_id`, `id`),
  CONSTRAINT `fk_wg_import_group` FOREIGN KEY (`group_id`) REFERENCES `wg_group` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='관심그룹 테마 md 가져오기 기록';
```
기존 표(`wg_group`·`wg_item`)는 바꾸지 않는다.

## 5. 미리보기 응답 (`theme_parse`) — 모양 예시

```json
{"group": {"name": "아틀라스테마", "exists": false, "id": 0, "modes": ["new"]},
 "meta": {"asof": "2026-10-26", "base": "close", "review": "2026-11-17", "source": "https://…", "vars": []},
 "sections": [
   {"label": "카메라 — 공급 확정 보도 (1차)", "dir": 0, "var": null,
    "rows": [{"code": "190510", "name_md": "나무가", "name_master": "나무가", "state": "new",
              "base": {"price": "…", "date": "2026-10-26", "src": "n"},
              "memo_len": 0, "warns": [], "errs": []}]}],
 "deletes": [], "memo_changes": [], "over": [], "errors": [], "warns": [],
 "counts": {"sections": 4, "stocks": 9, "excluded": 0}}
```
- `errors`(파일 단위)가 하나라도 있으면 적용 버튼을 막는다
- 행 단위 오류는 그 행만 빼고 적용할 수 있게 한다. 뺀 행은 목록으로 보인다

## 6. 테마 노트 표시
- 그룹에 `wg_import`가 있으면 종목 리스트 위에 접이식 「테마 노트」 한 줄을 둔다. 내용: asof · 재점검(review까지 D-day, 지났으면 「재점검」) · 출처 링크
- 펼치면 가장 최근 원문의 `## 노트`·`## 일정`·`## 출처`를 렌더한다. 리서치 화면의 md 렌더 방식을 재사용하고 HTML은 이스케이프한다
- 접힘 상태는 localStorage(`wg_note_fold`)에 둔다
- 판정·신호는 표시하지 않는다(보는 자리 원칙)

## 7. 되돌리기 (`theme_undo`)
- 그 그룹의 **가장 최근** 가져오기가 갈아끼우기일 때만 가능하다
- `backup_json`으로 `wg_item`·`wg_group_var`를 한 트랜잭션 안에서 복원한다
- 되돌린 것도 `wg_import`에 `mode='undo'`로 기록한다

## 8. 보류 — 사용자 결정 뒤에 한다
- **트리거·무효 숫자**: 구조로 저장하고 위치를 표시할지(「트리거까지 +x% / 무효선 −y%」, 차트 `setPriceLines`) 미정이다. Q18 판정 규칙과 리서치(`rs_level`) 분담을 사용자가 정한 뒤에 한다. **v1은 메모 글로만 넣는다.**
- **일정**: 구조로 저장할지(`rs_item` 연동 등) 미정이다. v1은 노트에 표시만 한다.
- **새 변수 자동 등록**(네이버 콜)

## 9. 단계
1. 계획 보고 → 승인
2. `wg_import` DDL
3. `parseThemeMd`/`themeParse` + 테스트(견본과 실패 견본)
4. `themeApply`(새 그룹·덧붙이기·갈아끼우기) + 롤백 시험
5. 모달 UI: 미리보기 표·모드 선택·`arm` 확인 — 기존 모달 흐름 안에서
6. 테마 노트 패널
7. `themeUndo`
8. e2e 하니스 추가 → 서버 렌더 확인 → CLAUDE.md 규칙 17 → 사용자 확인 후 커밋

## 10. 테스트 (견본 = 아틀라스테마)
1. **새 그룹**: 그룹 1 · 구분행 4 · 종목 9 · 메모 9 · 기준가 9(10/26 종가, src 표시) · 변수 0
2. **멱등**: 같은 파일을 덧붙이기로 다시 넣으면 변화 0
3. **갈아끼우기**: 백업이 생기고, 기준가를 이어받고, 행 순서가 md대로다
4. **되돌리기**: 갈아끼우기 전과 완전히 같다
5. **실패 견본**: 코드 없는 행 / 이름 불일치 / 중복 종목 / 31종목 / 메모 501자 / 없는 변수 / 변수 없는 방향 토큰 / 등록 영역 안 문장 / 머리말 없음(→ 기존 `parseTable`로 떨어짐) / 30,001자
6. **회귀**: 답변서 Q17 견본 A·B가 예전과 같은 결과
7. **하위 호환**: 견본의 `## 등록` 영역만 기존 모달에 넣어도 구분행 4·종목 9로 읽힌다
8. 고정 그룹은 대상으로 고를 수 없다

## 11. 완료 기준
- 아틀라스테마 md를 붙여넣고 「적용」 한 번으로 그룹이 생기고, 기준대비가 10/26 종가 기준으로 보인다
- 모든 오류·제외·초과가 미리보기에 나오고, 조용히 빠지는 것이 없다
- 갈아끼우기 후 되돌리기로 원상 복구된다
- 단타·마감 변동 등 관심그룹을 읽는 화면이 그대로 돈다
