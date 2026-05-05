# Zoey's Billiard Hall Management System (PHP + MySQL)

A modern, comprehensive **Billiard Hall Management System** using **PHP**, **MySQL (XAMPP)**, **HTML/CSS**, and **JavaScript** with a premium dark theme UI.

## Features

- **User Roles**: Admin (full access) & Cashier (operations).
- **Multiple Table Types**: 
  - Regular Billiard Tables (with Early Bird promos)
  - VIP Tables (with Karaoke Add-ons)
  - KTV Rooms
  - Kubo Rentals (Flat rate tracking)
- **Advanced Game Sessions**:
  - Global visual and audio alarms for timeouts (10-min warning & Time's Up).
  - Open Time vs. Fixed Hours.
  - Extend Time functionality.
  - Custom HTML modals for actions (Start, Extend, End, Void).
- **Reservations Module**: Schedule and track table reservations with down payments.
- **Void Management System**: Secure voiding with required reasons. Audited and separated from income reports.
- **Reporting & Exports**:
  - Daily & Weekly Income Charts.
  - Detailed Excel (.xls) exports for: Transactions, Reservations, Kubo Rentals, and Dead Time.
- **Security**: Built using secure PDO prepared statements, auto-logout on inactivity, and password hashing.

---

## 🚀 How to Transfer / Install to a New PC

Since this system uses XAMPP, moving it to a new PC is extremely simple.

### Method 1: The "1-Click ZIP" Method (Fastest)
If you want to move everything (including the current database and records) to a new PC:
1. On the **OLD PC**, stop Apache and MySQL in XAMPP.
2. Go to `Local Disk (C:)` and copy/ZIP the entire `xampp` folder.
3. Transfer the ZIP to the **NEW PC**.
4. Extract it directly to `Local Disk (C:)` so the path becomes `C:\xampp`.
5. Open `C:\xampp\htdocs\Billiard System` and double-click `launch_billiards.bat` to run the system like a desktop app.

### Method 2: Fresh Installation
If you want to install a fresh, blank copy of the system on a new PC:
1. Install **XAMPP** on the new PC.
2. Copy this `Billiard System` folder into `C:\xampp\htdocs\`.
3. Open XAMPP Control Panel and start **Apache** and **MySQL**.
4. Open your browser and go to the master installer:
   👉 `http://localhost/Billiard%20System/scripts/install.php`
5. The installer will automatically create the database, tables, columns, and default accounts.
6. **Login Accounts:**
   - Admin: `admin` / `admin123`
   - Cashier: `cashier` / `cashier123`
7. *(Optional)* Delete `install.php` after setup for security.

---

## 📂 Core File Structure

- `dashboard.php` — Live overview, charts, active tables
- `tables.php` — Regular billiard operations
- `vip_tables.php` — VIP & KTV room operations (with Karaoke)
- `reservations.php` — Booking system
- `kubo.php` — Kubo rental system
- `reports.php` — Audits, charts, and void logs
- `transactions.php` — Past payments and history
- `customers.php` — Customer loyalty tracking
- `receipt.php` — Printable digital receipts
- `scripts/install.php` — Master Auto-Installer & DB Migration
- `exports/` — Excel report generators
- `launch_billiards.bat` — 1-click startup script (App Mode)

---

## 🛠️ Notes for Developers

- **Database**: All operations use `$pdo->prepare()` from `config/database.php` to prevent SQL Injection.
- **Timers**: The frontend countdowns in `tables.php` and `vip_tables.php` sync with the server's `scheduled_end_time`.
- **Global Alarms**: Managed via `api_check_timeouts.php` and javascript in `includes/layout.php`.
- **Timezones**: Set to `Asia/Manila` in `database.php`.
