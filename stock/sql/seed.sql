-- ══════════════════════════════════════════════════════════════════════
--  초기 룰셋 시드 (참고용 원본)
--
--  실제 삽입은 classes/Pf.class 의 seedRuleSets() 가 pf_rule_set 이 비었을 때만
--  1회 수행한다. 이 파일은 값의 출처를 남기기 위한 기록.
--
--  ★ 출처: 엑셀 별첨 표에서 역산했다 (원본 룰셋 표를 받지 못해서).
--
--  1) '기본 8% / 7단계' — 별첨1 의 누적금액·손익분기율 표에서 역산
--     누적금액(한도 200백만): 3.0 / 8.1 / 13.2 / 26.0 / 62.9 / 118.4 / 201.6
--       → 비중 1.5 / 2.55 / 2.55 / 6.4 / 18.45 / 27.75 / 41.6 %  (합 100.8%)
--     손익분기율에서 단계하락률을 순차 역산 → -10 / -11 / -24 / -27.5 / -32 / -36 %
--     재현 검증: 손익분기율이 원본 "계산" 열과 최대 0.02%p 오차로 일치
--       -3.70 / -8.77 / -15.57 / -16.03 / -22.79 / -29.71 %
--
--  2) '변동율 5% / 7단계' — 별첨3(하이비스)·별첨4(한국가구) 이론가에서 역산
--     단계하락률 -7 / -8 / -21 / -25 / -29 / -33 %
--     재현 검증: 두 종목 전 차수 이론가 오차 0 (stock/tests/calc_test.php)
--
--  ⚠️ 목표수익률(15%→50%)은 원본 표에 차수별 값이 없어 15/20/25/30/35/40/50 으로
--     임시 배치했다. 확인 후 룰셋 관리 화면에서 수정할 것.
-- ══════════════════════════════════════════════════════════════════════

INSERT INTO pf_rule_set (name, volatility, memo) VALUES
  ('기본 8% / 7단계',    8.00, '초기 시드 (별첨1 손익분기율 표 역산)'),
  ('변동율 5% / 7단계',  5.00, '초기 시드 (별첨3·4 이론가 역산, 오차 0 검증)');

-- 1) 기본 8% / 7단계
INSERT INTO pf_rule_step (rule_set_id, step_no, weight, drop_rate, target_rate)
SELECT id, 1, 0.0150,  0.0000, 0.1500 FROM pf_rule_set WHERE name = '기본 8% / 7단계'
UNION ALL SELECT id, 2, 0.0255, -0.1000, 0.2000 FROM pf_rule_set WHERE name = '기본 8% / 7단계'
UNION ALL SELECT id, 3, 0.0255, -0.1100, 0.2500 FROM pf_rule_set WHERE name = '기본 8% / 7단계'
UNION ALL SELECT id, 4, 0.0640, -0.2400, 0.3000 FROM pf_rule_set WHERE name = '기본 8% / 7단계'
UNION ALL SELECT id, 5, 0.1845, -0.2750, 0.3500 FROM pf_rule_set WHERE name = '기본 8% / 7단계'
UNION ALL SELECT id, 6, 0.2775, -0.3200, 0.4000 FROM pf_rule_set WHERE name = '기본 8% / 7단계'
UNION ALL SELECT id, 7, 0.4160, -0.3600, 0.5000 FROM pf_rule_set WHERE name = '기본 8% / 7단계';

-- 2) 변동율 5% / 7단계
INSERT INTO pf_rule_step (rule_set_id, step_no, weight, drop_rate, target_rate)
SELECT id, 1, 0.0150,  0.0000, 0.1500 FROM pf_rule_set WHERE name = '변동율 5% / 7단계'
UNION ALL SELECT id, 2, 0.0255, -0.0700, 0.2000 FROM pf_rule_set WHERE name = '변동율 5% / 7단계'
UNION ALL SELECT id, 3, 0.0255, -0.0800, 0.2500 FROM pf_rule_set WHERE name = '변동율 5% / 7단계'
UNION ALL SELECT id, 4, 0.0640, -0.2100, 0.3000 FROM pf_rule_set WHERE name = '변동율 5% / 7단계'
UNION ALL SELECT id, 5, 0.1845, -0.2500, 0.3500 FROM pf_rule_set WHERE name = '변동율 5% / 7단계'
UNION ALL SELECT id, 6, 0.2770, -0.2900, 0.4000 FROM pf_rule_set WHERE name = '변동율 5% / 7단계'
UNION ALL SELECT id, 7, 0.4160, -0.3300, 0.5000 FROM pf_rule_set WHERE name = '변동율 5% / 7단계';

-- 기본 포트폴리오 1건
INSERT INTO pf_portfolio (name, broker, acct_no, principal, cash) VALUES ('기본 포트폴리오', '', '', 0, 0);
