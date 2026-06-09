<?php
// lg.php (화면 파일)
// 비로그인 상태일 때 접속하려던 원래 URL을 받음
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";


// 비로그인 상태일 때 접속하려던 원래 URL을 받음
$redirect_url = isset($_GET['url']) ? htmlspecialchars($_GET['url']) : 'etf_stock.php?mode=si';

// 사용자가 '로그인 버튼'을 눌러서 POST 방식으로 접근했을 때만 검증 실행!
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adminID'])) {
    
    // 💡 여기서 아이디와 비밀번호가 맞는지 체크하는 함수를 실행합니다.
    acc_log_on($_POST['adminID'], $pdo);
    
    // acc_log_on 함수 내부에서 성공/실패에 따라 페이지 이동을 시켜버리므로,
    // 이 밑으로는 코드가 실행되지 않습니다.
}


?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>로그인</title>

 <style>
			 .login-container {
													width: 300px;
													margin: 50px auto;
													padding: 20px;
													border: 1px solid #ddd;
													border-radius: 8px;
													background: #f9f9f9;
													font-family: sans-serif;
											}

			.input-group { margin-bottom: 15px; }
			.input-group label { display: block; margin-bottom: 5px; font-weight: bold; }
			.input-group input { width: 100%; padding: 8px; box-sizing: border-box; }
			button { width: 100%; padding: 10px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
			button:hover { background: #0056b3; }

</style>


<body>
    <div class="login-container">
        <h2>사용자 로그인</h2>
        <form method="post" action="lg.php">
            <input type="hidden" name="adminID[url]" value="<?php echo $redirect_url; ?>">
            <div class="input-group">
                <label for="usr_name">ID</label>
                <input type="text" name="adminID[usr_name]" id="usr_name" required>
            </div>
            <div class="input-group">
                <label for="usr_pwd">P/W</label>
                <input type="password" name="adminID[usr_pwd]" id="usr_pwd" required>
            </div>
            <button type="submit">로그인</button>
        </form>
    </div>
</body>



