<?php
/*
 * Admin Dashboard CSS Guide
 * This file demonstrates how to use the admin-dashboard.css components
 * in various admin pages for consistent styling.
 */
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard CSS Usage Guide</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .code-block {
            background: #f5f5f5;
            padding: 1rem;
            border-radius: 5px;
            border-left: 4px solid #6f42c1;
            overflow: auto;
            margin: 1rem 0;
        }
        
        h2 {
            margin-top: 2rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #eee;
        }
        
        .section {
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <h1>Admin Dashboard CSS Guide</h1>
    <p>This guide explains how to use the admin-dashboard.css stylesheet across various admin pages for consistent styling.</p>
    
    <div class="section">
        <h2>Including the CSS</h2>
        <p>First, include the CSS file in your PHP file after including the header:</p>
        <div class="code-block">
            <pre>
&lt;?php
include "includes/admin.php";
require 'includes/header.php';

// Link the admin dashboard stylesheet
echo '&lt;link rel="stylesheet" href="css/admin-dashboard.css"&gt;';
?&gt;
            </pre>
        </div>
    </div>
    
    <div class="section">
        <h2>Page Structure</h2>
        <p>Use the following structure for your pages:</p>
        <div class="code-block">
            <pre>
&lt;div class="container-fluid px-4 portal-dashboard"&gt;
    &lt;!-- Dashboard Header --&gt;
    &lt;div class="dashboard-header [section-name]-section mb-4"&gt;
        &lt;div class="row align-items-center"&gt;
            &lt;div class="col"&gt;
                &lt;h1 class="dashboard-title"&gt;Page Title&lt;/h1&gt;
                &lt;p class="text-muted"&gt;Page description here&lt;/p&gt;
            &lt;/div&gt;
            &lt;div class="col-auto"&gt;
                &lt;!-- Action buttons --&gt;
            &lt;/div&gt;
        &lt;/div&gt;
    &lt;/div&gt;
    
    &lt;!-- Page content goes here --&gt;
&lt;/div&gt;
            </pre>
        </div>
        <p>Available section classes for different admin areas:</p>
        <ul>
            <li><code>finance-section</code> - Green themed (for financial pages)</li>
            <li><code>student-section</code> - Blue themed (for student management)</li>
            <li><code>admin-section</code> - Purple themed (for administration)</li>
            <li><code>settings-section</code> - Gray themed (for settings pages)</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>Cards</h2>
        <p>Use the following card styles:</p>
        <div class="code-block">
            <pre>
&lt;div class="card shadow-sm admin-card [specific-card-type]"&gt;
    &lt;div class="card-header [section-name]-header bg-white py-3"&gt;
        &lt;h5 class="mb-0 text-primary"&gt;
            &lt;i class="fas fa-chart-line me-2"&gt;&lt;/i&gt;Card Title
        &lt;/h5&gt;
    &lt;/div&gt;
    &lt;div class="card-body"&gt;
        &lt;!-- Card content --&gt;
    &lt;/div&gt;
&lt;/div&gt;
            </pre>
        </div>
        <p>Available card type classes:</p>
        <ul>
            <li><code>selector-card</code> - For selection/filter components</li>
            <li><code>filter-card</code> - For filtering options</li>
            <li><code>data-card</code> - For displaying data</li>
            <li><code>chart-card</code> - For charts</li>
            <li><code>table-card</code> - For tables</li>
            <li><code>form-card</code> - For forms</li>
        </ul>
        
        <p>Card header types:</p>
        <ul>
            <li><code>finance-header</code> - Green themed</li>
            <li><code>student-header</code> - Blue themed</li>
            <li><code>admin-header</code> - Purple themed</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>Stat Cards</h2>
        <p>For statistical cards, use this structure:</p>
        <div class="code-block">
            <pre>
&lt;div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm"&gt;
    &lt;div class="d-flex align-items-center"&gt;
        &lt;div class="stat-icon bg-success rounded-circle p-3 me-3"&gt;
            &lt;i class="fas fa-coins fa-2x text-white"&gt;&lt;/i&gt;
        &lt;/div&gt;
        &lt;div&gt;
            &lt;h3 class="mb-1"&gt;$25,000&lt;/h3&gt;
            &lt;p class="text-muted mb-0"&gt;Total Revenue&lt;/p&gt;
            &lt;small class="text-success"&gt;
                &lt;i class="fas fa-arrow-up"&gt;&lt;/i&gt; 15% increase
            &lt;/small&gt;
        &lt;/div&gt;
    &lt;/div&gt;
&lt;/div&gt;
            </pre>
        </div>
        <p>Available icon background classes:</p>
        <ul>
            <li><code>bg-success</code> - Green</li>
            <li><code>bg-danger</code> - Red</li>
            <li><code>bg-primary</code> or <code>bg-info</code> - Blue</li>
            <li><code>bg-warning</code> - Yellow/Orange</li>
            <li><code>bg-purple</code> - Purple</li>
            <li><code>bg-secondary</code> - Gray</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>Progress Bars</h2>
        <p>Use the following structure for progress indicators:</p>
        <div class="code-block">
            <pre>
&lt;div class="progress-container"&gt;
    &lt;div class="progress mb-2"&gt;
        &lt;div class="progress-bar [color]-progress" 
             role="progressbar" 
             style="width: 75%" 
             aria-valuenow="75" 
             aria-valuemin="0" 
             aria-valuemax="100"&gt;
            75% Complete
        &lt;/div&gt;
    &lt;/div&gt;
&lt;/div&gt;
            </pre>
        </div>
        <p>Available progress bar colors:</p>
        <ul>
            <li>Default (primary color)</li>
            <li><code>success-progress</code> - Green</li>
            <li><code>info-progress</code> - Blue</li>
            <li><code>warning-progress</code> - Yellow</li>
            <li><code>danger-progress</code> - Red</li>
            <li><code>purple-progress</code> - Purple</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>Charts</h2>
        <p>For chart containers:</p>
        <div class="code-block">
            <pre>
&lt;div class="card shadow-sm h-100 chart-container"&gt;
    &lt;div class="card-header [section-name]-header bg-white py-3"&gt;
        &lt;h5 class="mb-0 text-primary"&gt;
            &lt;i class="fas fa-chart-line me-2"&gt;&lt;/i&gt;Chart Title
        &lt;/h5&gt;
    &lt;/div&gt;
    &lt;div class="card-body"&gt;
        &lt;canvas id="myChart"&gt;&lt;/canvas&gt;
    &lt;/div&gt;
&lt;/div&gt;
            </pre>
        </div>
    </div>
    
    <div class="section">
        <h2>Tables</h2>
        <p>For data tables:</p>
        <div class="code-block">
            <pre>
&lt;div class="card shadow-sm mb-4 data-table-container [section-name]-table"&gt;
    &lt;div class="card-header [section-name]-header bg-white py-3"&gt;
        &lt;h5 class="mb-0 text-primary"&gt;
            &lt;i class="fas fa-table me-2"&gt;&lt;/i&gt;Table Title
        &lt;/h5&gt;
    &lt;/div&gt;
    &lt;div class="card-body"&gt;
        &lt;div class="table-responsive"&gt;
            &lt;table class="table table-hover"&gt;
                &lt;!-- Table content --&gt;
            &lt;/table&gt;
        &lt;/div&gt;
    &lt;/div&gt;
&lt;/div&gt;
            </pre>
        </div>
        <p>Available table theme classes:</p>
        <ul>
            <li><code>finance-table</code> - Green hover effect</li>
            <li><code>student-table</code> - Blue hover effect</li>
            <li><code>admin-table</code> - Purple hover effect</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>Modals</h2>
        <p>For modals with themed headers:</p>
        <div class="code-block">
            <pre>
&lt;div class="modal fade" id="exampleModal" tabindex="-1" aria-hidden="true"&gt;
    &lt;div class="modal-dialog"&gt;
        &lt;div class="modal-content"&gt;
            &lt;div class="modal-header [section-name]-modal"&gt;
                &lt;h5 class="modal-title"&gt;Modal Title&lt;/h5&gt;
                &lt;button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"&gt;&lt;/button&gt;
            &lt;/div&gt;
            &lt;div class="modal-body"&gt;
                &lt;!-- Modal content --&gt;
            &lt;/div&gt;
            &lt;div class="modal-footer"&gt;
                &lt;!-- Modal buttons --&gt;
            &lt;/div&gt;
        &lt;/div&gt;
    &lt;/div&gt;
&lt;/div&gt;
            </pre>
        </div>
        <p>Available modal header theme classes:</p>
        <ul>
            <li><code>finance-modal</code> - Green themed</li>
            <li><code>student-modal</code> - Blue themed</li>
            <li><code>admin-modal</code> - Purple themed</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>Example: Converting Finance Page</h2>
        <p>To update the finance.php to use the new CSS:</p>
        <div class="code-block">
            <pre>
&lt;!-- Change this: --&gt;
&lt;div class="container-fluid px-4 finance-dashboard"&gt;

&lt;!-- To this: --&gt;
&lt;div class="container-fluid px-4 portal-dashboard"&gt;

&lt;!-- Change this: --&gt;
&lt;div class="dashboard-header mb-4"&gt;

&lt;!-- To this: --&gt;
&lt;div class="dashboard-header finance-section mb-4"&gt;

&lt;!-- Change this: --&gt;
&lt;div class="card shadow-sm period-selector"&gt;

&lt;!-- To this: --&gt;
&lt;div class="card shadow-sm admin-card selector-card"&gt;

&lt;!-- Update the card header: --&gt;
&lt;div class="card-header finance-header bg-white py-3"&gt;

&lt;!-- Update progress container: --&gt;
&lt;div class="financial-health-card"&gt;  → &lt;div class="progress-container"&gt;

&lt;!-- Update chart cards: --&gt;
&lt;div class="card shadow-sm h-100 chart-card"&gt;  → &lt;div class="card shadow-sm h-100 chart-container"&gt;

&lt;!-- Update table cards: --&gt;
&lt;div class="card shadow-sm mb-4 table-card"&gt;  → &lt;div class="card shadow-sm mb-4 data-table-container finance-table"&gt;

&lt;!-- Update filter modal: --&gt;
&lt;div class="modal-header"&gt;  → &lt;div class="modal-header finance-modal"&gt;
            </pre>
        </div>
    </div>
</body>
</html> 
