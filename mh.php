<?php
/**
 * mh.php — 연재 추적기 (독립형, 탭 구조)
 * 라우터+API 단일파일 · PDO prepared · 세션 인증 · 테이블 자동생성(self-healing)
 * 헤더 탭: [연재추적기] (mode=list, 기본) · [스크랩] (mode=scrap)
 *
 * tbl_mh       : no PK, tit, wb 장르0/1/2, url_no, url_dir, ord_no, last_no, latest_no, uDate, kg
 * tbl_mh_scrap : id PK, tit, url, ord_no   ← 스크랩(URL 즐겨찾기) 목록
 */
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

// ── 관리자(usr_level==1) 전용. 세션이 오래돼 usr_level이 없을 수 있어 DB에서 재확인 ──
$stmt = $pdo->prepare("SELECT usr_level FROM tbl_users WHERE usr_name = :n");
$stmt->execute([':n' => $_SESSION['usr_name']]);
$usr_level = (int)$stmt->fetchColumn();
$_SESSION['usr_level'] = $usr_level;
if ($usr_level != 1) {
    echo "<script>alert('관리자만 접근 가능합니다.'); location.href='/etf_stock.php?mode=si';</script>";
    exit;
}
$current_user = $_SESSION['usr_name'];

// ── 장르 설정: 라벨 + 도메인 조립 규칙 + 배지 색 ──
const MH_TYPES = [
    0 => ['label' => '웹툰',      'host' => 'toki', 'tld' => 'com', 'path' => 'webtoon', 'color' => '#3498db'],
    1 => ['label' => '웹소설',    'host' => 'toki', 'tld' => 'com', 'path' => 'novel',   'color' => '#27ae60'],
    2 => ['label' => '일본코믹스', 'host' => 'toki', 'tld' => 'com', 'path' => 'mana',    'color' => '#8e44ad'],
    3 => ['label' => '애니메이션', 'host' => 'toki', 'tld' => 'com', 'path' => 'anime',   'color' => '#e67e22'],
];

/** 테이블 자동 생성 (없으면). 삭제돼도 500 대신 스스로 복구된다. */
function mh_ensure_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tbl_mh` (
          `no`        INT NOT NULL AUTO_INCREMENT,
          `tit`       VARCHAR(255) NOT NULL DEFAULT '',
          `wb`        TINYINT      NOT NULL DEFAULT 0,
          `url_no`    INT          NOT NULL DEFAULT 0,
          `url_dir`   VARCHAR(255) NOT NULL DEFAULT '',
          `ord_no`    INT          NOT NULL DEFAULT 0,
          `last_no`   INT          NOT NULL DEFAULT 0,
          `latest_no` INT          NOT NULL DEFAULT 0,
          `uDate`     DATE         NULL DEFAULT NULL,
          `kg`        TINYINT      NOT NULL DEFAULT 0,
          PRIMARY KEY (`no`),
          KEY `idx_ord` (`ord_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("ALTER TABLE `tbl_mh` ADD COLUMN IF NOT EXISTS `latest_no` INT NOT NULL DEFAULT 0 AFTER `last_no`");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tbl_mh_scrap` (
          `id`     INT NOT NULL AUTO_INCREMENT,
          `grp`    TINYINT       NOT NULL DEFAULT 0,
          `tit`    VARCHAR(255)  NOT NULL DEFAULT '',
          `url`    VARCHAR(1000) NOT NULL DEFAULT '',
          `ord_no` INT           NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          KEY `idx_grp` (`grp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // 기존 스크랩 테이블에도 그룹 컬럼 멱등 추가 (스크랩=0 / 유튜브=1 …)
    $pdo->exec("ALTER TABLE `tbl_mh_scrap` ADD COLUMN IF NOT EXISTS `grp` TINYINT NOT NULL DEFAULT 0 AFTER `id`");
    // 완료(보관) 플래그 멱등 추가 — 체크 시 목록 맨 아래로 정렬
    $pdo->exec("ALTER TABLE `tbl_mh_scrap` ADD COLUMN IF NOT EXISTS `kg` TINYINT NOT NULL DEFAULT 0 AFTER `ord_no`");
}

// ── 스크랩 탭 설정 (그룹별 독립 목록). key = mode 값 ──
const MH_SCRAP_TABS = [
    'scrap'   => ['grp' => 0, 'label' => '🔖 스크랩', 'title' => '스크랩'],
    'youtube' => ['grp' => 1, 'label' => '▶️ 유튜브', 'title' => '유튜브'],
];

/** 작품 1건의 외부 링크 조립: https://{host}{url_no}.{tld}/{path}/{url_dir} */
function mh_url(array $r): string {
    $t = MH_TYPES[$r['wb']] ?? MH_TYPES[0];
    return "https://{$t['host']}{$r['url_no']}.{$t['tld']}/{$t['path']}/" . rawurlencode($r['url_dir']);
}

/** 독립 헤더(탭) 출력 */
function mh_header(string $active, string $user): void {
    $tabs = ['list' => ['📚 연재추적기', 'mh.php']];
    foreach (MH_SCRAP_TABS as $k => $v) $tabs[$k] = [$v['label'], 'mh.php?mode=' . $k];
    echo '<div class="mh-head"><nav class="mh-nav">';
    foreach ($tabs as $k => $v) {
        $cls = $k === $active ? ' class="active"' : '';
        echo '<a href="' . $v[1] . '"' . $cls . '>' . htmlspecialchars($v[0]) . '</a>';
    }
    echo '</nav><div class="right"><span class="who">👤 ' . htmlspecialchars($user) . '</span>'
       . '<a href="/etf_stock.php?mode=si">메인</a><a href="/logout.php">로그아웃</a></div></div>';
}

mh_ensure_table($pdo);

// ════════════════════════════════════════════════════════════
//  북마클릿 수신 (GET) — 사이트 목록 페이지에서 top-level 이동으로 최신화 전달.
//  Cloudflare(403)를 이미 통과한 사용자 브라우저에서만 실행되므로 서버 크롤링 차단을 우회한다.
// ════════════════════════════════════════════════════════════
if (isset($_GET['set_latest'])) {
    $url_dir = trim($_GET['url_dir'] ?? '');
    $latest  = (int)($_GET['latest_no'] ?? 0);   // 본문 최댓값 = 최신화
    $read    = (int)($_GET['read_no'] ?? 0);     // 제목 화수 = 지금 보고 있는(읽은) 화
    $wb      = (int)($_GET['wb'] ?? 0);       if (!isset(MH_TYPES[$wb])) $wb = 0;
    $url_no  = (int)($_GET['url_no'] ?? 0);
    $tit     = trim($_GET['tit'] ?? '');

    // url_dir 로 기존 작품 조회
    $row = null;
    if ($url_dir !== '') {
        $c = $pdo->prepare("SELECT no, tit, latest_no FROM tbl_mh WHERE url_dir=:d LIMIT 1");
        $c->execute([':d' => $url_dir]);
        $row = $c->fetch(PDO::FETCH_ASSOC);
    }

    header('Content-Type: text/html; charset=utf-8');

    if ($row) {
        // 등록된 작품. 북마클릿이 페이지 종류를 판정해 최신화(목록) 또는 읽은화(뷰어) 중 하나만 보낸다.
        // 최신화는 목록 페이지가 authoritative → 그대로 덮어쓴다(재실행 시 항상 정정).
        if ($latest > 0) {
            $st = $pdo->prepare("UPDATE tbl_mh SET latest_no = :l WHERE no=:no");
            $st->execute([':l' => $latest, ':no' => (int)$row['no']]);
        }
        if ($read > 0) {
            $st = $pdo->prepare("UPDATE tbl_mh SET last_no=:r, uDate=CURDATE(), kg=0 WHERE no=:no");
            $st->execute([':r' => $read, ':no' => (int)$row['no']]);
        }
        // 갱신 후 최종 최신화 재조회 (GREATEST 결과 반영)
        $lt = (int)$pdo->query("SELECT latest_no FROM tbl_mh WHERE no=" . (int)$row['no'])->fetchColumn();
        $eTitle = htmlspecialchars($row['tit'] ?: '(제목없음)');
        $lines  = "<p style='font-size:18px'>✓ <b>{$eTitle}</b></p>";
        if ($read > 0) {
            $lines .= "<p>읽은 회차 <b>{$read}화</b>로 기록</p>";
            if ($lt > 0) $lines .= ($read >= $lt)
                ? "<p style='color:#27ae60;font-weight:700'>최신화까지 다 읽었습니다 👍</p>"
                : "<p style='color:#e67e22'>최신 {$lt}화 기준 " . ($lt - $read) . "화 남음</p>";
        } else {
            $lines .= "<p>최신 <b>{$lt}화</b> 기록</p>";
        }
        echo "<!doctype html><meta charset=utf-8><body style='font:16px sans-serif;padding:24px;text-align:center;line-height:1.6'>"
           . $lines
           . "<p style='color:#888;font-size:13px'>이 창은 잠시 후 닫힙니다.</p>"
           . "<script>setTimeout(function(){window.close();},1500);</script></body>";
        exit;
    }

    // 미등록 → 등록 폼 (동일 출처라 세션쿠키로 add 가능)
    $optTags = '';
    foreach (MH_TYPES as $i => $mt) {
        $sel = $i === $wb ? ' selected' : '';
        $optTags .= "<option value='{$i}'{$sel}>" . htmlspecialchars($mt['label']) . "</option>";
    }
    $eTit = htmlspecialchars($tit, ENT_QUOTES);
    $eDir = htmlspecialchars($url_dir, ENT_QUOTES);
    ?>
<!doctype html><html lang=ko><head><meta charset=utf-8><title>새 작품 등록</title>
<style>
body{font-family:'Malgun Gothic',sans-serif;background:#f0f2f5;margin:0;padding:24px}
.box{max-width:420px;margin:0 auto;background:#fff;border-radius:12px;padding:22px;box-shadow:0 2px 10px rgba(0,0,0,.1)}
h3{margin:0 0 4px}.hint{color:#888;font-size:13px;margin-bottom:16px}
label{display:block;font-size:13px;font-weight:600;color:#5b6b78;margin:12px 0 4px}
input,select{width:100%;font:inherit;padding:8px 10px;border:1px solid #cbd5db;border-radius:6px}
.foot{display:flex;gap:8px;margin-top:18px}
button{flex:1;border:none;border-radius:6px;padding:10px;font-weight:700;cursor:pointer}
.ok{background:#3498db;color:#fff}.no{background:#eef2f5;color:#2c3e50}
</style></head><body>
<div class="box">
  <h3>새 작품 등록</h3>
  <div class="hint">추적기에 없는 작품입니다. 아래 정보로 등록할까요?</div>
  <label>제목</label><input id="tit" value="<?= $eTit ?>">
  <label>장르</label><select id="wb"><?= $optTags ?></select>
  <label>경로 (url_dir)</label><input id="url_dir" value="<?= $eDir ?>">
  <label>서버번호 (url_no)</label><input id="url_no" type="number" value="<?= (int)$url_no ?>">
  <label>최신화 (latest_no)</label><input id="latest_no" type="number" value="<?= (int)$latest ?>">
  <label>읽은 회차 (last_no)</label><input id="last_no" type="number" value="<?= (int)$read ?>">
  <div class="foot">
    <button class="no" onclick="window.close()">취소</button>
    <button class="ok" onclick="reg()">등록</button>
  </div>
</div>
<script>
async function reg(){
  const g = id => document.getElementById(id);
  const p = {
    action:'add',
    tit: g('tit').value.trim(),
    wb: parseInt(g('wb').value)||0,
    url_dir: g('url_dir').value.trim(),
    url_no: parseInt(g('url_no').value)||0,
    latest_no: parseInt(g('latest_no').value)||0,
    last_no: parseInt(g('last_no').value)||0,
  };
  if(!p.tit){ alert('제목을 입력하세요'); return; }
  if(!p.url_dir){ alert('경로(url_dir)를 입력하세요'); return; }
  try{
    const r = await fetch('mh.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});
    const j = await r.json().catch(()=>({ok:false,error:'응답 오류'}));
    if(j.ok){ document.body.innerHTML='<p style="font:16px sans-serif;padding:24px;text-align:center">✓ 등록 완료. 창을 닫습니다.</p>'; setTimeout(()=>window.close(),1200); }
    else alert('실패: '+(j.error||''));
  }catch(e){ alert('네트워크 오류: '+e.message); }
}
</script>
</body></html>
    <?php
    exit;
}

// ════════════════════════════════════════════════════════════
//  API (AJAX) — JSON 본문 또는 폼 POST 로 action 전달
// ════════════════════════════════════════════════════════════
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$action = $in['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($action) {

            case 'add': {
                $wb = (int)($in['wb'] ?? 0);
                if (!isset(MH_TYPES[$wb])) $wb = 0;
                $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(ord_no),0) FROM tbl_mh")->fetchColumn();
                $st = $pdo->prepare(
                    "INSERT INTO tbl_mh (tit, wb, url_no, url_dir, ord_no, last_no, latest_no, uDate, kg)
                     VALUES (:tit, :wb, :url_no, :url_dir, :ord, :last, :latest, CURDATE(), 0)");
                $st->execute([
                    ':tit'     => trim($in['tit'] ?? ''),
                    ':wb'      => $wb,
                    ':url_no'  => (int)($in['url_no'] ?? 0),
                    ':url_dir' => trim($in['url_dir'] ?? ''),
                    ':ord'     => $maxOrd + 1,
                    ':last'    => (int)($in['last_no'] ?? 0),
                    ':latest'  => (int)($in['latest_no'] ?? 0),
                ]);
                break;
            }

            case 'update_meta': {
                $wb = (int)($in['wb'] ?? 0);
                if (!isset(MH_TYPES[$wb])) $wb = 0;
                $st = $pdo->prepare(
                    "UPDATE tbl_mh SET tit=:tit, wb=:wb, url_no=:url_no, url_dir=:url_dir WHERE no=:no");
                $st->execute([
                    ':tit'     => trim($in['tit'] ?? ''),
                    ':wb'      => $wb,
                    ':url_no'  => (int)($in['url_no'] ?? 0),
                    ':url_dir' => trim($in['url_dir'] ?? ''),
                    ':no'      => (int)($in['no'] ?? 0),
                ]);
                break;
            }

            case 'update_chapter': {
                $st = $pdo->prepare("UPDATE tbl_mh SET last_no=:c, uDate=CURDATE(), kg=0 WHERE no=:no");
                $st->execute([':c' => (int)($in['last_no'] ?? 0), ':no' => (int)($in['no'] ?? 0)]);
                break;
            }

            case 'set_latest_manual': {   // 최신화 수동 입력 (배지 클릭)
                $st = $pdo->prepare("UPDATE tbl_mh SET latest_no=:l WHERE no=:no");
                $st->execute([':l' => (int)($in['latest_no'] ?? 0), ':no' => (int)($in['no'] ?? 0)]);
                break;
            }

            case 'toggle_kg': {
                $st = $pdo->prepare("UPDATE tbl_mh SET kg = 1 - kg WHERE no=:no");
                $st->execute([':no' => (int)($in['no'] ?? 0)]);
                break;
            }

            case 'delete': {
                $st = $pdo->prepare("DELETE FROM tbl_mh WHERE no=:no");
                $st->execute([':no' => (int)($in['no'] ?? 0)]);
                break;
            }

            case 'move': {   // 인접 행과 ord_no 교환 (up/down)
                $no  = (int)($in['no'] ?? 0);
                $dir = ($in['dir'] ?? '') === 'up' ? 'up' : 'down';
                $cur = $pdo->prepare("SELECT no, ord_no FROM tbl_mh WHERE no=:no");
                $cur->execute([':no' => $no]);
                $c = $cur->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    $q = $dir === 'up'
                        ? "SELECT no, ord_no FROM tbl_mh WHERE ord_no < :o ORDER BY ord_no DESC LIMIT 1"
                        : "SELECT no, ord_no FROM tbl_mh WHERE ord_no > :o ORDER BY ord_no ASC LIMIT 1";
                    $ns = $pdo->prepare($q);
                    $ns->execute([':o' => $c['ord_no']]);
                    $n = $ns->fetch(PDO::FETCH_ASSOC);
                    if ($n) {
                        $u = $pdo->prepare("UPDATE tbl_mh SET ord_no=:o WHERE no=:no");
                        $u->execute([':o' => $n['ord_no'], ':no' => $c['no']]);
                        $u->execute([':o' => $c['ord_no'], ':no' => $n['no']]);
                    }
                }
                break;
            }

            case 'set_urlno_all': {   // 서버번호 일괄 변경 (사이트 도메인 로테이션 대응)
                $st = $pdo->prepare("UPDATE tbl_mh SET url_no=:u");
                $st->execute([':u' => (int)($in['url_no'] ?? 0)]);
                break;
            }

            case 'reset_dates': {     // 전체 마지막조회일을 오늘로
                $pdo->exec("UPDATE tbl_mh SET uDate=CURDATE()");
                break;
            }

            // ── 스크랩(URL 즐겨찾기) ──
            case 'scrap_add': {
                $url = trim($in['url'] ?? '');
                if ($url === '') throw new RuntimeException('URL을 입력하세요');
                if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;   // 스킴 보정
                $tit = trim($in['tit'] ?? '');
                if ($tit === '') $tit = $url;
                $grp = (int)($in['grp'] ?? 0);
                $mx = $pdo->prepare("SELECT COALESCE(MAX(ord_no),0) FROM tbl_mh_scrap WHERE grp=:g");
                $mx->execute([':g' => $grp]);
                $maxOrd = (int)$mx->fetchColumn();
                $st = $pdo->prepare("INSERT INTO tbl_mh_scrap (grp, tit, url, ord_no) VALUES (:g, :t, :u, :o)");
                $st->execute([':g' => $grp, ':t' => $tit, ':u' => $url, ':o' => $maxOrd + 1]);
                break;
            }

            case 'scrap_del': {
                $st = $pdo->prepare("DELETE FROM tbl_mh_scrap WHERE id=:id");
                $st->execute([':id' => (int)($in['id'] ?? 0)]);
                break;
            }

            case 'scrap_toggle_kg': {   // 완료 토글 → 목록 맨 아래로
                $st = $pdo->prepare("UPDATE tbl_mh_scrap SET kg = 1 - kg WHERE id=:id");
                $st->execute([':id' => (int)($in['id'] ?? 0)]);
                break;
            }

            // ── 드래그 순서 저장: 넘어온 id 순서대로 ord_no 재부여 ──
            case 'reorder': {          // 연재추적기 (tbl_mh)
                $ids = $in['ids'] ?? [];
                if (is_array($ids)) {
                    $u = $pdo->prepare("UPDATE tbl_mh SET ord_no=:o WHERE no=:no");
                    $i = 1;
                    foreach ($ids as $id) { $u->execute([':o' => $i++, ':no' => (int)$id]); }
                }
                break;
            }
            case 'scrap_reorder': {    // 스크랩/유튜브 (tbl_mh_scrap)
                $ids = $in['ids'] ?? [];
                if (is_array($ids)) {
                    $u = $pdo->prepare("UPDATE tbl_mh_scrap SET ord_no=:o WHERE id=:id");
                    $i = 1;
                    foreach ($ids as $id) { $u->execute([':o' => $i++, ':id' => (int)$id]); }
                }
                break;
            }

            default:
                throw new RuntimeException('알 수 없는 요청: ' . $action);
        }
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
//  렌더링 — 탭 라우팅
// ════════════════════════════════════════════════════════════
$mode = $_GET['mode'] ?? '';
if ($mode !== 'list' && !isset(MH_SCRAP_TABS[$mode])) $mode = 'list';
$scrapCfg = MH_SCRAP_TABS[$mode] ?? null;   // 스크랩 계열이면 설정, 아니면 null

// 북마클릿(연재추적기 탭에서 안내)
$bookmarklet = <<<'BM'
javascript:(function(){var segs=location.pathname.split('/').filter(Boolean);if(segs.length<2||!/^\d+$/.test(segs[1])){alert('작품 페이지에서 실행하세요');return;}var seg=segs[0],d=segs[1];var wb=(seg==='novel')?1:((seg==='mana'||seg==='comic')?2:((seg==='anime')?3:0));var hn=(location.hostname.match(/(\d+)/)||[])[1]||'';var tit=(document.title||'').replace(/\s*[-|:｜].*$/,'').trim();var base='https://economist.kr/mh.php?set_latest=1&url_dir='+d+'&wb='+wb+'&url_no='+hn+'&tit='+encodeURIComponent(tit);var x;if(segs.length>=3){var last=segs[segs.length-1],nums=last.match(/\d+/g)||[],cur=nums.length?parseInt(nums[nums.length-1],10):0;if(!cur){var rt=/(\d{1,4})[화회]/g,tt=document.title||'';while((x=rt.exec(tt))!==null){cur=parseInt(x[1],10);}}if(!cur){alert('현재 화수를 찾지 못했습니다');return;}window.open(base+'&read_no='+cur,'_blank');}else{var t=document.body.innerText,rb=/(\d{1,4})[화회]/g,mx=0;while((x=rb.exec(t))!==null){var v=parseInt(x[1],10);if(v>mx)mx=v;}if(!mx){alert('최신 화수를 찾지 못했습니다');return;}window.open(base+'&latest_no='+mx,'_blank');}})();
BM;

if ($mode === 'list') {
    $sort = ($_GET['sort'] ?? 'ord') === 'stale' ? 'stale' : 'ord';
    $rows = $pdo->query("SELECT * FROM tbl_mh ORDER BY kg ASC, ord_no ASC")->fetchAll(PDO::FETCH_ASSOC);
    $today = new DateTimeImmutable('today');
    foreach ($rows as &$r) {
        if (!empty($r['kg'])) {
            $r['_days'] = -1;
        } elseif (!empty($r['uDate']) && $r['uDate'] !== '0000-00-00') {
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $r['uDate']);
            $r['_days'] = $d ? (int)$today->diff($d)->days : 0;
        } else {
            $r['_days'] = 0;
        }
    }
    unset($r);
    if ($sort === 'stale') usort($rows, fn($a, $b) => $b['_days'] <=> $a['_days']);
    $curUrlNo    = $rows ? (int)$rows[0]['url_no'] : 1;
    $statOverdue = count(array_filter($rows, fn($r) => $r['_days'] >= 7));
    $statBehind  = count(array_filter($rows, fn($r) => (int)($r['latest_no'] ?? 0) > (int)$r['last_no']));
} else {
    $st = $pdo->prepare("SELECT * FROM tbl_mh_scrap WHERE grp=:g ORDER BY kg ASC, ord_no, id");
    $st->execute([':g' => $scrapCfg['grp']]);
    $scraps = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($scrapCfg ? $scrapCfg['title'] : '연재추적기') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- 외부 사이트 anti-hotlink(Referer 기반 차단) 우회: 나갈 때 Referer 를 보내지 않음 -->
<meta name="referrer" content="no-referrer">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Pretendard','Malgun Gothic',sans-serif;background:#f0f2f5;color:#2c3e50}
a{color:inherit;text-decoration:none}

/* ── 독립 헤더(탭) ── */
.mh-head{position:sticky;top:0;z-index:1500;background:#2c3e50;color:#fff;display:flex;align-items:center;gap:12px;padding:0 16px;min-height:52px;box-shadow:0 2px 8px rgba(0,0,0,.15)}
.mh-nav{display:flex;gap:4px}
.mh-nav a{color:#ecf0f1;padding:14px 16px;font-weight:700;font-size:16px;border-bottom:3px solid transparent}
.mh-nav a:hover{color:#f1c40f}
.mh-nav a.active{color:#f1c40f;border-bottom-color:#f1c40f}
.mh-head .right{margin-left:auto;display:flex;gap:12px;align-items:center;white-space:nowrap}
.mh-head .right .who{font-size:13px;color:#bdc3c7}
.mh-head .right a{font-size:13px;color:#bdc3c7}
.mh-head .right a:hover{color:#fff}

.wrap{max-width:960px;margin:0 auto;padding:16px}
.bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:14px 0}
.bar h1{font-size:22px;margin-right:auto}
.btn{border:none;cursor:pointer;border-radius:6px;font-size:14px;font-weight:600;padding:8px 14px;transition:.15s}
.btn-primary{background:#3498db;color:#fff}.btn-primary:hover{background:#2980b9}
.btn-outline{background:#fff;border:1px solid #cbd5db;color:#2c3e50}.btn-outline:hover{background:#eef2f5}
.btn-sm{padding:5px 9px;font-size:13px}
.panel{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:12px 14px;margin-bottom:14px}
.panel .row{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.panel label{font-size:13px;color:#5b6b78;font-weight:600}
.panel input[type=number]{width:80px}
input,select{font:inherit;padding:7px 9px;border:1px solid #cbd5db;border-radius:6px;background:#fff}
input:focus,select:focus{outline:2px solid #3498db55;border-color:#3498db}
.stat{font-size:13px;color:#5b6b78}
.stat b{color:#e67e22}
details.help{margin-bottom:14px;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:6px 14px}
details.help summary{cursor:pointer;font-weight:600;font-size:14px;padding:8px 0;color:#2c3e50}
details.help .body{font-size:13px;color:#5b6b78;line-height:1.7;padding:4px 0 12px}
details.help code{background:#eef2f5;padding:1px 5px;border-radius:4px;font-size:12px}
.bm-link{display:inline-block;background:#8e44ad;color:#fff;padding:7px 14px;border-radius:6px;font-weight:700;margin:4px 0;cursor:grab}

/* 리스트 공통 */
.list{display:flex;flex-direction:column;gap:8px}
.card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);display:flex;align-items:center;gap:12px;padding:12px 14px}
.card.overdue{border-left:4px solid #e67e22}
.card.kg{opacity:.7;border-left:4px solid #95a5a6}
.card.dragging{opacity:.4;outline:2px dashed #3498db}
.card.dragover-hint{box-shadow:0 -2px 0 #3498db}
.drag{flex-shrink:0;cursor:grab;color:#c5ccd2;font-size:16px;line-height:1;user-select:none;padding:0 2px}
.drag:active{cursor:grabbing}
.card[draggable=true]:hover .drag{color:#8e99a3}
.ord{display:flex;flex-direction:column;gap:2px;color:#95a5a6}
.ord button{border:none;background:#eef2f5;border-radius:4px;cursor:pointer;font-size:11px;line-height:1;padding:3px 5px}
.ord button:hover{background:#dfe6ea}
.badge{flex-shrink:0;font-size:12px;font-weight:700;color:#fff;padding:3px 8px;border-radius:20px}
.main{flex:1;min-width:0}
.main .tit{font-size:16px;font-weight:700;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}
.main .tit:hover{color:#2980b9;text-decoration:underline}
.main .sub{font-size:12px;color:#95a5a6;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chap{flex-shrink:0;text-align:center;cursor:pointer;min-width:58px;border-radius:8px;padding:6px 8px;background:#f4f7f9}
.chap:hover{background:#e9eff3}
.chap .n{font-size:19px;font-weight:800;color:#2c3e50;line-height:1}
.chap .l{font-size:11px;color:#95a5a6}
.behind{flex-shrink:0;min-width:64px;text-align:center;cursor:pointer;border-radius:8px;padding:6px 4px}
.behind:hover{background:#f4f7f9}
.behind .latest{font-size:11px;color:#95a5a6;display:block;line-height:1.3}
.behind .gap{font-weight:800;font-size:14px}
.behind .gap.done{color:#27ae60}.behind .gap.wait{color:#e67e22}.behind .gap.none{color:#bdc3c7;font-weight:600}
.days{flex-shrink:0;min-width:48px;text-align:center;font-size:13px;font-weight:700}
.days.warn{color:#e67e22}.days.old{color:#e74c3c}.days.kg{color:#95a5a6;font-size:12px}
.acts{flex-shrink:0;display:flex;gap:5px;align-items:center}
.scrap-state{display:none;font-size:12px;font-weight:800;color:#27ae60;margin-right:4px;white-space:nowrap}
.scrap-state.on{display:inline}
.icon{border:none;background:#f4f7f9;border-radius:6px;cursor:pointer;padding:6px 8px;font-size:14px}
.icon:hover{background:#e9eff3}
.empty{text-align:center;color:#95a5a6;padding:40px}

/* 모달 */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;align-items:flex-start;justify-content:center;padding:60px 16px}
.modal-bg.on{display:flex}
.modal{background:#fff;border-radius:12px;width:100%;max-width:420px;padding:20px}
.modal h2{font-size:18px;margin-bottom:14px}
.modal .fld{margin-bottom:12px}
.modal .fld label{display:block;font-size:13px;font-weight:600;color:#5b6b78;margin-bottom:5px}
.modal .fld input,.modal .fld select{width:100%}
.modal .genres{display:flex;gap:8px}
.modal .genres label{display:flex;align-items:center;gap:5px;font-weight:600;cursor:pointer;font-size:14px}
.modal .foot{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}

/* 숫자판(키패드) 모달 */
.kp{max-width:320px}
.kp .ctx{text-align:center;font-size:13px;color:#95a5a6;margin:-6px 0 10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kp .disp{font-size:42px;font-weight:800;text-align:center;padding:12px;background:#f4f7f9;border-radius:10px;margin-bottom:10px;letter-spacing:1px;min-height:70px;line-height:1}
.kp .disp .u{font-size:20px;color:#95a5a6;font-weight:700;margin-left:2px}
.kp .steps{display:flex;gap:8px;margin-bottom:10px}
.kp .steps button{flex:1;font-size:16px;font-weight:700;padding:11px 0;border:1px solid #e1e7eb;border-radius:9px;background:#fff;cursor:pointer;transition:.1s}
.kp .steps button:hover{background:#eef2f5}.kp .steps button:active{transform:scale(.97)}
.kp .steps button.latest{color:#e67e22;border-color:#f0c9a3}
.kp .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.kp .grid button{font-size:23px;font-weight:700;padding:15px 0;border:1px solid #e1e7eb;border-radius:10px;background:#fff;cursor:pointer;transition:.1s}
.kp .grid button:hover{background:#eef2f5}.kp .grid button:active{transform:scale(.96);background:#e3ebf0}
.kp .grid button.util{background:#f4f7f9;font-size:19px;color:#5b6b78}

@media (max-width:640px){
  .card{flex-wrap:wrap;gap:8px}
  .main{flex-basis:100%;order:-1}
  .days{min-width:40px}
}
</style>
</head>
<body>

<?php mh_header($mode, $current_user); ?>

<?php if ($scrapCfg): ?>
<!-- ════════════ 스크랩 페이지 (그룹별) ════════════ -->
<div class="wrap">
  <div class="bar">
    <h1><?= htmlspecialchars($scrapCfg['label']) ?></h1>
    <span class="stat" style="margin-right:auto">저장 <b style="color:#2c3e50"><?= count($scraps) ?></b>개 · 제목 클릭 시 팝업으로 열립니다</span>
    <span class="stat" id="openCount"></span>
    <button class="btn btn-outline btn-sm" onclick="closeAllScraps()">열린창 전체 닫기</button>
  </div>

  <div class="panel">
    <div class="row">
      <input type="text" id="s_tit" placeholder="제목 (비우면 URL 사용)" style="width:200px">
      <input type="text" id="s_url" placeholder="https://... URL 붙여넣기" style="flex:1;min-width:200px">
      <button class="btn btn-primary" onclick="addScrapForm()">＋ 저장</button>
    </div>
  </div>

  <?php if (!$scraps): ?>
    <div class="empty">저장된 스크랩이 없습니다. 위에 제목과 URL을 넣고 <b>저장</b>하세요.</div>
  <?php else: ?>
  <div class="list" id="scrapList">
    <?php foreach ($scraps as $s): ?>
    <div class="card <?= !empty($s['kg']) ? 'kg' : '' ?>" draggable="true" data-id="<?= (int)$s['id'] ?>">
      <span class="drag" title="드래그해서 순서변경">⠿</span>
      <div class="main">
        <span class="tit" onclick="openScrap(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['url'], ENT_QUOTES) ?>')"><?= htmlspecialchars($s['tit']) ?> ↗</span>
        <div class="sub"><?= htmlspecialchars($s['url']) ?></div>
      </div>
      <div class="acts">
        <span class="scrap-state" id="st_<?= (int)$s['id'] ?>">● 열림</span>
        <button class="btn btn-outline btn-sm" onclick="openScrap(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['url'], ENT_QUOTES) ?>')">열기</button>
        <button class="icon" title="열린 팝업 닫기" onclick="closeScrap(<?= (int)$s['id'] ?>)">✖</button>
        <button class="icon" title="<?= !empty($s['kg']) ? '보관 해제' : '완결/보관' ?>" onclick="scrapToggleKg(<?= (int)$s['id'] ?>)"><?= !empty($s['kg']) ? '📌' : '📎' ?></button>
        <button class="icon" title="삭제" onclick="delScrap(<?= (int)$s['id'] ?>,'<?= htmlspecialchars(addslashes($s['tit']), ENT_QUOTES) ?>')">🗑️</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ════════════ 연재추적기 페이지 ════════════ -->
<div class="wrap">
  <div class="bar">
    <h1>연재 목록</h1>
    <span class="stat">총 <b style="color:#2c3e50"><?= count($rows) ?></b>편 · 밀림 <b><?= $statBehind ?></b>편 · 7일↑ <b><?= $statOverdue ?></b>편</span>
    <a class="btn btn-outline btn-sm" href="?sort=<?= $sort === 'stale' ? 'ord' : 'stale' ?>">
      정렬: <?= $sort === 'stale' ? '경과일순' : '순서대로' ?></a>
    <button class="btn btn-primary" onclick="openModal()">+ 새 작품</button>
  </div>

  <div class="panel">
    <div class="row">
      <label>서버번호(도메인) 일괄변경</label>
      <input type="number" id="urlNoAll" value="<?= $curUrlNo ?>">
      <button class="btn btn-outline btn-sm" onclick="setUrlNoAll()">적용</button>
      <span class="stat" style="margin-left:auto">사이트 도메인이 바뀌면 여기서 번호만 올리면 모든 링크가 갱신됩니다</span>
      <button class="btn btn-outline btn-sm" onclick="resetDates()">전체 오늘로 초기화</button>
    </div>
  </div>

  <details class="help">
    <summary>🔖 최신화·읽은 회차 북마클릿 — 사용법</summary>
    <div class="body">
      사이트는 Cloudflare로 서버 크롤링(외부 요청)을 막습니다. 그래서 <b>이미 그 사이트가 열리는 회원님 브라우저</b>에서
      화수를 읽어오는 방식이 가장 확실합니다. 버튼 하나로 <b>지금 있는 페이지에 맞춰</b> 최신화 또는 읽은 회차를 기록합니다.
      <ol style="margin:8px 0 8px 18px">
        <li>아래 보라색 버튼을 <b>브라우저 즐겨찾기(북마크바)로 드래그</b>해서 등록하세요.</li>
        <li><b>회차 목록 페이지</b>(예: <code>toki30.com/webtoon/807393</code>)에서 누르면 → <b>최신화</b>만 기록됩니다.</li>
        <li><b>한 화를 보는 중</b>(예: <code>…/807393/nv-807393-73</code>)에 누르면 → 그 화(73)가 <b>읽은 회차</b>로 기록됩니다. (최신화는 안 건드림)</li>
      </ol>
      <a class="bm-link" href="<?= htmlspecialchars($bookmarklet, ENT_QUOTES) ?>" onclick="alert('클릭이 아니라, 이 버튼을 브라우저 북마크바로 드래그해서 등록하세요.');return false;">📌 화수 가져오기 (mh)</a>
      <div style="color:#95a5a6">
        ※ 작품은 경로(url_dir)로 매칭됩니다. 기록 후 뜨는 창에 <b>작품 제목·화수</b>가 표시되니 맞게 저장됐는지 바로 확인할 수 있어요.
        최신화는 <b>목록 페이지</b>가 기준이라 목록에서 다시 누르면 정확한 값으로 갱신됩니다. <b>없는 작품이면 등록 폼</b>이 떠서 바로 추가할 수 있습니다.
      </div>
    </div>
  </details>

  <?php if (!$rows): ?>
    <div class="empty">등록된 작품이 없습니다. <b>+ 새 작품</b>으로 추가하세요.</div>
  <?php else: ?>
  <div class="list" id="mhList">
    <?php foreach ($rows as $r):
        $t = MH_TYPES[$r['wb']] ?? MH_TYPES[0];
        $days = $r['_days'];
        $latest = (int)($r['latest_no'] ?? 0);
        $gap = $latest - (int)$r['last_no'];
        if ($latest <= 0)  { $gapCls = 'none'; $gapTxt = '?'; }
        elseif ($gap <= 0) { $gapCls = 'done'; $gapTxt = '최신'; }
        else               { $gapCls = 'wait'; $gapTxt = '+' . $gap; }
        $cardCls = !empty($r['kg']) ? 'kg' : ($days >= 7 ? 'overdue' : '');
        $dayCls = !empty($r['kg']) ? 'kg' : ($days >= 14 ? 'old' : ($days >= 7 ? 'warn' : ''));
        $rj = htmlspecialchars(json_encode([
            'no' => (int)$r['no'], 'tit' => $r['tit'], 'wb' => (int)$r['wb'],
            'url_no' => (int)$r['url_no'], 'url_dir' => $r['url_dir'],
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    ?>
    <div class="card <?= $cardCls ?>" draggable="true" data-id="<?= (int)$r['no'] ?>">
      <span class="drag" title="드래그해서 순서변경">⠿</span>
      <span class="badge" style="background:<?= $t['color'] ?>"><?= htmlspecialchars($t['label']) ?></span>
      <div class="main">
        <?php if ((int)$r['wb'] === 3): // 애니메이션 → 팝업창 ?>
        <span class="tit" style="cursor:pointer" onclick="openPopup('<?= htmlspecialchars(mh_url($r), ENT_QUOTES) ?>')">
          <?= htmlspecialchars($r['tit']) ?: '(제목없음)' ?> ⧉</span>
        <?php else: // 그 외 → 새 탭 ?>
        <a class="tit" draggable="false" href="<?= htmlspecialchars(mh_url($r)) ?>" target="_blank" rel="noopener">
          <?= htmlspecialchars($r['tit']) ?: '(제목없음)' ?> ↗</a>
        <?php endif; ?>
        <div class="sub"><?= htmlspecialchars($r['url_dir']) ?> · 서버<?= (int)$r['url_no'] ?></div>
      </div>
      <div class="chap" onclick="readChap(<?= (int)$r['no'] ?>,<?= (int)$r['last_no'] ?>,'<?= htmlspecialchars(addslashes($r['tit']), ENT_QUOTES) ?>',<?= $latest ?>)" title="읽은 화수 갱신">
        <div class="n"><?= (int)$r['last_no'] ?></div><div class="l">읽음</div>
      </div>
      <div class="behind" onclick="setLatest(<?= (int)$r['no'] ?>,<?= $latest ?>)" title="최신화 수동 입력">
        <span class="latest"><?= $latest > 0 ? $latest . '화' : '최신?' ?></span>
        <span class="gap <?= $gapCls ?>"><?= $gapTxt ?></span>
      </div>
      <div class="days <?= $dayCls ?>">
        <?php if (!empty($r['kg'])): ?>보관<?php elseif ($days === 0): ?>오늘<?php else: ?><?= $days ?>일<?php endif; ?>
      </div>
      <div class="acts">
        <button class="icon" title="<?= !empty($r['kg']) ? '보관 해제' : '완결/보관' ?>" onclick="toggleKg(<?= (int)$r['no'] ?>)"><?= !empty($r['kg']) ? '📌' : '📎' ?></button>
        <button class="icon" title="수정" onclick='openModal(<?= $rj ?>)'>✏️</button>
        <button class="icon" title="삭제" onclick="del(<?= (int)$r['no'] ?>,'<?= htmlspecialchars(addslashes($r['tit']), ENT_QUOTES) ?>')">🗑️</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- 추가/수정 모달 -->
<div class="modal-bg" id="modalBg" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h2 id="modalTitle">새 작품 추가</h2>
    <input type="hidden" id="f_no">
    <div class="fld">
      <label>장르</label>
      <div class="genres">
        <?php foreach (MH_TYPES as $i => $t): ?>
          <label><input type="radio" name="f_wb" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>><?= htmlspecialchars($t['label']) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="fld"><label>타이틀</label><input type="text" id="f_tit" placeholder="작품 제목"></div>
    <div class="fld"><label>경로 (url_dir)</label><input type="text" id="f_url_dir" placeholder="예: 776255"></div>
    <div class="fld"><label>서버번호 (url_no)</label><input type="number" id="f_url_no" value="<?= $curUrlNo ?>"></div>
    <div class="foot">
      <button class="btn btn-outline" onclick="closeModal()">취소</button>
      <button class="btn btn-primary" onclick="saveModal()">저장</button>
    </div>
  </div>
</div>

<!-- 읽은 화수 숫자판(키패드) 모달 -->
<div class="modal-bg" id="kpBg" onclick="if(event.target===this)closeKp()">
  <div class="modal kp">
    <h2>읽은 화수 입력</h2>
    <div class="ctx" id="kpCtx"></div>
    <div class="disp"><span id="kpNum">0</span><span class="u">화</span></div>
    <div class="steps">
      <button onclick="kpStep(-1)">－1</button>
      <button onclick="kpStep(1)">＋1</button>
      <button class="latest" id="kpLatestBtn" onclick="kpLatest()">최신으로</button>
    </div>
    <div class="grid">
      <button onclick="kpDigit('1')">1</button>
      <button onclick="kpDigit('2')">2</button>
      <button onclick="kpDigit('3')">3</button>
      <button onclick="kpDigit('4')">4</button>
      <button onclick="kpDigit('5')">5</button>
      <button onclick="kpDigit('6')">6</button>
      <button onclick="kpDigit('7')">7</button>
      <button onclick="kpDigit('8')">8</button>
      <button onclick="kpDigit('9')">9</button>
      <button class="util" onclick="kpClear()">C</button>
      <button onclick="kpDigit('0')">0</button>
      <button class="util" onclick="kpBack()">←</button>
    </div>
    <div class="foot">
      <button class="btn btn-outline" onclick="closeKp()">취소</button>
      <button class="btn btn-primary" onclick="kpSave()">저장</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
async function api(payload){
  try{
    const r = await fetch('mh.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const j = await r.json().catch(()=>({ok:false,error:'응답 파싱 실패'}));
    if(!j.ok){ alert('오류: '+(j.error||'실패')); return false; }
    return true;
  }catch(e){ alert('네트워크 오류: '+e.message); return false; }
}
const reloadIf = async p => { if(await api(p)) location.reload(); };

/* ── 팝업창 (좌상단부터 안 겹치는 격자 배치) ── */
const SCRAP_GRP = <?= (int)($scrapCfg['grp'] ?? 0) ?>;   // 현재 스크랩 탭 그룹
let _scrapSeq = 0;
const openWins = {};   // 스크랩 id -> 우리가 연 팝업 window 핸들 배열
function popupTiled(url, prefix){
  const name = (prefix || 'mhpop') + '_' + (Date.now().toString(36)) + '_' + (_scrapSeq);
  const W = 880, H = 704;
  const baseL = screen.availLeft || 0, baseT = screen.availTop || 0;
  const cols = Math.max(1, Math.floor((screen.availWidth  || W) / W));
  const rows = Math.max(1, Math.floor((screen.availHeight || H) / H));
  const slot = _scrapSeq % (cols * rows);        // 화면이 꽉 차면 처음 자리부터 재사용
  const left = baseL + (slot % cols) * W;
  const top  = baseT + Math.floor(slot / cols) * H;
  _scrapSeq++;
  return window.open(url, name,
    'width=' + W + ',height=' + H + ',left=' + left + ',top=' + top + ',scrollbars=yes,resizable=yes');
}
function openPopup(url){ popupTiled(url, 'mhread'); }   // 연재추적기 애니메이션 등 팝업 열기
function openScrap(id, url){
  const w = popupTiled(url, 'mhscrap');
  if (w) { (openWins[id] = openWins[id] || []).push(w); }
  refreshScrapStates();
}
function closeScrap(id){
  (openWins[id] || []).forEach(w => { try { if (w && !w.closed) w.close(); } catch(e){} });
  openWins[id] = [];
  refreshScrapStates();
}
function closeAllScraps(){
  let n = 0;
  Object.keys(openWins).forEach(id => {
    (openWins[id] || []).forEach(w => { try { if (w && !w.closed) { w.close(); n++; } } catch(e){} });
    openWins[id] = [];
  });
  refreshScrapStates();
  if (!n) alert('닫을 열린 팝업이 없습니다. (이 페이지에서 연 창만 닫을 수 있어요)');
}
function refreshScrapStates(){
  let total = 0;
  document.querySelectorAll('.scrap-state').forEach(el => {
    const id = el.id.slice(3);
    const arr = (openWins[id] || []).filter(w => w && !w.closed);
    openWins[id] = arr;
    total += arr.length;
    if (arr.length) { el.classList.add('on'); el.textContent = arr.length > 1 ? ('● 열림 ' + arr.length) : '● 열림'; }
    else el.classList.remove('on');
  });
  const oc = document.getElementById('openCount');
  if (oc) oc.textContent = total ? ('열린 팝업 ' + total + '개') : '';
}
// 사용자가 팝업을 직접 닫아도 반영되도록 주기적으로 점검
setInterval(refreshScrapStates, 1500);
async function addScrapForm(){
  const tit = document.getElementById('s_tit').value.trim();
  const url = document.getElementById('s_url').value.trim();
  if(!url){ alert('URL을 입력하세요'); return; }
  reloadIf({action:'scrap_add', url, tit, grp:SCRAP_GRP});
}
function delScrap(id,tit){
  if(!confirm('스크랩 “'+tit+'” 삭제할까요?')) return;
  reloadIf({action:'scrap_del', id});
}
function scrapToggleKg(id){ reloadIf({action:'scrap_toggle_kg', id}); }

/* ── 연재 목록 ── */
function move(no,dir){ reloadIf({action:'move',no,dir}); }
function toggleKg(no){ reloadIf({action:'toggle_kg',no}); }
/* ── 읽은 화수 숫자판(키패드) ── */
let _kp = { no:0, val:0, latest:0, touched:false };
function readChap(no,cur,tit,latest){
  _kp = { no, val:parseInt(cur)||0, latest:parseInt(latest)||0, touched:false };
  document.getElementById('kpCtx').textContent =
    (tit||'') + (_kp.latest>0 ? ' · 최신 '+_kp.latest+'화' : '');
  document.getElementById('kpLatestBtn').style.display = _kp.latest>0 ? '' : 'none';
  kpRender();
  document.getElementById('kpBg').classList.add('on');
}
function kpRender(){ document.getElementById('kpNum').textContent = _kp.val; }
function kpDigit(d){
  if(!_kp.touched){ _kp.val = 0; _kp.touched = true; }   // 첫 입력은 기존값 덮어쓰기
  _kp.val = Math.min(9999, _kp.val*10 + parseInt(d));
  kpRender();
}
function kpBack(){ _kp.touched=true; _kp.val = Math.floor(_kp.val/10); kpRender(); }
function kpClear(){ _kp.touched=true; _kp.val = 0; kpRender(); }
function kpStep(n){ _kp.touched=true; _kp.val = Math.max(0, _kp.val + n); kpRender(); }
function kpLatest(){ if(_kp.latest>0){ _kp.touched=true; _kp.val=_kp.latest; kpRender(); } }
function closeKp(){ document.getElementById('kpBg').classList.remove('on'); }
function kpSave(){ closeKp(); reloadIf({action:'update_chapter',no:_kp.no,last_no:_kp.val}); }
function setLatest(no,cur){
  const v = prompt('최신 화수 (사이트에서 확인한 최근 화)', cur||'');
  if(v===null) return;
  reloadIf({action:'set_latest_manual',no,latest_no:parseInt(v)||0});
}
function del(no,tit){
  if(!confirm('“'+tit+'” 을(를) 삭제할까요?')) return;
  reloadIf({action:'delete',no});
}
function setUrlNoAll(){
  const v = document.getElementById('urlNoAll').value;
  if(!confirm('모든 작품의 서버번호를 '+v+' 로 변경할까요?')) return;
  reloadIf({action:'set_urlno_all',url_no:parseInt(v)||0});
}
function resetDates(){
  if(!confirm('모든 작품의 마지막 조회일을 오늘로 초기화할까요?')) return;
  reloadIf({action:'reset_dates'});
}

/* 모달(연재추적기 전용) */
function openModal(row){
  document.getElementById('modalTitle').textContent = row ? '작품 수정' : '새 작품 추가';
  document.getElementById('f_no').value       = row ? row.no : '';
  document.getElementById('f_tit').value      = row ? row.tit : '';
  document.getElementById('f_url_dir').value  = row ? row.url_dir : '';
  document.getElementById('f_url_no').value   = row ? row.url_no : document.getElementById('urlNoAll').value;
  const wb = row ? row.wb : 0;
  document.querySelectorAll('input[name=f_wb]').forEach(el => el.checked = (parseInt(el.value)===wb));
  document.getElementById('modalBg').classList.add('on');
  setTimeout(()=>document.getElementById('f_tit').focus(),50);
}
function closeModal(){ document.getElementById('modalBg').classList.remove('on'); }
async function saveModal(){
  const no = document.getElementById('f_no').value;
  const payload = {
    action: no ? 'update_meta' : 'add',
    no: no ? parseInt(no) : undefined,
    tit: document.getElementById('f_tit').value.trim(),
    wb: parseInt(document.querySelector('input[name=f_wb]:checked').value),
    url_dir: document.getElementById('f_url_dir').value.trim(),
    url_no: parseInt(document.getElementById('f_url_no').value)||0,
  };
  if(!payload.tit){ alert('타이틀을 입력하세요'); return; }
  reloadIf(payload);
}
document.addEventListener('keydown', e => {
  const kpBg = document.getElementById('kpBg');
  if(kpBg && kpBg.classList.contains('on')){
    if(e.key==='Escape'){ closeKp(); }
    else if(e.key>='0' && e.key<='9'){ kpDigit(e.key); e.preventDefault(); }
    else if(e.key==='Backspace'){ kpBack(); e.preventDefault(); }
    else if(e.key==='Enter'){ kpSave(); e.preventDefault(); }
    else if(e.key==='+'){ kpStep(1); }
    else if(e.key==='-'){ kpStep(-1); }
    return;
  }
  if(e.key==='Escape') closeModal();
});

/* ── 드래그 순서변경 ── */
function initDrag(sel, action){
  const list = document.querySelector(sel);
  if(!list) return;
  let dragged = null;
  list.querySelectorAll('.card').forEach(card => {
    card.addEventListener('dragstart', () => { dragged = card; setTimeout(() => card.classList.add('dragging'), 0); });
    card.addEventListener('dragend',   () => { card.classList.remove('dragging'); dragged = null; persistOrder(list, action); });
  });
  list.addEventListener('dragover', e => {
    e.preventDefault();
    if(!dragged) return;
    const after = dragAfter(list, e.clientY);
    if(after == null) list.appendChild(dragged);
    else list.insertBefore(dragged, after);
  });
}
function dragAfter(list, y){
  const els = [...list.querySelectorAll('.card:not(.dragging)')];
  let best = { off: -Infinity, el: null };
  for(const el of els){
    const box = el.getBoundingClientRect();
    const off = y - box.top - box.height / 2;
    if(off < 0 && off > best.off) best = { off, el };
  }
  return best.el;
}
async function persistOrder(list, action){
  const ids = [...list.querySelectorAll('.card')].map(c => parseInt(c.dataset.id)).filter(Boolean);
  await api({ action, ids });
}
if(document.getElementById('scrapList')) initDrag('#scrapList', 'scrap_reorder');
<?php if ($mode === 'list' && ($sort ?? 'ord') === 'ord'): ?>
if(document.getElementById('mhList')) initDrag('#mhList', 'reorder');
<?php endif; ?>
</script>
</body>
</html>
