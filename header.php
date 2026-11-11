<?php
/**
 * Bless Mall - Premium Header v2
 * Cinema-Quality Header with Dynamic Categories, Search, and User Experience
 */
if (session_status() === PHP_SESSION_NONE) session_start();

// ========== SAFE CONSTANTS ==========
if (!defined('SITE_URL')) define('SITE_URL', '/');
if (!defined('ASSETS_URL')) define('ASSETS_URL', 'assets/');
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    define('BASE_URL', $protocol . $host . ($basePath ? '/' . ltrim($basePath, '/') : ''));
}

// ========== DATABASE CONNECTION ==========
$header_db = null;
$header_db_connected = false;

if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    try {
        if (!defined('DB_HOST')) {
            require_once __DIR__ . '/config.php';
        }
        if (defined('DB_HOST')) {
            $header_db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if (!$header_db->connect_error) {
                $header_db->set_charset("utf8mb4");
                $header_db_connected = true;
            }
        }
    } catch (Exception $e) {
        error_log("Header DB Connection Error: " . $e->getMessage());
    }
} else {
    $header_db = $conn;
    $header_db_connected = true;
}

// ========== CART COUNT ==========
$cart_count = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item) && isset($item['quantity'])) {
            $cart_count += (int)$item['quantity'];
        } elseif (is_numeric($item)) {
            $cart_count += (int)$item;
        }
    }
}

// ========== USER AUTH ==========
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_name = '';
$user_email = '';
$user_role = 'buyer';
$user_initial = 'U';
$is_admin = false;
$is_seller = false;

if ($is_logged_in) {
    $user_name = htmlspecialchars(substr($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User', 0, 50));
    $user_email = $_SESSION['user_email'] ?? '';
    $user_role = $_SESSION['role_name'] ?? $_SESSION['role_id'] ?? 'buyer';
    $user_initial = strtoupper(mb_substr($user_name, 0, 1));
    $is_admin = ($user_role === 'admin' || $user_role === '1');
    $is_seller = ($user_role === 'seller' || $user_role === '2');
}

// ========== CURRENT PAGE ==========
$current_page = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (empty($current_page) || $current_page === '/') $current_page = 'index.php';
$current_page = strtolower($current_page);

// ========== FETCH CATEGORIES ==========
$categories = [];
$category_image_map = [];

if ($header_db_connected && $header_db && !$header_db->connect_error) {
    try {
        $cat_sql = "SELECT id, name, slug, icon, image FROM categories WHERE is_active = 1 AND parent_id IS NULL ORDER BY name ASC LIMIT 12";
        $cat_result = $header_db->query($cat_sql);
        if ($cat_result && $cat_result->num_rows > 0) {
            while ($row = $cat_result->fetch_assoc()) {
                $categories[] = $row;
                if (!empty($row['image'])) {
                    $category_image_map[$row['id']] = $row['image'];
                }
            }
            $cat_result->free();
        }
    } catch (Exception $e) {
        error_log("Header Categories Error: " . $e->getMessage());
    }
}

// Premium fallback categories with icons
if (empty($categories)) {
    $categories = [
        ['id' => 1, 'name' => '🔥 Hot Deals', 'slug' => 'hot-deals', 'icon' => 'fa-fire'],
        ['id' => 2, 'name' => 'Fashion', 'slug' => 'fashion', 'icon' => 'fa-tshirt'],
        ['id' => 3, 'name' => 'Electronics', 'slug' => 'electronics', 'icon' => 'fa-laptop'],
        ['id' => 4, 'name' => 'Phones & Tablets', 'slug' => 'phones', 'icon' => 'fa-mobile-alt'],
        ['id' => 5, 'name' => 'Groceries', 'slug' => 'groceries', 'icon' => 'fa-shopping-basket'],
        ['id' => 6, 'name' => 'Home & Living', 'slug' => 'home', 'icon' => 'fa-home'],
        ['id' => 7, 'name' => 'Beauty & Health', 'slug' => 'beauty', 'icon' => 'fa-heart'],
        ['id' => 8, 'name' => 'Sports & Outdoor', 'slug' => 'sports', 'icon' => 'fa-futbol'],
    ];
}

// ========== HELPER: Category Icon ==========
function header_get_icon($cat) {
    if (!empty($cat['icon']) && str_starts_with($cat['icon'], 'fa-')) {
        return $cat['icon'];
    }
    $name = strtolower($cat['name'] ?? '');
    $icon_map = [
        'hot' => 'fa-fire', 'deal' => 'fa-tag', 'fashion' => 'fa-tshirt',
        'clothing' => 'fa-tshirt', 'dress' => 'fa-tshirt', 'electronic' => 'fa-laptop',
        'gadget' => 'fa-laptop', 'phone' => 'fa-mobile-alt', 'tablet' => 'fa-tablet-alt',
        'mobile' => 'fa-mobile-alt', 'grocery' => 'fa-shopping-basket', 'food' => 'fa-apple-alt',
        'home' => 'fa-home', 'furniture' => 'fa-couch', 'living' => 'fa-home',
        'beauty' => 'fa-heart', 'health' => 'fa-heartbeat', 'makeup' => 'fa-paint-brush',
        'sport' => 'fa-futbol', 'outdoor' => 'fa-campground', 'shoe' => 'fa-shoe-prints',
        'bag' => 'fa-shopping-bag', 'jewelry' => 'fa-gem', 'watch' => 'fa-clock',
        'book' => 'fa-book', 'toy' => 'fa-gamepad', 'game' => 'fa-gamepad',
        'baby' => 'fa-baby-carriage', 'pet' => 'fa-paw', 'car' => 'fa-car',
        'automotive' => 'fa-car', 'computer' => 'fa-desktop', 'laptop' => 'fa-laptop',
        'camera' => 'fa-camera', 'audio' => 'fa-headphones', 'music' => 'fa-music',
        'office' => 'fa-building', 'garden' => 'fa-seedling', 'kitchen' => 'fa-utensils',
        'decor' => 'fa-palette', 'light' => 'fa-lightbulb', 'tool' => 'fa-tools',
    ];
    foreach ($icon_map as $keyword => $icon) {
        if (strpos($name, $keyword) !== false) return $icon;
    }
    return 'fa-tag';
}

// ========== HELPER: Category Image URL ==========
function header_get_category_image_url($image) {
    if (empty($image)) return '';
    if (filter_var($image, FILTER_VALIDATE_URL)) return $image;
    return BASE_URL . '/' . ltrim($image, '/');
}

// Page title
$page_title = !empty($page_title) ? $page_title : 'Bless Mall - Quality Products in Ghana';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0B6E3D">
    <meta name="description" content="Bless Mall - Premium Quality Products at Affordable Prices in Ghana. Fast delivery, great deals.">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,900;1,400;1,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <style>
    /* ═══════════════════════════════════════════════════════════════
       BLESS MALL — PREMIUM HEADER v2
       Cinema-Quality Navigation Experience
    ═══════════════════════════════════════════════════════════════ */
    :root {
        --primary: #0B6E3D;
        --primary-dark: #085229;
        --primary-light: #E6F5EE;
        --primary-glow: rgba(11,110,61,0.15);
        --gold: #C9A227;
        --gold-light: #FDF5E1;
        --gold-dark: #A6861F;
        --red: #DC2626;
        --red-light: #FEE2E2;
        --white: #FFFFFF;
        --off-white: #F8F7F4;
        --gray-50: #F9FAFB;
        --gray-100: #F3F4F6;
        --gray-200: #E5E7EB;
        --gray-300: #D1D5DB;
        --gray-400: #9CA3AF;
        --gray-500: #6B7280;
        --gray-600: #4B5563;
        --gray-700: #374151;
        --gray-800: #1F2937;
        --gray-900: #111827;
        --shadow-xs: 0 1px 2px rgba(0,0,0,0.04);
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
        --shadow-md: 0 10px 15px rgba(0,0,0,0.07);
        --shadow-lg: 0 20px 40px rgba(0,0,0,0.10);
        --shadow-xl: 0 25px 50px rgba(0,0,0,0.12);
        --radius-sm: 8px;
        --radius: 12px;
        --radius-lg: 16px;
        --radius-full: 9999px;
        --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        --transition-slow: 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        --font-primary: 'Inter', -apple-system, sans-serif;
        --font-display: 'Playfair Display', Georgia, serif;
    }

    /* ─── TOP BAR ────────────────────────────────────────────── */
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
    .top-bar-left i { color: #1FAF5B; }
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
        .top-bar-inner { padding: 0 0.75rem; }
    }

    /* ─── NAVBAR ──────────────────────────────────────────────── */
    .navbar {
        background: rgba(255,255,255,0.96);
        backdrop-filter: blur(20px) saturate(180%);
        -webkit-backdrop-filter: blur(20px) saturate(180%);
        border-bottom: 1px solid rgba(229,231,235,0.6);
        position: sticky;
        top: 0;
        z-index: 1000;
        transition: var(--transition);
    }
    .navbar.scrolled {
        box-shadow: var(--shadow-md);
        background: rgba(255,255,255,0.98);
    }
    .nav-inner {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
        height: 72px;
        display: flex;
        align-items: center;
        gap: 1.25rem;
        transition: var(--transition);
    }
    @media (max-width: 768px) {
        .nav-inner { height: 64px; padding: 0 1rem; gap: 0.75rem; }
    }

    /* ─── HAMBURGER ───────────────────────────────────────────── */
    .hamburger-btn {
        display: none;
        background: none;
        border: 2px solid var(--gray-200);
        color: var(--gray-700);
        width: 42px;
        height: 42px;
        border-radius: var(--radius-sm);
        cursor: pointer;
        font-size: 1.2rem;
        align-items: center;
        justify-content: center;
        transition: var(--transition);
        flex-shrink: 0;
    }
    .hamburger-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light);
    }
    .hamburger-btn:active { transform: scale(0.95); }
    @media (max-width: 1024px) { .hamburger-btn { display: flex; } }

    /* ─── LOGO ────────────────────────────────────────────────── */
    .logo-link {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        text-decoration: none;
        flex-shrink: 0;
        transition: var(--transition);
    }
    .logo-link:hover { transform: scale(1.02); }
    .logo-link:active { transform: scale(0.98); }
    .logo-img {
        width: 44px;
        height: 44px;
        border-radius: var(--radius-sm);
        object-fit: cover;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
    }
    .logo-link:hover .logo-img { box-shadow: 0 0 24px var(--primary-glow); }
    .logo-text {
        font-family: var(--font-display);
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--primary);
        letter-spacing: -0.02em;
        line-height: 1.1;
    }
    .logo-text .gold {
        color: var(--gold);
        font-weight: 800;
        background: linear-gradient(135deg, var(--gold) 0%, #d4a830 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    @media (max-width: 768px) {
        .logo-text { font-size: 1.15rem; }
        .logo-img { width: 36px; height: 36px; }
    }
    @media (max-width: 480px) {
        .logo-text { font-size: 1rem; }
        .logo-img { width: 32px; height: 32px; }
    }

    /* ─── SEARCH ────────────────────────────────────────────────── */
    .search-wrap {
        flex: 1;
        max-width: 480px;
        min-width: 160px;
    }
    .search-box {
        display: flex;
        align-items: center;
        background: var(--gray-100);
        border: 2px solid transparent;
        border-radius: var(--radius-full);
        padding: 3px 4px 3px 18px;
        transition: var(--transition);
        gap: 6px;
    }
    .search-box:focus-within {
        border-color: var(--primary);
        background: var(--white);
        box-shadow: 0 0 0 5px var(--primary-glow), 0 4px 16px rgba(0,0,0,0.05);
        transform: translateY(-1px);
    }
    .search-icon { color: var(--gray-400); font-size: 0.9rem; flex-shrink: 0; }
    .search-box input {
        border: none;
        background: transparent;
        padding: 8px 6px;
        font-family: var(--font-primary);
        font-size: 0.88rem;
        outline: none;
        width: 100%;
        color: var(--gray-800);
        min-width: 0;
    }
    .search-box input::placeholder { color: var(--gray-400); font-weight: 400; }
    .search-submit {
        background: var(--primary);
        color: white;
        border: none;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.85rem;
    }
    .search-submit:hover {
        background: var(--gold-dark);
        transform: scale(1.06);
        box-shadow: 0 4px 16px rgba(201,162,39,0.4);
    }
    .search-submit:active { transform: scale(0.95); }

    @media (max-width: 1024px) {
        .search-wrap { display: none; }
        .search-wrap.mobile-visible {
            display: block;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-width: 100%;
            padding: 10px 16px;
            background: white;
            border-top: 1px solid var(--gray-200);
            box-shadow: var(--shadow-md);
            z-index: 50;
        }
        .search-wrap.mobile-visible .search-box {
            border-color: var(--primary);
            background: white;
        }
    }

    /* ─── NAV MENU ────────────────────────────────────────────── */
    .nav-menu {
        display: flex;
        align-items: center;
        gap: 2px;
        list-style: none;
        flex-shrink: 0;
    }
    .nav-menu > li { position: relative; }
    .nav-menu > li > a {
        text-decoration: none;
        font-weight: 500;
        color: var(--gray-600);
        font-size: 0.85rem;
        padding: 8px 14px;
        border-radius: var(--radius-sm);
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        cursor: pointer;
        font-family: var(--font-primary);
    }
    .nav-menu > li > a i { 
        font-size: 1rem; 
        color: var(--primary); 
        transition: var(--transition); 
    }
    .nav-menu > li > a:hover {
        background: var(--primary-light);
        color: var(--primary);
        transform: translateY(-1px);
    }
    .nav-menu > li > a:active { transform: translateY(0) scale(0.97); }
    .nav-menu > li > a.active {
        background: var(--primary-light);
        color: var(--primary);
        font-weight: 600;
    }
    .nav-menu > li > a.active i { color: var(--primary); }
    body:not(.touch) .nav-menu > li > a:hover i { transform: scale(1.1); }

    /* ─── CART BUTTON ──────────────────────────────────────────── */
    .cart-btn { position: relative; }
    .cart-count {
        position: absolute;
        top: 0px;
        right: 2px;
        background: var(--red);
        color: white;
        font-size: 0.6rem;
        font-weight: 700;
        min-width: 18px;
        height: 18px;
        line-height: 18px;
        border-radius: 9px;
        text-align: center;
        padding: 0 5px;
        border: 2px solid white;
        box-shadow: var(--shadow-sm);
        animation: cartPop 0.3s ease;
    }
    .cart-count:empty { display: none; }
    @keyframes cartPop {
        0% { transform: scale(0); }
        50% { transform: scale(1.2); }
        100% { transform: scale(1); }
    }

    /* ─── DROPDOWN ─────────────────────────────────────────────── */
    .dropdown-toggle::after {
        content: '\f107';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        font-size: 0.55rem;
        margin-left: 2px;
        transition: var(--transition);
    }
    .nav-menu > li:hover .dropdown-toggle::after { transform: rotate(180deg); }

    .mega-dropdown {
        position: absolute;
        top: calc(100% + 10px);
        left: 50%;
        transform: translateX(-50%) translateY(6px);
        background: white;
        min-width: 560px;
        box-shadow: var(--shadow-xl);
        border-radius: var(--radius);
        padding: 20px;
        opacity: 0;
        visibility: hidden;
        transition: var(--transition-slow);
        z-index: 10;
        border: 1px solid var(--gray-200);
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 4px;
    }
    .mega-dropdown::before {
        content: '';
        position: absolute;
        top: -6px;
        left: 50%;
        transform: translateX(-50%) rotate(45deg);
        width: 12px;
        height: 12px;
        background: white;
        border-left: 1px solid var(--gray-200);
        border-top: 1px solid var(--gray-200);
    }
    .nav-menu > li:hover .mega-dropdown,
    .nav-menu > li:focus-within .mega-dropdown {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) translateY(0);
    }

    .mega-item {
        padding: 10px 14px;
        border-radius: var(--radius-sm);
        font-size: 0.85rem;
        text-decoration: none !important;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--gray-600) !important;
        font-weight: 500;
        transition: var(--transition);
        font-family: var(--font-primary);
    }
    .mega-item:hover {
        background: var(--primary-light) !important;
        color: var(--primary) !important;
        transform: translateX(4px);
    }
    .mega-item i { 
        font-size: 1.05rem; 
        width: 24px; 
        text-align: center; 
        color: var(--primary); 
        flex-shrink: 0;
    }
    .mega-item .cat-img {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        object-fit: cover;
        flex-shrink: 0;
        background: var(--gray-100);
    }
    .mega-view-all {
        grid-column: 1 / -1;
        background: var(--primary) !important;
        color: white !important;
        justify-content: center;
        padding: 12px !important;
        margin-top: 8px;
        font-weight: 600;
        border-radius: var(--radius-sm);
    }
    .mega-view-all:hover {
        background: var(--primary-dark) !important;
        transform: translateY(-2px) !important;
        box-shadow: var(--shadow-md);
    }
    .mega-view-all i { color: white !important; }

    @media (max-width: 1024px) {
        .nav-menu { display: none; }
        .mega-dropdown { display: none; }
    }

    /* ─── AUTH WRAP ────────────────────────────────────────────── */
    .auth-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-shrink: 0;
        position: relative;
    }
    .btn {
        padding: 8px 18px;
        border-radius: var(--radius-full);
        font-weight: 600;
        font-size: 0.82rem;
        text-decoration: none;
        transition: var(--transition);
        white-space: nowrap;
        cursor: pointer;
        font-family: var(--font-primary);
        border: 2px solid transparent;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn:active { transform: scale(0.95); }
    .btn-outline {
        background: transparent;
        color: var(--primary);
        border-color: var(--primary);
    }
    .btn-outline:hover {
        background: var(--primary);
        color: white;
        box-shadow: 0 4px 16px var(--primary-glow);
    }
    .btn-solid {
        background: var(--primary);
        color: white;
    }
    .btn-solid:hover {
        background: var(--primary-dark);
        box-shadow: 0 4px 16px var(--primary-glow);
        transform: translateY(-1px);
    }

    @media (max-width: 480px) {
        .btn { padding: 6px 12px; font-size: 0.75rem; }
        .btn-outline { display: none; }
    }

    /* ─── USER MENU ────────────────────────────────────────────── */
    .user-drop { position: relative; }
    .user-trigger {
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        padding: 4px 12px 4px 4px;
        border-radius: var(--radius-full);
        border: 2px solid var(--gray-200);
        background: var(--white);
        font-family: var(--font-primary);
        font-size: 0.82rem;
        font-weight: 500;
        color: var(--gray-700);
        transition: var(--transition);
    }
    .user-trigger:hover {
        border-color: var(--primary);
        background: var(--primary-light);
        box-shadow: var(--shadow-sm);
    }
    .user-avatar {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary) 0%, #1a8a4a 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.75rem;
        box-shadow: 0 2px 8px var(--primary-glow);
    }
    .user-panel {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background: white;
        min-width: 220px;
        border-radius: var(--radius);
        box-shadow: var(--shadow-xl);
        border: 1px solid var(--gray-200);
        padding: 6px;
        opacity: 0;
        visibility: hidden;
        transform: translateY(6px);
        transition: var(--transition-slow);
        z-index: 10;
    }
    .user-panel::before {
        content: '';
        position: absolute;
        top: -6px;
        right: 30px;
        width: 12px;
        height: 12px;
        background: white;
        transform: rotate(45deg);
        border-left: 1px solid var(--gray-200);
        border-top: 1px solid var(--gray-200);
    }
    .user-drop:hover .user-panel,
    .user-drop:focus-within .user-panel {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    .user-panel a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 14px;
        text-decoration: none;
        color: var(--gray-600);
        font-size: 0.82rem;
        border-radius: var(--radius-sm);
        transition: var(--transition);
        font-family: var(--font-primary);
    }
    .user-panel a:hover {
        background: var(--primary-light);
        color: var(--primary);
    }
    .user-panel a i { width: 20px; color: var(--primary); font-size: 0.85rem; }
    .user-panel hr { margin: 4px 8px; border: none; border-top: 1px solid var(--gray-200); }
    .user-panel .admin-link { color: var(--gold-dark) !important; font-weight: 600; }
    .user-panel .logout-link { color: var(--red) !important; }

    /* ─── MOBILE SIDEBAR ───────────────────────────────────────── */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 997;
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
    }
    .sidebar-overlay.active {
        opacity: 1;
        pointer-events: auto;
    }
    .mobile-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 300px;
        max-width: 85vw;
        height: 100vh;
        height: 100dvh;
        background: white;
        z-index: 998;
        transform: translateX(-100%);
        transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        overflow-y: auto;
        box-shadow: var(--shadow-xl);
    }
    .mobile-sidebar.open { transform: translateX(0); }
    .sidebar-head {
        padding: 20px 20px 14px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        background: white;
        z-index: 2;
    }
    .sidebar-head h3 {
        font-family: var(--font-display);
        color: var(--primary);
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .sidebar-head h3 img {
        width: 32px;
        height: 32px;
        border-radius: 6px;
    }
    .sidebar-close {
        background: var(--gray-100);
        border: none;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 1.1rem;
        color: var(--gray-600);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition);
    }
    .sidebar-close:hover { background: var(--red-light); color: var(--red); }
    .sidebar-nav { padding: 12px 0; }
    .sidebar-nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 24px;
        text-decoration: none;
        color: var(--gray-700);
        font-weight: 500;
        font-size: 0.9rem;
        transition: var(--transition);
        font-family: var(--font-primary);
    }
    .sidebar-nav a:hover { background: var(--gray-50); color: var(--primary); padding-left: 28px; }
    .sidebar-nav a i { width: 22px; color: var(--primary); font-size: 1rem; }
    .sidebar-nav hr {
        margin: 8px 20px;
        border: none;
        border-top: 1px solid var(--gray-200);
    }

    /* ─── MOBILE BOTTOM BAR ────────────────────────────────────── */
    .mobile-bar {
        display: none;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(255,255,255,0.97);
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border-top: 1px solid var(--gray-200);
        padding: 4px 8px;
        padding-bottom: max(4px, env(safe-area-inset-bottom));
        z-index: 999;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.06);
    }
    .mobile-bar-inner {
        display: flex;
        justify-content: space-around;
        align-items: center;
        max-width: 500px;
        margin: 0 auto;
    }
    .mobile-bar a {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 2px;
        text-decoration: none;
        color: var(--gray-400);
        font-size: 0.6rem;
        font-weight: 500;
        padding: 4px 10px;
        border-radius: var(--radius-sm);
        transition: var(--transition);
        position: relative;
        min-width: 44px;
        font-family: var(--font-primary);
    }
    .mobile-bar a i { font-size: 1.2rem; transition: var(--transition); }
    .mobile-bar a.active { color: var(--primary); }
    .mobile-bar a.active i { color: var(--primary); }
    .mobile-bar a:active { transform: scale(0.9); }
    .mobile-bar .mobile-cart-dot {
        position: absolute;
        top: 0;
        right: 4px;
        background: var(--red);
        color: white;
        font-size: 0.55rem;
        min-width: 16px;
        height: 16px;
        line-height: 16px;
        border-radius: 8px;
        text-align: center;
        padding: 0 4px;
        border: 1.5px solid white;
        font-weight: 700;
    }

    @media (max-width: 1024px) {
        .sidebar-overlay { display: block; }
        .mobile-bar { display: block; }
        body { padding-bottom: 60px; }
    }
    @media (min-width: 1025px) {
        .mobile-sidebar, .sidebar-overlay, .mobile-bar { display: none !important; }
    }

    /* ─── ACCESSIBILITY ────────────────────────────────────────── */
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0,0,0,0);
        border: 0;
    }
    :focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 3px;
        border-radius: 3px;
    }

    /* ─── REDUCED MOTION ───────────────────────────────────────── */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: 0.01ms !important;
            transition-duration: 0.01ms !important;
        }
    }
    </style>
</head>
<body>
<script>document.body.classList.add('ontouchstart' in window || navigator.maxTouchPoints ? 'touch' : 'no-touch');</script>

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

<!-- ─── NAVBAR ──────────────────────────────────────────────────── -->
<header class="navbar" id="navbar" role="banner">
    <div class="nav-inner">
        <!-- Hamburger -->
        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Open menu" aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Logo -->
        <a href="index.php" class="logo-link" title="Bless Mall - Home">
            <img src="<?php echo ASSETS_URL; ?>images/logo/logo.jpeg" alt="Bless Mall" class="logo-img" 
                 width="44" height="44" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2244%22 height=%2244%22><rect fill=%22%230B6E3D%22 width=%2244%22 height=%2244%22 rx=%228%22/><text fill=%22white%22 x=%2250%25%22 y=%2255%25%22 text-anchor=%22middle%22 dy=%22.1em%22 font-size=%2220%22 font-weight=%22bold%22>B</text></svg>';">
            <span class="logo-text">Bless <span class="gold">Mall</span></span>
        </a>

        <!-- Search (Desktop) -->
        <div class="search-wrap" id="searchWrap">
            <form class="search-box" action="shop.php" method="get" role="search">
                <i class="fas fa-search search-icon"></i>
                <input type="search" name="search" placeholder="Search products, brands..." 
                       aria-label="Search products" 
                       value="<?php echo htmlspecialchars($_GET['search'] ?? $_GET['q'] ?? ''); ?>">
                <button type="submit" class="search-submit" aria-label="Search">
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
        </div>

        <!-- Desktop Nav -->
        <nav aria-label="Main navigation">
            <ul class="nav-menu">
                <li><a href="index.php" class="<?php echo ($current_page === 'index.php' || $current_page === '') ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a></li>
                <li><a href="shop.php" class="<?php echo $current_page === 'shop.php' ? 'active' : ''; ?>">
                    <i class="fas fa-store"></i> Shop
                </a></li>
                <li>
                    <a href="shop.php" class="dropdown-toggle <?php echo $current_page === 'category.php' ? 'active' : ''; ?>">
                        <i class="fas fa-th-large"></i> Categories
                    </a>
                    <div class="mega-dropdown">
                        <?php foreach (array_slice($categories, 0, 10) as $cat): ?>
                            <a href="shop.php?category=<?php echo urlencode($cat['slug']); ?>" class="mega-item">
                                <i class="fas <?php echo header_get_icon($cat); ?>"></i>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </a>
                        <?php endforeach; ?>
                        <a href="shop.php" class="mega-item mega-view-all">
                            <i class="fas fa-eye"></i> View All Products
                        </a>
                    </div>
                </li>
                <li><a href="express.php" class="<?php echo $current_page === 'express.php' ? 'active' : ''; ?>">
                    <i class="fas fa-rocket"></i> Express
                </a></li>
                <li>
                    <a href="cart.php" class="cart-btn <?php echo $current_page === 'cart.php' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-bag"></i> Cart
                        <span class="cart-count" id="cartCount"><?php echo $cart_count > 0 ? $cart_count : ''; ?></span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Auth -->
        <div class="auth-wrap">
            <?php if ($is_logged_in): ?>
                <div class="user-drop">
                    <button class="user-trigger" id="userTrigger" aria-label="User menu" aria-expanded="false">
                        <span class="user-avatar"><?php echo $user_initial; ?></span>
                        <span style="max-width:80px;overflow:hidden;text-overflow:ellipsis;display:none;" class="user-name-desk"><?php echo $user_name; ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.55rem;"></i>
                    </button>
                    <div class="user-panel">
                        <a href="account.php"><i class="fas fa-user"></i> My Account</a>
                        <a href="orders.php"><i class="fas fa-box"></i> My Orders</a>
                        <a href="addresses.php"><i class="fas fa-map-marker-alt"></i> Addresses</a>
                        <a href="wishlist.php"><i class="fas fa-heart"></i> Wishlist</a>
                        <?php if ($is_admin): ?>
                            <hr>
                            <a href="admin/" class="admin-link"><i class="fas fa-shield-alt"></i> Admin Panel</a>
                        <?php endif; ?>
                        <?php if ($is_seller && !$is_admin): ?>
                            <hr>
                            <a href="vendor/" class="admin-link"><i class="fas fa-store"></i> Vendor Dashboard</a>
                        <?php endif; ?>
                        <hr>
                        <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="login.php" class="btn btn-outline"><i class="fas fa-sign-in-alt"></i> Login</a>
                <a href="register.php" class="btn btn-solid"><i class="fas fa-user-plus"></i> Sign Up</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- ─── MOBILE SIDEBAR ─────────────────────────────────────────── -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="mobile-sidebar" id="mobileSidebar" aria-label="Mobile menu">
    <div class="sidebar-head">
        <h3>
            <img src="<?php echo ASSETS_URL; ?>images/logo/logo.jpeg" alt="" width="32" height="32" onerror="this.style.display='none'">
            Menu
        </h3>
        <button class="sidebar-close" id="sidebarClose" aria-label="Close menu">&times;</button>
    </div>
    <nav class="sidebar-nav">
        <a href="index.php"><i class="fas fa-home"></i> Home</a>
        <a href="shop.php"><i class="fas fa-store"></i> All Products</a>
        <a href="shop.php"><i class="fas fa-th-large"></i> Categories</a>
        <a href="express.php"><i class="fas fa-rocket"></i> Express Delivery</a>
        <a href="cart.php"><i class="fas fa-shopping-bag"></i> Cart 
            <?php if ($cart_count > 0): ?>
                <span style="background:var(--red);color:white;padding:1px 8px;border-radius:10px;font-size:0.7rem;margin-left:auto;"><?php echo $cart_count; ?></span>
            <?php endif; ?>
        </a>
        
        <hr>
        
        <?php if ($is_logged_in): ?>
            <a href="account.php"><i class="fas fa-user"></i> My Account</a>
            <a href="orders.php"><i class="fas fa-box"></i> My Orders</a>
            <a href="addresses.php"><i class="fas fa-map-marker-alt"></i> Addresses</a>
            <a href="wishlist.php"><i class="fas fa-heart"></i> Wishlist</a>
            <?php if ($is_admin): ?>
                <a href="admin/" style="color:var(--gold-dark);"><i class="fas fa-shield-alt"></i> Admin Panel</a>
            <?php endif; ?>
            <?php if ($is_seller && !$is_admin): ?>
                <a href="vendor/" style="color:var(--gold-dark);"><i class="fas fa-store"></i> Vendor Dashboard</a>
            <?php endif; ?>
            <a href="logout.php" style="color:var(--red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
        <?php else: ?>
            <a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
            <a href="register.php"><i class="fas fa-user-plus"></i> Sign Up</a>
        <?php endif; ?>
    </nav>
</aside>

<!-- ─── MOBILE BOTTOM BAR ──────────────────────────────────────── -->
<nav class="mobile-bar" aria-label="Mobile navigation">
    <div class="mobile-bar-inner">
        <a href="index.php" class="<?php echo ($current_page === 'index.php' || $current_page === '') ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> Home
        </a>
        <a href="shop.php" class="<?php echo $current_page === 'shop.php' ? 'active' : ''; ?>">
            <i class="fas fa-store"></i> Shop
        </a>
        <a href="#" id="mobileSearchBtn">
            <i class="fas fa-search"></i> Search
        </a>
        <a href="shop.php" class="<?php echo $current_page === 'category.php' ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Cats
        </a>
        <a href="cart.php" class="<?php echo $current_page === 'cart.php' ? 'active' : ''; ?> cart-btn">
            <i class="fas fa-shopping-bag"></i> Cart
            <?php if ($cart_count > 0): ?>
                <span class="mobile-cart-dot"><?php echo $cart_count > 99 ? '99+' : $cart_count; ?></span>
            <?php endif; ?>
        </a>
    </div>
</nav>

<!-- ─── SCRIPTS ──────────────────────────────────────────────────── -->
<script>
(function() {
    'use strict';
    
    // ===== MOBILE SIDEBAR =====
    var hamburger = document.getElementById('hamburgerBtn');
    var sidebar = document.getElementById('mobileSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var sidebarClose = document.getElementById('sidebarClose');
    var body = document.body;
    
    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        body.style.overflow = 'hidden';
        if (hamburger) {
            hamburger.setAttribute('aria-expanded', 'true');
            hamburger.setAttribute('aria-label', 'Close menu');
        }
    }
    
    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        body.style.overflow = '';
        if (hamburger) {
            hamburger.setAttribute('aria-expanded', 'false');
            hamburger.setAttribute('aria-label', 'Open menu');
        }
    }
    
    if (hamburger) {
        hamburger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }
    
    if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    
    // Swipe to close
    var touchStartX = 0;
    sidebar.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
    }, {passive: true});
    
    sidebar.addEventListener('touchmove', function(e) {
        var diff = touchStartX - e.touches[0].clientX;
        if (diff > 50) closeSidebar();
    }, {passive: true});
    
    // ===== MOBILE SEARCH TOGGLE =====
    var mobileSearch = document.getElementById('mobileSearchBtn');
    var searchWrap = document.getElementById('searchWrap');
    
    if (mobileSearch && searchWrap) {
        mobileSearch.addEventListener('click', function(e) {
            e.preventDefault();
            searchWrap.classList.toggle('mobile-visible');
            if (searchWrap.classList.contains('mobile-visible')) {
                var input = searchWrap.querySelector('input');
                if (input) setTimeout(function() { input.focus(); }, 100);
            }
        });
    }
    
    // ===== SCROLL SHADOW =====
    var navbar = document.getElementById('navbar');
    var lastScrollY = 0;
    window.addEventListener('scroll', function() {
        var scrollY = window.scrollY;
        if (scrollY > 10) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
        lastScrollY = scrollY;
    }, {passive: true});
    
    // ===== CART UPDATER =====
    window.updateCartCount = function(count) {
        count = parseInt(count) || 0;
        
        // Desktop badge
        var desktopBadge = document.getElementById('cartCount');
        if (desktopBadge) {
            desktopBadge.textContent = count > 0 ? (count > 99 ? '99+' : count) : '';
            if (count > 0) {
                desktopBadge.style.display = '';
                desktopBadge.style.animation = 'none';
                desktopBadge.offsetHeight;
                desktopBadge.style.animation = 'cartPop 0.3s ease';
            } else {
                desktopBadge.style.display = 'none';
            }
        }
        
        // Mobile badge
        var mobileDots = document.querySelectorAll('.mobile-cart-dot');
        mobileDots.forEach(function(dot) {
            if (count > 0) {
                dot.textContent = count > 99 ? '99+' : count;
                dot.style.display = '';
            } else {
                dot.style.display = 'none';
            }
        });
    };
    
    // Listen for cart:updated events
    document.addEventListener('cart:updated', function(e) {
        if (e.detail && typeof e.detail.count === 'number') {
            window.updateCartCount(e.detail.count);
        }
    });
    
    // Keyboard
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });
    
    // Show username on wider screens
    var userNameDesk = document.querySelector('.user-name-desk');
    function toggleUserName() {
        if (userNameDesk) {
            userNameDesk.style.display = window.innerWidth > 480 ? 'inline' : 'none';
        }
    }
    toggleUserName();
    window.addEventListener('resize', toggleUserName);
    
    console.log('🇬🇭 Bless Mall Header — Premium v2 Loaded');
})();
</script>