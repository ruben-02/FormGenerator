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
$stmt = $db->prepare("SELECT datasource FROM forms WHERE id = ?");
$stmt->execute([$form_id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
$datasource = $r['datasource'] ?? 'sqlite';
$rows = [];
if ($datasource === 'mysql') {
    $mysql = get_mysql_pdo();
    if ($mysql) {
        $table = 'form_' . intval($form_id) . '_submissions';
        try {
            $q = $mysql->query("SELECT submission, created_at FROM `" . $table . "` ORDER BY created_at DESC LIMIT 200");
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) { $rows = []; }
    }
} else {
    $stmt = $db->prepare("SELECT submission, created_at FROM form_submissions WHERE form_id = ? ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$form_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

$mode = trim(strtolower($_POST['mode'] ?? 'insight'));
$allowed = ['insight','graph','table'];
if (!in_array($mode, $allowed, true)) $mode = 'insight';

$prompt = $summary . "\nAdmin query: " . $query . "\n";
if ($mode === 'insight') {
    $prompt .= "Answer succinctly and include examples or counts when appropriate. Respond in plain text, no markdown.";
} elseif ($mode === 'graph') {
    $prompt .= "SYSTEM INSTRUCTION: The user requested a 'graph' output. Respond with JSON only (no explanatory text or markdown) with keys: chartType (string, e.g., 'ColumnChart' or 'LineChart'), columns (array of objects {label,type} where type is 'string' or 'number'), rows (array of arrays matching columns), options (object for chart options). Example: {\"chartType\":\"ColumnChart\",\"columns\":[{\"label\":\"Answer\",\"type\":\"string\"},{\"label\":\"Count\",\"type\":\"number\"}],\"rows\":[[\"Yes\",12],[\"No\",5]],\"options\":{\"title\":\"Responses\"}}. Do not include any other text.";
} else {
    $prompt .= "SYSTEM INSTRUCTION: The user requested a 'table' output. Respond with JSON only (no explanatory text or markdown) with keys: headers (array of column names), rows (array of arrays), and optional pageSize (number). Example: {\"headers\":[\"Field\",\"Value\"],\"rows\":[[\"A\",\"1\"],[\"B\",\"2\"]],\"pageSize\":10}. Do not include any other text.";
}

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

// Clean the assistant output to remove markdown and unwanted symbols (keep SQL extraction above intact)
function clean_assistant_text($text){
    if (!is_string($text)) return '';
    $clean = $text;
    // Remove fenced code blocks but keep their content
    $clean = preg_replace('/```[a-zA-Z0-9_-]*\n(.*?)```/is', '$1', $clean);
    // Remove any remaining backticks
    $clean = str_replace('`', '', $clean);
    // Remove asterisks used for emphasis
    $clean = str_replace('*', '', $clean);
    // Remove common bullet characters at line starts
    $clean = preg_replace('/^[\s]*[\-\*\+•]\s+/m', '', $clean);
    // Remove visual separators like --- or *** on their own lines
    $clean = preg_replace('/^[\-\*]{3,}\s*$/m', '', $clean);
    // Normalize multiple blank lines
    $clean = preg_replace("/\n{3,}/", "\n\n", $clean);
    // Trim whitespace
    $clean = trim($clean);
    return $clean;
}

$clean_answer = clean_assistant_text($answer);

$response = ['sql_result' => $sql_result];
if ($mode === 'graph' || $mode === 'table') {
    // Try to extract JSON payload from assistant output (strip code fences first)
    $maybe = $answer;
    $maybe = preg_replace('/^\s*```(?:json)?\s*/i', '', $maybe);
    $maybe = preg_replace('/\s*```\s*$/', '', $maybe);
    $decoded = json_decode($maybe, true);
    if (is_array($decoded)) {
        $response['type'] = $mode;
        $response['payload'] = $decoded;
    } else {
        // fallback to plain insight text
        $response['type'] = 'insight';
        $response['answer'] = $clean_answer;
    }
} else {
    $response['type'] = 'insight';
    $response['answer'] = $clean_answer;
}

header('Content-Type: application/json');
echo json_encode($response);
exit;

?>
