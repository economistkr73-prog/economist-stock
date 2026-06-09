<?php
// ==========================================
// 카카오 최초 인증 페이지 (refresh_token 1회 발급용)
// 브라우저로 이 페이지에 접속하면 카카오 동의 화면으로 이동 →
// 동의 후 돌아오면 토큰을 kakao_token.json 에 저장한다.
// ==========================================
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();
require_once "./env/kakao.inc";

// 1) code 가 없으면 카카오 동의 화면으로 보냄
if (empty($_GET['code'])) {
    $authUrl = "https://kauth.kakao.com/oauth/authorize?" . http_build_query([
        "response_type" => "code",
        "client_id"     => KAKAO_REST_API_KEY,
        "redirect_uri"  => KAKAO_REDIRECT_URI,
        "scope"         => "talk_message",
    ]);
    header("Location: " . $authUrl);
    exit;
}

// 2) 돌아온 code 로 토큰 발급
$ch = curl_init("https://kauth.kakao.com/oauth/token");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        "grant_type"    => "authorization_code",
        "client_id"     => KAKAO_REST_API_KEY,
        "client_secret" => KAKAO_CLIENT_SECRET,
        "redirect_uri"  => KAKAO_REDIRECT_URI,
        "code"          => $_GET['code'],
    ]),
]);
$res  = curl_exec($ch);
$data = json_decode($res, true);
curl_close($ch);

if (empty($data['refresh_token'])) {
    echo "<h3>토큰 발급 실패</h3><pre>" . htmlspecialchars($res) . "</pre>";
    exit;
}

// 3) 토큰 저장
file_put_contents(KAKAO_TOKEN_FILE, json_encode([
    "access_token"  => $data['access_token'],
    "refresh_token" => $data['refresh_token'],
], JSON_UNESCAPED_UNICODE));

echo "<h3>✅ 카카오 인증 완료! 이제 KakaoNotify::send() 로 알림을 보낼 수 있습니다.</h3>";
?>
