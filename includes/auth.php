<?php
// Simple auth utilities - stores users in users.db
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function check_login() {
    if (!isset($_SESSION['username'])) {
        header('Location: login.php');
        exit;
    }
}

function get_user_db() {
    $db = new PDO('sqlite:users.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        username TEXT PRIMARY KEY,
        password TEXT,
        fullname TEXT
    )");
    return $db;
}

?>
