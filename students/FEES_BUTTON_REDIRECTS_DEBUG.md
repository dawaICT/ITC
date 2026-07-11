# Fees.php Button Redirect Debugging Guide

## Overview
This document maps all clickable buttons and links in `students/fees.php` and their redirect destinations.

---

## Button/Link Inventory

### 1. **Navigation Tabs**

#### Balance Statement Tab (Line 316)
```php
<a class="nav-link" href="balanceStatement.php">Balance Statement</a>
```
- **Redirects to:** `students/balanceStatement.php`
- **Trigger:** Click on "Balance Statement" tab
- **Purpose:** View detailed balance statement

---

### 2. **New Invoice Section** (Conditional - Only shown after course registration)

#### Pay Now Button (Line 278)
```php
<a href="payment.php?invoice=<?php echo urlencode($reg['invoice'] ?? ''); ?>" class="btn btn-success">
    <i class="fas fa-credit-card"></i> Pay Now
</a>
```
- **Redirects to:** `students/payment.php?invoice=[INVOICE_NUMBER]`
- **Example:** `payment.php?invoice=INV2024001`
- **Trigger:** Click "Pay Now" button in new invoice card
- **Purpose:** Process payment for newly generated invoice
- **Condition:** Only visible when `$showNewInvoice` is true (after course registration)

#### Print Invoice Button (Line 281)
```php
<a href="printReceipt.php?invoice=<?php echo urlencode($reg['invoice'] ?? ''); ?>" class="btn btn-outline-primary ms-2" target="_blank">
    <i class="fas fa-print"></i> Print Invoice
</a>
```
- **Redirects to:** `students/printReceipt.php?invoice=[INVOICE_NUMBER]`
- **Example:** `printReceipt.php?invoice=INV2024001`
- **Trigger:** Click "Print Invoice" button
- **Purpose:** Opens invoice in new tab for printing
- **Opens in:** New browser tab (`target="_blank"`)
- **Condition:** Only visible when `$showNewInvoice` is true

#### View Registered Courses Button (Line 284)
```php
<a href="myCourses.php" class="btn btn-outline-secondary ms-2">
    <i class="fas fa-book"></i> View Registered Courses
</a>
```
- **Redirects to:** `students/myCourses.php`
- **Trigger:** Click "View Registered Courses" button
- **Purpose:** Navigate to courses page to view registered courses
- **Condition:** Only visible when `$showNewInvoice` is true

---

### 3. **Payment Records Table**

#### Print Receipt Button (Line 366)
```php
<a href="printReceipt.php?view=<?= urlencode($r->reference_number) ?>" class="btn-sm-action" target="_blank">
    <i class="fas fa-print"></i>
</a>
```
- **Redirects to:** `students/printReceipt.php?view=[REFERENCE_NUMBER]`
- **Example:** `printReceipt.php?view=REF123456`
- **Trigger:** Click printer icon button in "Action" column of payment records table
- **Purpose:** Print receipt for specific payment
- **Opens in:** New browser tab (`target="_blank"`)
- **Condition:** Only visible if payment has a reference number (not empty)
- **Note:** This appears for each payment record in the table

---

## File Locations Summary

All redirect destinations (relative to `students/` directory):
1. `balanceStatement.php` - Balance statement page
2. `payment.php` - Payment processing page
3. `printReceipt.php` - Receipt printing page (two different parameter types: `invoice` or `view`)
4. `myCourses.php` - Student courses listing page

---

## Query Parameters Used

### payment.php
- `invoice` - The invoice number to pay
- Example: `payment.php?invoice=INV2024001`

### printReceipt.php
- `invoice` - Print invoice by invoice number (for new/unpaid invoices)
- `view` - Print receipt by reference number (for completed payments)
- Examples:
  - `printReceipt.php?invoice=INV2024001`
  - `printReceipt.php?view=REF123456`

---

## Conditional Visibility

### New Invoice Section (Lines 200-289)
**Condition:** `$showNewInvoice && !empty($_SESSION['last_course_reg'])`
- This entire section only appears when:
  1. Student just completed course registration
  2. Session variable `show_course_invoice` is set to true
  3. Registration data is stored in `$_SESSION['last_course_reg']`

**Buttons in this section:**
- Pay Now → `payment.php?invoice=[INVOICE]`
- Print Invoice → `printReceipt.php?invoice=[INVOICE]` (new tab)
- View Registered Courses → `myCourses.php`

### Print Receipt Button (Line 366)
**Condition:** `!empty($r->reference_number)`
- Only appears for payment records that have a reference number
- Appears in each row of the payment records table

---

## Testing Checklist

To test all button redirects:

### Tab Navigation
- [ ] Click "Balance Statement" tab → Should go to `balanceStatement.php`

### New Invoice Section (after course registration)
- [ ] Click "Pay Now" → Should go to `payment.php` with invoice parameter
- [ ] Click "Print Invoice" → Should open `printReceipt.php` in new tab with invoice parameter
- [ ] Click "View Registered Courses" → Should go to `myCourses.php`

### Payment Records Table
- [ ] Click printer icon for any payment → Should open `printReceipt.php` in new tab with view parameter
- [ ] Verify printer icon only appears when reference_number exists

---

## Common Issues to Check

1. **Relative Path Issues**
   - All redirects use relative paths (no `../`)
   - Files should be in same `students/` directory
   - If files are missing, links will result in 404 errors

2. **Missing Parameters**
   - `payment.php` requires `invoice` parameter
   - `printReceipt.php` requires either `invoice` or `view` parameter
   - Empty parameters will cause errors in target pages

3. **Session Dependencies**
   - New invoice section requires `$_SESSION['last_course_reg']`
   - If session expires, section won't display

4. **Database Dependencies**
   - Print receipt button requires `reference_number` from database
   - If reference_number is NULL or empty, button won't appear

---

## Debug Mode

Enable debug mode by adding `?debug=true` to URL:
```
http://localhost/wucportal/students/fees.php?debug=true
```

This will show:
- Session Sid value
- Database connection status
- Database host info

---

## Related Files to Examine

If buttons aren't working as expected, check these files:
1. `students/payment.php` - Payment processing logic
2. `students/printReceipt.php` - Receipt printing logic
3. `students/balanceStatement.php` - Balance statement page
4. `students/myCourses.php` - Course listing page
5. `students/includes/navbar.php` - Navigation bar (loaded on line 186)
6. `students/includes/guard.php` - Session validation (loaded on line 2)
7. `includes/finance_guard.php` - Finance access control (loaded on line 4)
