# Hostinger Deployment Checklist

## ✅ Completed Steps

1. **Database Configuration** - Updated with Hostinger credentials
2. **Hardcoded Paths Fixed** - All `/capstone(foreal this time)/` paths replaced with relative paths:
   - ✅ `includes/header.php` - Fixed all navigation links
   - ✅ `modules/forms/medical_form.php` - Fixed dashboard links
   - ✅ `modules/forms/dental_form.php` - Fixed dashboard links
   - ✅ `modules/forms/history_form.php` - Fixed dashboard links
   - ✅ `modules/user_medical_form.php` - Fixed dashboard links
   - ✅ `modules/user_dental_form.php` - Fixed dashboard links
   - ✅ `modules/user_history_form.php` - Fixed dashboard links
   - ✅ `modules/qrcode/generate.php` - Fixed QR code URL generation

## 🔧 Next Steps on Hostinger

### 1. Upload Files
- ✅ All files uploaded to `public_html`

### 2. Set File Permissions
Run these commands in File Manager or via SSH:
```bash
chmod 755 public_html
chmod 644 public_html/*.php
chmod 755 public_html/uploads
chmod 755 public_html/uploads/pictures
chmod 755 public_html/assets
chmod 755 public_html/assets/qrcodes
```

### 3. Database Setup
- ✅ Database created in Hostinger
- ✅ Database credentials updated in `config/database.php`
- ⚠️ **IMPORTANT**: Make sure the database tables are created. If not, the `database.php` file will auto-create them on first access.

### 4. Test the Site
1. Visit: `https://bsu-clinic.web-systems.tech/debug.php`
   - This will show you any remaining issues
   - **DELETE THIS FILE AFTER TESTING** for security

2. Visit: `https://bsu-clinic.web-systems.tech/index.php`
   - Should load the login page
   - Test login with admin credentials

### 5. Common Issues & Solutions

#### HTTP 500 Error
- Check `debug.php` for specific errors
- Verify database credentials in `config/database.php`
- Check file permissions (see step 2)
- Check PHP error logs in Hostinger control panel

#### Database Connection Failed
- Verify database name, username, password in `config/database.php`
- Make sure database user has proper permissions
- Check if database host is `localhost` (usually correct for Hostinger)

#### File Upload Not Working
- Check `uploads/pictures/` directory exists
- Set permissions: `chmod 755 uploads/pictures/`
- Verify PHP `upload_max_filesize` and `post_max_size` settings

#### Images/CSS Not Loading
- Check file paths are relative (not absolute)
- Verify `assets/` folder is uploaded correctly
- Check browser console for 404 errors

### 6. Security Checklist
- [ ] Delete `debug.php` after testing
- [ ] Remove any test files (test_db.php, test_upload.php, etc.)
- [ ] Set proper file permissions (see step 2)
- [ ] Change default admin password
- [ ] Enable HTTPS (SSL certificate)

### 7. Default Login Credentials
After database setup, you can login with:
- **Username**: `admin`
- **Password**: `admin123`

**⚠️ CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN!**

## 📝 Files Modified for Deployment

1. `config/database.php` - Database credentials
2. `includes/header.php` - Dynamic path calculation
3. `modules/forms/*.php` - Relative paths for dashboard links
4. `modules/user_*.php` - Relative paths for dashboard links
5. `modules/qrcode/generate.php` - Dynamic URL generation

## 🆘 Need Help?

If you encounter issues:
1. Check `debug.php` output
2. Check Hostinger error logs
3. Verify all files are uploaded correctly
4. Check file permissions
5. Verify database connection settings

