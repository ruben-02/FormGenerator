<?php
require "includes/auth.php";

$error = "";

if(isset($_SESSION['username'])){
    header("Location: index.php");
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $db = get_user_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE username=?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if($user && password_verify($password, $user['password'])){
        $_SESSION['username'] = $user['username'];
        $_SESSION['fullname'] = $user['fullname'];
        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid username or password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-container">
        <div class="login-top">
            <img src="assets/logo512.png" alt="SmartCard AI" class="login-logo-img">
            <div class="login-brand">SmartCard AI</div>
            <div class="login-sub">AI Form Generator</div>
        </div>

        <?php if($error): ?>
            <div style="background:#ffebee;color:#b71c1c;padding:10px;border-radius:8px;margin-bottom:12px;text-align:center"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <input class="login-input" name="username" type="text" placeholder="Email or username" required>
            <input class="login-input" name="password" type="password" placeholder="Password" required>
            <button class="login-submit" type="submit">Login</button>
        </form>

        <div class="login-note">Don't have an account? <a href="signup.php">Sign up</a></div>
    </div>
</div>
</body>
</html>