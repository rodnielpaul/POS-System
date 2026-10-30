# POS System

A Point of Sale (POS) and inventory management web application built with PHP and MySQL.

## Features
- User authentication & role management (Admin, Cashier, etc.)
- POS checkout & order processing
- Inventory & product management
- Employee & payroll management
- Suppliers & reporting
- Activity logging

## Requirements
- PHP 7.4+ or PHP 8.x
- MySQL / MariaDB (e.g. XAMPP)
- Apache Web Server

## Setup
1. Clone or copy files into `htdocs/POS-System`.
2. Import the database schemas from the `database/` folder (or run `migrate.php` / `seed.php`).
3. Configure database connection settings in [config/database.php](config/database.php).
4. Access the application via `http://localhost/POS-System`.
