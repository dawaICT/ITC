/**
 * Student Dashboard JavaScript
 * WUC Portal - Enhanced Dropdown and Accessibility
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize dropdown functionality
    initializeDropdown();
    
    // Initialize loading overlay
    initializeLoadingOverlay();
});

/**
 * Enhanced dropdown with keyboard accessibility
 */
function initializeDropdown() {
    const dropdownToggle = document.getElementById('userDropdown');
    const dropdownMenu = document.getElementById('userDropdownMenu');
    
    if (!dropdownToggle || !dropdownMenu) {
        return;
    }
    
    // Click handler
    dropdownToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        const isExpanded = dropdownMenu.classList.contains('show');
        toggleDropdown(!isExpanded);
    });
    
    // Keyboard accessibility
    dropdownToggle.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const isExpanded = dropdownMenu.classList.contains('show');
            toggleDropdown(!isExpanded);
        } else if (e.key === 'Escape') {
            toggleDropdown(false);
            dropdownToggle.focus();
        } else if (e.key === 'ArrowDown' && !dropdownMenu.classList.contains('show')) {
            e.preventDefault();
            toggleDropdown(true);
            focusFirstMenuItem();
        }
    });
    
    // Handle keyboard navigation within menu
    const menuItems = dropdownMenu.querySelectorAll('.dropdown-item');
    menuItems.forEach((item, index) => {
        item.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const nextItem = menuItems[index + 1] || menuItems[0];
                nextItem.focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prevItem = menuItems[index - 1] || menuItems[menuItems.length - 1];
                prevItem.focus();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                toggleDropdown(false);
                dropdownToggle.focus();
            } else if (e.key === 'Tab') {
                toggleDropdown(false);
            }
        });
    });
    
    // Close when clicking outside
    document.addEventListener('click', function(e) {
        if (!dropdownToggle.contains(e.target) && !dropdownMenu.contains(e.target)) {
            toggleDropdown(false);
        }
    });
    
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            toggleDropdown(false);
        }
    });
    
    /**
     * Toggle dropdown visibility
     * @param {boolean} show - Whether to show the dropdown
     */
    function toggleDropdown(show) {
        if (show) {
            dropdownMenu.classList.add('show');
            dropdownToggle.setAttribute('aria-expanded', 'true');
        } else {
            dropdownMenu.classList.remove('show');
            dropdownToggle.setAttribute('aria-expanded', 'false');
        }
    }
    
    /**
     * Focus the first menu item
     */
    function focusFirstMenuItem() {
        const firstItem = dropdownMenu.querySelector('.dropdown-item');
        if (firstItem) {
            firstItem.focus();
        }
    }
}

/**
 * Loading overlay functionality
 */
function initializeLoadingOverlay() {
    window.showLoading = function() {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.display = 'flex';
            document.body.classList.add('loading');
        }
    };
    
    window.hideLoading = function() {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.display = 'none';
            document.body.classList.remove('loading');
        }
    };
}

/**
 * Handle profile image loading errors
 */
function handleImageError(img) {
    img.src = '../uploads/profile/default.jpg';
    img.alt = 'Default profile image';
}

// Export functions for global use
window.handleImageError = handleImageError;
