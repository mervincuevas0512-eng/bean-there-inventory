# Bean There Cafe - Web-Based Inventory Management and Stock Monitoring System

An academic case study prototype for monitoring coffee shop ingredients and supplies. Built with PHP, MariaDB/MySQL, HTML, CSS, and JavaScript. This is a source-code repository; the application runs locally through XAMPP.

## Features

- Owner and Staff accounts with role-based permissions
- Inventory and category management
- Stock In, Stock Out, and physical stock adjustment
- Stock batches, expiration monitoring, and first-expired-first-out (FEFO)
- Low-stock alerts, Activity Log, Reports, and printing
- Staff account management and expiration alert settings
- Responsive desktop, tablet, and mobile interface

## Local installation

1. Install XAMPP with PHP 8.1 or newer and enable PDO MySQL and mbstring.
2. Clone or download the repository into C:\xampp\htdocs\bean-there-inventory.
3. Copy app/config.example.php to app/config.php. Configure your own database credentials. The root/empty-password example is for default local XAMPP only.
4. Start Apache and MySQL. For initial setup, the configured account must have permission to create the database and tables.
5. Open http://localhost/bean-there-inventory/setup.php and create the first Owner account.
6. Sign in at http://localhost/bean-there-inventory/.

Setup initializes the database from database/schema.sql and prevents a second Owner setup. There are no bundled real login credentials or existing database records.

Optional local demonstration data can be generated after Owner setup:
C:\xampp\php\php.exe C:\xampp\htdocs\bean-there-inventory\database\seed_inventory_examples.php

The sample data is illustrative, not verified actual cafe stock.

## Inventory workflow

The Owner registers ingredients and supplies with categories, units, item codes, and low-stock thresholds. Authorized personnel record incoming deliveries through Stock In and usage or disposal through Stock Out. FEFO deducts from the earliest-expiring usable batches. Waste and damage require selecting the affected batch. The Owner uses Adjust Stock to reconcile physical counts. The dashboard, activity records, and reports reflect stock movements.

## Project structure

- index.php: application interface
- setup.php: initial Owner registration
- actions.php: server-side form processing
- app/: database, session, and inventory helpers
- assets/: styles, scripts, and logo
- database/: database schema and optional sample data

## Verification

Local testing covered account creation and access, item creation, duplicate SKU rejection, stock receipts and deductions, FEFO, insufficient stock prevention, physical count adjustment, expiration tracking, and reports. Responsive testing used Chrome viewport simulations from 320px to 2560px, including landscape screens. This is not exhaustive testing across all physical devices, browsers, or production loads.


## Scope and privacy

Inventory monitoring only; not a POS or sales system. On-screen movement reports show up to 1,000 entries for the selected date range. Real account data, local database credentials, screenshots, and live database exports are excluded. For deployment outside localhost, use HTTPS, restricted database privileges, and additional security and load testing.