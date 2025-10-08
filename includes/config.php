<?php
$envFile = __DIR__ . '/../.env';
$env = file_exists($envFile) ? @parse_ini_file($envFile) : [];

$GEMINI_API_KEY = $env['GEMINI_API_KEY'] ?? null;
$GEMINI_MODEL   = $env['GEMINI_MODEL'] ?? "models/gemini-2.5-flash"; // default if not set
?>
