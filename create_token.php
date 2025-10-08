<?php
include "includes/db.php";
include "includes/auth.php";
check_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$id = $_POST['id'] ?? null;
$action = $_POST['action'] ?? 'create'; // create or revoke
if (!$id) {
    echo json_encode(['error' => 'Missing id']);
    exit;
}

if ($action === 'revoke') {
    $stmt = $db->prepare("UPDATE forms SET public_token = NULL WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'revoked' => true]);
    exit;
}

$token = bin2hex(random_bytes(16));
$stmt = $db->prepare("UPDATE forms SET public_token = ? WHERE id = ?");
$stmt->execute([$token, $id]);

header('Content-Type: application/json');
echo json_encode(['success' => true, 'token' => $token]);
exit;

?>
<?php
include "includes/db.php";
include "includes/auth.php";
check_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['error' => 'Missing id']);
    exit;
}

$token = bin2hex(random_bytes(16));
$stmt = $db->prepare("UPDATE forms SET public_token = ? WHERE id = ?");
$stmt->execute([$token, $id]);

header('Content-Type: application/json');
echo json_encode(['success' => true, 'token' => $token]);
exit;

?>
