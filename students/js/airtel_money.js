/**
 * Airtel Money Payment Integration
 * 
 * Handles Airtel Money payment UI and interactions
 */

class AirtelMoneyPayment {
    constructor(config = {}) {
        this.config = {
            processorUrl: config.processorUrl || 'process_airtel_payment.php',
            pollInterval: config.pollInterval || 5000, // Poll every 5 seconds
            maxPolls: config.maxPolls || 60, // Max 5 minutes
            ...config
        };
        
        this.pollCount = 0;
        this.reference = null;
        this.transactionId = null;
    }
    
    /**
     * Initialize Airtel Money payment option UI
     */
    initPaymentOption() {
        const container = document.getElementById('airtel-money-container');
        if (!container) return;
        
        container.innerHTML = `
            <div class="airtel-payment-option">
                <div class="payment-method-card">
                    <div class="payment-method-header">
                        <img src="images/airtel-logo.png" alt="Airtel Money" class="payment-logo">
                        <h5>Airtel Money</h5>
                    </div>
                    <p class="payment-description">
                        Pay directly from your Airtel Money account. Fast and secure mobile money payment.
                    </p>
                    
                    <form id="airtel-payment-form" class="payment-form">
                        <div class="form-group mb-3">
                            <label for="airtel-phone" class="form-label">Airtel Money Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-phone"></i>
                                </span>
                                <input 
                                    type="tel" 
                                    class="form-control" 
                                    id="airtel-phone" 
                                    name="phone_number"
                                    placeholder="e.g., 0976543210 or 260976543210"
                                    required
                                >
                            </div>
                            <small class="form-text text-muted">
                                Enter your Airtel Money registered phone number
                            </small>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Amount (ZMW)</label>
                            <div class="input-group">
                                <span class="input-group-text">ZMW</span>
                                <input 
                                    type="number" 
                                    class="form-control" 
                                    id="airtel-amount" 
                                    name="amount"
                                    step="1"
                                    min="10"
                                    max="100000"
                                    required
                                    readonly
                                >
                            </div>
                            <small class="form-text text-muted" id="airtel-amount-info"></small>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>How it works:</strong>
                            <ol class="mb-0">
                                <li>Enter your Airtel Money phone number</li>
                                <li>Click "Pay Now" to initiate payment</li>
                                <li>You'll receive a prompt on your phone</li>
                                <li>Enter your Airtel Money PIN to confirm</li>
                                <li>Payment will be processed automatically</li>
                            </ol>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-lg w-100" id="airtel-pay-btn">
                            <i class="fas fa-wallet"></i> Pay Now with Airtel Money
                        </button>
                    </form>
                    
                    <div id="airtel-status-container" style="display: none;" class="mt-3">
                        <div class="alert alert-info" id="airtel-status-message">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span id="airtel-status-text">Processing payment...</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Attach event listeners
        this.attachEventListeners();
    }
    
    /**
     * Attach event listeners to form
     */
    attachEventListeners() {
        const form = document.getElementById('airtel-payment-form');
        const payBtn = document.getElementById('airtel-pay-btn');
        
        if (form) {
            form.addEventListener('submit', (e) => this.handlePaymentSubmit(e));
        }
        
        if (payBtn) {
            payBtn.addEventListener('click', (e) => {
                if (!this.validateForm()) {
                    e.preventDefault();
                }
            });
        }
    }
    
    /**
     * Validate payment form
     */
    validateForm() {
        const phone = document.getElementById('airtel-phone')?.value?.trim();
        const amount = parseFloat(document.getElementById('airtel-amount')?.value || 0);
        
        if (!phone) {
            this.showError('Please enter a phone number');
            return false;
        }
        
        if (amount <= 0) {
            this.showError('Please enter a valid amount');
            return false;
        }
        
        if (amount < 10) {
            this.showError('Minimum amount is ZMW 10');
            return false;
        }
        
        if (amount > 100000) {
            this.showError('Maximum amount is ZMW 100,000');
            return false;
        }
        
        return true;
    }
    
    /**
     * Handle payment form submission
     */
    async handlePaymentSubmit(event) {
        event.preventDefault();
        
        if (!this.validateForm()) {
            return;
        }
        
        const phone = document.getElementById('airtel-phone').value.trim();
        const amount = parseFloat(document.getElementById('airtel-amount').value);
        const studentId = document.getElementById('student-id')?.value || '';
        const narration = 'ITC Portal Student Registration Fee';
        
        // Show processing status
        this.showProcessing();
        
        try {
            const response = await fetch(this.config.processorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'initiate',
                    phone_number: phone,
                    amount: amount,
                    student_id: studentId,
                    narration: narration
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.reference = result.reference;
                this.transactionId = result.transaction_id;
                this.pollCount = 0;
                
                this.showSuccess(
                    'Payment initiated! Reference: ' + result.reference + 
                    '. Please check your phone for Airtel Money prompt.'
                );
                
                // Disable form during polling
                document.getElementById('airtel-payment-form').style.opacity = '0.5';
                document.getElementById('airtel-payment-form').style.pointerEvents = 'none';
                
                // Start polling for transaction status
                this.pollTransactionStatus();
            } else {
                this.showError(result.error || 'Payment initiation failed');
                this.hideProcessing();
            }
            
        } catch (error) {
            console.error('Payment error:', error);
            this.showError('Network error: ' + error.message);
            this.hideProcessing();
        }
    }
    
    /**
     * Poll transaction status
     */
    async pollTransactionStatus() {
        if (this.pollCount >= this.config.maxPolls) {
            this.showError('Payment verification timeout. Please check your Airtel Money account.');
            this.hideProcessing();
            this.resetForm();
            return;
        }
        
        try {
            const response = await fetch(this.config.processorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'query',
                    reference: this.reference
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                const status = result.status.toLowerCase();
                
                if (status === 'success' || status === 'completed') {
                    this.showSuccess(
                        'Payment successful! Reference: ' + this.reference + 
                        '. Amount: ZMW ' + result.amount
                    );
                    
                    // Redirect after 3 seconds
                    setTimeout(() => {
                        window.location.href = 'fees.php?payment_success=1&ref=' + this.reference;
                    }, 3000);
                    
                    return;
                } else if (status === 'failed' || status === 'cancelled' || status === 'declined') {
                    this.showError('Payment ' + status + '. Please try again.');
                    this.hideProcessing();
                    this.resetForm();
                    return;
                }
                
                // Still pending, poll again
                this.pollCount++;
                this.updatePollingStatus();
                
                setTimeout(() => this.pollTransactionStatus(), this.config.pollInterval);
            } else {
                // Continue polling on query errors
                this.pollCount++;
                setTimeout(() => this.pollTransactionStatus(), this.config.pollInterval);
            }
            
        } catch (error) {
            console.error('Query error:', error);
            this.pollCount++;
            setTimeout(() => this.pollTransactionStatus(), this.config.pollInterval);
        }
    }
    
    /**
     * Update polling status message
     */
    updatePollingStatus() {
        const elapsed = (this.pollCount * this.config.pollInterval / 1000).toFixed(0);
        const statusText = document.getElementById('airtel-status-text');
        if (statusText) {
            statusText.textContent = 'Waiting for payment confirmation... (' + elapsed + 's)';
        }
    }
    
    /**
     * Show processing status
     */
    showProcessing() {
        const container = document.getElementById('airtel-status-container');
        const form = document.getElementById('airtel-payment-form');
        
        if (container) {
            container.style.display = 'block';
            container.className = 'mt-3';
            container.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span id="airtel-status-text">Processing payment...</span>
                </div>
            `;
        }
        
        if (form) {
            document.getElementById('airtel-pay-btn').disabled = true;
        }
    }
    
    /**
     * Show success message
     */
    showSuccess(message) {
        const container = document.getElementById('airtel-status-container');
        
        if (container) {
            container.style.display = 'block';
            container.className = 'mt-3';
            container.innerHTML = `
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }
    }
    
    /**
     * Show error message
     */
    showError(message) {
        const container = document.getElementById('airtel-status-container');
        
        if (container) {
            container.style.display = 'block';
            container.className = 'mt-3';
            container.innerHTML = `
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }
    }
    
    /**
     * Hide processing status
     */
    hideProcessing() {
        const container = document.getElementById('airtel-status-container');
        const payBtn = document.getElementById('airtel-pay-btn');
        
        if (container) {
            container.style.display = 'none';
        }
        
        if (payBtn) {
            payBtn.disabled = false;
        }
    }
    
    /**
     * Reset form to initial state
     */
    resetForm() {
        const form = document.getElementById('airtel-payment-form');
        const formDiv = document.getElementById('airtel-payment-form').parentElement;
        
        if (form) {
            form.reset();
            form.style.opacity = '1';
            form.style.pointerEvents = 'auto';
        }
        
        this.reference = null;
        this.transactionId = null;
        this.pollCount = 0;
    }
    
    /**
     * Set payment amount (called from fees page)
     */
    setAmount(amount) {
        const amountField = document.getElementById('airtel-amount');
        const amountInfo = document.getElementById('airtel-amount-info');
        
        if (amountField) {
            amountField.value = amount;
        }
        
        if (amountInfo) {
            amountInfo.textContent = 'Total amount due: ZMW ' + parseFloat(amount).toLocaleString();
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    const airtelPayment = new AirtelMoneyPayment();
    airtelPayment.initPaymentOption();
    
    // Make available globally
    window.airtelPayment = airtelPayment;
});
