# Users Interface - Setup & Access Guide

## Issues Fixed ✓

The following errors have been resolved:

### 1. **Database Configuration Constants**
- **Issue**: Mismatched constant names (`DB_HOST` vs `DB_SERVER`, `DB_PASS` vs `DB_PASSWORD`)
- **Fix**: Updated `database/config.php` to define all variations for compatibility
- **Status**: ✅ FIXED

### 2. **Database Connection Management**
- **Issue**: `users/index.php` was creating its own connection instead of using the global `$conn`
- **Fix**: Updated to use the global database connection
- **Status**: ✅ FIXED

### 3. **Database Setup**
- **Issue**: Database may not exist
- **Solution**: Automatic installation on first access OR manual setup
- **Status**: ✅ READY

---

## Step-by-Step Setup Instructions

### Step 1: Ensure Database Exists
Run one of the following:

**Option A: Automatic Setup (Recommended)**
1. Open browser and navigate to: `http://localhost/ParishSystem/database/install.php`
2. The script will create the database and tables automatically
3. You should see: "Database setup completed successfully!"

**Option B: Manual Setup via phpMyAdmin**
1. Open phpMyAdmin at `http://localhost/phpmyadmin`
2. Import the file: `database/setup.sql`
3. Database name: `parish_management_system`

### Step 2: Access the Login Page
Navigate to: `http://localhost/ParishSystem/`

### Step 3: Login Credentials

**Admin Account** (for testing admin features):
- Email: `admin@parish.com`
- Password: `admin123`

**Create User Account** (for parishioners):
- Click "Don't have an account? Register here"
- Fill in your details
- Account will be created with 'user' role

### Step 4: Access Users Dashboard
After login with a user account:
1. You'll be redirected to: `http://localhost/ParishSystem/users/`
2. Dashboard shows:
   - Recent requests
   - Pending requests
   - Approved requests
   - System notifications

---

## Users Interface Features

### Available Functions:

1. **Dashboard** (`/users/index.php`)
   - View statistics
   - Access quick links
   - System notifications

2. **My Requests** (`/users/my-requests.php`)
   - View all submitted requests
   - Check request status
   - Track processing

3. **Request Certificate** (`/users/request-certificate.php`)
   - Submit certificate requests
   - Choose request type
   - Add description

4. **Request Blessing** (`/users/request-blessing.php`)
   - Submit blessing requests
   - House blessing
   - Car blessing
   - Other services

5. **View Schedule** (`/users/view-schedule.php`)
   - Church schedules
   - Available time slots
   - Make reservations

6. **My Profile** (`/auth/profile.php`)
   - Update profile information
   - Change password
   - Manage settings

---

## Troubleshooting

### Error: "Undefined constant 'DB_SERVER'"
✅ **RESOLVED** - Database constants have been updated

### Error: "Connection failed: Unknown database"
1. Run the installation script: `http://localhost/ParishSystem/database/install.php`
2. Check MySQL is running (XAMPP Control Panel)

### Error: "Access Denied for user 'root'@'localhost'"
1. Check MySQL credentials in `database/config.php`
2. Default is: User=`root`, Password=`` (empty)
3. Update if your MySQL requires a password

### Page shows blank or "Database connection failed"
1. Verify MySQL service is running
2. Check `database/config.php` settings
3. Review server error logs

---

## Database Structure

**Main Tables:**
- `users` - User accounts and profiles
- `requests` - Certificate and service requests
- `baptism_records` - Baptism records
- `confirmation_records` - Confirmation records
- `first_communion_records` - First communion records
- `marriage_records` - Marriage records
- `announcements` - Parish announcements
- `certificates` - Generated certificates

---

## Security Notes

- Passwords are securely hashed
- Session timeouts enabled (30 minutes)
- CSRF tokens on all forms
- SQL injection prevention (prepared statements)
- Input sanitization on all user inputs

---

## Next Steps

1. ✅ Database is configured
2. ✅ Users interface is ready
3. 📋 Run `database/install.php` to initialize database
4. 🔐 Login with credentials
5. 📊 Access users dashboard

---

**System Ready for Use!** 🎉

The Parish Request and Sacramental Records Management System is now configured and ready for users to access their dashboard and manage requests.
