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

if (empty($form_name) || empty($prompt)) {
    die("<p>Error: form_name or prompt missing.</p>");
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

        <div class="card">
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
            })();
            </script>
        </div>
    </div>
</body>
</html>
