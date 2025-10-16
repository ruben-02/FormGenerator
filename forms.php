<?php
include "includes/db.php";
include "includes/auth.php";
check_login();


$username = $_SESSION['username'];
$stmt = $db->prepare("SELECT id, name, created_at FROM forms WHERE username = ? ORDER BY created_at DESC");
$stmt->execute([$username]);
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// session token for delete actions (session started in includes/auth.php)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Saved Forms</title>
    <link rel="stylesheet" href="assets/css/style.css">
      <link rel="stylesheet" href="assets/css/index-buttons.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">Saved Forms</h1>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="small">Logged in as <?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? '') ?></span>
                <a class="new-btn" href="index.php">Create New Form</a>
                <a class="new-btn" href="logout.php">Logout</a>
            </div>
        </div>

        <div class="card">
            <ul class="forms-list">
                <?php foreach ($forms as $form): ?>
                    <li>
                        <div class="form-item">
                            <div class="form-info">
                                <a class="form-name" href="view_form.php?id=<?= $form['id'] ?>"><?= htmlspecialchars($form['name']) ?></a>
                                <span class="form-meta">Created: <?= $form['created_at'] ?></span>
                            </div>
                            <div class="actions">
                                <button class="link" onclick="shareForm(<?= $form['id'] ?>, '<?= htmlspecialchars(addslashes($form['name'])) ?>')">Share</button>
                                <a class="link" href="submissions.php?form_id=<?= $form['id'] ?>">View Submissions</a>
                                <form method="post" action="delete.php" style="display:inline;margin-left:8px;" onsubmit="return confirm('Delete this form?');">
                                    <input type="hidden" name="id" value="<?= $form['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <button type="submit" class="link" style="color:#ef4444;background:none;border:none;padding:0;cursor:pointer;">Delete</button>
                                </form>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</body>
</html>
<script>
function shareForm(id, name) {
    // Use correct path for PHP built-in server (no /FormGenerate/ prefix)
    const base = window.location.origin + '/public.php?id=' + id;
    // Check if a token already exists
    fetch('get_token.php?id=' + encodeURIComponent(id)).then(r => r.json()).then(data => {
        const has = data && data.token;
        if (!has) {
            // offer create or open
            const create = confirm('Create a protected token for this public link? OK = create, Cancel = copy open link');
            if (!create) {
                copyUrl(base);
                return;
            }
            // create token
            fetch('create_token.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)}).then(r=>r.json()).then(resp=>{
                if (resp && resp.success && resp.token) {
                    copyUrl(base + '&token=' + resp.token);
                } else alert('Failed to create token');
            }).catch(()=>alert('Failed to create token'));
        } else {
            // token exists - copy protected link or offer revoke
            const action = confirm('A protected token already exists for this form. OK = copy protected link, Cancel = revoke token');
            if (action) {
                copyUrl(base + '&token=' + data.token);
            } else {
                // revoke
                fetch('create_token.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)+'&action=revoke'}).then(r=>r.json()).then(resp=>{
                    if (resp && resp.success) alert('Token revoked'); else alert('Failed to revoke');
                }).catch(()=>alert('Failed to revoke'));
            }
        }
    }).catch(()=>{ copyUrl(base); });
}

function copyUrl(url){
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => alert('Public link copied to clipboard:\n' + url)).catch(()=>prompt('Copy this public link for sharing:', url));
    } else prompt('Copy this public link for sharing:', url);
}
</script>
