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

// Get user id (username) from session if available
session_start();
$user_id = $_SESSION['username'] ?? 'guest';

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

    // Isolate uploads by user id and form id
    $userDir = $uploadBase . '/' . preg_replace('/[^A-Za-z0-9_\-.]/', '_', $user_id);
    if (!is_dir($userDir)) mkdir($userDir, 0755, true);
    $formDir = $userDir . '/' . intval($form_id);
    if (!is_dir($formDir)) mkdir($formDir, 0755, true);

    $maxFileSize = 2 * 1024 * 1024; // 2MB

    $totalFiles = 0;
    foreach ($_FILES as $fieldName => $info) {
        // Support multiple files per field
        if (is_array($info['name'])) {
            $submissionArr[$fieldName] = [];
            $count = count($info['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($info['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($info['size'][$i] > $maxFileSize) {
                    $submissionArr[$fieldName][] = [
                        'error' => 'File too large (max 2MB)',
                        'filename' => $info['name'][$i]
                    ];
                    continue;
                }
                if ($totalFiles >= 2) {
                    $submissionArr[$fieldName][] = [
                        'error' => 'Only 2 files allowed per form',
                        'filename' => $info['name'][$i]
                    ];
                    continue;
                }
                $orig = $info['name'][$i];
                $tmp  = $info['tmp_name'][$i];
                $safe = preg_replace('/[^A-Za-z0-9_\-.]/', '_', basename($orig));
                $unique = uniqid($user_id . '_', true);
                $target = $formDir . '/' . $unique . "_{$i}_" . $safe;
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
                    $totalFiles++;
                }
            }
        } else {
            if ($info['error'] === UPLOAD_ERR_OK) {
                if ($info['size'] > $maxFileSize) {
                    $submissionArr[$fieldName] = [
                        'error' => 'File too large (max 2MB)',
                        'filename' => $info['name']
                    ];
                    continue;
                }
                if ($totalFiles >= 2) {
                    $submissionArr[$fieldName] = [
                        'error' => 'Only 2 files allowed per form',
                        'filename' => $info['name']
                    ];
                    continue;
                }
                $orig = $info['name'];
                $tmp  = $info['tmp_name'];
                $safe = preg_replace('/[^A-Za-z0-9_\-.]/', '_', basename($orig));
                $unique = uniqid($user_id . '_', true);
                $target = $formDir . '/' . $unique . '_' . $safe;
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
                    $totalFiles++;
                }
            }
        }
    }
}

// Save submission JSON into DB (column 'submission')
$submission = json_encode($submissionArr);
// Determine datasource for this form
$stmt = $db->prepare("SELECT datasource FROM forms WHERE id = ?");
$stmt->execute([intval($form_id)]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$datasource = $row['datasource'] ?? 'sqlite';

if ($datasource === 'mysql') {
    // Try to insert into MySQL (store JSON in a submissions table named form_{id}_submissions)
    include_once __DIR__ . '/includes/config.php';
    $mysql = get_mysql_pdo();
    if ($mysql) {
        $table = 'form_' . intval($form_id) . '_submissions';
        try {
            // Create table if not exists
            $createSql = "CREATE TABLE IF NOT EXISTS `" . $table . "` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                submission LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) CHARSET=utf8mb4";
            $mysql->exec($createSql);
            $ins = $mysql->prepare("INSERT INTO `" . $table . "` (submission) VALUES (?)");
            $ins->execute([$submission]);
        } catch (Exception $ex) {
            // Log the error and fallback to sqlite to avoid data loss
            $logDir = __DIR__ . '/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
            $msg = date('c') . " - MySQL insert failed for form_id {$form_id}: " . $ex->getMessage() . "\n";
            @file_put_contents($logDir . '/mysql_errors.log', $msg, FILE_APPEND);
            $stmt = $db->prepare("INSERT INTO form_submissions (form_id, submission) VALUES (?, ?)");
            $stmt->execute([intval($form_id), $submission]);
        }
    } else {
        // fallback to sqlite if mysql not configured
        $stmt = $db->prepare("INSERT INTO form_submissions (form_id, submission) VALUES (?, ?)");
        $stmt->execute([intval($form_id), $submission]);
    }
} else {
    $stmt = $db->prepare("INSERT INTO form_submissions (form_id, submission) VALUES (?, ?)");
    $stmt->execute([intval($form_id), $submission]);
}

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
