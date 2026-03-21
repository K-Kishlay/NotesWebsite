# NotesPro Setup

1. Start Apache and MySQL in XAMPP.
2. Import database schema: open phpMyAdmin and run `sql/setup.sql`.
3. Open the app: `http://localhost/NOTES%20WEBSITE/`.
4. Default admin login:
   - Email: `admin@example.com`
   - Password: `admin123`

## Config
If your MySQL credentials are different, edit:
- `config.php`

Also configure:
- `NOTE_PRIVATE_DIR` for protected note files
- `MAIL_FROM` and `MAIL_FROM_NAME` for admin email broadcasts
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE` for functional SMTP email sending

## Role behavior
- `student`/`educator`: browse notes, add to cart, checkout, access library.
- `admin`: all user actions + upload notes + view sales and user metrics.

## Security and Protection
- Notes uploaded by admin are now stored in private storage and served via `download.php` only after access checks.
- Legacy note files in `uploads/` are protected by `uploads/.htaccess`.
- CSRF protection is enabled for critical POST actions.

## New feature
- Like system: users can like/unlike notes; counts are visible on marketplace and notes listing.
