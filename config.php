<?php
/*
|--------------------------------------------------------------------------
| Application
|--------------------------------------------------------------------------
*/
define('APP_NAME', 'NotesPro');
define('BASE_URL', '/NOTES WEBSITE');

/*
|--------------------------------------------------------------------------
| Database (XAMPP defaults)
|--------------------------------------------------------------------------
*/
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'notes_platform');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

/*
|--------------------------------------------------------------------------
| File Storage
|--------------------------------------------------------------------------
*/
// Keep this outside direct web access.
define('NOTE_PRIVATE_DIR', getenv('NOTE_PRIVATE_DIR') ?: 'C:/xampp/notes_private');

/*
|--------------------------------------------------------------------------
| Mail / SMTP
|--------------------------------------------------------------------------
*/
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'kishlaybhardwaj383@gmail.com');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'NotesPro Admin');

// Recommended for Gmail:
// SMTP_HOST=smtp.gmail.com
// SMTP_PORT=587
// SMTP_SECURE=tls
// SMTP_USER=your_email@gmail.com
// SMTP_PASS=your_16_char_app_password
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: 'kishlaybhardwaj383@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'kishlay383kk.bb');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls'); // tls | ssl | ''
define('SMTP_TIMEOUT', (int) (getenv('SMTP_TIMEOUT') ?: 20));

/*
|--------------------------------------------------------------------------
| PDF Import (MCQ Parser)
|--------------------------------------------------------------------------
*/
// Set this to Poppler pdftotext binary if not in PATH.
// Example (Windows): C:\\poppler\\Library\\bin\\pdftotext.exe
define('PDFTOTEXT_BIN', getenv('PDFTOTEXT_BIN') ?: 'pdftotext');
