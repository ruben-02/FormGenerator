<?php
include "includes/db.php";
include "includes/auth.php";
include "includes/config.php";
check_login();

// Simple proxy endpoint: POST { form_id, query }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$form_id = $_POST['form_id'] ?? null;
$query   = trim($_POST['query'] ?? '');

if (!$form_id || $query === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing form_id or query']);
    exit;
}

// fetch submissions for the form (limit to last 200 rows to avoid huge payloads)
$stmt = $db->prepare("SELECT submission, created_at FROM form_submissions WHERE form_id = ? ORDER BY created_at DESC LIMIT 200");
$stmt->execute([$form_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$submissions = [];
foreach ($rows as $r) {
    $data = json_decode($r['submission'], true);
    $submissions[] = ['created_at' => $r['created_at'], 'data' => $data];
}

// Build a prompt summarizing the submissions and including the user query
$summary = "You are an assistant that helps analyze form submissions. The admin will ask questions about the submissions for a particular form.\n";
$summary .= "Here are the recent submissions (most recent first), up to 200 rows:\n";
foreach ($submissions as $i => $s) {
    $idx = $i + 1;
    $summary .= "#{$idx} (" . ($s['created_at'] ?? '') . "):\n";
    if (is_array($s['data'])) {
        foreach ($s['data'] as $k => $v) {
            if (is_array($v) || is_object($v)) {
                // may be file info
                $fileTextParts = [];
                if (isset($v['text']) && is_string($v['text'])) {
                    $fileTextParts[] = substr($v['text'], 0, 2000);
                } elseif (is_array($v)) {
                    foreach ($v as $item) {
                        if (isset($item['text']) && is_string($item['text'])) {
                            $fileTextParts[] = substr($item['text'], 0, 2000);
                        }
                    }
                }
                if ($fileTextParts) {
                    $summary .= " - {$k}: [file_text_start] \n" . implode("\n---\n", $fileTextParts) . "\n[file_text_end]\n";
                } else {
                    $val = json_encode($v);
                    $summary .= " - {$k}: {$val}\n";
                }
            } else {
                $val = is_scalar($v) ? $v : json_encode($v);
                $summary .= " - {$k}: {$val}\n";
            }
        }
    } else {
        $summary .= " - raw: " . (is_scalar($s['data']) ? $s['data'] : json_encode($s['data'])) . "\n";
    }
}

$prompt = $summary . "\nAdmin query: " . $query . "\nAnswer succinctly and include examples or counts when appropriate.";

// Call Gemini REST API (Generative Language API)
$endpoint = "https://generativelanguage.googleapis.com/v1/$GEMINI_MODEL:generateContent?key=$GEMINI_API_KEY";

$payload = [
    'contents' => [[
        'parts' => [[ 'text' => $prompt ]]
    ]]
];

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$resp = curl_exec($ch);
if ($resp === false) {
    http_response_code(500);
    echo json_encode(['error' => 'API request failed: ' . curl_error($ch)]);
    exit;
}
curl_close($ch);

$json = json_decode($resp, true);
if (!$json) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid API response', 'raw' => $resp]);
    exit;
}


$answer = null;
$sql_result = null;
if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
    $answer = $json['candidates'][0]['content']['parts'][0]['text'];

    // Try to extract SQL query from the answer (look for ```sql ... ``` block)
    if (preg_match('/```sql\s*(.*?)```/is', $answer, $m)) {
        $sql = trim($m[1]);
        // Only allow SELECT queries for safety
        if (stripos($sql, 'select') === 0) {
            try {
                $stmt = $db->query($sql);
                $sql_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $ex) {
                $sql_result = 'SQL Error: ' . $ex->getMessage();
            }
        } else {
            $sql_result = 'Only SELECT queries are allowed.';
        }
    }
} elseif (isset($json['error'])) {
    http_response_code(500);
    echo json_encode(['error' => $json['error']]);
    exit;
} else {
    $answer = json_encode($json);
}

header('Content-Type: application/json');
echo json_encode(['answer' => $answer, 'sql_result' => $sql_result]);
exit;

?>
