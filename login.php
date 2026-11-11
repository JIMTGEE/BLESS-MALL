<?php
/**
 * login.php - Bless Mall Login Page
 * Quality Chinese Products | Ghana
 * 
 * Features:
 *   - Email/Phone login with Argon2id/Bcrypt password verification
 *   - Remember Me functionality
 *   - CSRF protection
 *   - Rate limiting for brute force protection
 *   - Redirect after successful login based on user role (Admin, Seller, Buyer, Deliverer)
 *   - Password reset link
 *   - Delivery partner login support with proper role detection
 */

// Load core configuration and authentication system
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Initialize page variables
$error_message = '';
$success_message = '';
$email_or_phone = '';
$login_type = $_GET['type'] ?? ''; // For specific login types (deliverer, admin, etc.)

// Check for messages from other pages (registration, password reset, etc.)
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

/**
 * Determine redirect URL based on user role
 * Uses session data set during login in auth.php
 */
function getRedirectUrl() {
    // Debug: Log session data for troubleshooting
    error_log("=== REDIRECT CHECK ===");
    error_log("Role ID: " . ($_SESSION['role_id'] ?? 'none'));
    error_log("Role Name: " . ($_SESSION['role_name'] ?? 'none'));
    error_log("Deliverer ID: " . ($_SESSION['deliverer_id'] ?? 'none'));
    error_log("User ID: " . ($_SESSION['user_id'] ?? 'none'));
    
    // FIRST: Check if user has deliverer_id in session (set by loadDelivererData)
    if (isset($_SESSION['deliverer_id']) && $_SESSION['deliverer_id'] > 0) {
        error_log("✅ Redirecting to DELIVERY dashboard - deliverer_id: " . $_SESSION['deliverer_id']);
        return BASE_URL . 'delivery/index.php';
    }
    
    // SECOND: Check role_name from roles table
    if (isset($_SESSION['role_name'])) {
        $role_name = strtolower($_SESSION['role_name']);
        error_log("Checking role_name: " . $role_name);
        
        // Check for deliverer role
        if ($role_name === 'deliverer') {
            error_log("✅ Redirecting to DELIVERY dashboard - role_name: deliverer");
            return BASE_URL . 'delivery/index.php';
        }
        
        // Check for admin role
        if ($role_name === 'admin') {
            error_log("✅ Redirecting to ADMIN dashboard - role_name: admin");
            return BASE_URL . 'admin/index.php';
        }
        
        // Check for seller role
        if ($role_name === 'seller') {
            error_log("✅ Redirecting to SELLER dashboard - role_name: seller");
            return BASE_URL . 'seller/index.php';
        }
        
        // Default: buyer
        if ($role_name === 'buyer') {
            error_log("✅ Redirecting to BUYER account - role_name: buyer");
            return BASE_URL . 'account.php';
        }
    }
    
    // THIRD: Check role_id as fallback (only if role_name is not set)
    if (isset($_SESSION['role_id'])) {
        error_log("Fallback: Checking role_id: " . $_SESSION['role_id']);
        switch ((int)$_SESSION['role_id']) {
            case ROLE_ADMIN:
                error_log("✅ Redirecting to ADMIN dashboard - role_id: " . ROLE_ADMIN);
                return BASE_URL . 'admin/index.php';
            case ROLE_SELLER:
                error_log("✅ Redirecting to SELLER dashboard - role_id: " . ROLE_SELLER);
                return BASE_URL . 'seller/index.php';
            case ROLE_BUYER:
            default:
                error_log("✅ Redirecting to BUYER account - role_id: " . $_SESSION['role_id']);
                return BASE_URL . 'account.php';
        }
    }
    
    // Default: buyer/dashboard
    error_log("⚠️ No role found, redirecting to account.php");
    return BASE_URL . 'account.php';
}

// If user is already logged in, redirect to appropriate page
if (Auth::isLoggedIn()) {
    error_log("=== USER ALREADY LOGGED IN ===");
    error_log("Session data: " . print_r($_SESSION, true));
    
    // Make sure deliverer data is loaded if needed
    if (isset($_SESSION['user_id']) && !isset($_SESSION['deliverer_id'])) {
        // Try to load deliverer data for this user
        try {
            global $conn;
            $stmt = $conn->prepare("
                SELECT d.id, d.availability_status, d.vehicle_type, 
                       r.role_name
                FROM deliverers d 
                JOIN users u ON d.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE d.user_id = ? 
                LIMIT 1
            ");
            $stmt->bind_param('i', $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $_SESSION['deliverer_id'] = (int)$row['id'];
                $_SESSION['deliverer_status'] = $row['availability_status'];
                $_SESSION['deliverer_vehicle_type'] = $row['vehicle_type'] ?? null;
                // Set role_name if not already set
                if (!isset($_SESSION['role_name']) && isset($row['role_name'])) {
                    $_SESSION['role_name'] = $row['role_name'];
                }
                error_log("✅ Loaded deliverer data for logged-in user: deliverer_id=" . $_SESSION['deliverer_id']);
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("⚠️ Deliverer data load failed: " . $e->getMessage());
        }
    }
    
    $redirect_url = getRedirectUrl();
    error_log("🔄 Redirecting to: " . $redirect_url);
    header('Location: ' . $redirect_url);
    exit;
}

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Validate CSRF token
    if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
        $error_message = 'Security validation failed. Please try again.';
    } else {
        $email_or_phone = trim($_POST['email_or_phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember_me']);
        $login_type = $_POST['login_type'] ?? '';

        // Validate input fields
        if (empty($email_or_phone)) {
            $error_message = 'Please enter your email address or phone number.';
        } elseif (empty($password)) {
            $error_message = 'Please enter your password.';
        } else {
            // Attempt login - Auth::login() handles session setup
            $result = Auth::login($email_or_phone, $password, $remember);

            if ($result['success']) {
                // Auth::login() set basic session data including deliverer_id if applicable
                // But let's double-check and ensure role_name is set
                if (isset($_SESSION['user_id'])) {
                    try {
                        global $conn;
                        
                        // Get complete user data with role name
                        $stmt = $conn->prepare("
                            SELECT u.id, u.role_id, u.name, u.email, u.phone, 
                                   r.role_name,
                                   d.id as deliverer_id, d.availability_status, d.vehicle_type
                            FROM users u
                            LEFT JOIN roles r ON u.role_id = r.id
                            LEFT JOIN deliverers d ON u.id = d.user_id
                            WHERE u.id = ?
                            LIMIT 1
                        ");
                        $stmt->bind_param('i', $_SESSION['user_id']);
                        $stmt->execute();
                        $userData = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        
                        if ($userData) {
                            // Update session with complete data
                            $_SESSION['role_name'] = $userData['role_name'] ?? 'buyer';
                            $_SESSION['user_name'] = $userData['name'];
                            $_SESSION['user_email'] = $userData['email'];
                            $_SESSION['user_phone'] = $userData['phone'];
                            $_SESSION['role_id'] = (int)$userData['role_id'];
                            
                            // Set deliverer data if exists
                            if (!empty($userData['deliverer_id'])) {
                                $_SESSION['deliverer_id'] = (int)$userData['deliverer_id'];
                                $_SESSION['deliverer_status'] = $userData['availability_status'] ?? 'offline';
                                $_SESSION['deliverer_vehicle_type'] = $userData['vehicle_type'] ?? null;
                                error_log("✅ Deliverer data loaded: ID=" . $_SESSION['deliverer_id']);
                            } else {
                                // Ensure deliverer data is cleared if not a deliverer
                                unset($_SESSION['deliverer_id']);
                                unset($_SESSION['deliverer_status']);
                                unset($_SESSION['deliverer_vehicle_type']);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("⚠️ Post-login user data fetch failed: " . $e->getMessage());
                    }
                }
                
                // Set success message for the redirected page
                $_SESSION['success_message'] = $result['message'];
                
                // Determine redirect URL based on user type
                $redirect_url = getRedirectUrl();
                error_log("🔄 Post-login redirect URL: " . $redirect_url);
                
                // If login_type was 'deliverer' and user is not a deliverer, show error
                if ($login_type === 'deliverer' && !isset($_SESSION['deliverer_id'])) {
                    $error_message = 'This account is not registered as a delivery partner. Please login as a regular user.';
                    // Log out the user since they tried to access as deliverer but aren't one
                    Auth::logout();
                    // Don't redirect, show error message
                } else {
                    // Perform the redirect
                    header('Location: ' . $redirect_url);
                    exit;
                }
            } else {
                $error_message = $result['message'];
            }
        }
    }
}

// Set page title
$page_title = 'Login - ' . SITE_NAME;

// Get login type from URL for styling
$is_deliverer_login = ($login_type === 'deliverer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Login to your Bless Mall account - Access quality Chinese products at affordable prices in Ghana">
    <meta name="keywords" content="Bless Mall, login, Ghana, Chinese products, e-commerce">
    <meta name="author" content="Bless Mall">
    
    <title><?php echo $page_title; ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo ASSETS_URL; ?>images/favicon.ico">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: <?php echo DARK_TEXT; ?>;
        }
        
        /* Login Container */
        .login-wrapper {
            width: 100%;
            max-width: 520px;
        }
        
        .login-container {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            padding: 40px;
            position: relative;
            overflow: hidden;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, <?php echo $is_deliverer_login ? '#FF6B35' : PRIMARY_GREEN; ?>, <?php echo $is_deliverer_login ? '#FF8C5A' : GOLD_ACCENT; ?>, <?php echo $is_deliverer_login ? '#FFA07A' : LIGHT_GREEN; ?>);
        }
        
        /* Header */
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: <?php echo DARK_TEXT; ?>;
            margin-bottom: 8px;
        }
        
        .login-header p {
            color: #6b7280;
            font-size: 15px;
            margin-bottom: 0;
        }
        
        /* Delivery Badge */
        .delivery-badge {
            display: inline-block;
            background: #FFF3EB;
            color: #FF6B35;
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
        }
        
        /* Role Icons */
        .role-icons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 15px;
            padding: 10px;
            background: #f9fafb;
            border-radius: 50px;
            flex-wrap: wrap;
        }
        
        .role-icon {
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 12px;
            color: #9ca3af;
            transition: all 0.3s ease;
            text-decoration: none;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 8px;
        }
        
        .role-icon i {
            font-size: 20px;
            margin-bottom: 4px;
        }
        
        .role-icon:hover {
            color: <?php echo PRIMARY_GREEN; ?>;
            background: rgba(11, 110, 61, 0.05);
        }
        
        .role-icon.active {
            color: <?php echo PRIMARY_GREEN; ?>;
            background: rgba(11, 110, 61, 0.1);
        }
        
        .role-icon.deliverer-active {
            color: #FF6B35;
            background: rgba(255, 107, 53, 0.1);
        }
        
        .role-icon.deliverer-active:hover {
            color: #FF6B35;
            background: rgba(255, 107, 53, 0.15);
        }
        
        /* Alert Messages */
        .alert {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            animation: slideDown 0.3s ease-out;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-icon {
            flex-shrink: 0;
            font-size: 18px;
            margin-top: 1px;
        }
        
        /* Form Styles */
        .login-form {
            margin-top: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: <?php echo DARK_TEXT; ?>;
            margin-bottom: 8px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 18px;
            pointer-events: none;
            transition: color 0.3s ease;
            z-index: 1;
        }
        
        .form-input {
            width: 100%;
            padding: 14px 16px 14px 45px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            color: <?php echo DARK_TEXT; ?>;
            background: #f9fafb;
            transition: all 0.3s ease;
            outline: none;
            font-family: 'Inter', sans-serif;
        }
        
        .form-input:focus {
            border-color: <?php echo $is_deliverer_login ? '#FF6B35' : PRIMARY_GREEN; ?>;
            background: #ffffff;
            box-shadow: 0 0 0 4px <?php echo $is_deliverer_login ? 'rgba(255, 107, 53, 0.1)' : 'rgba(11, 110, 61, 0.1)'; ?>;
        }
        
        .form-input:focus ~ .input-icon {
            color: <?php echo $is_deliverer_login ? '#FF6B35' : PRIMARY_GREEN; ?>;
        }
        
        .form-input::placeholder {
            color: #9ca3af;
        }
        
        /* Password Group */
        .password-wrapper {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 5px;
            font-size: 16px;
            transition: color 0.3s ease;
            z-index: 2;
        }
        
        .toggle-password:hover {
            color: <?php echo $is_deliverer_login ? '#FF6B35' : PRIMARY_GREEN; ?>;
        }
        
        /* Form Options */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: <?php echo $is_deliverer_login ? '#FF6B35' : PRIMARY_GREEN; ?>;
            cursor: pointer;
        }
        
        .remember-me label {
            font-size: 14px;
            color: #4b5563;
            cursor: pointer;
            user-select: none;
        }
        
        .forgot-password {
            font-size: 14px;
            color: <?php echo $is_deliverer_login ? '#FF6B35' : PRIMARY_GREEN; ?>;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .forgot-password:hover {
            color: <?php echo $is_deliverer_login ? '#E55A2B' : LIGHT_GREEN; ?>;
            text-decoration: underline;
        }
        
        /* Submit Button */
        .btn-login {
            width: 100%;
            padding: 16px;
            background: <?php echo $is_deliverer_login ? 'linear-gradient(135deg, #FF6B35, #FF8C5A)' : 'linear-gradient(135deg, ' . PRIMARY_GREEN . ', ' . LIGHT_GREEN . ')'; ?>;
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
            font-family: 'Inter', sans-serif;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px <?php echo $is_deliverer_login ? 'rgba(255, 107, 53, 0.3)' : 'rgba(11, 110, 61, 0.3)'; ?>;
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Divider */
        .divider {
            margin: 25px 0;
            position: relative;
            text-align: center;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: calc(50% - 30px);
            height: 1px;
            background: #e5e7eb;
        }
        
        .divider::before {
            left: 0;
        }
        
        .divider::after {
            right: 0;
        }
        
        .divider span {
            background: #ffffff;
            padding: 0 15px;
            color: #9ca3af;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Guest Button */
        .guest-btn {
            width: 100%;
            padding: 16px;
            background: #f3f4f6;
            color: #374151;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-family: 'Inter', sans-serif;
        }
        
        .guest-btn:hover {
            background: #e5e7eb;
            border-color: #d1d5db;
        }
        
        /* Register Link */
        .register-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e5e7eb;
        }
        
        .register-link p {
            color: #6b7280;
            font-size: 15px;
            margin-bottom: 0;
        }
        
        .register-link a {
            color: <?php echo $is_deliverer_login ? '#FF6B35' : PRIMARY_GREEN; ?>;
            font-weight: 700;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .register-link a:hover {
            color: <?php echo $is_deliverer_login ? '#E55A2B' : LIGHT_GREEN; ?>;
            text-decoration: underline;
        }
        
        /* Deliverer Link */
        .deliverer-link {
            text-align: center;
            margin-top: 15px;
        }
        
        .deliverer-link a {
            color: #6b7280;
            text-decoration: none;
            font-size: 13px;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .deliverer-link a:hover {
            color: <?php echo PRIMARY_GREEN; ?>;
        }
        
        /* Trust Badges */
        .trust-badges {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 30px;
            color: #9ca3af;
            font-size: 13px;
            flex-wrap: wrap;
        }
        
        .trust-badges span {
            white-space: nowrap;
        }
        
        /* Back to Home */
        .back-home {
            text-align: center;
            margin-top: 25px;
        }
        
        .back-home a {
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .back-home a:hover {
            color: <?php echo PRIMARY_GREEN; ?>;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-container {
            animation: fadeIn 0.6s ease-out;
        }
        
        /* Loading State */
        .btn-login.loading {
            position: relative;
            pointer-events: none;
        }
        
        .btn-login.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        @keyframes spin {
            to {
                transform: translateY(-50%) rotate(360deg);
            }
        }
        
        /* Responsive Design */
        @media (max-width: 576px) {
            body {
                padding: 15px;
                align-items: flex-start;
                padding-top: 30px;
            }
            
            .login-container {
                padding: 30px 20px;
                border-radius: 15px;
            }
            
            .login-header h1 {
                font-size: 24px;
            }
            
            .login-header p {
                font-size: 14px;
            }
            
            .form-options {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .trust-badges {
                font-size: 12px;
                gap: 10px;
            }
            
            .role-icons {
                gap: 10px;
                padding: 8px 12px;
            }
            
            .role-icon {
                font-size: 10px;
                padding: 4px 8px;
            }
            
            .role-icon i {
                font-size: 16px;
            }
        }
        
        @media (max-width: 360px) {
            .login-container {
                padding: 25px 15px;
            }
            
            .login-header h1 {
                font-size: 22px;
            }
            
            .form-input {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <!-- Header -->
            <div class="login-header">
                <h1><?php echo $is_deliverer_login ? '🚚 Delivery Partner Login' : 'Welcome Back!'; ?></h1>
                <p><?php echo $is_deliverer_login ? 'Access your delivery dashboard' : 'Login to your Bless Mall account'; ?></p>
                
                <?php if ($is_deliverer_login): ?>
                <div class="delivery-badge">🛵 Delivery Partner Access</div>
                <?php endif; ?>
                
                <!-- Role Icons -->
                <div class="role-icons">
                    <a href="<?php echo BASE_URL; ?>login.php" class="role-icon <?php echo !$is_deliverer_login ? 'active' : ''; ?>" title="Customer Login">
                        <span style="font-size:20px;">👤</span>
                        <span>Buyer</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>login.php?type=deliverer" class="role-icon <?php echo $is_deliverer_login ? 'deliverer-active' : ''; ?>" title="Delivery Partner Login">
                        <span style="font-size:20px;">🛵</span>
                        <span>Delivery</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>login.php" class="role-icon" title="Seller Login">
                        <span style="font-size:20px;">🏪</span>
                        <span>Seller</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>login.php" class="role-icon" title="Administrator Login">
                        <span style="font-size:20px;">⚙️</span>
                        <span>Admin</span>
                    </a>
                </div>
            </div>

            <!-- Success Message -->
            <?php if ($success_message): ?>
            <div class="alert alert-success">
                <span class="alert-icon">✅</span>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if ($error_message): ?>
            <div class="alert alert-error">
                <span class="alert-icon">⚠️</span>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . ($is_deliverer_login ? '?type=deliverer' : '')); ?>" method="POST" class="login-form" id="loginForm" novalidate>
                <!-- CSRF Token -->
                <?php echo CSRFToken::field(); ?>
                <input type="hidden" name="login" value="1">
                <input type="hidden" name="login_type" value="<?php echo $is_deliverer_login ? 'deliverer' : 'user'; ?>">

                <!-- Email/Phone Input -->
                <div class="form-group">
                    <label for="email_or_phone" class="form-label">Email Address or Phone Number</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                <polyline points="22,6 12,13 2,6"/>
                            </svg>
                        </span>
                        <input 
                            type="text" 
                            id="email_or_phone" 
                            name="email_or_phone" 
                            class="form-input" 
                            placeholder="<?php echo $is_deliverer_login ? 'Enter your registered email or phone' : 'e.g., user@email.com or 024XXXXXXX'; ?>"
                            value="<?php echo htmlspecialchars($email_or_phone); ?>"
                            required
                            autocomplete="username"
                            autofocus
                        >
                    </div>
                </div>

                <!-- Password Input -->
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-wrapper password-wrapper">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input" 
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Remember Me & Forgot Password -->
                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember_me" value="1" checked>
                        <span>Remember me</span>
                    </label>
                    <a href="<?php echo BASE_URL; ?>forgot-password.php" class="forgot-password">Forgot Password?</a>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-login" id="loginButton">
                    <span id="loginText"><?php echo $is_deliverer_login ? 'Login as Delivery Partner' : 'Login to Your Account'; ?></span>
                </button>
            </form>

            <!-- Guest Checkout Option (only for regular users) -->
            <?php if (!$is_deliverer_login): ?>
            <div class="divider">
                <span>or</span>
            </div>

            <a href="<?php echo BASE_URL; ?>shop.php" class="guest-btn">
                🛍️ Continue as Guest (Browse Products)
            </a>
            <?php endif; ?>

            <!-- Register Link -->
            <div class="register-link">
                <p><?php echo $is_deliverer_login ? 'Don\'t have a delivery account?' : 'Don\'t have an account?'; ?> 
                <a href="<?php echo BASE_URL; ?>register.php<?php echo $is_deliverer_login ? '?type=deliverer' : ''; ?>">
                    <?php echo $is_deliverer_login ? 'Apply to become a Delivery Partner' : 'Create New Account'; ?>
                </a></p>
            </div>
            
            <!-- Deliverer Registration Link (only for regular login page) -->
            <?php if (!$is_deliverer_login): ?>
            <div class="deliverer-link">
                <a href="<?php echo BASE_URL; ?>register.php?type=deliverer">
                    🛵 Want to become a Delivery Partner? Apply here →
                </a>
            </div>
            <?php endif; ?>

            <!-- Trust Badges -->
            <div class="trust-badges">
                <span>🔒 Secure Login</span>
                <span>•</span>
                <span>💳 MTN • Vodafone • AirtelTigo</span>
                <span>•</span>
                <span>🇬🇭 Ghana</span>
                <span>•</span>
                <span>🚚 Free Delivery over ₵300</span>
            </div>
        </div>
        
        <!-- Back to Home -->
        <div class="back-home">
            <a href="<?php echo BASE_URL; ?>">
                ← Back to Home
            </a>
        </div>
    </div>

    <!-- JavaScript for Password Toggle & Form Interaction -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Update icon
                const svg = this.querySelector('svg');
                if (type === 'text') {
                    // Eye-off icon
                    svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
                } else {
                    // Eye icon
                    svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
                }
            });
        }

        // Form submission with loading state
        const loginForm = document.getElementById('loginForm');
        const loginButton = document.getElementById('loginButton');
        const loginText = document.getElementById('loginText');

        if (loginForm && loginButton) {
            loginForm.addEventListener('submit', function(e) {
                // Basic client-side validation
                const emailPhone = document.getElementById('email_or_phone').value.trim();
                const password = passwordInput.value.trim();

                if (!emailPhone || !password) {
                    e.preventDefault();
                    return false;
                }

                // Show loading state
                loginButton.disabled = true;
                loginButton.classList.add('loading');
                loginText.textContent = 'Authenticating...';
                
                // Form will submit normally
                return true;
            });
        }

        // Clear error messages when user starts typing
        const inputs = document.querySelectorAll('.form-input');
        const alertError = document.querySelector('.alert-error');
        
        inputs.forEach(input => {
            input.addEventListener('input', function() {
                if (alertError) {
                    alertError.style.opacity = '0';
                    setTimeout(() => {
                        if (alertError) {
                            alertError.remove();
                        }
                    }, 300);
                }
            });
        });

        // Auto-focus on appropriate field
        const emailPhoneInput = document.getElementById('email_or_phone');
        if (emailPhoneInput) {
            emailPhoneInput.focus();
        }
    });
    </script>
</body>
</html>