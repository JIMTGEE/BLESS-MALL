<?php
/**
 * cart.php - Bless Mall Shopping Cart v10 (Premium)
 * 
 * Features:
 * - Self-contained AJAX cart management (no separate API folder)
 * - Real-time quantity updates with stock validation
 * - Perfect product images with fallbacks (same as shop.php)
 * - Dynamic delivery fee based on location (Accra-based)
 * - Save for later (wishlist integration)
 * - Express delivery option with visual badge
 * - Mobile-responsive premium design
 * - Guest cart support via session
 * - Coupon code support with visual feedback
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BLESSMALL_CONFIG_INCLUDED')) {
    require_once __DIR__ . '/includes/config.php';
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Define BASE_URL if not already defined
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    define('BASE_URL', $protocol . $host . ($basePath ? '/' . ltrim($basePath, '/') : ''));
}

// ════════════════════════════════════════════════════════════════════
//  HANDLE ALL AJAX REQUESTS INLINE
// ════════════════════════════════════════════════════════════════════
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $conn = getConnection();

    $csrfActions = [
        'update_cart_quantity', 'remove_from_cart', 'clear_cart',
        'apply_coupon', 'remove_coupon', 'save_for_later', 'update_delivery_zone',
    ];
    if (in_array($action, $csrfActions, true) && !validateCartCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security validation failed. Refresh the page and try again.']);
        exit;
    }
    
    // ═══ UPDATE CART QUANTITY ═══
    if ($action === 'update_cart_quantity') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $pricing_tier_id = (int)($_POST['pricing_tier_id'] ?? 0);
        $new_quantity = max(1, (int)($_POST['quantity'] ?? 1));
        
        if (!isset($_SESSION['cart'])) {
            echo json_encode(['success' => false, 'message' => 'Cart is empty']);
            exit;
        }
        
        $cart_key = '';
        foreach ($_SESSION['cart'] as $key => $item) {
            $item_pid = (int)($item['product_id'] ?? 0);
            $item_tid = (int)($item['pricing_tier_id'] ?? 0);
            if ($item_pid === $product_id && $item_tid === $pricing_tier_id) {
                $cart_key = $key;
                break;
            }
        }
        
        if (empty($cart_key)) {
            $fallback_key = 'p_' . $product_id . '_t_' . $pricing_tier_id;
            if (isset($_SESSION['cart'][$fallback_key])) {
                $cart_key = $fallback_key;
            }
        }
        
        if (empty($cart_key) || !isset($_SESSION['cart'][$cart_key])) {
            echo json_encode(['success' => false, 'message' => 'Item not found in cart']);
            exit;
        }
        
        $item = &$_SESSION['cart'][$cart_key];
        
        $available = 999;
        if ($pricing_tier_id > 0) {
            $stockStmt = $conn->prepare("
                SELECT stock_quantity, reserved_quantity 
                FROM product_inventory 
                WHERE pricing_tier_id = ?
            ");
            $stockStmt->bind_param("i", $pricing_tier_id);
            $stockStmt->execute();
            $stockResult = $stockStmt->get_result();
            if ($stockData = $stockResult->fetch_assoc()) {
                $available = max(0, (int)($stockData['stock_quantity'] ?? 0) - (int)($stockData['reserved_quantity'] ?? 0));
            }
            $stockStmt->close();
        }
        
        if ($new_quantity > $available && $available > 0) {
            $new_quantity = $available;
        }
        
        $item['quantity'] = $new_quantity;
        $cartData = calculateCartTotals();
        $price = (float)($item['price'] ?? 0);
        $item_total = $price * $new_quantity;
        
        echo json_encode([
            'success' => true,
            'quantity' => $new_quantity,
            'item_total' => _bm_fmt($item_total),
            'item_total_raw' => $item_total,
            'cart_count' => $cartData['total_items'],
            'subtotal' => _bm_fmt($cartData['subtotal']),
            'subtotal_raw' => $cartData['subtotal'],
            'discount_amount_raw' => $cartData['discount_amount'],
            'delivery_fee' => _bm_fmt($cartData['delivery_fee']),
            'delivery_fee_raw' => $cartData['delivery_fee'],
            'total' => _bm_fmt($cartData['total']),
            'total_raw' => $cartData['total'],
            'delivery_breakdown' => $cartData['delivery_breakdown']
        ]);
        exit;
    }
    
    // ═══ REMOVE FROM CART ═══
    if ($action === 'remove_from_cart') {
        $cart_key = $_POST['cart_key'] ?? '';
        
        if (isset($_SESSION['cart'][$cart_key])) {
            unset($_SESSION['cart'][$cart_key]);
        }
        
        $cartData = calculateCartTotals();
        
        echo json_encode([
            'success' => true,
            'message' => 'Item removed from cart',
            'cart_count' => $cartData['total_items'],
            'subtotal' => _bm_fmt($cartData['subtotal']),
            'subtotal_raw' => $cartData['subtotal'],
            'discount_amount_raw' => $cartData['discount_amount'],
            'delivery_fee' => _bm_fmt($cartData['delivery_fee']),
            'delivery_fee_raw' => $cartData['delivery_fee'],
            'total' => _bm_fmt($cartData['total']),
            'total_raw' => $cartData['total'],
            'is_empty' => empty($_SESSION['cart']),
            'delivery_breakdown' => $cartData['delivery_breakdown']
        ]);
        exit;
    }
    
    // ═══ CLEAR CART ═══
    if ($action === 'clear_cart') {
        $_SESSION['cart'] = [];
        echo json_encode([
            'success' => true,
            'message' => 'Cart cleared',
            'cart_count' => 0,
            'is_empty' => true
        ]);
        exit;
    }
    
    // ═══ APPLY COUPON ═══
    if ($action === 'apply_coupon') {
        $coupon_code = trim($_POST['coupon_code'] ?? '');
        
        if (empty($coupon_code)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a coupon code']);
            exit;
        }
        
        $couponStmt = $conn->prepare("
            SELECT * FROM promotions 
            WHERE (name = ? OR name LIKE ?) 
              AND is_active = 1 
              AND start_date <= CURDATE() 
              AND end_date >= CURDATE()
              AND discount_type IN ('percentage', 'fixed')
            LIMIT 1
        ");
        $couponStmt->bind_param("ss", $coupon_code, $coupon_code);
        $couponStmt->execute();
        $coupon = $couponStmt->get_result()->fetch_assoc();
        $couponStmt->close();
        
        if ($coupon) {
            $_SESSION['applied_coupon'] = [
                'code' => $coupon_code,
                'type' => $coupon['discount_type'],
                'value' => (float)$coupon['discount_value'],
                'name' => $coupon['name']
            ];
            
            $cartData = calculateCartTotals();
            
            echo json_encode([
                'success' => true,
                'message' => 'Coupon applied: ' . htmlspecialchars($coupon['name']),
                'coupon_name' => $coupon['name'],
                'coupon_type' => $coupon['discount_type'],
                'coupon_value' => (float)$coupon['discount_value'],
                'subtotal' => _bm_fmt($cartData['subtotal']),
                'subtotal_raw' => $cartData['subtotal'],
                'discount_amount_raw' => $cartData['discount_amount'],
                'delivery_fee' => _bm_fmt($cartData['delivery_fee']),
                'delivery_fee_raw' => $cartData['delivery_fee'],
                'total' => _bm_fmt($cartData['total']),
                'total_raw' => $cartData['total'],
                'delivery_breakdown' => $cartData['delivery_breakdown']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired coupon code']);
        }
        exit;
    }
    
    // ═══ REMOVE COUPON ═══
    if ($action === 'remove_coupon') {
        unset($_SESSION['applied_coupon']);
        $cartData = calculateCartTotals();
        
        echo json_encode([
            'success' => true,
            'message' => 'Coupon removed',
            'subtotal' => _bm_fmt($cartData['subtotal']),
            'subtotal_raw' => $cartData['subtotal'],
            'discount_amount_raw' => $cartData['discount_amount'],
            'delivery_fee' => _bm_fmt($cartData['delivery_fee']),
            'delivery_fee_raw' => $cartData['delivery_fee'],
            'total' => _bm_fmt($cartData['total']),
            'total_raw' => $cartData['total'],
            'delivery_breakdown' => $cartData['delivery_breakdown']
        ]);
        exit;
    }
    
    // ═══ SAVE FOR LATER ═══
    if ($action === 'save_for_later') {
        if (!Auth::isLoggedIn()) {
            echo json_encode([
                'success' => false, 
                'message' => 'Please login to save items',
                'redirect' => 'login.php?redirect=cart.php'
            ]);
            exit;
        }
        
        $product_id = (int)($_POST['product_id'] ?? 0);
        $user_id = (int)$_SESSION['user_id'];
        
        $checkStmt = $conn->prepare("SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?");
        $checkStmt->bind_param("ii", $user_id, $product_id);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        
        if ($exists) {
            echo json_encode(['success' => true, 'message' => 'Already in wishlist']);
        } else {
            $insStmt = $conn->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)");
            $insStmt->bind_param("ii", $user_id, $product_id);
            $insStmt->execute();
            $insStmt->close();
            echo json_encode(['success' => true, 'message' => 'Saved to wishlist! ❤️']);
        }
        exit;
    }
    
    // ═══ UPDATE DELIVERY ZONE ═══
    if ($action === 'update_delivery_zone') {
        $zone = $_POST['zone'] ?? 'legon_campus';
        if (!is_string($zone) || !array_key_exists($zone, DELIVERY_ZONE_SURCHARGES)) {
            echo json_encode(['success' => false, 'message' => 'Invalid delivery zone']);
            exit;
        }
        $_SESSION['delivery_zone'] = $zone;
        $cartData = calculateCartTotals();
        
        echo json_encode([
            'success' => true,
            'message' => 'Delivery zone updated',
            'zone' => $zone,
            'delivery_fee' => _bm_fmt($cartData['delivery_fee']),
            'delivery_fee_raw' => $cartData['delivery_fee'],
            'total' => _bm_fmt($cartData['total']),
            'total_raw' => $cartData['total'],
            'delivery_breakdown' => $cartData['delivery_breakdown']
        ]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ════════════════════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════════════

function _bm_fmt($v) {
    $n = (float) $v;
    if ($n <= 0) return 'GH₵ 0.00';
    return 'GH₵ ' . number_format($n, 2, '.', ',');
}

function validateCartCsrfToken($token): bool {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function calculateDeliveryFee() {
    $subtotal = 0;
    foreach ($_SESSION['cart'] ?? [] as $item) {
        $subtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
    }
    $discountedSubtotal = max(0, $subtotal - calculateCartDiscount($subtotal));
    return calculateDeliveryPricing($_SESSION['cart'] ?? [], 'delivery', null, $discountedSubtotal);
}

function calculateCartTotals() {
    $subtotal = 0;
    $total_items = 0;
    
    if (!empty($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            $subtotal += $price * $qty;
            $total_items += $qty;
        }
    }
    
    $discount_amount = calculateCartDiscount($subtotal);
    
    $subtotal_after_discount = max(0, $subtotal - $discount_amount);
    $delivery = calculateDeliveryFee();
    
    $delivery_fee = $delivery['total_delivery_fee'];
    $total = $subtotal_after_discount + $delivery_fee;
    
    return [
        'subtotal' => round($subtotal, 2),
        'total_items' => $total_items,
        'discount_amount' => round($discount_amount, 2),
        'subtotal_after_discount' => round($subtotal_after_discount, 2),
        'delivery_fee' => round($delivery_fee, 2),
        'total' => round($total, 2),
        'delivery_breakdown' => $delivery,
        'free_delivery_eligible' => $delivery['has_free_delivery']
    ];
}

function calculateCartDiscount(float $subtotal): float {
    if (!isset($_SESSION['applied_coupon'])) {
        return 0;
    }

    $coupon = $_SESSION['applied_coupon'];
    if ($coupon['type'] === 'percentage') {
        return $subtotal * ((float)$coupon['value'] / 100);
    }
    if ($coupon['type'] === 'fixed') {
        return min((float)$coupon['value'], $subtotal);
    }
    return 0;
}

// ════════════════════════════════════════════════════════════════════
//  IMAGE HELPER - SAME AS SHOP.PHP
// ════════════════════════════════════════════════════════════════════
function getCartImageUrl($imagePath) {
    if (empty($imagePath)) {
        return BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
    }
    
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        return $imagePath;
    }
    
    $imagePath = ltrim($imagePath, '/');
    
    $paths_to_check = [
        '/assets/uploads/products/' . $imagePath,
        '/uploads/products/' . $imagePath,
        '/' . $imagePath
    ];
    
    foreach ($paths_to_check as $path) {
        $fullPath = __DIR__ . $path;
        if (file_exists($fullPath)) {
            return BASE_URL . $path;
        }
    }
    
    return BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
}

// Page metadata
$page_title = 'Shopping Cart — Bless Mall';

// Initialize delivery zone if not set
if (!isset($_SESSION['delivery_zone'])) {
    $_SESSION['delivery_zone'] = 'legon_campus';
}

// Calculate initial cart data
$cartData = calculateCartTotals();

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

require_once __DIR__ . '/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="Review your shopping cart at Bless Mall Ghana. Secure checkout with fast delivery.">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,900;1,400;1,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

<style>
/* ════════════════════════════════════════════════════════════════════
   BLESS MALL — CART PAGE v10 Premium (Matches Index Style)
════════════════════════════════════════════════════════════════════ */
:root {
    --primary: #0B6E3D;
    --primary-dark: #085229;
    --primary-light: #E6F5EE;
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
    --amber: #D97706;
    --purple: #7C3AED;
    --purple-lt: #EDE9FE;
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

/* ─── CART PAGE ──────────────────────────────────────────────── */
.cart-page { padding: 2rem 0 4rem; }
.cart-container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }

.cart-breadcrumb {
    display: flex; align-items: center; gap: .5rem;
    font-size: .8rem; color: var(--text-muted); flex-wrap: wrap;
    margin-bottom: 1.5rem;
}
.cart-breadcrumb a { color: var(--primary); text-decoration: none; font-weight: 500; }
.cart-breadcrumb a:hover { text-decoration: underline; }

/* ─── CART HEADER ────────────────────────────────────────────── */
.cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 2rem;
}
.cart-header h1 {
    font-family: var(--font-display);
    font-size: clamp(1.6rem, 4vw, 2.2rem);
    font-weight: 400;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: .75rem;
}
.cart-header h1 i { color: var(--primary); }
.cart-count-badge {
    background: var(--primary);
    color: #fff;
    font-size: .8rem;
    font-family: var(--font-primary);
    font-weight: 600;
    padding: .15rem .7rem;
    border-radius: 20px;
}
.cart-header .continue-shopping {
    color: var(--text-muted);
    text-decoration: none;
    font-size: .85rem;
    font-weight: 500;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: .4rem;
}
.cart-header .continue-shopping:hover { color: var(--primary); }

/* ─── CART LAYOUT ────────────────────────────────────────────── */
.cart-layout {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 2rem;
    align-items: start;
}

/* ─── CART ITEMS ──────────────────────────────────────────────── */
.cart-items-section {
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.cart-items-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: .5rem;
}
.cart-items-header h2 {
    font-family: var(--font-display);
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--dark);
}
.btn-clear-cart {
    background: none;
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: .35rem 1rem;
    font-size: .75rem;
    font-weight: 600;
    color: var(--red);
    cursor: pointer;
    transition: var(--transition);
    font-family: var(--font-primary);
}
.btn-clear-cart:hover {
    background: var(--red-lt);
    border-color: var(--red);
}

/* ─── CART ITEM ────────────────────────────────────────────────── */
.cart-item {
    display: grid;
    grid-template-columns: 120px 1fr auto;
    gap: 1.25rem;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #f3f4f6;
    transition: var(--transition);
    align-items: center;
}
.cart-item:hover { background: #fafbfc; }
.cart-item:last-child { border-bottom: none; }
.cart-item.removing {
    opacity: .4;
    transform: translateX(30px);
    pointer-events: none;
    transition: all 0.3s ease;
}

/* Item Image */
.cart-item-image {
    width: 120px;
    height: 120px;
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: #f5f5f5;
    flex-shrink: 0;
    border: 1px solid var(--border);
}
.cart-item-image a {
    display: block;
    width: 100%;
    height: 100%;
}
.cart-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
.cart-item-image a:hover img { transform: scale(1.05); }
.cart-item-image .img-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: #ddd;
    background: #f5f5f5;
}

/* Item Details */
.cart-item-details {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}
.cart-item-name {
    font-family: var(--font-display);
    font-size: .95rem;
    font-weight: 500;
    color: var(--dark);
    text-decoration: none;
    margin-bottom: .1rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    transition: color 0.2s;
}
.cart-item-name:hover { color: var(--primary); }

.cart-item-meta {
    display: flex;
    flex-wrap: wrap;
    gap: .25rem .6rem;
    font-size: .7rem;
    color: var(--text-muted);
    margin-bottom: .2rem;
}
.cart-item-meta span {
    display: flex;
    align-items: center;
    gap: .2rem;
}
.cart-item-meta i { font-size: .6rem; }
.cart-item-meta .express-tag {
    color: var(--gold-dark);
    background: #fef3c7;
    padding: .05rem .4rem;
    border-radius: 4px;
    font-weight: 600;
}
.cart-item-meta .wholesale-tag {
    color: var(--purple);
    background: var(--purple-lt);
    padding: .05rem .4rem;
    border-radius: 4px;
    font-weight: 600;
}

.cart-item-pricing {
    display: flex;
    align-items: baseline;
    gap: .5rem;
    flex-wrap: wrap;
}
.cart-item-price {
    font-size: 1rem;
    font-weight: 700;
    color: var(--dark);
}
.cart-item-original-price {
    font-size: .75rem;
    color: var(--text-muted);
    text-decoration: line-through;
}
.cart-item-discount-badge {
    font-size: .6rem;
    font-weight: 700;
    color: #059669;
    background: #d1fae5;
    padding: .05rem .4rem;
    border-radius: 4px;
}
.cart-item-promo-badge {
    font-size: .6rem;
    font-weight: 700;
    color: var(--gold-dark);
    background: var(--gold-lt);
    padding: .05rem .4rem;
    border-radius: 4px;
}

.stock-warning {
    margin-top: .2rem;
    font-size: .7rem;
    display: flex;
    align-items: center;
    gap: .2rem;
}
.stock-warning.warning { color: var(--amber); }
.stock-warning.error { color: var(--red); }

/* Item Actions */
.cart-item-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
    gap: .6rem;
    flex-shrink: 0;
}
.cart-item-total {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--primary);
    white-space: nowrap;
}

/* Quantity Control */
.quantity-control {
    display: flex;
    align-items: center;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-full);
    overflow: hidden;
    background: var(--white);
}
.qty-btn {
    width: 34px;
    height: 34px;
    border: none;
    background: var(--white);
    color: var(--text-secondary);
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-primary);
}
.qty-btn:hover:not(:disabled) {
    background: var(--primary);
    color: #fff;
}
.qty-btn:disabled {
    opacity: .3;
    cursor: not-allowed;
}
.qty-input {
    width: 44px;
    height: 34px;
    border: none;
    border-left: 1.5px solid var(--border);
    border-right: 1.5px solid var(--border);
    text-align: center;
    font-size: .85rem;
    font-weight: 600;
    color: var(--dark);
    font-family: var(--font-primary);
    background: var(--white);
    -moz-appearance: textfield;
}
.qty-input::-webkit-outer-spin-button,
.qty-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.btn-remove, .btn-save-later {
    background: none;
    border: none;
    cursor: pointer;
    font-size: .7rem;
    padding: .2rem .4rem;
    border-radius: 6px;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: .2rem;
    font-family: var(--font-primary);
    font-weight: 500;
}
.btn-remove { color: var(--red); }
.btn-remove:hover { background: var(--red-lt); }
.btn-save-later { color: var(--text-muted); }
.btn-save-later:hover { background: #f3f4f6; color: var(--dark); }

/* ─── DELIVERY ZONE SELECTOR ──────────────────────────────────── */
.delivery-zone-selector {
    background: #f0faf4;
    border-radius: var(--radius-sm);
    padding: .75rem 1rem;
    margin: .5rem 0 .75rem;
    border: 1px solid #d1fae5;
}
.delivery-zone-selector .zone-label {
    font-size: .7rem;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    gap: .3rem;
    margin-bottom: .3rem;
}
.delivery-zone-selector .zone-label i { color: var(--primary); }
.delivery-zone-selector select {
    width: 100%;
    padding: .45rem .7rem;
    border: 1px solid #d1fae5;
    border-radius: var(--radius-sm);
    font-family: var(--font-primary);
    font-size: .8rem;
    background: white;
    color: var(--dark);
    cursor: pointer;
    outline: none;
    transition: var(--transition);
}
.delivery-zone-selector select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.delivery-zone-selector .zone-hint {
    font-size: .65rem;
    color: var(--text-muted);
    margin-top: .2rem;
}

/* ─── CART SUMMARY ────────────────────────────────────────────── */
.cart-summary { position: sticky; top: 90px; }
.summary-card {
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    padding: 1.5rem;
}
.summary-card h3 {
    font-family: var(--font-display);
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    padding-bottom: .75rem;
    border-bottom: 1px solid var(--border);
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .5rem 0;
    font-size: .85rem;
    color: var(--text-secondary);
}
.summary-row .delivery-detail {
    font-size: .65rem;
    color: var(--text-muted);
    font-weight: 400;
}
.summary-row.total {
    border-top: 2px solid var(--border);
    margin-top: .5rem;
    padding-top: .75rem;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--dark);
}
.summary-row.total .amount {
    color: var(--primary);
    font-size: 1.3rem;
}

/* Coupon Section */
.coupon-section { margin: .75rem 0; }
.coupon-input-group {
    display: flex;
    gap: .5rem;
}
.coupon-input {
    flex: 1;
    padding: .5rem .8rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-full);
    font-size: .8rem;
    font-family: var(--font-primary);
    outline: none;
    transition: var(--transition);
}
.coupon-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.btn-coupon {
    padding: .5rem 1rem;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: var(--radius-full);
    font-size: .75rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    font-family: var(--font-primary);
    white-space: nowrap;
}
.btn-coupon:hover { background: var(--primary-dark); }
.coupon-applied {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .4rem .7rem;
    background: #d1fae5;
    border-radius: var(--radius-sm);
    font-size: .75rem;
    color: #065f46;
    font-weight: 500;
}
.coupon-applied button {
    background: none;
    border: none;
    color: var(--red);
    cursor: pointer;
    font-weight: 600;
    font-size: .7rem;
}

/* Checkout Button */
.btn-checkout {
    width: 100%;
    padding: .85rem;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: var(--radius-full);
    font-size: .9rem;
    font-weight: 700;
    cursor: pointer;
    transition: var(--transition);
    font-family: var(--font-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    text-decoration: none;
    margin-top: 1rem;
}
.btn-checkout:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 20px var(--primary-glow);
}
.btn-checkout:disabled {
    background: #d1d5db;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-continue {
    display: block;
    text-align: center;
    padding: .6rem;
    color: var(--text-muted);
    text-decoration: none;
    font-weight: 500;
    font-size: .8rem;
    margin-top: .5rem;
    transition: var(--transition);
}
.btn-continue:hover { color: var(--primary); }

.secure-badge {
    text-align: center;
    margin-top: .75rem;
    font-size: .7rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
}
.secure-badge i { color: #10b981; }

.payment-methods {
    text-align: center;
    margin-top: .75rem;
    padding-top: .75rem;
    border-top: 1px solid var(--border);
}
.payment-methods p {
    font-size: .7rem;
    color: var(--text-muted);
    margin-bottom: .3rem;
}
.payment-methods .methods {
    display: flex;
    justify-content: center;
    gap: .3rem;
    flex-wrap: wrap;
}
.payment-methods .method {
    background: var(--off-white);
    padding: .15rem .5rem;
    border-radius: 4px;
    font-size: .65rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: .2rem;
    color: var(--text-secondary);
}

/* ─── EMPTY CART ───────────────────────────────────────────────── */
.empty-cart {
    text-align: center;
    padding: 4rem 2rem;
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
}
.empty-cart-icon {
    font-size: 4rem;
    color: #d1d5db;
    margin-bottom: 1rem;
}
.empty-cart h2 {
    font-family: var(--font-display);
    font-size: 1.5rem;
    font-weight: 400;
    margin-bottom: .5rem;
}
.empty-cart p {
    color: var(--text-secondary);
    font-size: .88rem;
    margin-bottom: 1.5rem;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}
.btn-start-shopping {
    display: inline-block;
    padding: .75rem 2rem;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    border-radius: var(--radius-full);
    font-weight: 600;
    font-size: .88rem;
    transition: var(--transition);
}
.btn-start-shopping:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
}

/* ─── TOAST ────────────────────────────────────────────────────── */
.toast-container {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    pointer-events: none;
    max-width: calc(100vw - 3rem);
}
.toast {
    background: var(--white);
    padding: .75rem 1rem;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-xl);
    display: flex;
    align-items: center;
    gap: .5rem;
    font-weight: 600;
    font-size: .82rem;
    pointer-events: auto;
    animation: toastIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    border-left: 4px solid var(--primary);
}
.toast.error { border-left-color: var(--red); }
.toast.warning { border-left-color: var(--amber); }
@keyframes toastIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* ─── LOADING OVERLAY ──────────────────────────────────────────── */
.loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(255,255,255,.7);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 10000;
    backdrop-filter: blur(4px);
}
.loading-overlay.active { display: flex; }
.loading-spinner {
    width: 44px;
    height: 44px;
    border: 4px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ─── RESPONSIVE ──────────────────────────────────────────────── */
@media (max-width: 992px) {
    .cart-layout { grid-template-columns: 1fr; }
    .cart-summary { position: static; }
}

@media (max-width: 768px) {
    .cart-page { padding: 1rem 0 3rem; }
    .cart-container { padding: 0 1rem; }
    .cart-header h1 { font-size: 1.4rem; }
    
    .cart-item {
        grid-template-columns: 90px 1fr;
        gap: .75rem;
        padding: .85rem;
    }
    .cart-item-image {
        width: 90px;
        height: 90px;
    }
    .cart-item-actions {
        grid-column: 1 / -1;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        margin-top: .5rem;
        padding-top: .5rem;
        border-top: 1px solid #f3f4f6;
        flex-wrap: wrap;
        gap: .5rem;
    }
    .cart-item-name { font-size: .85rem; }
    .cart-item-price { font-size: .9rem; }
    .cart-item-total { font-size: .95rem; }
    .summary-card { padding: 1rem; }
}

@media (max-width: 480px) {
    .cart-item {
        grid-template-columns: 70px 1fr;
        padding: .7rem;
        gap: .6rem;
    }
    .cart-item-image {
        width: 70px;
        height: 70px;
    }
    .qty-btn { width: 28px; height: 28px; font-size: .85rem; }
    .qty-input { width: 36px; height: 28px; font-size: .75rem; }
    .cart-item-meta { font-size: .6rem; }
    .cart-item-name { font-size: .78rem; }
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

<!-- ─── CART PAGE ───────────────────────────────────────────────── -->
<div class="cart-page">
    <div class="cart-container">
        
        <!-- Breadcrumb -->
        <div class="cart-breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Home</a>
            <span>›</span>
            <a href="shop.php">Shop</a>
            <span>›</span>
            <span>Shopping Cart</span>
        </div>
        
        <!-- Cart Header -->
        <div class="cart-header">
            <h1>
                <i class="fas fa-shopping-cart"></i> Your Cart
                <?php if ($cartData['total_items'] > 0): ?>
                <span class="cart-count-badge" id="cartCountBadge"><?php echo $cartData['total_items']; ?></span>
                <?php endif; ?>
            </h1>
            <a href="shop.php" class="continue-shopping">
                <i class="fas fa-arrow-left"></i> Continue Shopping
            </a>
        </div>
        
        <?php if (empty($_SESSION['cart'])): ?>
            <!-- Empty Cart -->
            <div class="empty-cart">
                <div class="empty-cart-icon"><i class="fas fa-shopping-cart"></i></div>
                <h2>Your cart is empty</h2>
                <p>Looks like you haven't added anything yet. Browse our collection and find great deals!</p>
                <a href="shop.php" class="btn-start-shopping">
                    <i class="fas fa-store"></i> Start Shopping
                </a>
            </div>
        <?php else: ?>
            <div class="cart-layout">
                
                <!-- ─── CART ITEMS ────────────────────────────────── -->
                <div class="cart-items-section" id="cartItemsSection">
                    <div class="cart-items-header">
                        <h2>Cart Items (<?php echo count($_SESSION['cart']); ?>)</h2>
                        <button class="btn-clear-cart" onclick="clearCart()">
                            <i class="fas fa-trash-alt"></i> Clear All
                        </button>
                    </div>
                    
                    <?php 
                    $conn = getConnection();
                    
                    foreach ($_SESSION['cart'] as $key => $item):
                        $product_id = (int)($item['product_id'] ?? 0);
                        $pricing_tier_id = (int)($item['pricing_tier_id'] ?? 0);
                        $quantity = (int)($item['quantity'] ?? 1);
                        $cart_price = (float)($item['price'] ?? 0);
                        $original_price = (float)($item['original_price'] ?? $cart_price);
                        $item_name = $item['name'] ?? 'Product';
                        $item_slug = $item['slug'] ?? '';
                        $item_image = $item['image'] ?? '';
                        $tier_name = $item['tier_name'] ?? '';
                        $pricing_type = $item['pricing_type'] ?? 'retail';
                        $is_express = isset($item['is_express']) ? $item['is_express'] : false;
                        
                        $brand = '';
                        $vendor_name = '';
                        $available_stock = 999;
                        $promo_name = '';
                        
                        try {
                            $product_sql = "
                                SELECT 
                                    p.brand, p.sku,
                                    v.business_name as vendor_name, v.is_express,
                                    COALESCE(pi_inv.stock_quantity, 0) as stock_quantity,
                                    COALESCE(pi_inv.reserved_quantity, 0) as reserved_quantity,
                                    active_promos.promotion_name
                                FROM products p
                                LEFT JOIN vendors v ON p.vendor_id = v.id
                                LEFT JOIN product_inventory pi_inv ON ? = pi_inv.pricing_tier_id
                                LEFT JOIN (
                                    SELECT pp.product_id, prom.name as promotion_name
                                    FROM product_promotions pp
                                    INNER JOIN promotions prom ON pp.promotion_id = prom.id
                                    WHERE prom.is_active = 1 AND prom.start_date <= CURDATE() AND prom.end_date >= CURDATE()
                                ) active_promos ON p.id = active_promos.product_id
                                WHERE p.id = ? AND p.is_active = 1
                                LIMIT 1
                            ";
                            $product_stmt = $conn->prepare($product_sql);
                            $product_stmt->bind_param('ii', $pricing_tier_id, $product_id);
                            $product_stmt->execute();
                            $product_data = $product_stmt->get_result()->fetch_assoc();
                            $product_stmt->close();
                            
                            if ($product_data) {
                                $brand = $product_data['brand'] ?? '';
                                $vendor_name = $product_data['vendor_name'] ?? '';
                                $is_express = (bool)($product_data['is_express'] ?? false);
                                $available_stock = max(0, (int)($product_data['stock_quantity'] ?? 0) - (int)($product_data['reserved_quantity'] ?? 0));
                                $promo_name = $product_data['promotion_name'] ?? '';
                            }
                        } catch (Exception $e) {
                            // Use defaults
                        }
                        
                        $has_discount = $cart_price < $original_price && $original_price > 0;
                        $has_promo = !empty($promo_name);
                        $item_total = $cart_price * $quantity;
                        $img_url = getCartImageUrl($item_image);
                        $disc_pct = $has_discount ? round((($original_price - $cart_price) / $original_price) * 100) : 0;
                        
                        // Build product detail URL
                        $detail_url = 'product-detail.php?slug=' . urlencode($item_slug);
                        if (empty($item_slug)) {
                            $detail_url = 'product-detail.php?id=' . $product_id;
                        }
                    ?>
                    
                    <div class="cart-item" id="cartItem-<?php echo $product_id; ?>" data-product-id="<?php echo $product_id; ?>" data-tier-id="<?php echo $pricing_tier_id; ?>" data-cart-key="<?php echo htmlspecialchars($key); ?>">
                        
                        <!-- Image -->
                        <div class="cart-item-image">
                            <a href="<?php echo $detail_url; ?>">
                                <?php if (!empty($img_url) && strpos($img_url, 'placeholder') === false): ?>
                                    <img src="<?php echo htmlspecialchars($img_url); ?>" 
                                         alt="<?php echo htmlspecialchars($item_name); ?>" 
                                         loading="lazy"
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="img-placeholder" style="display:none;"><i class="fas fa-box-open"></i></div>
                                <?php else: ?>
                                    <div class="img-placeholder"><i class="fas fa-box-open"></i></div>
                                <?php endif; ?>
                            </a>
                        </div>
                        
                        <!-- Details -->
                        <div class="cart-item-details">
                            <a href="<?php echo $detail_url; ?>" class="cart-item-name">
                                <?php echo htmlspecialchars($item_name); ?>
                            </a>
                            
                            <div class="cart-item-meta">
                                <?php if (!empty($brand)): ?>
                                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($brand); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($tier_name)): ?>
                                    <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($tier_name); ?></span>
                                <?php endif; ?>
                                <?php if ($pricing_type === 'wholesale'): ?>
                                    <span class="wholesale-tag"><i class="fas fa-boxes"></i> Wholesale</span>
                                <?php endif; ?>
                                <?php if ($is_express): ?>
                                    <span class="express-tag"><i class="fas fa-rocket"></i> Express</span>
                                <?php endif; ?>
                                <?php if (!empty($vendor_name) && $vendor_name !== 'Bless Mall'): ?>
                                    <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($vendor_name); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="cart-item-pricing">
                                <span class="cart-item-price"><?php echo _bm_fmt($cart_price); ?></span>
                                <?php if ($has_discount): ?>
                                    <span class="cart-item-original-price"><?php echo _bm_fmt($original_price); ?></span>
                                    <span class="cart-item-discount-badge">-<?php echo $disc_pct; ?>%</span>
                                <?php endif; ?>
                                <?php if ($has_promo): ?>
                                    <span class="cart-item-promo-badge">
                                        <i class="fas fa-ticket-alt"></i> <?php echo htmlspecialchars($promo_name); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($available_stock <= 10 && $available_stock > 0): ?>
                                <div class="stock-warning warning">
                                    <i class="fas fa-exclamation-circle"></i> Only <?php echo $available_stock; ?> left
                                </div>
                            <?php elseif ($available_stock <= 0): ?>
                                <div class="stock-warning error">
                                    <i class="fas fa-times-circle"></i> Out of stock
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Actions -->
                        <div class="cart-item-actions">
                            <span class="cart-item-total" id="itemTotal-<?php echo $product_id; ?>"><?php echo _bm_fmt($item_total); ?></span>
                            
                            <div class="quantity-control">
                                <button class="qty-btn" onclick="updateCartQty(<?php echo $product_id; ?>, <?php echo $pricing_tier_id; ?>, -1, <?php echo $available_stock; ?>)"
                                        <?php echo $quantity <= 1 ? 'disabled' : ''; ?> aria-label="Decrease quantity">−</button>
                                <input type="text" class="qty-input" id="qtyInput-<?php echo $product_id; ?>"
                                       value="<?php echo $quantity; ?>" readonly aria-label="Quantity">
                                <button class="qty-btn" onclick="updateCartQty(<?php echo $product_id; ?>, <?php echo $pricing_tier_id; ?>, 1, <?php echo $available_stock; ?>)"
                                        <?php echo ($available_stock > 0 && $quantity >= $available_stock) ? 'disabled' : ''; ?> aria-label="Increase quantity">+</button>
                            </div>
                            
                            <button class="btn-remove" onclick="removeFromCart(<?php echo $product_id; ?>, <?php echo $pricing_tier_id; ?>)">
                                <i class="fas fa-trash-alt"></i> Remove
                            </button>
                            
                            <button class="btn-save-later" onclick="saveForLater(<?php echo $product_id; ?>)">
                                <i class="far fa-heart"></i> Save for later
                            </button>
                        </div>
                    </div>
                    
                    <?php endforeach; ?>
                </div>
                
                <!-- ─── CART SUMMARY ───────────────────────────────── -->
                <div class="cart-summary">
                    <div class="summary-card" id="summaryCard">
                        <h3>Order Summary</h3>
                        
                        <div class="summary-row">
                            <span>Subtotal (<?php echo $cartData['total_items']; ?> items)</span>
                            <span id="summarySubtotal"><?php echo _bm_fmt($cartData['subtotal']); ?></span>
                        </div>
                        
                        <?php if ($cartData['discount_amount'] > 0): ?>
                        <div class="summary-row" style="color: #059669;">
                            <span>Discount</span>
                            <span id="summaryDiscount">-<?php echo _bm_fmt($cartData['discount_amount']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Delivery Zone Selector -->
                        <div class="delivery-zone-selector">
                            <div class="zone-label">
                                <i class="fas fa-map-pin"></i> Delivery Location
                            </div>
                            <select id="deliveryZone" onchange="updateDeliveryZone(this.value)">
                                <option value="legon_campus" <?php echo ($_SESSION['delivery_zone'] ?? 'legon_campus') === 'legon_campus' ? 'selected' : ''; ?>>
                                    🏫 UG Legon Campus
                                </option>
                                <option value="legon_adjacent" <?php echo ($_SESSION['delivery_zone'] ?? '') === 'legon_adjacent' ? 'selected' : ''; ?>>
                                    🏘️ Legon Adjacent (+GH₵ 10)
                                </option>
                                <option value="accra_central" <?php echo ($_SESSION['delivery_zone'] ?? '') === 'accra_central' ? 'selected' : ''; ?>>
                                    🏙️ Central Accra (+GH₵ 20)
                                </option>
                                <option value="accra_suburbs" <?php echo ($_SESSION['delivery_zone'] ?? '') === 'accra_suburbs' ? 'selected' : ''; ?>>
                                    🏠 Accra Suburbs (+GH₵ 30)
                                </option>
                                <option value="accra_outskirts" <?php echo ($_SESSION['delivery_zone'] ?? '') === 'accra_outskirts' ? 'selected' : ''; ?>>
                                    🌳 Accra Outskirts (+GH₵ 50)
                                </option>
                            </select>
                            <div class="zone-hint">
                                <i class="fas fa-info-circle"></i> 
                                <?php 
                                $zone = $_SESSION['delivery_zone'] ?? 'legon_campus';
                                $zone_names = [
                                    'legon_campus' => 'Free pickup/drop at UG Legon',
                                    'legon_adjacent' => 'Areas around Legon',
                                    'accra_central' => 'Central Accra CBD',
                                    'accra_suburbs' => 'Residential suburbs',
                                    'accra_outskirts' => 'Outskirts of Accra'
                                ];
                                echo $zone_names[$zone] ?? 'Select your location';
                                ?>
                            </div>
                        </div>
                        
                        <div class="summary-row">
                            <span>
                                Delivery Fee
                                <?php if ($cartData['delivery_breakdown']['has_express']): ?>
                                    <span class="delivery-detail">(Express included)</span>
                                <?php endif; ?>
                            </span>
                            <span id="summaryDelivery"><?php echo _bm_fmt($cartData['delivery_fee']); ?></span>
                        </div>
                        
                        <!-- Delivery Breakdown -->
                        <?php if ($cartData['delivery_fee'] > 0): ?>
                        <div style="font-size:.65rem;color:var(--text-muted);padding:.2rem 0 .4rem;border-bottom:1px solid var(--border);">
                            <div style="display:flex;justify-content:space-between;">
                                <span>Base fee</span>
                                <span><?php echo _bm_fmt($cartData['delivery_breakdown']['base_fee']); ?></span>
                            </div>
                            <?php if ($cartData['delivery_breakdown']['zone_surcharge'] > 0): ?>
                            <div style="display:flex;justify-content:space-between;">
                                <span>Zone surcharge</span>
                                <span>+<?php echo _bm_fmt($cartData['delivery_breakdown']['zone_surcharge']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($cartData['delivery_breakdown']['express_surcharge'] > 0): ?>
                            <div style="display:flex;justify-content:space-between;">
                                <span>Express surcharge</span>
                                <span>+<?php echo _bm_fmt($cartData['delivery_breakdown']['express_surcharge']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Coupon Section -->
                        <div class="coupon-section">
                            <?php if (isset($_SESSION['applied_coupon'])): ?>
                                <div class="coupon-applied" id="couponApplied">
                                    <span><i class="fas fa-check-circle"></i> <strong><?php echo htmlspecialchars($_SESSION['applied_coupon']['name']); ?></strong> applied</span>
                                    <button onclick="removeCoupon()"><i class="fas fa-times"></i> Remove</button>
                                </div>
                            <?php else: ?>
                                <div class="coupon-input-group">
                                    <input type="text" class="coupon-input" id="couponCode" 
                                           placeholder="Enter coupon code" aria-label="Coupon code">
                                    <button class="btn-coupon" onclick="applyCoupon()">Apply</button>
                                </div>
                                <div id="couponMessage" style="font-size:.7rem;margin-top:.25rem;color:var(--red);"></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="summary-row total">
                            <span>Total</span>
                            <span class="amount" id="summaryTotal"><?php echo _bm_fmt($cartData['total']); ?></span>
                        </div>
                        
                        <a href="checkout.php" class="btn-checkout" id="btnCheckout" <?php echo $cartData['total_items'] === 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-lock"></i> Proceed to Checkout
                            <?php if ($cartData['total_items'] > 0): ?>
                            <span style="font-weight:400;font-size:.75rem;">(<?php echo $cartData['total_items']; ?> items)</span>
                            <?php endif; ?>
                        </a>
                        
                        <a href="shop.php" class="btn-continue">
                            <i class="fas fa-arrow-left"></i> Continue Shopping
                        </a>
                        
                        <div class="secure-badge">
                            <i class="fas fa-shield-alt"></i> Secure SSL Checkout
                        </div>
                        
                        <div class="payment-methods">
                            <p>We Accept</p>
                            <div class="methods">
                                <span class="method"><i class="fas fa-mobile-alt"></i> MoMo</span>
                                <span class="method"><i class="fas fa-credit-card"></i> Card</span>
                                <span class="method"><i class="fas fa-money-bill-wave"></i> Cash</span>
                                <span class="method"><i class="fas fa-university"></i> Bank</span>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        <?php endif; ?>
        
    </div>
</div>

<!-- ─── TOAST CONTAINER ────────────────────────────────────────── -->
<div class="toast-container" id="toastContainer"></div>

<!-- ─── LOADING OVERLAY ────────────────────────────────────────── -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
</div>

<!-- ─── SCRIPTS ────────────────────────────────────────────────── -->
<script>
// ═══════════════════════════════════════════════════════════════════
// CART SCRIPTS v10 — Premium
// ═══════════════════════════════════════════════════════════════════
var cartCsrfToken = <?php echo json_encode(CSRFToken::generate()); ?>;

// ═══ TOAST ═══
function showToast(message, type) {
    type = type || 'success';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast' + (type === 'error' ? ' error' : '') + (type === 'warning' ? ' warning' : '');
    var icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    toast.innerHTML = (icons[type] || '✅') + ' ' + message;
    container.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(120%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300);
    }, 4000);
}

// ═══ LOADING ═══
function showLoading() { document.getElementById('loadingOverlay').classList.add('active'); }
function hideLoading() { document.getElementById('loadingOverlay').classList.remove('active'); }

// ═══ UPDATE CART BADGE ═══
function updateCartBadge(count) {
    var badge = document.getElementById('cartCount');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }
    var pageBadge = document.getElementById('cartCountBadge');
    if (pageBadge) pageBadge.textContent = count;
}

// ═══ UPDATE DELIVERY ZONE ═══
function updateDeliveryZone(zone) {
    showLoading();
    var data = new URLSearchParams();
    data.append('action', 'update_delivery_zone');
    data.append('csrf_token', cartCsrfToken);
    data.append('zone', zone);
    
    fetch('cart.php', {
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
            updateSummary(data);
            showToast('Delivery location updated!', 'success');
            var hints = {
                'legon_campus': 'Free pickup/drop at UG Legon',
                'legon_adjacent': 'Areas around Legon',
                'accra_central': 'Central Accra CBD',
                'accra_suburbs': 'Residential suburbs',
                'accra_outskirts': 'Outskirts of Accra'
            };
            var hintEl = document.querySelector('.zone-hint');
            if (hintEl && hints[zone]) {
                hintEl.innerHTML = '<i class="fas fa-info-circle"></i> ' + hints[zone];
            }
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() { hideLoading(); });
}

// ═══ UPDATE CART QUANTITY ═══
function updateCartQty(productId, pricingTierId, change, maxStock) {
    var input = document.getElementById('qtyInput-' + productId);
    if (!input) return;
    
    var currentQty = parseInt(input.value) || 1;
    var newQty = currentQty + change;
    if (newQty < 1) newQty = 1;
    if (maxStock > 0 && newQty > maxStock) {
        showToast('Maximum available stock: ' + maxStock, 'warning');
        newQty = maxStock;
    }
    
    var cartItem = document.getElementById('cartItem-' + productId);
    if (cartItem) cartItem.style.opacity = '0.6';
    
    var data = new URLSearchParams();
    data.append('action', 'update_cart_quantity');
    data.append('csrf_token', cartCsrfToken);
    data.append('product_id', productId);
    data.append('pricing_tier_id', pricingTierId);
    data.append('quantity', newQty);
    
    fetch('cart.php', {
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
            input.value = data.quantity;
            var itemTotalEl = document.getElementById('itemTotal-' + productId);
            if (itemTotalEl) itemTotalEl.textContent = data.item_total;
            
            var minusBtn = cartItem.querySelector('.qty-btn:first-child');
            var plusBtn = cartItem.querySelector('.qty-btn:last-child');
            if (minusBtn) minusBtn.disabled = data.quantity <= 1;
            if (plusBtn) plusBtn.disabled = (maxStock > 0 && data.quantity >= maxStock);
            
            updateSummary(data);
            updateCartBadge(data.cart_count);
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() {
        if (cartItem) cartItem.style.opacity = '1';
    });
}

// ═══ REMOVE FROM CART ═══
function removeFromCart(productId, pricingTierId) {
    if (!confirm('Remove this item from your cart?')) return;
    
    var cartItem = document.getElementById('cartItem-' + productId);
    if (cartItem) cartItem.classList.add('removing');
    showLoading();
    
    var data = new URLSearchParams();
    data.append('action', 'remove_from_cart');
    data.append('csrf_token', cartCsrfToken);
    data.append('product_id', productId);
    data.append('pricing_tier_id', pricingTierId);
    data.append('cart_key', cartItem ? (cartItem.dataset.cartKey || '') : '');
    
    fetch('cart.php', {
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
            if (data.is_empty) { location.reload(); return; }
            if (cartItem) {
                cartItem.style.transition = 'all 0.35s ease';
                cartItem.style.maxHeight = cartItem.offsetHeight + 'px';
                requestAnimationFrame(function() {
                    cartItem.style.maxHeight = '0';
                    cartItem.style.opacity = '0';
                    cartItem.style.padding = '0';
                    cartItem.style.margin = '0';
                    cartItem.style.border = 'none';
                });
                setTimeout(function() { if (cartItem.parentNode) cartItem.remove(); }, 380);
            }
            updateSummary(data);
            updateCartBadge(data.cart_count);
            showToast('Item removed from cart', 'success');
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() { hideLoading(); });
}

// ═══ CLEAR CART ═══
function clearCart() {
    if (!confirm('Clear your entire cart?')) return;
    showLoading();
    
    var data = new URLSearchParams();
    data.append('action', 'clear_cart');
    data.append('csrf_token', cartCsrfToken);
    
    fetch('cart.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: data.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) location.reload();
    })
    .catch(function() { showToast('Error clearing cart', 'error'); })
    .finally(function() { hideLoading(); });
}

// ═══ APPLY COUPON ═══
function applyCoupon() {
    var codeInput = document.getElementById('couponCode');
    if (!codeInput) return;
    var code = codeInput.value.trim();
    if (!code) { showToast('Enter a coupon code', 'warning'); return; }
    
    showLoading();
    var data = new URLSearchParams();
    data.append('action', 'apply_coupon');
    data.append('csrf_token', cartCsrfToken);
    data.append('coupon_code', code);
    
    fetch('cart.php', {
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
            var couponSection = document.querySelector('.coupon-section');
            if (couponSection) {
                couponSection.innerHTML = 
                    '<div class="coupon-applied" id="couponApplied">' +
                        '<span><i class="fas fa-check-circle"></i> <strong>' + data.coupon_name + '</strong> applied</span>' +
                        '<button onclick="removeCoupon()"><i class="fas fa-times"></i> Remove</button>' +
                    '</div>';
            }
            updateSummary(data);
            showToast('Coupon applied!', 'success');
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() { hideLoading(); });
}

// ═══ REMOVE COUPON ═══
function removeCoupon() {
    showLoading();
    var data = new URLSearchParams();
    data.append('action', 'remove_coupon');
    data.append('csrf_token', cartCsrfToken);
    
    fetch('cart.php', {
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
            var couponSection = document.querySelector('.coupon-section');
            if (couponSection) {
                couponSection.innerHTML = 
                    '<div class="coupon-input-group">' +
                        '<input type="text" class="coupon-input" id="couponCode" placeholder="Enter coupon code">' +
                        '<button class="btn-coupon" onclick="applyCoupon()">Apply</button>' +
                    '</div>' +
                    '<div id="couponMessage" style="font-size:.7rem;margin-top:.25rem;color:var(--red);"></div>';
            }
            updateSummary(data);
            showToast('Coupon removed', 'info');
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() { hideLoading(); });
}

// ═══ SAVE FOR LATER ═══
function saveForLater(productId) {
    <?php if (Auth::isLoggedIn()): ?>
    var data = new URLSearchParams();
    data.append('action', 'save_for_later');
    data.append('csrf_token', cartCsrfToken);
    data.append('product_id', productId);
    
    fetch('cart.php', {
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
            showToast(data.message || 'Saved to wishlist! ❤️', 'success');
        } else if (data.redirect) {
            window.location.href = data.redirect;
        }
    })
    .catch(function() { showToast('Connection error', 'error'); });
    <?php else: ?>
    window.location.href = 'login.php?redirect=cart.php';
    <?php endif; ?>
}

// ═══ UPDATE SUMMARY ═══
function updateSummary(data) {
    var el = document.getElementById('summarySubtotal');
    if (el && data.subtotal) el.textContent = data.subtotal;
    
    var discRow = document.getElementById('summaryDiscount');
    if (discRow && discRow.parentElement) {
        if (data.discount_amount_raw && data.discount_amount_raw > 0) {
            discRow.textContent = '-GH₵ ' + parseFloat(data.discount_amount_raw).toFixed(2);
            discRow.parentElement.style.display = '';
        } else {
            discRow.parentElement.style.display = 'none';
        }
    }
    
    var delEl = document.getElementById('summaryDelivery');
    if (delEl && data.delivery_fee) delEl.textContent = data.delivery_fee;
    
    var totalEl = document.getElementById('summaryTotal');
    if (totalEl && data.total) totalEl.textContent = data.total;
    
    // Update checkout button
    var checkoutBtn = document.getElementById('btnCheckout');
    if (checkoutBtn) {
        if (data.cart_count > 0) {
            checkoutBtn.disabled = false;
            checkoutBtn.innerHTML = '<i class="fas fa-lock"></i> Proceed to Checkout <span style="font-weight:400;font-size:.75rem;">(' + data.cart_count + ' items)</span>';
        } else {
            checkoutBtn.disabled = true;
            checkoutBtn.innerHTML = '<i class="fas fa-lock"></i> Cart is empty';
        }
    }
}

// ═══ INIT ═══
document.addEventListener('DOMContentLoaded', function() {
    updateCartBadge(<?php echo $cartData['total_items']; ?>);
});

console.log('%c🛒 Bless Mall Cart %cv10 - Premium',
    'font-size:1.2em;font-weight:bold;color:#0B6E3D;',
    'font-size:1em;color:#C9A227;');
console.log('%cProduct images now display correctly with same logic as shop.php', 'font-style:italic;color:#4A665C;');
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>