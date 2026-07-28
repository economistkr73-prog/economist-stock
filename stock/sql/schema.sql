-- ══════════════════════════════════════════════════════════════════════
--  주식 포트폴리오(분할매수) 스키마 — economist73 / MariaDB 10.6 / utf8mb4
--
--  참고용 원본. 실제 생성은 classes/Pf.class 의 ensureTables() 가
--  CREATE TABLE IF NOT EXISTS 로 페이지 최초 진입 시 자동 수행한다.
--  (DB host 가 localhost 라 로컬에서 직접 접속할 수 없으므로 자동생성 방식 채택)
--
--  테이블 접두어 pf_ : economist73 은 all_stock_info·tbl_users 등이 함께 쓰는
--  공유 스키마라 stock/portfolio/position/trade 같은 일반명 사용을 피했다.
--
--  ★ 저장하지 않는 파생값 (전부 런타임 계산 — stock/lib/calc.php)
--     이론가 · 이론수량 · 누적단가 · 평가금액 · 평가손익 · 손익분기율
--     · 자동매도가 · 다음매수가
--     → 룰셋을 수정해도 과거 데이터가 깨지지 않게 하기 위함. 컬럼으로 만들지 말 것.
-- ══════════════════════════════════════════════════════════════════════

-- 종목 마스터 (시세는 all_stock_info 에서 동기화하거나 직접 입력)
CREATE TABLE IF NOT EXISTS pf_stock (
  code        VARCHAR(10)   NOT NULL,
  name        VARCHAR(60)   NOT NULL DEFAULT '',
  market      VARCHAR(10)   NOT NULL DEFAULT 'KOSPI',
  last_price  DECIMAL(14,2) DEFAULT NULL,          -- 현재가 (캐시)
  high_price  DECIMAL(14,2) DEFAULT NULL,          -- 최고가 (관측 누적 or 수동)
  priced_at   DATETIME      DEFAULT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 포트폴리오 = 종목을 담는 단위
--   증권사 계좌와 1:1 로 써도 되고(broker/acct_no), 한 계좌를 전략별로 쪼개
--   여러 개를 둘 수도 있다. 대시보드는 이 단위로 그룹핑한다.
CREATE TABLE IF NOT EXISTS pf_portfolio (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(60)  NOT NULL DEFAULT '',
  broker    VARCHAR(40)  NOT NULL DEFAULT '',      -- 증권사
  acct_no   VARCHAR(40)  NOT NULL DEFAULT '',      -- 계좌번호
  principal_seed BIGINT  NOT NULL DEFAULT 0,       -- 이관 시드. 실제 원금은 pf_principal_flow 합계
  cash      BIGINT       NOT NULL DEFAULT 0,       -- 여유자금
  memo      VARCHAR(200) NOT NULL DEFAULT '',      -- 한 줄 메모
  note      VARCHAR(200) NOT NULL DEFAULT '',      -- 상세메모 (여러 줄, 최대 200자)
  sort_no   INT          NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 원금 입출금 이력 — 원금은 이 표의 합계로 파생된다 (증액 +, 인출 −)
CREATE TABLE IF NOT EXISTS pf_principal_flow (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  portfolio_id INT          NOT NULL,
  flow_at      DATE         NOT NULL,
  amount       BIGINT       NOT NULL DEFAULT 0,
  reason       VARCHAR(80)  NOT NULL DEFAULT '',
  created_at   DATETIME     NOT NULL,
  KEY idx_pf_pflow (portfolio_id, flow_at, id),
  CONSTRAINT fk_pf_pflow FOREIGN KEY (portfolio_id)
    REFERENCES pf_portfolio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 시뮬레이터에 올린 일자별 종가. [["2015-07-24",306000], ...] 형태로 통째 보관한다.
--   종목코드가 키다 (종목당 1건). 같은 종목을 다시 올리면 시세만 갈아끼운다.
--   수익률 같은 결과는 저장하지 않는다 — 조건만 들고 있다가 볼 때마다 다시 계산한다.
CREATE TABLE IF NOT EXISTS pf_sim_data (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  stock_code  VARCHAR(10) NOT NULL DEFAULT '',
  stock_name  VARCHAR(60) NOT NULL DEFAULT '',
  from_date   DATE        DEFAULT NULL,
  to_date     DATE        DEFAULT NULL,
  bar_count   INT         NOT NULL DEFAULT 0,
  has_ohlc    TINYINT(1)  NOT NULL DEFAULT 0,    -- 고가·저가가 있으면 장중 체결을 흉내낼 수 있다
  rows_json   MEDIUMTEXT,                        -- [일자,종가] 또는 [일자,종가,시가,고가,저가]
  rule_set_id INT         NOT NULL DEFAULT 0,    -- ↓ 마지막으로 돌린 조건
  broker_id   INT         NOT NULL DEFAULT 0,
  market      VARCHAR(10) NOT NULL DEFAULT '',
  limit_amt   BIGINT      NOT NULL DEFAULT 0,
  opt_from    DATE        DEFAULT NULL,
  opt_to      DATE        DEFAULT NULL,
  reenter     TINYINT(1)  NOT NULL DEFAULT 1,
  wait_days   INT         NOT NULL DEFAULT 0,
  intraday    TINYINT(1)  NOT NULL DEFAULT 1,    -- 장중(고가·저가) 체결 가정
  -- 목록용 요약 캐시. cache_key = 시세버전 + 조건 + 룰셋/수수료/세율 지문이라
  -- 무엇 하나라도 바뀌면 키가 달라져 저절로 무효가 된다 (결과를 '저장'하는 게 아니다).
  data_ver    INT          NOT NULL DEFAULT 0,
  cache_key   VARCHAR(40)  NOT NULL DEFAULT '',
  cache_json  VARCHAR(255) NOT NULL DEFAULT '',
  cached_at   DATETIME     DEFAULT NULL,
  created_at  DATETIME    NOT NULL,
  UNIQUE KEY uk_pf_sim_code (stock_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 이관: name 컬럼은 종목명으로 백필 후 제거, idx_pf_sim_code → uk_pf_sim_code (Pf::migrate)

-- 포트폴리오 메모 이력 — 짧은 한 줄 기록을 시간순으로 쌓는다 (수정 없이 신규/삭제만)
CREATE TABLE IF NOT EXISTS pf_portfolio_memo (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  portfolio_id INT         NOT NULL,
  content      VARCHAR(60) NOT NULL DEFAULT '',
  created_at   DATETIME    NOT NULL,
  KEY idx_pf_pmemo (portfolio_id, created_at),
  CONSTRAINT fk_pf_pmemo FOREIGN KEY (portfolio_id)
    REFERENCES pf_portfolio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 룰셋 헤더
CREATE TABLE IF NOT EXISTS pf_rule_set (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL DEFAULT '',     -- '기본 8% / 7단계'
  volatility DECIMAL(5,2) DEFAULT NULL,            -- 변동율 파라미터 (참고용 메모)
  memo       TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 룰셋 상세 = 차수별 룰
CREATE TABLE IF NOT EXISTS pf_rule_step (
  rule_set_id INT          NOT NULL,
  step_no     TINYINT      NOT NULL,               -- 1..N
  weight      DECIMAL(7,4) NOT NULL DEFAULT 0,     -- 비중       0.0150 = 1.5%
  drop_rate   DECIMAL(7,4) NOT NULL DEFAULT 0,     -- 단계하락률 -0.1000 = -10% (★직전 차수 대비)
  target_rate DECIMAL(7,4) NOT NULL DEFAULT 0,     -- 목표수익률 0.1500 = +15%
  PRIMARY KEY (rule_set_id, step_no),
  CONSTRAINT fk_pf_step_set FOREIGN KEY (rule_set_id)
    REFERENCES pf_rule_set(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 포지션 = 포트폴리오 × 종목
CREATE TABLE IF NOT EXISTS pf_position (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  portfolio_id INT          NOT NULL,
  stock_code   VARCHAR(10)  NOT NULL,
  rule_set_id  INT          NOT NULL,
  limit_amt    BIGINT       NOT NULL DEFAULT 0,    -- 투자한도
  started_at   DATE         DEFAULT NULL,
  status       ENUM('watch','open','closed') NOT NULL DEFAULT 'watch',
  sort_no      INT          NOT NULL DEFAULT 0,
  memo         VARCHAR(200) NOT NULL DEFAULT '',
  UNIQUE KEY uk_pf_pos (portfolio_id, stock_code),
  KEY idx_pf_pos_pf (portfolio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 이관 (초기 버전에서 올라오는 경우) — Pf::ensureTables() 가 자동 수행한다
--   RENAME TABLE pf_account TO pf_portfolio;
--   ALTER TABLE pf_position CHANGE account_id portfolio_id INT NOT NULL;
--   ALTER TABLE pf_portfolio ADD acct_no VARCHAR(40) NOT NULL DEFAULT '' AFTER broker;
--   ALTER TABLE pf_portfolio ADD memo VARCHAR(200) NOT NULL DEFAULT '' AFTER cash;
--   ALTER TABLE pf_portfolio ADD note VARCHAR(200) NOT NULL DEFAULT '' AFTER memo;
--   ALTER TABLE pf_portfolio CHANGE principal principal_seed BIGINT NOT NULL DEFAULT 0;
--   (그 뒤 pf_principal_flow 에 '최초 원금' 한 줄을 심는다 — Pf::seedPrincipalFlow())

-- 체결 기록 (실제 매매)
CREATE TABLE IF NOT EXISTS pf_trade (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  position_id INT           NOT NULL,
  step_no     TINYINT       NOT NULL DEFAULT 0,    -- 매수 차수 (매도는 0)
  side        ENUM('buy','sell') NOT NULL DEFAULT 'buy',
  traded_at   DATE          NOT NULL,
  price       DECIMAL(14,2) NOT NULL DEFAULT 0,    -- 실체결가 (비용 제외)
  qty         INT           NOT NULL DEFAULT 0,
  memo        VARCHAR(200)  NOT NULL DEFAULT '',
  KEY idx_pf_trade_pos (position_id, step_no),
  CONSTRAINT fk_pf_trade_pos FOREIGN KEY (position_id)
    REFERENCES pf_position(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
