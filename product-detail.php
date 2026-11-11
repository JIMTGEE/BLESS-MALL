<?php
/**
 * product-detail.php — Bless Mall Product Detail v12
 * Premium product page with variant support & rock‑solid image handling
 * Embedded AJAX for cart/wishlist/quick‑buy
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!defined('BLESSMALL_CONFIG_INCLUDED')) { require_once __DIR__ . '/includes/config.php'; }
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ------------------------------------------------------------
// DEFINE ALL NECESSARY CONSTANTS (CHECK FIRST TO AVOID ERRORS)
// ------------------------------------------------------------
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    define('BASE_URL', $protocol . $host . ($basePath ? '/' . ltrim($basePath, '/') : ''));
}

if (!defined('UPLOAD_BASE_DIR')) {
    define('UPLOAD_BASE_DIR', __DIR__ . '/uploads/');
}
if (!defined('PRODUCT_UPLOAD_DIR')) {
    define('PRODUCT_UPLOAD_DIR', UPLOAD_BASE_DIR . 'products/');
}
if (!defined('PRODUCT_UPLOAD_URL')) {
    define('PRODUCT_UPLOAD_URL', BASE_URL . '/uploads/products/');
}
if (!defined('ASSETS_URL')) {
    define('ASSETS_URL', BASE_URL . '/assets/');
}

// Helper Functions
function _bm_fmt($v) {
    $n = (float) $v;
    if ($n <= 0) return 'GH₵ 0.00';
    return 'GH₵ ' . number_format($n, 2, '.', ',');
}

function getCartCount() {
    $count = 0;
    if (!empty($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $item) {
            $count += (int)($item['quantity'] ?? 0);
        }
    }
    return $count;
}

/**
 * Get the full URL for a product image – with file existence check.
 */
function getProductImageUrl($image_path) {
    if (empty($image_path)) {
        return '';
    }

    // If it's already a full URL
    if (filter_var($image_path, FILTER_VALIDATE_URL)) {
        return $image_path;
    }

    // Clean the path
    $image_path = ltrim($image_path, '/');

    // Check if it's stored with product ID prefix (e.g., "1/primary/image.jpg")
    if (preg_match('/^\d+\/(primary|secondary)\/.+\.(jpg|jpeg|png|gif|webp)$/i', $image_path)) {
        $full = PRODUCT_UPLOAD_DIR . $image_path;
        if (file_exists($full)) {
            return PRODUCT_UPLOAD_URL . $image_path;
        }
        // Try without product ID folder (legacy)
        $base = basename($image_path);
        $full2 = PRODUCT_UPLOAD_DIR . $base;
        if (file_exists($full2)) {
            return PRODUCT_UPLOAD_URL . $base;
        }
        return '';
    }

    // Check common paths
    $paths_to_check = [
        '/assets/uploads/products/' . $image_path,
        '/uploads/products/' . $image_path,
        '/assets/images/products/' . $image_path,
        '/assets/images/' . $image_path,
    ];

    foreach ($paths_to_check as $path) {
        $fullPath = __DIR__ . $path;
        if (file_exists($fullPath)) {
            return BASE_URL . $path;
        }
    }

    // Check if it's just a filename in the products folder
    $fullPath = PRODUCT_UPLOAD_DIR . $image_path;
    if (file_exists($fullPath)) {
        return PRODUCT_UPLOAD_URL . $image_path;
    }

    // Try assets uploads
    $fullPath = __DIR__ . '/assets/uploads/products/' . $image_path;
    if (file_exists($fullPath)) {
        return BASE_URL . '/assets/uploads/products/' . $image_path;
    }

    // Not found
    return '';
}

// Get product slug
$product_slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
if (empty($product_slug)) {
    header('Location: shop.php');
    exit;
}

// ════════════════════════════════════════════════════════════════════
// HANDLE ALL AJAX REQUESTS INLINE (Embedded API)
// ════════════════════════════════════════════════════════════════════
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=utf-8');
    $conn = getConnection();
    
    // ═══ ADD TO CART (supports variant tier) ═══
    if (isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $pricing_tier_id = (int)($_POST['pricing_tier_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        
        if ($pricing_tier_id > 0) {
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.slug,
                       ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                       ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
                       ppt.min_quantity, ppt.max_quantity
                FROM products p
                JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                WHERE p.id = ? AND ppt.id = ? AND p.is_active = 1 AND ppt.is_active = 1
                LIMIT 1
            ");
            $stmt->bind_param("ii", $product_id, $pricing_tier_id);
        } else {
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.slug,
                       ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                       ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
                       ppt.min_quantity, ppt.max_quantity
                FROM products p
                JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                WHERE p.id = ? AND ppt.pricing_type = 'retail' AND p.is_active = 1 AND ppt.is_active = 1
                ORDER BY ppt.unit_price ASC
                LIMIT 1
            ");
            $stmt->bind_param("i", $product_id);
        }
        
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not available']);
            exit;
        }
        
        $unit_price = (float)$product['unit_price'];
        $discount_price = !empty($product['discount_price']) ? (float)$product['discount_price'] : null;
        $discount_percentage = !empty($product['discount_percentage']) ? (float)$product['discount_percentage'] : null;
        
        $final_price = $unit_price;
        if ($discount_price && $discount_price > 0 && $discount_price < $unit_price) {
            $final_price = $discount_price;
        } elseif ($discount_percentage && $discount_percentage > 0) {
            $final_price = round($unit_price * (1 - $discount_percentage / 100), 2);
        }
        
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        $cart_key = 'p_' . $product_id . '_t_' . $product['pricing_tier_id'];
        
        if (isset($_SESSION['cart'][$cart_key])) {
            $_SESSION['cart'][$cart_key]['quantity'] += $quantity;
        } else {
            $imgStmt = $conn->prepare("
                SELECT image_path FROM product_images 
                WHERE product_id = ? 
                ORDER BY is_primary DESC, sort_order ASC 
                LIMIT 1
            ");
            $img_path = '';
            if ($imgStmt) {
                $imgStmt->bind_param("i", $product_id);
                $imgStmt->execute();
                $imgResult = $imgStmt->get_result();
                if ($imgRow = $imgResult->fetch_assoc()) {
                    $img_path = $imgRow['image_path'];
                }
                $imgStmt->close();
            }
            
            $_SESSION['cart'][$cart_key] = [
                'product_id' => $product_id,
                'pricing_tier_id' => $product['pricing_tier_id'],
                'name' => $product['name'],
                'slug' => $product['slug'],
                'tier_name' => $product['tier_name'],
                'pricing_type' => $product['pricing_type'],
                'price' => $final_price,
                'original_price' => $unit_price,
                'quantity' => $quantity,
                'image' => $img_path,
                'added_at' => time()
            ];
        }
        
        $cart_count = getCartCount();
        
        echo json_encode([
            'success' => true,
            'message' => htmlspecialchars($product['name']) . ' added to cart!',
            'cart_count' => $cart_count,
            'cart_key' => $cart_key,
            'quantity' => $quantity
        ]);
        exit;
    }
    
    // ═══ BUY NOW ═══
    if (isset($_POST['action']) && $_POST['action'] === 'buy_now') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $pricing_tier_id = (int)($_POST['pricing_tier_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        
        if ($pricing_tier_id > 0) {
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.slug,
                       ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                       ppt.unit_price, ppt.discount_price, ppt.discount_percentage
                FROM products p
                JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                WHERE p.id = ? AND ppt.id = ? AND p.is_active = 1
                LIMIT 1
            ");
            $stmt->bind_param("ii", $product_id, $pricing_tier_id);
        } else {
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.slug,
                       ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                       ppt.unit_price, ppt.discount_price, ppt.discount_percentage
                FROM products p
                JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                WHERE p.id = ? AND ppt.pricing_type = 'retail' AND p.is_active = 1
                ORDER BY ppt.unit_price ASC
                LIMIT 1
            ");
            $stmt->bind_param("i", $product_id);
        }
        
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not available']);
            exit;
        }
        
        $unit_price = (float)$product['unit_price'];
        $discount_price = !empty($product['discount_price']) ? (float)$product['discount_price'] : null;
        $discount_percentage = !empty($product['discount_percentage']) ? (float)$product['discount_percentage'] : null;
        
        $final_price = $unit_price;
        if ($discount_price && $discount_price > 0 && $discount_price < $unit_price) {
            $final_price = $discount_price;
        } elseif ($discount_percentage && $discount_percentage > 0) {
            $final_price = round($unit_price * (1 - $discount_percentage / 100), 2);
        }
        
        $_SESSION['cart'] = [];
        $cart_key = 'p_' . $product_id . '_t_' . $product['pricing_tier_id'];
        
        $imgStmt = $conn->prepare("
            SELECT image_path FROM product_images 
            WHERE product_id = ? 
            ORDER BY is_primary DESC, sort_order ASC 
            LIMIT 1
        ");
        $img_path = '';
        if ($imgStmt) {
            $imgStmt->bind_param("i", $product_id);
            $imgStmt->execute();
            $imgResult = $imgStmt->get_result();
            if ($imgRow = $imgResult->fetch_assoc()) {
                $img_path = $imgRow['image_path'];
            }
            $imgStmt->close();
        }
        
        $_SESSION['cart'][$cart_key] = [
            'product_id' => $product_id,
            'pricing_tier_id' => $product['pricing_tier_id'],
            'name' => $product['name'],
            'slug' => $product['slug'],
            'tier_name' => $product['tier_name'],
            'pricing_type' => $product['pricing_type'],
            'price' => $final_price,
            'original_price' => $unit_price,
            'quantity' => $quantity,
            'image' => $img_path,
            'added_at' => time()
        ];
        
        echo json_encode(['success' => true, 'redirect' => 'checkout.php']);
        exit;
    }
    
    // ═══ TOGGLE WISHLIST ═══
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_wishlist') {
        if (!Auth::isLoggedIn()) {
            echo json_encode([
                'success' => false,
                'message' => 'Please login to manage wishlist',
                'redirect' => 'login.php?redirect=' . urlencode($_SERVER['HTTP_REFERER'] ?? $_SERVER['REQUEST_URI'])
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
            $delStmt = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
            $delStmt->bind_param("ii", $user_id, $product_id);
            $delStmt->execute();
            $delStmt->close();
            echo json_encode(['success' => true, 'action' => 'removed', 'message' => 'Removed from wishlist']);
        } else {
            $insStmt = $conn->prepare("INSERT INTO wishlist (user_id, product_id, created_at) VALUES (?, ?, NOW())");
            $insStmt->bind_param("ii", $user_id, $product_id);
            $insStmt->execute();
            $insStmt->close();
            echo json_encode(['success' => true, 'action' => 'added', 'message' => 'Added to wishlist! ❤️']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ════════════════════════════════════════════════════════════════════
// MAIN PRODUCT DATA FETCHING
// ════════════════════════════════════════════════════════════════════
try {
    $conn = getConnection();
    
    // Fetch main product with category and vendor
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            c.id as cat_id, c.name as cat_name, c.slug as cat_slug, c.parent_id as cat_parent_id,
            v.id as vendor_id, v.business_name as vendor_name, v.logo as vendor_logo,
            v.is_express as vendor_express, v.rating as vendor_rating,
            v.store_badge as vendor_badge, v.is_approved as vendor_approved
        FROM products p
        INNER JOIN categories c ON p.category_id = c.id AND c.is_active = 1
        INNER JOIN vendors v ON p.vendor_id = v.id AND v.status = 'active'
        WHERE p.slug = ? AND p.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('s', $product_slug);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if (!$product) {
        header('Location: shop.php');
        exit;
    }
    
    $product_id = (int)$product['id'];
    
    // ─── FETCH IMAGES (with existence check) ──────────────────
    $images = [];
    $image_paths = [];
    $imgResult = $conn->query("
        SELECT id, image_path, is_primary, sort_order
        FROM product_images
        WHERE product_id = $product_id
        ORDER BY is_primary DESC, sort_order ASC
    ");
    
    while ($img = $imgResult->fetch_assoc()) {
        $full_url = getProductImageUrl($img['image_path']);
        // Only add if we found a valid URL
        if (!empty($full_url)) {
            $images[] = [
                'id' => $img['id'],
                'path' => $img['image_path'],
                'url' => $full_url,
                'is_primary' => $img['is_primary'],
                'sort_order' => $img['sort_order']
            ];
            $image_paths[] = $full_url;
        }
    }
    // If no images found, add placeholder
    if (empty($images)) {
        $images[] = [
            'id' => 0,
            'path' => '',
            'url' => ASSETS_URL . 'images/placeholders/product-placeholder.jpg',
            'is_primary' => 1,
            'sort_order' => 0
        ];
        $image_paths[] = ASSETS_URL . 'images/placeholders/product-placeholder.jpg';
    }
    
    $main_image = $image_paths[0] ?? '';
    
    // ─── FETCH VARIANTS WITH PRICING ──────────────────────────
    $variants = [];
    $varStmt = $conn->prepare("
        SELECT 
            pv.id, pv.variant_name, pv.variant_value, pv.sku_suffix,
            ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
            ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
            COALESCE(pi.stock_quantity - pi.reserved_quantity, 0) as available_stock
        FROM product_variants pv
        LEFT JOIN product_pricing_tiers ppt 
            ON pv.id = ppt.product_variant_id 
            AND ppt.is_active = 1 
            AND ppt.pricing_type = 'retail'
        LEFT JOIN product_inventory pi ON ppt.id = pi.pricing_tier_id
        WHERE pv.product_id = ?
        ORDER BY pv.variant_name ASC, pv.variant_value ASC
    ");
    $varStmt->bind_param("i", $product_id);
    $varStmt->execute();
    $varResult = $varStmt->get_result();
    while ($var = $varResult->fetch_assoc()) {
        // Calculate effective price
        $unit_price = (float)($var['unit_price'] ?? 0);
        $discount_price = !empty($var['discount_price']) ? (float)$var['discount_price'] : null;
        $discount_percentage = !empty($var['discount_percentage']) ? (float)$var['discount_percentage'] : null;
        
        $eff_price = $unit_price;
        $has_discount = false;
        $discount_pct = 0;
        if ($discount_price && $discount_price > 0 && $discount_price < $unit_price) {
            $eff_price = $discount_price;
            $has_discount = true;
            $discount_pct = round(($unit_price - $eff_price) / $unit_price * 100);
        } elseif ($discount_percentage && $discount_percentage > 0) {
            $eff_price = round($unit_price * (1 - $discount_percentage / 100), 2);
            $has_discount = true;
            $discount_pct = round($discount_percentage);
        }
        $var['effective_price'] = $eff_price;
        $var['original_price'] = $unit_price;
        $var['has_discount'] = $has_discount;
        $var['discount_pct'] = $discount_pct;
        $var['available_stock'] = (int)($var['available_stock'] ?? 0);
        $variants[] = $var;
    }
    $varStmt->close();
    
    // Group variants by variant_name
    $variant_groups = [];
    foreach ($variants as $v) {
        $group = $v['variant_name'];
        if (!isset($variant_groups[$group])) $variant_groups[$group] = [];
        $variant_groups[$group][] = $v;
    }
    $has_variants = !empty($variant_groups);
    
    // ─── FETCH PRICING TIERS (for default when no variant) ────
    $all_tiers = [];
    $retail_tiers = [];
    $wholesale_tiers = [];
    $tierResult = $conn->query("
        SELECT 
            id, pricing_type, tier_name, min_quantity, max_quantity,
            unit_price, discount_price, discount_percentage, is_active
        FROM product_pricing_tiers
        WHERE product_id = $product_id AND is_active = 1
        ORDER BY FIELD(pricing_type, 'retail', 'wholesale'), min_quantity ASC
    ");
    while ($tier = $tierResult->fetch_assoc()) {
        $tier['unit_price'] = (float)$tier['unit_price'];
        $tier['discount_price'] = $tier['discount_price'] ? (float)$tier['discount_price'] : null;
        $tier['discount_percentage'] = $tier['discount_percentage'] ? (float)$tier['discount_percentage'] : null;
        $eff = $tier['unit_price'];
        if ($tier['discount_price'] && $tier['discount_price'] > 0 && $tier['discount_price'] < $tier['unit_price']) {
            $eff = $tier['discount_price'];
        } elseif ($tier['discount_percentage'] && $tier['discount_percentage'] > 0) {
            $eff = round($tier['unit_price'] * (1 - $tier['discount_percentage'] / 100), 2);
        }
        $tier['effective_price'] = $eff;
        $tier['discount_percent_display'] = $tier['unit_price'] > 0 ? round(($tier['unit_price'] - $eff) / $tier['unit_price'] * 100) : 0;
        
        $all_tiers[] = $tier;
        if ($tier['pricing_type'] === 'retail') {
            $retail_tiers[] = $tier;
        } else {
            $wholesale_tiers[] = $tier;
        }
    }
    
    // Default tier (first retail or wholesale)
    $default_tier = !empty($retail_tiers) ? $retail_tiers[0] : (!empty($wholesale_tiers) ? $wholesale_tiers[0] : null);
    $default_price = $default_tier ? $default_tier['effective_price'] : 0;
    $default_original = $default_tier ? $default_tier['unit_price'] : 0;
    $default_discount_pct = $default_tier ? $default_tier['discount_percent_display'] : 0;
    $default_tier_id = $default_tier ? $default_tier['id'] : 0;
    $has_discount = $default_discount_pct > 0;
    
    $wholesale_only = empty($retail_tiers) && !empty($wholesale_tiers);
    $wholesale_tier = !empty($wholesale_tiers) ? $wholesale_tiers[0] : null;
    $wholesale_price = $wholesale_tier ? $wholesale_tier['effective_price'] : 0;
    $wholesale_min_qty = $wholesale_tier ? $wholesale_tier['min_quantity'] : 0;
    $has_wholesale = $wholesale_price > 0 && $wholesale_price < $default_price;
    
    // ─── INVENTORY (overall) ──────────────────────────────────
    $invResult = $conn->query("
        SELECT 
            SUM(i.stock_quantity) as total_stock,
            SUM(i.reserved_quantity) as total_reserved,
            MIN(i.low_stock_threshold) as low_threshold
        FROM product_inventory i
        INNER JOIN product_pricing_tiers pt ON i.pricing_tier_id = pt.id
        WHERE pt.product_id = $product_id
    ");
    $inv = $invResult->fetch_assoc();
    $total_stock = (int)($inv['total_stock'] ?? 0);
    $total_reserved = (int)($inv['total_reserved'] ?? 0);
    $low_threshold = (int)($inv['low_threshold'] ?? 10);
    $available_stock = max(0, $total_stock - $total_reserved);
    
    if ($available_stock > $low_threshold) {
        $stock_status = 'in_stock';
        $stock_text = '✓ In Stock';
        $stock_icon = '✅';
    } elseif ($available_stock > 0) {
        $stock_status = 'low_stock';
        $stock_text = "⚠ Only {$available_stock} left";
        $stock_icon = '⚠️';
    } else {
        $stock_status = 'out_of_stock';
        $stock_text = '✕ Out of Stock';
        $stock_icon = '❌';
    }
    
    // ─── PROMOTIONS ────────────────────────────────────────────
    $promo = null;
    $promoResult = $conn->query("
        SELECT prom.discount_type, prom.discount_value, prom.name as promo_name, 
               prom.description as promo_desc, prom.end_date
        FROM product_promotions pp
        INNER JOIN promotions prom ON pp.promotion_id = prom.id
        WHERE pp.product_id = $product_id
          AND prom.is_active = 1
          AND prom.start_date <= CURDATE()
          AND prom.end_date >= CURDATE()
        ORDER BY prom.discount_value DESC
        LIMIT 1
    ");
    if ($promoResult && $promoResult->num_rows > 0) {
        $promo = $promoResult->fetch_assoc();
        $promo['discount_value'] = (float)$promo['discount_value'];
    }
    
    // ─── REVIEWS ──────────────────────────────────────────────
    $reviewResult = $conn->query("
        SELECT COUNT(*) as total_reviews, AVG(rating) as avg_rating
        FROM reviews
        WHERE product_id = $product_id
    ");
    $reviewStats = $reviewResult->fetch_assoc();
    $review_count = (int)($reviewStats['total_reviews'] ?? 0);
    $avg_rating = round((float)($reviewStats['avg_rating'] ?? 0), 1);
    
    $rating_counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    if ($review_count > 0) {
        $distResult = $conn->query("SELECT rating, COUNT(*) as cnt FROM reviews WHERE product_id = $product_id GROUP BY rating");
        while ($d = $distResult->fetch_assoc()) {
            $rating_counts[(int)$d['rating']] = (int)$d['cnt'];
        }
    }
    
    $reviews_list = [];
    $reviewsResult = $conn->query("
        SELECT r.*, u.name as user_name
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.product_id = $product_id
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    while ($rv = $reviewsResult->fetch_assoc()) {
        $reviews_list[] = $rv;
    }
    
    // ─── WISHLIST ─────────────────────────────────────────────
    $in_wishlist = false;
    if (Auth::isLoggedIn()) {
        $uid = (int)$_SESSION['user_id'];
        $wlResult = $conn->query("SELECT 1 FROM wishlist WHERE user_id = $uid AND product_id = $product_id");
        $in_wishlist = $wlResult && $wlResult->num_rows > 0;
    }
    
    // ─── PARENT CATEGORY ──────────────────────────────────────
    $parent_cat_name = null;
    $parent_cat_slug = null;
    if (!empty($product['cat_parent_id'])) {
        $parentResult = $conn->query("SELECT name, slug FROM categories WHERE id = " . (int)$product['cat_parent_id']);
        if ($parentResult && $parent = $parentResult->fetch_assoc()) {
            $parent_cat_name = $parent['name'];
            $parent_cat_slug = $parent['slug'];
        }
    }
    
    // ─── RELATED PRODUCTS ─────────────────────────────────────
    $related = [];
    $cat_id = (int)$product['cat_id'];
    $relatedResult = $conn->query("
        SELECT DISTINCT
            p.id, p.name, p.slug,
            (SELECT image_path FROM product_images 
             WHERE product_id = p.id 
             ORDER BY is_primary DESC, sort_order ASC LIMIT 1) as image,
            COALESCE(
                (SELECT MIN(
                    COALESCE(NULLIF(pt.discount_price, 0), pt.unit_price)
                ) FROM product_pricing_tiers pt
                WHERE pt.product_id = p.id AND pt.is_active = 1),
                0
            ) as min_price
        FROM products p
        WHERE p.category_id = $cat_id AND p.id != $product_id AND p.is_active = 1
        ORDER BY p.created_at DESC
        LIMIT 8
    ");
    while ($rel = $relatedResult->fetch_assoc()) {
        $rel['min_price'] = (float)$rel['min_price'];
        $rel['image_url'] = getProductImageUrl($rel['image']);
        $related[] = $rel;
    }
    
    $cart_count = getCartCount();
    
    $page_title = htmlspecialchars($product['name']) . ' — Bless Mall';
    $meta_desc = !empty($product['short_description']) 
        ? htmlspecialchars(substr(strip_tags($product['short_description']), 0, 160))
        : 'Shop ' . htmlspecialchars($product['name']) . ' at Bless Mall. Quality products with fast delivery across Ghana.';
    
} catch (Exception $e) {
    error_log("Product detail error: " . $e->getMessage());
    header('Location: shop.php');
    exit;
}

require_once __DIR__ . '/header.php';
?>

<!-- ════════════════════════════════════════════════════════════════════
     PRODUCT DETAIL PAGE STYLES - ENHANCED PREMIUM
════════════════════════════════════════════════════════════════════ -->
<style>
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
    --green-lt2: #f0faf4;
    --gold: #c9a227;
    --gold-dk: #a6861f;
    --gold-lt: #fdf5e1;
    --gold-lt2: #fefce8;
    --red: #dc2626;
    --red-lt: #fee2e2;
    --amber: #d97706;
    --blue: #2563eb;
    --blue-lt: #dbeafe;
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
    --transition-slow: 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--paper);
    font-family: var(--fb);
    color: var(--ink);
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

/* ═══ BREADCRUMB ═══ */
.breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    font-size: 0.8rem;
    color: var(--ink-4);
    margin-bottom: 1.5rem;
    padding: 0.5rem 0;
    animation: fadeInUp 0.4s ease;
}
.breadcrumb a {
    color: var(--ink-3);
    text-decoration: none;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}
.breadcrumb a:hover { color: var(--green); }
.breadcrumb .sep { color: var(--ink-4); user-select: none; }
.breadcrumb .current { color: var(--ink); font-weight: 500; }

/* ═══ MAIN PAGE ═══ */
.product-page {
    max-width: 1300px;
    margin: 0 auto;
    padding: 0 1.25rem 3rem;
}
.product-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2.5rem;
    margin-bottom: 3rem;
    animation: fadeInUp 0.5s ease 0.1s both;
}

/* ═══ GALLERY ═══ */
.gallery {
    position: sticky;
    top: 90px;
    background: var(--white);
    border-radius: var(--radius);
    overflow: hidden;
    border: 1px solid var(--line);
    box-shadow: var(--sh-sm);
    transition: var(--transition);
}
.gallery:hover { box-shadow: var(--sh-md); }
.main-image {
    aspect-ratio: 1 / 1;
    background: #f5f5f5;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}
.main-image img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    transition: transform var(--transition-slow);
}
.gallery:hover .main-image img { transform: scale(1.03); }
.thumbnails {
    display: flex;
    padding: 0.75rem;
    gap: 0.5rem;
    overflow-x: auto;
    border-top: 1px solid var(--line);
    scrollbar-width: thin;
}
.thumbnails::-webkit-scrollbar { height: 4px; }
.thumbnails::-webkit-scrollbar-track { background: var(--paper); }
.thumbnails::-webkit-scrollbar-thumb { background: var(--line); border-radius: 4px; }
.thumbnail {
    width: 70px;
    height: 70px;
    min-width: 70px;
    border-radius: var(--radius-sm);
    overflow: hidden;
    cursor: pointer;
    border: 2px solid transparent;
    opacity: 0.6;
    transition: var(--transition);
    position: relative;
    background: #f5f5f5;
}
.thumbnail:hover { opacity: 0.9; }
.thumbnail.active {
    border-color: var(--green);
    opacity: 1;
}
.thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.thumbnail .check-mark {
    position: absolute;
    bottom: 4px;
    right: 4px;
    background: var(--green);
    color: #fff;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: var(--transition);
}
.thumbnail.active .check-mark { opacity: 1; }

/* ═══ PRODUCT INFO ═══ */
.product-info { padding: 0.25rem 0; }
.product-title {
    font-family: var(--fh);
    font-size: clamp(1.5rem, 4vw, 2.2rem);
    font-weight: 400;
    line-height: 1.2;
    margin-bottom: 0.5rem;
    color: var(--ink);
}
.product-brand {
    font-size: 0.85rem;
    color: var(--ink-4);
    margin-bottom: 0.5rem;
}
.product-brand strong { color: var(--ink-2); }

/* ═══ VENDOR ROW ═══ */
.vendor-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    margin-bottom: 0.75rem;
    font-size: 0.85rem;
}
.vendor-row .label { color: var(--ink-4); }
.vendor-name {
    font-weight: 600;
    color: var(--ink);
}
.express-badge {
    background: var(--gold);
    color: #fff;
    padding: 0.2rem 0.7rem;
    border-radius: var(--radius-full);
    font-size: 0.65rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    letter-spacing: 0.5px;
}
.vendor-badge {
    background: var(--purple);
    color: #fff;
    padding: 0.2rem 0.7rem;
    border-radius: var(--radius-full);
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.3px;
}

/* ═══ RATING ROW ═══ */
.rating-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin: 0.5rem 0 0.75rem;
}
.stars {
    color: var(--gold);
    font-size: 0.9rem;
    letter-spacing: 2px;
}
.rating-num {
    font-weight: 600;
    color: var(--ink);
    font-size: 0.9rem;
}
.review-link {
    color: var(--ink-4);
    text-decoration: none;
    font-size: 0.8rem;
    transition: var(--transition);
}
.review-link:hover { color: var(--green); text-decoration: underline; }

/* ═══ PRICE BLOCK (with variant support) ═══ */
.price-block {
    background: var(--green-lt);
    border-radius: var(--radius);
    padding: 1.25rem 1.5rem;
    margin: 0.75rem 0 1rem;
    border: 1px solid rgba(10,110,61,0.1);
}
.price-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.current-price {
    font-size: clamp(1.6rem, 5vw, 2.4rem);
    font-weight: 800;
    color: var(--green);
    line-height: 1.1;
}
.original-price {
    font-size: 1rem;
    color: var(--ink-4);
    text-decoration: line-through;
}
.discount-badge {
    display: inline-block;
    background: var(--red);
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 0.2rem 0.6rem;
    border-radius: var(--radius-full);
    vertical-align: middle;
}
.wholesale-only-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    background: var(--purple-lt);
    color: var(--purple);
    padding: 0.2rem 0.7rem;
    border-radius: var(--radius-full);
    font-size: 0.7rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.wholesale-note {
    margin-top: 0.5rem;
    font-size: 0.8rem;
    color: var(--gold);
}
.wholesale-note i { margin-right: 0.25rem; }
.vat-note { font-size: 0.7rem; color: var(--ink-4); margin-top: 0.4rem; }
.promo-tag {
    margin-top: 0.4rem;
    font-size: 0.8rem;
    color: var(--red);
    font-weight: 600;
}
.promo-tag i { margin-right: 0.25rem; }

/* ═══ VARIANT SELECTOR ═══ */
.variant-selector {
    margin: 0.75rem 0;
}
.variant-group {
    margin-bottom: 0.5rem;
}
.variant-group-label {
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--ink-2);
    margin-right: 0.5rem;
}
.variant-options {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 0.25rem;
}
.variant-btn {
    padding: 0.3rem 0.9rem;
    border: 1.5px solid var(--line);
    border-radius: var(--radius-full);
    background: var(--white);
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--ink-2);
    cursor: pointer;
    transition: var(--transition);
    font-family: var(--fb);
    line-height: 1.4;
}
.variant-btn:hover:not(.disabled) {
    border-color: var(--green);
    color: var(--green);
}
.variant-btn.active {
    background: var(--green);
    border-color: var(--green);
    color: #fff;
}
.variant-btn.disabled {
    opacity: 0.4;
    cursor: not-allowed;
    text-decoration: line-through;
}

/* ═══ STOCK BADGE ═══ */
.stock-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 1rem;
    border-radius: var(--radius-full);
    font-size: 0.8rem;
    font-weight: 600;
    margin: 0.5rem 0;
}
.stock-in { background: #d1fae5; color: #065f46; }
.stock-low { background: #fef3c7; color: #92400e; }
.stock-out { background: #fee2e2; color: #991b1b; }

/* ═══ QUANTITY SELECTOR ═══ */
.qty-selector {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}
.qty-label {
    font-weight: 600;
    color: var(--ink-2);
}
.qty-group {
    display: flex;
    align-items: center;
    border: 1.5px solid var(--line);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--white);
}
.qty-btn {
    width: 44px;
    height: 44px;
    border: none;
    background: var(--white);
    font-size: 1.2rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    color: var(--ink);
    display: flex;
    align-items: center;
    justify-content: center;
}
.qty-btn:hover:not(:disabled) {
    background: var(--green-lt);
    color: var(--green);
}
.qty-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.qty-input {
    width: 60px;
    height: 44px;
    text-align: center;
    border: none;
    border-left: 1.5px solid var(--line);
    border-right: 1.5px solid var(--line);
    font-family: var(--fb);
    font-size: 1rem;
    font-weight: 600;
    background: var(--white);
    -moz-appearance: textfield;
}
.qty-input::-webkit-outer-spin-button,
.qty-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.qty-input:focus { outline: 2px solid var(--green); outline-offset: -2px; }

/* ═══ ACTIONS ═══ */
.actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 0.5rem;
}
.btn-primary {
    flex: 1;
    min-width: 140px;
    padding: 0.9rem 1.5rem;
    background: var(--green);
    color: #fff;
    border: none;
    border-radius: var(--radius-full);
    font-family: var(--fb);
    font-weight: 700;
    font-size: 0.9rem;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}
.btn-primary:hover:not(:disabled) {
    background: var(--green-dk);
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(10,110,61,0.3);
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
}
.btn-primary.added {
    background: var(--gold);
    color: var(--ink);
}
.btn-outline {
    flex: 1;
    min-width: 120px;
    padding: 0.9rem 1.2rem;
    background: transparent;
    color: var(--green);
    border: 2px solid var(--green);
    border-radius: var(--radius-full);
    font-family: var(--fb);
    font-weight: 700;
    font-size: 0.9rem;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}
.btn-outline:hover:not(:disabled) {
    background: var(--green);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(10,110,61,0.2);
}
.btn-outline:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.wishlist-btn {
    width: 52px;
    height: 52px;
    min-width: 52px;
    border: 2px solid var(--line);
    background: var(--white);
    border-radius: var(--radius-full);
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: var(--ink-3);
}
.wishlist-btn:hover {
    border-color: var(--red);
    color: var(--red);
    transform: translateY(-2px);
}
.wishlist-btn.active {
    background: var(--red-lt);
    border-color: var(--red);
    color: var(--red);
}
.wishlist-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ═══ TABS ═══ */
.product-tabs {
    margin-top: 2.5rem;
    animation: fadeInUp 0.5s ease 0.3s both;
}
.tab-headers {
    display: flex;
    gap: 1.5rem;
    border-bottom: 2px solid var(--line);
    margin-bottom: 1.5rem;
    overflow-x: auto;
    scrollbar-width: none;
}
.tab-headers::-webkit-scrollbar { display: none; }
.tab-btn {
    padding: 0.75rem 0.25rem;
    font-weight: 600;
    color: var(--ink-3);
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    transition: var(--transition);
    font-family: var(--fb);
    font-size: 0.9rem;
    white-space: nowrap;
    padding-bottom: 0.6rem;
}
.tab-btn:hover { color: var(--ink); }
.tab-btn.active {
    color: var(--green);
    border-color: var(--green);
}
.tab-panel {
    display: none;
    animation: fadeInUp 0.3s ease;
}
.tab-panel.active { display: block; }

/* ═══ PRICING TABLE ═══ */
.pricing-table {
    width: 100%;
    background: var(--white);
    border-radius: var(--radius);
    overflow: hidden;
    border: 1px solid var(--line);
    box-shadow: var(--sh-sm);
}
.pricing-table table {
    width: 100%;
    border-collapse: collapse;
}
.pricing-table th {
    background: var(--paper);
    padding: 0.85rem 1rem;
    text-align: left;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ink-3);
    border-bottom: 1px solid var(--line);
}
.pricing-table td {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--line);
}
.pricing-table tr:last-child td { border-bottom: none; }
.pricing-table tr:hover td { background: var(--green-lt2); }
.tier-badge {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: var(--radius-full);
    font-size: 0.65rem;
    font-weight: 700;
}
.tier-retail { background: var(--blue-lt); color: var(--blue); }
.tier-wholesale { background: var(--purple-lt); color: var(--purple); }
.savings-badge {
    color: var(--red);
    font-weight: 700;
    font-size: 0.8rem;
}

/* ═══ DESCRIPTION ═══ */
.description {
    background: var(--white);
    border-radius: var(--radius);
    padding: 1.5rem 2rem;
    border: 1px solid var(--line);
    line-height: 1.8;
}
.description p { margin-bottom: 0.75rem; }
.description p:last-child { margin-bottom: 0; }

/* ═══ SPECS ═══ */
.specs-table {
    background: var(--white);
    border-radius: var(--radius);
    padding: 1.5rem 2rem;
    border: 1px solid var(--line);
}
.specs-row {
    display: flex;
    padding: 0.6rem 0;
    border-bottom: 1px solid var(--line);
}
.specs-row:last-child { border-bottom: none; }
.specs-label {
    width: 140px;
    font-weight: 600;
    color: var(--ink-3);
    flex-shrink: 0;
}
.specs-value { flex: 1; color: var(--ink-2); }

/* ═══ REVIEWS ═══ */
.reviews-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    background: var(--white);
    border-radius: var(--radius);
    padding: 1.5rem 2rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--line);
}
.avg-rating-box {
    text-align: center;
    min-width: 120px;
}
.avg-number {
    font-size: 2.8rem;
    font-weight: 800;
    color: var(--ink);
    line-height: 1;
}
.rating-bars { flex: 1; min-width: 200px; }
.bar-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.4rem;
    font-size: 0.8rem;
}
.bar-label { width: 30px; color: var(--ink-3); }
.bar-fill {
    flex: 1;
    height: 8px;
    background: var(--line);
    border-radius: 4px;
    overflow: hidden;
}
.bar-fill span {
    display: block;
    height: 100%;
    background: var(--gold);
    border-radius: 4px;
    transition: width 0.6s ease;
}
.bar-count { width: 35px; text-align: right; color: var(--ink-4); }

.review-card {
    background: var(--white);
    border-radius: var(--radius);
    padding: 1.25rem 1.5rem;
    margin-bottom: 0.75rem;
    border: 1px solid var(--line);
    transition: var(--transition);
}
.review-card:hover { border-color: var(--gold); }
.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.4rem;
}
.review-user {
    font-weight: 700;
    color: var(--ink);
}
.review-date {
    font-size: 0.75rem;
    color: var(--ink-4);
}
.review-rating {
    color: var(--gold);
    font-size: 0.8rem;
    margin-bottom: 0.4rem;
}
.review-comment {
    color: var(--ink-2);
    line-height: 1.7;
}
.review-empty {
    text-align: center;
    padding: 2rem;
    color: var(--ink-4);
}

/* ═══ RELATED ═══ */
.related-section {
    margin-top: 3.5rem;
    animation: fadeInUp 0.5s ease 0.4s both;
}
.related-title {
    font-family: var(--fh);
    font-size: 1.5rem;
    font-weight: 400;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid var(--line);
}
.related-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1.25rem;
}
.rel-card {
    background: var(--white);
    border-radius: var(--radius);
    overflow: hidden;
    border: 1px solid var(--line);
    text-decoration: none;
    transition: var(--transition);
    box-shadow: var(--sh-sm);
}
.rel-card:hover {
    transform: translateY(-6px);
    box-shadow: var(--sh-lg);
    border-color: var(--gold);
}
.rel-image {
    aspect-ratio: 1;
    overflow: hidden;
    background: #f5f5f5;
}
.rel-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform var(--transition-slow);
}
.rel-card:hover .rel-image img { transform: scale(1.08); }
.rel-info {
    padding: 0.75rem 1rem;
}
.rel-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 0.2rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.rel-price {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--green);
}

/* ═══ TOAST ═══ */
.toast-container {
    position: fixed;
    bottom: 1.5rem;
    right: 1rem;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    pointer-events: none;
    max-width: calc(100vw - 2rem);
}
.toast {
    background: var(--white);
    border-radius: var(--radius-sm);
    padding: 0.85rem 1.2rem;
    box-shadow: var(--sh-xl);
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.85rem;
    font-weight: 500;
    pointer-events: auto;
    animation: slideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    border-left: 4px solid var(--green);
    min-width: 260px;
}
.toast.error { border-left-color: var(--red); }
.toast.info { border-left-color: var(--gold); }
.toast .toast-close {
    margin-left: auto;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--ink-4);
    font-size: 1.1rem;
    padding: 0 0 0 0.5rem;
    line-height: 1;
    transition: var(--transition);
}
.toast .toast-close:hover { color: var(--ink); }
@keyframes slideIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ═══ RESPONSIVE ═══ */
@media (max-width: 1024px) {
    .product-layout { gap: 1.5rem; }
    .related-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
}

@media (max-width: 768px) {
    .product-layout {
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    .gallery {
        position: relative;
        top: 0;
    }
    .thumbnail {
        width: 60px;
        height: 60px;
        min-width: 60px;
    }
    .related-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .actions {
        flex-wrap: wrap;
    }
    .btn-primary, .btn-outline {
        flex: 1;
        min-width: 120px;
        padding: 0.8rem 1rem;
    }
    .specs-label { width: 110px; }
    .product-page { padding: 0 0.75rem 2rem; }
    .price-block { padding: 1rem 1.25rem; }
    .description, .specs-table { padding: 1rem 1.25rem; }
    .reviews-summary { padding: 1rem 1.25rem; }
    .review-card { padding: 1rem 1.25rem; }
}

@media (max-width: 480px) {
    .product-page { padding: 0 0.5rem 1.5rem; }
    .related-grid { grid-template-columns: 1fr; }
    .reviews-summary {
        flex-direction: column;
        text-align: center;
    }
    .specs-label { width: 90px; font-size: 0.8rem; }
    .specs-value { font-size: 0.85rem; }
    .qty-group { width: 100%; }
    .qty-input { width: 100%; }
    .qty-btn { width: 48px; height: 48px; }
    .btn-primary, .btn-outline {
        min-width: 100%;
        padding: 0.85rem;
        font-size: 0.85rem;
    }
    .wishlist-btn {
        width: 48px;
        height: 48px;
        min-width: 48px;
    }
    .actions { gap: 0.5rem; }
    .tab-headers { gap: 1rem; }
    .tab-btn { font-size: 0.8rem; padding: 0.5rem 0; }
    .pricing-table th, .pricing-table td { padding: 0.6rem 0.75rem; font-size: 0.8rem; }
}

@media (max-width: 380px) {
    .thumbnail { width: 50px; height: 50px; min-width: 50px; }
    .product-title { font-size: 1.2rem; }
    .current-price { font-size: 1.5rem; }
    .stock-badge { font-size: 0.7rem; padding: 0.3rem 0.75rem; }
}
</style>

<div class="product-page">
    <!-- Breadcrumb -->
    <nav class="breadcrumb">
        <a href="index.php"><i class="fas fa-home"></i> Home</a>
        <span class="sep">›</span>
        <a href="shop.php">Shop</a>
        <?php if ($parent_cat_name): ?>
            <span class="sep">›</span>
            <a href="shop.php?category=<?php echo urlencode($parent_cat_slug); ?>"><?php echo htmlspecialchars($parent_cat_name); ?></a>
        <?php endif; ?>
        <span class="sep">›</span>
        <a href="shop.php?category=<?php echo urlencode($product['cat_slug']); ?>"><?php echo htmlspecialchars($product['cat_name']); ?></a>
        <span class="sep">›</span>
        <span class="current"><?php echo htmlspecialchars($product['name']); ?></span>
    </nav>

    <!-- Main Product Layout -->
    <div class="product-layout">
        <!-- Gallery -->
        <div class="gallery">
            <div class="main-image">
                <?php if (!empty($main_image)): ?>
                    <img src="<?php echo htmlspecialchars($main_image); ?>" 
                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                         id="mainImage"
                         loading="eager">
                <?php else: ?>
                    <img src="<?php echo ASSETS_URL; ?>images/placeholders/product-placeholder.jpg" 
                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                         id="mainImage">
                <?php endif; ?>
            </div>
            <?php if (count($images) > 1): ?>
            <div class="thumbnails">
                <?php foreach ($images as $idx => $img): ?>
                <div class="thumbnail <?php echo $idx === 0 ? 'active' : ''; ?>" 
                     data-image="<?php echo htmlspecialchars($img['url']); ?>"
                     onclick="changeMainImage(this)">
                    <img src="<?php echo htmlspecialchars($img['url']); ?>" 
                         alt="Thumbnail <?php echo $idx + 1; ?>"
                         loading="lazy">
                    <span class="check-mark"><i class="fas fa-check"></i></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Product Info -->
        <div class="product-info">
            <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>
            
            <?php if (!empty($product['brand'])): ?>
            <div class="product-brand">Brand: <strong><?php echo htmlspecialchars($product['brand']); ?></strong></div>
            <?php endif; ?>
            
            <div class="vendor-row">
                <span class="label">Sold by</span>
                <span class="vendor-name"><?php echo htmlspecialchars($product['vendor_name']); ?></span>
                <?php if ($product['vendor_express']): ?>
                <span class="express-badge"><i class="fas fa-bolt"></i> EXPRESS</span>
                <?php endif; ?>
                <?php if ($product['vendor_badge']): ?>
                <span class="vendor-badge"><?php echo htmlspecialchars($product['vendor_badge']); ?></span>
                <?php endif; ?>
            </div>
            
            <!-- Rating -->
            <div class="rating-row">
                <div class="stars">
                    <?php 
                    $full_stars = floor($avg_rating);
                    $half_star = ($avg_rating - $full_stars) >= 0.5;
                    for ($i = 0; $i < $full_stars; $i++) echo '★';
                    if ($half_star) echo '½';
                    for ($i = $full_stars + ($half_star ? 1 : 0); $i < 5; $i++) echo '☆';
                    ?>
                </div>
                <span class="rating-num"><?php echo number_format($avg_rating, 1); ?></span>
                <a href="#reviews" class="review-link">(<?php echo $review_count; ?> reviews)</a>
            </div>
            
            <!-- Price Block -->
            <div class="price-block">
                <?php if ($wholesale_only): ?>
                <div class="wholesale-only-badge"><i class="fas fa-boxes"></i> Wholesale Only</div>
                <?php endif; ?>
                
                <?php if ($default_price <= 0): ?>
                    <div class="price-row">
                        <span class="current-price" style="color: var(--ink-4);">Price on Request</span>
                    </div>
                    <div class="vat-note">Contact vendor for pricing</div>
                <?php else: ?>
                    <div class="price-row">
                        <span class="current-price" id="displayPrice"><?php echo _bm_fmt($default_price); ?></span>
                        <?php if ($has_discount && $default_original > $default_price): ?>
                            <span class="original-price" id="displayOriginal"><?php echo _bm_fmt($default_original); ?></span>
                            <span class="discount-badge" id="displayDiscount">-<?php echo $default_discount_pct; ?>%</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($has_wholesale && $wholesale_min_qty > 0): ?>
                    <div class="wholesale-note">
                        <i class="fas fa-tag"></i> 
                        Wholesale from <?php echo _bm_fmt($wholesale_price); ?> 
                        (min. <?php echo $wholesale_min_qty; ?> units)
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($promo): ?>
                    <div class="promo-tag">
                        <i class="fas fa-ticket-alt"></i> 
                        <?php echo htmlspecialchars($promo['promo_name']); ?> — 
                        <?php echo $promo['discount_type'] === 'percentage' ? $promo['discount_value'] . '% OFF' : 'GH₵ ' . number_format($promo['discount_value'], 2) . ' OFF'; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="vat-note">incl. VAT</div>
                <?php endif; ?>
            </div>
            
            <!-- Variant Selector -->
            <?php if ($has_variants): ?>
            <div class="variant-selector">
                <?php foreach ($variant_groups as $groupName => $groupVariants): ?>
                <div class="variant-group" data-group="<?php echo htmlspecialchars($groupName); ?>">
                    <span class="variant-group-label"><?php echo htmlspecialchars($groupName); ?>:</span>
                    <div class="variant-options">
                        <?php foreach ($groupVariants as $var):
                            $isDisabled = $var['available_stock'] <= 0 || $var['effective_price'] <= 0;
                            $active = false; // none active by default
                        ?>
                        <button class="variant-btn <?php echo $isDisabled ? 'disabled' : ''; ?>"
                                data-variant-id="<?php echo $var['id']; ?>"
                                data-tier-id="<?php echo $var['pricing_tier_id']; ?>"
                                data-price="<?php echo $var['effective_price']; ?>"
                                data-original="<?php echo $var['original_price']; ?>"
                                data-discount-pct="<?php echo $var['discount_pct']; ?>"
                                data-stock="<?php echo $var['available_stock']; ?>"
                                <?php echo $isDisabled ? 'disabled' : ''; ?>
                                onclick="selectVariant(this)">
                            <?php echo htmlspecialchars($var['variant_value']); ?>
                            <?php if ($isDisabled): ?> (N/A)<?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <!-- Hidden input to store selected tier id -->
                <input type="hidden" id="selectedTierId" value="<?php echo $default_tier_id; ?>">
                <input type="hidden" id="selectedVariantId" value="0">
            </div>
            <?php else: ?>
            <input type="hidden" id="selectedTierId" value="<?php echo $default_tier_id; ?>">
            <?php endif; ?>
            
            <!-- Stock Status -->
            <div class="stock-badge stock-<?php echo $stock_status === 'in_stock' ? 'in' : ($stock_status === 'low_stock' ? 'low' : 'out'); ?>" id="stockBadge">
                <?php echo $stock_icon; ?> <?php echo $stock_text; ?>
            </div>
            
            <!-- Quantity Selector -->
            <div class="qty-selector">
                <span class="qty-label">Quantity:</span>
                <div class="qty-group">
                    <button class="qty-btn" onclick="changeQty(-1)" <?php echo $stock_status === 'out_of_stock' ? 'disabled' : ''; ?> aria-label="Decrease quantity">−</button>
                    <input type="number" 
                           class="qty-input" 
                           id="productQty" 
                           value="1" 
                           min="1" 
                           max="999"
                           <?php echo $stock_status === 'out_of_stock' ? 'disabled' : ''; ?>
                           aria-label="Product quantity">
                    <button class="qty-btn" onclick="changeQty(1)" <?php echo $stock_status === 'out_of_stock' ? 'disabled' : ''; ?> aria-label="Increase quantity">+</button>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="actions">
                <button class="btn-primary" 
                        id="addToCartBtn"
                        data-product-id="<?php echo $product_id; ?>"
                        data-tier-id="<?php echo $default_tier_id; ?>"
                        <?php echo $stock_status === 'out_of_stock' ? 'disabled' : ''; ?>>
                    <i class="fas fa-cart-plus"></i> Add to Cart
                </button>
                <button class="btn-outline" 
                        id="buyNowBtn"
                        data-product-id="<?php echo $product_id; ?>"
                        data-tier-id="<?php echo $default_tier_id; ?>"
                        <?php echo $stock_status === 'out_of_stock' ? 'disabled' : ''; ?>>
                    <i class="fas fa-bolt"></i> Buy Now
                </button>
                <button class="wishlist-btn <?php echo $in_wishlist ? 'active' : ''; ?>" 
                        id="wishlistBtn"
                        data-product-id="<?php echo $product_id; ?>"
                        title="<?php echo $in_wishlist ? 'Remove from wishlist' : 'Add to wishlist'; ?>"
                        aria-label="Toggle wishlist">
                    <i class="fas <?php echo $in_wishlist ? 'fa-heart' : 'fa-heart'; ?>"></i>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Tabs Section -->
    <div class="product-tabs">
        <div class="tab-headers">
            <button class="tab-btn active" data-tab="description">Description</button>
            <button class="tab-btn" data-tab="pricing">Pricing</button>
            <button class="tab-btn" data-tab="specs">Specifications</button>
            <button class="tab-btn" data-tab="reviews">Reviews (<?php echo $review_count; ?>)</button>
        </div>
        
        <!-- Description Tab -->
        <div class="tab-panel active" id="description">
            <div class="description">
                <?php if (!empty($product['short_description'])): ?>
                    <p><strong><?php echo nl2br(htmlspecialchars($product['short_description'])); ?></strong></p>
                <?php endif; ?>
                <?php echo !empty($product['full_description']) ? nl2br(htmlspecialchars($product['full_description'])) : '<p>No detailed description available.</p>'; ?>
            </div>
        </div>
        
        <!-- Pricing Tab -->
        <div class="tab-panel" id="pricing">
            <?php if (!empty($all_tiers)): ?>
            <div class="pricing-table">
                <table>
                    <thead>
                        <tr>
                            <th>Tier Name</th>
                            <th>Type</th>
                            <th>Quantity Range</th>
                            <th>Unit Price</th>
                            <th>Effective Price</th>
                            <th>Savings</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_tiers as $tier): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($tier['tier_name']); ?></strong></td>
                            <td>
                                <span class="tier-badge tier-<?php echo $tier['pricing_type']; ?>">
                                    <?php echo ucfirst($tier['pricing_type']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $tier['min_quantity']; ?>
                                <?php if ($tier['max_quantity']): ?>
                                    – <?php echo $tier['max_quantity']; ?>
                                <?php else: ?>
                                    +
                                <?php endif; ?>
                            </td>
                            <td><?php echo _bm_fmt($tier['unit_price']); ?></td>
                            <td style="font-weight: 700; color: var(--green);">
                                <?php echo _bm_fmt($tier['effective_price']); ?>
                            </td>
                            <td>
                                <?php if ($tier['discount_percent_display'] > 0): ?>
                                    <span class="savings-badge">-<?php echo $tier['discount_percent_display']; ?>%</span>
                                <?php else: ?>
                                    <span style="color: var(--ink-4);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="description" style="text-align: center;">
                    <p>Pricing information not available. Please contact the vendor.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Specifications Tab -->
        <div class="tab-panel" id="specs">
            <div class="specs-table">
                <?php if (!empty($product['sku'])): ?>
                <div class="specs-row">
                    <div class="specs-label">SKU</div>
                    <div class="specs-value"><?php echo htmlspecialchars($product['sku']); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($product['brand'])): ?>
                <div class="specs-row">
                    <div class="specs-label">Brand</div>
                    <div class="specs-value"><?php echo htmlspecialchars($product['brand']); ?></div>
                </div>
                <?php endif; ?>
                
                <div class="specs-row">
                    <div class="specs-label">Category</div>
                    <div class="specs-value"><?php echo htmlspecialchars($product['cat_name']); ?></div>
                </div>
                
                <?php if (!empty($product['weight']) && $product['weight'] > 0): ?>
                <div class="specs-row">
                    <div class="specs-label">Weight</div>
                    <div class="specs-value"><?php echo number_format((float)$product['weight'], 2); ?> kg</div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($product['dimensions'])): ?>
                <div class="specs-row">
                    <div class="specs-label">Dimensions</div>
                    <div class="specs-value"><?php echo htmlspecialchars($product['dimensions']); ?></div>
                </div>
                <?php endif; ?>
                
                <div class="specs-row">
                    <div class="specs-label">Vendor</div>
                    <div class="specs-value"><?php echo htmlspecialchars($product['vendor_name']); ?></div>
                </div>
                
                <div class="specs-row">
                    <div class="specs-label">Availability</div>
                    <div class="specs-value" id="specStock"><?php echo $stock_text; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Reviews Tab -->
        <div class="tab-panel" id="reviews">
            <?php if ($review_count > 0): ?>
            <div class="reviews-summary">
                <div class="avg-rating-box">
                    <div class="avg-number"><?php echo number_format($avg_rating, 1); ?></div>
                    <div class="stars" style="justify-content: center;">
                        <?php
                        $full = floor($avg_rating);
                        $half = ($avg_rating - $full) >= 0.5;
                        for ($i = 0; $i < $full; $i++) echo '★';
                        if ($half) echo '½';
                        for ($i = $full + ($half ? 1 : 0); $i < 5; $i++) echo '☆';
                        ?>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--ink-4); margin-top: 0.25rem;">
                        <?php echo $review_count; ?> review<?php echo $review_count !== 1 ? 's' : ''; ?>
                    </div>
                </div>
                <div class="rating-bars">
                    <?php for ($star = 5; $star >= 1; $star--):
                        $pct = $review_count > 0 ? ($rating_counts[$star] / $review_count * 100) : 0;
                    ?>
                    <div class="bar-row">
                        <span class="bar-label"><?php echo $star; ?>★</span>
                        <div class="bar-fill"><span style="width: <?php echo $pct; ?>%;"></span></div>
                        <span class="bar-count"><?php echo $rating_counts[$star]; ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php foreach ($reviews_list as $rv): ?>
            <div class="review-card">
                <div class="review-header">
                    <span class="review-user"><?php echo htmlspecialchars($rv['user_name']); ?></span>
                    <span class="review-date"><?php echo date('M d, Y', strtotime($rv['created_at'])); ?></span>
                </div>
                <div class="review-rating">
                    <?php for ($i = 0; $i < (int)$rv['rating']; $i++) echo '★'; ?>
                    <?php for ($i = (int)$rv['rating']; $i < 5; $i++) echo '☆'; ?>
                </div>
                <?php if (!empty($rv['comment'])): ?>
                <div class="review-comment"><?php echo nl2br(htmlspecialchars($rv['comment'])); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($reviews_list)): ?>
            <div class="review-empty">
                <i class="fas fa-star" style="font-size: 2rem; color: var(--ink-4); margin-bottom: 0.5rem; display: block;"></i>
                <p>No reviews yet. Be the first to review this product!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Related Products -->
    <?php if (!empty($related)): ?>
    <div class="related-section">
        <h3 class="related-title">You May Also Like</h3>
        <div class="related-grid">
            <?php foreach ($related as $rel): ?>
            <a href="product-detail.php?slug=<?php echo urlencode($rel['slug']); ?>" class="rel-card">
                <div class="rel-image">
                    <?php if (!empty($rel['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($rel['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($rel['name']); ?>"
                             loading="lazy">
                    <?php else: ?>
                        <img src="<?php echo ASSETS_URL; ?>images/placeholders/product-placeholder.jpg" 
                             alt="<?php echo htmlspecialchars($rel['name']); ?>"
                             loading="lazy">
                    <?php endif; ?>
                </div>
                <div class="rel-info">
                    <div class="rel-name"><?php echo htmlspecialchars($rel['name']); ?></div>
                    <div class="rel-price"><?php echo _bm_fmt($rel['min_price']); ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<script>
// ════════════════════════════════════════════════════════════════════
// PRODUCT DETAIL SCRIPTS - Enhanced with Variant Support
// ════════════════════════════════════════════════════════════════════

// Gallery thumbnail switcher
function changeMainImage(element) {
    document.querySelectorAll('.thumbnail').forEach(t => t.classList.remove('active'));
    element.classList.add('active');
    const newImage = element.dataset.image;
    const mainImage = document.getElementById('mainImage');
    if (mainImage) {
        mainImage.style.opacity = '0.6';
        mainImage.src = newImage;
        mainImage.onload = function() {
            this.style.opacity = '1';
        };
        mainImage.onerror = function() {
            this.style.opacity = '1';
            this.src = '<?php echo ASSETS_URL; ?>images/placeholders/product-placeholder.jpg';
        };
    }
}

// Quantity controls
function changeQty(delta) {
    const input = document.getElementById('productQty');
    if (!input) return;
    let val = parseInt(input.value) || 1;
    val = Math.max(1, Math.min(999, val + delta));
    input.value = val;
    input.dispatchEvent(new Event('change'));
}

document.getElementById('productQty')?.addEventListener('change', function() {
    let val = parseInt(this.value) || 1;
    this.value = Math.max(1, Math.min(999, val));
});

// ─── VARIANT SELECTION ──────────────────────────────────────────
function selectVariant(btn) {
    if (btn.disabled) return;
    // Deselect other buttons in the same group
    const group = btn.closest('.variant-group');
    group.querySelectorAll('.variant-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Update price and tier
    const price = parseFloat(btn.dataset.price) || 0;
    const original = parseFloat(btn.dataset.original) || 0;
    const discountPct = parseInt(btn.dataset.discountPct) || 0;
    const tierId = parseInt(btn.dataset.tierId) || 0;
    const stock = parseInt(btn.dataset.stock) || 0;
    
    document.getElementById('selectedTierId').value = tierId;
    document.getElementById('selectedVariantId').value = btn.dataset.variantId;
    
    // Update price display
    const priceEl = document.getElementById('displayPrice');
    const origEl = document.getElementById('displayOriginal');
    const discEl = document.getElementById('displayDiscount');
    
    if (priceEl) {
        priceEl.textContent = 'GH₵ ' + price.toFixed(2);
    }
    if (origEl) {
        if (discountPct > 0 && original > price) {
            origEl.textContent = 'GH₵ ' + original.toFixed(2);
            origEl.style.display = 'inline';
        } else {
            origEl.style.display = 'none';
        }
    }
    if (discEl) {
        if (discountPct > 0) {
            discEl.textContent = '-' + discountPct + '%';
            discEl.style.display = 'inline-block';
        } else {
            discEl.style.display = 'none';
        }
    }
    
    // Update stock status
    const stockBadge = document.getElementById('stockBadge');
    const specStock = document.getElementById('specStock');
    if (stockBadge) {
        if (stock <= 0) {
            stockBadge.className = 'stock-badge stock-out';
            stockBadge.innerHTML = '❌ Out of Stock';
        } else if (stock <= 10) {
            stockBadge.className = 'stock-badge stock-low';
            stockBadge.innerHTML = '⚠ Only ' + stock + ' left';
        } else {
            stockBadge.className = 'stock-badge stock-in';
            stockBadge.innerHTML = '✅ In Stock';
        }
    }
    if (specStock) {
        if (stock <= 0) specStock.textContent = 'Out of Stock';
        else if (stock <= 10) specStock.textContent = 'Low Stock (' + stock + ' left)';
        else specStock.textContent = 'In Stock';
    }
    
    // Enable/disable add-to-cart buttons
    const addBtn = document.getElementById('addToCartBtn');
    const buyBtn = document.getElementById('buyNowBtn');
    if (stock <= 0) {
        if (addBtn) { addBtn.disabled = true; addBtn.innerHTML = '<i class="fas fa-times"></i> Out of Stock'; }
        if (buyBtn) { buyBtn.disabled = true; buyBtn.innerHTML = '<i class="fas fa-times"></i> Out of Stock'; }
    } else {
        if (addBtn) { addBtn.disabled = false; addBtn.innerHTML = '<i class="fas fa-cart-plus"></i> Add to Cart'; }
        if (buyBtn) { buyBtn.disabled = false; buyBtn.innerHTML = '<i class="fas fa-bolt"></i> Buy Now'; }
    }
    
    // Update data-tier-id on buttons
    if (addBtn) addBtn.dataset.tierId = tierId;
    if (buyBtn) buyBtn.dataset.tierId = tierId;
}

// ─── TOAST ──────────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    
    let icon = '';
    if (type === 'success') icon = '✅ ';
    else if (type === 'error') icon = '❌ ';
    else icon = 'ℹ️ ';
    
    toast.innerHTML = icon + message + 
        '<button class="toast-close" onclick="this.parentElement.remove()" aria-label="Close notification">&times;</button>';
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
    
    const toasts = container.querySelectorAll('.toast');
    while (toasts.length > 3) toasts[0].remove();
}

// Update cart badge
function updateCartBadge(count) {
    const headerBadge = document.getElementById('cartCount');
    if (headerBadge) {
        headerBadge.textContent = count;
        headerBadge.style.display = count > 0 ? 'inline-block' : 'none';
    }
}

// ─── ADD TO CART ───────────────────────────────────────────────
document.getElementById('addToCartBtn')?.addEventListener('click', async function() {
    const btn = this;
    if (btn.disabled) return;
    
    const productId = parseInt(btn.dataset.productId);
    const tierId = parseInt(document.getElementById('selectedTierId').value) || parseInt(btn.dataset.tierId);
    const quantity = parseInt(document.getElementById('productQty')?.value) || 1;
    
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Adding...';
    btn.disabled = true;
    
    const formData = new URLSearchParams();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('pricing_tier_id', tierId);
    formData.append('quantity', quantity);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData.toString()
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast(data.message, 'success');
            updateCartBadge(data.cart_count);
            
            btn.innerHTML = '<i class="fas fa-check"></i> Added!';
            btn.classList.add('added');
            
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.classList.remove('added');
                // Re-check stock state (could be disabled if out)
                const stockBadge = document.getElementById('stockBadge');
                if (stockBadge && stockBadge.classList.contains('stock-out')) {
                    btn.disabled = true;
                } else {
                    btn.disabled = false;
                }
            }, 2000);
        } else {
            showToast(data.message || 'Failed to add to cart', 'error');
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    } catch (error) {
        console.error('Add to cart error:', error);
        showToast('Connection error. Please try again.', 'error');
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
});

// ─── BUY NOW ──────────────────────────────────────────────────
document.getElementById('buyNowBtn')?.addEventListener('click', async function() {
    const btn = this;
    if (btn.disabled) return;
    
    const productId = parseInt(btn.dataset.productId);
    const tierId = parseInt(document.getElementById('selectedTierId').value) || parseInt(btn.dataset.tierId);
    const quantity = parseInt(document.getElementById('productQty')?.value) || 1;
    
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Processing...';
    btn.disabled = true;
    
    const formData = new URLSearchParams();
    formData.append('action', 'buy_now');
    formData.append('product_id', productId);
    formData.append('pricing_tier_id', tierId);
    formData.append('quantity', quantity);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData.toString()
        });
        
        const data = await response.json();
        
        if (data.success && data.redirect) {
            window.location.href = data.redirect;
        } else {
            showToast(data.message || 'Error processing request', 'error');
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    } catch (error) {
        console.error('Buy now error:', error);
        showToast('Connection error. Please try again.', 'error');
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
});

// ─── WISHLIST ──────────────────────────────────────────────────
document.getElementById('wishlistBtn')?.addEventListener('click', async function() {
    const btn = this;
    const productId = parseInt(btn.dataset.productId);
    
    <?php if (Auth::isLoggedIn()): ?>
    btn.disabled = true;
    
    const formData = new URLSearchParams();
    formData.append('action', 'toggle_wishlist');
    formData.append('product_id', productId);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData.toString()
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (data.action === 'added') {
                btn.classList.add('active');
                btn.title = 'Remove from wishlist';
                showToast('Added to wishlist! ❤️', 'success');
            } else {
                btn.classList.remove('active');
                btn.title = 'Add to wishlist';
                showToast('Removed from wishlist', 'info');
            }
        } else if (data.redirect) {
            window.location.href = data.redirect;
        } else {
            showToast(data.message || 'Error updating wishlist', 'error');
        }
    } catch (error) {
        console.error('Wishlist error:', error);
        showToast('Connection error', 'error');
    } finally {
        btn.disabled = false;
    }
    <?php else: ?>
    window.location.href = 'login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>';
    <?php endif; ?>
});

// ─── TABS ──────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabId = this.dataset.tab;
        
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        document.querySelectorAll('.tab-panel').forEach(panel => {
            panel.classList.remove('active');
            panel.style.opacity = '0';
        });
        
        const targetPanel = document.getElementById(tabId);
        if (targetPanel) {
            targetPanel.classList.add('active');
            requestAnimationFrame(() => {
                targetPanel.style.opacity = '1';
                targetPanel.style.transition = 'opacity 0.3s ease';
            });
        }
    });
});

// ─── KEYBOARD SHORTCUTS ──────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.toast').forEach(t => t.remove());
    }
});

// ─── CONSOLE ──────────────────────────────────────────────────
console.log('%c🛍️ Bless Mall Product Detail %cv12 (Variants + Images)',
    'font-size:1.2em;font-weight:bold;color:#0a6e3d;',
    'font-size:1em;color:#c9a227;');
console.log('%c✨ Variant support • Rock‑solid image handling', 'font-style:italic;color:#666;');
</script>

<?php require_once __DIR__ . '/footer.php'; ?>