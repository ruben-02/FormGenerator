<?php
session_start();
include "includes/db.php";
include "includes/auth.php";
check_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

$id = $_POST['id'] ?? null;
$token = $_POST['csrf_token'] ?? '';

if (!$id) {
    die('Missing id');
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    die('Invalid CSRF token');
}

$stmt = $db->prepare("DELETE FROM forms WHERE id = ?");
$stmt->execute([$id]);

// Optional: also delete submissions for this form if you have a submissions table
// $stmt = $db->prepare("DELETE FROM submissions WHERE form_id = ?");
// $stmt->execute([$id]);

// Clear CSRF token to prevent replay
unset($_SESSION['csrf_token']);

header('Location: forms.php');
exit;

?>
