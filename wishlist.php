<?php
/**
 * Bless Mall - Wishlist Page
 * User's saved products with full pricing tiers and image support
 * Matches the functionality of index.php
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration
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

// Redirect if not logged in
if (!Auth::isLoggedIn()) {
    $_SESSION['redirect_after_login'] = 'wishlist.php';
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$conn = getConnection();

// Handle remove from wishlist
if (isset($_GET['remove']) && is_numeric($_GET['remove'])) {
    $product_id = (int)$_GET['remove'];
    $delete_query = "DELETE FROM wishlist WHERE user_id = ? AND product_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("ii", $user_id, $product_id);
    if ($stmt->execute()) {
        $_SESSION['wishlist_message'] = 'Product removed from wishlist';
        $_SESSION['wishlist_message_type'] = 'success';
    }
    header('Location: ' . BASE_URL . 'wishlist.php');
    exit;
}

// Handle clear all wishlist
if (isset($_POST['clear_wishlist'])) {
    $delete_query = "DELETE FROM wishlist WHERE user_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $_SESSION['wishlist_message'] = 'Wishlist cleared successfully';
        $_SESSION['wishlist_message_type'] = 'success';
    }
    header('Location: ' . BASE_URL . 'wishlist.php');
    exit;
}

// ==================== BUILD CART MAP ====================
$cart_map = [];
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $key => $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $tid = (int)($item['pricing_tier_id'] ?? 0);
        if ($pid > 0) {
            if (!isset($cart_map[$pid])) {
                $cart_map[$pid] = [
                    'keys' => [],
                    'total_qty' => 0,
                    'cart_key' => $key,
                    'quantity' => (int)$item['quantity'],
                    'pricing_tier_id' => $tid
                ];
            }
            $cart_map[$pid]['total_qty'] += (int)$item['quantity'];
        }
    }
}

// ==================== FETCH WISHLIST PRODUCTS ====================
$wishlist_items = [];
$total_items = 0;
$total_value = 0;

$query = "SELECT w.product_id, w.created_at as wishlist_date
          FROM wishlist w
          WHERE w.user_id = ?
          ORDER BY w.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$wishlist_result = $stmt->get_result();

$product_ids = [];
while ($row = $wishlist_result->fetch_assoc()) {
    $product_ids[] = (int)$row['product_id'];
}
$stmt->close();

if (!empty($product_ids)) {
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $types = str_repeat('i', count($product_ids));
    
    // Fetch products with all details - same as index.php
    $product_query = "
        SELECT 
            p.id, p.name, p.slug, p.short_description, p.brand, p.featured, p.created_at,
            c.name as category_name,
            v.business_name as vendor_name, v.is_express as vendor_express, v.store_badge,
            (SELECT pi.image_path FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) as image,
            ROUND(COALESCE(AVG(r.rating), 0), 1) as avg_rating,
            COUNT(DISTINCT r.id) as review_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN vendors v ON p.vendor_id = v.id AND v.status = 'active'
        LEFT JOIN reviews r ON p.id = r.product_id
        WHERE p.id IN ($placeholders) AND p.is_active = 1
        GROUP BY p.id
        ORDER BY FIELD(p.id, $placeholders)
    ";
    
    // Combine parameters
    $params = array_merge($product_ids, $product_ids);
    $param_types = $types . $types;
    
    $stmt = $conn->prepare($product_query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $product_result = $stmt->get_result();
    
    while ($row = $product_result->fetch_assoc()) {
        $product_id = (int)$row['id'];
        
        // Get ALL pricing tiers - same as index.php
        $pricing_query = "
            SELECT 
                id, pricing_type, tier_name, 
                min_quantity, max_quantity,
                unit_price, discount_price, discount_percentage,
                is_active
            FROM product_pricing_tiers 
            WHERE product_id = ? AND is_active = 1
            ORDER BY FIELD(pricing_type, 'retail', 'wholesale'), min_quantity ASC
        ";
        
        $pricing_stmt = $conn->prepare($pricing_query);
        $pricing_stmt->bind_param("i", $product_id);
        $pricing_stmt->execute();
        $pricing_result = $pricing_stmt->get_result();
        
        $row['pricing_tiers'] = [];
        $row['best_price'] = null;
        $row['original_price'] = null;
        $row['has_discount'] = false;
        $row['discount_percent'] = 0;
        $row['has_wholesale'] = false;
        $row['default_tier_id'] = 0;
        
        while ($tier = $pricing_result->fetch_assoc()) {
            $unit_price = (float)$tier['unit_price'];
            $discount_price = !empty($tier['discount_price']) ? (float)$tier['discount_price'] : null;
            $discount_percentage = !empty($tier['discount_percentage']) ? (float)$tier['discount_percentage'] : null;
            
            $final_price = $unit_price;
            $has_discount = false;
            $discount_percent = 0;
            
            if ($discount_price && $discount_price > 0 && $discount_price < $unit_price) {
                $final_price = $discount_price;
                $has_discount = true;
                $discount_percent = round(($unit_price - $final_price) / $unit_price * 100);
            } elseif ($discount_percentage && $discount_percentage > 0) {
                $final_price = round($unit_price * (1 - $discount_percentage / 100), 2);
                $has_discount = true;
                $discount_percent = round($discount_percentage);
            }
            
            $tier['final_price'] = $final_price;
            $tier['has_discount'] = $has_discount;
            $tier['discount_percent_display'] = $discount_percent;
            
            $row['pricing_tiers'][] = $tier;
            
            // Track best retail price for display
            if ($tier['pricing_type'] === 'retail') {
                if ($row['best_price'] === null || $final_price < $row['best_price']) {
                    $row['best_price'] = $final_price;
                    $row['original_price'] = $unit_price;
                    $row['has_discount'] = $has_discount;
                    $row['discount_percent'] = $discount_percent;
                    $row['default_tier_id'] = (int)$tier['id'];
                }
            }
            
            if ($tier['pricing_type'] === 'wholesale') {
                $row['has_wholesale'] = true;
            }
        }
        $pricing_stmt->close();
        
        // Get total stock
        $stock_query = "
            SELECT SUM(pi.stock_quantity - pi.reserved_quantity) as available_stock
            FROM product_inventory pi
            JOIN product_pricing_tiers pt ON pi.pricing_tier_id = pt.id
            WHERE pt.product_id = ?
        ";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("i", $product_id);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        $stock_data = $stock_result->fetch_assoc();
        $row['total_stock'] = (int)($stock_data['available_stock'] ?? 0);
        $stock_stmt->close();
        
        // Set stock status
        if ($row['total_stock'] > 10) {
            $row['stock_status'] = 'in_stock';
        } elseif ($row['total_stock'] > 0) {
            $row['stock_status'] = 'low_stock';
        } else {
            $row['stock_status'] = 'out_of_stock';
        }
        
        // Cart info
        $row['in_cart'] = isset($cart_map[$product_id]);
        $row['cart_qty'] = $row['in_cart'] ? $cart_map[$product_id]['total_qty'] : 0;
        $row['cart_key'] = $row['in_cart'] ? $cart_map[$product_id]['cart_key'] : '';
        $row['cart_tier_id'] = $row['in_cart'] ? $cart_map[$product_id]['pricing_tier_id'] : 0;
        
        $wishlist_items[] = $row;
        
        // Calculate total value
        if ($row['best_price']) {
            $total_value += $row['best_price'];
        }
    }
    $stmt->close();
}

$total_items = count($wishlist_items);

// Page title
$page_title = "My Wishlist - " . (defined('SITE_NAME') ? SITE_NAME : 'Bless Mall');

// Helper functions
function formatPrice($price) {
    if ($price === null || $price < 0) return 'GH₵ 0.00';
    return 'GH₵ ' . number_format((float)$price, 2);
}

function getProductImageUrl($imagePath) {
    if (empty($imagePath)) {
        return BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
    }
    
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        return $imagePath;
    }
    
    $imagePath = ltrim($imagePath, '/');
    
    $uploadDir = '/assets/uploads/products/';
    $fullPath = __DIR__ . $uploadDir . $imagePath;
    if (file_exists($fullPath)) {
        return BASE_URL . $uploadDir . $imagePath;
    }
    
    $altFullPath = __DIR__ . '/uploads/products/' . $imagePath;
    if (file_exists($altFullPath)) {
        return BASE_URL . '/uploads/products/' . $imagePath;
    }
    
    if (strpos($imagePath, 'uploads/products/') === 0) {
        return BASE_URL . '/' . $imagePath;
    }
    
    if (strpos($imagePath, '/') === 0) {
        return BASE_URL . $imagePath;
    }
    
    return BASE_URL . '/' . $imagePath;
}

// Include header
require_once __DIR__ . '/header.php';
?>

<style>
/* ════════════════════════════════════════════════════════════════════
   BLESS MALL — WISHLIST PAGE Premium Styles
════════════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,900;1,400;1,500&display=swap');

:root {
    --ink: #1a1a1a;
    --ink-2: #333;
    --ink-3: #666;
    --ink-4: #999;
    --paper: #f8f7f4;
    --white: #fff;
    --line: #e5e0d8;
    --green: #0a6e3d;
    --green-dk: #085530;
    --green-lt: #e6f5ee;
    --gold: #c9a227;
    --gold-dk: #a6861f;
    --red: #dc2626;
    --red-lt: #fee2e2;
    --amber: #d97706;
    --purple: #7c3aed;
    --purple-lt: #ede9fe;
    --sh-sm: 0 1px 2px rgba(0,0,0,.06);
    --sh-md: 0 4px 12px rgba(0,0,0,.08);
    --sh-lg: 0 8px 24px rgba(0,0,0,.12);
    --sh-xl: 0 20px 40px rgba(0,0,0,.15);
    --fh: 'Playfair Display', Georgia, serif;
    --fb: 'Inter', system-ui, sans-serif;
    --radius: 12px;
    --radius-sm: 8px;
    --radius-full: 9999px;
    --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--paper); font-family: var(--fb); color: var(--ink); line-height: 1.6; }

/* ═══ WISHLIST PAGE ═══ */
.wishlist-page { padding: 2rem 0; min-height: 80vh; }
.wishlist-container { max-width: 1400px; margin: 0 auto; padding: 0 1.25rem; }

/* Breadcrumb */
.wishlist-breadcrumb {
    display: flex; align-items: center; gap: .5rem; margin-bottom: 1.5rem;
    font-size: .82rem; color: var(--ink-4); flex-wrap: wrap;
}
.wishlist-breadcrumb a { color: var(--green); text-decoration: none; font-weight: 500; }
.wishlist-breadcrumb a:hover { text-decoration: underline; }

/* Header */
.wishlist-header {
    background: var(--white); border-radius: var(--radius);
    padding: 1.5rem 2rem; margin-bottom: 2rem;
    border: 1px solid var(--line); box-shadow: var(--sh-sm);
}
.wishlist-title {
    font-family: var(--fh); font-size: 1.75rem; font-weight: 400; color: var(--ink);
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;
}
.wishlist-title h1 {
    display: flex; align-items: center; gap: .75rem; font-size: 1.75rem; font-weight: 400;
}
.wishlist-title h1 i { color: var(--red); }
.wishlist-stats {
    display: flex; gap: 2rem; margin-top: 1rem; padding-top: 1rem;
    border-top: 1px solid var(--line); flex-wrap: wrap;
}
.stat-item { display: flex; align-items: baseline; gap: .5rem; }
.stat-label { color: var(--ink-4); font-size: .85rem; }
.stat-value { font-weight: 700; font-size: 1.1rem; color: var(--ink); }

/* Action Buttons */
.wishlist-actions { display: flex; gap: .75rem; flex-wrap: wrap; }
.btn-outline {
    background: transparent; border: 1.5px solid var(--line); padding: .6rem 1.25rem;
    border-radius: var(--radius-full); font-weight: 600; cursor: pointer;
    transition: var(--transition); color: var(--ink-3); font-family: var(--fb);
    display: inline-flex; align-items: center; gap: .5rem; font-size: .85rem;
}
.btn-outline:hover { border-color: var(--green); color: var(--green); background: var(--green-lt); }
.btn-primary {
    background: var(--green); color: #fff; border: none; padding: .6rem 1.25rem;
    border-radius: var(--radius-full); font-weight: 600; cursor: pointer;
    transition: var(--transition); font-family: var(--fb);
    display: inline-flex; align-items: center; gap: .5rem; font-size: .85rem;
}
.btn-primary:hover { background: var(--green-dk); transform: translateY(-2px); box-shadow: var(--sh-md); }
.btn-danger {
    background: transparent; border: 1.5px solid var(--red-lt); color: var(--red);
    padding: .6rem 1.25rem; border-radius: var(--radius-full); font-weight: 600;
    cursor: pointer; transition: var(--transition); font-family: var(--fb);
    display: inline-flex; align-items: center; gap: .5rem; font-size: .85rem;
}
.btn-danger:hover { background: var(--red-lt); border-color: var(--red); }

/* ═══ WISHLIST GRID ═══ */
.wishlist-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem; margin-bottom: 2rem;
}

/* ═══ WISHLIST CARD ═══ */
.wishlist-card {
    background: var(--white); border-radius: var(--radius);
    overflow: hidden; border: 1px solid var(--line);
    transition: all .3s; position: relative;
}
.wishlist-card:hover { transform: translateY(-4px); box-shadow: var(--sh-lg); border-color: var(--gold); }

/* Badges */
.card-badge {
    position: absolute; top: 10px; left: 10px; z-index: 2;
    display: flex; flex-direction: column; gap: 4px;
}
.badge {
    padding: .25rem .75rem; border-radius: var(--radius-full);
    font-size: .65rem; font-weight: 700; letter-spacing: .3px; text-transform: uppercase;
}
.badge-express { background: var(--gold); color: var(--ink); display: flex; align-items: center; gap: 4px; }
.badge-discount { background: var(--red); color: #fff; }
.badge-featured { background: var(--green); color: #fff; }
.badge-wholesale { background: var(--purple); color: #fff; display: flex; align-items: center; gap: 4px; }

/* Remove Button */
.remove-btn {
    position: absolute; top: 10px; right: 10px; z-index: 3;
    width: 32px; height: 32px; border-radius: 50%;
    background: rgba(255,255,255,.95); border: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: var(--transition);
    color: var(--ink-3); box-shadow: var(--sh-sm);
}
.remove-btn:hover { background: var(--red-lt); border-color: var(--red); color: var(--red); transform: scale(1.1); }

/* Image */
.card-image {
    position: relative; padding-top: 100%; background: var(--gray-50);
    overflow: hidden; cursor: pointer;
}
.card-image img {
    position: absolute; inset: 0; width: 100%; height: 100%;
    object-fit: cover; transition: transform .4s;
}
.wishlist-card:hover .card-image img { transform: scale(1.06); }
.card-image .img-placeholder {
    position: absolute; inset: 0; display: flex; align-items: center;
    justify-content: center; font-size: 3.5rem; color: #ddd; background: var(--gray-50);
}

/* Card Content */
.card-content { padding: 1rem 1.25rem 1.25rem; display: flex; flex-direction: column; flex: 1; }

.vendor-info {
    display: flex; align-items: center; gap: .4rem;
    font-size: .68rem; text-transform: uppercase; letter-spacing: .5px;
    color: var(--ink-4); font-weight: 600; margin-bottom: .3rem;
}
.vendor-info i { color: var(--green); }

.product-name {
    font-weight: 700; font-size: .95rem; color: var(--ink);
    margin-bottom: .5rem; line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden; cursor: pointer; transition: color .2s;
}
.product-name:hover { color: var(--green); }

.product-description {
    font-size: .78rem; color: var(--ink-4);
    margin-bottom: .5rem; line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Rating */
.rating {
    display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem;
    font-size: .8rem;
}
.stars { color: var(--gold); display: flex; gap: 1px; }
.rating-count { color: var(--ink-4); font-size: .72rem; }

/* Price */
.price-section {
    display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
    margin-bottom: .5rem;
}
.current-price { font-size: 1.15rem; font-weight: 700; color: var(--green); }
.original-price { font-size: .82rem; color: var(--ink-4); text-decoration: line-through; }
.discount {
    font-size: .68rem; font-weight: 700; color: var(--red);
    background: var(--red-lt); padding: .1rem .5rem; border-radius: 4px;
}
.wholesale-tag {
    font-size: .65rem; font-weight: 600; color: var(--purple);
    background: var(--purple-lt); padding: .1rem .5rem; border-radius: 4px;
    display: inline-flex; align-items: center; gap: .25rem;
}

/* Stock Status */
.stock-status {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .72rem; font-weight: 500; padding: .2rem .6rem;
    border-radius: var(--radius-full); margin-bottom: .5rem; width: fit-content;
}
.stock-status.in-stock { background: #d1fae5; color: #065f46; }
.stock-status.low-stock { background: #fef3c7; color: #92400e; }
.stock-status.out-of-stock { background: #fee2e2; color: #991b1b; }

/* Quantity Selector */
.quantity-selector {
    display: flex; align-items: center; gap: .5rem; margin-bottom: .75rem;
}
.qty-btn {
    width: 30px; height: 30px; border: 1.5px solid var(--line);
    background: var(--white); border-radius: var(--radius-sm);
    cursor: pointer; transition: var(--transition);
    font-size: .85rem; font-weight: 700; color: var(--ink-3);
    display: flex; align-items: center; justify-content: center;
}
.qty-btn:hover { border-color: var(--green); color: var(--green); background: var(--green-lt); }
.qty-input {
    width: 44px; height: 30px; text-align: center; border: 1.5px solid var(--line);
    border-radius: var(--radius-sm); font-size: .82rem; font-weight: 600;
    font-family: var(--fb); color: var(--ink); background: var(--white);
    -moz-appearance: textfield;
}
.qty-input::-webkit-outer-spin-button, .qty-input::-webkit-inner-spin-button {
    -webkit-appearance: none; margin: 0;
}

/* Card Actions */
.card-actions {
    display: flex; gap: .6rem; margin-top: auto;
}
.cart-btn {
    flex: 1; padding: .6rem .75rem; background: var(--green); color: #fff;
    border: none; border-radius: var(--radius-full); font-weight: 600;
    font-size: .8rem; cursor: pointer; transition: var(--transition);
    font-family: var(--fb); display: flex; align-items: center;
    justify-content: center; gap: .4rem;
}
.cart-btn:hover:not(:disabled) { background: var(--gold-dk); color: var(--ink); transform: translateY(-1px); }
.cart-btn:disabled { background: var(--gray-300); cursor: not-allowed; }

.view-btn {
    padding: .6rem .9rem; background: transparent; border: 1.5px solid var(--line);
    border-radius: var(--radius-full); font-weight: 600; font-size: .8rem;
    cursor: pointer; transition: var(--transition); font-family: var(--fb);
    display: inline-flex; align-items: center; gap: .4rem; color: var(--ink-3);
    text-decoration: none;
}
.view-btn:hover { border-color: var(--green); color: var(--green); background: var(--green-lt); }

/* ═══ EMPTY STATE ═══ */
.empty-state {
    text-align: center; padding: 4rem 2rem; background: var(--white);
    border-radius: var(--radius); border: 1px solid var(--line); box-shadow: var(--sh-sm);
}
.empty-state-icon { font-size: 4rem; color: #d1d5db; margin-bottom: 1rem; }
.empty-state h3 { font-family: var(--fh); font-size: 1.5rem; font-weight: 400; margin-bottom: .5rem; }
.empty-state p { color: var(--ink-3); font-size: .88rem; margin-bottom: 1.5rem; }
.btn-shop-now {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .75rem 2rem; background: var(--green); color: #fff;
    text-decoration: none; border-radius: var(--radius-full); font-weight: 600;
    font-size: .88rem; transition: var(--transition);
}
.btn-shop-now:hover { background: var(--green-dk); transform: translateY(-2px); box-shadow: var(--sh-md); }

/* ═══ TOAST ═══ */
.toast-container {
    position: fixed; bottom: 1.5rem; right: 1.25rem; z-index: 99999;
    display: flex; flex-direction: column-reverse; gap: .5rem; pointer-events: none;
}
.toast {
    background: var(--white); border-radius: var(--radius-sm);
    box-shadow: 0 8px 24px rgba(0,0,0,.15); padding: .75rem 1rem;
    display: flex; align-items: center; gap: .5rem; font-size: .8rem; font-weight: 500;
    max-width: 350px; pointer-events: auto;
    animation: toastIn .3s cubic-bezier(.16,1,.3,1);
    border-left: 3px solid var(--green);
}
.toast.error { border-left-color: var(--red); }
.toast .close-toast {
    margin-left: auto; background: none; border: none; cursor: pointer;
    color: var(--ink-4); font-size: 1rem; padding: 0; line-height: 1;
}
@keyframes toastIn { from { transform: translateX(110%); opacity: 0; } to { opacity: 1; transform: none; } }

/* ═══ MODAL ═══ */
.modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5);
    z-index: 2000; align-items: center; justify-content: center;
    padding: 1.5rem;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: var(--white); border-radius: var(--radius); max-width: 460px;
    width: 100%; padding: 1.5rem; box-shadow: var(--sh-xl);
}
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 1rem; padding-bottom: .75rem; border-bottom: 1px solid var(--line);
}
.modal-header h3 { font-family: var(--fh); font-size: 1.2rem; font-weight: 600; }
.modal-close {
    cursor: pointer; font-size: 1.4rem; color: var(--ink-4);
    background: none; border: none; transition: var(--transition);
}
.modal-close:hover { color: var(--red); }
.modal-body { margin-bottom: 1.5rem; color: var(--ink-3); }
.modal-footer { display: flex; gap: .75rem; justify-content: flex-end; }

/* ═══ RESPONSIVE ═══ */
@media (max-width: 768px) {
    .wishlist-container { padding: 0 .75rem; }
    .wishlist-header { padding: 1rem 1.25rem; }
    .wishlist-title { flex-direction: column; align-items: flex-start; font-size: 1.4rem; }
    .wishlist-title h1 { font-size: 1.4rem; }
    .wishlist-stats { gap: 1rem; }
    .wishlist-grid { grid-template-columns: 1fr 1fr; gap: .85rem; }
    .card-content { padding: .75rem; }
    .product-name { font-size: .85rem; }
    .current-price { font-size: 1rem; }
    .cart-btn { font-size: .72rem; padding: .5rem .6rem; }
    .view-btn { font-size: .72rem; padding: .5rem .6rem; }
}
@media (max-width: 480px) {
    .wishlist-grid { grid-template-columns: 1fr; }
    .wishlist-actions { width: 100%; }
    .wishlist-actions .btn-primary, .wishlist-actions .btn-danger { flex: 1; justify-content: center; }
}
</style>

<!-- ═══ TOAST CONTAINER ═══ -->
<div class="toast-container" id="toastContainer"></div>

<!-- ═══ WISHLIST PAGE ═══ -->
<div class="wishlist-page">
    <div class="wishlist-container">
        
        <!-- Breadcrumb -->
        <div class="wishlist-breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Home</a>
            <span>›</span>
            <a href="account.php">My Account</a>
            <span>›</span>
            <span>Wishlist</span>
        </div>
        
        <!-- Header -->
        <div class="wishlist-header">
            <div class="wishlist-title">
                <h1>
                    <i class="fas fa-heart"></i> My Wishlist
                </h1>
                <?php if ($total_items > 0): ?>
                <div class="wishlist-actions">
                    <button class="btn-danger" id="clearWishlistBtn">
                        <i class="fas fa-trash-alt"></i> Clear All
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="wishlist-stats">
                <div class="stat-item">
                    <span class="stat-label">Total Items:</span>
                    <span class="stat-value" id="wishlistCount"><?php echo $total_items; ?></span>
                </div>
                <?php if ($total_items > 0): ?>
                <div class="stat-item">
                    <span class="stat-label">Total Value:</span>
                    <span class="stat-value"><?php echo formatPrice($total_value); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($wishlist_items)): ?>
        <!-- Wishlist Grid -->
        <div class="wishlist-grid" id="wishlistGrid">
            <?php foreach ($wishlist_items as $item): 
                $product_id = (int)$item['id'];
                $img_url = getProductImageUrl($item['image'] ?? '');
                $best_price = $item['best_price'];
                $original_price = $item['original_price'];
                $has_discount = $item['has_discount'];
                $discount_percent = $item['discount_percent'];
                $has_wholesale = $item['has_wholesale'];
                $stock_status = $item['stock_status'] ?? 'in_stock';
                $total_stock = $item['total_stock'] ?? 0;
                $is_express = (int)($item['vendor_express'] ?? 0);
                $featured = (int)($item['featured'] ?? 0);
                $in_cart = $item['in_cart'] ?? false;
                $cart_qty = $item['cart_qty'] ?? 0;
                $cart_key = $item['cart_key'] ?? '';
                $default_tier_id = $item['default_tier_id'] ?? 0;
                $vendor_name = $item['vendor_name'] ?? 'Bless Mall';
                $avg_rating = (float)($item['avg_rating'] ?? 0);
                $review_count = (int)($item['review_count'] ?? 0);
            ?>
            <div class="wishlist-card" id="card-<?php echo $product_id; ?>" data-pid="<?php echo $product_id; ?>" data-default-tier="<?php echo $default_tier_id; ?>">
                
                <!-- Badges -->
                <div class="card-badge">
                    <?php if ($has_discount && $discount_percent > 0): ?>
                        <span class="badge badge-discount">-<?php echo $discount_percent; ?>%</span>
                    <?php endif; ?>
                    <?php if ($is_express && !$has_discount): ?>
                        <span class="badge badge-express"><i class="fas fa-bolt"></i> Express</span>
                    <?php endif; ?>
                    <?php if ($featured): ?>
                        <span class="badge badge-featured">Featured</span>
                    <?php endif; ?>
                    <?php if ($has_wholesale && !$has_discount && !$is_express): ?>
                        <span class="badge badge-wholesale"><i class="fas fa-boxes"></i> Bulk</span>
                    <?php endif; ?>
                </div>
                
                <!-- Remove Button -->
                <button class="remove-btn" onclick="removeFromWishlist(<?php echo $product_id; ?>)" title="Remove from wishlist">
                    <i class="fas fa-times"></i>
                </button>
                
                <!-- Image -->
                <div class="card-image" onclick="window.location.href='product-detail.php?id=<?php echo $product_id; ?>&slug=<?php echo urlencode($item['slug'] ?? ''); ?>'">
                    <?php if (!empty($img_url)): ?>
                        <img src="<?php echo htmlspecialchars($img_url); ?>" 
                             alt="<?php echo htmlspecialchars($item['name']); ?>"
                             loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="img-placeholder" style="display:none;"><i class="fas fa-box-open"></i></div>
                    <?php else: ?>
                        <div class="img-placeholder"><i class="fas fa-box-open"></i></div>
                    <?php endif; ?>
                </div>
                
                <!-- Content -->
                <div class="card-content">
                    <div class="vendor-info">
                        <i class="fas fa-store"></i>
                        <span><?php echo htmlspecialchars($vendor_name); ?></span>
                    </div>
                    
                    <div class="product-name" onclick="window.location.href='product-detail.php?id=<?php echo $product_id; ?>&slug=<?php echo urlencode($item['slug'] ?? ''); ?>'">
                        <?php echo htmlspecialchars($item['name']); ?>
                    </div>
                    
                    <?php if (!empty($item['short_description'])): ?>
                    <div class="product-description">
                        <?php echo htmlspecialchars(substr($item['short_description'], 0, 80)); ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($review_count > 0): ?>
                    <div class="rating">
                        <span class="stars">
                            <?php
                            $rating_val = round($avg_rating * 2) / 2;
                            $full = floor($rating_val);
                            $half = ($rating_val - $full) >= 0.5;
                            $empty = 5 - $full - ($half ? 1 : 0);
                            for ($i = 0; $i < $full; $i++) echo '<i class="fas fa-star"></i>';
                            if ($half) echo '<i class="fas fa-star-half-alt"></i>';
                            for ($i = 0; $i < $empty; $i++) echo '<i class="far fa-star"></i>';
                            ?>
                        </span>
                        <span class="rating-count">(<?php echo $review_count; ?>)</span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="price-section">
                        <span class="current-price"><?php echo formatPrice($best_price); ?></span>
                        <?php if ($has_discount && $best_price < $original_price): ?>
                            <span class="original-price"><?php echo formatPrice($original_price); ?></span>
                            <span class="discount">-<?php echo $discount_percent; ?>%</span>
                        <?php endif; ?>
                        <?php if ($has_wholesale && !$has_discount): ?>
                            <span class="wholesale-tag"><i class="fas fa-boxes"></i> Wholesale</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stock-status <?php echo $stock_status; ?>">
                        <?php if ($stock_status === 'in_stock'): ?>
                            <i class="fas fa-check-circle"></i> In Stock
                        <?php elseif ($stock_status === 'low_stock'): ?>
                            <i class="fas fa-exclamation-circle"></i> Only <?php echo $total_stock; ?> left
                        <?php else: ?>
                            <i class="fas fa-times-circle"></i> Out of Stock
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($in_cart): ?>
                        <!-- Already in cart - show quantity controls -->
                        <div style="display:flex;gap:.4rem;align-items:center;margin-bottom:.75rem;">
                            <div style="display:flex;align-items:center;border:1.5px solid var(--green);border-radius:var(--radius-full);overflow:hidden;flex:1;">
                                <button class="qty-btn" onclick="updateCartQty('<?php echo $cart_key; ?>', <?php echo $product_id; ?>, <?php echo $cart_qty - 1; ?>)" <?php echo $cart_qty <= 1 ? 'disabled' : ''; ?>>−</button>
                                <input type="text" class="qty-input" value="<?php echo $cart_qty; ?>" readonly style="width:38px;border:none;border-left:1px solid var(--green);border-right:1px solid var(--green);">
                                <button class="qty-btn" onclick="updateCartQty('<?php echo $cart_key; ?>', <?php echo $product_id; ?>, <?php echo $cart_qty + 1; ?>)" <?php echo $stock_status === 'out_of_stock' ? 'disabled' : ''; ?>>+</button>
                            </div>
                            <button class="remove-btn" onclick="removeFromCart('<?php echo $cart_key; ?>', <?php echo $product_id; ?>)" style="position:static;width:36px;height:36px;" title="Remove from cart">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="quantity-selector">
                            <button class="qty-btn" onclick="changeQuantity(<?php echo $product_id; ?>, -1)" aria-label="Decrease quantity">−</button>
                            <input type="text" class="qty-input" id="qty_<?php echo $product_id; ?>" value="1" readonly>
                            <button class="qty-btn" onclick="changeQuantity(<?php echo $product_id; ?>, 1)" <?php echo $stock_status === 'out_of_stock' ? 'disabled' : ''; ?> aria-label="Increase quantity">+</button>
                        </div>
                        
                        <div class="card-actions">
                            <button class="cart-btn" onclick="addToCart(<?php echo $product_id; ?>, <?php echo $default_tier_id; ?>, this)" <?php echo $stock_status === 'out_of_stock' ? 'disabled' : ''; ?>>
                                <i class="fas fa-shopping-cart"></i> Add to Cart
                            </button>
                            <a href="product-detail.php?id=<?php echo $product_id; ?>&slug=<?php echo urlencode($item['slug'] ?? ''); ?>" class="view-btn">
                                <i class="fas fa-eye"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Continue Shopping -->
        <div style="text-align:center;margin:1.5rem 0 2rem;">
            <a href="shop.php" style="color:var(--green);text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;font-weight:500;">
                <i class="fas fa-arrow-left"></i> Continue Shopping
            </a>
        </div>
        
        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-state-icon"><i class="far fa-heart"></i></div>
            <h3>Your wishlist is empty</h3>
            <p>Save your favorite items here to buy them later!</p>
            <a href="shop.php" class="btn-shop-now">
                <i class="fas fa-shopping-bag"></i> Start Shopping
            </a>
        </div>
        <?php endif; ?>
        
    </div>
</div>

<!-- ═══ CLEAR WISHLIST MODAL ═══ -->
<div class="modal-overlay" id="clearModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Clear Wishlist</h3>
            <button class="modal-close" onclick="closeModal('clearModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to remove all items from your wishlist? This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button class="btn-outline" onclick="closeModal('clearModal')">Cancel</button>
            <form method="POST" style="display:inline;">
                <button type="submit" name="clear_wishlist" class="btn-danger">Yes, Clear All</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
// ═══════════════════════════════════════════════════════════════════
// WISHLIST SCRIPTS — Full AJAX integration
// ═══════════════════════════════════════════════════════════════════

// ═══ TOAST NOTIFICATIONS ═══
function showToast(message, type) {
    type = type || 'info';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    
    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML = '<span>' + message + '</span>' +
        '<button class="close-toast" onclick="this.parentElement.remove()">&times;</button>';
    container.appendChild(toast);
    
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300);
    }, 4000);
    
    var toasts = container.querySelectorAll('.toast');
    while (toasts.length > 3) { toasts[0].remove(); }
}

// ═══ UPDATE CART BADGE ═══
function updateCartBadge(count) {
    var badge = document.getElementById('cartCount');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }
}

// ═══ CHANGE QUANTITY ═══
function changeQuantity(productId, change) {
    var input = document.getElementById('qty_' + productId);
    if (!input) return;
    var current = parseInt(input.value) || 1;
    var newQty = Math.max(1, current + change);
    input.value = newQty;
}

// ═══ ADD TO CART ═══
function addToCart(productId, pricingTierId, button) {
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    button.disabled = true;
    
    // Get quantity from input
    var qtyInput = document.getElementById('qty_' + productId);
    var quantity = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
    
    var formData = new URLSearchParams();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('pricing_tier_id', pricingTierId || 0);
    formData.append('quantity', quantity);
    
    fetch('wishlist.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            updateCartBadge(data.cart_count);
            showToast(data.message || 'Added to cart!', 'success');
            
            // Update card to show quantity controls
            var card = document.getElementById('card-' + productId);
            if (card) {
                var content = card.querySelector('.card-content');
                var qtySelector = content.querySelector('.quantity-selector');
                var actions = content.querySelector('.card-actions');
                var stockStatus = content.querySelector('.stock-status');
                
                if (qtySelector) qtySelector.remove();
                if (actions) {
                    var cartKey = data.cart_key || ('p_' + productId + '_t_' + (data.pricing_tier_id || pricingTierId || 0));
                    var qty = data.quantity || quantity;
                    
                    actions.innerHTML = 
                        '<div style="display:flex;gap:.4rem;align-items:center;width:100%;">' +
                            '<div style="display:flex;align-items:center;border:1.5px solid var(--green);border-radius:var(--radius-full);overflow:hidden;flex:1;">' +
                                '<button class="qty-btn" onclick="updateCartQty(\'' + cartKey + '\', ' + productId + ', ' + (qty - 1) + ')" ' + (qty <= 1 ? 'disabled' : '') + '>−</button>' +
                                '<input type="text" class="qty-input" value="' + qty + '" readonly style="width:38px;border:none;border-left:1px solid var(--green);border-right:1px solid var(--green);">' +
                                '<button class="qty-btn" onclick="updateCartQty(\'' + cartKey + '\', ' + productId + ', ' + (qty + 1) + ')">+</button>' +
                            '</div>' +
                            '<button class="remove-btn" onclick="removeFromCart(\'' + cartKey + '\', ' + productId + ')" style="position:static;width:36px;height:36px;" title="Remove from cart">' +
                                '<i class="fas fa-trash-alt"></i>' +
                            '</button>' +
                        '</div>';
                }
            }
        } else {
            showToast(data.message || 'Failed to add to cart', 'error');
            button.innerHTML = originalHTML;
            button.disabled = false;
        }
    })
    .catch(function(err) {
        console.error('Add to cart error:', err);
        showToast('Connection error. Please try again.', 'error');
        button.innerHTML = originalHTML;
        button.disabled = false;
    });
}

// ═══ UPDATE CART QUANTITY ═══
function updateCartQty(cartKey, productId, newQty) {
    newQty = Math.max(0, Math.min(999, parseInt(newQty) || 0));
    if (newQty === 0) { removeFromCart(cartKey, productId); return; }
    
    var card = document.getElementById('card-' + productId);
    if (!card) return;
    
    var qtyInput = card.querySelector('.qty-input');
    if (qtyInput) qtyInput.value = newQty;
    
    var formData = new URLSearchParams();
    formData.append('action', 'update_cart_quantity');
    formData.append('cart_key', cartKey);
    formData.append('quantity', newQty);
    formData.append('product_id', productId);
    formData.append('pricing_tier_id', 0);
    
    fetch('wishlist.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            updateCartBadge(data.cart_count);
        }
    })
    .catch(function(err) { console.error('Update cart error:', err); });
}

// ═══ REMOVE FROM CART ═══
function removeFromCart(cartKey, productId) {
    var card = document.getElementById('card-' + productId);
    if (!card) return;
    
    var formData = new URLSearchParams();
    formData.append('action', 'remove_from_cart');
    formData.append('cart_key', cartKey);
    
    fetch('wishlist.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            updateCartBadge(data.cart_count);
            showToast('Removed from cart', 'success');
            
            // Restore Add to Cart button
            var content = card.querySelector('.card-content');
            var actions = content.querySelector('[style*="display:flex;gap:.4rem;"]');
            if (actions) {
                var defaultTier = parseInt(card.dataset.defaultTier) || 0;
                actions.innerHTML = 
                    '<div class="quantity-selector">' +
                        '<button class="qty-btn" onclick="changeQuantity(' + productId + ', -1)">−</button>' +
                        '<input type="text" class="qty-input" id="qty_' + productId + '" value="1" readonly>' +
                        '<button class="qty-btn" onclick="changeQuantity(' + productId + ', 1)">+</button>' +
                    '</div>' +
                    '<div class="card-actions">' +
                        '<button class="cart-btn" onclick="addToCart(' + productId + ', ' + defaultTier + ', this)"><i class="fas fa-shopping-cart"></i> Add to Cart</button>' +
                        '<a href="product-detail.php?id=' + productId + '" class="view-btn"><i class="fas fa-eye"></i></a>' +
                    '</div>';
            }
        }
    })
    .catch(function(err) { console.error('Remove from cart error:', err); });
}

// ═══ REMOVE FROM WISHLIST ═══
function removeFromWishlist(productId) {
    if (!confirm('Remove this item from your wishlist?')) return;
    
    var card = document.getElementById('card-' + productId);
    if (card) {
        card.style.transition = 'all 0.35s ease';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.95)';
        setTimeout(function() { if (card.parentNode) card.remove(); }, 350);
    }
    
    fetch('wishlist.php?remove=' + productId, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(response) {
        if (response.ok) {
            showToast('Removed from wishlist', 'success');
            updateWishlistCount();
        }
    })
    .catch(function(err) {
        console.error('Remove wishlist error:', err);
        showToast('Error removing item', 'error');
    });
}

// ═══ UPDATE WISHLIST COUNT ═══
function updateWishlistCount() {
    var remainingCards = document.querySelectorAll('.wishlist-card');
    var count = remainingCards.length;
    var countEl = document.getElementById('wishlistCount');
    if (countEl) countEl.textContent = count;
    
    if (count === 0) {
        location.reload();
    }
}

// ═══ MODAL FUNCTIONS ═══
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// ═══ CLEAR WISHLIST MODAL ═══
document.getElementById('clearWishlistBtn')?.addEventListener('click', function() {
    openModal('clearModal');
});

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

// ═══ INIT ═══
console.log('%c❤️ Bless Mall Wishlist %cv2',
    'font-size:1.2em;font-weight:bold;color:#dc2626;',
    'font-size:1em;color:#c9a227;');
console.log('%cFull pricing tiers and image support enabled', 'font-style:italic;color:#666;');
</script>
</body>
</html>