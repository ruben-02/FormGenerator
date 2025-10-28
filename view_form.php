<?php
include "includes/db.php";
include "includes/auth.php";
check_login();

$id = $_GET['id'] ?? null;
if (!$id) {
    die("Form ID missing.");
}

$stmt = $db->prepare("SELECT * FROM forms WHERE id = ?");
$stmt->execute([$id]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    die("Form not found.");
}
// generate a CSRF token for delete action (session started in includes/auth.php)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($form['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index-buttons.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title"><?= htmlspecialchars($form['name']) ?></h1>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="small">Logged in as <?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? '') ?></span>
                <a class="new-btn" href="forms.php">Back to Saved Forms</a>
                <a class="new-btn" href="logout.php">Logout</a>
            </div>
        </div>

        <div class="card">
            <?php if (preg_match('/<input[^>]*type\s*=\s*["\']?file["\']?/i', $form['form_code'])) { ?>
            <div class="small" style="margin-bottom:10px;color:#ef4444;font-weight:500;">
                Maximum file size per upload: 2MB. Only 2 files allowed per form.
            </div>
            <?php } ?>
            <div class="form-preview">
                <?= $form['form_code'] ?>
            </div>

            <hr>
            <a class="link" href="submissions.php?form_id=<?= $id ?>">View Submissions</a>
            
            <form method="post" action="delete.php" onsubmit="return confirm('Delete this form and all associated data? This cannot be undone.')" style="margin-top:12px;">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn" style="background:#ef4444;border-color:#ef4444">Delete Form</button>
            </form>
        </div>
    </div>
</body>
</html>
