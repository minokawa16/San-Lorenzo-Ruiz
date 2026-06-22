# E-REQUEST: Parish Management System
## Functional & Non-Functional Requirements

---

## 📋 FUNCTIONAL REQUIREMENTS
*What the system DOES - Core features and capabilities*

### 1. **Authentication & Authorization**
- [x] User registration with email verification
- [x] User login with role-based access (User/Admin)
- [x] Password hashing with bcrypt security
- [x] Session management (30-minute timeout)
- [x] Logout with complete session destruction
- [x] Profile picture upload capability
- [x] User role assignment (user or admin)

### 2. **User Dashboard**
- [x] View personal statistics (requests, reservations, notifications)
- [x] Access to announcements and events
- [x] View pending and completed requests
- [x] Download generated certificates
- [x] Track request status in real-time
- [x] Update user profile information
- [x] Quick access to all services

### 3. **Request Management (User Side)**
- [x] Submit certificate requests:
  - Baptismal certificates
  - Confirmation certificates
  - First Communion certificates
  - Marriage certificates
- [x] Submit blessing requests:
  - House blessings
  - Car blessings
- [x] Submit reservation requests:
  - Church venue reservations
  - Wedding reservations
  - Burial reservations
  - Baptism reservations
  - Confirmation/Communion reservations
- [x] View request history with status tracking
- [x] Receive admin responses and feedback
- [x] Generate reference numbers for tracking

### 4. **Request Management (Admin Side)**
- [x] View all submitted requests with filters
- [x] Review request details and descriptions
- [x] Approve or reject requests
- [x] Add admin responses with feedback
- [x] Track request status changes
- [x] Generate reference numbers
- [x] Mark requests as processing, approved, completed, or rejected

### 5. **Announcement System**
- [x] Post announcements (news, schedule, events, obituaries)
- [x] Add images to announcements
- [x] Set publication dates
- [x] Set expiry dates for auto-hiding
- [x] Display announcements on user dashboard
- [x] Categorize announcements by type
- [x] Activate/deactivate announcements
- [x] Admin-only posting capability

### 6. **Certificate Generation & Management**
- [x] Certificate template management by admins
- [x] Templates for 4 certificate types:
  - Baptismal certificates
  - Confirmation certificates
  - First Communion certificates
  - Marriage certificates
- [x] Dynamic field population ({{fullname}}, {{date}}, etc.)
- [x] PDF generation and download
- [x] Print-ready certificate formatting
- [x] Digital storage of generated certificates

### 7. **Reservation System**
- [x] Users make event reservations
- [x] Specify event date and time
- [x] Add event details
- [x] Admin approve/reject reservations
- [x] Prevent double-booking with unique constraints
- [x] Admin notes on reservations
- [x] Track reservation status (pending, approved, rejected, cancelled)
- [x] Calendar view of reserved dates

### 8. **Sacramental Records Archive**
- [x] Baptism records management
- [x] First Communion records management
- [x] Confirmation records management
- [x] Marriage records management
- [x] Link records to certificate requests
- [x] Mark records as active or archived
- [x] Search and filter records by:
  - Full name
  - Date range
  - Record type

### 9. **Notification System**
- [x] Generate notifications for status updates
- [x] Mark notifications as read/unread
- [x] Display notification count
- [x] Email notifications for important events
- [x] System-wide broadcast notifications
- [x] User-specific notifications

### 10. **User Management (Admin Only)**
- [x] Create new user accounts
- [x] Edit user information
- [x] Activate/deactivate user accounts
- [x] Assign user roles (user or admin)
- [x] View all user accounts
- [x] Search and filter users
- [x] Delete/archive user accounts

### 11. **Reporting & Analytics**
- [x] Generate request reports
- [x] View reservation statistics
- [x] Track system usage metrics
- [x] Generate audit reports
- [x] Export data for analysis
- [x] View request completion rates
- [x] Analyze user engagement

### 12. **Audit & Compliance**
- [x] Log all system actions
- [x] Track user, action, timestamp, IP address
- [x] Record before/after values for changes
- [x] Maintain immutable audit log
- [x] Enable compliance with church regulations
- [x] Track certificate generation for records
- [x] Document all approvals and rejections

### 13. **Search & Filter**
- [x] Search requests by reference number
- [x] Search users by email or name
- [x] Filter requests by status
- [x] Filter announcements by type
- [x] Search sacramental records by name
- [x] Filter records by date range
- [x] Advanced search with multiple criteria

---

## ⚙️ NON-FUNCTIONAL REQUIREMENTS
*How the system performs - Quality attributes*

### 1. **Security**
- [x] Password hashing with bcrypt (industry standard)
- [x] SQL injection prevention via prepared statements
- [x] CSRF token protection on all forms
- [x] Session timeout after 30 minutes of inactivity
- [x] Secure cookie handling (HttpOnly, SameSite flags)
- [x] Input validation and sanitization on all data
- [x] XSS (Cross-Site Scripting) protection
- [x] Role-based access control (RBAC)
- [x] Prevent unauthorized access to admin functions
- [x] Audit logging of all sensitive actions
- [x] Secure password storage
- [x] Rate limiting on login attempts

### 2. **Performance**
- [x] Database query optimization with proper indexes
- [x] Foreign key relationships for referential integrity
- [x] Pagination for large result sets
- [x] Caching mechanisms for frequently accessed data
- [x] Lazy loading for images and large content
- [x] CSS and JavaScript minification
- [x] Asynchronous operations where applicable
- [x] Database connection pooling
- [x] Query execution time monitoring

### 3. **Availability & Reliability**
- [x] Error handling with graceful degradation
- [x] System doesn't crash on database errors
- [x] Default values for failed queries
- [x] Comprehensive error messages for debugging
- [x] Automatic error logging to files
- [x] Session persistence across page reloads
- [x] Transaction rollback on errors
- [x] Data backup and recovery procedures
- [x] Redundancy in critical systems

### 4. **Usability**
- [x] Intuitive user interface design
- [x] Responsive design (mobile, tablet, desktop)
- [x] Bootstrap 5.3.0 framework for consistency
- [x] Clear navigation menus
- [x] Breadcrumb navigation
- [x] Helpful error messages
- [x] Clear form labels and placeholders
- [x] Status indicators for request progress
- [x] Confirmation dialogs for destructive actions
- [x] Search functionality on all list pages
- [x] Help documentation and tooltips

### 5. **Scalability**
- [x] Database designed for growth (auto-increment IDs)
- [x] Normalized schema reduces data redundancy
- [x] Foreign keys enable data consistency
- [x] Indexes on frequently queried columns
- [x] Architecture supports multi-admin setup
- [x] Room for additional roles and permissions
- [x] Modular code structure for extensibility
- [x] API-ready design for future integration
- [x] Can handle thousands of requests monthly

### 6. **Maintainability & Code Quality**
- [x] MVC-like architecture for organization
- [x] Separation of concerns (includes/, database/, admin/, users/)
- [x] Consistent naming conventions
- [x] Code comments and documentation
- [x] Configuration file centralization (config.php)
- [x] Helper functions for code reuse
- [x] Logger class for consistent logging
- [x] Session management in separate file
- [x] Easy to add new features
- [x] Backward compatibility maintained

### 7. **Data Integrity**
- [x] Foreign key constraints enforced
- [x] CASCADE deletes for data consistency
- [x] Unique constraints on critical fields (email, reference_number)
- [x] ENUM types prevent invalid values
- [x] Timestamp automatic updates
- [x] Transactional operations for multi-step processes
- [x] Data validation before storage
- [x] Immutable audit log records

### 8. **Compatibility**
- [x] PHP 7.4+ compatibility
- [x] MySQL 5.7+ compatibility
- [x] Chrome, Firefox, Safari, Edge browsers
- [x] Windows, Linux, macOS servers
- [x] XAMPP stack support
- [x] Standard HTML5 compliance
- [x] CSS3 compatibility
- [x] JavaScript ES6 support

### 9. **Accessibility**
- [x] Semantic HTML for screen readers
- [x] ARIA labels where needed
- [x] Proper heading hierarchy
- [x] Alt text for images
- [x] Keyboard navigation support
- [x] Color contrast compliance
- [x] Form accessibility with proper labels
- [x] Error message accessibility

### 10. **Compliance & Standards**
- [x] GDPR compliance for user data
- [x] Church regulatory compliance
- [x] Data protection standards
- [x] Audit trail for compliance verification
- [x] Terms of service enforcement
- [x] Privacy policy implementation
- [x] Data retention policies
- [x] Certificate authenticity verification

---

## 📊 FEATURE MATRIX

| Feature | Functional | Non-Functional | Priority |
|---------|-----------|-----------------|----------|
| User Authentication | ✅ | Security | Critical |
| Request Submission | ✅ | Usability | Critical |
| Admin Approval | ✅ | Usability | Critical |
| Certificate Generation | ✅ | Performance | High |
| Announcement System | ✅ | Availability | High |
| Audit Logging | ✅ | Compliance | High |
| Search & Filter | ✅ | Performance | Medium |
| Reporting | ✅ | Scalability | Medium |
| User Profile | ✅ | Usability | Low |

---

## 🎯 SUMMARY

**Total Functional Requirements: 35+**
- Authentication & Users: 7
- Requests: 12
- Announcements: 6
- Certificates: 5
- Reservations: 8
- Records: 5
- Notifications: 5
- Admin Management: 6
- Search & Filter: 5
- Audit: 7
- Reporting: 7

**Total Non-Functional Requirements: 60+**
- Security: 12
- Performance: 9
- Availability: 9
- Usability: 11
- Scalability: 8
- Maintainability: 10
- Data Integrity: 8
- Compatibility: 8
- Accessibility: 8
- Compliance: 8

