<?php include "includes/db.php"; include "includes/auth.php"; check_login(); ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>AI Form Builder</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index-buttons.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">AI Form Generator</h1>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="small">Logged in as <?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? '') ?></span>
                <a class="new-btn" href="index.php">Create New Forms</a>
                <a class="new-btn" href="forms.php">View Saved Forms</a>
                <button id="mysql-config-btn" class="new-btn" type="button">MySQL Connect</button>
                 <a class="new-btn" href="logout.php">Logout</a>
            </div>
        </div>

        <!-- MySQL Connect Modal -->
        <div id="mysql-modal" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(0,0,0,0.3);z-index:9999">
            <div style="background:white;padding:20px;border-radius:8px;min-width:360px">
                <h3>MySQL Connection</h3>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <label>Host</label>
                    <input id="mysql-host" type="text" style="width:100%" placeholder="127.0.0.1">
                    <label>Port</label>
                    <input id="mysql-port" type="text" style="width:100%" placeholder="3306">
                    <label>Database name</label>
                    <input id="mysql-dbname" type="text" style="width:100%" placeholder="formgen">
                    <label>User</label>
                    <input id="mysql-user" type="text" style="width:100%" placeholder="User">
                    <label>Password</label>
                    <input id="mysql-pass" type="password" style="width:100%" placeholder="Password">
                    <div style="display:flex;gap:8px;margin-top:8px;justify-content:flex-end">
                        <button id="mysql-disconnect" class="btn secondary" type="button">Disconnect</button>
                        <button id="mysql-connect" class="btn" type="button">Connect</button>
                        <button id="mysql-close" class="btn secondary" type="button">Close</button>
                    </div>
                    <div id="mysql-msg" style="margin-top:8px;color:green;display:none"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <form class="form-horizontal" method="post" action="generate.php">
                <div class="form-row">
                    <label for="datasource">Data source</label>
                    <select id="datasource" name="datasource">
                        <option value="sqlite" selected>Default (SQLite)</option>
                        <option value="mysql">MySQL</option>
                    </select>
                </div>
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
        <script>
        (function(){
            const modal = document.getElementById('mysql-modal');
            const openBtn = document.getElementById('mysql-config-btn');
            const closeBtn = document.getElementById('mysql-close');
            const connectBtn = document.getElementById('mysql-connect');
            const disconnectBtn = document.getElementById('mysql-disconnect');
            const msg = document.getElementById('mysql-msg');
            const hostEl = document.getElementById('mysql-host');
            const portEl = document.getElementById('mysql-port');
            const dbnameEl = document.getElementById('mysql-dbname');
            const userEl = document.getElementById('mysql-user');
            const passEl = document.getElementById('mysql-pass');
            const dsSelect = document.getElementById('datasource');

            openBtn.addEventListener('click', function(){ modal.style.display='flex'; msg.style.display='none'; });
            closeBtn.addEventListener('click', function(){ modal.style.display='none'; });

            connectBtn.addEventListener('click', function(){
                msg.style.display='none';
                connectBtn.disabled = true;
                const data = new FormData();
                data.append('action','connect');
                data.append('host', hostEl.value);
                data.append('port', portEl.value);
                data.append('dbname', dbnameEl.value);
                data.append('user', userEl.value);
                data.append('pass', passEl.value);
                fetch('mysql_connect.php', { method:'POST', body: data }).then(r=>r.json()).then(resp=>{
                    connectBtn.disabled = false;
                    if (resp && resp.success) {
                        msg.style.color='green'; msg.textContent='Connected and saved for this session.'; msg.style.display='block';
                        // set datasource to mysql automatically
                        if (dsSelect) dsSelect.value='mysql';
                    } else {
                        msg.style.color='red'; msg.textContent = resp.error || 'Connection failed'; msg.style.display='block';
                    }
                }).catch(err=>{ connectBtn.disabled=false; msg.style.color='red'; msg.textContent='Connection failed'; msg.style.display='block'; });
            });

            disconnectBtn.addEventListener('click', function(){
                disconnectBtn.disabled = true;
                const data = new FormData(); data.append('action','disconnect');
                fetch('mysql_connect.php', { method:'POST', body: data }).then(r=>r.json()).then(resp=>{
                    disconnectBtn.disabled = false;
                    msg.style.color='green'; msg.textContent='Disconnected'; msg.style.display='block';
                    if (dsSelect && dsSelect.value==='mysql') dsSelect.value='sqlite';
                }).catch(err=>{ disconnectBtn.disabled=false; msg.style.color='red'; msg.textContent='Failed'; msg.style.display='block'; });
            });
        })();
        </script>
    </div>
</body>
</html>
