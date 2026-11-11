<?php
/**
 * config.php - Core Configuration & Database Connection
 * Bless Mall - Quality Chinese Products | Ghana
 *
 * Centralized configuration for the entire e-commerce platform.
 * All constants are guarded to prevent "already defined" warnings.
 */

// ==================== SENTINEL: Prevent multiple inclusions ====================
if (defined('BLESSMALL_CONFIG_INCLUDED')) {
    return; // Already loaded – constants, connection and functions are already available
}
define('BLESSMALL_CONFIG_INCLUDED', true);

$privateConfigPath = dirname(__DIR__, 2) . '/blessmall-private.php';
if (is_file($privateConfigPath)) {
    require_once $privateConfigPath;
}

// ==================== ERROR REPORTING ====================
error_reporting(E_ALL);
ini_set('display_errors', getenv('BLESSMALL_DISPLAY_ERRORS') === '1' ? '1' : '0');

// ==================== SESSION CONFIGURATION ====================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==================== DATABASE CONFIGURATION ====================
defined('DB_HOST') or define('DB_HOST', getenv('BLESSMALL_DB_HOST') ?: 'localhost');
defined('DB_USER') or define('DB_USER', getenv('BLESSMALL_DB_USER') ?: 'blessmal_wise');
defined('DB_PASS') or define('DB_PASS', getenv('BLESSMALL_DB_PASS') ?: 'Kaokbnia.4040@1957');
defined('DB_NAME') or define('DB_NAME', getenv('BLESSMALL_DB_NAME') ?: 'blessmal_db');
defined('PAYSTACK_PUBLIC_KEY') or define('PAYSTACK_PUBLIC_KEY', getenv('BLESSMALL_PAYSTACK_PUBLIC_KEY') ?: 'pk_test_b4b3b976e0ed036ab23e444fcb202b23d38f1411');
defined('PAYSTACK_SECRET_KEY') or define('PAYSTACK_SECRET_KEY', getenv('BLESSMALL_PAYSTACK_SECRET_KEY') ?: 'sk_test_93a367e490b75ecd3b9b685b3f2881de2011e265');
defined('SMS_API_KEY') or define('SMS_API_KEY', getenv('BLESSMALL_SMS_API_KEY') ?: 'RFZLcHFxc05CTHlOWXhXY0xlbGo');
defined('SMS_SENDER_ID') or define('SMS_SENDER_ID', getenv('BLESSMALL_SMS_SENDER_ID') ?: 'BLESSMALLGH');

// ==================== CREATE DATABASE CONNECTION ====================
if (!isset($conn) || !$conn instanceof mysqli) {
    if (DB_USER === '' || DB_PASS === '') {
        error_log('Bless Mall DB credentials are not configured.');
        die('Unable to connect to the database. Please try again later.');
    }

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        error_log("Bless Mall DB Connection Failed: " . $conn->connect_error);
        die("Unable to connect to the database. Please try again later.");
    }

    // Set proper charset
    $conn->set_charset("utf8mb4");
}

// ==================== TIMEZONE ====================
date_default_timezone_set('Africa/Accra');

// ==================== SITE IDENTITY ====================
defined('SITE_NAME')        or define('SITE_NAME', 'Bless Mall');
defined('SITE_TAGLINE')     or define('SITE_TAGLINE', 'Quality you can trust, Prices you will love');
defined('SITE_FULL_TAGLINE') or define('SITE_FULL_TAGLINE', 'Quality Chinese Products at Affordable Prices in Ghana');
defined('SITE_EMAIL')       or define('SITE_EMAIL', 'info@blessmallgh.com');
defined('SITE_PHONE')       or define('SITE_PHONE', '+233 20 289 7810');
defined('SITE_WHATSAPP')    or define('SITE_WHATSAPP', '+233 20 289 7810');

// ==================== URL & PATH CONSTANTS ====================
defined('BASE_URL')  or define('BASE_URL', 'https://blessmallgh.com/');
defined('BASE_PATH') or define('BASE_PATH', dirname(__DIR__) . '/');

defined('ASSETS_URL')  or define('ASSETS_URL', BASE_URL . 'assets/');
defined('CSS_URL')     or define('CSS_URL', ASSETS_URL . 'css/');
defined('JS_URL')      or define('JS_URL', ASSETS_URL . 'js/');
defined('IMAGES_URL')  or define('IMAGES_URL', ASSETS_URL . 'images/');

defined('UPLOADS_URL')         or define('UPLOADS_URL', BASE_URL . 'uploads/');
defined('PRODUCT_IMAGES_URL')  or define('PRODUCT_IMAGES_URL', UPLOADS_URL . 'products/');

defined('UPLOAD_PATH')         or define('UPLOAD_PATH', BASE_PATH . 'uploads/');
defined('PRODUCT_IMAGES_PATH') or define('PRODUCT_IMAGES_PATH', UPLOAD_PATH . 'products/');

// ==================== BRANDING COLORS ====================
defined('PRIMARY_GREEN') or define('PRIMARY_GREEN', '#0B6E3D');
defined('LIGHT_GREEN')   or define('LIGHT_GREEN', '#1FAF5B');
defined('GOLD_ACCENT')   or define('GOLD_ACCENT', '#D4AF37');
defined('DARK_TEXT')     or define('DARK_TEXT', '#1A1A1A');

// ==================== CURRENCY & FORMATTING ====================
defined('CURRENCY_SYMBOL') or define('CURRENCY_SYMBOL', '₵');
defined('CURRENCY_CODE')   or define('CURRENCY_CODE', 'GHS');
defined('DECIMAL_PLACES')  or define('DECIMAL_PLACES', 2);

// ==================== PAGINATION ====================
defined('PRODUCTS_PER_PAGE')       or define('PRODUCTS_PER_PAGE', 12);
defined('ADMIN_PRODUCTS_PER_PAGE') or define('ADMIN_PRODUCTS_PER_PAGE', 20);
defined('ADMIN_ORDERS_PER_PAGE')   or define('ADMIN_ORDERS_PER_PAGE', 15);

// ==================== SHIPPING & DELIVERY ====================
defined('DELIVERY_FEE_BASE')       or define('DELIVERY_FEE_BASE', 40.00);
defined('DELIVERY_EXPRESS_SURCHARGE') or define('DELIVERY_EXPRESS_SURCHARGE', 30.00);
defined('DELIVERY_ZONE_SURCHARGES') or define('DELIVERY_ZONE_SURCHARGES', [
    'legon_campus' => 0.00,
    'legon_adjacent' => 10.00,
    'accra_central' => 20.00,
    'accra_suburbs' => 30.00,
    'accra_outskirts' => 50.00,
]);
defined('FREE_DELIVERY_THRESHOLD') or define('FREE_DELIVERY_THRESHOLD', 300.00);

// ==================== IMAGE UPLOAD SETTINGS ====================
defined('MAX_IMAGE_SIZE')     or define('MAX_IMAGE_SIZE', 5242880); // 5MB
defined('ALLOWED_IMAGE_TYPES') or define('ALLOWED_IMAGE_TYPES', serialize(['image/jpeg', 'image/png', 'image/webp']));
defined('PRODUCT_IMAGE_WIDTH')  or define('PRODUCT_IMAGE_WIDTH', 800);
defined('PRODUCT_IMAGE_HEIGHT') or define('PRODUCT_IMAGE_HEIGHT', 800);
defined('THUMBNAIL_WIDTH')      or define('THUMBNAIL_WIDTH', 300);
defined('THUMBNAIL_HEIGHT')     or define('THUMBNAIL_HEIGHT', 300);

// ==================== AUTHENTICATION CONSTANTS ====================
defined('PASSWORD_COST')         or define('PASSWORD_COST', 12);
defined('MAX_LOGIN_ATTEMPTS')    or define('MAX_LOGIN_ATTEMPTS', 5);
defined('LOGIN_LOCKOUT_MINUTES') or define('LOGIN_LOCKOUT_MINUTES', 15);
defined('REMEMBER_ME_DAYS')      or define('REMEMBER_ME_DAYS', 30);

// ==================== BUSINESS CONSTANTS ====================
defined('EXPRESS_VENDOR_ID')   or define('EXPRESS_VENDOR_ID', 1);
defined('EXPRESS_VENDOR_NAME') or define('EXPRESS_VENDOR_NAME', 'Bless Mall Express');

// ==================== USER ROLES ====================
defined('ROLE_ADMIN')  or define('ROLE_ADMIN', 1);
defined('ROLE_SELLER') or define('ROLE_SELLER', 2);
defined('ROLE_BUYER')  or define('ROLE_BUYER', 3);

// ==================== DELIVERY STATUS ====================
defined('DELIVERY_AVAILABLE') or define('DELIVERY_AVAILABLE', 'available');
defined('DELIVERY_BUSY')      or define('DELIVERY_BUSY', 'busy');
defined('DELIVERY_OFFLINE')   or define('DELIVERY_OFFLINE', 'offline');

// ==================== ORDER & PAYMENT STATUS ARRAYS ====================
if (!isset($order_statuses)) {
    $order_statuses = [
        'pending'     => 'Pending',
        'confirmed'   => 'Confirmed',
        'processing'  => 'Processing',
        'packed'      => 'Packed',
        'shipped'     => 'Shipped',
        'in_transit'  => 'In Transit',
        'delivered'   => 'Delivered',
        'cancelled'   => 'Cancelled',
        'refunded'    => 'Refunded'
    ];
}

if (!isset($payment_statuses)) {
    $payment_statuses = [
        'unpaid'         => 'Unpaid',
        'paid'           => 'Paid',
        'partially_paid' => 'Partially Paid',
        'refunded'       => 'Refunded'
    ];
}

if (!isset($delivery_statuses)) {
    $delivery_statuses = [
        'pending'    => 'Awaiting Assignment',
        'assigned'   => 'Rider Assigned',
        'in_transit' => 'On the Way',
        'delivered'  => 'Delivered',
        'failed'     => 'Delivery Failed'
    ];
}

// Payment Methods
if (!isset($payment_methods)) {
    $payment_methods = [
        'momo'             => 'Mobile Money (MTN, Vodafone, AirtelTigo)',
        'card'             => 'Credit/Debit Card',
        'cash_on_delivery' => 'Cash on Delivery',
        'bank_transfer'    => 'Bank Transfer'
    ];
}

// ==================== HELPER FUNCTIONS ====================
if (!function_exists('getConnection')) {
    function getConnection() {
        global $conn;
        return $conn;
    }
}

if (!function_exists('cleanInput')) {
    function cleanInput($data) {
        global $conn;
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $conn->real_escape_string($data);
    }
}

if (!function_exists('formatPrice')) {
    function formatPrice($amount) {
        return CURRENCY_SYMBOL . number_format($amount, DECIMAL_PLACES);
    }
}

// ==================== SHUTDOWN ====================
if (!function_exists('closeConnection')) {
    function closeConnection() {
        global $conn;
        if ($conn) $conn->close();
    }
    register_shutdown_function('closeConnection');
}