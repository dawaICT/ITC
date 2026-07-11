# Airtel Money Payment Gateway - Setup & Integration Guide

## Overview

This document provides complete setup and integration instructions for the Airtel Money payment gateway in the WUC Portal.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Configuration](#configuration)
3. [Frontend Integration](#frontend-integration)
4. [Testing](#testing)
5. [Troubleshooting](#troubleshooting)
6. [User Documentation](#user-documentation)

---

## Prerequisites

### Required Information

Before setting up, obtain the following from Airtel Money API documentation or your Airtel Money representative:

1. **Merchant Account Credentials**
   - Merchant ID / Merchant Code
   - Client ID (OAuth2)
   - Client Secret (OAuth2)
   - API Key (optional, depends on Airtel implementation)

2. **Environment URLs**
   - Sandbox (Testing): Usually `https://sandbox.airtelapi.com`
   - Production (Live): `https://api.airtelapi.com`

3. **Webhook Configuration**
   - Ensure your server can receive POST requests from Airtel
   - Your webhook URL will be: `https://yourdomain.com/wucportal/students/airtel_callback.php`

### Server Requirements

- PHP 7.2+ with cURL support
- MySQL/MariaDB for transaction logging
- SSL certificate (required for production)
- Outbound HTTPS access to Airtel API endpoints

---

## Configuration

### Step 1: Environment Variables

Create or update your environment configuration. Add the following to your `.env` or system environment:

```bash
# .env file (in project root)
AIRTEL_ENV=sandbox                          # Use 'sandbox' for testing, 'production' for live
AIRTEL_CLIENT_ID=your_client_id_here
AIRTEL_CLIENT_SECRET=your_client_secret_here
AIRTEL_MERCHANT_CODE=your_merchant_code_here
AIRTEL_WEBHOOK_URL=https://yourdomain.com/wucportal/students/airtel_callback.php
```

### Step 2: Update Configuration File

Edit `includes/airtel_config.php` to use environment variables:

```php
<?php
// includes/airtel_config.php

// Environment
define('AIRTEL_ENV', getenv('AIRTEL_ENV') ?: 'sandbox');

// OAuth2 Credentials
define('AIRTEL_CLIENT_ID', getenv('AIRTEL_CLIENT_ID'));
define('AIRTEL_CLIENT_SECRET', getenv('AIRTEL_CLIENT_SECRET'));
define('AIRTEL_MERCHANT_CODE', getenv('AIRTEL_MERCHANT_CODE'));

// Webhook Configuration
define('AIRTEL_WEBHOOK_URL', getenv('AIRTEL_WEBHOOK_URL'));

// ... rest of configuration
?>
```

### Step 3: Database Schema

Ensure your database has the `transactions` table. If not, run this migration:

```sql
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `reference_id` VARCHAR(100) UNIQUE NOT NULL,
    `transaction_id` VARCHAR(100) UNIQUE,
    `amount` DECIMAL(10, 2) NOT NULL,
    `currency` VARCHAR(3) DEFAULT 'ZMW',
    `phone_number` VARCHAR(20),
    `narration` VARCHAR(255),
    `channel` VARCHAR(50) DEFAULT 'Airtel Money',
    `status` VARCHAR(20) DEFAULT 'pending', -- pending, completed, failed, cancelled
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `callback_response` LONGTEXT,
    FOREIGN KEY (`student_id`) REFERENCES `students` (`Sid`)
);

CREATE INDEX idx_reference ON transactions(reference_id);
CREATE INDEX idx_student ON transactions(student_id);
CREATE INDEX idx_status ON transactions(status);
```

### Step 4: Create Logs Directory

Create a logs directory for Airtel Money callbacks:

```bash
mkdir -p logs
chmod 755 logs
```

---

## Frontend Integration

### Step 1: Include JavaScript Library

In your payment page (e.g., `students/fees.php`), add the Airtel Money JavaScript:

```html
<!-- In the <head> section -->
<script src="js/airtel_money.js"></script>
```

### Step 2: Add Payment Container

Add this HTML where you want the Airtel Money payment option to appear:

```html
<!-- Payment Methods Section -->
<div class="row mt-4">
    <div class="col-md-6">
        <div id="airtel-money-container"></div>
    </div>
</div>
```

### Step 3: Display Amount Due

Set the amount dynamically from your fees calculation:

```php
<?php
// In fees.php, after calculating amount due
$amount_due = 2500; // Example: ZMW 2,500

echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (window.airtelPayment) {
            window.airtelPayment.setAmount($amount_due);
        }
    });
</script>";
?>
```

### Step 4: Complete Example

Here's a complete payment page example:

```html
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="css/bootstrap.css">
    <script src="js/airtel_money.js"></script>
</head>
<body>
    <div class="container mt-4">
        <h2>Student Registration Fee Payment</h2>
        
        <div class="alert alert-info">
            <strong>Amount Due:</strong> ZMW <span id="amount-due">2,500.00</span>
        </div>
        
        <!-- Payment Methods -->
        <div class="row mt-4">
            <div class="col-md-6">
                <h4>Select Payment Method</h4>
                <div id="airtel-money-container"></div>
            </div>
        </div>
    </div>
    
    <script>
        // Set the amount from PHP or hardcoded
        document.addEventListener('DOMContentLoaded', function() {
            const amount = parseFloat(document.getElementById('amount-due').textContent);
            if (window.airtelPayment) {
                window.airtelPayment.setAmount(amount);
            }
        });
    </script>
</body>
</html>
```

---

## Testing

### Test in Sandbox Mode

1. **Configure for Sandbox**
   ```php
   // In airtel_config.php
   define('AIRTEL_ENV', 'sandbox');
   ```

2. **Test Phone Numbers**
   - Airtel typically provides test phone numbers in sandbox
   - Common format: `+260976543210` or `0976543210`

3. **Test Amounts**
   - Minimum: ZMW 10
   - Maximum: ZMW 100,000
   - Recommended test: ZMW 50

4. **Manual Testing Steps**
   ```
   1. Navigate to student fees page
   2. Enter test phone number (e.g., 0976543210)
   3. Airtel Money amount should be pre-filled
   4. Click "Pay Now with Airtel Money"
   5. Verify sandbox shows payment prompt
   6. Check transaction table for entry
   7. Verify student_payments record created
   ```

### Check Logs

Review payment attempts in the logs:

```bash
# View recent callbacks
tail -f logs/airtel_callbacks.log

# View all transactions for a student
grep "student_id: 12345" logs/airtel_callbacks.log
```

### Database Verification

```sql
-- Check transaction table
SELECT * FROM transactions WHERE channel = 'Airtel Money' ORDER BY created_at DESC LIMIT 10;

-- Check student payments created
SELECT * FROM student_payments WHERE reference_number LIKE 'AIRTEL%' ORDER BY dte_time DESC LIMIT 10;

-- Verify payment status
SELECT t.reference_id, t.status, sp.amount 
FROM transactions t
LEFT JOIN student_payments sp ON sp.reference_number = t.reference_id
WHERE t.student_id = ? ORDER BY t.created_at DESC;
```

---

## Troubleshooting

### Issue: "Payment initiation failed"

**Causes:**
1. Invalid phone number format
2. Amount outside allowed range (10-100,000)
3. Student ID not found

**Solution:**
- Enable debug mode: `define('AIRTEL_DEBUG_MODE', true);` in `airtel_config.php`
- Check `logs/airtel_callbacks.log` for API error details
- Verify phone number: should be 10 digits (local) or 12 digits (international)

### Issue: "Payment verification timeout"

**Causes:**
1. Airtel API slow to respond
2. Network connectivity issue
3. Transaction really failed

**Solution:**
- Check `transactions` table for reference_id status
- Manually query: `process_airtel_payment.php?action=query&reference=<ref>`
- If still pending, wait 5 more minutes
- If failed, user should try again

### Issue: Duplicate Payment Created

**Causes:**
- Should not happen with current guard logic
- May indicate callback received twice

**Solution:**
- Check `transactions` table for duplicate `reference_id`
- The code uses `ON DUPLICATE KEY IGNORE` to prevent duplicates
- If occurred, manually verify `student_payments` entries

### Issue: Webhook Not Receiving Callbacks

**Causes:**
1. Firewall/NAT blocking inbound POST
2. Incorrect webhook URL configured
3. Airtel IP whitelist not updated

**Solution:**
- Verify webhook URL is accessible: `curl -X POST https://yourdomain.com/wucportal/students/airtel_callback.php`
- Check server firewall: `sudo ufw allow 443/tcp`
- Ensure SSL certificate is valid
- Whitelist Airtel IPs in server firewall (get from Airtel support)
- Check `logs/airtel_callbacks.log` for incoming requests

### Issue: Authentication Failed (OAuth2 Error)

**Causes:**
1. Invalid Client ID/Secret
2. Credentials not URL-encoded properly
3. Token expiration

**Solution:**
- Verify credentials in `airtel_config.php`
- Check Airtel API documentation for current OAuth2 endpoint
- Ensure `AIRTEL_ENV` matches your credentials environment
- Enable debug: Add `var_dump($tokenResponse)` in `authenticate()` method

---

## Deployment Checklist

Before going to production:

- [ ] Test complete payment flow in sandbox
- [ ] Obtain production credentials from Airtel
- [ ] Update `airtel_config.php` with production URLs and credentials
- [ ] Update `.env` to `AIRTEL_ENV=production`
- [ ] SSL certificate installed and valid
- [ ] Webhook URL registered with Airtel
- [ ] Database transactions table verified
- [ ] Logs directory writable
- [ ] Admin dashboard can monitor transactions
- [ ] User documentation created and shared
- [ ] Support team trained on troubleshooting
- [ ] Monitoring alert set for failed transactions

---

## User Documentation

### How to Pay via Airtel Money

**For Students:**

1. **Go to Fees Page**
   - Log in to WUC Portal
   - Navigate to "My Fees" or "Student Payments"

2. **Select Airtel Money**
   - Look for the "Airtel Money" payment option
   - Click on "Airtel Money" section to expand

3. **Enter Your Details**
   - **Phone Number:** Enter your Airtel Money registered phone number
     - Format: `0976543210` (local) or `260976543210` (international)
   - **Amount:** Pre-filled with your amount due
   - Verify the amount is correct

4. **Click "Pay Now with Airtel Money"**
   - You'll receive a prompt on your phone
   - Enter your Airtel Money PIN

5. **Wait for Confirmation**
   - The portal will automatically check payment status
   - You'll see a success message when payment is confirmed
   - You can also check "Transaction History" to see payment receipt

**Frequently Asked Questions:**

- **How long does payment take?** Usually 1-2 minutes, but can take up to 5 minutes.
- **What if payment fails?** Try again or use another payment method.
- **Can I cancel payment?** Contact Airtel Money customer service if payment was deducted but not credited.
- **Is there a fee?** Airtel Money charges may apply. See Airtel Money terms for details.
- **Payment not showing?** Wait a few minutes and refresh the page. Contact support if not resolved within 10 minutes.

---

## Support

For issues with:
- **Payment Processing:** Contact `support@wucportal.com`
- **Airtel Money Accounts:** Contact Airtel Money customer service
- **Portal Access:** Contact IT help desk

---

## Technical Support Contact

- **Administrator Email:** `admin@wucportal.com`
- **API Documentation:** Refer to `includes/AirtelMoneyGateway.php` inline documentation
- **Error Logs:** Check `logs/airtel_callbacks.log` for transaction details

---

**Last Updated:** 2024  
**Version:** 1.0  
**Status:** Production Ready
