# WUC Portal CSS Structure

This document provides an overview of the CSS structure for the WUC Portal.

## CSS Files

### Core Files
- **main.css**: Base styles, variables, typography, layout, and utilities
- **sidebar.css**: All sidebar navigation styles
- **components.css**: Reusable UI components like buttons, alerts, badges, etc.
- **forms.css**: Form elements, inputs, validations
- **tables.css**: Table styles, data display
- **dashboard.css**: Dashboard-specific components

## How to Use

1. Include the required CSS files in your PHP header using the `includes/styles-include.php` file.
2. To use the sidebar, add the following HTML structure:

```html
<div class="app-container">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="logo">
                    <img src="/path/to/logo.png" alt="WUC Logo">
                </div>
                <div class="logo-text">WUC Portal</div>
            </div>
        </div>
        
        <div class="sidebar-content">
            <!-- Nav Sections -->
            <div class="nav-section">
                <div class="nav-section-title">Main Navigation</div>
                
                <!-- Nav Items -->
                <a href="dashboard.php" class="nav-item active">
                    <i class="fa fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="students.php" class="nav-item">
                    <i class="fa fa-user-graduate"></i>
                    <span>Students</span>
                </a>
                
                <!-- Add more nav items as needed -->
            </div>
        </div>
        
        <!-- Optional User Info -->
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <img src="/path/to/avatar.png" alt="User Avatar">
                </div>
                <div class="user-details">
                    <span class="user-name">John Doe</span>
                    <span class="user-role">Administrator</span>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Include the sidebar toggle button for mobile -->
    <?php include 'includes/sidebar-toggle.php'; ?>
    
    <!-- Main Content -->
    <main class="main-wrapper">
        <div class="main-content">
            <!-- Your page content here -->
        </div>
    </main>
</div>
```

## CSS Variables

The CSS uses a system of variables defined in `main.css` that can be used throughout the application. Some key variables include:

- `--primary-purple`: Main theme color
- `--sidebar-width`: Width of the sidebar
- `--sidebar-bg`: Background gradient for sidebar
- `--border-radius`: Default border radius for components
- `--shadow`: Default box shadow
- `--transition-speed`: Default transition speed

## Responsive Behavior

The CSS is designed to be responsive. On mobile devices:
- The sidebar is hidden by default and can be toggled
- The toggle button appears in the top left corner
- The main content spans the full width

## Browser Support

This CSS is designed to work in modern browsers including:
- Chrome 60+
- Firefox 54+
- Safari 10+
- Edge 15+

## Migration Notes

If you're migrating from the old CSS structure:

1. Replace navbar references with sidebar
2. Update color variables to use the new CSS variables
3. Replace direct color references with variable references
4. Update layout classes to use the new grid system
