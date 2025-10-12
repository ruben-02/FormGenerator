<?php include "includes/db.php"; include "includes/auth.php"; check_login(); ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>AI Form Builder</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">AI Form Generator</h1>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="small">Logged in as <?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? '') ?></span>
                <a class="new-btn" href="index.php">Create New Forms</a>
                <a class="new-btn" href="forms.php">View Saved Forms</a>
                <a class="new-btn" href="logout.php">Logout</a>
            </div>
        </div>

        <div class="card">
            <form class="form-horizontal" method="post" action="generate.php">
                <div class="form-row">
                    <label for="form_name">Form Name</label>
                    <input id="form_name" type="text" name="form_name" required>
                </div>

                <div class="form-row">
                    <label for="prompt">Prompt / Description</label>
                    <textarea id="prompt" name="prompt" required></textarea>
                </div>

                <div class="form-row">
                    <button class="btn" type="submit">Generate Form</button>
                </div>
            </form>
        </div>
    
        <!-- processing overlay -->
        <div id="processing-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);align-items:center;justify-content:center;z-index:9999">
            <div style="background:white;padding:20px;border-radius:10px;display:flex;flex-direction:column;align-items:center;gap:12px;min-width:220px">
                <svg width="36" height="36" viewBox="0 0 50 50" style="animation:spin 1s linear infinite"><circle cx="25" cy="25" r="20" fill="none" stroke="#2563eb" stroke-width="4" stroke-linecap="round" stroke-dasharray="31.4 31.4"/></svg>
                <div style="font-weight:600">Generating...</div>
                <div class="small">This may take a few seconds.</div>
            </div>
        </div>

        <script>
        (function(){
            const form = document.querySelector('form');
            const overlay = document.getElementById('processing-overlay');
            const btn = form.querySelector('button[type=submit]');
            form.addEventListener('submit', function(e){
                // show overlay and let the form submit
                if (overlay) overlay.style.display = 'flex';
                if (btn) btn.disabled = true;
            });
        })();
        </script>
    </div>
</body>
</html>
