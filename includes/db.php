<?php
// db.php - SQLite connection
$db = new PDO("sqlite:forms.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Forms table
$db->exec("CREATE TABLE IF NOT EXISTS forms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    prompt TEXT,
    form_code TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Ensure username column exists for user-dependent forms
$cols = $db->query("PRAGMA table_info(forms)")->fetchAll(PDO::FETCH_ASSOC);
$has_username = false;
$has_token = false;
 $has_datasource = false;
foreach ($cols as $c) {
    if ($c['name'] === 'username') { $has_username = true; }
    if ($c['name'] === 'public_token') { $has_token = true; }
    if ($c['name'] === 'datasource') { $has_datasource = true; }
}
if (!$has_username) {
    $db->exec("ALTER TABLE forms ADD COLUMN username TEXT");
}
if (!$has_token) {
    $db->exec("ALTER TABLE forms ADD COLUMN public_token TEXT");
}
if (!$has_datasource) {
    // default to sqlite when not provided
    $db->exec("ALTER TABLE forms ADD COLUMN datasource TEXT DEFAULT 'sqlite'");
}

// Submissions table
$db->exec("CREATE TABLE IF NOT EXISTS form_submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id INTEGER,
    submission TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
?>
