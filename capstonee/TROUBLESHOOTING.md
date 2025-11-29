# Troubleshooting HTTP 500 Error

## Step 1: Check Debug File
First, visit: `https://bsu-clinic.web-systems.tech/debug.php`

This will show you the exact error. Common issues:
- Database connection failed
- Missing PHP extensions
- File permission issues
- PHP syntax errors

## Step 2: Test with Minimal .htaccess

If the `.htaccess` file is causing the error:

1. **Rename current .htaccess:**
   - In File Manager, rename `.htaccess` to `.htaccess.backup`

2. **Upload minimal version:**
   - Upload `.htaccess.minimal` and rename it to `.htaccess`
   - Or create a new `.htaccess` with just:
   ```
   DirectoryIndex index.php
   ```

3. **Test again:**
   - Visit `https://bsu-clinic.web-systems.tech/`
   - If it works, the issue was with .htaccess

## Step 3: Check PHP Error Logs

In Hostinger:
1. Go to **Files** → **File Manager**
2. Look for error logs (usually in `logs/` or `error_log`)
3. Check for PHP fatal errors

## Step 4: Common Fixes

### Database Connection Issue
- Verify credentials in `config/database.php`
- Make sure database exists in Hostinger
- Check database user permissions

### Missing PHP Extensions
Hostinger usually has these enabled, but check:
- mysqli
- gd (for image processing)
- json
- mbstring

### File Permissions
Set these via File Manager or SSH:
```
chmod 644 *.php
chmod 755 uploads
chmod 755 assets
```

### PHP Version
- Check PHP version in Hostinger (should be 7.4+)
- Some hosts use PHP 8.x which may have compatibility issues

## Step 5: Test Direct Access

Try accessing files directly:
- `https://bsu-clinic.web-systems.tech/index.php` (should work)
- `https://bsu-clinic.web-systems.tech/debug.php` (should show debug info)

If `index.php` works but root URL doesn't, it's an .htaccess issue.

## Step 6: Disable .htaccess Temporarily

1. Rename `.htaccess` to `.htaccess.disabled`
2. Test the site
3. If it works, gradually add rules back to .htaccess

## Still Having Issues?

Check:
1. ✅ Database credentials correct
2. ✅ All files uploaded correctly
3. ✅ File permissions set
4. ✅ PHP version compatible
5. ✅ No syntax errors in PHP files
6. ✅ debug.php shows specific error

