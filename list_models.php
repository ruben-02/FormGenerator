<?php
include "includes/config.php";

if (empty($GEMINI_API_KEY)) {
    die("Error: GEMINI_API_KEY not found in .env");
}

$endpoint = "https://generativelanguage.googleapis.com/v1/models?key=$GEMINI_API_KEY";

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

if (!$response) {
    die("cURL Error: " . curl_error($ch));
}

curl_close($ch);

$result = json_decode($response, true);

echo "<pre>";
print_r($result);
echo "</pre>";
