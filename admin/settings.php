<?php
/**
 * settings.php - Bless Mall Admin Settings
 * System Configuration Management
 * 
 * Manage store settings, payment methods, delivery options,
 * email/SMS templates, and system preferences
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP
// ------------------------------------------------------------
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Settings, payment keys, and system controls are admin-only.
if (!Auth::isAdmin()) {
    header('Location: ' . BASE_URL . 'account.php?error=unauthorized');
    exit;
}

$is_admin = true;
$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
$user_email = htmlspecialchars($_SESSION['user_email'] ?? '');

// ------------------------------------------------------------
// 2. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 3. ACTIVE TAB
// ------------------------------------------------------------
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
$allowedTabs = ['general', 'payment', 'delivery', 'email', 'sms', 'store', 'tax', 'advanced'];
if (!in_array($activeTab, $allowedTabs)) {
    $activeTab = 'general';
}

// ------------------------------------------------------------
// 4. FETCH ALL SETTINGS
// ------------------------------------------------------------
$settingsData = [];
$settingsQuery = "SELECT setting_key, setting_value FROM settings ORDER BY setting_key ASC";
$settingsResult = $conn->query($settingsQuery);
if ($settingsResult && $settingsResult->num_rows > 0) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settingsData[$row['setting_key']] = $row['setting_value'];
    }
}

// Helper to get setting with fallback
function getSetting($key, $default = '') {
    global $settingsData;
    return $settingsData[$key] ?? $default;
}

// ------------------------------------------------------------
// 5. HELPER FUNCTIONS
// ------------------------------------------------------------
function saveSetting($key, $value) {
    global $conn;
    $key = $conn->real_escape_string($key);
    $value = $conn->real_escape_string($value);
    
    $sql = "INSERT INTO settings (setting_key, setting_value) 
            VALUES ('$key', '$value') 
            ON DUPLICATE KEY UPDATE setting_value = '$value', updated_at = CURRENT_TIMESTAMP";
    
    return $conn->query($sql);
}

function deleteSetting($key) {
    global $conn;
    $key = $conn->real_escape_string($key);
    return $conn->query("DELETE FROM settings WHERE setting_key = '$key'");
}

// ------------------------------------------------------------
// 6. FORM HANDLING
// ------------------------------------------------------------
$successMessage = '';
$errorMessage = '';
$csrfToken = CSRFToken::generate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Security validation failed.');
        }

        // ---------- GENERAL SETTINGS ----------
        if ($action === 'save_general') {
            saveSetting('site_name', $_POST['site_name'] ?? 'Bless Mall');
            saveSetting('site_tagline', $_POST['site_tagline'] ?? '');
            saveSetting('site_description', $_POST['site_description'] ?? '');
            saveSetting('admin_email', $_POST['admin_email'] ?? '');
            saveSetting('contact_phone', $_POST['contact_phone'] ?? '');
            saveSetting('contact_whatsapp', $_POST['contact_whatsapp'] ?? '');
            saveSetting('contact_address', $_POST['contact_address'] ?? '');
            saveSetting('currency', $_POST['currency'] ?? 'GHS');
            saveSetting('currency_symbol', $_POST['currency_symbol'] ?? '₵');
            saveSetting('timezone', $_POST['timezone'] ?? 'Africa/Accra');
            saveSetting('date_format', $_POST['date_format'] ?? 'd/m/Y');
            saveSetting('products_per_page', $_POST['products_per_page'] ?? '12');
            saveSetting('enable_maintenance_mode', $_POST['enable_maintenance_mode'] ?? '0');
            saveSetting('maintenance_message', $_POST['maintenance_message'] ?? '');
            
            $successMessage = 'General settings saved successfully!';
        }
        
        // ---------- PAYMENT SETTINGS ----------
        if ($action === 'save_payment') {
            saveSetting('payment_momo_enabled', $_POST['payment_momo_enabled'] ?? '0');
            saveSetting('payment_momo_merchant_id', $_POST['payment_momo_merchant_id'] ?? '');
            saveSetting('payment_momo_api_key', $_POST['payment_momo_api_key'] ?? '');
            saveSetting('payment_momo_network', $_POST['payment_momo_network'] ?? 'mtn');
            
            saveSetting('payment_card_enabled', $_POST['payment_card_enabled'] ?? '0');
            saveSetting('payment_card_processor', $_POST['payment_card_processor'] ?? '');
            saveSetting('payment_card_public_key', $_POST['payment_card_public_key'] ?? '');
            saveSetting('payment_card_secret_key', $_POST['payment_card_secret_key'] ?? '');
            
            saveSetting('payment_cod_enabled', $_POST['payment_cod_enabled'] ?? '1');
            saveSetting('payment_cod_fee', $_POST['payment_cod_fee'] ?? '0');
            
            saveSetting('payment_bank_transfer_enabled', $_POST['payment_bank_transfer_enabled'] ?? '0');
            saveSetting('payment_bank_details', $_POST['payment_bank_details'] ?? '');
            
            $successMessage = 'Payment settings saved successfully!';
        }
        
        // ---------- DELIVERY SETTINGS ----------
        if ($action === 'save_delivery') {
            saveSetting('delivery_base_fee', $_POST['delivery_base_fee'] ?? '15');
            saveSetting('delivery_free_threshold', $_POST['delivery_free_threshold'] ?? '300');
            saveSetting('delivery_express_enabled', $_POST['delivery_express_enabled'] ?? '1');
            saveSetting('delivery_express_fee', $_POST['delivery_express_fee'] ?? '25');
            saveSetting('delivery_express_time', $_POST['delivery_express_time'] ?? '24-48 hours');
            saveSetting('delivery_pickup_enabled', $_POST['delivery_pickup_enabled'] ?? '1');
            saveSetting('delivery_pickup_address', $_POST['delivery_pickup_address'] ?? '');
            saveSetting('delivery_partners', $_POST['delivery_partners'] ?? '');
            saveSetting('delivery_regions', $_POST['delivery_regions'] ?? '');
            
            $successMessage = 'Delivery settings saved successfully!';
        }
        
        // ---------- EMAIL SETTINGS ----------
        if ($action === 'save_email') {
            saveSetting('email_from_name', $_POST['email_from_name'] ?? 'Bless Mall');
            saveSetting('email_from_address', $_POST['email_from_address'] ?? '');
            saveSetting('email_smtp_host', $_POST['email_smtp_host'] ?? '');
            saveSetting('email_smtp_port', $_POST['email_smtp_port'] ?? '587');
            saveSetting('email_smtp_username', $_POST['email_smtp_username'] ?? '');
            saveSetting('email_smtp_password', $_POST['email_smtp_password'] ?? '');
            saveSetting('email_smtp_encryption', $_POST['email_smtp_encryption'] ?? 'tls');
            saveSetting('email_order_confirmation', $_POST['email_order_confirmation'] ?? '1');
            saveSetting('email_shipping_update', $_POST['email_shipping_update'] ?? '1');
            saveSetting('email_welcome_enabled', $_POST['email_welcome_enabled'] ?? '1');
            
            $successMessage = 'Email settings saved successfully!';
        }
        
        // ---------- SMS SETTINGS ----------
        if ($action === 'save_sms') {
            saveSetting('sms_enabled', $_POST['sms_enabled'] ?? '0');
            saveSetting('sms_provider', $_POST['sms_provider'] ?? '');
            saveSetting('sms_api_key', $_POST['sms_api_key'] ?? '');
            saveSetting('sms_api_secret', $_POST['sms_api_secret'] ?? '');
            saveSetting('sms_sender_id', $_POST['sms_sender_id'] ?? 'BlessMall');
            saveSetting('sms_order_updates', $_POST['sms_order_updates'] ?? '1');
            saveSetting('sms_delivery_updates', $_POST['sms_delivery_updates'] ?? '1');
            
            $successMessage = 'SMS settings saved successfully!';
        }
        
        // ---------- STORE SETTINGS ----------
        if ($action === 'save_store') {
            saveSetting('store_default_vendor', $_POST['store_default_vendor'] ?? '');
            saveSetting('store_commission_rate', $_POST['store_commission_rate'] ?? '0');
            saveSetting('store_min_order_amount', $_POST['store_min_order_amount'] ?? '0');
            saveSetting('store_max_order_amount', $_POST['store_max_order_amount'] ?? '0');
            saveSetting('store_order_prefix', $_POST['store_order_prefix'] ?? 'BM');
            saveSetting('store_invoice_prefix', $_POST['store_invoice_prefix'] ?? 'INV');
            saveSetting('store_tax_number', $_POST['store_tax_number'] ?? '');
            saveSetting('store_registration_number', $_POST['store_registration_number'] ?? '');
            
            $successMessage = 'Store settings saved successfully!';
        }
        
        // ---------- TAX SETTINGS ----------
        if ($action === 'save_tax') {
            saveSetting('tax_enabled', $_POST['tax_enabled'] ?? '1');
            saveSetting('tax_name', $_POST['tax_name'] ?? 'VAT');
            saveSetting('tax_rate', $_POST['tax_rate'] ?? '15');
            saveSetting('tax_inclusive_pricing', $_POST['tax_inclusive_pricing'] ?? '1');
            saveSetting('tax_display_prices', $_POST['tax_display_prices'] ?? 'inclusive');
            
            $successMessage = 'Tax settings saved successfully!';
        }
        
        // ---------- ADVANCED SETTINGS ----------
        if ($action === 'save_advanced') {
            saveSetting('advanced_cache_enabled', $_POST['advanced_cache_enabled'] ?? '0');
            saveSetting('advanced_cache_duration', $_POST['advanced_cache_duration'] ?? '3600');
            saveSetting('advanced_log_level', $_POST['advanced_log_level'] ?? 'error');
            saveSetting('advanced_max_login_attempts', $_POST['advanced_max_login_attempts'] ?? '5');
            saveSetting('advanced_session_lifetime', $_POST['advanced_session_lifetime'] ?? '7200');
            saveSetting('advanced_cookie_domain', $_POST['advanced_cookie_domain'] ?? '');
            
            $successMessage = 'Advanced settings saved successfully!';
        }
        
        // ---------- CLEAR CACHE ----------
        if ($action === 'clear_cache') {
            // Clear any cache files if implemented
            $cacheDir = __DIR__ . '/../cache/';
            if (is_dir($cacheDir)) {
                $files = glob($cacheDir . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            $successMessage = 'Cache cleared successfully!';
        }
        
        // Refresh settings after save
        $settingsResult = $conn->query($settingsQuery);
        $settingsData = [];
        if ($settingsResult && $settingsResult->num_rows > 0) {
            while ($row = $settingsResult->fetch_assoc()) {
                $settingsData[$row['setting_key']] = $row['setting_value'];
            }
        }
        
    } catch (Exception $e) {
        $errorMessage = 'Error saving settings: ' . $e->getMessage();
        error_log('Settings save error: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// 7. QUICK STATS
// ------------------------------------------------------------
$pendingOrders = 0;
$result = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE order_status = 'pending'");
if ($result && $row = $result->fetch_assoc()) {
    $pendingOrders = (int)$row['cnt'];
}

$totalProducts = 0;
$result = $conn->query("SELECT COUNT(*) as cnt FROM products WHERE is_active = 1");
if ($result && $row = $result->fetch_assoc()) {
    $totalProducts = (int)$row['cnt'];
}

$totalUsers = 0;
$result = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE is_active = 1 AND deleted_at IS NULL");
if ($result && $row = $result->fetch_assoc()) {
    $totalUsers = (int)$row['cnt'];
}

$totalVendors = 0;
$result = $conn->query("SELECT COUNT(*) as cnt FROM vendors WHERE status = 'active'");
if ($result && $row = $result->fetch_assoc()) {
    $totalVendors = (int)$row['cnt'];
}

// ------------------------------------------------------------
// 8. PAGE TITLE
// ------------------------------------------------------------
$page_title = 'Settings - Bless Mall Admin Panel';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0B6E3D">
    
    <title><?php echo $page_title; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --admin-bg: #0F172A;
            --admin-bg-light: #1E293B;
            --admin-surface: #1E293B;
            --admin-surface-hover: #273548;
            --admin-border: #334155;
            --admin-border-light: #475569;
            --admin-primary: #3B82F6;
            --admin-primary-dark: #2563EB;
            --admin-primary-light: #60A5FA;
            --admin-primary-glow: rgba(59, 130, 246, 0.15);
            --admin-success: #10B981;
            --admin-success-bg: rgba(16, 185, 129, 0.1);
            --admin-warning: #F59E0B;
            --admin-warning-bg: rgba(245, 158, 11, 0.1);
            --admin-danger: #EF4444;
            --admin-danger-bg: rgba(239, 68, 68, 0.1);
            --admin-info: #06B6D4;
            --text-primary: #F1F5F9;
            --text-secondary: #94A3B8;
            --text-muted: #64748B;
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.5);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-full: 9999px;
            --transition-fast: 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --font-heading: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
            --header-height: 64px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-body);
            background: var(--admin-bg);
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--admin-bg); }
        ::-webkit-scrollbar-thumb { background: var(--admin-border-light); border-radius: 3px; }

        /* Settings Layout */
        .settings-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
        }
        .page-header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .page-header h1 {
            font-family: var(--font-heading);
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-header h1 i {
            color: var(--admin-primary);
            font-size: 1.5rem;
        }
        .page-header p {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .breadcrumb a {
            color: var(--admin-primary);
            text-decoration: none;
            transition: var(--transition-fast);
        }
        .breadcrumb a:hover { color: var(--admin-primary-light); }
        .breadcrumb .separator { color: var(--text-muted); font-size: 0.6rem; }
        .breadcrumb .current { color: var(--text-primary); font-weight: 500; }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.875rem;
            animation: slideDown 0.3s ease;
        }
        .alert-success {
            background: var(--admin-success-bg);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--admin-success);
        }
        .alert-error {
            background: var(--admin-danger-bg);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--admin-danger);
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Settings Layout Grid */
        .settings-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        /* Settings Sidebar */
        .settings-sidebar {
            background: var(--admin-bg-light);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            position: sticky;
            top: calc(var(--header-height) + 1.5rem);
        }
        .sidebar-section-title {
            padding: 0.75rem 1rem;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .settings-nav {
            display: flex;
            flex-direction: column;
        }
        .settings-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.7rem 1rem;
            text-decoration: none;
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition-fast);
            border-left: 3px solid transparent;
        }
        .settings-nav a:hover {
            background: var(--admin-surface-hover);
            color: var(--text-primary);
        }
        .settings-nav a.active {
            background: var(--admin-primary-glow);
            color: var(--admin-primary);
            border-left-color: var(--admin-primary);
            font-weight: 600;
        }
        .settings-nav a i {
            width: 20px;
            text-align: center;
            font-size: 0.9rem;
        }
        .settings-nav a .badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: var(--admin-primary-glow);
            color: var(--admin-primary);
            padding: 2px 8px;
            border-radius: var(--radius-full);
        }

        /* Settings Content */
        .settings-content {
            background: var(--admin-bg-light);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .content-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .content-header h2 {
            font-family: var(--font-heading);
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .content-header h2 i {
            color: var(--admin-primary);
        }
        .content-body {
            padding: 1.5rem;
        }

        /* Form Styles */
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--admin-border);
        }
        .form-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        .form-section-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-section-title i {
            color: var(--admin-primary);
            font-size: 1rem;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.25rem;
        }
        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.25rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .form-group label .required {
            color: var(--admin-danger);
        }
        .form-group label i {
            color: var(--text-muted);
            font-size: 0.7rem;
        }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="number"],
        .form-group input[type="password"],
        .form-group input[type="url"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.65rem 0.85rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: var(--font-body);
            font-size: 0.85rem;
            transition: var(--transition-fast);
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
        }
        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: var(--text-muted);
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
            font-family: var(--font-mono);
            font-size: 0.8rem;
        }
        .form-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748B' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2rem;
        }
        .form-group .help-text {
            font-size: 0.7rem;
            color: var(--text-muted);
            line-height: 1.4;
        }
        .form-group .help-text code {
            background: rgba(59, 130, 246, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: var(--font-mono);
            font-size: 0.7rem;
            color: var(--admin-primary-light);
        }

        /* Toggle Switch */
        .toggle-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--admin-border);
            transition: var(--transition-fast);
            border-radius: var(--radius-full);
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            transition: var(--transition-fast);
            border-radius: 50%;
        }
        .toggle-switch input:checked + .toggle-slider {
            background: var(--admin-primary);
        }
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }
        .toggle-label {
            font-size: 0.85rem;
            color: var(--text-primary);
            font-weight: 500;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.7rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-family: var(--font-body);
            text-decoration: none;
        }
        .btn-primary {
            background: var(--admin-primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--admin-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--admin-border-light);
            color: var(--text-secondary);
        }
        .btn-outline:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
            background: var(--admin-primary-glow);
        }
        .btn-danger {
            background: var(--admin-danger);
            color: white;
        }
        .btn-danger:hover {
            background: #DC2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        .btn-sm {
            padding: 0.4rem 0.85rem;
            font-size: 0.75rem;
        }
        .form-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--admin-border);
            margin-top: 1.5rem;
        }

        /* Info Cards */
        .info-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .info-card {
            background: var(--admin-bg-light);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .info-card-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .info-card-icon.blue { background: var(--admin-primary-glow); color: var(--admin-primary); }
        .info-card-icon.green { background: var(--admin-success-bg); color: var(--admin-success); }
        .info-card-icon.amber { background: var(--admin-warning-bg); color: var(--admin-warning); }
        .info-card-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-card-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* Toast */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .toast {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            color: var(--text-primary);
            padding: 12px 20px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            animation: slideInRight 0.3s ease;
        }
        .toast.success { border-left: 3px solid var(--admin-success); }
        @keyframes slideInRight {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .settings-layout {
                grid-template-columns: 1fr;
            }
            .settings-sidebar {
                position: static;
            }
            .settings-nav {
                display: flex;
                flex-direction: row;
                flex-wrap: wrap;
                gap: 0.25rem;
                padding: 0.75rem;
            }
            .settings-nav a {
                border-left: none;
                border-bottom: 2px solid transparent;
                border-radius: var(--radius-sm);
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
            }
            .settings-nav a.active {
                border-left: none;
                border-bottom-color: var(--admin-primary);
                border-radius: var(--radius-sm) var(--radius-sm) 0 0;
            }
        }
        @media (max-width: 768px) {
            .settings-container { padding: 1rem; }
            .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
            .page-header-content { flex-direction: column; }
            .form-actions { flex-direction: column; }
            .btn { justify-content: center; }
            .info-cards { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .info-cards { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Admin Header -->
    <?php include __DIR__ . '/header.php'; ?>

    <!-- Main Content -->
    <div class="admin-main">
        <div class="settings-container">
            
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <span class="separator">/</span>
                <span class="current">Settings</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <div>
                        <h1><i class="fas fa-cog"></i> System Settings</h1>
                        <p>Manage all system configurations and preferences</p>
                    </div>
                    <div style="display: flex; gap: 0.75rem;">
                        <a href="<?php echo BASE_URL; ?>" target="_blank" class="btn btn-outline btn-sm">
                            <i class="fas fa-external-link-alt"></i> View Store
                        </a>
                    </div>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if ($successMessage): ?>
            <div class="alert alert-success" id="alertMessage">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $successMessage; ?></span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; opacity: 0.7;">&times;</button>
            </div>
            <?php endif; ?>
            
            <?php if ($errorMessage): ?>
            <div class="alert alert-error" id="alertMessage">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $errorMessage; ?></span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; opacity: 0.7;">&times;</button>
            </div>
            <?php endif; ?>

            <!-- Quick Info Cards -->
            <div class="info-cards">
                <div class="info-card">
                    <div class="info-card-icon blue"><i class="fas fa-box-open"></i></div>
                    <div>
                        <div class="info-card-label">Products</div>
                        <div class="info-card-value"><?php echo number_format($totalProducts); ?></div>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-card-icon green"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="info-card-label">Users</div>
                        <div class="info-card-value"><?php echo number_format($totalUsers); ?></div>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-card-icon amber"><i class="fas fa-clock"></i></div>
                    <div>
                        <div class="info-card-label">Pending Orders</div>
                        <div class="info-card-value"><?php echo number_format($pendingOrders); ?></div>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-card-icon blue"><i class="fas fa-store"></i></div>
                    <div>
                        <div class="info-card-label">Vendors</div>
                        <div class="info-card-value"><?php echo number_format($totalVendors); ?></div>
                    </div>
                </div>
            </div>

            <!-- Settings Layout -->
            <div class="settings-layout">
                
                <!-- Settings Sidebar Navigation -->
                <aside class="settings-sidebar">
                    <div class="sidebar-section-title">Configuration</div>
                    <nav class="settings-nav">
                        <a href="?tab=general" class="<?php echo $activeTab === 'general' ? 'active' : ''; ?>">
                            <i class="fas fa-building"></i> General
                        </a>
                        <a href="?tab=payment" class="<?php echo $activeTab === 'payment' ? 'active' : ''; ?>">
                            <i class="fas fa-credit-card"></i> Payment Methods
                        </a>
                        <a href="?tab=delivery" class="<?php echo $activeTab === 'delivery' ? 'active' : ''; ?>">
                            <i class="fas fa-truck"></i> Delivery & Shipping
                        </a>
                        <a href="?tab=email" class="<?php echo $activeTab === 'email' ? 'active' : ''; ?>">
                            <i class="fas fa-envelope"></i> Email Templates
                        </a>
                        <a href="?tab=sms" class="<?php echo $activeTab === 'sms' ? 'active' : ''; ?>">
                            <i class="fas fa-sms"></i> SMS Notifications
                        </a>
                        <a href="?tab=store" class="<?php echo $activeTab === 'store' ? 'active' : ''; ?>">
                            <i class="fas fa-store-alt"></i> Store Config
                        </a>
                        <a href="?tab=tax" class="<?php echo $activeTab === 'tax' ? 'active' : ''; ?>">
                            <i class="fas fa-calculator"></i> Tax Settings
                        </a>
                        <a href="?tab=advanced" class="<?php echo $activeTab === 'advanced' ? 'active' : ''; ?>">
                            <i class="fas fa-sliders-h"></i> Advanced
                        </a>
                    </nav>
                </aside>
                
                <!-- Settings Content Area -->
                <div class="settings-content">
                    
                    <!-- ========== GENERAL SETTINGS ========== -->
                    <?php if ($activeTab === 'general'): ?>
                    <div class="content-header">
                        <h2><i class="fas fa-building"></i> General Settings</h2>
                    </div>
                    <div class="content-body">
                        <form method="POST" action="?tab=general">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_general">
                            
                            <!-- Site Identity -->
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-globe"></i> Site Identity</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Site Name <span class="required">*</span></label>
                                        <input type="text" name="site_name" value="<?php echo htmlspecialchars(getSetting('site_name', 'Bless Mall')); ?>" required>
                                        <span class="help-text">The name of your online store</span>
                                    </div>
                                    <div class="form-group">
                                        <label>Tagline</label>
                                        <input type="text" name="site_tagline" value="<?php echo htmlspecialchars(getSetting('site_tagline', 'Quality Chinese Products in Ghana')); ?>">
                                        <span class="help-text">A short description of your store</span>
                                    </div>
                                    <div class="form-group full-width">
                                        <label>Site Description</label>
                                        <textarea name="site_description" placeholder="Brief description for SEO"><?php echo htmlspecialchars(getSetting('site_description')); ?></textarea>
                                        <span class="help-text">Used for meta description and SEO purposes</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contact Information -->
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-address-book"></i> Contact Information</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Admin Email <span class="required">*</span></label>
                                        <input type="email" name="admin_email" value="<?php echo htmlspecialchars(getSetting('admin_email', SITE_EMAIL ?? '')); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Contact Phone</label>
                                        <input type="text" name="contact_phone" value="<?php echo htmlspecialchars(getSetting('contact_phone', SITE_PHONE ?? '')); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>WhatsApp Number</label>
                                        <input type="text" name="contact_whatsapp" value="<?php echo htmlspecialchars(getSetting('contact_whatsapp', SITE_WHATSAPP ?? '')); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Physical Address</label>
                                        <input type="text" name="contact_address" value="<?php echo htmlspecialchars(getSetting('contact_address')); ?>" placeholder="Spintex Road, Accra, Ghana">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Regional Settings -->
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-globe-africa"></i> Regional Settings</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Currency</label>
                                        <select name="currency">
                                            <option value="GHS" <?php echo getSetting('currency', 'GHS') === 'GHS' ? 'selected' : ''; ?>>GHS - Ghanaian Cedi</option>
                                            <option value="USD" <?php echo getSetting('currency') === 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Currency Symbol</label>
                                        <input type="text" name="currency_symbol" value="<?php echo htmlspecialchars(getSetting('currency_symbol', '₵')); ?>" placeholder="₵">
                                    </div>
                                    <div class="form-group">
                                        <label>Timezone</label>
                                        <select name="timezone">
                                            <option value="Africa/Accra" <?php echo getSetting('timezone', 'Africa/Accra') === 'Africa/Accra' ? 'selected' : ''; ?>>Africa/Accra (GMT+0)</option>
                                            <option value="UTC" <?php echo getSetting('timezone') === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Date Format</label>
                                        <select name="date_format">
                                            <option value="d/m/Y" <?php echo getSetting('date_format', 'd/m/Y') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                            <option value="m/d/Y" <?php echo getSetting('date_format') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                            <option value="Y-m-d" <?php echo getSetting('date_format') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Products Per Page</label>
                                        <select name="products_per_page">
                                            <option value="12" <?php echo getSetting('products_per_page', '12') === '12' ? 'selected' : ''; ?>>12</option>
                                            <option value="24" <?php echo getSetting('products_per_page') === '24' ? 'selected' : ''; ?>>24</option>
                                            <option value="36" <?php echo getSetting('products_per_page') === '36' ? 'selected' : ''; ?>>36</option>
                                            <option value="48" <?php echo getSetting('products_per_page') === '48' ? 'selected' : ''; ?>>48</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Maintenance Mode -->
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-tools"></i> Maintenance Mode</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Enable Maintenance Mode</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="enable_maintenance_mode" value="1" <?php echo getSetting('enable_maintenance_mode', '0') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="toggle-label"><?php echo getSetting('enable_maintenance_mode', '0') === '1' ? 'Enabled' : 'Disabled'; ?></span>
                                        </div>
                                        <span class="help-text">When enabled, only admins can access the site</span>
                                    </div>
                                    <div class="form-group">
                                        <label>Maintenance Message</label>
                                        <textarea name="maintenance_message" placeholder="We'll be back shortly..."><?php echo htmlspecialchars(getSetting('maintenance_message')); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save General Settings
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ========== PAYMENT SETTINGS ========== -->
                    <?php if ($activeTab === 'payment'): ?>
                    <div class="content-header">
                        <h2><i class="fas fa-credit-card"></i> Payment Methods</h2>
                    </div>
                    <div class="content-body">
                        <form method="POST" action="?tab=payment">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_payment">
                            
                            <!-- Mobile Money -->
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-mobile-alt"></i> Mobile Money (MoMo)</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Enable Mobile Money</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="payment_momo_enabled" value="1" <?php echo getSetting('payment_momo_enabled', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Merchant ID</label>
                                        <input type="text" name="payment_momo_merchant_id" value="<?php echo htmlspecialchars(getSetting('payment_momo_merchant_id')); ?>" placeholder="Enter your MoMo merchant ID">
                                    </div>
                                    <div class="form-group">
                                        <label>API Key</label>
                                        <input type="password" name="payment_momo_api_key" value="<?php echo htmlspecialchars(getSetting('payment_momo_api_key')); ?>" placeholder="••••••••">
                                    </div>
                                    <div class="form-group">
                                        <label>Network</label>
                                        <select name="payment_momo_network">
                                            <option value="mtn" <?php echo getSetting('payment_momo_network', 'mtn') === 'mtn' ? 'selected' : ''; ?>>MTN Mobile Money</option>
                                            <option value="vodafone" <?php echo getSetting('payment_momo_network') === 'vodafone' ? 'selected' : ''; ?>>Vodafone Cash</option>
                                            <option value="airteltigo" <?php echo getSetting('payment_momo_network') === 'airteltigo' ? 'selected' : ''; ?>>AirtelTigo Money</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Card Payments -->
                            <div class="form-section">
                                <div class="form-section-title"><i class="far fa-credit-card"></i> Card Payments</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Enable Card Payments</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="payment_card_enabled" value="1" <?php echo getSetting('payment_card_enabled', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Processor</label>
                                        <select name="payment_card_processor">
                                            <option value="">Select Processor</option>
                                            <option value="paystack" <?php echo getSetting('payment_card_processor') === 'paystack' ? 'selected' : ''; ?>>Paystack</option>
                                            <option value="flutterwave" <?php echo getSetting('payment_card_processor') === 'flutterwave' ? 'selected' : ''; ?>>Flutterwave</option>
                                            <option value="stripe" <?php echo getSetting('payment_card_processor') === 'stripe' ? 'selected' : ''; ?>>Stripe</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Public Key</label>
                                        <input type="text" name="payment_card_public_key" value="<?php echo htmlspecialchars(getSetting('payment_card_public_key')); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Secret Key</label>
                                        <input type="password" name="payment_card_secret_key" value="<?php echo htmlspecialchars(getSetting('payment_card_secret_key')); ?>" placeholder="••••••••">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Cash on Delivery -->
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-money-bill-wave"></i> Cash on Delivery</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Enable Cash on Delivery</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="payment_cod_enabled" value="1" <?php echo getSetting('payment_cod_enabled', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>COD Fee (GHS)</label>
                                        <input type="number" name="payment_cod_fee" value="<?php echo htmlspecialchars(getSetting('payment_cod_fee', '0')); ?>" step="0.01" min="0">
                                        <span class="help-text">Additional fee for cash on delivery orders</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Bank Transfer -->
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-university"></i> Bank Transfer</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Enable Bank Transfer</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="payment_bank_transfer_enabled" value="1" <?php echo getSetting('payment_bank_transfer_enabled', '0') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group full-width">
                                        <label>Bank Account Details</label>
                                        <textarea name="payment_bank_details" placeholder="Bank: GCBBank&#10;Account Name: Bless Mall Ltd&#10;Account Number: 1234567890&#10;Branch: Spintex"><?php echo htmlspecialchars(getSetting('payment_bank_details')); ?></textarea>
                                        <span class="help-text">Displayed to customers during checkout</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Payment Settings
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ========== DELIVERY SETTINGS ========== -->
                    <?php if ($activeTab === 'delivery'): ?>
                    <div class="content-header">
                        <h2><i class="fas fa-truck"></i> Delivery & Shipping</h2>
                    </div>
                    <div class="content-body">
                        <form method="POST" action="?tab=delivery">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_delivery">
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-shipping-fast"></i> Standard Delivery</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Base Delivery Fee (GHS)</label>
                                        <input type="number" name="delivery_base_fee" value="<?php echo htmlspecialchars(getSetting('delivery_base_fee', '15')); ?>" step="0.01" min="0" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Free Delivery Threshold (GHS)</label>
                                        <input type="number" name="delivery_free_threshold" value="<?php echo htmlspecialchars(getSetting('delivery_free_threshold', '300')); ?>" step="0.01" min="0" required>
                                        <span class="help-text">Orders above this amount get free delivery</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-bolt"></i> Express Delivery</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Enable Express Delivery</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="delivery_express_enabled" value="1" <?php echo getSetting('delivery_express_enabled', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Express Fee (GHS)</label>
                                        <input type="number" name="delivery_express_fee" value="<?php echo htmlspecialchars(getSetting('delivery_express_fee', '25')); ?>" step="0.01" min="0">
                                    </div>
                                    <div class="form-group">
                                        <label>Express Delivery Time</label>
                                        <input type="text" name="delivery_express_time" value="<?php echo htmlspecialchars(getSetting('delivery_express_time', '24-48 hours')); ?>" placeholder="24-48 hours">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-store"></i> Pickup Options</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Enable Store Pickup</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="delivery_pickup_enabled" value="1" <?php echo getSetting('delivery_pickup_enabled', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group full-width">
                                        <label>Pickup Address</label>
                                        <textarea name="delivery_pickup_address" placeholder="Bless Mall Complex&#10;Spintex Road, Accra"><?php echo htmlspecialchars(getSetting('delivery_pickup_address')); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-truck-moving"></i> Delivery Partners</div>
                                <div class="form-grid">
                                    <div class="form-group full-width">
                                        <label>Available Partners</label>
                                        <textarea name="delivery_partners" placeholder="DHL Ghana&#10;FedEx Ghana&#10;Local Courier"><?php echo htmlspecialchars(getSetting('delivery_partners')); ?></textarea>
                                        <span class="help-text">One per line. These will appear as options for delivery</span>
                                    </div>
                                    <div class="form-group full-width">
                                        <label>Delivery Regions</label>
                                        <textarea name="delivery_regions" placeholder="Greater Accra&#10;Ashanti&#10;Western&#10;Eastern&#10;Central&#10;Volta&#10;Northern&#10;Upper East&#10;Upper West&#10;Bono&#10;Ahafo&#10;Bono East&#10;Oti&#10;North East&#10;Savannah&#10;Western North"><?php echo htmlspecialchars(getSetting('delivery_regions')); ?></textarea>
                                        <span class="help-text">Regions where delivery is available. One per line.</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Delivery Settings
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ========== EMAIL SETTINGS ========== -->
                    <?php if ($activeTab === 'email'): ?>
                    <div class="content-header">
                        <h2><i class="fas fa-envelope"></i> Email Settings</h2>
                    </div>
                    <div class="content-body">
                        <form method="POST" action="?tab=email">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_email">
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-cog"></i> SMTP Configuration</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>From Name</label>
                                        <input type="text" name="email_from_name" value="<?php echo htmlspecialchars(getSetting('email_from_name', 'Bless Mall')); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>From Email Address</label>
                                        <input type="email" name="email_from_address" value="<?php echo htmlspecialchars(getSetting('email_from_address')); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>SMTP Host</label>
                                        <input type="text" name="email_smtp_host" value="<?php echo htmlspecialchars(getSetting('email_smtp_host')); ?>" placeholder="smtp.gmail.com">
                                    </div>
                                    <div class="form-group">
                                        <label>SMTP Port</label>
                                        <input type="number" name="email_smtp_port" value="<?php echo htmlspecialchars(getSetting('email_smtp_port', '587')); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>SMTP Username</label>
                                        <input type="text" name="email_smtp_username" value="<?php echo htmlspecialchars(getSetting('email_smtp_username')); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>SMTP Password</label>
                                        <input type="password" name="email_smtp_password" value="<?php echo htmlspecialchars(getSetting('email_smtp_password')); ?>" placeholder="••••••••">
                                    </div>
                                    <div class="form-group">
                                        <label>Encryption</label>
                                        <select name="email_smtp_encryption">
                                            <option value="tls" <?php echo getSetting('email_smtp_encryption', 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                            <option value="ssl" <?php echo getSetting('email_smtp_encryption') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                            <option value="none" <?php echo getSetting('email_smtp_encryption') === 'none' ? 'selected' : ''; ?>>None</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-bell"></i> Email Notifications</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Order Confirmation</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="email_order_confirmation" value="1" <?php echo getSetting('email_order_confirmation', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="toggle-label">Send email when order is placed</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Shipping Updates</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="email_shipping_update" value="1" <?php echo getSetting('email_shipping_update', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="toggle-label">Send email on shipping status change</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Welcome Email</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="email_welcome_enabled" value="1" <?php echo getSetting('email_welcome_enabled', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="toggle-label">Send welcome email to new users</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Email Settings
                                </button>
                                <button type="button" class="btn btn-outline" onclick="sendTestEmail()">
                                    <i class="fas fa-paper-plane"></i> Send Test Email
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ========== SMS SETTINGS ========== -->
                    <?php if ($activeTab === 'sms'): ?>
                    <div class="content-header">
                        <h2><i class="fas fa-sms"></i> SMS Notifications</h2>
                    </div>
                    <div class="content-body">
                        <form method="POST" action="?tab=sms">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_sms">
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-cog"></i> SMS Provider</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Enable SMS</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="sms_enabled" value="1" <?php echo getSetting('sms_enabled', '0') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>SMS Provider</label>
                                        <select name="sms_provider">
                                            <option value="">Select Provider</option>
                                            <option value="hubtel" <?php echo getSetting('sms_provider') === 'hubtel' ? 'selected' : ''; ?>>Hubtel</option>
                                            <option value="twilio" <?php echo getSetting('sms_provider') === 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                                            <option value="arkesel" <?php echo getSetting('sms_provider') === 'arkesel' ? 'selected' : ''; ?>>Arkesel</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>API Key</label>
                                        <input type="text" name="sms_api_key" value="<?php echo htmlspecialchars(getSetting('sms_api_key')); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>API Secret</label>
                                        <input type="password" name="sms_api_secret" value="<?php echo htmlspecialchars(getSetting('sms_api_secret')); ?>" placeholder="••••••••">
                                    </div>
                                    <div class="form-group">
                                        <label>Sender ID</label>
                                        <input type="text" name="sms_sender_id" value="<?php echo htmlspecialchars(getSetting('sms_sender_id', 'BlessMall')); ?>" maxlength="11">
                                        <span class="help-text">Max 11 characters. Appears as the SMS sender</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-bell"></i> SMS Triggers</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Order Updates</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="sms_order_updates" value="1" <?php echo getSetting('sms_order_updates', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="toggle-label">Send SMS on order status changes</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Delivery Updates</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="sms_delivery_updates" value="1" <?php echo getSetting('sms_delivery_updates', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="toggle-label">Send SMS for delivery tracking updates</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save SMS Settings
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ========== STORE SETTINGS ========== -->
                    <?php if ($activeTab === 'store'): ?>
                    <div class="content-header">
                        <h2><i class="fas fa-store-alt"></i> Store Configuration</h2>
                    </div>
                    <div class="content-body">
                        <form method="POST" action="?tab=store">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_store">
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-sliders-h"></i> Order Settings</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Order Number Prefix</label>
                                        <input type="text" name="store_order_prefix" value="<?php echo htmlspecialchars(getSetting('store_order_prefix', 'BM')); ?>" required>
                                        <span class="help-text">Prefix for order numbers (e.g., <code>BM-2024-0001</code>)</span>
                                    </div>
                                    <div class="form-group">
                                        <label>Invoice Prefix</label>
                                        <input type="text" name="store_invoice_prefix" value="<?php echo htmlspecialchars(getSetting('store_invoice_prefix', 'INV')); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Minimum Order Amount (GHS)</label>
                                        <input type="number" name="store_min_order_amount" value="<?php echo htmlspecialchars(getSetting('store_min_order_amount', '0')); ?>" step="0.01" min="0">
                                    </div>
                                    <div class="form-group">
                                        <label>Maximum Order Amount (GHS)</label>
                                        <input type="number" name="store_max_order_amount" value="<?php echo htmlspecialchars(getSetting('store_max_order_amount', '0')); ?>" step="0.01" min="0">
                                        <span class="help-text">0 = no limit</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-handshake"></i> Vendor Settings</div>
                                <div class="form-grid-3">
                                    <?php if ($is_admin): ?>
                                    <div class="form-group">
                                        <label>Commission Rate (%)</label>
                                        <input type="number" name="store_commission_rate" value="<?php echo htmlspecialchars(getSetting('store_commission_rate', '0')); ?>" step="0.01" min="0" max="100">
                                        <span class="help-text">Commission charged to vendors per sale</span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="form-group">
                                        <label>Default Vendor</label>
                                        <select name="store_default_vendor">
                                            <option value="">None</option>
                                            <?php
                                            $vendorQuery = "SELECT id, business_name FROM vendors WHERE status = 'active' ORDER BY business_name ASC";
                                            $vendorRes = $conn->query($vendorQuery);
                                            if ($vendorRes && $vendorRes->num_rows > 0) {
                                                while ($v = $vendorRes->fetch_assoc()) {
                                                    $selected = getSetting('store_default_vendor') == $v['id'] ? 'selected' : '';
                                                    echo "<option value='{$v['id']}' $selected>" . htmlspecialchars($v['business_name']) . "</option>";
                                                }
                                            }
                                            ?>
                                        </select>
                                        <span class="help-text">Default vendor for admin-created products</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-certificate"></i> Business Information</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Tax Identification Number</label>
                                        <input type="text" name="store_tax_number" value="<?php echo htmlspecialchars(getSetting('store_tax_number')); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Business Registration Number</label>
                                        <input type="text" name="store_registration_number" value="<?php echo htmlspecialchars(getSetting('store_registration_number')); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Store Settings
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ========== TAX SETTINGS ========== -->
                    <?php if ($activeTab === 'tax'): ?>
                    <div class="content-header">
                        <h2><i class="fas fa-calculator"></i> Tax Settings</h2>
                    </div>
                    <div class="content-body">
                        <form method="POST" action="?tab=tax">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_tax">
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-percent"></i> Tax Configuration</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Enable Tax</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="tax_enabled" value="1" <?php echo getSetting('tax_enabled', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Tax Name</label>
                                        <input type="text" name="tax_name" value="<?php echo htmlspecialchars(getSetting('tax_name', 'VAT')); ?>" required>
                                        <span class="help-text">e.g., VAT, GST, Sales Tax</span>
                                    </div>
                                    <div class="form-group">
                                        <label>Tax Rate (%)</label>
                                        <input type="number" name="tax_rate" value="<?php echo htmlspecialchars(getSetting('tax_rate', '15')); ?>" step="0.01" min="0" max="100" required>
                                        <span class="help-text">Ghana VAT rate is typically 15%</span>
                                    </div>
                                    <div class="form-group">
                                        <label>Inclusive Pricing</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="tax_inclusive_pricing" value="1" <?php echo getSetting('tax_inclusive_pricing', '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="toggle-label">Prices include tax</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Price Display</label>
                                        <select name="tax_display_prices">
                                            <option value="inclusive" <?php echo getSetting('tax_display_prices', 'inclusive') === 'inclusive' ? 'selected' : ''; ?>>Tax Inclusive</option>
                                            <option value="exclusive" <?php echo getSetting('tax_display_prices') === 'exclusive' ? 'selected' : ''; ?>>Tax Exclusive</option>
                                            <option value="both" <?php echo getSetting('tax_display_prices') === 'both' ? 'selected' : ''; ?>>Show Both</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Tax Settings
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ========== ADVANCED SETTINGS ========== -->
                    <?php if ($activeTab === 'advanced'): ?>
                    <div class="content-header">
                        <h2><i class="fas fa-sliders-h"></i> Advanced Settings</h2>
                    </div>
                    <div class="content-body">
                        <form method="POST" action="?tab=advanced">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-bolt"></i> Performance</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Enable Caching</label>
                                        <div class="toggle-wrapper">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="advanced_cache_enabled" value="1" <?php echo getSetting('advanced_cache_enabled', '0') === '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Cache Duration (seconds)</label>
                                        <input type="number" name="advanced_cache_duration" value="<?php echo htmlspecialchars(getSetting('advanced_cache_duration', '3600')); ?>" min="60">
                                        <span class="help-text">3600 seconds = 1 hour</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-shield-alt"></i> Security</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Max Login Attempts</label>
                                        <input type="number" name="advanced_max_login_attempts" value="<?php echo htmlspecialchars(getSetting('advanced_max_login_attempts', '5')); ?>" min="1" max="20">
                                    </div>
                                    <div class="form-group">
                                        <label>Session Lifetime (seconds)</label>
                                        <input type="number" name="advanced_session_lifetime" value="<?php echo htmlspecialchars(getSetting('advanced_session_lifetime', '7200')); ?>" min="300">
                                        <span class="help-text">7200 seconds = 2 hours</span>
                                    </div>
                                    <div class="form-group">
                                        <label>Cookie Domain</label>
                                        <input type="text" name="advanced_cookie_domain" value="<?php echo htmlspecialchars(getSetting('advanced_cookie_domain')); ?>" placeholder=".blessmallgh.com">
                                        <span class="help-text">Leave empty for default</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title"><i class="fas fa-bug"></i> Logging</div>
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Log Level</label>
                                        <select name="advanced_log_level">
                                            <option value="error" <?php echo getSetting('advanced_log_level', 'error') === 'error' ? 'selected' : ''; ?>>Errors Only</option>
                                            <option value="warning" <?php echo getSetting('advanced_log_level') === 'warning' ? 'selected' : ''; ?>>Warnings & Errors</option>
                                            <option value="info" <?php echo getSetting('advanced_log_level') === 'info' ? 'selected' : ''; ?>>Info, Warnings & Errors</option>
                                            <option value="debug" <?php echo getSetting('advanced_log_level') === 'debug' ? 'selected' : ''; ?>>Debug (All)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="action" value="save_advanced" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Advanced Settings
                                </button>
                                
                                <button type="submit" name="action" value="clear_cache" class="btn btn-outline">
                                    <i class="fas fa-broom"></i> Clear Cache
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                </div><!-- /settings-content -->
                
            </div><!-- /settings-layout -->
            
        </div>
    </div>

    <!-- Admin Footer -->
    <?php include __DIR__ . '/footer.php'; ?>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        // Auto-hide alerts
        setTimeout(() => {
            const alert = document.getElementById('alertMessage');
            if (alert) {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }
        }, 5000);

        // Toggle switch label updates
        document.querySelectorAll('.toggle-switch input').forEach(toggle => {
            toggle.addEventListener('change', function() {
                const label = this.closest('.toggle-wrapper')?.querySelector('.toggle-label');
                if (label) {
                    label.textContent = this.checked ? 'Enabled' : 'Disabled';
                }
            });
        });

        // Toast notification
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            if (!container) return;
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease forwards';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Send test email
        function sendTestEmail() {
            const emailInput = document.querySelector('input[name="email_from_address"]');
            const email = emailInput?.value;
            
            if (!email) {
                showToast('Please enter a from email address first', 'error');
                return;
            }
            
            // Simulate test email (you can implement actual AJAX call)
            showToast(`Test email configuration saved. Send test to ${email}?`, 'success');
        }

        // Add slideOut animation
        const styleEl = document.createElement('style');
        styleEl.textContent = `
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(120%); opacity: 0; }
            }
        `;
        document.head.appendChild(styleEl);
    </script>
</body>
</html>