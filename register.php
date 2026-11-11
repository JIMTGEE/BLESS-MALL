<?php
/**
 * register.php - Bless Mall Registration Page
 * Quality Chinese Products | Ghana
 * 
 * Features:
 *   - User registration with email/phone
 *   - Deliverer registration with vehicle details
 *   - Password strength validation
 *   - Argon2id/Bcrypt password hashing
 *   - CSRF protection
 *   - Auto-login after successful registration
 *   - Mobile-responsive design
 */

// Load core configuration and authentication system
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Check if registering as deliverer
$is_deliverer_registration = isset($_GET['type']) && $_GET['type'] === 'deliverer';

// Initialize page variables
$error_message = '';
$success_message = '';
$form_data = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'vehicle_type' => '',
    'license_number' => ''
];

// If user is already logged in, redirect to appropriate page
if (Auth::isLoggedIn()) {
    if (Auth::isAdmin() || Auth::isSeller()) {
        header('Location: ' . BASE_URL . 'admin/index.php');
        exit;
    } elseif (Auth::isDeliverer()) {
        header('Location: ' . BASE_URL . 'delivery/index.php');
        exit;
    } else {
        header('Location: ' . BASE_URL . 'account.php');
        exit;
    }
}

// Process registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // Validate CSRF token
    if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
        $error_message = 'Security validation failed. Please try again.';
    } else {
        // Sanitize and capture form data
        $form_data['name'] = trim($_POST['name'] ?? '');
        $form_data['email'] = trim($_POST['email'] ?? '');
        $form_data['phone'] = trim($_POST['phone'] ?? '');
        $form_data['vehicle_type'] = trim($_POST['vehicle_type'] ?? '');
        $form_data['license_number'] = trim($_POST['license_number'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $agree_terms = isset($_POST['agree_terms']);
        $register_as_deliverer = isset($_POST['register_as_deliverer']);

        // Server-side validation
        $errors = [];

        // Name validation
        if (empty($form_data['name'])) {
            $errors[] = 'Full name is required.';
        } elseif (strlen($form_data['name']) < 3) {
            $errors[] = 'Name must be at least 3 characters.';
        } elseif (strlen($form_data['name']) > 150) {
            $errors[] = 'Name must not exceed 150 characters.';
        } elseif (!preg_match('/^[a-zA-Z\s\'-]+$/', $form_data['name'])) {
            $errors[] = 'Name contains invalid characters.';
        }

        // Email validation (optional but validated if provided)
        if (!empty($form_data['email'])) {
            if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
        }

        // Phone validation (required)
        if (empty($form_data['phone'])) {
            $errors[] = 'Phone number is required.';
        } elseif (!preg_match('/^0[2-9][0-9]{8}$/', $form_data['phone'])) {
            $errors[] = 'Please enter a valid Ghana phone number (e.g., 024XXXXXXX).';
        }

        // Deliverer-specific validations
        if ($register_as_deliverer) {
            if (empty($form_data['vehicle_type'])) {
                $errors[] = 'Vehicle type is required for delivery partners.';
            }
            if (empty($form_data['license_number'])) {
                $errors[] = 'License number is required for delivery partners.';
            }
        }

        // Password validation
        $password_validation = Auth::validatePasswordStrength($password);
        if (empty($password)) {
            $errors[] = 'Password is required.';
        } elseif (!$password_validation['valid']) {
            $errors[] = $password_validation['message'];
        }

        // Confirm password
        if ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match.';
        }

        // Terms agreement
        if (!$agree_terms) {
            $errors[] = 'You must agree to the Terms & Conditions.';
        }

        // If no validation errors, proceed with registration
        if (empty($errors)) {
            // Prepare registration data
            $registration_data = [
                'name' => $form_data['name'],
                'email' => $form_data['email'],
                'phone' => $form_data['phone'],
                'password' => $password,
                'register_as_deliverer' => $register_as_deliverer,
                'vehicle_type' => $form_data['vehicle_type'],
                'license_number' => $form_data['license_number']
            ];

            try {
                // Attempt registration
                $result = registerUserWithDeliverer($registration_data, $conn);

                if ($result['success']) {
                    // Login the user automatically
                    $login_result = Auth::login($form_data['phone'], $password, false);
                    
                    if ($login_result['success']) {
                        // If deliverer, set deliverer session
                        if ($register_as_deliverer) {
                            $user_id = Auth::getCurrentUserId();
                            $check_deliverer = $conn->prepare("SELECT id FROM deliverers WHERE user_id = ?");
                            $check_deliverer->bind_param('i', $user_id);
                            $check_deliverer->execute();
                            $deliverer_result = $check_deliverer->get_result();
                            
                            if ($deliverer_result->num_rows > 0) {
                                $deliverer_data = $deliverer_result->fetch_assoc();
                                $_SESSION['deliverer_id'] = $deliverer_data['id'];
                                $_SESSION['user_role'] = 'deliverer';
                            }
                            $check_deliverer->close();
                        }
                        
                        $_SESSION['success_message'] = $result['message'];
                        
                        // Redirect based on registration type
                        if ($register_as_deliverer) {
                            header('Location: ' . BASE_URL . 'delivery/index.php');
                        } else {
                            header('Location: ' . BASE_URL . 'account.php');
                        }
                        exit;
                    } else {
                        $error_message = 'Account created but login failed. Please login manually.';
                    }
                } else {
                    $error_message = $result['message'];
                }
            } catch (Exception $e) {
                error_log("Registration Error: " . $e->getMessage());
                $error_message = 'Registration failed. Please try again later.';
            }
        } else {
            // Combine all validation errors
            $error_message = implode('<br>', $errors);
        }
    }
}

/**
 * Register user with optional deliverer profile
 */
function registerUserWithDeliverer($data, $conn) {
    // Check if phone number already exists
    $check_phone = $conn->prepare("SELECT id FROM users WHERE phone = ?");
    $check_phone->bind_param('s', $data['phone']);
    $check_phone->execute();
    if ($check_phone->get_result()->num_rows > 0) {
        $check_phone->close();
        return ['success' => false, 'message' => 'Phone number already registered.'];
    }
    $check_phone->close();

    // Check if email already exists (if provided)
    if (!empty($data['email'])) {
        $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check_email->bind_param('s', $data['email']);
        $check_email->execute();
        if ($check_email->get_result()->num_rows > 0) {
            $check_email->close();
            return ['success' => false, 'message' => 'Email address already registered.'];
        }
        $check_email->close();
    }

    // Determine role_id (1 = buyer, 3 = deliverer based on roles table)
    $role_id = $data['register_as_deliverer'] ? 3 : 1;
    
    // Hash password
    $hashed_password = password_hash($data['password'], PASSWORD_ARGON2ID);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert into users table
        $insert_user = $conn->prepare("
            INSERT INTO users (role_id, name, email, phone, password, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $insert_user->bind_param('issss', $role_id, $data['name'], $data['email'], $data['phone'], $hashed_password);
        
        if (!$insert_user->execute()) {
            throw new Exception("Failed to create user account");
        }
        
        $user_id = $conn->insert_id;
        $insert_user->close();
        
        // If registering as deliverer, insert into deliverers table
        if ($data['register_as_deliverer']) {
            $insert_deliverer = $conn->prepare("
                INSERT INTO deliverers (user_id, vehicle_type, license_number, availability_status, created_at) 
                VALUES (?, ?, ?, 'offline', NOW())
            ");
            $insert_deliverer->bind_param('iss', $user_id, $data['vehicle_type'], $data['license_number']);
            
            if (!$insert_deliverer->execute()) {
                throw new Exception("Failed to create deliverer profile");
            }
            $insert_deliverer->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        $message = $data['register_as_deliverer'] 
            ? 'Your delivery partner account has been created successfully! Welcome aboard! 🛵' 
            : 'Account created successfully! Welcome to Bless Mall! 🎉';
        
        return ['success' => true, 'message' => $message, 'user_id' => $user_id];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Registration Transaction Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Registration failed. Please try again.'];
    }
}

// Set page title
$page_title = ($is_deliverer_registration ? 'Become a Delivery Partner - ' : 'Create Account - ') . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo $is_deliverer_registration ? 'Join Bless Mall as a delivery partner - Earn money delivering quality Chinese products in Ghana' : 'Create your Bless Mall account - Shop quality Chinese products at affordable prices in Ghana'; ?>">
    <meta name="keywords" content="Bless Mall, register, sign up, Ghana, Chinese products, e-commerce, delivery partner">
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
        
        /* Register Container */
        .register-wrapper {
            width: 100%;
            max-width: 580px;
        }
        
        .register-container {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            padding: 40px;
            position: relative;
            overflow: hidden;
        }
        
        .register-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, <?php echo GOLD_ACCENT; ?>, <?php echo PRIMARY_GREEN; ?>, <?php echo LIGHT_GREEN; ?>);
        }
        
        /* Header */
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .register-logo {
            width: 100px;
            height: auto;
            margin-bottom: 15px;
        }
        
        .register-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: <?php echo DARK_TEXT; ?>;
            margin-bottom: 8px;
        }
        
        .register-header p {
            color: #6b7280;
            font-size: 15px;
            margin-bottom: 0;
        }
        
        /* Benefits Bar */
        .benefits-bar {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 25px;
            padding: 15px;
            background: <?php echo $is_deliverer_registration ? '#fef3c7' : '#f0fdf4'; ?>;
            border-radius: 12px;
            flex-wrap: wrap;
        }
        
        .benefit-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: <?php echo $is_deliverer_registration ? '#92400e' : '#166534'; ?>;
            font-weight: 500;
        }
        
        .benefit-icon {
            font-size: 16px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.6;
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
        .register-form {
            margin-top: 5px;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: <?php echo DARK_TEXT; ?>;
            margin-bottom: 6px;
        }
        
        .form-label .optional {
            color: #9ca3af;
            font-weight: 400;
            font-size: 12px;
        }
        
        .form-label .required {
            color: #ef4444;
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
            border-color: <?php echo PRIMARY_GREEN; ?>;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(11, 110, 61, 0.1);
        }
        
        .form-input:focus ~ .input-icon {
            color: <?php echo PRIMARY_GREEN; ?>;
        }
        
        .form-input::placeholder {
            color: #9ca3af;
        }
        
        .form-input.error {
            border-color: #ef4444;
        }
        
        .form-input.success {
            border-color: #22c55e;
        }
        
        /* Password Group */
        .password-wrapper {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 45px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 5px;
            transition: color 0.3s ease;
            z-index: 2;
        }
        
        .toggle-password:hover {
            color: <?php echo PRIMARY_GREEN; ?>;
        }
        
        /* Password Strength Meter */
        .password-strength {
            margin-top: 8px;
        }
        
        .strength-bar {
            height: 4px;
            border-radius: 2px;
            background: #e5e7eb;
            margin-bottom: 5px;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            border-radius: 2px;
            transition: all 0.3s ease;
            width: 0;
        }
        
        .strength-text {
            font-size: 12px;
            font-weight: 500;
        }
        
        .strength-weak { color: #ef4444; }
        .strength-fair { color: #f59e0b; }
        .strength-good { color: #3b82f6; }
        .strength-strong { color: #22c55e; }
        
        .strength-fill.weak { width: 25%; background: #ef4444; }
        .strength-fill.fair { width: 50%; background: #f59e0b; }
        .strength-fill.good { width: 75%; background: #3b82f6; }
        .strength-fill.strong { width: 100%; background: #22c55e; }
        
        /* Terms Checkbox */
        .terms-group {
            margin-bottom: 22px;
        }
        
        .terms-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
        }
        
        .terms-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: <?php echo PRIMARY_GREEN; ?>;
            cursor: pointer;
            flex-shrink: 0;
        }
        
        .terms-text {
            font-size: 14px;
            color: #4b5563;
            line-height: 1.5;
        }
        
        .terms-text a {
            color: <?php echo PRIMARY_GREEN; ?>;
            text-decoration: none;
            font-weight: 600;
        }
        
        .terms-text a:hover {
            text-decoration: underline;
        }
        
        /* Submit Button */
        .btn-register {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, <?php echo PRIMARY_GREEN; ?>, <?php echo LIGHT_GREEN; ?>);
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
        
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(11, 110, 61, 0.3);
        }
        
        .btn-register:active {
            transform: translateY(0);
        }
        
        .btn-register:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Login Link */
        .login-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e5e7eb;
        }
        
        .login-link p {
            color: #6b7280;
            font-size: 15px;
            margin-bottom: 0;
        }
        
        .login-link a {
            color: <?php echo PRIMARY_GREEN; ?>;
            font-weight: 700;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .login-link a:hover {
            color: <?php echo LIGHT_GREEN; ?>;
            text-decoration: underline;
        }
        
        /* Registration Type Toggle */
        .reg-type-toggle {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            background: #f3f4f6;
            padding: 6px;
            border-radius: 50px;
        }
        
        .reg-type-btn {
            flex: 1;
            text-align: center;
            padding: 10px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 14px;
            color: #6b7280;
        }
        
        .reg-type-btn.active {
            background: <?php echo PRIMARY_GREEN; ?>;
            color: white;
            box-shadow: 0 2px 8px rgba(11, 110, 61, 0.2);
        }
        
        /* Trust Badges */
        .trust-badges {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 25px;
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
        
        .register-container {
            animation: fadeIn 0.6s ease-out;
        }
        
        /* Loading State */
        .btn-register.loading {
            position: relative;
            pointer-events: none;
        }
        
        .btn-register.loading::after {
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
            
            .register-container {
                padding: 30px 20px;
                border-radius: 15px;
            }
            
            .register-header h1 {
                font-size: 24px;
            }
            
            .register-header p {
                font-size: 14px;
            }
            
            .benefits-bar {
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
            
            .trust-badges {
                font-size: 12px;
                gap: 10px;
            }
            
            .reg-type-toggle {
                flex-direction: column;
                border-radius: 12px;
            }
            
            .reg-type-btn {
                border-radius: 8px;
            }
        }
        
        @media (max-width: 360px) {
            .register-container {
                padding: 25px 15px;
            }
            
            .register-header h1 {
                font-size: 22px;
            }
            
            .form-input {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="register-wrapper">
        <div class="register-container">
            <!-- Header -->
            <div class="register-header">
                <img src="<?php echo ASSETS_URL; ?>images/logo.jpeg" alt="<?php echo SITE_NAME; ?>" class="register-logo">
                <h1><?php echo $is_deliverer_registration ? 'Become a Delivery Partner' : 'Create Your Account'; ?></h1>
                <p><?php echo $is_deliverer_registration ? 'Join our team and start earning today' : 'Join Bless Mall and start shopping'; ?></p>
            </div>

            <!-- Registration Type Toggle (only show on main register page) -->
            <?php if (!isset($_GET['type'])): ?>
            <div class="reg-type-toggle">
                <div class="reg-type-btn <?php echo !$is_deliverer_registration ? 'active' : ''; ?>" data-type="customer">
                    👤 Customer Account
                </div>
                <div class="reg-type-btn <?php echo $is_deliverer_registration ? 'active' : ''; ?>" data-type="deliverer">
                    🛵 Delivery Partner
                </div>
            </div>
            <?php endif; ?>

            <!-- Benefits Bar -->
            <div class="benefits-bar">
                <?php if ($is_deliverer_registration): ?>
                    <div class="benefit-item">
                        <span class="benefit-icon">💰</span>
                        <span>Competitive earnings</span>
                    </div>
                    <div class="benefit-item">
                        <span class="benefit-icon">⏰</span>
                        <span>Flexible hours</span>
                    </div>
                    <div class="benefit-item">
                        <span class="benefit-icon">🛵</span>
                        <span>Become your own boss</span>
                    </div>
                <?php else: ?>
                    <div class="benefit-item">
                        <span class="benefit-icon">⭐</span>
                        <span>Quality products</span>
                    </div>
                    <div class="benefit-item">
                        <span class="benefit-icon">💳</span>
                        <span>Secure payments</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Error Message -->
            <?php if ($error_message): ?>
            <div class="alert alert-error">
                <span class="alert-icon">⚠️</span>
                <span><?php echo $error_message; ?></span>
            </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . ($is_deliverer_registration ? '?type=deliverer' : ''); ?>" method="POST" class="register-form" id="registerForm" novalidate>
                <!-- CSRF Token -->
                <?php echo CSRFToken::field(); ?>
                <input type="hidden" name="register" value="1">
                <input type="hidden" name="register_as_deliverer" id="registerAsDeliverer" value="<?php echo $is_deliverer_registration ? '1' : '0'; ?>">

                <!-- Full Name -->
                <div class="form-group">
                    <label for="name" class="form-label">Full Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </span>
                        <input 
                            type="text" 
                            id="name" 
                            name="name" 
                            class="form-input" 
                            placeholder="Enter your full name"
                            value="<?php echo htmlspecialchars($form_data['name']); ?>"
                            required
                            autocomplete="name"
                            maxlength="150"
                        >
                    </div>
                </div>

                <!-- Email Address -->
                <div class="form-group">
                    <label for="email" class="form-label">Email Address <span class="optional">(optional)</span></label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                <polyline points="22,6 12,13 2,6"/>
                            </svg>
                        </span>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input" 
                            placeholder="e.g., user@email.com"
                            value="<?php echo htmlspecialchars($form_data['email']); ?>"
                            autocomplete="email"
                        >
                    </div>
                </div>

                <!-- Phone Number -->
                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                            </svg>
                        </span>
                        <input 
                            type="tel" 
                            id="phone" 
                            name="phone" 
                            class="form-input" 
                            placeholder="e.g., 024XXXXXXX"
                            value="<?php echo htmlspecialchars($form_data['phone']); ?>"
                            required
                            autocomplete="tel"
                            pattern="0[2-9][0-9]{8}"
                            maxlength="10"
                        >
                    </div>
                </div>

                <!-- Deliverer-specific fields -->
                <div id="delivererFields" style="display: <?php echo $is_deliverer_registration ? 'block' : 'none'; ?>;">
                    <div class="form-group">
                        <label for="vehicle_type" class="form-label">Vehicle Type <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12h4l2-3h6l2 3h4M3 12v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2m8 2v2a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-6"/>
                                    <circle cx="5" cy="17" r="2"/>
                                    <circle cx="17" cy="17" r="2"/>
                                </svg>
                            </span>
                            <select id="vehicle_type" name="vehicle_type" class="form-input" style="padding-left: 45px;">
                                <option value="">Select vehicle type</option>
                                <option value="bicycle" <?php echo $form_data['vehicle_type'] === 'bicycle' ? 'selected' : ''; ?>>🚲 Bicycle</option>
                                <option value="motorbike" <?php echo $form_data['vehicle_type'] === 'motorbike' ? 'selected' : ''; ?>>🏍️ Motorbike</option>
                                <option value="car" <?php echo $form_data['vehicle_type'] === 'car' ? 'selected' : ''; ?>>🚗 Car</option>
                                <option value="van" <?php echo $form_data['vehicle_type'] === 'van' ? 'selected' : ''; ?>>🚐 Van/Truck</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="license_number" class="form-label">Driver's License Number <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="2" y="3" width="20" height="18" rx="2" ry="2"/>
                                    <line x1="8" y1="9" x2="16" y2="9"/>
                                    <line x1="8" y1="13" x2="12" y2="13"/>
                                    <line x1="15" y1="13" x2="16" y2="13"/>
                                    <line x1="8" y1="17" x2="16" y2="17"/>
                                </svg>
                            </span>
                            <input 
                                type="text" 
                                id="license_number" 
                                name="license_number" 
                                class="form-input" 
                                placeholder="e.g., GW-1234-5678"
                                value="<?php echo htmlspecialchars($form_data['license_number']); ?>"
                                autocomplete="off"
                            >
                        </div>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label for="password" class="form-label">Password <span class="required">*</span></label>
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
                            placeholder="Create a strong password"
                            required
                            autocomplete="new-password"
                            minlength="8"
                        >
                        <button type="button" class="toggle-password" data-target="password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                        <!-- Password Strength Meter -->
                        <div class="password-strength" id="passwordStrength" style="display: none;">
                            <div class="strength-bar">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                            <span class="strength-text" id="strengthText"></span>
                        </div>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password <span class="required">*</span></label>
                    <div class="input-wrapper password-wrapper">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-input" 
                            placeholder="Confirm your password"
                            required
                            autocomplete="new-password"
                        >
                        <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Terms & Conditions -->
                <div class="terms-group">
                    <label class="terms-checkbox">
                        <input type="checkbox" name="agree_terms" value="1" required>
                        <span class="terms-text">
                            I agree to the <a href="<?php echo BASE_URL; ?>terms.php" target="_blank">Terms & Conditions</a> and 
                            <a href="<?php echo BASE_URL; ?>privacy.php" target="_blank">Privacy Policy</a>
                        </span>
                    </label>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-register" id="registerButton">
                    <span id="registerText"><?php echo $is_deliverer_registration ? 'Apply as Delivery Partner' : 'Create Account'; ?></span>
                </button>
            </form>

            <!-- Login Link -->
            <div class="login-link">
                <p>Already have an account? <a href="<?php echo BASE_URL; ?>login.php">Login Here</a></p>
            </div>

            <!-- Trust Badges -->
            <div class="trust-badges">
                <span>🔒 Secure Registration</span>
                <span>•</span>
                <span>💳 MTN • Vodafone • AirtelTigo</span>
                <span>•</span>
                <span>🇬🇭 Ghana</span>
            </div>
        </div>
        
        <!-- Back to Home -->
        <div class="back-home">
            <a href="<?php echo BASE_URL; ?>">
                ← Back to Home
            </a>
        </div>
    </div>

    <!-- JavaScript for Form Interaction -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Registration type toggle
        const typeBtns = document.querySelectorAll('.reg-type-btn');
        const delivererFields = document.getElementById('delivererFields');
        const registerAsDeliverer = document.getElementById('registerAsDeliverer');
        const registerText = document.getElementById('registerText');
        const registerHeader = document.querySelector('.register-header h1');
        const registerSubtext = document.querySelector('.register-header p');
        const benefitsBar = document.querySelector('.benefits-bar');
        
        typeBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const type = this.getAttribute('data-type');
                const isDeliverer = type === 'deliverer';
                
                // Update active state
                typeBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Update hidden field
                registerAsDeliverer.value = isDeliverer ? '1' : '0';
                
                // Show/hide deliverer fields
                delivererFields.style.display = isDeliverer ? 'block' : 'none';
                
                // Update button text
                registerText.textContent = isDeliverer ? 'Apply as Delivery Partner' : 'Create Account';
                
                // Update header
                registerHeader.textContent = isDeliverer ? 'Become a Delivery Partner' : 'Create Your Account';
                registerSubtext.textContent = isDeliverer ? 'Join our team and start earning today' : 'Join Bless Mall and start shopping';
                
                // Update benefits bar
                if (isDeliverer) {
                    benefitsBar.innerHTML = `
                        <div class="benefit-item">
                            <span class="benefit-icon">💰</span>
                            <span>Competitive earnings</span>
                        </div>
                        <div class="benefit-item">
                            <span class="benefit-icon">⏰</span>
                            <span>Flexible hours</span>
                        </div>
                        <div class="benefit-item">
                            <span class="benefit-icon">🛵</span>
                            <span>Become your own boss</span>
                        </div>
                    `;
                    benefitsBar.style.background = '#fef3c7';
                    benefitsBar.querySelectorAll('.benefit-item').forEach(item => {
                        item.style.color = '#92400e';
                    });
                } else {
                    benefitsBar.innerHTML = `
                        <div class="benefit-item">
                            <span class="benefit-icon">⭐</span>
                            <span>Quality products</span>
                        </div>
                        <div class="benefit-item">
                            <span class="benefit-icon">💳</span>
                            <span>Secure payments</span>
                        </div>
                    `;
                    benefitsBar.style.background = '#f0fdf4';
                    benefitsBar.querySelectorAll('.benefit-item').forEach(item => {
                        item.style.color = '#166534';
                    });
                }
                
                // Update URL without reload
                const url = new URL(window.location.href);
                if (isDeliverer) {
                    url.searchParams.set('type', 'deliverer');
                } else {
                    url.searchParams.delete('type');
                }
                window.history.pushState({}, '', url);
            });
        });

        // Password visibility toggle
        const toggleButtons = document.querySelectorAll('.toggle-password');
        
        toggleButtons.forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const passwordInput = document.getElementById(targetId);
                
                if (passwordInput) {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    
                    // Update icon
                    const svg = this.querySelector('svg');
                    if (type === 'text') {
                        svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
                    } else {
                        svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
                    }
                }
            });
        });

        // Password Strength Meter
        const passwordInput = document.getElementById('password');
        const strengthMeter = document.getElementById('passwordStrength');
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');
        
        if (passwordInput && strengthMeter) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                
                if (password.length === 0) {
                    strengthMeter.style.display = 'none';
                    return;
                }
                
                strengthMeter.style.display = 'block';
                
                // Calculate strength
                let score = 0;
                
                // Length check
                if (password.length >= 8) score++;
                if (password.length >= 12) score++;
                
                // Character variety
                if (/[A-Z]/.test(password)) score++;
                if (/[a-z]/.test(password)) score++;
                if (/[0-9]/.test(password)) score++;
                if (/[^A-Za-z0-9]/.test(password)) score++;
                
                // Update UI
                strengthFill.className = 'strength-fill';
                strengthText.className = 'strength-text';
                
                if (score <= 2) {
                    strengthFill.classList.add('weak');
                    strengthText.classList.add('strength-weak');
                    strengthText.textContent = 'Weak password';
                } else if (score <= 3) {
                    strengthFill.classList.add('fair');
                    strengthText.classList.add('strength-fair');
                    strengthText.textContent = 'Fair password';
                } else if (score <= 4) {
                    strengthFill.classList.add('good');
                    strengthText.classList.add('strength-good');
                    strengthText.textContent = 'Good password';
                } else {
                    strengthFill.classList.add('strong');
                    strengthText.classList.add('strength-strong');
                    strengthText.textContent = 'Strong password';
                }
            });
        }

        // Phone number formatting (Ghana format)
        const phoneInput = document.getElementById('phone');
        
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let value = this.value.replace(/\D/g, '');
                
                // Ensure it starts with 0
                if (value.length > 0 && value[0] !== '0') {
                    value = '0' + value;
                }
                
                // Limit to 10 digits
                if (value.length > 10) {
                    value = value.slice(0, 10);
                }
                
                this.value = value;
            });
        }

        // Confirm password match indicator
        const confirmPasswordInput = document.getElementById('confirm_password');
        
        if (confirmPasswordInput && passwordInput) {
            confirmPasswordInput.addEventListener('input', function() {
                if (this.value === '') {
                    this.classList.remove('error', 'success');
                } else if (this.value === passwordInput.value) {
                    this.classList.remove('error');
                    this.classList.add('success');
                } else {
                    this.classList.remove('success');
                    this.classList.add('error');
                }
            });
            
            // Also check when password changes
            passwordInput.addEventListener('input', function() {
                if (confirmPasswordInput.value !== '') {
                    if (confirmPasswordInput.value === this.value) {
                        confirmPasswordInput.classList.remove('error');
                        confirmPasswordInput.classList.add('success');
                    } else {
                        confirmPasswordInput.classList.remove('success');
                        confirmPasswordInput.classList.add('error');
                    }
                }
            });
        }

        // Form submission with loading state
        const registerForm = document.getElementById('registerForm');
        const registerButton = document.getElementById('registerButton');
        
        if (registerForm && registerButton) {
            registerForm.addEventListener('submit', function(e) {
                // Basic validation
                const name = document.getElementById('name').value.trim();
                const phone = document.getElementById('phone').value.trim();
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                const agreeTerms = document.querySelector('input[name="agree_terms"]').checked;
                const isDeliverer = registerAsDeliverer.value === '1';
                
                let hasError = false;
                
                if (!name || !phone || !password || !confirmPassword || !agreeTerms) {
                    hasError = true;
                }
                
                if (password !== confirmPassword) {
                    hasError = true;
                }
                
                // Deliverer-specific validation
                if (isDeliverer) {
                    const vehicleType = document.getElementById('vehicle_type').value;
                    const licenseNumber = document.getElementById('license_number').value.trim();
                    if (!vehicleType || !licenseNumber) {
                        hasError = true;
                    }
                }
                
                if (hasError) {
                    e.preventDefault();
                    return false;
                }

                // Show loading state
                registerButton.disabled = true;
                registerButton.classList.add('loading');
                registerText.textContent = isDeliverer ? 'Processing Application...' : 'Creating Account...';
                
                return true;
            });
        }

        // Clear error messages when user starts typing
        const formInputs = document.querySelectorAll('.form-input');
        const alertError = document.querySelector('.alert-error');
        
        formInputs.forEach(input => {
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

        // Auto-focus on name field
        const nameInput = document.getElementById('name');
        if (nameInput && !nameInput.value) {
            nameInput.focus();
        } else if (phoneInput && !phoneInput.value) {
            phoneInput.focus();
        }
    });
    </script>
</body>
</html>