# WUC Portal - Styling Quick Reference & Best Practices

## 🎨 Color System

### Primary Colors
```css
/* Main Purple Theme */
--primary-purple: #6f42c1;        /* Main brand color */
--primary-purple-hover: #5a32a3;  /* Hover state */
--primary-purple-dark: #4b006e;   /* Dark variant */
--primary-purple-darker: #3a0057; /* Darkest variant */
--primary-purple-light: rgba(111, 66, 193, 0.1);
```

### Status Colors
```css
--success: #28a745;   /* Green - positive actions */
--danger: #dc3545;    /* Red - destructive actions */
--warning: #ffc107;   /* Yellow - alerts & warnings */
--info: #17a2b8;      /* Blue - information */
```

### Neutral Colors
```css
--dark: #333333;
--light: #f8f9fa;
--text-primary: #2c2c2c;
--text-secondary: #6c757d;
--text-muted: #999999;
--bg-light: #f8f9fa;
--bg-white: #ffffff;
--border-color: #dee2e6;
```

## 📏 Spacing System

### Margin Classes
```html
<!-- Unified spacing: 0.5rem, 1rem, 1.5rem, 2rem, 3rem -->
<div class="m-1">All sides: 0.5rem</div>
<div class="m-2">All sides: 1rem</div>
<div class="mt-3">Top margin: 1.5rem</div>
<div class="mx-2">Left & Right: 1rem</div>
<div class="my-auto">Top & Bottom: auto</div>
```

### Padding Classes
```html
<div class="p-2">All sides: 1rem</div>
<div class="px-3">Left & Right: 1.5rem</div>
<div class="py-1">Top & Bottom: 0.5rem</div>
```

### Gap (Flexbox)
```html
<div class="d-flex gap-2">
  <div>Item 1</div>
  <div>Item 2</div>
</div>
```

## 🔤 Typography

### Font Weights
```html
<span class="fw-300">Light text</span>
<span class="fw-500">Medium text</span>
<span class="fw-600">Semi-bold text</span>
<span class="fw-700">Bold text</span>
```

### Font Sizes
```html
<p class="fs-xs">Extra small: 0.75rem</p>
<p class="fs-sm">Small: 0.875rem</p>
<p class="fs-base">Base: 1rem</p>
<p class="fs-lg">Large: 1.125rem</p>
<p class="fs-xl">Extra Large: 1.25rem</p>
```

## 🖼️ Layout Utilities

### Display Classes
```html
<div class="d-none">Hidden</div>
<div class="d-block">Block element</div>
<div class="d-flex">Flexbox container</div>
<div class="d-grid">Grid container</div>
```

### Flexbox
```html
<div class="d-flex flex-column gap-2">
  <!-- Flex column with gap -->
</div>

<div class="d-flex justify-content-between align-items-center">
  <!-- Spread items apart, center vertically -->
</div>
```

### Sizing
```html
<div class="w-100">Full width</div>
<div class="h-100">Full height</div>
<div class="w-50">50% width</div>
```

## 🎯 Position Utilities

### Position Classes
```html
<div class="position-relative">
  <div class="position-absolute bottom-0 end-0">
    Corner positioned element
  </div>
</div>
```

### Position Values
- `.top-0`, `.end-0`, `.bottom-0`, `.start-0`

## 🎨 Border & Shadow Utilities

### Borders
```html
<div class="border">All borders</div>
<div class="border-top">Top border only</div>
<div class="rounded">8px border radius</div>
<div class="rounded-3">12px border radius</div>
<div class="rounded-circle">Circular</div>
```

### Shadows
```html
<div class="shadow-sm">Small shadow</div>
<div class="shadow">Medium shadow (default)</div>
<div class="shadow-lg">Large shadow</div>
```

## 📊 Card Styling

### Basic Card
```html
<div class="card shadow-sm">
  <div class="card-header bg-light">
    <h5 class="mb-0">Card Title</h5>
  </div>
  <div class="card-body">
    <!-- Content here -->
  </div>
  <div class="card-footer bg-light">
    <!-- Footer content -->
  </div>
</div>
```

## ✅ DO's and ❌ DON'Ts

### ✅ DO Use
```html
<!-- Good: Use utility classes -->
<div class="d-flex gap-2 p-3 rounded bg-light">
  <span class="fw-600 text-primary">Correct</span>
</div>

<!-- Good: Use CSS variables -->
<style>
  .my-component {
    color: var(--text-primary);
    padding: var(--spacing-unit);
  }
</style>
```

### ❌ DON'T Use
```html
<!-- Bad: Inline styles -->
<div style="display: flex; gap: 8px; padding: 12px;">

<!-- Bad: Hardcoded colors -->
<div style="color: #6f42c1; background-color: #f8f9fa;">

<!-- Bad: Magic numbers -->
<div style="width: 250px; height: 120px;">
```

## 🔧 Common Patterns

### Alert Box
```html
<div class="alert alert-success" role="alert">
  <i class="fas fa-check-circle me-2"></i>
  <strong>Success!</strong> Operation completed.
</div>
```

### Button Group
```html
<div class="btn-group gap-1" role="group">
  <button class="btn btn-primary">Primary</button>
  <button class="btn btn-secondary">Secondary</button>
  <button class="btn btn-danger">Delete</button>
</div>
```

### Table
```html
<div class="table-responsive">
  <table class="table table-hover">
    <thead class="table-light">
      <tr>
        <th>Column 1</th>
        <th>Column 2</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Data 1</td>
        <td>Data 2</td>
      </tr>
    </tbody>
  </table>
</div>
```

### Badge
```html
<span class="badge bg-success">Active</span>
<span class="badge bg-warning text-dark">Pending</span>
<span class="badge bg-danger">Archived</span>
```

## 📱 Responsive Design

### Breakpoints
- Extra small: `< 576px` - Mobile phones
- Small: `≥ 576px` - Landscape phones
- Medium: `≥ 768px` - Tablets
- Large: `≥ 992px` - Desktops
- Extra large: `≥ 1200px` - Large desktops

### Responsive Classes
```html
<!-- Hidden on small screens, visible on medium+ -->
<div class="d-none d-md-block">
  Desktop only content
</div>

<!-- Visible on small screens, hidden on medium+ -->
<div class="d-md-none">
  Mobile only content
</div>

<!-- Responsive sizing -->
<div class="col-12 col-md-6 col-lg-4">
  Responsive columns
</div>
```

## 🎨 Customization

### Override Variables
```css
:root {
  --primary-purple: #your-color;
  --border-radius: 4px; /* Change from 8px */
  --sidebar-width: 300px; /* Change sidebar width */
}
```

### Add New Utilities
Add to `css/consistent-styles.css`:
```css
.my-custom-class {
  /* Your styles */
  color: var(--text-primary);
  padding: var(--spacing-unit);
}
```

## 📚 CSS Files Hierarchy

1. **main.css** - Base styles, variables (loaded first)
2. **layout.css** - Layout structure
3. **dashboard.css** - Dashboard specific
4. **components.css** - Component styles
5. **forms.css** - Form elements
6. **tables.css** - Table styles
7. **consistent-styles.css** - Utility classes (loaded last)

## 🚀 Performance Tips

1. Use CSS variables instead of repeated values
2. Use utility classes instead of inline styles
3. Minimize specificity (avoid `!important`)
4. Use semantic HTML
5. Cache CSS files with version parameters: `?v=<?php echo time(); ?>`

## 🐛 Debugging

### Check Applied Styles
1. Open DevTools (F12)
2. Inspect element
3. Look for conflicting styles
4. Check CSS cascade order

### Common Issues
- **Colors not changing**: Check if another rule has higher specificity
- **Layout broken**: Check if margins/paddings are collapsing
- **Text not visible**: Check text color vs background
- **Unresponsive**: Check media query breakpoints

## 📖 Additional Resources

- CSS Variables: `css/main.css` (lines 8-59)
- Spacing System: `css/consistent-styles.css` (lines 1-100)
- Bootstrap 5: https://getbootstrap.com/docs/5.3
- Font Awesome: https://fontawesome.com/icons
