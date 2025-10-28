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
                                <button class="small-black-btn" onclick="shareForm(<?= $form['id'] ?>, '<?= htmlspecialchars(addslashes($form['name'])) ?>')">Share</button>
                                <a class="small-black-btn" href="submissions.php?form_id=<?= $form['id'] ?>">Submissions</a>
                                <form method="post" action="delete.php" style="display:inline;margin-left:8px;" onsubmit="event.preventDefault(); confirmDelete(this);">
                                    <input type="hidden" name="id" value="<?= $form['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <button type="submit" class="small-black-btn">Delete</button>
                                </form>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <!-- Modal markup used for confirmations (kept in document root so it can be reused) -->
    <div id="app-modal-overlay" class="app-modal-overlay" aria-hidden="true">
        <div id="app-modal-box" class="app-modal-box" role="dialog" aria-modal="true">
            <div class="app-modal-header">
                <h3 id="app-modal-title"></h3>
            </div>
            <div id="app-modal-body" class="app-modal-body"></div>
            <div id="app-modal-actions" class="app-modal-actions"></div>
        </div>
    </div>

    <script>
    // Modal helper: showModal(title, message, buttons) -> Promise resolves with button id
    function showModal(title, message, buttons){
        return new Promise((resolve)=>{
            const overlay = document.getElementById('app-modal-overlay');
            const titleEl = document.getElementById('app-modal-title');
            const bodyEl = document.getElementById('app-modal-body');
            const actionsEl = document.getElementById('app-modal-actions');

            titleEl.textContent = title || '';
            bodyEl.textContent = message || '';
            actionsEl.innerHTML = '';

            // create buttons in order
            buttons.forEach(b => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = b.class || 'btn';
                btn.textContent = b.label || b.id;
                btn.addEventListener('click', ()=>{
                    overlay.classList.remove('visible');
                    resolve(b.id);
                });
                actionsEl.appendChild(btn);
            });

            // show overlay
            overlay.classList.add('visible');
        });
    }

    // Confirm delete: shows modal and submits the form when confirmed
    function confirmDelete(form){
        showModal('Delete form', 'Delete this form?', [
            {id:'cancel', label:'Cancel', class:'btn secondary'},
            {id:'confirm', label:'Delete', class:'small-black-btn'}
        ]).then(choice=>{
            if (choice === 'confirm') form.submit();
        });
    }

    // Share flow - convert to async and replace confirm() with modal choices
    async function shareForm(id, name){
        const base = window.location.origin + '/public.php?id=' + id;
        try {
            const r = await fetch('get_token.php?id=' + encodeURIComponent(id));
            const data = await r.json();
            const has = data && data.token;
            if (!has) {
                // offer create or copy open link
                const choice = await showModal('Share form', 'Create a protected token for this public link? Choose Create to create a token, or Copy to copy an open link.', [
                    {id:'copy', label:'Copy open link', class:'small-black-btn'},
                    {id:'create', label:'Create token', class:'small-black-btn'}
                ]);
                if (choice === 'copy') { copyUrl(base); return; }
                // create token
                const resp = await fetch('create_token.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)}).then(r=>r.json());
                if (resp && resp.success && resp.token) {
                    copyUrl(base + '&token=' + resp.token);
                } else {
                    await showModal('Error','Failed to create token',[{id:'ok',label:'OK',class:'small-black-btn'}]);
                }
            } else {
                // token exists - copy protected link or offer revoke
                const choice = await showModal('Share form','A protected token already exists for this form. Choose Copy to copy protected link, or Revoke to revoke the token.',[
                    {id:'copy', label:'Copy protected link', class:'small-black-btn'},
                    {id:'revoke', label:'Revoke token', class:'small-black-btn'}
                ]);
                if (choice === 'copy') {
                    copyUrl(base + '&token=' + data.token);
                    return;
                }
                if (choice === 'revoke'){
                    const resp = await fetch('create_token.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)+'&action=revoke'}).then(r=>r.json());
                    if (resp && resp.success) await showModal('Success','Token revoked',[{id:'ok',label:'OK',class:'small-black-btn'}]); else await showModal('Error','Failed to revoke',[{id:'ok',label:'OK',class:'small-black-btn'}]);
                }
            }
        } catch (err){
            // on network or parse error, fallback to copy open link
            copyUrl(base);
        }
    }

    function copyUrl(url){
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(() => showModal('Copied','Public link copied to clipboard:\n' + url,[{id:'ok',label:'OK',class:'small-black-btn'}])).catch(()=>{
                // fallback to prompt
                prompt('Copy this public link for sharing:', url);
            });
        } else prompt('Copy this public link for sharing:', url);
    }
    </script>
</body>
</html>
