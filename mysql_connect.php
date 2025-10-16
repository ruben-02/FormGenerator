<?php
// mysql_connect.php
// POST: action=connect|disconnect
// connect: { dsn, user, pass }
// On connect: try to open PDO and if successful store credentials in session

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$action = $_POST['action'] ?? null;
if (!$action) { http_response_code(400); echo json_encode(['error'=>'Missing action']); exit; }

if ($action === 'disconnect') {
    unset($_SESSION['mysql_dsn'], $_SESSION['mysql_user'], $_SESSION['mysql_pass']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'connect') {
    $host = trim($_POST['host'] ?? '127.0.0.1');
    $port = trim($_POST['port'] ?? '3306');
    $dbname = trim($_POST['dbname'] ?? 'formgen');
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? null;
    if (!$host || !$port || !$user) { http_response_code(400); echo json_encode(['error'=>'Missing host/port/user']); exit; }

    // Connect to MySQL server without specifying database to create DB if needed
    $adminDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    try {
        $adminPdo = new PDO($adminDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['error' => 'Server connection failed: ' . $ex->getMessage()]);
        exit;
    }

    // Create database if not exists
    try {
        $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`','', $dbname) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $ex) {
        // non-fatal: continue
    }

    // Reconnect using the specific database DSN
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // success - store in session
        $_SESSION['mysql_dsn'] = $dsn;
        $_SESSION['mysql_user'] = $user;
        $_SESSION['mysql_pass'] = $pass;
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['error' => 'Connection to created DB failed: ' . $ex->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error'=>'Unknown action']);
exit;
