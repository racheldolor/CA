# Capstone Culture and Arts Management System

A PHP + MySQL web system for managing campus culture and arts operations. It covers student artist applications, events and participation, announcements, inventory and borrowing, and role-based dashboards for administrators, heads, director, and students.

## Roles and Portals
- Admin: full system management and user administration.
- Head/Director: campus management, student profiles, events, announcements, and inventory/borrowing oversight.
- Student: profile management, event participation, announcements, and borrowing requests.

## Core Modules
- Authentication with role-based routing.
- Student artist applications and profiles.
- Events and participation tracking.
- Announcements with audience targeting.
- Inventory management and borrowing requests.
- PDF exports for student profiles (TCPDF).
- Admin action logging.

## Tech Stack
- PHP 8.x
- MySQL/MariaDB
- HTML/CSS/JavaScript
- TCPDF (PDF generation)

## Local Setup (XAMPP)
1. Place the project in your XAMPP htdocs folder (e.g., `C:\xampp\htdocs\capstone`).
2. Start Apache and MySQL in XAMPP.
3. Create a database named `capstone_culture_arts`.
4. Import the schema and seed data from `FINALDATABASE.sql`.
5. Update database credentials in `config/database.php`.
6. Ensure the `uploads/` directory is writable by the web server.
7. Install PHPMailer from the project root: `composer install --no-dev`.
8. Configure the SMTP environment variables below in Apache/PHP-FPM or the hosting control panel.
9. Run `database/2026_11_otp_migration.sql` once against `capstone_culture_arts`.
10. For an existing Supabase database created from an earlier schema, run `database/2026_11_fix_updated_at_triggers.sql` once to remove invalid `updated_at` triggers.
11. Open the app in a browser:
   - `http://localhost/capstone/`

## Database
The database schema is provided in `FINALDATABASE.sql` and includes tables for:
- Users and student artists
- Applications
- Announcements
- Events and participation
- Inventory and borrowing requests
- Logs and audit trails

## Entry Points
- `index.php`: login and role-based redirect
- `admin/`: admin dashboard and management actions
- `head-staff/`: head/director dashboard and operations
- `student/`: student dashboard and self-service flows

## Email OTP Setup

Registration and password recovery use PHPMailer with Gmail SMTP. Enable two-step verification on `oca.notification@gmail.com`, create a Google App Password, and do not use the normal Gmail password. Configure these values outside the repository:

```text
OCA_SMTP_HOST=smtp.gmail.com
OCA_SMTP_PORT=587
OCA_SMTP_ENCRYPTION=tls
OCA_SMTP_USERNAME=oca.notification@gmail.com
OCA_SMTP_PASSWORD=the-google-app-password
OCA_MAIL_FROM=oca.notification@gmail.com
OCA_MAIL_FROM_NAME=OCA Notification System
OCA_TEST_RECIPIENT=spamdummy11@gmail.com
```

`.env.example` documents the variables, but PHP does not load `.env` files automatically. Set them in the web-server environment and keep real values out of Git. `OCA_TEST_RECIPIENT` is a temporary exact-address exception for testing; remove it after testing. The migration creates `otp_challenges` and adds the pending registration password hash column to `applications`. OTP values are hashed, expire after five minutes, allow five attempts, and can be resent after sixty seconds. Real SMTP delivery still requires the Gmail App Password.

## Notes
- Sessions are used for authentication and access control.
- Some modules auto-create tables if missing (e.g., borrowing requests).

## License
Internal academic project (no public license specified).
