/* ============================================================================
   LOGIN PAGE JAVASCRIPT
   Description: Handle login form submission, validation, dan authentication
   Dependencies: None (Pure vanilla JavaScript)
   API Endpoint: server/auth_login.php, server/auth_verify.php
   ============================================================================ */

document.addEventListener('DOMContentLoaded', function () {
  // ========================================================================
  // DOM ELEMENTS REFERENCE
  // Cache selectors untuk performance
  // ========================================================================
  const loginForm = document.getElementById('loginForm');
  const loginBtn = document.getElementById('loginBtn');
  const togglePassword = document.getElementById('togglePassword');
  const passwordInput = document.getElementById('password');
  const emailInput = document.getElementById('email');

  // Validate bahwa semua required elements ada
  if (!loginForm || !loginBtn || !passwordInput || !emailInput) {
    console.error('[Login] Required elements not found!', {
      loginForm: !!loginForm,
      loginBtn: !!loginBtn,
      passwordInput: !!passwordInput,
      emailInput: !!emailInput,
    });
    return;
  }

  // ========================================================================
  // TOGGLE PASSWORD VISIBILITY
  // Feature: Show/hide password dengan click icon
  // ========================================================================
  if (togglePassword) {
    console.log('[Login] ✅ Toggle password button initialized');

    togglePassword.addEventListener('click', function (e) {
      // CRITICAL: Prevent form submission
      e.preventDefault();
      e.stopPropagation();

      const currentType = passwordInput.type;
      console.log('[Login] 👆 Eye button clicked! Current type:', currentType);

      // Toggle type antara password dan text
      if (currentType === 'password') {
        passwordInput.type = 'text';
        this.textContent = '🙈'; // Icon untuk hide
        console.log('[Login] ✅ Password NOW VISIBLE as text');
      } else {
        passwordInput.type = 'password';
        this.textContent = '👁️'; // Icon untuk show
        console.log('[Login] 🔒 Password NOW HIDDEN as dots');
      }

      console.log('[Login] After toggle, type is:', passwordInput.type);
    });

    // Backup: Prevent mousedown from causing issues
    togglePassword.addEventListener('mousedown', function (e) {
      e.preventDefault();
    });
  } else {
    console.error('[Login] ❌ Toggle password button NOT found!');
  }

  // ========================================================================
  // SHOW ALERT FUNCTION
  // Purpose: Display error/success messages ke user
  // Parameters:
  //   - message: Text yang akan ditampilkan
  //   - type: 'error' atau 'success'
  // Auto-hide: After 5 seconds
  // ========================================================================
  function showAlert(message, type = 'error') {
    // Remove existing alert jika ada
    const existingAlert = document.querySelector('.alert');
    if (existingAlert) {
      existingAlert.remove();
    }

    // Create alert element
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} show`;
    alert.textContent = message;

    // Insert alert di awal form (sebelum semua elements)
    loginForm.insertBefore(alert, loginForm.firstChild);

    // Auto-hide setelah 5 detik
    setTimeout(() => {
      alert.classList.remove('show');
      // Remove dari DOM setelah animation selesai
      setTimeout(() => alert.remove(), 300);
    }, 5000);
  }

  // ========================================================================
  // EMAIL VALIDATION FUNCTION
  // Purpose: Validate email format menggunakan regex
  // Returns: true jika valid, false jika tidak
  // ========================================================================
  function isValidEmail(email) {
    // Regex untuk validasi email format standar
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  // ========================================================================
  // FORM SUBMISSION HANDLER
  // Process:
  //   1. Prevent default form submission
  //   2. Validate inputs
  //   3. Send login request ke server
  //   4. Handle response (success/error)
  //   5. Redirect ke index.html jika berhasil
  // ========================================================================
  loginForm.addEventListener('submit', async function (e) {
    e.preventDefault(); // Prevent default form submission

    // Get form values
    const email = emailInput.value.trim();
    const password = passwordInput.value;
    const rememberMe = document.getElementById('rememberMe').checked;

    // ====================================================================
    // CLIENT-SIDE VALIDATION
    // Validate sebelum kirim ke server untuk UX yang lebih baik
    // ====================================================================

    // Check empty fields
    if (!email || !password) {
      showAlert('Please enter both email and password');
      return;
    }

    // Validate email format
    if (!isValidEmail(email)) {
      showAlert('Please enter a valid email address');
      return;
    }

    // ====================================================================
    // BUTTON LOADING STATE
    // Disable button dan show loading text saat proses
    // ====================================================================
    loginBtn.disabled = true;
    loginBtn.querySelector('.btn-text').style.display = 'none';
    loginBtn.querySelector('.btn-loading').style.display = 'inline';

    try {
      // ================================================================
      // SEND LOGIN REQUEST KE SERVER
      // Method: POST
      // Endpoint: server/auth_login.php
      // Body: JSON dengan email, password, rememberMe
      // ================================================================
      const response = await fetch('server/auth_login.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          email: email,
          password: password,
          rememberMe: rememberMe,
        }),
      });

      // Check jika response tidak OK (status code bukan 200-299)
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      // Parse JSON response
      const data = await response.json();

      // 🆕 DEBUG: Log response untuk troubleshooting
      console.log('Login response:', data);

      // ================================================================
      // HANDLE LOGIN SUCCESS
      // Actions:
      //   1. Show success message
      //   2. Store token di sessionStorage (always)
      //   3. Store token di localStorage (jika remember me)
      //   4. Store user data
      //   5. Redirect ke index.html
      // ================================================================
      if (data.status === 'success') {
        showAlert('Login successful! Redirecting...', 'success');

        // Store session token untuk authentication
        sessionStorage.setItem('auth_token', data.token);
        sessionStorage.setItem('user_data', JSON.stringify(data.user));

        // Jika remember me checked, simpan juga di localStorage
        // localStorage persistent bahkan setelah browser ditutup
        if (rememberMe) {
          localStorage.setItem('auth_token', data.token);
        }

        // Redirect ke halaman utama setelah 1 detik
        setTimeout(() => {
          window.location.href = 'index.html';
        }, 1000);
      }
      // ================================================================
      // HANDLE LOGIN FAILURE
      // Show error message dari server
      // ================================================================
      else {
        const errorMsg = data.message || 'Login failed. Please try again.';
        const debugInfo = data.debug ? ` (Debug: ${data.debug})` : '';

        // Show error ke user
        showAlert(errorMsg);

        // Log debug info ke console untuk troubleshooting
        console.error('Login failed:', {
          message: data.message,
          debug: data.debug,
          email: email,
          passwordLength: password.length,
        });
      }
    } catch (error) {
      // ====================================================================
      // HANDLE NETWORK ERROR
      // Catch error jika server tidak bisa diakses atau network issue
      // ====================================================================
      console.error('Login error:', error);
      showAlert('Network error. Please check your connection and try again.');
    } finally {
      // ====================================================================
      // FINALLY BLOCK
      // Re-enable button setelah proses selesai (success atau error)
      // ====================================================================
      loginBtn.disabled = false;
      loginBtn.querySelector('.btn-text').style.display = 'inline';
      loginBtn.querySelector('.btn-loading').style.display = 'none';
    }
  });

  // ========================================================================
  // DISABLE SIGNUP LINK
  // Registration feature belum diimplementasi
  // Show alert ketika user click "Sign up"
  // ========================================================================
  const signupLink = document.getElementById('signupLink');
  if (signupLink) {
    signupLink.addEventListener('click', function (e) {
      e.preventDefault();
      showAlert('Registration is currently disabled. Please contact administrator.');
    });
  }

  // ========================================================================
  // AUTO-FOCUS EMAIL INPUT
  // UX improvement: Langsung focus ke email input saat page load
  // ========================================================================
  if (emailInput) {
    emailInput.focus();
  }

  // ========================================================================
  // CHECK EXISTING SESSION
  // Purpose: Redirect ke index.html jika user sudah login
  // Process:
  //   1. Check token di sessionStorage atau localStorage
  //   2. Verify token validity dengan server
  //   3. Redirect jika token valid
  // ========================================================================
  const token = sessionStorage.getItem('auth_token') || localStorage.getItem('auth_token');
  if (token) {
    console.log('[Login] Found existing token, verifying...');

    // Verify token dengan server
    fetch('server/auth_verify.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ token }),
    })
      .then((res) => res.json())
      .then((data) => {
        // Jika token masih valid, langsung redirect ke dashboard
        if (data.status === 'success') {
          console.log('[Login] Token valid, redirecting to index.html');
          window.location.href = 'index.html';
        } else {
          // Token invalid, clear storage
          console.log('[Login] Token invalid, clearing storage');
          sessionStorage.clear();
          localStorage.removeItem('auth_token');
        }
      })
      .catch((err) => {
        console.error('[Login] Token verification error:', err);
        // Clear storage on error untuk safety
        sessionStorage.clear();
        localStorage.removeItem('auth_token');
      });
  }
});

/* ============================================================================
   END OF LOGIN.JS
   ============================================================================ */
