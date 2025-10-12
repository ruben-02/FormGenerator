<?php
// Public form view - no auth required
include "includes/db.php";

$id = $_GET['id'] ?? null;
if (!$id) {
    die('Form ID missing.');
}
// fetch form
$stmt = $db->prepare("SELECT * FROM forms WHERE id = ?");
$stmt->execute([$id]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    die('Form not found.');
}

// If form has a public_token set, require token match in query string
if (!empty($form['public_token'])) {
    $token = $_GET['token'] ?? null;
    if (!$token || !hash_equals($form['public_token'], $token)) {
        http_response_code(403);
        die('This form is protected. Missing or invalid token.');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($form['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="card">
            <h1 class="title"><?= htmlspecialchars($form['name']) ?></h1>
                <?php if (preg_match('/<input[^>]*type\s*=\s*["\']?file["\']?/i', $form['form_code'])) { ?>
                <div class="small" style="margin-bottom:10px;color:#ef4444;font-weight:500;">
                    Maximum file size per upload: 2MB. Only 2 files allowed per form.
                </div>
                <?php } ?>
                <div class="form-preview">
                    <?php
                    // Remove all required attributes so fields are not mandatory
                    $form_html = preg_replace('/\srequired(\s*|(?=>))/i', '', $form['form_code']);
                    // Enforce phone number format: digits only, no dashes or spaces
                    $form_html = preg_replace_callback(
                        '/<input([^>]*type\s*=\s*["\']?tel["\']?[^>]*)>/i',
                        function($m) {
                            $input = $m[1];
                            $input = preg_replace('/\s*pattern\s*=\s*"[^"]*"/i', '', $input);
                            $input = preg_replace('/\s*inputmode\s*=\s*"[^"]*"/i', '', $input);
                            $input .= ' pattern="\\d{10,15}" inputmode="numeric"';
                            return '<input' . $input . '>';
                        },
                        $form_html
                    );
                    // Ensure the stored form HTML contains a submit button so public users can submit.
                    libxml_use_internal_errors(true);
                    $doc = new DOMDocument();
                    // Wrap to parse fragment
                    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $form_html . '</div>');
                    $xpath = new DOMXPath($doc);

                    $formEl = $xpath->query('//*[@id="root"]//form')->item(0);
                    if ($formEl instanceof DOMElement) {
                        // Ensure form has method=post and action set to process.php if missing
                        if (!$formEl->hasAttribute('method')) {
                            $formEl->setAttribute('method', 'post');
                        }
                        if (!$formEl->hasAttribute('action')) {
                            $formEl->setAttribute('action', 'process.php');
                        }

                        // Check for submit controls
                        $hasSubmit = false;
                        foreach ($xpath->query('.//button|.//input', $formEl) as $ctrl) {
                            if ($ctrl instanceof DOMElement) {
                                $tag = strtolower($ctrl->tagName);
                                if ($tag === 'button') {
                                    $type = $ctrl->getAttribute('type');
                                    if ($type === '' || strtolower($type) === 'submit') { $hasSubmit = true; break; }
                                } elseif ($tag === 'input') {
                                    $type = strtolower($ctrl->getAttribute('type'));
                                    if ($type === 'submit') { $hasSubmit = true; break; }
                                }
                            }
                        }

                        if (!$hasSubmit) {
                            // create a submit button and append to the form
                            $btn = $doc->createElement('button', 'Submit');
                            $btn->setAttribute('type', 'submit');
                            $btn->setAttribute('class', 'btn');
                            $formEl->appendChild($btn);
                        }
                    }

                    // Output inner HTML of #root
                    $root = $doc->getElementById('root');
                    if ($root) {
                        $out = '';
                        foreach ($root->childNodes as $child) {
                            $out .= $doc->saveHTML($child);
                        }
                        echo $out;
                    } else {
                        echo $form_html;
                    }
                    libxml_clear_errors();
                    ?>
                </div>
        </div>
    </div>
</body>
</html>
