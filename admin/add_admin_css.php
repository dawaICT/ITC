<?php
// This script adds the admin.css stylesheet to all PHP files in the admin directory
// that don't already have it included.

// Setup error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');

$directory = __DIR__;
$count = 0;
$skipped = 0;
$errors = [];

echo "<h2>Admin CSS Stylesheet Integration</h2>";
echo "<p>Adding admin.css stylesheet to all admin PHP files...</p>";

// Get all PHP files in the admin directory
$files = glob($directory . '/*.php');

foreach ($files as $file) {
    // Skip this utility script
    if (basename($file) == 'add_admin_css.php') {
        continue;
    }
    
    // Read file content
    $content = file_get_contents($file);
    
    // Check if admin.css is already included
    if (strpos($content, "assets/css/admin.css") !== false) {
        echo "<p>Skipped: " . basename($file) . " (already includes admin.css)</p>";
        $skipped++;
        continue;
    }
    
    // Find the require header.php line (if it exists)
    $pattern = '/require\s+[\'"]includes\/header\.php[\'"]\s*;/';
    
    if (preg_match($pattern, $content)) {
        // Add admin.css link after header include
        $replacement = "$0\n// Add link to admin.css stylesheet\necho '<link rel=\"stylesheet\" href=\"../assets/css/admin.css\">';\n";
        $new_content = preg_replace($pattern, $replacement, $content);
        
        // Write the updated content back to the file
        if (file_put_contents($file, $new_content)) {
            echo "<p>Updated: " . basename($file) . "</p>";
            $count++;
        } else {
            $errors[] = "Failed to write to " . basename($file);
        }
    } else {
        // If header.php is not included, we need to find another insertion point
        // Look for a common pattern like opening PHP tag
        $pattern = '/<\?php/';
        
        if (preg_match($pattern, $content)) {
            // Add admin.css link after the opening PHP tag
            $replacement = "$0\n// Add link to admin.css stylesheet\necho '<link rel=\"stylesheet\" href=\"../assets/css/admin.css\">';\n";
            $new_content = preg_replace($pattern, $replacement, $content, 1);
            
            // Write the updated content back to the file
            if (file_put_contents($file, $new_content)) {
                echo "<p>Updated: " . basename($file) . "</p>";
                $count++;
            } else {
                $errors[] = "Failed to write to " . basename($file);
            }
        } else {
            $errors[] = "Could not find insertion point in " . basename($file);
        }
    }
}

echo "<hr>";
echo "<p><strong>Summary:</strong></p>";
echo "<p>Total files processed: " . count($files) . "</p>";
echo "<p>Files updated: $count</p>";
echo "<p>Files skipped: $skipped</p>";

if (count($errors) > 0) {
    echo "<p><strong>Errors:</strong></p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>$error</li>";
    }
    echo "</ul>";
}

echo "<p>Done. <a href=\"index.php\">Go back to admin dashboard</a></p>";
?> 