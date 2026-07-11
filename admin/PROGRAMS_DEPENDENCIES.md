# Programs.php Required Dependencies

## Critical Frontend Libraries

Your `includes/header.php` **MUST** include the following libraries in this order:

### 1. Bootstrap 5 (CSS & JS)
```html
<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap JS Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
```

### 2. FontAwesome (Icons)
```html
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
```

### 3. jQuery (Required by DataTables)
```html
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
```

### 4. DataTables with Buttons Extension
```html
<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<!-- DataTables Buttons -->
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<!-- Required for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
```

### 5. Chart.js (For Enrollment Graph)
```html
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
```

---

## Load Order (Critical!)

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 1. Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- 2. FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- 3. DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
</head>
<body>
    <!-- Your content -->
    
    <!-- JavaScript at end of body -->
    <!-- 1. jQuery FIRST -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <!-- 2. Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- 3. Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
    
    <!-- 4. JSZip (for Excel export) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    
    <!-- 5. DataTables Core -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- 6. DataTables Buttons -->
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
</body>
</html>
```

---

## Testing the Dependencies

Add this to a test page to verify all libraries load correctly:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Dependency Test</title>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <h1>Dependency Test</h1>
    <div id="results"></div>
    
    <script>
        let results = [];
        
        // Test jQuery
        if (typeof $ !== 'undefined') {
            results.push('✅ jQuery loaded (v' + $.fn.jquery + ')');
        } else {
            results.push('❌ jQuery NOT loaded');
        }
        
        // Test Chart.js
        if (typeof Chart !== 'undefined') {
            results.push('✅ Chart.js loaded');
        } else {
            results.push('❌ Chart.js NOT loaded');
        }
        
        // Test DataTables
        if (typeof $.fn.DataTable !== 'undefined') {
            results.push('✅ DataTables loaded');
        } else {
            results.push('❌ DataTables NOT loaded');
        }
        
        document.getElementById('results').innerHTML = results.join('<br>');
    </script>
</body>
</html>
```

---

## Browser Console Checks

Open programs.php and check the browser console (F12):

### ✅ Success (No errors):
```
Chart canvas not found. Chart will not be rendered.
```
OR
```
(No messages - chart renders successfully)
```

### ❌ Failure (Errors to watch for):
```
Uncaught ReferenceError: $ is not defined
Uncaught ReferenceError: Chart is not defined
Uncaught TypeError: $(...).DataTable is not a function
```

---

## Database Schema Verification

Run this SQL to verify your tables are correct:

```sql
-- Check programs table structure
DESCRIBE programs;

-- Check departments table
DESCRIBE departments;

-- Check student_program table
DESCRIBE student_program;

-- Verify program_duration can handle 0.25 (3 months)
SELECT COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'programs' 
AND COLUMN_NAME = 'program_duration';
-- Should return: decimal(4,2)
```

---

## Bug Fixes Implemented

1. ✅ **Redirect Logic** - Now checks `headers_sent()` and uses `PHP_SELF`
2. ✅ **Chart.js Safety** - Added canvas existence check before initialization
3. ✅ **SQL Precision** - Changed DECIMAL(4,1) to DECIMAL(4,2) for 3-month certificates
4. ✅ **Update Validation** - Ensures `original_code` is never empty
5. ✅ **Console Warnings** - Added helpful debug messages

---

## Next Steps

1. Check `includes/header.php` has all dependencies
2. Run browser console test
3. Verify database schema with SQL above
4. Test adding a 3-month certificate program (0.25 years)
5. Test Excel export and Print buttons
