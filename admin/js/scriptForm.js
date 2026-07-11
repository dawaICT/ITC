// Form validation and submission handling
const form = document.querySelector('.form');

// File validation configuration
const fileConfig = {
    profile_image: {
        maxSize: 5 * 1024 * 1024,
        allowedTypes: ['image/jpeg', 'image/png'],
        errorMessage: 'Please select a JPEG or PNG image under 5MB'
    },
    nrc_file: {
        maxSize: 5 * 1024 * 1024,
        allowedTypes: ['application/pdf', 'image/jpeg', 'image/png'],
        errorMessage: 'Please select a PDF, JPEG or PNG file under 5MB'
    },
    results: {
        maxSize: 10 * 1024 * 1024,
        allowedTypes: ['application/pdf'],
        errorMessage: 'Please select a PDF file under 10MB'
    }
};

// Field validation patterns
const validationPatterns = {
    Fname: {
        pattern: /^[A-Za-z\s]{2,}$/,
        message: 'Please enter a valid first name (letters only, minimum 2 characters)'
    },
    Lname: {
        pattern: /^[A-Za-z\s]{2,}$/,
        message: 'Please enter a valid last name (letters only, minimum 2 characters)'
    },
    mobile: {
        pattern: /^[0-9]{10}$/,
        message: 'Please enter a valid 10-digit phone number'
    },
    emergency_phone: {
        pattern: /^[0-9]{10}$/,
        message: 'Please enter a valid 10-digit phone number'
    },
    email: {
        pattern: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/,
        message: 'Please enter a valid email address'
    },
    nrc_pass: {
        pattern: /^[A-Za-z0-9/-]{6,}$/,
        message: 'Please enter a valid ID/passport number (minimum 6 characters)'
    }
};

// Add validation listeners to all form fields
document.querySelectorAll('input, select, textarea').forEach(field => {
    // Validate on blur
    field.addEventListener('blur', () => {
        validateField(field);
    });

    // Clear error on input
    field.addEventListener('input', () => {
        clearError(field);
    });

    // Prevent form submission on enter key
    field.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    });
});

// File input validation and preview
document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', (e) => {
        const file = e.target.files[0];
        const config = fileConfig[input.id];
        
        if (!file) return;
        
        clearError(input);
        
        if (config) {
            if (file.size > config.maxSize) {
                showError(input, `File size must be less than ${config.maxSize / (1024 * 1024)}MB`);
                input.value = '';
                return;
            }
            
            if (!config.allowedTypes.includes(file.type)) {
                showError(input, config.errorMessage);
                input.value = '';
                return;
            }
            
            // Show preview for profile image
            if (input.id === 'profile_image') {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('preview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        }
    });
});

// Field validation function
function validateField(field) {
    clearError(field);
    
    // Check if field is required and empty
    if (field.required && !field.value.trim()) {
        showError(field, 'This field is required');
        return false;
    }

    // Pattern validation
    const pattern = validationPatterns[field.id];
    if (pattern && !pattern.pattern.test(field.value.trim())) {
        showError(field, pattern.message);
        return false;
    }

    // Date validations
    if (field.type === 'date') {
        const date = new Date(field.value);
        const today = new Date();
        const minAge = new Date();
        const maxAge = new Date();
        minAge.setFullYear(today.getFullYear() - 16);
        maxAge.setFullYear(today.getFullYear() - 60);

        if (date > minAge || date < maxAge) {
            showError(field, 'You must be between 16 and 60 years old');
            return false;
        }
    }

    // Month/Year validations
    if (field.type === 'month') {
        const selectedDate = new Date(field.value);
        const today = new Date();
        
        if (selectedDate > today) {
            showError(field, 'Date cannot be in the future');
            return false;
        }
    }

    return true;
}

// Error handling functions
function showError(input, message) {
    clearError(input);
    input.classList.add('error');
    const errorDiv = input.nextElementSibling;
    if (errorDiv && errorDiv.classList.contains('invalid-feedback')) {
        errorDiv.textContent = message;
    }
}

function clearError(input) {
    input.classList.remove('error');
    const errorDiv = input.nextElementSibling;
    if (errorDiv && errorDiv.classList.contains('invalid-feedback')) {
        errorDiv.textContent = input.validationMessage;
    }
}

// Form submission handling
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    // Validate all fields
    let isValid = true;
    form.querySelectorAll('input, select, textarea').forEach(field => {
        if (!validateField(field)) {
            isValid = false;
            // Scroll to first error
            if (isValid === false) {
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                field.focus();
                isValid = null; // Prevent multiple scrolls
            }
        }
    });
    
    if (!isValid) return;
    
    // Show loading overlay if present
    const loadingEl = document.getElementById('loading');
    if (loadingEl) {
        loadingEl.style.display = 'flex';
    }
    
    // Submit the form
    form.submit();
});

// Prevent form resubmission on page refresh
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}

const prevBtns = document.querySelectorAll(".btn-prev");
const nextBtns = document.querySelectorAll(".btn-next");
const progress = document.getElementById("progress");
const formSteps = document.querySelectorAll(".form-step");
const progressSteps = document.querySelectorAll(".progress-step");

let formStepsNum = 0;

nextBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
        // Validate current step before proceeding
        const currentStep = formSteps[formStepsNum];
        const inputs = currentStep.querySelectorAll("input[required], select[required]");
        let isValid = true;

        inputs.forEach(input => {
            if (!input.value) {
                isValid = false;
                input.classList.add("is-invalid");
            } else {
                input.classList.remove("is-invalid");
            }
        });

        if (isValid) {
            formStepsNum++;
            updateFormSteps();
            updateProgressbar();
        }
    });
});

prevBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
        formStepsNum--;
        updateFormSteps();
        updateProgressbar();
    });
});

function updateFormSteps() {
    formSteps.forEach((formStep) => {
        formStep.classList.contains("form-step-active") &&
            formStep.classList.remove("form-step-active");
    });

    formSteps[formStepsNum].classList.add("form-step-active");
}

function updateProgressbar() {
    progressSteps.forEach((progressStep, idx) => {
        if (idx < formStepsNum + 1) {
            progressStep.classList.add("progress-step-active");
        } else {
            progressStep.classList.remove("progress-step-active");
        }
    });

    const progressActive = document.querySelectorAll(".progress-step-active");

    progress.style.width =
        ((progressActive.length - 1) / (progressSteps.length - 1)) * 100 + "%";
}

// Format student ID to uppercase
document.getElementById('SID').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

// Format phone numbers
document.getElementById('mobile').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9+\-\s]/g, '');
});

document.getElementById('next_kin_mobile').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9+\-\s]/g, '');
});