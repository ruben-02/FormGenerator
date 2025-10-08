<?php
include "includes/db.php";
include "includes/auth.php";
check_login();

$id = $_GET['id'] ?? null;
if (!$id) { echo json_encode(['error'=>'Missing id']); exit; }

$stmt = $db->prepare("SELECT public_token FROM forms WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['error'=>'Not found']); exit; }

header('Content-Type: application/json');
echo json_encode(['token' => $row['public_token'] ?? null]);
exit;

?>
