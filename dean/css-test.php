<?php
$page_title = 'CSS Test - Dean Dashboard';
require_once __DIR__ . '/includes/guard.php';
require "includes/nav.php";
?>

<div class="container-fluid px-4 portal-dashboard dean-dashboard dean-module">
        <style>
            /* Inline test styles */
            .css-test {
                background: #f0f0f0;
                padding: 20px;
                margin: 20px;
                border: 2px solid #333;
            }
            .css-test h1 { color: #6f42c1; }
            .css-test .test-element { 
                background: #6f42c1; 
                color: white; 
                padding: 10px; 
                margin: 10px 0;
                border-radius: 5px;
            }
        </style>
        <div class="css-test mb-4">
            <h1>CSS Loading Test</h1>
            <p>This page tests if the dean CSS is loading correctly.</p>
            
            <div class="test-element mb-3">
                <h3>Test Element 1</h3>
                <p>This should have dean styling applied.</p>
            </div>
            
            <div class="dashboard-header dean-section mb-4">
                <h2 class="dashboard-title">Test Dashboard Header</h2>
                <p>This should have the purple gradient background.</p>
            </div>
            
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Test Card Header</h5>
                    </div>
                </div>
                <div class="card-body">
                    <p>This card should have dean styling.</p>
                    <button class="btn btn-primary">Test Button</button>
                </div>
            </div>
            
            <div class="stat-icon bg-dean mb-4">
                <i class="fas fa-user-graduate"></i>
            </div>
            
            <h3 class="mb-3">CSS Variables Test:</h3>
            <ul>
                <li>Primary: <span style="color: var(--dean-primary);">#6f42c1</span></li>
                <li>Secondary: <span style="color: var(--dean-secondary);">#8c68cd</span></li>
                <li>Success: <span style="color: var(--dean-success);">#28a745</span></li>
            </ul>
            
            <h3>Debug Information:</h3>
            <div id="debug-info">
                <p>Check browser console for CSS loading information.</p>
                <p>Look for any red outlines around elements (indicates conflicts).</p>
                <p>Check if purple gradient appears on dashboard header.</p>
            </div>
        </div>
    </div>
    
    <script>
        // JavaScript to check CSS loading
        console.log('CSS Test Page Loaded');
        
        // Check if CSS variables are working
        const root = document.documentElement;
        const computedStyle = getComputedStyle(root);
        
        console.log('CSS Variables Check:');
        console.log('--dean-primary:', computedStyle.getPropertyValue('--dean-primary'));
        console.log('--dean-secondary:', computedStyle.getPropertyValue('--dean-secondary'));
        console.log('--dean-success:', computedStyle.getPropertyValue('--dean-success'));
        
        // Check if dean-module class is applied
        const deanModule = document.querySelector('.dean-module');
        if (deanModule) {
            console.log('✓ dean-module class found');
        } else {
            console.log('✗ dean-module class NOT found');
        }
        
        // Check if dean-dashboard class is applied
        const deanDashboard = document.querySelector('.dean-dashboard');
        if (deanDashboard) {
            console.log('✓ dean-dashboard class found');
        } else {
            console.log('✗ dean-dashboard class NOT found');
        }
        
        // Check for CSS conflicts
        const adminDashboard = document.querySelector('.admin-dashboard');
        if (adminDashboard && !adminDashboard.classList.contains('dean-dashboard')) {
            console.log('⚠ WARNING: admin-dashboard without dean-dashboard class found');
        }
        
        // Test responsive breakpoints
        function checkBreakpoint() {
            const width = window.innerWidth;
            if (width > 1200) {
                console.log('Breakpoint: Desktop (>1200px)');
            } else if (width > 991.98) {
                console.log('Breakpoint: Large Tablet (991.98px - 1200px)');
            } else if (width > 768) {
                console.log('Breakpoint: Tablet (768px - 991.98px)');
            } else {
                console.log('Breakpoint: Mobile (<768px)');
            }
        }
        
        checkBreakpoint();
        window.addEventListener('resize', checkBreakpoint);
    </script>
    <?php require "includes/footer.php"; ?>

