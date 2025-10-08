<?php
include "includes/db.php";
include "includes/auth.php";
check_login();

$form_id = $_GET['form_id'] ?? null;
if (!$form_id) {
    die("Form ID missing.");
}

$stmt = $db->prepare("SELECT name FROM forms WHERE id = ?");
$stmt->execute([$form_id]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    die("Form not found.");
}

// Count submissions
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM form_submissions WHERE form_id = ?");
$stmt->execute([$form_id]);
$countRow = $stmt->fetch(PDO::FETCH_ASSOC);
$count = (int)($countRow['cnt'] ?? 0);

// If requested as CSV download, stream CSV and exit
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="submissions_form_' . intval($form_id) . '.csv"');

    $out = fopen('php://output', 'w');
    // Write header row: id, created_at, plus submission keys discovered in first row
    $stmt = $db->prepare("SELECT submission, id, created_at FROM form_submissions WHERE form_id = ? ORDER BY created_at ASC");
    $stmt->execute([$form_id]);

    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $fields = [];
    foreach ($all as $r) {
        $data = json_decode($r['submission'], true);
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (!in_array($k, $fields, true)) $fields[] = $k;
            }
        }
    }

    // header
    $header = array_merge(['id', 'created_at'], $fields);
    fputcsv($out, $header);

    foreach ($all as $r) {
        $row = [];
        $row[] = $r['id'];
        $row[] = $r['created_at'];
        $data = json_decode($r['submission'], true);
        foreach ($fields as $f) {
            $row[] = is_array($data) && array_key_exists($f, $data) ? (is_scalar($data[$f]) ? $data[$f] : json_encode($data[$f])) : '';
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Submissions for <?= htmlspecialchars($form['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">Submissions: <?= htmlspecialchars($form['name']) ?></h1>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="small">Logged in as <?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? '') ?></span>
                <a class="btn" href="index.php">Create New Forms</a>
                <a class="btn" href="logout.php">Logout</a>
            </div>
        </div>

        <div class="card">
            <p class="small">Total submissions received:</p>
            <h2><?= $count ?></h2>

            <div style="margin-top:12px;display:flex;gap:12px;align-items:center">
                <?php if ($count > 0): ?>
                    <button id="download-csv" class="btn">Download CSV</button>
                <?php else: ?>
                    <button class="btn secondary" disabled>No submissions</button>
                <?php endif; ?>
                <a class="link" href="forms.php">Back to Saved Forms</a>
            </div>
            <div id="msg" style="margin-top:12px;display:none;color:green">CSV download started.</div>
            
            <!-- Chatbot UI -->
            <div class="card" style="margin-top:18px;">
                <h3>Query submissions (Chat)</h3>
                <div id="chat-area" style="height:420px;display:flex;flex-direction:column;overflow:hidden;border:1px solid #eef6ff;padding:12px;border-radius:8px;margin-top:12px;background:#fbfeff">
                    <div id="chat-history" style="flex:1;overflow:auto;padding-right:6px"></div>
                    <div id="chat-processing" style="display:none;margin-top:8px;align-items:center;gap:8px;color:var(--muted)">
                        <svg id="chat-spinner" width="20" height="20" viewBox="0 0 50 50" style="margin-right:8px;animation:spin 1s linear infinite"><circle cx="25" cy="25" r="20" fill="none" stroke="#2563eb" stroke-width="4" stroke-linecap="round" stroke-dasharray="31.4 31.4"/></svg>
                        <span>Processing...</span>
                    </div>
                    <div style="margin-top:8px;display:flex;flex-direction:column;gap:8px">
                        <textarea id="chat-input" placeholder="Ask about the submissions (e.g., 'How many submitted yes to question X?')" style="width:100%;min-height:100px;padding:12px;border-radius:8px;border:1px solid #dbe7f5"></textarea>
                        <div style="display:flex;gap:8px;align-items:center">
                            <button id="chat-send" class="btn">Submit</button>
                            <button id="chat-clear" class="btn secondary" type="button">Clear</button>
                            <span class="small" id="chat-status" style="margin-left:8px;color:var(--muted)"></span>
                        </div>
                    </div>
                </div>
                <div class="small" style="margin-top:8px;color:var(--muted)">Tip: The assistant sees up to the most recent 200 submissions.</div>
            </div>

            <script>
            (function(){
                const btn = document.getElementById('download-csv');
                if (btn) {
                    btn.addEventListener('click', function(){
                        if (!confirm('Download all submissions as CSV?')) return;
                        // start download by navigating to CSV endpoint
                        const url = 'submissions.php?form_id=<?= $form_id ?>&download=csv';
                        // show transient message
                        const msg = document.getElementById('msg');
                        if (msg) { msg.style.display = 'block'; }
                        // initiate download
                        window.location.href = url;
                        // hide message after 5s
                        setTimeout(()=>{ if (msg) msg.style.display='none'; }, 5000);
                    });
                }
            })();
            
            (function(){
                const send = document.getElementById('chat-send');
                const clear = document.getElementById('chat-clear');
                const input = document.getElementById('chat-input');
                const history = document.getElementById('chat-history');
                const status = document.getElementById('chat-status');

                function appendMessage(who, text){
                    const el = document.createElement('div');
                    el.style.padding = '8px';
                    el.style.marginBottom = '6px';
                    el.style.borderRadius = '6px';
                    el.style.maxWidth = '100%';
                    if (who === 'admin') { el.style.background = '#eef6ff'; el.style.textAlign='right'; el.style.marginLeft='20%'; }
                    else { el.style.background = '#fff'; el.style.marginRight='20%'; }
                    // allow simple newlines
                    text = String(text);
                    const pre = document.createElement('div');
                    pre.style.whiteSpace = 'pre-wrap';
                    pre.textContent = text;
                    el.appendChild(pre);
                    history.appendChild(el);
                    history.scrollTop = history.scrollHeight;
                }

                if (send) {
                    send.addEventListener('click', function(){
                        const q = input.value.trim();
                        if (!q) return;
                        appendMessage('admin', q);
                        input.value = '';
                        status.textContent = '';

                        // show processing
                        const processing = document.getElementById('chat-processing');
                        const sendBtn = document.getElementById('chat-send');
                        if (processing) processing.style.display = 'flex';
                        if (sendBtn) sendBtn.disabled = true;

                        fetch('submissions_chat.php', {
                            method: 'POST',
                            headers: {'Content-Type':'application/x-www-form-urlencoded'},
                            body: 'form_id=' + encodeURIComponent('<?= $form_id ?>') + '&query=' + encodeURIComponent(q)
                        }).then(r => r.json()).then(data => {
                            if (processing) processing.style.display = 'none';
                            if (sendBtn) sendBtn.disabled = false;
                            if (data && data.answer) {
                                appendMessage('bot', data.answer);
                                status.textContent = 'Done';
                                setTimeout(()=>{ status.textContent=''; }, 3000);
                            } else {
                                appendMessage('bot', 'No answer (error).');
                                status.textContent = '';
                            }
                        }).catch(err => {
                            if (processing) processing.style.display = 'none';
                            if (sendBtn) sendBtn.disabled = false;
                            appendMessage('bot', 'Error: ' + err.message);
                            status.textContent = '';
                        });
                    });
                }

                if (clear) {
                    clear.addEventListener('click', function(){
                        history.innerHTML = '';
                        input.value = '';
                        status.textContent = '';
                    });
                }
            })();
            </script>
        </div>
    </div>
</body>
</html>
