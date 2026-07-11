/**
 * Modern Form Steps Script
 * Handles multi-step forms with validation and improved user experience
 */

document.addEventListener('DOMContentLoaded', function() {
    const prevBtns = document.querySelectorAll(".btn-prev");
    const nextBtns = document.querySelectorAll(".btn-next");
    const progress = document.getElementById("progress");
    const formSteps = document.querySelectorAll(".form-step");
    const progressSteps = document.querySelectorAll(".progress-step");
    const form = document.querySelector(".multi-step-form");

    if (!form || formSteps.length === 0) {
        return;
    }
    
    let formStepsNum = 0;
    
    // Add validation event listeners to required inputs
    const requiredInputs = form.querySelectorAll('[required]');
    requiredInputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateInput(this);
        });
    });
    
    // Initialize custom file inputs
    const fileInputs = document.querySelectorAll('.custom-file-input');
    fileInputs.forEach(input => {
        input.addEventListener('change', function() {
            const fileName = this.files[0]?.name || 'Choose file';
            const label = this.nextElementSibling;
            if (label) {
                label.textContent = fileName;
            }
        });
    });
    
    // Handle next button clicks with validation
    nextBtns.forEach(btn => {
        btn.addEventListener("click", (e) => {
            e.preventDefault();
            const currentStep = formSteps[formStepsNum];
            const requiredFields = currentStep.querySelectorAll('[required]');
            
            // Validate all required fields in current step
            let isValid = true;
            requiredFields.forEach(field => {
                if (!validateInput(field)) {
                    isValid = false;
                }
            });
            
            if (isValid) {
                formStepsNum++;
                updateFormSteps();
                updateProgressbar();
                
                // Scroll to top of form for better UX
                form.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
    
    // Handle previous button clicks
    prevBtns.forEach(btn => {
        btn.addEventListener("click", (e) => {
            e.preventDefault();
            formStepsNum--;
            updateFormSteps();
            updateProgressbar();
            
            // Scroll to top of form for better UX
            form.scrollIntoView({ behavior: 'smooth' });
        });
    });
    
    // Update which form step is shown
    function updateFormSteps() {
        formSteps.forEach(formStep => {
            formStep.classList.remove("form-step-active");
        });
        
        formSteps[formStepsNum].classList.add("form-step-active");
        
        // Enable/disable prev/next buttons based on current step
        prevBtns.forEach(btn => {
            btn.style.display = formStepsNum === 0 ? 'none' : 'inline-flex';
        });
        
        nextBtns.forEach((btn, idx) => {
            const isLastStep = formStepsNum === formSteps.length - 1;
            if (idx === formStepsNum) {
                if (isLastStep && btn.type !== 'submit') {
                    btn.classList.add('d-none');
                    const submitBtn = btn.parentElement.querySelector('[type="submit"]');
                    if (submitBtn) submitBtn.classList.remove('d-none');
                } else if (!isLastStep) {
                    btn.classList.remove('d-none');
                    const submitBtn = btn.parentElement.querySelector('[type="submit"]');
                    if (submitBtn) submitBtn.classList.add('d-none');
                }
            }
        });
    }
    
    // Update the progress bar
    function updateProgressbar() {
        progressSteps.forEach((progressStep, idx) => {
            if (idx < formStepsNum + 1) {
                progressStep.classList.add("progress-step-active");
            } else {
                progressStep.classList.remove("progress-step-active");
            }
        });
        
        if (!progress || progressSteps.length < 2) {
            return;
        }

        const progressActive = document.querySelectorAll(".progress-step-active");
        progress.style.width = ((progressActive.length - 1) / (progressSteps.length - 1)) * 100 + "%";
    }
    
    // Validate a single input field
    function validateInput(input) {
        let isValid = true;
        const errorMessage = input.dataset.errorMsg || 'This field is required';
        
        if (input.type === 'email' && input.value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            isValid = emailRegex.test(input.value);
        } else if (input.type === 'tel' && input.value) {
            const phoneRegex = /^\d{10,}$/;
            isValid = phoneRegex.test(input.value.replace(/[\s()-]/g, ''));
        } else {
            isValid = input.value.trim() !== '';
        }
        
        const feedbackElement = input.nextElementSibling?.classList.contains('invalid-feedback') ? 
            input.nextElementSibling : createFeedbackElement(input, errorMessage);
        
        if (!isValid) {
            input.classList.add('is-invalid');
            input.classList.remove('is-valid');
            feedbackElement.style.display = 'block';
        } else {
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
            feedbackElement.style.display = 'none';
        }
        
        return isValid;
    }
    
    // Create error feedback element
    function createFeedbackElement(input, message) {
        const feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        feedback.textContent = message;
        input.insertAdjacentElement('afterend', feedback);
        return feedback;
    }
    
    // Initialize the first step
    updateFormSteps();
});
