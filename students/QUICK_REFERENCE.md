# Quick Reference - Student Dashboard Security & Best Practices

## 🔒 Security Checklist (Use for ALL PHP pages)

### 1. Session Security (Copy to every page)
```php
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
```

### 2. Security Headers (Add to all pages)
```php
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-XSS-Protection: 1; mode=block");
```

### 3. Input Validation Pattern
```php
// Validate before use
if (!preg_match('/^[A-Za-z0-9\-_]+$/', $input)) {
    error_log("Invalid input: " . $input);
    header('Location: error.php');
    exit();
}
```

### 4. Prepared Statement Template
```php
// ALWAYS use this pattern for database queries
$stmt = $db->prepare("SELECT col1, col2 FROM table WHERE id = ?");
if (!$stmt) {
    error_log("Query prep failed: " . $db->error);
    die("System error. Please try again.");
}
$stmt->bind_param("s", $value);  // s=string, i=integer, d=double
$stmt->execute();
$result = $stmt->get_result();
// ... use result ...
$stmt->close();
```

### 5. Output Escaping (NEVER skip)
```php
// HTML content
echo htmlspecialchars($user_input, ENT_QUOTES, 'UTF-8');

// URLs
echo urlencode($user_input);

// JavaScript (if needed)
echo json_encode($user_input, JSON_HEX_TAG | JSON_HEX_AMP);
```

## ♿ Accessibility Patterns

### Button/Link
```html
<a href="profile.php" 
   class="btn" 
   aria-label="Edit your profile">
    <i class="fa fa-edit" aria-hidden="true"></i> Edit
</a>
```

### Form Input
```html
<label for="email">Email Address</label>
<input type="email" 
       id="email" 
       name="email" 
       aria-required="true"
       aria-describedby="email-help">
<small id="email-help">We'll never share your email.</small>
```

### Dropdown
```html
<button aria-expanded="false" 
        aria-haspopup="true" 
        aria-controls="menu-id">
    Menu
</button>
<ul id="menu-id" role="menu" hidden>
    <li role="none">
        <a role="menuitem">Item 1</a>
    </li>
</ul>
```

### Live Region (for dynamic updates)
```html
<div role="status" aria-live="polite" aria-atomic="true">
    <!-- Content that updates dynamically -->
</div>
```

## 🎨 CSS Best Practices

### Use Variables
```css
:root {
    --primary: #6f42c1;
    --danger: #dc3545;
}
.btn { background: var(--primary); }
```

### Touch Targets (Minimum 44x44px)
```css
.btn, .link, .icon-button {
    min-width: 44px;
    min-height: 44px;
    padding: 12px 16px;
}
```

### Focus Indicators
```css
*:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}
```

### Responsive Grid
```css
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}
```

## 📱 Responsive Breakpoints

```css
/* Mobile First Approach */
/* Base styles for mobile (320px+) */

@media (min-width: 576px) { /* Small tablets */ }
@media (min-width: 768px) { /* Tablets */ }
@media (min-width: 992px) { /* Desktops */ }
@media (min-width: 1200px) { /* Large desktops */ }
```

## 🚀 Performance Tips

### Image Loading
```html
<img src="image.jpg" 
     alt="Description"
     loading="lazy"
     width="300"
     height="200"
     onerror="this.src='fallback.jpg'">
```

### Font Loading
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
```

### CSS/JS Organization
```
page.php (minimal inline styles)
├── css/page.css (all styles)
└── js/page.js (all scripts)
```

## 🐛 Common Mistakes to Avoid

### ❌ DON'T
```php
// Direct query - SQL injection risk
$result = $db->query("SELECT * FROM users WHERE id = '$id'");

// No output escaping - XSS risk
echo "<div>$user_input</div>";

// Inline styles/scripts - maintenance nightmare
<style>/* 500 lines */</style>

// No validation
$email = $_POST['email'];
sendEmail($email);

// Missing ARIA
<button onclick="delete()">Delete</button>
```

### ✅ DO
```php
// Prepared statement
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("s", $id);

// Always escape output
echo "<div>" . htmlspecialchars($user_input) . "</div>";

// External files
<link rel="stylesheet" href="css/style.css">

// Validate input
if (filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
    sendEmail($_POST['email']);
}

// Proper ARIA
<button onclick="delete()" 
        aria-label="Delete item">Delete</button>
```

## 📋 Pre-Deploy Checklist

- [ ] All queries use prepared statements
- [ ] All user input is validated
- [ ] All output is escaped (htmlspecialchars)
- [ ] Session security configured
- [ ] Security headers set
- [ ] Error logging enabled
- [ ] ARIA labels on interactive elements
- [ ] Touch targets 44x44px minimum
- [ ] Keyboard navigation works
- [ ] Images have alt text
- [ ] CSS/JS in external files
- [ ] Responsive on mobile
- [ ] Tested with screen reader
- [ ] No console errors

## 🔗 Resources

- **OWASP Top 10**: https://owasp.org/www-project-top-ten/
- **WCAG Guidelines**: https://www.w3.org/WAI/WCAG21/quickref/
- **PHP Security**: https://www.php.net/manual/en/security.php
- **MDN Accessibility**: https://developer.mozilla.org/en-US/docs/Web/Accessibility

## 💡 Quick Fixes

### Fix SQL Injection
1. Find all `$db->query()` calls
2. Convert to prepared statements
3. Use `bind_param()` for values

### Fix XSS
1. Find all `echo` statements with variables
2. Wrap in `htmlspecialchars()`
3. Test with `<script>alert('XSS')</script>`

### Fix Accessibility
1. Add `aria-label` to buttons/links
2. Use semantic HTML (`<nav>`, `<main>`, `<article>`)
3. Test with Tab key navigation

---

**Remember**: Security and accessibility are NOT optional. They protect users AND your application.
