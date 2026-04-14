# Billiard Hall Management System (PHP + MySQL)

Simple, beginner-friendly **Billiard Hall Management System** using **PHP**, **MySQL (XAMPP)**, **HTML/CSS**, and **JavaScript** with a clean black & white theme.

## Requirements

- XAMPP (Apache + MySQL)
- PHP 8+ recommended

## Setup (XAMPP)

- **1) Copy project**
  - Put this folder inside: `C:\xampp\htdocs\Billiard System\`

- **2) Create database**
  - Start **Apache** + **MySQL** in XAMPP Control Panel.
  - Open `phpMyAdmin`.
  - Import `database.sql` (optional — installer can also create the schema).

- **3) Configure connection**
  - Edit `config/database.php` if needed:
    - DB: `billiard_system`
    - User: `root`
    - Pass: *(blank by default on XAMPP)*

- **4) Run installer (recommended)**
  - Open: `http://localhost/Billiard%20System/install.php`
  - This will:
    - Create tables (if missing)
    - Seed users + sample tables
  - After successful install, **delete** `install.php`.

- **5) Login**
  - Open: `http://localhost/Billiard%20System/`
  - Default accounts:
    - **admin / admin123**
    - **staff / staff123**

## Features

- **User roles**: admin, staff
- **Authentication**: login/logout, sessions, auto logout on inactivity
- **Dashboard**: active tables, ongoing games, daily income, loyalty points summary
- **Table management**: add/edit/delete tables (delete = admin only)
- **Game sessions**:
  - Start game (timer starts)
  - End game (auto time + billing)
  - Payment + change calculation
- **Customers**: add/edit/delete (delete = admin only)
- **Loyalty system**:
  - Earn: **1 point per ₱50 spent**
  - Redeem during payment: **1 point = ₱1 discount**
  - Loyalty card view
- **Transaction history**: filter by date/customer
- **Reports**: daily/weekly income, most used tables, top loyal customers
- **Receipt printing**: browser print dialog

## Pages / File structure

- `index.php` (login)
- `dashboard.php`
- `tables.php`
- `customers.php`
- `loyalty.php`
- `transactions.php`
- `receipt.php`
- `reports.php`
- `users.php` (admin only)
- `config/database.php`
- `config/auth.php`
- `assets/css/style.css`
- `assets/js/script.js`

## Notes

- All DB queries use **PDO prepared statements** to reduce SQL injection risk.
- Timer shown on `tables.php` is client-side display only; the **server** stores start/end time and calculates totals.

