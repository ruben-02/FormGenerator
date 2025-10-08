<?php
include "includes/db.php";

// Helper: extract text from common file types. Returns string or null.
function extract_text_from_file($path) {
    if (!file_exists($path)) return null;
    $mime = mime_content_type($path) ?: '';

    // Plain text and common text-like extensions
    if (strpos($mime, 'text/') === 0 || preg_match('/\.(txt|csv|json|md|html?)$/i', $path)) {
        return @file_get_contents($path, false, null, 0, 200 * 1024);
    }

    // PDF: try using pdftotext if available
    if (stripos($mime, 'pdf') !== false || preg_match('/\.pdf$/i', $path)) {
        $out = null;
        $cmd = 'pdftotext ' . escapeshellarg($path) . ' -';
        @exec($cmd, $outArr, $rc);
        if ($rc === 0 && !empty($outArr)) return implode("\n", $outArr);
    }

    // DOCX, ODT, PPTX: these are zip packages with text/xml inside
    if (preg_match('/\.(docx|odt|pptx)$/i', $path)) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $text = '';
            // docx
            if (($idx = $zip->locateName('word/document.xml')) !== false) {
                $xml = $zip->getFromIndex($idx);
                $xml = strip_tags($xml);
                $text .= $xml;
            }
            // odt
            if (($idx = $zip->locateName('content.xml')) !== false) {
                $xml = $zip->getFromIndex($idx);
                $xml = strip_tags($xml);
                $text .= "\n" . $xml;
            }
            // pptx notes/slides
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/ppt\/slides\/slide[0-9]+\.xml$/', $name)) {
                    $xml = $zip->getFromIndex($i);
                    $text .= "\n" . strip_tags($xml);
                }
            }
            $zip->close();
            if ($text !== '') return $text;
        }
    }

    // RTF: basic extraction by stripping braces (approximate)
    if (preg_match('/\.rtf$/i', $path)) {
        $rtf = @file_get_contents($path);
        if ($rtf !== false) {
            // remove rtf control words
            $rtf = preg_replace('/\\[a-z]+-?\d*\s?/i', '', $rtf);
            $rtf = str_replace(['{','}'], '', $rtf);
            return $rtf;
        }
    }

    // fallback: return null (no text extracted)
    return null;
}

$form_id = $_POST['form_id'] ?? null;
if (!$form_id) {
    die("Form ID missing in submission.");
}

// Build submission array from POST (exclude form_id)
$submissionArr = [];
foreach ($_POST as $k => $v) {
    if ($k === 'form_id') continue;
    $submissionArr[$k] = $v;
}

// Handle file uploads
if (!empty($_FILES)) {
    $uploadBase = __DIR__ . '/uploads';
    if (!is_dir($uploadBase)) mkdir($uploadBase, 0755, true);

    $formDir = $uploadBase . '/' . intval($form_id);
    if (!is_dir($formDir)) mkdir($formDir, 0755, true);

    foreach ($_FILES as $fieldName => $info) {
        // Support multiple files per field
        if (is_array($info['name'])) {
            $submissionArr[$fieldName] = [];
            $count = count($info['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($info['error'][$i] !== UPLOAD_ERR_OK) continue;
                $orig = $info['name'][$i];
                $tmp  = $info['tmp_name'][$i];
                $safe = preg_replace('/[^A-Za-z0-9_\-.]/', '_', basename($orig));
                $target = $formDir . '/' . time() . "_{$i}_" . $safe;
                if (move_uploaded_file($tmp, $target)) {
                    $mime = mime_content_type($target) ?: 'application/octet-stream';
                    $entry = [
                        'filename' => $orig,
                        'stored'   => str_replace(__DIR__, '', $target),
                        'path'     => $target,
                        'mime'     => $mime,
                    ];
                    // extract text for many common text-based formats
                    $txt = extract_text_from_file($target);
                    if ($txt !== null && $txt !== '') {
                        // store a limited amount to avoid huge DB rows
                        $entry['text'] = mb_substr($txt, 0, 100 * 1024);
                    }
                    $submissionArr[$fieldName][] = $entry;
                }
            }
        } else {
            if ($info['error'] === UPLOAD_ERR_OK) {
                $orig = $info['name'];
                $tmp  = $info['tmp_name'];
                $safe = preg_replace('/[^A-Za-z0-9_\-.]/', '_', basename($orig));
                $target = $formDir . '/' . time() . '_' . $safe;
                if (move_uploaded_file($tmp, $target)) {
                    $mime = mime_content_type($target) ?: 'application/octet-stream';
                    $entry = [
                        'filename' => $orig,
                        'stored'   => str_replace(__DIR__, '', $target),
                        'path'     => $target,
                        'mime'     => $mime,
                    ];
                    $txt = extract_text_from_file($target);
                    if ($txt !== null && $txt !== '') {
                        $entry['text'] = mb_substr($txt, 0, 100 * 1024);
                    }
                    $submissionArr[$fieldName] = $entry;
                }
            }
        }
    }
}

// Save submission JSON into DB (column 'submission')
$submission = json_encode($submissionArr);
$stmt = $db->prepare("INSERT INTO form_submissions (form_id, submission) VALUES (?, ?)");
$stmt->execute([intval($form_id), $submission]);

// Simple thank you page for public users
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Thank you</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .thanks{max-width:720px;margin:80px auto;padding:20px;text-align:center}
    </style>
</head>
<body>
    <div class="thanks">
        <h2>Form Submitted Successfully!</h2>
    <p>Thank you for your response.</p>
    </div>
</body>
</html>
?>
