<?php
/**
 * account.php – Bless Mall Buyer Dashboard v2.0 (Premium)
 * Central hub for customer account management
 * 
 * Features:
 * - Premium UI with glassmorphism effects
 * - Order management with direct links to orders.php
 * - Address management with modal forms
 * - Wishlist management
 * - Profile and security settings
 * - Responsive design
 * - Toast notifications
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP
// ------------------------------------------------------------
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// ------------------------------------------------------------
// 2. CHECK LOGIN
// ------------------------------------------------------------
if (!Auth::isLoggedIn()) {
    $_SESSION['redirect_after_login'] = 'account.php';
    header('Location: login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];

// ------------------------------------------------------------
// 3. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 4. ACTIVE TAB
// ------------------------------------------------------------
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$allowedTabs = ['overview', 'orders', 'addresses', 'wishlist', 'profile', 'security'];
if (!in_array($activeTab, $allowedTabs)) {
    $activeTab = 'overview';
}

// ------------------------------------------------------------
// 5. USER DATA
// ------------------------------------------------------------
$userQuery = "SELECT 
                id, name, email, phone, account_type, is_guest, 
                is_active, last_login, email_verified_at, created_at,
                (SELECT COUNT(*) FROM orders WHERE user_id = " . $userId . ") AS total_orders,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = " . $userId . " AND order_status != 'cancelled') AS total_spent,
                (SELECT COUNT(*) FROM wishlist WHERE user_id = " . $userId . ") AS wishlist_count,
                (SELECT COUNT(*) FROM addresses WHERE user_id = " . $userId . ") AS addresses_count
              FROM users 
              WHERE id = " . $userId . " AND deleted_at IS NULL";
$userResult = $conn->query($userQuery);
$user = $userResult->fetch_assoc();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// ------------------------------------------------------------
// 6. RECENT ORDERS
// ------------------------------------------------------------
$recentOrders = [];
$recentQuery = "SELECT 
                    id, order_number, total_amount, order_status, payment_status, created_at,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = orders.id) AS item_count
                FROM orders 
                WHERE user_id = " . $userId . "
                ORDER BY created_at DESC 
                LIMIT 5";
$recentResult = $conn->query($recentQuery);
if ($recentResult && $recentResult->num_rows > 0) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentOrders[] = $row;
    }
}

// ------------------------------------------------------------
// 7. ADDRESSES
// ------------------------------------------------------------
$addresses = [];
$addressQuery = "SELECT * FROM addresses WHERE user_id = " . $userId . " ORDER BY is_default DESC, created_at DESC";
$addressResult = $conn->query($addressQuery);
if ($addressResult && $addressResult->num_rows > 0) {
    while ($row = $addressResult->fetch_assoc()) {
        $addresses[] = $row;
    }
}

// ------------------------------------------------------------
// 8. WISHLIST ITEMS
// ------------------------------------------------------------
$wishlistItems = [];
$wishlistQuery = "SELECT 
                    w.id, w.created_at,
                    p.id AS product_id, p.name, p.slug,
                    (SELECT pi.image_path FROM product_images pi 
                     WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS image_path,
                    (SELECT unit_price FROM product_pricing_tiers 
                     WHERE product_id = p.id AND pricing_type = 'retail' AND is_active = 1 
                     LIMIT 1) AS price,
                    (SELECT discount_price FROM product_pricing_tiers 
                     WHERE product_id = p.id AND pricing_type = 'retail' AND is_active = 1 
                     LIMIT 1) AS discount_price,
                    v.business_name AS vendor_name,
                    COALESCE(AVG(r.rating), 0) AS avg_rating,
                    COUNT(r.id) AS review_count
                  FROM wishlist w
                  INNER JOIN products p ON w.product_id = p.id
                  LEFT JOIN vendors v ON p.vendor_id = v.id
                  LEFT JOIN reviews r ON p.id = r.product_id
                  WHERE w.user_id = " . $userId . " AND p.is_active = 1
                  GROUP BY w.id, p.id
                  ORDER BY w.created_at DESC
                  LIMIT 6";
$wishlistResult = $conn->query($wishlistQuery);
if ($wishlistResult && $wishlistResult->num_rows > 0) {
    while ($row = $wishlistResult->fetch_assoc()) {
        $wishlistItems[] = $row;
    }
}

// ------------------------------------------------------------
// 9. CART COUNT
// ------------------------------------------------------------
$cart_count = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += (int)($item['quantity'] ?? 0);
    }
}

// ------------------------------------------------------------
// 10. HELPER FUNCTIONS
// ------------------------------------------------------------
function getOrderStatusBadge($status) {
    $badges = [
        'pending' => 'badge-pending',
        'confirmed' => 'badge-confirmed',
        'processing' => 'badge-processing',
        'packed' => 'badge-packed',
        'shipped' => 'badge-shipped',
        'in_transit' => 'badge-shipped',
        'delivered' => 'badge-delivered',
        'cancelled' => 'badge-cancelled'
    ];
    $class = $badges[strtolower($status)] ?? 'badge-pending';
    return '<span class="status-badge ' . $class . '">' . ucfirst($status) . '</span>';
}

function formatDateShort($date) {
    return date('M d, Y', strtotime($date));
}

function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

function formatPrice($price) {
    return 'GH₵ ' . number_format((float)$price, 2);
}

function getImageUrl($imagePath) {
    if (empty($imagePath)) {
        return BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
    }
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        return $imagePath;
    }
    $imagePath = ltrim($imagePath, '/');
    $paths = [
        '/assets/uploads/products/' . $imagePath,
        '/uploads/products/' . $imagePath,
        '/' . $imagePath
    ];
    foreach ($paths as $path) {
        if (file_exists(__DIR__ . $path)) {
            return BASE_URL . $path;
        }
    }
    return BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
}

// ------------------------------------------------------------
// 11. FORM HANDLING
// ------------------------------------------------------------
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Update profile
    if ($action === 'update_profile' && isset($_POST['name'], $_POST['phone'])) {
        $name = $conn->real_escape_string(trim($_POST['name']));
        $phone = $conn->real_escape_string(trim($_POST['phone']));
        
        $updateQuery = "UPDATE users SET name = '$name', phone = '$phone' WHERE id = " . $userId;
        if ($conn->query($updateQuery)) {
            $successMessage = "Profile updated successfully!";
            $user['name'] = $name;
            $user['phone'] = $phone;
            $_SESSION['user_name'] = $name;
        } else {
            $errorMessage = "Failed to update profile. Please try again.";
        }
    }
    
    // Change password
    if ($action === 'change_password' && isset($_POST['current_password'], $_POST['new_password'], $_POST['confirm_password'])) {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        $passQuery = "SELECT password FROM users WHERE id = " . $userId;
        $passResult = $conn->query($passQuery);
        $userPass = $passResult->fetch_assoc();
        
        if (Auth::verifyPassword($currentPassword, $userPass['password'])) {
            if ($newPassword === $confirmPassword) {
                if (strlen($newPassword) >= 6) {
                    $hashedPassword = Auth::hashPassword($newPassword);
                    $updatePass = "UPDATE users SET password = '$hashedPassword' WHERE id = " . $userId;
                    if ($conn->query($updatePass)) {
                        $successMessage = "Password changed successfully!";
                    } else {
                        $errorMessage = "Failed to change password. Please try again.";
                    }
                } else {
                    $errorMessage = "New password must be at least 6 characters long.";
                }
            } else {
                $errorMessage = "New passwords do not match.";
            }
        } else {
            $errorMessage = "Current password is incorrect.";
        }
    }
    
    // Add new address
    if ($action === 'add_address' && isset($_POST['address_line1'], $_POST['region'], $_POST['city'], $_POST['phone'])) {
        $line1 = $conn->real_escape_string(trim($_POST['address_line1']));
        $line2 = $conn->real_escape_string(trim($_POST['address_line2'] ?? ''));
        $city = $conn->real_escape_string(trim($_POST['city']));
        $region = $conn->real_escape_string(trim($_POST['region']));
        $phone = $conn->real_escape_string(trim($_POST['phone']));
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        
        if ($isDefault) {
            $conn->query("UPDATE addresses SET is_default = 0 WHERE user_id = " . $userId);
        }
        
        $insert = "INSERT INTO addresses (user_id, address_line1, address_line2, city, region, phone, is_default)
                   VALUES ($userId, '$line1', '$line2', '$city', '$region', '$phone', $isDefault)";
        if ($conn->query($insert)) {
            $successMessage = "Address added successfully!";
            header("Location: account.php?tab=addresses&added=1");
            exit();
        } else {
            $errorMessage = "Failed to add address. Please try again.";
        }
    }
    
    // Delete address
    if ($action === 'delete_address' && isset($_POST['address_id'])) {
        $addressId = (int)$_POST['address_id'];
        $deleteQuery = "DELETE FROM addresses WHERE id = $addressId AND user_id = $userId";
        if ($conn->query($deleteQuery)) {
            $successMessage = "Address deleted successfully!";
            header("Location: account.php?tab=addresses&deleted=1");
            exit();
        } else {
            $errorMessage = "Failed to delete address.";
        }
    }
    
    // Set default address
    if ($action === 'set_default_address' && isset($_POST['address_id'])) {
        $addressId = (int)$_POST['address_id'];
        $conn->query("UPDATE addresses SET is_default = 0 WHERE user_id = $userId");
        $updateQuery = "UPDATE addresses SET is_default = 1 WHERE id = $addressId AND user_id = $userId";
        if ($conn->query($updateQuery)) {
            $successMessage = "Default address updated!";
            header("Location: account.php?tab=addresses&default=1");
            exit();
        } else {
            $errorMessage = "Failed to update default address.";
        }
    }
}

// ------------------------------------------------------------
// 12. PAGE TITLE
// ------------------------------------------------------------
$page_title = 'My Account — Bless Mall';
require_once __DIR__ . '/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="Manage your Bless Mall account - orders, addresses, wishlist and profile settings.">
    <meta name="robots" content="noindex, nofollow">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,900;1,400;1,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

<style>
/* ════════════════════════════════════════════════════════════════════
   BLESS MALL — ACCOUNT PAGE v2.0 Premium
════════════════════════════════════════════════════════════════════ */
:root {
    --primary: #0B6E3D;
    --primary-dark: #085229;
    --primary-light: #1FAF5B;
    --primary-glow: rgba(11,110,61,0.15);
    --gold: #C9A227;
    --gold-glow: rgba(201,162,39,0.2);
    --gold-dark: #A6861F;
    --dark: #0A1A12;
    --text: #1A1A1A;
    --text-secondary: #4A665C;
    --text-muted: #7C9A8E;
    --white: #FFFFFF;
    --off-white: #F8F7F4;
    --border: #E2EDE8;
    --red: #DC2626;
    --red-lt: #FEE2E2;
    --green-lt: #E6F5EE;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
    --shadow-md: 0 8px 30px rgba(0,0,0,0.06);
    --shadow-lg: 0 16px 48px rgba(0,0,0,0.08);
    --shadow-xl: 0 24px 64px rgba(0,0,0,0.12);
    --radius-sm: 8px;
    --radius: 16px;
    --radius-lg: 24px;
    --radius-full: 9999px;
    --transition: 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    --font-primary: 'Inter', -apple-system, sans-serif;
    --font-display: 'Playfair Display', Georgia, serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    background: var(--off-white);
    font-family: var(--font-primary);
    color: var(--text);
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

/* ─── TOP BAR ────────────────────────────────────────────────── */
.top-bar {
    background: var(--primary-dark);
    color: rgba(255,255,255,0.9);
    font-size: 0.75rem;
    padding: 6px 0;
    position: relative;
    z-index: 1001;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.top-bar-inner {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px;
}
.top-bar-left {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}
.top-bar-left span {
    display: flex;
    align-items: center;
    gap: 4px;
}
.top-bar-left i { color: var(--primary-light); }
.top-bar-right {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.top-bar-right a {
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    transition: color 0.2s;
    font-weight: 500;
}
.top-bar-right a:hover { color: var(--gold); }
.top-bar-right .express-link { color: var(--gold); }

@media (max-width: 768px) {
    .top-bar-left span:not(:first-child) { display: none; }
    .top-bar-right .express-link span { display: none; }
}

/* ─── PAGE HEADER ────────────────────────────────────────────── */
.account-hero {
    background: linear-gradient(165deg, #060E0A 0%, #0A1F15 30%, #0D2A1C 60%, #0A1F15 100%);
    padding: 2.5rem 0 2rem;
    position: relative;
    overflow: hidden;
    margin-bottom: 2rem;
}
.account-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: 
        radial-gradient(ellipse 70% 50% at 50% 100%, rgba(11,110,61,0.15) 0%, transparent 60%),
        radial-gradient(ellipse 30% 30% at 85% 10%, rgba(201,162,39,0.05) 0%, transparent 50%);
    pointer-events: none;
}
.account-hero .container {
    position: relative;
    z-index: 1;
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1.5rem;
}
.account-hero .breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: rgba(255,255,255,0.3);
    font-size: 0.75rem;
    margin-bottom: 0.75rem;
}
.account-hero .breadcrumb a {
    color: rgba(255,255,255,0.5);
    text-decoration: none;
    transition: var(--transition);
}
.account-hero .breadcrumb a:hover { color: var(--gold); }
.account-hero .breadcrumb .current { color: rgba(255,255,255,0.7); }

.account-hero h1 {
    font-family: var(--font-display);
    font-size: clamp(1.8rem, 4vw, 2.6rem);
    font-weight: 700;
    color: var(--white);
    line-height: 1.1;
    margin-bottom: 0.3rem;
}
.account-hero h1 .highlight {
    color: var(--gold);
    position: relative;
}
.account-hero h1 .highlight::after {
    content: '';
    position: absolute;
    bottom: 2px;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--gold);
    border-radius: 4px;
    opacity: 0.3;
}
.account-hero .hero-sub {
    color: rgba(255,255,255,0.4);
    font-size: 0.85rem;
}

/* ─── DASHBOARD LAYOUT ──────────────────────────────────────── */
.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1.5rem 4rem;
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 2rem;
}

/* ─── SIDEBAR ────────────────────────────────────────────────── */
.dashboard-sidebar {
    position: sticky;
    top: 90px;
    align-self: start;
}

.user-profile-card {
    background: var(--white);
    border-radius: var(--radius);
    padding: 1.5rem;
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.25rem;
    transition: var(--transition);
}
.user-profile-card:hover { box-shadow: var(--shadow-md); }
.user-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--gold) 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 0.75rem;
    color: white;
    font-size: 1.8rem;
    font-weight: 700;
    box-shadow: 0 4px 16px rgba(11,110,61,0.25);
}
.user-name {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--dark);
    margin-bottom: 0.1rem;
}
.user-email {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-bottom: 0.3rem;
    word-break: break-all;
}
.user-since {
    font-size: 0.65rem;
    color: var(--text-muted);
}
.account-type-badge {
    display: inline-block;
    background: var(--green-lt);
    color: var(--primary);
    font-size: 0.6rem;
    padding: 2px 10px;
    border-radius: var(--radius-full);
    margin-top: 0.4rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sidebar-nav {
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}
.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0.85rem 1.2rem;
    color: var(--text-secondary);
    text-decoration: none;
    transition: var(--transition);
    border-left: 3px solid transparent;
    font-size: 0.85rem;
    font-weight: 500;
    font-family: var(--font-primary);
}
.nav-item i { width: 20px; font-size: 0.95rem; }
.nav-item:hover {
    background: var(--off-white);
    color: var(--primary);
}
.nav-item.active {
    background: var(--green-lt);
    color: var(--primary);
    border-left-color: var(--primary);
    font-weight: 600;
}
.nav-item .badge-count {
    margin-left: auto;
    background: var(--primary);
    color: white;
    padding: 1px 8px;
    border-radius: var(--radius-full);
    font-size: 0.65rem;
    font-weight: 700;
}
.nav-logout {
    border-top: 1px solid var(--border);
    color: var(--red);
}
.nav-logout:hover {
    background: var(--red-lt);
    color: var(--red);
}

/* ─── MAIN CONTENT ───────────────────────────────────────────── */
.dashboard-content {
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.content-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    background: var(--off-white);
}
.content-header h2 {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.content-header h2 i { color: var(--primary); }
.content-body { padding: 1.5rem; }

/* ─── STATS CARDS ────────────────────────────────────────────── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
}
.stat-card {
    background: var(--off-white);
    border-radius: var(--radius-sm);
    padding: 1.25rem 1rem;
    text-align: center;
    border: 1px solid var(--border);
    transition: var(--transition);
}
.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--primary);
}
.stat-icon {
    width: 48px;
    height: 48px;
    background: var(--green-lt);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 0.6rem;
    color: var(--primary);
    font-size: 1.1rem;
    transition: var(--transition);
}
.stat-card:hover .stat-icon {
    background: var(--primary);
    color: white;
}
.stat-value {
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--primary);
    line-height: 1.2;
}
.stat-label {
    font-size: 0.65rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

/* ─── SECTION HEADER ─────────────────────────────────────────── */
.section-header-with-link {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.section-header-with-link h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.section-header-with-link h3 i { color: var(--primary); }
.view-all-link {
    color: var(--primary);
    text-decoration: none;
    font-size: 0.8rem;
    font-weight: 600;
    transition: var(--transition);
}
.view-all-link:hover {
    text-decoration: underline;
    color: var(--gold-dark);
}

/* ─── ORDERS TABLE ───────────────────────────────────────────── */
.orders-table-wrap {
    overflow-x: auto;
}
.orders-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.orders-table th {
    text-align: left;
    padding: 0.7rem 0.75rem;
    background: var(--off-white);
    color: var(--text-secondary);
    font-weight: 600;
    border-bottom: 1px solid var(--border);
}
.orders-table td {
    padding: 0.7rem 0.75rem;
    border-bottom: 1px solid var(--border);
    color: var(--text);
}
.orders-table tr:last-child td { border-bottom: none; }
.orders-table tr:hover td { background: var(--off-white); }

.status-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: var(--radius-full);
    font-size: 0.65rem;
    font-weight: 600;
}
.badge-pending { background: #fef3c7; color: #92400e; }
.badge-confirmed { background: #dbeafe; color: #1e40af; }
.badge-processing { background: #ede9fe; color: #5b21b6; }
.badge-packed { background: #fce7f3; color: #be185d; }
.badge-shipped { background: #cffafe; color: #0891b2; }
.badge-delivered { background: #d1fae5; color: #065f46; }
.badge-cancelled { background: #fee2e2; color: #991b1b; }

.view-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.75rem;
}
.view-link:hover { text-decoration: underline; }

/* ─── ADDRESSES ───────────────────────────────────────────────── */
.addresses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.25rem;
    margin-top: 1rem;
}
.address-card {
    background: var(--off-white);
    border-radius: var(--radius-sm);
    padding: 1.25rem;
    border: 1px solid var(--border);
    position: relative;
    transition: var(--transition);
}
.address-card:hover { box-shadow: var(--shadow-sm); }
.address-card.default {
    border-color: var(--primary);
    background: var(--green-lt);
}
.default-badge {
    position: absolute;
    top: -8px;
    right: 1rem;
    background: var(--primary);
    color: white;
    font-size: 0.6rem;
    padding: 1px 10px;
    border-radius: var(--radius-full);
    font-weight: 600;
}
.address-name {
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 0.3rem;
    font-size: 0.95rem;
}
.address-details {
    font-size: 0.78rem;
    color: var(--text-secondary);
    line-height: 1.6;
    margin-bottom: 0.75rem;
}
.address-details i { width: 16px; color: var(--primary); }
.address-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.address-action {
    font-size: 0.7rem;
    color: var(--primary);
    text-decoration: none;
    padding: 3px 8px;
    border-radius: var(--radius-xs);
    transition: var(--transition);
    background: none;
    border: none;
    cursor: pointer;
    font-family: var(--font-primary);
    font-weight: 500;
}
.address-action:hover {
    background: var(--green-lt);
    text-decoration: none;
}
.address-action.danger { color: var(--red); }
.address-action.danger:hover { background: var(--red-lt); }

/* ─── WISHLIST ───────────────────────────────────────────────── */
.wishlist-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1.25rem;
}
.wishlist-card {
    background: var(--off-white);
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--border);
    transition: var(--transition);
}
.wishlist-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--primary);
}
.wishlist-image {
    height: 160px;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.wishlist-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.wishlist-image .placeholder {
    font-size: 2.5rem;
    color: #ddd;
}
.wishlist-info { padding: 0.75rem 1rem; }
.wishlist-name {
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--dark);
    margin-bottom: 0.2rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-decoration: none;
}
.wishlist-name:hover { color: var(--primary); }
.wishlist-price {
    font-weight: 800;
    color: var(--primary);
    font-size: 0.95rem;
    margin-bottom: 0.5rem;
}
.wishlist-actions {
    display: flex;
    gap: 0.4rem;
}
.btn-sm {
    flex: 1;
    padding: 0.4rem 0.6rem;
    border-radius: var(--radius-sm);
    font-size: 0.65rem;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    transition: var(--transition);
    cursor: pointer;
    border: none;
    font-family: var(--font-primary);
}
.btn-cart {
    background: var(--primary);
    color: white;
}
.btn-cart:hover { background: var(--primary-dark); }
.btn-remove-sm {
    background: white;
    color: var(--red);
    border: 1px solid #fca5a5;
}
.btn-remove-sm:hover { background: var(--red-lt); }

/* ─── FORMS ───────────────────────────────────────────────────── */
.profile-form { max-width: 500px; }
.form-group { margin-bottom: 1.25rem; }
.form-group label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    margin-bottom: 0.3rem;
    color: var(--dark);
}
.form-group input, .form-group textarea, .form-group select {
    width: 100%;
    padding: 0.7rem 1rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: var(--font-primary);
    font-size: 0.85rem;
    transition: var(--transition);
}
.form-group input:focus, .form-group textarea:focus, .form-group select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.form-group input:disabled {
    background: var(--off-white);
    color: var(--text-muted);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.form-group .checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 400;
    font-size: 0.85rem;
    color: var(--text-secondary);
}
.form-group .checkbox-label input { width: auto; }

.btn-submit {
    padding: 0.75rem 1.8rem;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius-full);
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    font-family: var(--font-primary);
    font-size: 0.85rem;
}
.btn-submit:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 20px var(--primary-glow);
}
.btn-secondary {
    background: var(--text-muted);
}
.btn-secondary:hover {
    background: var(--text-secondary);
}

/* ─── ALERTS ──────────────────────────────────────────────────── */
.alert-success {
    background: #d1fae5;
    border: 1px solid #6ee7b7;
    color: #065f46;
    padding: 0.7rem 1rem;
    border-radius: var(--radius-sm);
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
    padding: 0.7rem 1rem;
    border-radius: var(--radius-sm);
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* ─── MODAL ───────────────────────────────────────────────────── */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: var(--white);
    border-radius: var(--radius);
    max-width: 520px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-xl);
    animation: modalIn 0.3s ease;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95) translateY(20px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.modal-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h3 {
    font-size: 1.05rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.modal-close {
    cursor: pointer;
    font-size: 1.5rem;
    color: var(--text-muted);
    background: none;
    border: none;
    transition: var(--transition);
}
.modal-close:hover { color: var(--red); }
.modal-body { padding: 1.5rem; }

/* ─── EMPTY STATE ────────────────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 2.5rem 1.5rem;
}
.empty-icon {
    font-size: 3rem;
    color: var(--text-muted);
    opacity: 0.3;
    margin-bottom: 0.75rem;
}
.empty-state h4 {
    font-size: 1rem;
    color: var(--dark);
    margin-bottom: 0.3rem;
}
.empty-state p {
    font-size: 0.82rem;
    color: var(--text-muted);
}

/* ─── TOAST ───────────────────────────────────────────────────── */
.toast-container {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    pointer-events: none;
    max-width: calc(100vw - 3rem);
}
.toast {
    background: var(--white);
    padding: 0.75rem 1rem;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-xl);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    font-size: 0.82rem;
    pointer-events: auto;
    animation: toastIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    border-left: 4px solid var(--primary);
}
.toast.error { border-left-color: var(--red); }
@keyframes toastIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* ─── RESPONSIVE ──────────────────────────────────────────────── */
@media (max-width: 1024px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 992px) {
    .dashboard-container {
        grid-template-columns: 1fr;
        gap: 1.5rem;
        padding: 0 1rem 3rem;
    }
    .dashboard-sidebar { position: static; }
    .sidebar-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
        padding: 0.75rem;
    }
    .nav-item {
        border-left: none;
        border-bottom: 2px solid transparent;
        padding: 0.5rem 0.8rem;
        font-size: 0.8rem;
        border-radius: var(--radius-sm);
    }
    .nav-item.active {
        border-left: none;
        border-bottom-color: var(--primary);
        background: var(--green-lt);
    }
    .nav-logout { border-top: none; }
}

@media (max-width: 768px) {
    .account-hero { padding: 1.5rem 0 1.25rem; }
    .account-hero .container { padding: 0 1rem; }
    .dashboard-container { padding: 0 0.75rem 2rem; }
    .content-header { padding: 1rem; }
    .content-body { padding: 1rem; }
    .stats-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .stat-card { padding: 0.9rem; }
    .stat-value { font-size: 1.1rem; }
    .stat-icon { width: 40px; height: 40px; font-size: 0.9rem; }
    .addresses-grid { grid-template-columns: 1fr; }
    .wishlist-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .wishlist-image { height: 120px; }
    .orders-table { font-size: 0.7rem; }
    .orders-table th, .orders-table td { padding: 0.4rem 0.5rem; }
    .form-row { grid-template-columns: 1fr; }
    .top-bar-left span:not(:first-child) { display: none; }
    .top-bar-right .express-link span { display: none; }
}

@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
    .wishlist-grid { grid-template-columns: 1fr; }
    .account-hero h1 { font-size: 1.5rem; }
}
</style>

<!-- ─── TOP BAR ────────────────────────────────────────────────── -->
<div class="top-bar">
    <div class="top-bar-inner">
        <div class="top-bar-left">
            <span><i class="fas fa-phone-alt"></i> <?php echo defined('SITE_PHONE') ? SITE_PHONE : '+233 20 289 7810'; ?></span>
            <span><i class="fab fa-whatsapp"></i> <?php echo defined('SITE_WHATSAPP') ? SITE_WHATSAPP : '+233 20 289 7810'; ?></span>
            <span><i class="fas fa-clock"></i> Mon-Fri 8AM-6PM</span>
        </div>
        <div class="top-bar-right">
            <a href="track-order.php"><i class="fas fa-box-tissue"></i> Track Order</a>
            <a href="express.php" class="express-link"><i class="fas fa-bolt"></i> <span>Express Delivery</span></a>
            <span><i class="fas fa-map-marker-alt"></i> 🇬🇭 Ghana</span>
        </div>
    </div>
</div>

<!-- ─── ACCOUNT HERO ────────────────────────────────────────────── -->
<section class="account-hero">
    <div class="container">
        <div class="breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i></a>
            <span>›</span>
            <span class="current">My Account</span>
        </div>
        <h1>
            Welcome back, <span class="highlight"><?php echo htmlspecialchars($user['name']); ?></span>
        </h1>
        <p class="hero-sub">Manage your orders, addresses, and account settings</p>
    </div>
</section>

<!-- ─── DASHBOARD ───────────────────────────────────────────────── -->
<div class="dashboard-container">
    
    <!-- ─── SIDEBAR ────────────────────────────────────────────── -->
    <aside class="dashboard-sidebar">
        <div class="user-profile-card">
            <div class="user-avatar"><?php echo getInitials($user['name']); ?></div>
            <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
            <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
            <div class="user-since">Member since <?php echo date('M Y', strtotime($user['created_at'])); ?></div>
            <div class="account-type-badge"><i class="fas fa-gem"></i> <?php echo ucfirst($user['account_type']); ?></div>
        </div>
        
        <nav class="sidebar-nav">
            <a href="account.php?tab=overview" class="nav-item <?php echo $activeTab === 'overview' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Overview
            </a>
            <a href="orders.php" class="nav-item <?php echo $activeTab === 'orders' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-bag"></i> My Orders
                <?php if ($user['total_orders'] > 0): ?>
                    <span class="badge-count"><?php echo $user['total_orders']; ?></span>
                <?php endif; ?>
            </a>
            <a href="account.php?tab=addresses" class="nav-item <?php echo $activeTab === 'addresses' ? 'active' : ''; ?>">
                <i class="fas fa-map-marker-alt"></i> Addresses
                <?php if ($user['addresses_count'] > 0): ?>
                    <span class="badge-count"><?php echo $user['addresses_count']; ?></span>
                <?php endif; ?>
            </a>
            <a href="account.php?tab=wishlist" class="nav-item <?php echo $activeTab === 'wishlist' ? 'active' : ''; ?>">
                <i class="fas fa-heart"></i> Wishlist
                <?php if ($user['wishlist_count'] > 0): ?>
                    <span class="badge-count"><?php echo $user['wishlist_count']; ?></span>
                <?php endif; ?>
            </a>
            <a href="account.php?tab=profile" class="nav-item <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">
                <i class="fas fa-user-edit"></i> Profile
            </a>
            <a href="account.php?tab=security" class="nav-item <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                <i class="fas fa-lock"></i> Security
            </a>
            <a href="logout.php" class="nav-item nav-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </aside>
    
    <!-- ─── MAIN CONTENT ───────────────────────────────────────── -->
    <div class="dashboard-content">
        
        <!-- ═══ OVERVIEW TAB ═══ -->
        <?php if ($activeTab === 'overview'): ?>
        <div class="content-header">
            <h2><i class="fas fa-tachometer-alt"></i> Dashboard Overview</h2>
        </div>
        <div class="content-body">
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
                    <div class="stat-value"><?php echo $user['total_orders']; ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-cedi-sign"></i></div>
                    <div class="stat-value"><?php echo formatPrice($user['total_spent']); ?></div>
                    <div class="stat-label">Total Spent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="stat-value"><?php echo $user['addresses_count']; ?></div>
                    <div class="stat-label">Saved Addresses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-heart"></i></div>
                    <div class="stat-value"><?php echo $user['wishlist_count']; ?></div>
                    <div class="stat-label">Wishlist Items</div>
                </div>
            </div>
            
            <!-- Recent Orders -->
            <div class="section-header-with-link">
                <h3><i class="fas fa-clock"></i> Recent Orders</h3>
                <?php if ($user['total_orders'] > 0): ?>
                    <a href="orders.php" class="view-all-link">View All Orders →</a>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($recentOrders)): ?>
            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><strong>#<?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                            <td><?php echo formatDateShort($order['created_at']); ?></td>
                            <td><?php echo $order['item_count']; ?></td>
                            <td><?php echo formatPrice($order['total_amount']); ?></td>
                            <td><?php echo getOrderStatusBadge($order['order_status']); ?></td>
                            <td><a href="orders.php?view=<?php echo $order['id']; ?>" class="view-link">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-shopping-bag"></i></div>
                <h4>No orders yet</h4>
                <p>Start shopping to see your orders here</p>
                <a href="shop.php" class="btn-submit" style="display:inline-block;margin-top:0.75rem;text-decoration:none;">Start Shopping →</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- ═══ ORDERS TAB ═══ -->
        <?php if ($activeTab === 'orders'): ?>
        <div class="content-header">
            <h2><i class="fas fa-shopping-bag"></i> My Orders</h2>
        </div>
        <div class="content-body">
            <?php 
            $allOrdersQuery = "SELECT id, order_number, total_amount, order_status, payment_status, created_at,
                               (SELECT COUNT(*) FROM order_items WHERE order_id = orders.id) AS item_count
                               FROM orders WHERE user_id = " . $userId . " 
                               ORDER BY created_at DESC";
            $allOrdersResult = $conn->query($allOrdersQuery);
            $allOrders = [];
            if ($allOrdersResult && $allOrdersResult->num_rows > 0) {
                while ($row = $allOrdersResult->fetch_assoc()) {
                    $allOrders[] = $row;
                }
            }
            ?>
            <?php if (!empty($allOrders)): ?>
            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allOrders as $order): ?>
                        <tr>
                            <td><strong>#<?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                            <td><?php echo formatDateShort($order['created_at']); ?></td>
                            <td><?php echo $order['item_count']; ?></td>
                            <td><?php echo formatPrice($order['total_amount']); ?></td>
                            <td><span class="status-badge <?php echo $order['payment_status'] === 'paid' ? 'badge-delivered' : 'badge-pending'; ?>"><?php echo ucfirst($order['payment_status']); ?></span></td>
                            <td><?php echo getOrderStatusBadge($order['order_status']); ?></td>
                            <td><a href="orders.php?view=<?php echo $order['id']; ?>" class="view-link">Details</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-shopping-bag"></i></div>
                <h4>No orders found</h4>
                <p>You haven't placed any orders yet</p>
                <a href="shop.php" class="btn-submit" style="display:inline-block;margin-top:0.75rem;text-decoration:none;">Start Shopping</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- ═══ ADDRESSES TAB ═══ -->
        <?php if ($activeTab === 'addresses'): ?>
        <div class="content-header">
            <h2><i class="fas fa-map-marker-alt"></i> Saved Addresses</h2>
        </div>
        <div class="content-body">
            <?php if (isset($_GET['added']) || isset($_GET['deleted']) || isset($_GET['default'])): ?>
                <div class="alert-success" id="tempAlert">
                    <i class="fas fa-check-circle"></i> 
                    <?php if (isset($_GET['added'])) echo "Address added successfully!"; ?>
                    <?php if (isset($_GET['deleted'])) echo "Address deleted successfully!"; ?>
                    <?php if (isset($_GET['default'])) echo "Default address updated!"; ?>
                </div>
                <script>
                    setTimeout(() => {
                        const alert = document.getElementById('tempAlert');
                        if (alert) alert.style.display = 'none';
                    }, 3000);
                </script>
            <?php endif; ?>
            
            <?php if ($errorMessage): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?></div>
            <?php endif; ?>
            
            <!-- Add Address Button -->
            <button class="btn-submit" id="showAddAddressBtn" style="margin-bottom:1.25rem;">
                <i class="fas fa-plus"></i> Add New Address
            </button>
            
            <!-- Add Address Modal -->
            <div class="modal-overlay" id="addressModal">
                <div class="modal-box">
                    <div class="modal-header">
                        <h3><i class="fas fa-map-marker-alt"></i> Add New Address</h3>
                        <button class="modal-close" onclick="closeAddressModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="account.php?tab=addresses" class="profile-form">
                            <input type="hidden" name="action" value="add_address">
                            <div class="form-group">
                                <label>Address Line 1 *</label>
                                <input type="text" name="address_line1" required placeholder="Street address, P.O. Box, etc.">
                            </div>
                            <div class="form-group">
                                <label>Address Line 2</label>
                                <input type="text" name="address_line2" placeholder="Apartment, suite, unit, etc. (optional)">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>City *</label>
                                    <input type="text" name="city" required placeholder="e.g., Accra">
                                </div>
                                <div class="form-group">
                                    <label>Region *</label>
                                    <input type="text" name="region" required placeholder="e.g., Greater Accra">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Phone Number *</label>
                                <input type="tel" name="phone" required placeholder="Phone number for delivery">
                            </div>
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_default" value="1"> Set as default address
                                </label>
                            </div>
                            <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
                                <button type="submit" class="btn-submit">Save Address</button>
                                <button type="button" class="btn-submit btn-secondary" onclick="closeAddressModal()">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($addresses)): ?>
            <div class="addresses-grid">
                <?php foreach ($addresses as $address): ?>
                <div class="address-card <?php echo $address['is_default'] ? 'default' : ''; ?>">
                    <?php if ($address['is_default']): ?>
                        <div class="default-badge"><i class="fas fa-check-circle"></i> Default</div>
                    <?php endif; ?>
                    <div class="address-name"><i class="fas fa-user"></i> <?php echo htmlspecialchars($user['name']); ?></div>
                    <div class="address-details">
                        <i class="fas fa-location-dot"></i> <?php echo nl2br(htmlspecialchars($address['address_line1'])); ?>
                        <?php if ($address['address_line2']): ?><br><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($address['address_line2']); ?><?php endif; ?>
                        <br><i class="fas fa-city"></i> <?php echo htmlspecialchars($address['city']); ?>, <?php echo htmlspecialchars($address['region']); ?>
                        <br><i class="fas fa-phone"></i> <?php echo htmlspecialchars($address['phone']); ?>
                    </div>
                    <div class="address-actions">
                        <?php if (!$address['is_default']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="set_default_address">
                                <input type="hidden" name="address_id" value="<?php echo $address['id']; ?>">
                                <button type="submit" class="address-action"><i class="fas fa-star"></i> Set Default</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this address?')">
                            <input type="hidden" name="action" value="delete_address">
                            <input type="hidden" name="address_id" value="<?php echo $address['id']; ?>">
                            <button type="submit" class="address-action danger"><i class="fas fa-trash"></i> Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-map-marker-alt"></i></div>
                <h4>No saved addresses</h4>
                <p>Add your first delivery address to make checkout faster</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- ═══ WISHLIST TAB ═══ -->
        <?php if ($activeTab === 'wishlist'): ?>
        <div class="content-header">
            <h2><i class="fas fa-heart"></i> My Wishlist</h2>
        </div>
        <div class="content-body">
            <?php if (!empty($wishlistItems)): ?>
            <div class="wishlist-grid">
                <?php foreach ($wishlistItems as $item): 
                    $price = $item['discount_price'] && $item['discount_price'] < $item['price'] ? $item['discount_price'] : $item['price'];
                    $imgUrl = getImageUrl($item['image_path']);
                ?>
                <div class="wishlist-card" id="wishlist-item-<?php echo $item['id']; ?>">
                    <a href="product-detail.php?slug=<?php echo $item['slug']; ?>">
                        <div class="wishlist-image">
                            <?php if ($imgUrl): ?>
                                <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <div class="placeholder" style="display:none;"><i class="fas fa-box-open"></i></div>
                            <?php else: ?>
                                <div class="placeholder"><i class="fas fa-box-open"></i></div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <div class="wishlist-info">
                        <a href="product-detail.php?slug=<?php echo $item['slug']; ?>" class="wishlist-name"><?php echo htmlspecialchars($item['name']); ?></a>
                        <div class="wishlist-price"><?php echo formatPrice($price); ?></div>
                        <div class="wishlist-actions">
                            <button onclick="addToCart(<?php echo $item['product_id']; ?>)" class="btn-sm btn-cart">
                                <i class="fas fa-cart-plus"></i> Add
                            </button>
                            <button onclick="removeFromWishlist(<?php echo $item['id']; ?>)" class="btn-sm btn-remove-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-heart"></i></div>
                <h4>Your wishlist is empty</h4>
                <p>Save items you love to your wishlist</p>
                <a href="shop.php" class="btn-submit" style="display:inline-block;margin-top:0.75rem;text-decoration:none;">Browse Products →</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- ═══ PROFILE TAB ═══ -->
        <?php if ($activeTab === 'profile'): ?>
        <div class="content-header">
            <h2><i class="fas fa-user-edit"></i> Profile Settings</h2>
        </div>
        <div class="content-body">
            <?php if ($successMessage): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo $successMessage; ?></div>
            <?php endif; ?>
            <?php if ($errorMessage): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="account.php?tab=profile" class="profile-form">
                <input type="hidden" name="action" value="update_profile">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                    <small style="font-size:0.7rem;color:var(--text-muted);display:block;margin-top:0.2rem;">Email cannot be changed. Contact support for assistance.</small>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                </div>
                <button type="submit" class="btn-submit">Save Changes</button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- ═══ SECURITY TAB ═══ -->
        <?php if ($activeTab === 'security'): ?>
        <div class="content-header">
            <h2><i class="fas fa-lock"></i> Security Settings</h2>
        </div>
        <div class="content-body">
            <?php if ($successMessage && $activeTab === 'security'): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo $successMessage; ?></div>
            <?php endif; ?>
            <?php if ($errorMessage && $activeTab === 'security'): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="account.php?tab=security" class="profile-form">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required>
                    <small style="font-size:0.7rem;color:var(--text-muted);display:block;margin-top:0.2rem;">Minimum 6 characters</small>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn-submit">Change Password</button>
            </form>
        </div>
        <?php endif; ?>
        
    </div>
</div>

<!-- ─── TOAST CONTAINER ────────────────────────────────────────── -->
<div class="toast-container" id="toastContainer"></div>

<!-- ─── SCRIPTS ────────────────────────────────────────────────── -->
<script>
// ═══════════════════════════════════════════════════════════════════
// ACCOUNT SCRIPTS v2.0
// ═══════════════════════════════════════════════════════════════════

// ─── TOAST ──────────────────────────────────────────────────────
function showToast(message, type) {
    type = type || 'success';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast' + (type === 'error' ? ' error' : '');
    toast.innerHTML = (type === 'success' ? '✅' : '❌') + ' ' + message;
    container.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(120%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300);
    }, 4000);
}

// ─── ADDRESS MODAL ─────────────────────────────────────────────
var modal = document.getElementById('addressModal');
var showBtn = document.getElementById('showAddAddressBtn');

function openAddressModal() {
    if (modal) modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeAddressModal() {
    if (modal) modal.classList.remove('active');
    document.body.style.overflow = '';
}

if (showBtn) {
    showBtn.addEventListener('click', openAddressModal);
}

// Close modal on overlay click
if (modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) closeAddressModal();
    });
}

// Close modal on ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAddressModal();
});

// ─── ADD TO CART ───────────────────────────────────────────────
function addToCart(productId) {
    var data = new URLSearchParams();
    data.append('product_id', productId);
    data.append('quantity', 1);
    data.append('action', 'add_to_cart');
    
    fetch('<?php echo BASE_URL; ?>shop.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: data.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Added to cart! 🛒', 'success');
            var badge = document.getElementById('cartCount');
            if (badge && data.cart_count !== undefined) {
                badge.textContent = data.cart_count;
                badge.style.display = data.cart_count > 0 ? 'inline-block' : 'none';
            }
        } else {
            showToast(data.message || 'Failed to add', 'error');
        }
    })
    .catch(function() {
        showToast('Connection error', 'error');
    });
}

// ─── REMOVE FROM WISHLIST ─────────────────────────────────────
function removeFromWishlist(wishlistId) {
    if (!confirm('Remove this item from your wishlist?')) return;
    
    var data = new URLSearchParams();
    data.append('wishlist_id', wishlistId);
    
    fetch('<?php echo BASE_URL; ?>api/remove-from-wishlist.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: data.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Removed from wishlist', 'success');
            var item = document.getElementById('wishlist-item-' + wishlistId);
            if (item) {
                item.style.transition = 'all 0.3s ease';
                item.style.opacity = '0';
                item.style.transform = 'scale(0.95)';
                setTimeout(function() { if (item.parentNode) item.remove(); }, 300);
            }
            // Update count
            var countBadge = document.querySelector('.nav-item .badge-count');
            if (countBadge) {
                var current = parseInt(countBadge.textContent) || 0;
                if (current > 0) countBadge.textContent = current - 1;
                if (countBadge.textContent === '0') countBadge.style.display = 'none';
            }
        } else {
            showToast(data.message || 'Failed to remove', 'error');
        }
    })
    .catch(function() {
        showToast('Connection error', 'error');
    });
}

// ─── AUTO-HIDE ALERTS ──────────────────────────────────────────
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert-success, .alert-error');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s ease';
            setTimeout(function() { if (alert.parentNode) alert.remove(); }, 500);
        }, 4000);
    });
}, 100);

console.log('%c👤 Bless Mall Account %cv2.0 - Premium',
    'font-size:1.2em;font-weight:bold;color:#0B6E3D;',
    'font-size:1em;color:#C9A227;');
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>