# PLMun School Events Ticketing System

A professional starter project for a PLMun-focused school event ticketing system using PHP, MySQL, HTML, CSS, and JavaScript.

## Requirements
- PHP 8.1+
- MySQL 8+ or MariaDB
- XAMPP (Apache + MySQL) or equivalent
- Modern browser

## Setup (XAMPP)
1. Copy the `PLMun_Ticketing_System` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL from XAMPP.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`.
4. Import `database/schema.sql`.
5. Open `config/db.php` and check your database credentials.
6. Visit `http://localhost/PLMun_Ticketing_System/`.

## Email verification
New accounts must confirm their email before they can sign in.

1. **Database:** a fresh import of `database/schema.sql` already includes the new columns. If you already imported the database earlier, run `database/migration_email_verification.sql` once in phpMyAdmin (existing accounts are marked verified so they can still sign in).
2. **Email settings:** open `config/mail.php` and fill in your SMTP details. For Gmail, create an App Password at https://myaccount.google.com/apppasswords (needs 2-Step Verification) and use it as `password`. Also check `base_url` matches the address you use in the browser.
3. **No email yet?** Set `'mode' => 'log'` in `config/mail.php`. Links are then written to `storage/mail.log` instead of being emailed. Switch back to `'smtp'` for real use.

Flow: register → email with a link valid for 24 hours → `verify.php` activates the account → sign in. Unverified users can request a new link at `resend-verification.php` (one per 60 seconds). Only a hash of the token is stored in the database. PHPMailer 6.9.3 is bundled in `lib/PHPMailer/` (LGPL), so Composer is not needed.

## Demo admin
After importing the database, create an admin account through registration, then update that account's `role` to `admin` in phpMyAdmin:
```sql
UPDATE users SET role='admin' WHERE email='your-admin-email@example.com';
```
Only do this for an account you control. Do not leave a public registration flow that allows users to self-assign admin roles.

## Included in this starter
- Responsive public events page
- Registration and login
- Password hashing with `password_hash()`
- Session-based authentication
- Admin dashboard and event management
- User booking flow with a short seat hold
- Basic seat availability display
- Database transactions and unique event-seat booking constraint
- Demo ticket reference and QR payload
- CSV report export
- Activity log table

## Important limitations
This is a local-development starter, not a production-ready payment platform. It does not charge real money. The booking flow creates a demo booking marked `pending`; an admin can mark it paid in the admin panel. Before public deployment, add a real payment provider with server-side webhook verification, email delivery, CSRF protection across all forms, rate limiting, HTTPS, backups, and a security review. QR display is a payload placeholder; add a QR-generation library and a camera scanner workflow before using real event entry.
