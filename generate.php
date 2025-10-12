<?php 
include "includes/db.php";
include "includes/config.php";
include "includes/auth.php";
check_login();

if (empty($GEMINI_API_KEY)) {
    die("<p>Error: GEMINI_API_KEY not found in .env</p>");
}

if (empty($GEMINI_MODEL)) {
    die("<p>Error: GEMINI_MODEL not set in .env</p>");
}


$form_name = $_POST['form_name'] ?? '';
$prompt    = $_POST['prompt'] ?? '';
$refine    = $_POST['refine'] ?? '';

if (empty($form_name) || empty($prompt)) {
    die("<p>Error: form_name or prompt missing.</p>");
}

// If refine prompt is provided, use context caching to modify the existing form
$prev_form_code = $_POST['prev_form_code'] ?? '';
if (!empty($refine) && !empty($prev_form_code)) {
    $system_prompt = <<<EOT
You are an expert HTML form generator.
Rules:
- Always output a single complete <form> element, with action="process.php" and method="post".
- Always include a hidden <input type="hidden" name="form_id" value="TEMP_ID">.
- Add fields exactly as requested by the user (text, email, password, textarea, select, radio, checkbox, file upload, date, etc).
- Include <label> elements for accessibility.
- Do not add CSS or JavaScript, just raw HTML form.
- If a previous form HTML is provided, modify it according to the refinement instructions, do not generate a new form from scratch.
EOT;
    $prompt = "Original prompt: " . ($_POST['prompt'] ?? $prompt) . "\nPrevious form HTML:\n" . $prev_form_code . "\nRefinement: " . $refine;
}

$system_prompt = <<<EOT
You are an expert HTML form generator.
Rules:
- Always output a single complete <form> element, with action="process.php" and method="post".
- Always include a hidden <input type="hidden" name="form_id" value="TEMP_ID">.
- Add fields exactly as requested by the user (text, email, password, textarea, select, radio, checkbox, file upload, date, etc).
- Include <label> elements for accessibility.
- Do not add CSS or JavaScript, just raw HTML form.
EOT;

$endpoint = "https://generativelanguage.googleapis.com/v1/$GEMINI_MODEL:generateContent?key=$GEMINI_API_KEY";

$data = [
    "contents" => [[
        "parts" => [
            ["text" => $system_prompt],
            ["text" => "User request: " . $prompt]
        ]
    ]]
];

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);

if (!$response) {
    die("<p>cURL Error: " . curl_error($ch) . "</p>");
}

curl_close($ch);

$result = json_decode($response, true);

if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    $form_code = $result['candidates'][0]['content']['parts'][0]['text'];
    $form_code = preg_replace('/```(?:html)?/', '', $form_code);
    $form_code = trim($form_code);


    // Remove all required attributes so fields are not mandatory
    $form_code = preg_replace('/\srequired(\s*|(?=>))/i', '', $form_code);

    // Enforce phone number format: digits only, no dashes or spaces
    // Replace <input type="tel" ...> with pattern="\\d{10,15}" and inputmode="numeric"
    $form_code = preg_replace_callback(
        '/<input([^>]*type\s*=\s*["\']?tel["\']?[^>]*)>/i',
        function($m) {
            $input = $m[1];
            // Remove any pattern or inputmode attributes
            $input = preg_replace('/\s*pattern\s*=\s*"[^"]*"/i', '', $input);
            $input = preg_replace('/\s*inputmode\s*=\s*"[^"]*"/i', '', $input);
            // Add pattern and inputmode for digits only
            $input .= ' pattern="\\d{10,15}" inputmode="numeric"';
            return '<input' . $input . '>';
        },
        $form_code
    );

    // Remove submit buttons/inputs from the preview so the generated form
    // doesn't submit from the preview page. We'll keep a flag to notify the user.
    $removed_submit = false;

    // Remove <button type="submit">...</button> (case-insensitive, allow attributes)
    $new = preg_replace_callback('#<button\b([^>]*)>(.*?)</button>#is', function($m) use (&$removed_submit) {
        $attrs = $m[1];
        // if type is present and is submit, or type absent -> assume submit and remove
        if (preg_match('/type\s*=\s*"?submit"?/i', $attrs) || !preg_match('/type\s*=\s*"?(?:button|reset)"?/i', $attrs)) {
            $removed_submit = true;
            return '';
        }
        return $m[0];
    }, $form_code);

    // Remove <input type="submit"...>
    $new = preg_replace('#<input\b[^>]*type\s*=\s*"?submit"?[^>]*>#is', '', $new);
    // Also remove <input ... value="Submit"> variants without explicit type
    $new = preg_replace('#<input\b(?=[^>]*value\s*=\s*"?submit"?) [^>]*>#is', '', $new);

    if ($new !== $form_code) {
        $removed_submit = true;
        $form_code = $new;
    }

} elseif (isset($result['error'])) {
    die("<p>Gemini API Error: " . htmlspecialchars($result['error']['message']) . "</p>");
} else {
    die("<p>Unknown Gemini response:<pre>" . htmlspecialchars(print_r($result, true)) . "</pre></p>");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Form Preview</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">Preview: <?= htmlspecialchars($form_name) ?></h1>
            <a class="btn" href="forms.php">Back to Forms</a>
        </div>
          <!-- Refine prompt UI -->
            <form id="refine-form" method="post" action="generate.php" style="margin-bottom:18px;">
                <input type="hidden" name="form_name" value="<?= htmlspecialchars($form_name) ?>">
                <input type="hidden" name="prompt" value="<?= htmlspecialchars($_POST['prompt'] ?? $prompt) ?>">
                <input type="hidden" name="prev_form_code" value="<?= htmlspecialchars($form_code) ?>">
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" name="refine" id="refine-input" placeholder="Refine the form (e.g. add/remove fields)" style="flex:1;padding:8px;border-radius:6px;border:1px solid #dbe7f5;">
                    <button id="refine-btn" class="btn" type="submit">Refine</button>
                </div>
            </form>
            <!-- processing overlay for refine -->
            <div id="processing-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);align-items:center;justify-content:center;z-index:9999">
                <div style="background:white;padding:20px;border-radius:10px;display:flex;flex-direction:column;align-items:center;gap:12px;min-width:220px">
                    <svg width="36" height="36" viewBox="0 0 50 50" style="animation:spin 1s linear infinite"><circle cx="25" cy="25" r="20" fill="none" stroke="#2563eb" stroke-width="4" stroke-linecap="round" stroke-dasharray="31.4 31.4"/></svg>
                    <div style="font-weight:600">Refining...</div>
                    <div class="small">This may take a few seconds.</div>
                </div>
            </div>

        <div class="card">
           

            <?php
            $hasFileUpload = stripos($form_code, '<input') !== false && stripos($form_code, 'type="file"') !== false;
            if (preg_match('/<input[^>]*type\s*=\s*["\']?file["\']?/i', $form_code)) {
            ?>
            <div class="small" style="margin-bottom:10px;color:#ef4444;font-weight:500;">
                Maximum file size per upload: 2MB. Only 2 files allowed per form.
            </div>
            <?php } ?>
            <div class="form-preview">
                <?= $form_code ?>
            </div>

            <form id="save-form" class="inline-form" method="post" action="save.php">
                <input type="hidden" name="form_name" value="<?= htmlspecialchars($form_name) ?>">
                <input type="hidden" name="prompt" value="<?= htmlspecialchars($prompt) ?>">
                <textarea name="form_code" hidden><?= htmlspecialchars($form_code) ?></textarea>
                <div class="preview-actions">
                    <button id="save-btn" class="btn" type="submit">Save Form</button>
                    <a class="link" href="index.php">Generate Another</a>
                </div>
            </form>

            <script>
            (function(){
                // Save form AJAX
                const form = document.getElementById('save-form');
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    const btn = document.getElementById('save-btn');
                    btn.disabled = true;
                    const data = new FormData(form);
                    data.append('ajax', '1');
                    fetch(form.action, {
                        method: 'POST',
                        body: data,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }).then(r => r.json()).then(resp => {
                        if (resp && resp.success) {
                            // show simple alert then redirect
                            alert('Form saved successfully');
                            window.location.href = 'forms.php';
                        } else {
                            alert('Save failed');
                            btn.disabled = false;
                        }
                    }).catch(err => {
                        console.error(err);
                        alert('Save failed');
                        btn.disabled = false;
                    });
                });

                // Refine overlay
                const refineForm = document.getElementById('refine-form');
                const overlay = document.getElementById('processing-overlay');
                const refineBtn = document.getElementById('refine-btn');
                if (refineForm && overlay && refineBtn) {
                    refineForm.addEventListener('submit', function(e){
                        if (overlay) overlay.style.display = 'flex';
                        if (refineBtn) refineBtn.disabled = true;
                    });
                }
            })();
            </script>
        </div>
    </div>
</body>
</html>
