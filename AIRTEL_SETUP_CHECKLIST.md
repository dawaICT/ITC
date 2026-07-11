# Airtel Money Integration - Quick Setup Checklist

## 🎯 Pre-Deployment Setup (Complete Before Going Live)

### Phase 1: Preparation (30 minutes)

- [ ] **Read Documentation**
  - [ ] Read AIRTEL_MONEY_SETUP.md completely
  - [ ] Read AIRTEL_INTEGRATION_GUIDE.md
  - [ ] Understand payment flow from AIRTEL_MONEY_IMPLEMENTATION_SUMMARY.md

- [ ] **Get Airtel Credentials**
  - [ ] Contact Airtel Money representative
  - [ ] Request sandbox credentials first
  - [ ] Request production credentials (for later)
  - [ ] Obtain: Client ID, Client Secret, Merchant Code
  - [ ] Get: Sandbox API endpoint, Production API endpoint

- [ ] **Prepare Your Server**
  - [ ] Verify PHP version 7.2+ installed
  - [ ] Verify cURL extension enabled (`php -m | grep curl`)
  - [ ] Verify MySQL 5.7+ running
  - [ ] Verify outbound HTTPS connectivity to Airtel API
  - [ ] Verify SSL certificate valid (for production)

- [ ] **Create Logs Directory**
  ```bash
  mkdir -p /path/to/wucportal/logs
  chmod 755 /path/to/wucportal/logs
  ```

### Phase 2: Database Setup (15 minutes)

- [ ] **Create Transactions Table**
  ```bash
  # Run SQL script
  mysql -u root -p yourdb < AIRTEL_MONEY_SETUP.sql
  ```
  
  Or manually execute:
  ```sql
  CREATE TABLE transactions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      student_id INT NOT NULL,
      reference_id VARCHAR(100) UNIQUE NOT NULL,
      transaction_id VARCHAR(100) UNIQUE,
      amount DECIMAL(10, 2) NOT NULL,
      currency VARCHAR(3) DEFAULT 'ZMW',
      phone_number VARCHAR(20),
      narration VARCHAR(255),
      channel VARCHAR(50) DEFAULT 'Airtel Money',
      status VARCHAR(20) DEFAULT 'pending',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      callback_response LONGTEXT,
      FOREIGN KEY (student_id) REFERENCES students(Sid)
  );
  
  CREATE INDEX idx_reference ON transactions(reference_id);
  CREATE INDEX idx_student ON transactions(student_id);
  CREATE INDEX idx_status ON transactions(status);
  
  CREATE TABLE portal_settings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      key VARCHAR(100) UNIQUE NOT NULL,
      value LONGTEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  );
  ```

- [ ] **Verify Tables Created**
  ```sql
  SHOW TABLES LIKE 'transactions';
  SHOW TABLES LIKE 'portal_settings';
  DESCRIBE transactions;
  ```

### Phase 3: Configuration (20 minutes) - CHOOSE ONE METHOD

#### Method A: Admin Panel (Recommended)

- [ ] **Access Admin Panel**
  - [ ] Navigate to: `http://localhost/wucportal/admin/airtel_config.php`
  - [ ] Verify you're logged in as admin
  - [ ] You should see "Airtel Money Configuration" page

- [ ] **Configure for Sandbox**
  - [ ] Environment Mode: Select `Sandbox (Testing)`
  - [ ] OAuth2 Client ID: Enter your sandbox Client ID
  - [ ] OAuth2 Client Secret: Enter your sandbox Client Secret
  - [ ] Merchant Code: Enter your Merchant Code
  - [ ] Webhook URL: `https://yourdomain.com/wucportal/students/airtel_callback.php`
  - [ ] Minimum Amount: 10 (ZMW)
  - [ ] Maximum Amount: 100000 (ZMW)
  - [ ] Enable Airtel Money: Check ✓
  - [ ] Enable Debug Mode: Check ✓ (for sandbox testing)
  - [ ] Click **Save Configuration**

- [ ] **Test Credentials**
  - [ ] Click **Test Credentials** button
  - [ ] You should see "Connection Test Successful!"
  - [ ] If failed, verify credentials and try again

#### Method B: Direct File Edit

- [ ] **Edit Configuration File**
  ```bash
  # Open for editing
  nano includes/airtel_config.php
  ```
  
  Update constants:
  ```php
  define('AIRTEL_ENV', 'sandbox');
  define('AIRTEL_CLIENT_ID', 'your_sandbox_client_id');
  define('AIRTEL_CLIENT_SECRET', 'your_sandbox_client_secret');
  define('AIRTEL_MERCHANT_CODE', 'your_merchant_code');
  define('AIRTEL_WEBHOOK_URL', 'https://yourdomain.com/wucportal/students/airtel_callback.php');
  define('AIRTEL_MONEY_ENABLED', true);
  define('AIRTEL_DEBUG_MODE', true); // For testing
  ```

#### Method C: Environment Variables

- [ ] **Set Environment Variables**
  ```bash
  export AIRTEL_ENV=sandbox
  export AIRTEL_CLIENT_ID=your_client_id
  export AIRTEL_CLIENT_SECRET=your_client_secret
  export AIRTEL_MERCHANT_CODE=your_merchant_code
  export AIRTEL_WEBHOOK_URL=https://yourdomain.com/wucportal/students/airtel_callback.php
  ```

### Phase 4: Integration Testing (30 minutes)

- [ ] **Verify Files Exist**
  ```bash
  ls -la includes/AirtelMoneyGateway.php
  ls -la includes/airtel_config.php
  ls -la students/process_airtel_payment.php
  ls -la students/airtel_callback.php
  ls -la students/js/airtel_money.js
  ls -la admin/airtel_config.php
  ```

- [ ] **Test Payment Initiation**
  - [ ] Navigate to: `students/example_airtel_payment.php`
  - [ ] Verify payment form displays
  - [ ] Verify amount pre-filled correctly
  - [ ] Enter test phone: `0976543210`
  - [ ] Click "Pay Now with Airtel Money"
  - [ ] Watch for response (should show processing)
  - [ ] Check browser console for errors (press F12)

- [ ] **Monitor Logs**
  ```bash
  # In new terminal tab
  tail -f logs/airtel_callbacks.log
  ```
  - [ ] You should see incoming requests
  - [ ] Verify reference ID format: `AIRTEL_YYYYMMDD_...`

- [ ] **Check Database**
  ```sql
  SELECT * FROM transactions ORDER BY created_at DESC LIMIT 5;
  SELECT * FROM student_payments ORDER BY dte_time DESC LIMIT 5;
  ```
  - [ ] Verify transaction inserted
  - [ ] Verify student_payments updated on success

### Phase 5: Webhook Testing (20 minutes)

- [ ] **Register Webhook with Airtel**
  - [ ] Contact Airtel with your webhook URL
  - [ ] Provide: `https://yourdomain.com/wucportal/students/airtel_callback.php`
  - [ ] Confirm callback format (should be JSON POST)
  - [ ] Request test webhook call

- [ ] **Test Webhook Reception**
  - [ ] Check logs for incoming webhook
  - [ ] Verify format matches expected callback
  - [ ] Verify response sent to Airtel (200 OK)

- [ ] **Verify Callback Processing**
  - [ ] Transaction status updated from "pending" to appropriate status
  - [ ] student_payments record created with correct reference
  - [ ] No duplicate entries created

### Phase 6: Frontend Integration (30 minutes)

- [ ] **Integrate into Your Payment Page**
  
  In your `fees.php` or payment page, add:
  
  ```html
  <!-- At top of page or in <head> -->
  <script src="students/js/airtel_money.js"></script>
  
  <!-- Where you want payment options to appear -->
  <div id="airtel-money-container"></div>
  
  <!-- Initialize -->
  <script>
      document.addEventListener('DOMContentLoaded', function() {
          if (window.AirtelMoneyPayment) {
              const airtelPayment = new AirtelMoneyPayment({
                  processorUrl: 'students/process_airtel_payment.php'
              });
              airtelPayment.initPaymentOption();
              
              // Set the amount due (get from PHP)
              const amountDue = <?php echo $amount_due; ?>;
              airtelPayment.setAmount(amountDue);
          }
      });
  </script>
  ```

- [ ] **Test in Actual Payment Page**
  - [ ] Navigate to your fees.php
  - [ ] Verify Airtel Money option shows
  - [ ] Test entering phone number
  - [ ] Test payment initiation
  - [ ] Verify success/error messages

- [ ] **Test Error Cases**
  - [ ] Invalid phone number (letters instead of digits)
  - [ ] Amount too low (< 10)
  - [ ] Amount too high (> 100,000)
  - [ ] Empty form submission

### Phase 7: Production Preparation (1 hour)

- [ ] **Get Production Credentials**
  - [ ] Contact Airtel for production credentials
  - [ ] Obtain: Production Client ID, Secret, Merchant Code
  - [ ] Get: Production API endpoint

- [ ] **Update Configuration to Production**
  
  In admin panel or config file:
  ```php
  define('AIRTEL_ENV', 'production');
  define('AIRTEL_CLIENT_ID', 'your_prod_client_id');
  define('AIRTEL_CLIENT_SECRET', 'your_prod_client_secret');
  define('AIRTEL_MERCHANT_CODE', 'your_prod_merchant_code');
  define('AIRTEL_DEBUG_MODE', false); // Disable debug for production
  ```

- [ ] **Register Production Webhook**
  - [ ] Provide production webhook URL to Airtel
  - [ ] Verify webhook endpoint configured in system

- [ ] **Final Production Testing**
  - [ ] Test payment flow with production credentials
  - [ ] Use small amount for first test
  - [ ] Verify transaction appears in logs
  - [ ] Verify student_payments created correctly
  - [ ] Confirm payment registered in student account

- [ ] **Enable for All Users**
  - [ ] Ensure `AIRTEL_MONEY_ENABLED` is true
  - [ ] Payment option will appear in student fees page
  - [ ] Monitor first 24 hours for issues

## 🚀 Launch Checklist

Before Announcing to Students:

- [ ] Database tables created and verified
- [ ] Credentials configured and tested
- [ ] Webhook URL registered with Airtel
- [ ] Payment page integrated and tested
- [ ] Logs monitoring in place
- [ ] Error handling verified
- [ ] Admin panel accessible
- [ ] Documentation shared with support team
- [ ] User guide prepared for students

## ⚠️ Critical Points

| Point | Action |
|-------|--------|
| **Webhook URL** | Must be HTTPS, must be accessible from internet, register with Airtel |
| **SSL Certificate** | Must be valid (no self-signed for production) |
| **Phone Format** | Must be valid Zambian number: 0976543210 or 260976543210 |
| **Amount Limits** | Minimum ZMW 10, Maximum ZMW 100,000 |
| **Database** | Must have transactions table and portal_settings table |
| **Logs Directory** | Must be writable (755 permissions) |
| **Credentials** | Keep secure, never commit to git, use environment variables |

## 📊 Monitoring

### Daily Checks

- [ ] Check logs for errors: `tail -20 logs/airtel_callbacks.log`
- [ ] Count transactions: `SELECT COUNT(*) FROM transactions WHERE DATE(created_at) = CURDATE();`
- [ ] Check failed transactions: `SELECT * FROM transactions WHERE status = 'failed' ORDER BY created_at DESC LIMIT 5;`

### Weekly Checks

- [ ] Total transactions volume: `SELECT COUNT(*) FROM transactions;`
- [ ] Success rate: `SELECT status, COUNT(*) FROM transactions GROUP BY status;`
- [ ] Average amount: `SELECT AVG(amount) FROM transactions;`
- [ ] Review error logs for patterns

### Monthly Checks

- [ ] Reconcile transactions vs student_payments
- [ ] Review duplicate attempts (if any)
- [ ] Audit webhook deliveries
- [ ] Check credential expiration if applicable

## 🛠️ Troubleshooting Quick Links

- **Payment not initiating?** → Check airtel_config.php credentials
- **Webhook not received?** → Verify URL is accessible, check firewall
- **Duplicate payments?** → Should not happen; check logs and reference table
- **OAuth2 error?** → Verify Client ID/Secret, check sandbox/production match
- **Phone number error?** → Must be valid Zambian format: 0976543210

## 📞 Support

| Issue | First Check |
|-------|------------|
| Payment fails | Admin panel → Recent transactions |
| Webhook missing | Check logs/airtel_callbacks.log |
| Credentials wrong | Admin panel → Test Credentials button |
| Transaction stuck | Query transactions table, check status |
| Database error | Verify table exists, check permissions |

## ✅ Sign-Off Checklist

Before declaring implementation complete:

- [ ] All 7 files deployed and accessible
- [ ] Database tables created
- [ ] Configuration saved and tested
- [ ] Sandbox testing passed
- [ ] Webhook receiving callbacks
- [ ] Production credentials ready
- [ ] User documentation prepared
- [ ] Support team trained
- [ ] Monitoring alerts set up
- [ ] Ready for student announcement

---

**Implementation Status: Ready for Deployment**

**Estimated Time to Complete: 3-4 hours (first time)**

**Estimated Time for Support Readiness: 1 week (after launch)**

For detailed instructions, see:
- AIRTEL_MONEY_SETUP.md (Complete setup guide)
- AIRTEL_INTEGRATION_GUIDE.md (Technical reference)
- AIRTEL_MONEY_IMPLEMENTATION_SUMMARY.md (Overview)
