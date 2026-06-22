# Payment Receipt Feature Implementation

## Overview
Added a **Send Payment Receipt** feature to the Admin Request Workflow page that allows admins to generate and email payment receipts to parishioners.

## Changes Made

### 1. **Admin Request Workflow Page** (`admin/request-workflow.php`)

#### New UI Section
- Added a new card titled **"Send Payment Receipt"** in the right sidebar (admin panel)
- This section appears after the "Release File" card
- Only displays if there are verified payments for the request

#### Features:
- **Payment Selection Dropdown**: Shows all payments with:
  - Payment amount (in PHP)
  - Payment method (Bank Transfer, Check, Cash, etc.)
  - Payment status (Pending, Verified, Rejected)
  
- **Receipt Note Field** (Optional): Allows admin to add custom notes to the receipt
  
- **Send Receipt Button**: Generates and sends the receipt via email to the parishioner

#### New POST Handler
- Action: `send_receipt`
- Retrieves payment details and generates a professional receipt
- Sends receipt email to parishioner
- Logs the action in audit logs
- Updates the database to track when receipt was sent

### 2. **Helper Functions** (`includes/helpers.php`)

#### New Function: `sendPaymentReceipt()`
**Purpose**: Generates and sends payment receipt emails to parishioners

**Parameters**:
- `$conn` - Database connection
- `$request` - Request details (array)
- `$payment` - Payment details (array)
- `$request_id` - Request ID
- `$admin_id` - Admin user ID
- `$receipt_note` - Optional additional notes

**Features**:
- Generates unique receipt number (format: REC-YYYYMMDD-00001)
- Creates professional HTML email with:
  - Receipt number
  - Request reference number
  - Request type
  - Payment amount
  - Payment method
  - Reference number (if provided)
  - Payment date
  - Payment status
  - Admin remarks (if any)
  - Additional notes (if provided)
- Sends via configured mail system
- Returns status: `['ok' => true/false, 'error' => error message]`

#### Updated Function: `ensureRequestPaymentsSchema()`
**Changes**:
- Added new column: `receipt_sent_at` (TIMESTAMP)
- Tracks when the receipt email was sent to the parishioner
- Auto-creates column if it doesn't exist (backward compatible)

### 3. **Database Schema**

#### New Column in `request_payments` Table
```
receipt_sent_at TIMESTAMP NULL DEFAULT NULL
```
- Records when the payment receipt was sent to parishioner
- Used for tracking and audit purposes

## How to Use

### For Admins:

1. **Navigate to Request Workflow**
   - Go to Dashboard → Manage Requests
   - Click on a request to open its workflow

2. **Send Payment Receipt**
   - Scroll to the right sidebar
   - Find the "Send Payment Receipt" card
   - Select a payment from the dropdown
   - (Optional) Add a note in the "Receipt Note" field
   - Click "Send Receipt to Parishioner"
   - Success message confirms email was sent

3. **Receipt Email Content**
   - Parishioner receives professional receipt with all payment details
   - Includes request reference, type, and amount
   - Shows payment status and any admin remarks
   - Contains contact information for inquiries

## Receipt Email Template

The receipt email includes:
- Greeting to parishioner
- Receipt number (automatically generated)
- Request details
- Payment details
- Status information
- Optional additional notes
- Parish contact information

## Audit Logging

All receipt sends are logged in the audit log:
- **Action**: `SEND_PAYMENT_RECEIPT`
- **Target Table**: `request_payments`
- **Target ID**: Payment ID
- **User**: Admin ID

## Error Handling

The feature includes comprehensive error handling:
- Validates payment selection
- Checks if payment exists for the request
- Handles email sending failures gracefully
- Returns meaningful error messages

## Notes

- Receipts can be sent multiple times for the same payment
- Recipients receive email at the address registered with their account
- All receipts are tracked in the database via `receipt_sent_at` timestamp
- Email configuration must be set up in `config/mail.php`
- Falls back to XAMPP mail settings if no SMTP provider is configured

## Future Enhancements

Potential additions:
- View history of sent receipts
- Customize receipt email template per admin
- Add PDF receipt generation and attachment
- Send automatic receipts on payment verification
- Bulk send receipts for multiple payments
