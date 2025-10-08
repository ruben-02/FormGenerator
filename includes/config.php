<?php
// ...existing code...
$env = @parse_ini_file(__DIR__ . '/../.env'); // @ to suppress warning if missing

$GEMINI_API_KEY = getenv('GEMINI_API_KEY') ?: ($env['GEMINI_API_KEY'] ?? null);
$GEMINI_MODEL   = getenv('GEMINI_MODEL') ?: ($env['GEMINI_MODEL'] ?? "models/gemini-2.5-flash");
// ...existing code...
?>