/**
 * Authentication JavaScript Functions
 * Contains common functionality for all authentication pages
 */

// Password toggle functionality
function togglePassword(passwordId, iconId) {
    const passwordInput = document.getElementById(passwordId);
    const toggleIcon = document.getElementById(iconId);

    if (passwordInput) {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            if (toggleIcon) {
                toggleIcon.className = 'bi bi-eye-slash';
            }
        } else {
            passwordInput.type = 'password';
            if (toggleIcon) {
                toggleIcon.className = 'bi bi-eye';
            }
        }
    }
}

// Specific toggle functions for different password fields
function toggleMainPassword() {
    togglePassword('password', 'passwordIcon');
}

function toggleConfirmPassword() {
    togglePassword('confirm_password', 'confirmPasswordIcon');
}

// Password generator
function generatePassword() {
    const lowercase = 'abcdefghijklmnopqrstuvwxyz';
    const uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const numbers = '0123456789';
    const symbols = '!@#$%^&*()_+-=[]{}|;:,.<>?';

    let password = '';
    const length = 12;

    // Ensure at least one character from each category
    password += lowercase.charAt(Math.floor(Math.random() * lowercase.length));
    password += uppercase.charAt(Math.floor(Math.random() * uppercase.length));
    password += numbers.charAt(Math.floor(Math.random() * numbers.length));
    password += symbols.charAt(Math.floor(Math.random() * symbols.length));

    // Fill the rest randomly
    const allChars = lowercase + uppercase + numbers + symbols;
    for (let i = password.length; i < length; i++) {
        password += allChars.charAt(Math.floor(Math.random() * allChars.length));
    }

    // Shuffle the password
    password = password.split('').sort(() => Math.random() - 0.5).join('');

    // Set the password in the input field
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');

    if (passwordInput) {
        passwordInput.value = password;
        passwordInput.type = 'text'; // Show password temporarily

        // Update toggle icon to show password is visible
        const toggleIcon = document.getElementById('passwordIcon');
        if (toggleIcon) {
            toggleIcon.className = 'bi bi-eye-slash';
        }

        // Trigger password strength check
        checkPasswordStrength();

        // Copy to confirm password field if it's empty
        if (confirmPasswordInput && !confirmPasswordInput.value) {
            confirmPasswordInput.value = password;
        }
    }

    // Show success message
    showAlert('Strong password generated! You can toggle visibility to hide it.', 'success');
}

// Password strength checker
function checkPasswordStrength() {
    const password = document.getElementById('password');
    const strengthDiv = document.getElementById('passwordStrength');
    const strengthBar = document.getElementById('passwordStrengthBar');

    if (!password || !strengthDiv) return;

    const passwordValue = password.value;

    if (passwordValue.length === 0) {
        strengthDiv.innerHTML = '';
        if (strengthBar) {
            strengthBar.style.width = '0%';
            strengthBar.className = 'progress-bar';
        }
        return;
    }

    let strength = 0;
    let feedback = [];
    let checks = [];

    // Length check
    if (passwordValue.length >= 8) {
        strength += 20;
        checks.push('<i class="bi bi-check-circle-fill text-success me-1"></i>');
    } else {
        feedback.push('At least 8 characters');
        checks.push('<i class="bi bi-x-circle-fill text-danger me-1"></i>');
    }

    // Lowercase check
    if (/[a-z]/.test(passwordValue)) {
        strength += 20;
        checks.push('<i class="bi bi-check-circle-fill text-success me-1"></i>');
    } else {
        feedback.push('Lowercase letter');
        checks.push('<i class="bi bi-x-circle-fill text-danger me-1"></i>');
    }

    // Uppercase check
    if (/[A-Z]/.test(passwordValue)) {
        strength += 20;
        checks.push('<i class="bi bi-check-circle-fill text-success me-1"></i>');
    } else {
        feedback.push('Uppercase letter');
        checks.push('<i class="bi bi-x-circle-fill text-danger me-1"></i>');
    }

    // Number check
    if (/[0-9]/.test(passwordValue)) {
        strength += 20;
        checks.push('<i class="bi bi-check-circle-fill text-success me-1"></i>');
    } else {
        feedback.push('Number');
        checks.push('<i class="bi bi-x-circle-fill text-danger me-1"></i>');
    }

    // Special character check
    if (/[^A-Za-z0-9]/.test(passwordValue)) {
        strength += 20;
        checks.push('<i class="bi bi-check-circle-fill text-success me-1"></i>');
    } else {
        feedback.push('Special character');
        checks.push('<i class="bi bi-x-circle-fill text-danger me-1"></i>');
    }

    let strengthText = '';
    let strengthClass = '';
    let barClass = '';

    if (strength < 60) {
        strengthText = 'Weak';
        strengthClass = 'strength-weak';
        barClass = 'bg-danger';
    } else if (strength < 80) {
        strengthText = 'Medium';
        strengthClass = 'strength-medium';
        barClass = 'bg-warning';
    } else {
        strengthText = 'Strong';
        strengthClass = 'strength-strong';
        barClass = 'bg-success';
    }

    // Create feedback HTML
    let feedbackHtml = `
        <div class="password-strength-header">
            <span class="strength-text ${strengthClass}">${strengthText}</span>
        </div>
        <div class="password-requirements">
            <small class="text-muted">
                <div class="requirement-item">${checks[0]} At least 8 characters</div>
                <div class="requirement-item">${checks[1]} One lowercase letter</div>
                <div class="requirement-item">${checks[2]} One uppercase letter</div>
                <div class="requirement-item">${checks[3]} One number</div>
                <div class="requirement-item">${checks[4]} One special character</div>
            </small>
        </div>
    `;

    strengthDiv.innerHTML = feedbackHtml;

    // Update progress bar
    if (strengthBar) {
        strengthBar.style.width = strength + '%';
        strengthBar.className = `progress-bar ${barClass}`;
    }
}

// Confirm password validation
function validateConfirmPassword() {
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');

    if (!confirmPassword || !password) return;

    if (confirmPassword.value && password.value !== confirmPassword.value) {
        confirmPassword.setCustomValidity('Passwords do not match');
        showFieldValidation(confirmPassword, 'Passwords do not match', false);
    } else {
        confirmPassword.setCustomValidity('');
        if (confirmPassword.value) {
            showFieldValidation(confirmPassword, 'Passwords match', true);
        } else {
            clearFieldValidation(confirmPassword);
        }
    }
}

// Real-time field validation
function validateUsername() {
    const username = document.getElementById('username');
    if (!username) return;

    const value = username.value.trim();
    let isValid = true;
    let message = '';

    if (value.length === 0) {
        clearFieldValidation(username);
        return;
    }

    if (value.length < 3) {
        isValid = false;
        message = 'Username must be at least 3 characters';
    } else if (value.length > 50) {
        isValid = false;
        message = 'Username must be less than 50 characters';
    } else if (!/^[a-zA-Z0-9_]+$/.test(value)) {
        isValid = false;
        message = 'Username can only contain letters, numbers, and underscores';
    }

    if (isValid && value.length >= 3) {
        message = 'Username looks good';
    }

    showFieldValidation(username, message, isValid);
}

function validateEmail() {
    const email = document.getElementById('email');
    if (!email) return;

    const value = email.value.trim();
    let isValid = true;
    let message = '';

    if (value.length === 0) {
        clearFieldValidation(email);
        return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(value)) {
        isValid = false;
        message = 'Please enter a valid email address';
    } else if (value.length > 100) {
        isValid = false;
        message = 'Email address is too long';
    } else {
        message = 'Email format is valid';
    }

    showFieldValidation(email, message, isValid);
}

function validatePassword() {
    // Only validate on signup page; login/reset should not show composition hints
    if (!document.querySelector('.signup-card')) return;

    const password = document.getElementById('password');
    if (!password) return;

    const value = password.value;
    let isValid = true;
    let message = '';

    if (value.length === 0) {
        clearFieldValidation(password);
        return;
    }

    if (value.length < 8) {
        isValid = false;
        message = 'Password must be at least 8 characters';
    } else if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/.test(value)) {
        isValid = false;
        message = 'Password must contain uppercase, lowercase, number, and special character';
    } else {
        message = 'Password meets requirements';
    }

    showFieldValidation(password, message, isValid);
}

// Field validation helpers
function showFieldValidation(field, message, isValid) {
    clearFieldValidation(field);

    const feedbackDiv = document.createElement('div');
    feedbackDiv.className = `field-feedback ${isValid ? 'valid-feedback' : 'invalid-feedback'}`;
    feedbackDiv.innerHTML = `<small><i class="bi ${isValid ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger'} me-1"></i>${message}</small>`;

    field.parentNode.appendChild(feedbackDiv);

    // Update field appearance
    field.classList.remove('is-valid', 'is-invalid');
    field.classList.add(isValid ? 'is-valid' : 'is-invalid');
}

function clearFieldValidation(field) {
    const existingFeedback = field.parentNode.querySelector('.field-feedback');
    if (existingFeedback) {
        existingFeedback.remove();
    }
    field.classList.remove('is-valid', 'is-invalid');
}

// OTP input formatting and auto-submit
function setupOTPInput() {
    const otpInput = document.getElementById('otp') || document.getElementById('final_otp');

    if (!otpInput) return;

    // Auto-focus on OTP input
    otpInput.focus();

    // Format OTP input
    otpInput.addEventListener('input', function(e) {
        // Remove any non-numeric characters
        this.value = this.value.replace(/[^0-9]/g, '');

        // Limit to 6 digits
        if (this.value.length > 6) {
            this.value = this.value.slice(0, 6);
        }

        // Auto-submit when 6 digits are entered
        if (this.value.length === 6) {
            setTimeout(() => {
                if (this.value.length === 6) {
                    const form = this.closest('form');
                    if (form) {
                        form.submit();
                    }
                }
            }, 1000);
        }
    });
}

// Form submission animation
function setupFormAnimations() {
    const forms = document.querySelectorAll('form');

    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;

                // Add loading state
                if (originalText.includes('Sign In')) {
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Signing In...';
                } else if (originalText.includes('Create Account')) {
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Creating Account...';
                } else if (originalText.includes('Verify')) {
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Verifying...';
                } else if (originalText.includes('Reset')) {
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Resetting...';
                } else if (originalText.includes('Send')) {
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';
                } else if (originalText.includes('Complete')) {
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Completing...';
                } else {
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                }
            }
        });
    });
}

// OTP resend functionality
let countdown = 60;
let countdownInterval;

function startCountdown() {
    const countdownDiv = document.querySelector('.countdown');
    const resendLink = document.querySelector('.resend-link a');

    if (!countdownDiv || !resendLink) return;

    resendLink.style.pointerEvents = 'none';
    resendLink.style.opacity = '0.5';

    countdownInterval = setInterval(() => {
        countdownDiv.textContent = `Resend available in ${countdown} seconds`;
        countdown--;

        if (countdown < 0) {
            clearInterval(countdownInterval);
            countdownDiv.textContent = '';
            resendLink.style.pointerEvents = 'auto';
            resendLink.style.opacity = '1';
            countdown = 60;
        }
    }, 1000);
}

function resendOTP() {
    const resendLink = document.querySelector('.resend-link a');

    if (!resendLink) return;

    // Show loading state
    const originalText = resendLink.innerHTML;
    resendLink.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Sending...';
    resendLink.style.pointerEvents = 'none';

    // Get user ID from session (you might need to adjust this based on your implementation)
    const userId = document.querySelector('input[name="user_id"]')?.value ||
                   new URLSearchParams(window.location.search).get('user_id');

    // Send AJAX request to resend OTP
    fetch('resend_otp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'user_id=' + (userId || '')
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reset countdown
            clearInterval(countdownInterval);
            countdown = 60;
            startCountdown();

            // Show success message
            showAlert('OTP sent successfully! Check your email.', 'success');
        } else {
            showAlert(data.message || 'Failed to resend OTP', 'danger');
        }

        // Reset button state
        resendLink.innerHTML = originalText;
        resendLink.style.pointerEvents = 'auto';
    })
    .catch(error => {
        showAlert('Network error. Please try again.', 'danger');
        resendLink.innerHTML = originalText;
        resendLink.style.pointerEvents = 'auto';
    });
}

// Show alert messages
function showAlert(message, type) {
    const existingAlert = document.querySelector('.auth-body .alert');
    if (existingAlert) {
        existingAlert.remove();
    }

    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        <i class="bi bi-info-circle me-2"></i>${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;

    const authBody = document.querySelector('.auth-body');
    if (authBody) {
        authBody.insertBefore(alertDiv, authBody.firstChild);
    }

    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

// Auto-redirect functionality
function setupAutoRedirect(delay = 5000) {
    const redirectElement = document.getElementById('redirect-countdown');
    if (!redirectElement) return;

    let countdown = delay / 1000;

    const countdownInterval = setInterval(() => {
        redirectElement.textContent = countdown;
        countdown--;

        if (countdown < 0) {
            clearInterval(countdownInterval);
        }
    }, 1000);

    setTimeout(() => {
        window.location.href = 'login.php';
    }, delay);
}

// Initialize all functionality when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    const isSignupPage = !!document.querySelector('.signup-card');

    // Setup password toggles (available on both login and signup)
    const passwordToggle = document.getElementById('passwordToggle');
    if (passwordToggle) {
        passwordToggle.addEventListener('click', toggleMainPassword);
    }

    const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
    if (confirmPasswordToggle) {
        confirmPasswordToggle.addEventListener('click', toggleConfirmPassword);
    }

    if (isSignupPage) {
        // Signup-only: password generator
        const generatePasswordBtn = document.getElementById('generatePassword');
        if (generatePasswordBtn) {
            generatePasswordBtn.addEventListener('click', generatePassword);
        }

        // Signup-only: password strength checker and focus behavior
        const passwordInputStrength = document.getElementById('password');
        if (passwordInputStrength) {
            passwordInputStrength.addEventListener('input', checkPasswordStrength);

            // Auto-focus on password if email is already filled (signup)
            const emailInput = document.getElementById('email');
            if (emailInput && emailInput.value) {
                passwordInputStrength.focus();
            } else if (emailInput) {
                emailInput.focus();
            }
        }

        // Signup-only: confirm password validation
        const confirmPassword = document.getElementById('confirm_password');
        if (confirmPassword) {
            confirmPassword.addEventListener('input', validateConfirmPassword);
        }

        // Signup-only: real-time validation for username, email, and password composition
        const usernameInput = document.getElementById('username');
        if (usernameInput) {
            usernameInput.addEventListener('input', validateUsername);
            usernameInput.addEventListener('blur', validateUsername);
        }

        const emailInput = document.getElementById('email');
        if (emailInput) {
            emailInput.addEventListener('input', validateEmail);
            emailInput.addEventListener('blur', validateEmail);
        }

        const passwordInputValidation = document.getElementById('password');
        if (passwordInputValidation) {
            passwordInputValidation.addEventListener('input', function() {
                validatePassword();
                validateConfirmPassword(); // Re-validate confirm password when main password changes
            });
            passwordInputValidation.addEventListener('blur', validatePassword);
        }
    } else {
        // Non-signup pages (e.g., login): cleanup and focus
        const identifier = document.getElementById('identifier');
        if (identifier && !identifier.value) {
            identifier.focus();
        }
        // Ensure any residual validation UI is cleared on login/reset
        const pw = document.getElementById('password');
        if (pw) {
            clearFieldValidation(pw);
        }
    }

    // Setup OTP input (if present)
    setupOTPInput();

    // Setup form animations
    setupFormAnimations();

    // Setup countdown for OTP resend
    if (document.querySelector('.resend-link')) {
        startCountdown();
    }

    // Setup auto-redirect
    if (document.querySelector('.auto-redirect')) {
        setupAutoRedirect();
    }
});
