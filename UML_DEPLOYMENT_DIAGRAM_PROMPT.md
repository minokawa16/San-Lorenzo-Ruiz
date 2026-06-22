# UML Deployment Diagram Generation Prompt
## AI-Powered Parish Information Management System

---

## OBJECTIVE
Create a comprehensive **UML Deployment Diagram** for the AI-Powered Parish Information Management System that clearly visualizes the system architecture, deployment nodes, communication protocols, and external service integrations in a cloud-based environment.

---

## SYSTEM OVERVIEW

**System Name**: AI-Powered Parish Information Management System with Document Request, Certificate Generation, OCR Verification, Payment Receipt Verification, and Notification Features

**Deployment Architecture**: Cloud-based client-server web application

**Development URL**: `http://localhost/ParishSystem/index.php`

**Production URL**: `https://yourdomain.com` (HTTPS/SSL required)

---

## DEPLOYMENT NODES TO INCLUDE

### 1. **Parishioner Device** (`<<device>>`)
- **Purpose**: Used by parishioners to access the system
- **Functions**:
  - Register and log in
  - Submit certificate requests
  - Upload requirement documents
  - View announcements
  - Check schedules
  - Upload payment receipts
  - Receive notifications
- **Examples**: Desktop, Laptop, Smartphone, Tablet

### 2. **Admin Device** (`<<device>>`)
- **Purpose**: Used by parish staff and administrators
- **Functions**:
  - Verify parishioner accounts
  - Manage certificate requests
  - Verify payment receipts
  - Generate and release certificates
  - Manage schedules and events
  - Post announcements
  - View analytics and reports
  - Create and download database backups
- **Examples**: Parish office desktop, Admin laptop

### 3. **Printer** (`<<device>>`)
- **Purpose**: Print certificates and reports
- **Connection**: USB, Wi-Fi, or LAN printing
- **User**: Administrator

### 4. **External Backup Storage** (`<<device>>`)
- **Purpose**: Store downloaded backup files externally
- **Examples**: External hard drive, USB flash drive, Cloud storage

### 5. **Cloud Server** (`<<node>>`)
- **Purpose**: Central host for entire web system
- **Components to show inside**:
  - Apache Web Server
  - PHP Runtime Environment
  - ParishSystem Application Files
  - MySQL/MariaDB Database
  - File Storage System
  - Backup Storage
  - Optional Tesseract OCR Engine

### 6. **Apache Web Server** (`<<executionEnvironment>>`)
- **Purpose**: Receives HTTP/HTTPS requests and serves PHP pages
- **Location**: Inside Cloud Server node
- **Functions**:
  - Request routing
  - Static file serving
  - PHP request forwarding

### 7. **PHP Runtime** (`<<executionEnvironment>>`)
- **Purpose**: Executes backend application logic
- **Location**: Inside Cloud Server node
- **Handles**:
  - User authentication and authorization
  - Request processing
  - Payment handling
  - Notification sending
  - Report generation
  - Certificate generation
  - Admin operations

### 8. **ParishSystem Application Files** (`<<artifact>>`)
- **Purpose**: Source code and configuration files
- **Location**: Inside Cloud Server node
- **Main Directories**:
  - `auth/` - Authentication pages and logic
  - `users/` - Parishioner interface pages
  - `admin/` - Administrator interface pages
  - `api/` - API endpoints
  - `includes/` - Helper functions and libraries
  - `templates/` - Reusable page templates
  - `assets/` - CSS, JavaScript, images
  - `database/` - Database configuration
  - `config/` - System configuration

### 9. **MySQL/MariaDB Database** (`<<database>>`)
- **Purpose**: Stores all structured system data
- **Location**: Inside Cloud Server node
- **Database Name**: `parish_management_system`
- **Key Tables**:
  - Users and authentication tables
  - Requests and request documents
  - Payments and payment receipts
  - Sacramental records (Baptism, Communion, Confirmation, Marriage, Funeral)
  - Reservations
  - Schedule events
  - Announcements
  - Notifications
  - Chatbot inquiries
  - Audit logs
  - OTP codes
  - SMS notification logs

### 10. **Uploaded Files Storage** (`<<artifact>>`)
- **Purpose**: Stores user and admin uploaded files
- **Location**: Inside Cloud Server node
- **Storage Path**: `uploads/`
- **Content Types**:
  - Valid identification documents
  - Live face capture images
  - Request requirement files
  - Payment receipt images/PDFs
  - Released certificates
  - Announcement attachments

### 11. **Backup Files** (`<<artifact>>`)
- **Purpose**: Stores database and full system backups
- **Location**: Inside Cloud Server node
- **Storage Path**: `backups/`
- **Backup Types**:
  - Database exports
  - Full system backups

### 12. **Optional Tesseract OCR Engine** (`<<executionEnvironment>>`)
- **Purpose**: Processes and extracts text from valid ID images
- **Location**: Inside Cloud Server node (optional)
- **Functions**:
  - OCR-based ID text extraction
  - Name and birthdate matching
  - ID number extraction
  - OCR confidence scoring

### 13. **Email/SMTP Service** (`<<externalSystem>>`)
- **Purpose**: Sends email notifications and OTP codes
- **Protocol**: SMTP or mail service API
- **Used For**:
  - Account verification emails
  - OTP delivery
  - Request status updates
  - Announcement notifications
  - Password recovery messages

### 14. **Optional SMS Gateway** (`<<externalSystem>>`)
- **Purpose**: Sends SMS OTP and future SMS notifications
- **Protocol**: HTTPS API
- **Example Services**: Twilio, AWS SNS
- **Used For**:
  - Mobile OTP verification
  - Optional future SMS alerts

### 15. **QR Code API** (`<<externalSystem>>`)
- **Purpose**: Generates QR codes for certificate verification
- **Protocol**: HTTPS
- **Example**: `api.qrserver.com`
- **Used For**: Certificate digital verification and tracking

---

## COMMUNICATION PATHS (ARROWS) TO INCLUDE

| From | To | Protocol | Purpose |
|------|----|-----------| |
| Parishioner Device | Cloud Server | HTTPS | Registration, login, request submission, file upload, payment receipt upload, notification delivery |
| Admin Device | Cloud Server | HTTPS | Admin login, user verification, request management, payment verification, certificate generation, reports, backups |
| Admin Device | Printer | USB/Wi-Fi/LAN | Print certificates and reports |
| Admin Device | External Backup Storage | Manual download/copy | Store downloaded backup files |
| Cloud Server | MySQL/MariaDB Database | Local MySQL connection (Port 3306) | Store and retrieve all system data |
| Cloud Server | Uploaded Files Storage | Local file system | Save and retrieve user/admin uploaded documents |
| Cloud Server | Backup Files | Local file system | Create and store backup packages |
| Cloud Server | Email/SMTP Service | SMTP | Send email notifications and OTP messages |
| Cloud Server | Optional SMS Gateway | HTTPS API | Send SMS OTP or SMS notifications |
| Cloud Server | QR Code API | HTTPS | Generate QR codes for certificates |
| Cloud Server | Tesseract OCR | Local server process | Extract text from ID images for verification |

---

## SECURITY COMPONENTS TO HIGHLIGHT

Include these security features in the diagram or legend:

- **HTTPS/SSL Encryption**: All client-server communications encrypted
- **Firewall**: Protection at cloud server perimeter
- **Role-Based Access Control (RBAC)**: Admin vs. Parishioner roles
- **Multi-Factor Authentication**: Email OTP verification, optional SMS OTP
- **Password Security**: Bcrypt hashing for all passwords
- **Session Management**: Secure session tokens with timeout
- **OCR-Assisted Identity Verification**: Multi-document verification
- **File Upload Validation**: MIME type and size validation
- **Audit Logging**: All admin actions logged
- **Database Encryption**: Sensitive data encrypted at rest
- **Prepared Statements**: SQL injection prevention

---

## DIAGRAM LAYOUT RECOMMENDATION

```
┌─────────────────────────┐              ┌──────────────────────┐
│  Parishioner Device     │              │   Admin Device       │─────┐
│  (Desktop/Mobile/etc)   │              │  (Admin Workstation) │     │
└────────────┬────────────┘              └──────────┬───────────┘     │
             │ HTTPS                                 │ HTTPS           │ Printing
             │                                       │                 │
             └───────────────┬───────────────────────┘                 │
                             │                                         │
                    ┌────────▼──────────────────────────┐             │
                    │     CLOUD SERVER                 │             │
                    │  ┌────────────────────────────┐  │             │
                    │  │ Apache Web Server          │  │             │
                    │  │ PHP Runtime Environment    │  │             │
                    │  │ ParishSystem Application   │  │             │
                    │  │ MySQL/MariaDB Database     │  │             │
                    │  │ Uploaded Files Storage     │  │             │
                    │  │ Backup Files               │  │             │
                    │  │ Tesseract OCR (optional)   │  │             │
                    │  └────────────────────────────┘  │             │
                    └───┬────┬────┬────┬────────────────┘             │
                        │    │    │    │                              │
                  SMTP  │    │    │    │ HTTPS                       │
              ┌─────────┘    │    │    └──────────────┐              │
              │              │    │                   │              │
       ┌──────▼──────┐  ┌────▼──┐│              ┌─────▼──────┐      │
       │ Email/SMTP  │  │SMS    ││              │ QR Code    │      │
       │ Service     │  │Gateway││              │ API        │      │
       └─────────────┘  └───────┘│              └────────────┘      │
                                 │                                    │
                        ┌────────▼────────┐                          │
                        │ External        │◄─────────────────────────┘
                        │ Backup Storage  │ (Manual download)
                        └─────────────────┘
                        
                        ┌────────────────┐
                        │   Printer      │
                        └────────────────┘
```

---

## HARDWARE SPECIFICATIONS TABLE

Include this table in the diagram documentation:

| Component | Purpose | Minimum Spec | Recommended Spec |
|-----------|---------|--------------|------------------|
| Cloud Server | Web app, database, storage, backups | 2 vCPU, 4GB RAM, 80GB SSD | 4 vCPU, 8GB RAM, 160-250GB SSD |
| Admin Workstation | Admin access, processing, reports | Dual-core CPU, 4GB RAM, browser | Core i5/Ryzen 5, 8GB RAM, modern browser |
| Parishioner Device | User access | Smartphone or PC with browser | Modern smartphone, laptop, or desktop |
| Printer | Certificate and report printing | Basic inkjet/laser | Laser printer, high-quality output |
| Backup Storage | Store downloaded backups | 128GB USB/external drive | 1TB external drive or cloud storage |
| Network Router | Internet/LAN connectivity | Basic router | Business-grade with firewall |
| Internet Connection | Access cloud and services | 10 Mbps | 50 Mbps or higher |

---

## SOFTWARE REQUIREMENTS TABLE

Include this table in the documentation:

| Software | Purpose | Version |
|----------|---------|---------|
| Ubuntu Server LTS or Windows Server | Server operating system | Latest LTS |
| Apache Web Server | HTTP/HTTPS web server | 2.4+ |
| PHP | Backend application runtime | 8.x |
| MySQL/MariaDB | Database server | 8.0+ or MariaDB 10.5+ |
| phpMyAdmin or Adminer | Database administration | Latest |
| Tesseract OCR | OCR processing (optional) | 5.x |
| SMTP Email Service | Email delivery | Gmail, SendGrid, AWS SES, etc. |
| SMS Gateway | SMS delivery (optional) | Twilio, AWS SNS, etc. |
| SSL Certificate | HTTPS encryption | Let's Encrypt or commercial |
| Web Browser | User/admin access | Chrome, Firefox, Safari, Edge |
| Bootstrap | UI framework | 5.x |
| Font Awesome | Icon library | 6.x |
| FullCalendar | Calendar module | 6.x |
| jQuery | Frontend scripting | 3.x |
| QR Code API | QR generation | api.qrserver.com |

---

## DATA FLOW SEQUENCE (Optional - Include as Reference)

1. Parishioner opens browser and navigates to the system
2. Browser connects to Cloud Server via HTTPS
3. Apache Web Server receives the request
4. Apache forwards PHP request to PHP Runtime
5. PHP processes login/registration/request submission
6. PHP queries/updates MySQL/MariaDB Database
7. Uploaded files are saved to Uploaded Files Storage
8. Valid ID images may be processed by Tesseract OCR
9. System sends notifications via Email/SMTP Service
10. Optional SMS OTP sent via SMS Gateway
11. Admin users verify accounts and requests via Admin Device
12. Admin generates reports and certificates
13. Admin prints documents via Printer
14. Admin creates backups and downloads to External Backup Storage
15. QR codes generated via QR Code API for certificate verification

---

## DEPLOYMENT ENVIRONMENT NOTES

- **Development**: Single local machine running XAMPP
  - URL: `http://localhost/ParishSystem/`
  - Database: Local MySQL on port 3306

- **Production**: Cloud-hosted environment
  - URL: `https://yourdomain.com`
  - SSL/TLS certificates required
  - Separate or managed database service recommended
  - CDN for static assets recommended
  - Automated backups recommended
  - Load balancing for scalability (optional)

---

## KEY ASSUMPTIONS

1. System will be deployed on a cloud server (production)
2. Database and application hosted on same server (or connected via network)
3. HTTPS/SSL enabled in production
4. System supports 50+ concurrent users
5. Email notifications are mandatory
6. SMS gateway is optional enhancement
7. OCR processing available if Tesseract installed
8. Backups downloaded and stored externally by admins
9. Printing from administrator devices only
10. Firewall and security best practices implemented

---

## DIAGRAM OUTPUT REQUIREMENTS

**Format**: UML Deployment Diagram
- UML 2.0+ standard notation
- Clear node stereotypes (`<<device>>`, `<<node>>`, `<<artifact>>`, `<<executionEnvironment>>`, `<<externalSystem>>`, `<<database>>`)
- Communication paths with protocols labeled
- Color coding recommended (internal systems one color, external services another)
- Legend for security components
- Professional, enterprise-ready appearance

**Tools Suitable For**:
- Lucidchart
- Draw.io / Diagrams.net
- Visual Paradigm
- Enterprise Architect
- ArchiMate / UML modeling tools
- PlantUML (text-based)
- Miro (collaborative)

---

## PROMPT USAGE INSTRUCTIONS

**For AI Diagram Tools**:
Use this entire prompt as input to generate the UML Deployment Diagram.

**For Manual Diagram Creation**:
1. Draw Cloud Server as main central node
2. Add internal components (Apache, PHP, Database, Storage, OCR)
3. Add external devices (Parishioner, Admin, Printer)
4. Add external services (Email, SMS, QR Code)
5. Draw communication paths with protocol labels
6. Add security legend
7. Add hardware specification table as reference
8. Verify all nodes and connections match this specification

**For Team Documentation**:
This prompt serves as the specification document for the deployment architecture and can be used for:
- System design reviews
- Technical documentation
- Deployment planning
- Infrastructure requirements estimation
- Security compliance verification

---

**Generated**: June 14, 2026  
**System**: AI-Powered Parish Information Management System  
**Location**: `c:\xampp\htdocs\ParishSystem`
