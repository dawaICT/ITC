# Migration Guide: Applying Dashboard Improvements to Other Pages

This guide helps you apply the security, accessibility, and performance improvements from `students/index.php` to other pages in the WUC Portal.

## 📋 Step-by-Step Migration Process

### Step 1: Identify Target Pages
List all student-facing PHP pages that need updates:
- [ ] `editProfile.php`
- [ ] `editStudent.php`
- [ ] `courseRegistration.php`
- [ ] `viewResults.php`
- [ ] `payments.php`
- [ ] Other pages in `/students/` directory

### Step 2: Security Headers (5 minutes per page)

**Add to the TOP of every PHP file (before any output):**

```php
<?php
// Session security configuration
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict'
]);

if (session_status() === PHP_SESSION_NONE) { 
    session_start();
    if (!isset($_SESSION['_initialized'])) {
        session_regenerate_id(true);
        $_SESSION['_initialized'] = true;
    }
}

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-XSS-Protection: 1; mode=block");

require_once __DIR__ . '/../db/connect.php';

// Check database connection
if ($db->connect_error) {
    error_log("Database connection failed: " . $db->connect_error);
    die("System error. Please try again later.");
}
```

### Step 3: Input Validation (10 minutes per page)

**Find and replace session checks:**

```php
// OLD (vulnerable)
if (!isset($_SESSION['Sid'])) {
    header('Location: ../studentLogin.php');
    exit();
}

// NEW (secure)
if (!isset($_SESSION['Sid']) || empty($_SESSION['Sid'])) {
    header('Location: ../studentLogin.php');
    exit();
}

// Validate student ID format (alphanumeric with hyphens/underscores)
if (!preg_match('/^[A-Za-z0-9\-_]+$/', $_SESSION['Sid'])) {
    error_log("Invalid student ID format: " . $_SESSION['Sid']);
    session_destroy();
    header('Location: ../studentLogin.php');
    exit();
}
```

**Validate GET/POST parameters:**

```php
// Example: Course code validation
if (isset($_GET['course_code'])) {
    if (!preg_match('/^[A-Z]{3}[0-9]{3}$/', $_GET['course_code'])) {
        die("Invalid course code format");
    }
    $course_code = $_GET['course_code'];
}

// Example: Year validation
if (isset($_POST['year'])) {
    $year = filter_var($_POST['year'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 10]
    ]);
    if ($year === false) {
        die("Invalid year");
    }
}
```

### Step 4: Convert to Prepared Statements (15-30 minutes per page)

**Find all database queries and convert:**

#### Pattern 1: Simple SELECT
```php
// OLD (vulnerable)
$sid = $db->real_escape_string($_SESSION['Sid']);
$query = "SELECT * FROM courses WHERE student_id = '$sid'";
$result = $db->query($query);

// NEW (secure)
$query = "SELECT course_code, course_name, credits FROM courses WHERE student_id = ?";
$stmt = $db->prepare($query);
if (!$stmt) {
    error_log("Failed to prepare query: " . $db->error);
    die("System error. Please try again later.");
}
$stmt->bind_param("s", $_SESSION['Sid']);
$stmt->execute();
$result = $stmt->get_result();
// ... use result ...
$stmt->close();
```

#### Pattern 2: INSERT
```php
// OLD (vulnerable)
$name = $db->real_escape_string($_POST['name']);
$email = $db->real_escape_string($_POST['email']);
$query = "INSERT INTO students (name, email) VALUES ('$name', '$email')";
$db->query($query);

// NEW (secure)
$query = "INSERT INTO students (name, email) VALUES (?, ?)";
$stmt = $db->prepare($query);
if (!$stmt) {
    error_log("Failed to prepare insert: " . $db->error);
    die("System error. Please try again later.");
}
$stmt->bind_param("ss", $_POST['name'], $_POST['email']);
if (!$stmt->execute()) {
    error_log("Failed to insert: " . $stmt->error);
    die("System error. Please try again later.");
}
$stmt->close();
```

#### Pattern 3: UPDATE
```php
// OLD (vulnerable)
$id = $db->real_escape_string($_POST['id']);
$status = $db->real_escape_string($_POST['status']);
$query = "UPDATE students SET status = '$status' WHERE id = '$id'";
$db->query($query);

// NEW (secure)
$query = "UPDATE students SET status = ? WHERE id = ?";
$stmt = $db->prepare($query);
if (!$stmt) {
    error_log("Failed to prepare update: " . $db->error);
    die("System error. Please try again later.");
}
$stmt->bind_param("ss", $_POST['status'], $_POST['id']);
if (!$stmt->execute()) {
    error_log("Failed to update: " . $stmt->error);
    die("System error. Please try again later.");
}
echo "Updated " . $stmt->affected_rows . " row(s)";
$stmt->close();
```

#### Pattern 4: DELETE
```php
// OLD (vulnerable)
$id = $db->real_escape_string($_GET['id']);
$query = "DELETE FROM students WHERE id = '$id'";
$db->query($query);

// NEW (secure)
$query = "DELETE FROM students WHERE id = ?";
$stmt = $db->prepare($query);
if (!$stmt) {
    error_log("Failed to prepare delete: " . $db->error);
    die("System error. Please try again later.");
}
$stmt->bind_param("s", $_GET['id']);
if (!$stmt->execute()) {
    error_log("Failed to delete: " . $stmt->error);
    die("System error. Please try again later.");
}
$stmt->close();
```

### Step 5: Output Escaping (5 minutes per page)

**Find all echo/print statements and add escaping:**

```php
// OLD (XSS vulnerable)
echo "<h1>Welcome " . $student_name . "</h1>";
echo "<a href='profile.php?id=" . $student_id . "'>Profile</a>";

// NEW (secure)
echo "<h1>Welcome " . htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8') . "</h1>";
echo "<a href='profile.php?id=" . urlencode($student_id) . "'>Profile</a>";
```

**Use this helper function for consistency:**

```php
// Add to a shared include file (e.g., functions.php)
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Usage
echo "<h1>Welcome " . h($student_name) . "</h1>";
```

### Step 6: Accessibility Improvements (10-15 minutes per page)

#### 6.1: Add Semantic HTML
```html
<!-- OLD -->
<div class="header">
    <div class="title">Dashboard</div>
</div>
<div class="content">...</div>

<!-- NEW -->
<header role="banner">
    <h1>Dashboard</h1>
</header>
<main role="main">...</main>
```

#### 6.2: Add ARIA Labels
```html
<!-- Buttons -->
<button type="submit" aria-label="Submit registration form">
    <i class="fa fa-check" aria-hidden="true"></i> Submit
</button>

<!-- Links -->
<a href="profile.php" aria-label="Edit your profile">
    <i class="fa fa-edit" aria-hidden="true"></i>
</a>

<!-- Form inputs -->
<label for="email">Email Address</label>
<input type="email" id="email" name="email" aria-required="true">

<!-- Tables -->
<table role="table" aria-label="Course registration">
    <thead>
        <tr>
            <th scope="col">Course Code</th>
            <th scope="col">Course Name</th>
        </tr>
    </thead>
</table>
```

#### 6.3: Improve Forms
```html
<form method="post" action="register.php" aria-label="Course registration form">
    <fieldset>
        <legend>Select Courses</legend>
        
        <div class="form-group">
            <label for="course1">Course 1</label>
            <select id="course1" name="course1" aria-required="true">
                <option value="">Select a course</option>
                <!-- options -->
            </select>
            <small id="course1-help">Choose your first course</small>
        </div>
        
        <button type="submit" aria-label="Submit course selection">
            Register
        </button>
    </fieldset>
</form>
```

### Step 7: Extract CSS/JS (20-30 minutes per page)

#### 7.1: Move inline styles to external file
```php
// OLD
<style>
    .card { background: white; }
    /* 200 more lines */
</style>

// NEW
<link rel="stylesheet" href="css/page-name.css">
```

#### 7.2: Move inline scripts to external file
```php
// OLD
<script>
    function doSomething() { ... }
    // 100 more lines
</script>

// NEW
<script src="js/page-name.js"></script>
```

#### 7.3: Organize files
```
students/
├── css/
│   ├── dashboard.css (shared)
│   ├── profile.css
│   └── registration.css
└── js/
    ├── dashboard.js (shared)
    ├── profile.js
    └── registration.js
```

### Step 8: Performance Optimizations

#### 8.1: Optimize queries
```php
// OLD - Select all columns (wasteful)
$query = "SELECT * FROM students WHERE ...";

// NEW - Select only needed columns
$query = "SELECT SID, Fname, Lname, email FROM students WHERE ...";
```

#### 8.2: Add image optimization
```html
<!-- Add to all images -->
<img src="photo.jpg" 
     alt="Student photo"
     loading="lazy"
     width="200"
     height="200"
     onerror="this.src='default.jpg'">
```

#### 8.3: Add font preconnect
```html
<head>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Other head elements -->
</head>
```

## 🔍 Testing Each Migrated Page

### Security Testing
```bash
# Test SQL injection
http://localhost/wucportal/students/page.php?id=' OR '1'='1

# Test XSS
http://localhost/wucportal/students/page.php?name=<script>alert('XSS')</script>

# Check headers
curl -I http://localhost/wucportal/students/page.php
```

### Accessibility Testing
1. Navigate with Tab key only
2. Test with NVDA/JAWS screen reader
3. Check color contrast (use browser DevTools)
4. Zoom to 200%
5. Test on mobile device

### Performance Testing
1. Run Chrome Lighthouse
2. Check query execution time
3. Verify CSS/JS caching
4. Test on slow connection

## 📊 Migration Tracker

Create a spreadsheet to track progress:

| Page | Security Headers | Input Validation | Prepared Statements | Output Escaping | Accessibility | CSS/JS External | Tested | Status |
|------|-----------------|------------------|---------------------|-----------------|---------------|-----------------|--------|--------|
| index.php | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | Complete |
| editProfile.php | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | Pending |
| courseRegistration.php | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | Pending |

## 🚨 Common Pitfalls

### 1. Forgetting to close statements
```php
// WRONG - Memory leak
$stmt = $db->prepare($query);
$stmt->execute();
// ... forgot $stmt->close();

// RIGHT
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
// ... use result ...
$stmt->close(); // Always close!
```

### 2. Wrong bind_param types
```php
// WRONG
$stmt->bind_param("s", $year); // Year is integer, not string

// RIGHT
$stmt->bind_param("i", $year); // i = integer
// Types: s=string, i=integer, d=double, b=blob
```

### 3. Missing error checks
```php
// WRONG
$stmt = $db->prepare($query);
$stmt->execute(); // What if prepare() failed?

// RIGHT
$stmt = $db->prepare($query);
if (!$stmt) {
    error_log("Prepare failed: " . $db->error);
    die("System error");
}
```

### 4. Inline styles in loop
```php
// WRONG - Hard to maintain
foreach ($students as $s) {
    echo "<div style='padding:10px;margin:5px;'>" . h($s->name) . "</div>";
}

// RIGHT - Use CSS class
foreach ($students as $s) {
    echo "<div class='student-card'>" . h($s->name) . "</div>";
}
```

## 📚 Resources

- **Project Copilot Instructions**: `.github/copilot-instructions.md`
- **Dashboard Implementation**: `students/DASHBOARD_IMPROVEMENTS.md`
- **Quick Reference**: `students/QUICK_REFERENCE.md`
- **PHP Manual - Prepared Statements**: https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
- **OWASP PHP Security**: https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html

## 🎯 Priority Order

Migrate pages in this order (highest risk first):

1. **Authentication pages** (login, logout) - Highest risk
2. **Payment pages** - Financial data
3. **Profile/Edit pages** - Personal data
4. **Registration pages** - Student data
5. **Dashboard/View pages** - Lowest risk

## ✅ Definition of "Done"

A page is fully migrated when:
- [ ] Security headers implemented
- [ ] All queries use prepared statements
- [ ] Input validation on all $_GET/$_POST
- [ ] Output escaping on all echo statements
- [ ] ARIA labels on interactive elements
- [ ] Semantic HTML tags used
- [ ] CSS moved to external file
- [ ] JavaScript moved to external file
- [ ] Images have alt text and lazy loading
- [ ] No console errors
- [ ] Lighthouse score 90+
- [ ] Keyboard navigation works
- [ ] Screen reader tested

---

**Estimated Time**: 1-2 hours per page (depending on complexity)  
**Total WUC Portal**: ~20 pages = 20-40 hours of migration work
