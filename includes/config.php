<?php
// ...existing code...
$env = @parse_ini_file(__DIR__ . '/../.env'); // @ to suppress warning if missing

$GEMINI_API_KEY = getenv('GEMINI_API_KEY') ?: ($env['GEMINI_API_KEY'] ?? null);
$GEMINI_MODEL   = getenv('GEMINI_MODEL') ?: ($env['GEMINI_MODEL'] ?? "models/gemini-2.5-flash");
// MySQL settings (optional). Set these in .env if you want MySQL support.
$MYSQL_DSN = getenv('MYSQL_DSN') ?: ($env['MYSQL_DSN'] ?? null); // e.g. mysql:host=127.0.0.1;port=3306;dbname=mydb;charset=utf8mb4
$MYSQL_USER = getenv('MYSQL_USER') ?: ($env['MYSQL_USER'] ?? null);
$MYSQL_PASS = getenv('MYSQL_PASS') ?: ($env['MYSQL_PASS'] ?? null);

// Ensure session is started so we can store runtime MySQL credentials entered via UI
if (session_status() === PHP_SESSION_NONE) session_start();

function get_mysql_pdo() {
	static $cached = null;
	if ($cached instanceof PDO) return $cached;

	// Prefer session-stored credentials (entered via UI)
	if (!empty($_SESSION['mysql_dsn']) && !empty($_SESSION['mysql_user'])) {
		$dsn = $_SESSION['mysql_dsn'];
		$user = $_SESSION['mysql_user'];
		$pass = $_SESSION['mysql_pass'] ?? null;
	} else {
		global $MYSQL_DSN, $MYSQL_USER, $MYSQL_PASS;
		$dsn = $MYSQL_DSN;
		$user = $MYSQL_USER;
		$pass = $MYSQL_PASS;
	}

	if (empty($dsn) || empty($user)) return null;
	try {
		$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$cached = $pdo;
		return $pdo;
	} catch (Exception $ex) {
		return null;
	}
}
// ...existing code...
?>