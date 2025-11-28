# 🔴 URGENT: Fix Database Configuration

## The Problem
Your `config/database.php` file still has **localhost credentials** which won't work on Hostinger. This is causing the HTTP 500 error.

## How to Fix

### Step 1: Get Your Hostinger Database Credentials

1. Log into Hostinger
2. Go to **Websites** → **Databases**
3. Find your database (or create one if you haven't)
4. Note down:
   - **Database Name** (usually starts with `u` and numbers, like `u123456789_bsu_clinic_db`)
   - **Database Username** (usually same format, like `u123456789_dbuser`)
   - **Database Password** (the one you set)
   - **Database Host** (usually `localhost`)

### Step 2: Update config/database.php

Open `config/database.php` in File Manager and change these lines:

**BEFORE (WRONG):**
```php
$host = "localhost";
$username = "root";
$password = "";
$database = "bsu_clinic_db";
```

**AFTER (CORRECT - use YOUR actual values):**
```php
$host = "localhost";  // Usually "localhost" on Hostinger
$username = "u123456789_your_db_user";  // YOUR database username from Hostinger
$password = "your_actual_password";  // YOUR database password from Hostinger
$database = "u123456789_bsu_clinic_db";  // YOUR database name from Hostinger
```

### Step 3: Remove Database Creation Code

On Hostinger, you **cannot create databases** via PHP. The database must already exist.

**Remove or comment out this line:**
```php
// Remove this line - database should already exist in Hostinger
// $sql = "CREATE DATABASE IF NOT EXISTS $database";
// if ($conn->query($sql) !== TRUE) {
//     die("Error creating database: " . $conn->error);
// }
```

**Change to:**
```php
// Database should already exist in Hostinger
// Just select it
$conn->select_db($database);
```

### Step 4: Test

1. Upload the updated `config/database.php`
2. Visit: `https://bsu-clinic.web-systems.tech/simple_test.php`
3. It should show if the database connection works

## Quick Test

After updating, visit:
- `https://bsu-clinic.web-systems.tech/simple_test.php` - Basic test
- `https://bsu-clinic.web-systems.tech/test.php` - Database connection test
- `https://bsu-clinic.web-systems.tech/` - Main site

## Still Not Working?

If you still get errors:
1. Make sure the database exists in Hostinger
2. Make sure the database user has proper permissions
3. Check that you're using the exact username/password from Hostinger (case-sensitive)
4. Try accessing `simple_test.php` first to see what error you get

