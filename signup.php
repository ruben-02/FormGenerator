<?php

require "includes/auth.php";

$error = "";

// Multiple user signup allowed: removed single-admin restriction

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $fullname = trim($_POST['fullname']);

    if(!$username || !$password || !$fullname){
        $error = "All fields are required";
    } else {
        $db = get_user_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE username=?");
        $stmt->execute([$username]);
        if($stmt->fetch()){
            $error = "User already exists";
        } else {
            $stmt = $db->prepare("INSERT INTO users (username,password,fullname) VALUES (?,?,?)");
            $stmt->execute([$username, password_hash($password,PASSWORD_DEFAULT), $fullname]);
            $_SESSION['username'] = $username;
            $_SESSION['fullname'] = $fullname;
            header("Location: index.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Signup</title>
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
            <input class="login-input" name="fullname" type="text" placeholder="Full name" required>
            <input class="login-input" name="username" type="email" placeholder="Email or username" required>
            <input class="login-input" name="password" type="password" placeholder="Password" required>
            <button class="login-submit" type="submit">Signup</button>
        </form>

        <div class="login-note">Already have an account? <a href="login.php">Login here</a></div>
    </div>
</div>
</body>
</html>