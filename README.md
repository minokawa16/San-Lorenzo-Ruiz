# Parish Management System
## AI-Powered Web-Based Parish Request and Sacramental Records Management System

### Overview
A comprehensive parish management system that digitizes and streamlines parish operations including:
- Parishioner registration and authentication
- Sacramental certificate requests (Baptismal, Confirmation, First Communion, Marriage)
- Church services requests (Blessings, Reservations)
- Sacramental records management
- Announcements and scheduling
- Admin management and reporting

### Features

#### User (Parishioner) Features
- ✅ User Registration and Login
- ✅ Request Sacramental Certificates
- ✅ Submit Special Requests (Blessings, Reservations)
- ✅ Track Request Status
- ✅ View Church Announcements
- ✅ View Schedule and Events
- ✅ Download Approved Certificates (Optional)
- ✅ Notification System

#### Admin Features
- ✅ User Management (Active/Inactive)
- ✅ Request Approval/Rejection
- ✅ Sacramental Records Management (CRUD)
- ✅ Instant Search in Records
- ✅ Certificate Generation
- ✅ Announcement Management
- ✅ Reservation Management
- ✅ Reports and Analytics
- ✅ Audit Logging

### Technology Stack
- **Frontend**: HTML5, CSS3, Bootstrap 5, JavaScript
- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+
- **Server**: Apache (XAMPP)  
- **Additional**: AJAX for dynamic content

### Installation & Setup

#### Prerequisites
- XAMPP installed and running
- Apache and MySQL services active
- Browser with JavaScript enabled

#### Step 1: Download/Clone Project
```bash
cd c:\xampp\htdocs\
# Extract or clone project to ParishSystem directory
```

#### Step 2: Start XAMPP
- Open XAMPP Control Panel
- Start Apache
- Start MySQL

#### Step 3: Run Database Setup
1. Open your browser
2. Navigate to: `http://localhost/ParishSystem/database/install.php`
3. Wait for success message

#### Step 4: Access the System
- Open browser
- Go to: `http://localhost/ParishSystem/`
- You will be redirected to login page

#### Step 5: Login
**Default Admin Credentials:**
- Email: `admin@parish.com`
- Password: `admin123`

### Project Structure
```
ParishSystem/
├── admin/                      # Admin panel pages
│   ├── dashboard.php          # Admin dashboard
│   ├── manage-requests.php    # Request management
│   ├── manage-records.php     # Records management
│   ├── manage-users.php       # User management
│   ├── manage-announcements.php
│   └── manage-certificates.php
├── users/                      # User panel pages
│   ├── dashboard.php          # User dashboard
│   ├── request-certificate.php
│   ├── request-blessing.php
│   ├── make-reservation.php
│   └── my-requests.php
├── auth/                       # Authentication
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   └── profile.php
├── database/                   # Database configuration
│   ├── config.php             # DB connection
│   ├── setup.sql              # Database schema
│   └── install.php            # Setup installer
├── includes/                   # Helper functions
│   ├── helpers.php
│   └── auth-functions.php
├── templates/                  # Reusable templates
│   ├── header.php
│   └── footer.php
├── assets/                     # Static files
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── main.js
│   ├── images/
│   └── uploads/
└── index.php                   # Entry point
```

### Database Tables

#### Core Tables
1. **users** - System users (admin/parishioner)
2. **requests** - Certificate and service requests
3. **baptism_records** - Baptism information
4. **first_communion_records** - First Communion records
5. **confirmation_records** - Confirmation records
6. **marriage_records** - Marriage information

#### Support Tables
7. **announcements** - Church announcements
8. **reservations** - Church reservations
9. **certificate_templates** - Certificate design templates
10. **notifications** - User notifications
11. **audit_log** - System activity log

### Key Features Implementation

#### 1. Authentication System
- Secure login/registration
- Password hashing with bcrypt
- Session management
- Role-based access control

#### 2. Request Processing Workflow
```
User Submits Request 
    ↓
Auto-generates Reference Number
    ↓
Admin Reviews Request
    ↓
Admin Approves/Rejects
    ↓
System Updates Status
    ↓
User Receives Notification
```

#### 3. Sacramental Records Search
- Instant search by name, date, year
- Filter by record type
- Archive functionality
- Audit trail

#### 4. Certificate Generation
- Automated template-based generation
- Customizable templates
- Admin approval workflow
- Print-ready format
- Optional PDF export

### Usage Guide

#### For Parishioners
1. **Register** - Visit login page, click "Register here"
2. **Request Certificate** - Dashboard → New Request
3. **Track Status** - My Requests section
4. **View Announcements** - Dashboard home
5. **Make Reservation** - Quick Actions → Make Reservation

#### For Admin
1. **Login** - Use admin credentials
2. **Review Requests** - Dashboard → Pending Requests
3. **Approve/Reject** - Click Review button
4. **Manage Records** - Search and edit sacramental records
5. **Generate Reports** - Reports section

### Security Features
- ✅ SQL Injection protection (Prepared statements)
- ✅ Password hashing (bcrypt)
- ✅ Session security
- ✅ Input validation and sanitization
- ✅ Role-based access control
- ✅ CSRF protection (implementable)
- ✅ Audit logging

### Future Enhancements
- [ ] PDF certificate generation (TCPDF/DomPDF)
- [ ] Email notifications (PHPMailer)
- [ ] SMS notifications
- [ ] AI Chatbot for FAQs
- [ ] Advanced search with filters
- [ ] Multi-language support
- [ ] Mobile app
- [ ] Payment integration
- [ ] Online appointment booking
- [ ] Automated reports

### API Endpoints (To Be Implemented)
```
POST /api/requests/create
GET /api/requests/{id}
POST /api/records/search
GET /api/announcements
POST /api/reservations/check-availability
```

### Error Handling
- Database connection errors
- Validation errors
- Access control errors
- File upload errors
- All errors logged to audit_log table

### Performance Optimization
- Indexed database queries
- Pagination for large datasets
- Query optimization
- Caching (to be implemented)

### Troubleshooting

#### Database Connection Error
- Verify MySQL is running
- Check database credentials in `database/config.php`
- Run `database/install.php` again

#### Login Issues
- Clear browser cache
- Check database connection
- Verify user exists and is active

#### 404 Errors
- Verify Apache is running
- Check project path: `c:\xampp\htdocs\ParishSystem\`
- Verify mod_rewrite is enabled

### Support & Contact
For issues or feature requests, contact system administrator.

### License
© 2026 Parish Management System. All rights reserved.

### Credits
Built with:
- PHP 7.4+
- MySQL 5.7+
- Bootstrap 5
- jQuery
- Font Awesome Icons

---

**Version**: 1.0  
**Last Updated**: May 2026  
**Status**: Active Development
