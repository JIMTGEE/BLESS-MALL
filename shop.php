<?php
/**
 * shop.php — Bless Mall Product Listing v11 (Ultra-Premium)
 * Cinema-Quality E-Commerce Experience with Hidden Filters
 * 
 * Features:
 *  - Hidden filter drawer (slide-out on button click)
 *  - Dynamic hero with category showcase
 *  - Premium product cards with micro-interactions
 *  - Image slideshow per product (auto-play + touch support)
 *  - Variant selection (size/color) with real-time price update
 *  - Real-time cart with quantity controls
 *  - Wishlist management
 *  - Multi-tier pricing support
 *  - Fully responsive with smooth animations
 *  - All AJAX endpoints embedded
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!defined('BLESSMALL_CONFIG_INCLUDED')) { require_once __DIR__ . '/includes/config.php'; }
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ════════════════════════════════════════════════════════════════════
// BASE URL HELPER
// ════════════════════════════════════════════════════════════════════
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    define('BASE_URL', $protocol . $host . ($basePath ? '/' . ltrim($basePath, '/') : ''));
}

// ════════════════════════════════════════════════════════════════════
// PRICE FORMATTER
// ════════════════════════════════════════════════════════════════════
function _bm_fmt($v) {
    $n = (float) $v;
    if ($n <= 0) return 'GH₵ 0.00';
    return 'GH₵ ' . number_format($n, 2, '.', ',');
}

// ════════════════════════════════════════════════════════════════════
// HANDLE ALL AJAX REQUESTS INTERNALLY
// ════════════════════════════════════════════════════════════════════
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $conn = getConnection();
    
    // ═══ ADD TO CART (with optional variant) ═══
    if ($action === 'add_to_cart') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $pricing_tier_id = (int)($_POST['pricing_tier_id'] ?? 0);
        $variant_id = (int)($_POST['variant_id'] ?? 0);
        
        // If a variant is specified, find its default retail tier
        if ($variant_id > 0 && $pricing_tier_id === 0) {
            $vt = $conn->prepare("
                SELECT id FROM product_pricing_tiers 
                WHERE product_variant_id = ? AND pricing_type = 'retail' AND is_active = 1
                ORDER BY min_quantity ASC LIMIT 1
            ");
            $vt->bind_param("i", $variant_id);
            $vt->execute();
            $tier = $vt->get_result()->fetch_assoc();
            if ($tier) $pricing_tier_id = (int)$tier['id'];
            $vt->close();
        }
        
        if ($pricing_tier_id > 0) {
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.is_active, p.slug,
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
            // fallback to any retail tier
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.is_active, p.slug,
                       ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                       ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
                       ppt.min_quantity, ppt.max_quantity
                FROM products p
                JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                WHERE p.id = ? AND ppt.pricing_type = 'retail' AND p.is_active = 1 AND ppt.is_active = 1
                ORDER BY ppt.min_quantity ASC
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
        
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        
        $cart_key = 'p_' . $product_id . '_t_' . $product['pricing_tier_id'];
        if (isset($_SESSION['cart'][$cart_key])) {
            $_SESSION['cart'][$cart_key]['quantity'] += $quantity;
        } else {
            $img_path = '';
            $imgStmt = $conn->prepare("
                SELECT image_path FROM product_images 
                WHERE product_id = ? 
                ORDER BY is_primary DESC, sort_order ASC LIMIT 1
            ");
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
                'min_quantity' => $product['min_quantity'],
                'quantity' => $quantity,
                'image' => $img_path,
                'added_at' => time()
            ];
        }
        
        $total_items = 0;
        foreach ($_SESSION['cart'] as $item) {
            $total_items += (int)$item['quantity'];
        }
        
        echo json_encode([
            'success' => true,
            'message' => htmlspecialchars($product['name']) . ' added to cart!',
            'cart_count' => $total_items,
            'product_id' => $product_id,
            'pricing_tier_id' => $product['pricing_tier_id'],
            'quantity' => $_SESSION['cart'][$cart_key]['quantity'],
            'cart_key' => $cart_key
        ]);
        exit;
    }
    
    // ═══ UPDATE CART QUANTITY ═══
    if ($action === 'update_cart_quantity') {
        $cart_key = $_POST['cart_key'] ?? '';
        $new_quantity = max(0, (int)($_POST['quantity'] ?? 1));
        
        if (empty($cart_key) || !isset($_SESSION['cart'][$cart_key])) {
            echo json_encode(['success' => false, 'message' => 'Item not found in cart']);
            exit;
        }
        
        if ($new_quantity === 0) {
            unset($_SESSION['cart'][$cart_key]);
        } else {
            $_SESSION['cart'][$cart_key]['quantity'] = $new_quantity;
        }
        
        $total_items = 0;
        foreach ($_SESSION['cart'] as $item) {
            $total_items += (int)$item['quantity'];
        }
        
        echo json_encode([
            'success' => true,
            'cart_count' => $total_items,
            'quantity' => $new_quantity,
            'cart_key' => $cart_key
        ]);
        exit;
    }
    
    // ═══ REMOVE FROM CART ═══
    if ($action === 'remove_from_cart') {
        $cart_key = $_POST['cart_key'] ?? '';
        
        if (isset($_SESSION['cart'][$cart_key])) {
            unset($_SESSION['cart'][$cart_key]);
        }
        
        $total_items = 0;
        foreach ($_SESSION['cart'] as $item) {
            $total_items += (int)$item['quantity'];
        }
        
        echo json_encode([
            'success' => true,
            'cart_count' => $total_items,
            'message' => 'Item removed from cart',
            'cart_key' => $cart_key
        ]);
        exit;
    }
    
    // ═══ TOGGLE WISHLIST ═══
    if ($action === 'toggle_wishlist') {
        if (!Auth::isLoggedIn()) {
            echo json_encode([
                'success' => false, 
                'message' => 'Please login to manage wishlist',
                'redirect' => 'login.php?redirect=' . urlencode($_SERVER['HTTP_REFERER'] ?? 'shop.php')
            ]);
            exit;
        }
        
        $product_id = (int)($_POST['product_id'] ?? 0);
        $user_id = (int)$_SESSION['user_id'];
        
        $stmt = $conn->prepare("SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $user_id, $product_id);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        
        if ($exists) {
            $del = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
            $del->bind_param("ii", $user_id, $product_id);
            $del->execute();
            $del->close();
            echo json_encode(['success' => true, 'action' => 'removed', 'message' => 'Removed from wishlist']);
        } else {
            $ins = $conn->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)");
            $ins->bind_param("ii", $user_id, $product_id);
            $ins->execute();
            $ins->close();
            echo json_encode(['success' => true, 'action' => 'added', 'message' => 'Added to wishlist! ❤️']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ════════════════════════════════════════════════════════════════════
// PAGE PARAMETERS
// ════════════════════════════════════════════════════════════════════
$page           = isset($_GET['page'])      ? max(1, (int)$_GET['page'])  : 1;
$category_slug  = isset($_GET['category'])  ? trim($_GET['category'])     : '';
$search_query   = isset($_GET['search'])    ? trim($_GET['search'])       : '';
$sort_by        = isset($_GET['sort'])      ? trim($_GET['sort'])         : 'newest';
$min_price      = isset($_GET['min_price']) ? (float)$_GET['min_price']  : 0;
$max_price      = isset($_GET['max_price']) ? (float)$_GET['max_price']  : 0;
$brand_filter   = isset($_GET['brand'])     ? trim($_GET['brand'])        : '';
$in_stock_only  = isset($_GET['in_stock']);
$featured_only  = isset($_GET['featured']);
$promotion_only = isset($_GET['on_sale']);

$allowed_sorts = ['newest','oldest','price_asc','price_desc','name_asc','name_desc','popular','rating'];
if (!in_array($sort_by, $allowed_sorts)) $sort_by = 'newest';

$per_page = defined('PRODUCTS_PER_PAGE') ? (int)PRODUCTS_PER_PAGE : 24;
$offset   = ($page - 1) * $per_page;

// ════════════════════════════════════════════════════════════════════
// BUILD CART MAP FOR IN-CART DETECTION
// ════════════════════════════════════════════════════════════════════
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

// Build wishlist map
$wishlist_map = [];
if (Auth::isLoggedIn()) {
    $conn = getConnection();
    $wishResult = $conn->query("SELECT product_id FROM wishlist WHERE user_id = " . (int)$_SESSION['user_id']);
    if ($wishResult) {
        while ($row = $wishResult->fetch_assoc()) {
            $wishlist_map[(int)$row['product_id']] = true;
        }
    }
}

// ════════════════════════════════════════════════════════════════════
// IMAGE HELPER
// ════════════════════════════════════════════════════════════════════
function getProductImageUrl($imagePath) {
    if (empty($imagePath)) return '';
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) return $imagePath;
    
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
    
    return '';
}

// ════════════════════════════════════════════════════════════════════
// DATA FETCHING
// ════════════════════════════════════════════════════════════════════
$products = [];
$total_products = 0;
$all_cats = [];
$cat_tree = [];
$cat_counts = [];
$raw_cnt = [];
$brands = [];
$price_range = ['min' => 0, 'max' => 10000];
$active_cat_id = 0;
$active_cat_ids = [];
$error_message = '';
$featured_categories = [];

try {
    $conn = getConnection();

    // Fetch all categories with images
    $cr = $conn->query("SELECT id, name, slug, parent_id, icon, image FROM categories WHERE is_active = 1 ORDER BY COALESCE(parent_id, id) ASC, name ASC");
    while ($row = $cr->fetch_assoc()) {
        $all_cats[(int)$row['id']] = $row;
        $pk = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
        $cat_tree[$pk][] = $row;
    }

    // Get featured categories (categories with most products)
    $fcQuery = "
        SELECT c.id, c.name, c.slug, c.image, COUNT(p.id) as product_count
        FROM categories c
        INNER JOIN products p ON c.id = p.category_id AND p.is_active = 1
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY COUNT(p.id) DESC
        LIMIT 6
    ";
    $fcResult = $conn->query($fcQuery);
    if ($fcResult) {
        while ($row = $fcResult->fetch_assoc()) {
            $featured_categories[] = $row;
        }
    }

    // Resolve active category
    if (!empty($category_slug)) {
        foreach ($all_cats as $cid => $cat) {
            if ($cat['slug'] === $category_slug) { $active_cat_id = $cid; break; }
        }
        if ($active_cat_id) {
            $active_cat_ids = [$active_cat_id];
            if (isset($cat_tree[$active_cat_id])) {
                foreach ($cat_tree[$active_cat_id] as $ch) {
                    $active_cat_ids[] = (int)$ch['id'];
                }
            }
        }
    }

    // Product counts per category
    $cnr = $conn->query("SELECT category_id, COUNT(*) AS n FROM products WHERE is_active=1 GROUP BY category_id");
    while ($r = $cnr->fetch_assoc()) $raw_cnt[(int)$r['category_id']] = (int)$r['n'];
    foreach ($all_cats as $cid => $cat) {
        $cat_counts[$cid] = $raw_cnt[$cid] ?? 0;
    }
    foreach ($all_cats as $cid => $cat) {
        if (!empty($cat['parent_id'])) {
            $pid = (int)$cat['parent_id'];
            if (isset($cat_counts[$pid])) $cat_counts[$pid] += $cat_counts[$cid];
        }
    }

    // Brands
    $br = $conn->query("SELECT brand, COUNT(*) n FROM products WHERE is_active=1 AND brand IS NOT NULL AND brand!='' GROUP BY brand ORDER BY brand LIMIT 30");
    if ($br) {
        while ($b = $br->fetch_assoc()) $brands[] = ['name'=>$b['brand'], 'n'=>(int)$b['n']];
    }

    // Price range
    $pqr = $conn->query("
        SELECT MIN(COALESCE(NULLIF(discount_price,0), unit_price)) mn, MAX(unit_price) mx
        FROM product_pricing_tiers WHERE is_active = 1
    ");
    if ($pqr && $r = $pqr->fetch_assoc()) {
        $price_range['min'] = (int)floor((float)($r['mn'] ?? 0));
        $price_range['max'] = (int)ceil((float)($r['mx'] ?? 10000));
    }

    // Build product query
    $base_select = "
        p.id, p.name, p.slug, p.short_description, p.sku, p.brand,
        p.featured, p.created_at,
        c.id as cat_id, c.name as cat_name, c.slug as cat_slug, c.parent_id as cat_pid,
        v.business_name as vendor_name, v.is_express as vendor_express,
        COALESCE(rev.review_count, 0) as review_count,
        COALESCE(rev.avg_rating, 0) as avg_rating
    ";
    
    $base_from = "
        FROM products p
        INNER JOIN categories c ON p.category_id = c.id AND c.is_active = 1
        INNER JOIN vendors v ON p.vendor_id = v.id AND v.status = 'active'
        LEFT JOIN (
            SELECT product_id, COUNT(*) as review_count, AVG(rating) as avg_rating
            FROM reviews GROUP BY product_id
        ) rev ON p.id = rev.product_id
        WHERE p.is_active = 1
    ";

    $wh = []; $params = []; $types = '';

    if (!empty($active_cat_ids)) {
        $ph = implode(',', array_fill(0, count($active_cat_ids), '?'));
        $wh[] = "p.category_id IN ($ph)";
        foreach ($active_cat_ids as $id) { $params[] = $id; $types .= 'i'; }
    }
    if (!empty($search_query)) {
        $wh[] = '(p.name LIKE ? OR p.short_description LIKE ? OR p.brand LIKE ? OR p.sku LIKE ?)';
        $sp = "%$search_query%";
        array_push($params, $sp, $sp, $sp, $sp);
        $types .= 'ssss';
    }
    if (!empty($brand_filter)) { 
        $wh[] = 'p.brand = ?'; 
        $params[] = $brand_filter; 
        $types .= 's'; 
    }
    if ($featured_only) $wh[] = 'p.featured = 1';
    
    if ($min_price > 0 || $max_price > 0) {
        $wh[] = "EXISTS (
            SELECT 1 FROM product_pricing_tiers ppt2 
            WHERE ppt2.product_id = p.id 
            AND ppt2.is_active = 1 
            AND COALESCE(NULLIF(ppt2.discount_price,0), ppt2.unit_price) >= ?
        )";
        $params[] = $min_price;
        $types .= 'd';
        
        if ($max_price > 0) {
            $wh[] = "EXISTS (
                SELECT 1 FROM product_pricing_tiers ppt3 
                WHERE ppt3.product_id = p.id 
                AND ppt3.is_active = 1 
                AND COALESCE(NULLIF(ppt3.discount_price,0), ppt3.unit_price) <= ?
            )";
            $params[] = $max_price;
            $types .= 'd';
        }
    }
    
    if ($in_stock_only) {
        $wh[] = "EXISTS (
            SELECT 1 FROM product_inventory pi2
            JOIN product_pricing_tiers ppt4 ON pi2.pricing_tier_id = ppt4.id
            WHERE ppt4.product_id = p.id
            AND (pi2.stock_quantity - pi2.reserved_quantity) > 0
        )";
    }
    
    if ($promotion_only) {
        $wh[] = "EXISTS (
            SELECT 1 FROM product_promotions pp2
            INNER JOIN promotions prom2 ON pp2.promotion_id = prom2.id
            WHERE pp2.product_id = p.id
            AND prom2.is_active = 1
            AND prom2.start_date <= CURDATE()
            AND prom2.end_date >= CURDATE()
        )";
    }

    $wsql = $wh ? ' AND ' . implode(' AND ', $wh) : '';

    // Sort
    $extra_join = '';
    $ord = match($sort_by) {
        'oldest'     => 'p.created_at ASC',
        'price_asc'  => 'MIN(COALESCE(NULLIF(ppt.unit_price,0), ppt.discount_price)) ASC',
        'price_desc' => 'MAX(ppt.unit_price) DESC',
        'name_asc'   => 'p.name ASC',
        'name_desc'  => 'p.name DESC',
        'popular'    => 'rev.review_count DESC, rev.avg_rating DESC',
        'rating'     => 'rev.avg_rating DESC, rev.review_count DESC',
        default      => 'p.created_at DESC',
    };

    if (in_array($sort_by, ['price_asc', 'price_desc'])) {
        $extra_join = " LEFT JOIN product_pricing_tiers ppt ON p.id = ppt.product_id AND ppt.is_active = 1";
    }

    // Count query
    $cs = $conn->prepare("SELECT COUNT(DISTINCT p.id) as n $base_from $extra_join $wsql");
    if (!empty($params)) {
        $cs->bind_param($types, ...$params);
    }
    $cs->execute();
    $total_products = (int)($cs->get_result()->fetch_assoc()['n'] ?? 0);
    $cs->close();

    // Fetch products
    $dsql = "SELECT DISTINCT $base_select $base_from $extra_join $wsql GROUP BY p.id ORDER BY $ord LIMIT ? OFFSET ?";
    $dp = array_merge($params, [$per_page, $offset]);
    $dt = $types . 'ii';
    $ds = $conn->prepare($dsql);
    if (!empty($dp)) {
        $ds->bind_param($dt, ...$dp);
    }
    $ds->execute();
    $dr = $ds->get_result();
    $pids = [];
    $raw_products = [];
    while ($row = $dr->fetch_assoc()) { 
        $pids[] = (int)$row['id']; 
        $raw_products[] = $row; 
    }
    $ds->close();

    // Fetch all images for these products
    $images_map = [];
    if (!empty($pids)) {
        $ph_images = implode(',', array_fill(0, count($pids), '?'));
        $img_stmt = $conn->prepare("
            SELECT product_id, image_path, is_primary, sort_order
            FROM product_images
            WHERE product_id IN ($ph_images)
            ORDER BY is_primary DESC, sort_order ASC
        ");
        $img_stmt->bind_param(str_repeat('i', count($pids)), ...$pids);
        $img_stmt->execute();
        $img_res = $img_stmt->get_result();
        while ($img = $img_res->fetch_assoc()) {
            $pid = (int)$img['product_id'];
            if (!isset($images_map[$pid])) $images_map[$pid] = [];
            $images_map[$pid][] = $img['image_path'];
        }
        $img_stmt->close();
    }

    // Fetch variants with their pricing tiers
    $variants_map = [];
    if (!empty($pids)) {
        $ph_variants = implode(',', array_fill(0, count($pids), '?'));
        $var_stmt = $conn->prepare("
            SELECT pv.id, pv.product_id, pv.variant_name, pv.variant_value, pv.sku_suffix,
                   ppt.id as pricing_tier_id, ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
                   COALESCE(pi.stock_quantity - pi.reserved_quantity, 0) as available_stock
            FROM product_variants pv
            LEFT JOIN product_pricing_tiers ppt ON pv.id = ppt.product_variant_id AND ppt.is_active = 1 AND ppt.pricing_type = 'retail'
            LEFT JOIN product_inventory pi ON ppt.id = pi.pricing_tier_id
            WHERE pv.product_id IN ($ph_variants)
            ORDER BY pv.variant_name ASC, pv.variant_value ASC
        ");
        $var_stmt->bind_param(str_repeat('i', count($pids)), ...$pids);
        $var_stmt->execute();
        $var_res = $var_stmt->get_result();
        while ($var = $var_res->fetch_assoc()) {
            $pid = (int)$var['product_id'];
            if (!isset($variants_map[$pid])) $variants_map[$pid] = [];
            // Group by variant_name:value
            $key = $var['variant_name'] . ':' . $var['variant_value'];
            if (!isset($variants_map[$pid][$key])) {
                $variants_map[$pid][$key] = [
                    'id' => (int)$var['id'],
                    'name' => $var['variant_name'],
                    'value' => $var['variant_value'],
                    'sku_suffix' => $var['sku_suffix'],
                    'pricing_tier_id' => (int)($var['pricing_tier_id'] ?? 0),
                    'unit_price' => (float)($var['unit_price'] ?? 0),
                    'discount_price' => (float)($var['discount_price'] ?? 0),
                    'discount_percentage' => (float)($var['discount_percentage'] ?? 0),
                    'available_stock' => (int)($var['available_stock'] ?? 0)
                ];
            }
        }
        $var_stmt->close();
    }

    // Fetch all pricing tiers (for default price if no variant selected)
    $tier_map = [];
    if (!empty($pids)) {
        $ph_prices = implode(',', array_fill(0, count($pids), '?'));
        $tier_stmt = $conn->prepare("
            SELECT id, product_id, pricing_type, tier_name, 
                   min_quantity, max_quantity, unit_price, discount_price, discount_percentage
            FROM product_pricing_tiers 
            WHERE product_id IN($ph_prices) AND is_active = 1
            ORDER BY FIELD(pricing_type, 'retail', 'wholesale'), min_quantity ASC
        ");
        $tier_stmt->bind_param(str_repeat('i', count($pids)), ...$pids);
        $tier_stmt->execute();
        $tier_result = $tier_stmt->get_result();
        while ($tier = $tier_result->fetch_assoc()) {
            $tier_map[(int)$tier['product_id']][] = $tier;
        }
        $tier_stmt->close();
    }

    // Fetch inventory (for stock status)
    $inv_map = [];
    if (!empty($pids)) {
        $ph_inv = implode(',', array_fill(0, count($pids), '?'));
        $inv_stmt = $conn->prepare("
            SELECT pt.product_id, 
                   SUM(pi.stock_quantity) as total_stock,
                   SUM(pi.reserved_quantity) as total_reserved
            FROM product_inventory pi
            JOIN product_pricing_tiers pt ON pi.pricing_tier_id = pt.id
            WHERE pt.product_id IN($ph_inv)
            GROUP BY pt.product_id
        ");
        $inv_stmt->bind_param(str_repeat('i', count($pids)), ...$pids);
        $inv_stmt->execute();
        $inv_result = $inv_stmt->get_result();
        while ($inv = $inv_result->fetch_assoc()) {
            $inv_map[(int)$inv['product_id']] = $inv;
        }
        $inv_stmt->close();
    }

    // Build products array
    foreach ($raw_products as $row) {
        $pid = (int)$row['id'];
        $tiers = $tier_map[$pid] ?? [];
        $inv = $inv_map[$pid] ?? ['total_stock' => 0, 'total_reserved' => 0];
        $images = $images_map[$pid] ?? [];
        $variants = $variants_map[$pid] ?? [];
        
        // Default retail tier
        $retail_tiers = array_values(array_filter($tiers, fn($t) => $t['pricing_type'] === 'retail'));
        $default_tier = !empty($retail_tiers) ? $retail_tiers[0] : (!empty($tiers) ? $tiers[0] : null);
        
        $unit_price = $default_tier ? (float)$default_tier['unit_price'] : 0;
        $discount_price = $default_tier ? (float)($default_tier['discount_price'] ?? 0) : 0;
        $discount_pct = $default_tier ? (float)($default_tier['discount_percentage'] ?? 0) : 0;
        
        $eff_price = $unit_price;
        if ($discount_price > 0 && $discount_price < $unit_price) {
            $eff_price = $discount_price;
        } elseif ($discount_pct > 0) {
            $eff_price = round($unit_price * (1 - $discount_pct / 100), 2);
        }
        
        $orig_price = $unit_price;
        $has_discount = ($eff_price < $orig_price);
        $discount_percent = $has_discount ? round(($orig_price - $eff_price) / $orig_price * 100) : 0;
        
        $avail = (int)$inv['total_stock'] - (int)$inv['total_reserved'];
        $stock_status = $avail > 10 ? 'in_stock' : ($avail > 0 ? 'low_stock' : 'out_of_stock');
        
        // Build image URLs
        $image_urls = [];
        foreach ($images as $img_path) {
            $url = getProductImageUrl($img_path);
            if ($url) $image_urls[] = $url;
        }
        if (empty($image_urls)) {
            $image_urls[] = BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
        }
        
        // Build variants array (grouped by name)
        $variant_groups = [];
        foreach ($variants as $key => $var) {
            $name = $var['name'];
            if (!isset($variant_groups[$name])) $variant_groups[$name] = [];
            $variant_groups[$name][] = $var;
        }
        
        // Determine if product has any variants
        $has_variants = !empty($variant_groups);
        
        // In cart detection
        $in_cart = isset($cart_map[$pid]);
        $cart_qty = $in_cart ? $cart_map[$pid]['total_qty'] : 0;
        $cart_key = $in_cart ? $cart_map[$pid]['cart_key'] : '';
        $cart_tier_id = $in_cart ? $cart_map[$pid]['pricing_tier_id'] : 0;
        
        $products[] = [
            'id' => $pid,
            'name' => $row['name'],
            'slug' => $row['slug'],
            'short_desc' => $row['short_description'],
            'brand' => $row['brand'],
            'cat_name' => $row['cat_name'],
            'cat_slug' => $row['cat_slug'],
            'cat_parent' => $row['cat_pid'] && isset($all_cats[(int)$row['cat_pid']]) ? $all_cats[(int)$row['cat_pid']]['name'] : null,
            'vendor' => $row['vendor_name'],
            'express' => (bool)$row['vendor_express'],
            'images' => $image_urls,
            'orig' => $orig_price,
            'eff' => $eff_price,
            'unit' => $unit_price,
            'dpct' => $discount_percent,
            'has_disc' => $has_discount,
            'default_tier_id' => $default_tier ? $default_tier['id'] : 0,
            'stock_qty' => $avail,
            'ss' => $stock_status,
            'reviews' => (int)$row['review_count'],
            'rating' => (float)$row['avg_rating'],
            'featured' => (bool)$row['featured'],
            'is_new' => strtotime($row['created_at']) > strtotime('-14 days'),
            'in_cart' => $in_cart,
            'cart_qty' => $cart_qty,
            'cart_key' => $cart_key,
            'cart_tier_id' => $cart_tier_id,
            'in_wishlist' => isset($wishlist_map[$pid]),
            'has_variants' => $has_variants,
            'variant_groups' => $variant_groups,
        ];
    }

} catch (Exception $e) {
    error_log("Shop error: " . $e->getMessage());
    $error_message = "Unable to load products. Please try again later.";
}

$total_pages = $total_products > 0 ? (int)ceil($total_products / $per_page) : 0;

// Query string helper
function _qs($ex = []) {
    $p = $_GET;
    foreach ($ex as $k) unset($p[$k]);
    return http_build_query($p);
}

// Cart count
$cart_count = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += (int)($item['quantity'] ?? 0);
    }
}

// Wishlist count
$wishlist_count_val = Auth::isLoggedIn() ? count($wishlist_map) : 0;

function getCategoryImageUrl($image) {
    if (empty($image)) return '';
    if (filter_var($image, FILTER_VALIDATE_URL)) return $image;
    return BASE_URL . '/' . ltrim($image, '/');
}

$page_title = 'Shop — Bless Mall | Quality Products at Great Prices';
require_once __DIR__ . '/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="Shop quality products at Bless Mall Ghana. Find electronics, fashion, home goods and more with fast delivery.">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,900;1,400;1,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

<style>
/* ══════════════════════════════════════════════════════════════════
   BLESS MALL — SHOP v11 Ultra-Premium
   Hidden Filters • Cinema-Quality Experience • Slideshow + Variants
   ═══════════════════════════════════════════════════════════════════ */
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
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
    --shadow-md: 0 8px 30px rgba(0,0,0,0.06);
    --shadow-lg: 0 16px 48px rgba(0,0,0,0.08);
    --shadow-xl: 0 24px 64px rgba(0,0,0,0.12);
    --radius-sm: 8px;
    --radius: 16px;
    --radius-lg: 24px;
    --radius-full: 9999px;
    --transition: 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    --transition-slow: 0.5s cubic-bezier(0.4, 0, 0.2, 1);
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
    display: none;
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

@media (min-width: 768px) { .top-bar { display: block; } }
@media (max-width: 768px) {
    .top-bar-left span:not(:first-child) { display: none; }
    .top-bar-right .express-link span { display: none; }
}

/* ─── SHOP HERO ──────────────────────────────────────────────── */
.shop-hero {
    background: linear-gradient(165deg, #060E0A 0%, #0A1F15 30%, #0D2A1C 60%, #0A1F15 100%);
    padding: 3rem 0 2.5rem;
    position: relative;
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.shop-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: 
        radial-gradient(ellipse 70% 50% at 50% 100%, rgba(11,110,61,0.12) 0%, transparent 60%),
        radial-gradient(ellipse 30% 30% at 85% 10%, rgba(201,162,39,0.05) 0%, transparent 50%);
    pointer-events: none;
}
.shop-hero .container {
    position: relative;
    z-index: 1;
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1.5rem;
}
.shop-hero .hero-breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: rgba(255,255,255,0.3);
    font-size: 0.75rem;
    margin-bottom: 0.5rem;
}
.shop-hero .hero-breadcrumb a {
    color: rgba(255,255,255,0.5);
    text-decoration: none;
    transition: var(--transition);
}
.shop-hero .hero-breadcrumb a:hover { color: var(--gold); }
.shop-hero .hero-breadcrumb .current { color: rgba(255,255,255,0.7); }

.shop-hero h1 {
    font-family: var(--font-display);
    font-size: clamp(2rem, 4.5vw, 3rem);
    font-weight: 700;
    color: var(--white);
    line-height: 1.05;
    margin-bottom: 0.3rem;
}
.shop-hero h1 .highlight {
    color: var(--gold);
    position: relative;
}
.shop-hero h1 .highlight::after {
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
.shop-hero .hero-sub {
    color: rgba(255,255,255,0.4);
    font-size: 0.85rem;
    margin-bottom: 1rem;
}

.shop-hero .hero-search {
    max-width: 500px;
    display: flex;
    border-radius: var(--radius-full);
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
}
.shop-hero .hero-search input {
    flex: 1;
    padding: 0.8rem 1.2rem;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.08);
    border-right: none;
    font-family: var(--font-primary);
    font-size: 0.85rem;
    color: var(--white);
    outline: none;
    transition: var(--transition);
}
.shop-hero .hero-search input::placeholder { color: rgba(255,255,255,0.3); }
.shop-hero .hero-search input:focus {
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.15);
}
.shop-hero .hero-search button {
    padding: 0.8rem 1.5rem;
    background: var(--gold);
    color: var(--dark);
    border: 1px solid var(--gold);
    font-family: var(--font-primary);
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: var(--transition);
    white-space: nowrap;
}
.shop-hero .hero-search button:hover {
    background: var(--gold-dark);
    border-color: var(--gold-dark);
}

/* ─── CATEGORY PILLS ──────────────────────────────────────────── */
.category-pills {
    padding: 0 1.5rem;
    margin-bottom: 1.5rem;
    max-width: 1400px;
    margin-left: auto;
    margin-right: auto;
}
.pills-scroll {
    display: flex;
    gap: 0.6rem;
    overflow-x: auto;
    padding: 0.5rem 0.25rem 0.75rem;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.pills-scroll::-webkit-scrollbar { height: 3px; }
.pills-scroll::-webkit-scrollbar-track { background: var(--border); border-radius: 4px; }
.pills-scroll::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }
.pill-item {
    flex: 0 0 auto;
    text-decoration: none;
    padding: 0.5rem 1.2rem;
    background: var(--white);
    border-radius: var(--radius-full);
    border: 1.5px solid var(--border);
    transition: var(--transition);
    font-weight: 500;
    font-size: 0.78rem;
    color: var(--text-secondary);
    white-space: nowrap;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.pill-item:hover {
    border-color: var(--primary);
    color: var(--primary);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}
.pill-item.active {
    background: var(--primary);
    color: var(--white);
    border-color: var(--primary);
}
.pill-item .count {
    font-size: 0.55rem;
    opacity: 0.6;
    font-weight: 400;
}

/* ─── FILTER BUTTON ───────────────────────────────────────────── */
.filter-toggle-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    padding: 0 1.5rem;
    max-width: 1400px;
    margin-left: auto;
    margin-right: auto;
}
.filter-toggle-left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.filter-toggle-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 1.4rem;
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-full);
    font-family: var(--font-primary);
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition);
    box-shadow: var(--shadow-sm);
}
.filter-toggle-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--off-white);
}
.filter-toggle-btn i { font-size: 0.9rem; }
.filter-toggle-btn .badge-dot {
    width: 8px;
    height: 8px;
    background: var(--primary);
    border-radius: 50%;
    display: none;
}
.filter-toggle-btn.has-filters .badge-dot { display: inline-block; }

.filter-count {
    font-size: 0.7rem;
    color: var(--text-muted);
    font-weight: 500;
}
.filter-count strong { color: var(--text); }

.sort-select-wrap {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.sort-select-wrap label {
    font-size: 0.75rem;
    color: var(--text-muted);
    font-weight: 500;
}
.sort-select {
    padding: 0.4rem 2rem 0.4rem 0.8rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-full);
    font-family: var(--font-primary);
    font-size: 0.78rem;
    color: var(--text);
    background: var(--white);
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23666' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.7rem center;
    cursor: pointer;
    outline: none;
    transition: var(--transition);
}
.sort-select:focus { border-color: var(--primary); }

/* ─── FILTER DRAWER ───────────────────────────────────────────── */
.filter-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.4);
    z-index: 9998;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}
.filter-overlay.open {
    opacity: 1;
    pointer-events: auto;
}

.filter-drawer {
    position: fixed;
    top: 0;
    left: 0;
    width: 340px;
    max-width: 88vw;
    height: 100vh;
    height: 100dvh;
    background: var(--white);
    z-index: 9999;
    transform: translateX(-100%);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: var(--shadow-xl);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
.filter-drawer.open { transform: translateX(0); }
.filter-drawer::-webkit-scrollbar { width: 4px; }
.filter-drawer::-webkit-scrollbar-track { background: var(--border); }
.filter-drawer::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }

.filter-drawer-header {
    padding: 1.25rem 1.25rem 0.75rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    background: var(--white);
    position: sticky;
    top: 0;
    z-index: 2;
}
.filter-drawer-header h3 {
    font-family: var(--font-display);
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--dark);
}
.filter-drawer-header h3 i { color: var(--primary); }
.filter-drawer-close {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid var(--border);
    background: var(--white);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    color: var(--text-muted);
    transition: var(--transition);
}
.filter-drawer-close:hover {
    background: var(--red-lt);
    color: var(--red);
    border-color: var(--red);
}

.filter-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem 1.25rem 1.25rem;
}

.filter-group {
    margin-bottom: 1.25rem;
}
.filter-group:last-child { margin-bottom: 0; }
.filter-group-title {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    margin-bottom: 0.6rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.filter-group-title i { color: var(--primary); }

.filter-cat-link {
    display: block;
    padding: 0.35rem 0.5rem;
    border-radius: 6px;
    text-decoration: none;
    color: var(--text-secondary);
    font-size: 0.82rem;
    transition: var(--transition);
    margin-bottom: 1px;
}
.filter-cat-link:hover {
    background: var(--off-white);
    color: var(--primary);
}
.filter-cat-link.active {
    background: var(--primary);
    color: var(--white);
    font-weight: 600;
}
.filter-cat-link .cat-count {
    float: right;
    font-size: 0.65rem;
    opacity: 0.6;
}
.filter-cat-link.child { padding-left: 1.25rem; font-size: 0.78rem; }

.price-input-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.price-input-group input {
    flex: 1;
    padding: 0.45rem 0.6rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: var(--font-primary);
    font-size: 0.8rem;
    outline: none;
    transition: var(--transition);
    width: 80px;
}
.price-input-group input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.price-input-group span { color: var(--text-muted); font-weight: 500; }

.filter-check, .filter-radio {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0;
    cursor: pointer;
    font-size: 0.8rem;
    color: var(--text-secondary);
}
.filter-check input, .filter-radio input {
    accent-color: var(--primary);
    width: 16px;
    height: 16px;
}
.filter-check .check-count {
    margin-left: auto;
    font-size: 0.65rem;
    color: var(--text-muted);
}

.filter-drawer-footer {
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
    background: var(--white);
}
.btn-filter-apply {
    flex: 1;
    padding: 0.65rem;
    background: var(--primary);
    color: var(--white);
    border: none;
    border-radius: var(--radius-full);
    font-family: var(--font-primary);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}
.btn-filter-apply:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
}
.btn-filter-clear {
    flex: 1;
    padding: 0.65rem;
    background: var(--white);
    color: var(--text-secondary);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-full);
    font-family: var(--font-primary);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    text-align: center;
}
.btn-filter-clear:hover {
    border-color: var(--red);
    color: var(--red);
}

/* ─── ACTIVE FILTER CHIPS ────────────────────────────────────── */
.active-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    padding: 0 1.5rem;
    max-width: 1400px;
    margin: 0 auto 1rem;
}
.active-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: var(--green-lt);
    color: var(--primary);
    border: 1px solid rgba(11,110,61,0.15);
    border-radius: 20px;
    padding: 0.2rem 0.7rem;
    font-size: 0.7rem;
    font-weight: 500;
    transition: var(--transition);
}
.active-chip a {
    color: inherit;
    text-decoration: none;
    opacity: 0.6;
    font-size: 0.8rem;
    transition: var(--transition);
}
.active-chip a:hover { opacity: 1; color: var(--red); }

/* ─── PRODUCT GRID ────────────────────────────────────────────── */
.shop-main {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1.5rem 4rem;
}
.product-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
}

/* ─── PRODUCT CARD (with Slideshow & Variants) ──────────────── */
.product-card {
    background: var(--white);
    border-radius: var(--radius);
    overflow: hidden;
    border: 1px solid var(--border);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    position: relative;
    box-shadow: var(--shadow-sm);
}
.product-card:hover {
    transform: translateY(-8px);
    box-shadow: var(--shadow-xl);
    border-color: var(--gold);
}

.product-card .image-wrap {
    position: relative;
    padding-top: 100%;
    background: #f5f5f5;
    overflow: hidden;
    cursor: pointer;
    flex-shrink: 0;
}
.product-card .image-wrap .carousel {
    position: absolute;
    inset: 0;
    display: flex;
    transition: transform 0.5s ease;
    height: 100%;
}
.product-card .image-wrap .carousel img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    flex-shrink: 0;
    display: block;
}
.product-card .image-wrap .carousel .placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: #ddd;
    background: #f5f5f5;
    flex-shrink: 0;
}
.product-card .image-wrap .carousel-dots {
    position: absolute;
    bottom: 8px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 5px;
    z-index: 5;
    background: rgba(0,0,0,0.3);
    padding: 3px 6px;
    border-radius: 20px;
    backdrop-filter: blur(4px);
}
.product-card .image-wrap .carousel-dots .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.4);
    transition: background 0.3s, transform 0.3s;
    cursor: pointer;
}
.product-card .image-wrap .carousel-dots .dot.active {
    background: var(--gold);
    transform: scale(1.3);
}
.product-card .image-wrap .carousel-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
    height: 28px;
    background: rgba(255,255,255,0.8);
    border: none;
    border-radius: 50%;
    cursor: pointer;
    z-index: 5;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: var(--transition);
}
.product-card:hover .image-wrap .carousel-nav { display: flex; }
.product-card .image-wrap .carousel-nav:hover {
    background: var(--white);
}
.product-card .image-wrap .carousel-nav.prev { left: 6px; }
.product-card .image-wrap .carousel-nav.next { right: 6px; }

.product-card .badges {
    position: absolute;
    top: 10px;
    left: 10px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    z-index: 6;
    pointer-events: none;
}
.product-card .badge {
    padding: 0.15rem 0.6rem;
    border-radius: 20px;
    font-size: 0.55rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    width: fit-content;
}
.badge-sale { background: #DC2626; color: #fff; }
.badge-new { background: var(--dark); color: #fff; }
.badge-express { background: var(--gold); color: var(--dark); display: flex; align-items: center; gap: 3px; }
.badge-featured { background: var(--primary); color: #fff; }
.badge-low-stock { background: #D97706; color: #fff; }

.product-card .wishlist-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(255,255,255,0.92);
    border: 1px solid var(--border);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    color: var(--text-muted);
    transition: var(--transition);
    z-index: 7;
    backdrop-filter: blur(10px);
}
.product-card .wishlist-btn:hover {
    color: #DC2626;
    border-color: #DC2626;
    transform: scale(1.1);
}
.product-card .wishlist-btn.liked {
    color: #DC2626;
    background: #FEE2E2;
    border-color: #DC2626;
}

.product-card .info {
    padding: 0.8rem 1rem 1rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.product-card .vendor {
    font-size: 0.55rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    font-weight: 600;
    margin-bottom: 0.1rem;
}
.product-card .name {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text);
    line-height: 1.3;
    margin-bottom: 0.3rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    cursor: pointer;
    transition: color 0.2s;
}
.product-card .name:hover { color: var(--primary); }

.product-card .rating {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.65rem;
    margin-bottom: 0.3rem;
}
.product-card .stars {
    color: #F59E0B;
    display: flex;
    gap: 1px;
}
.product-card .stars .empty { color: #E5E0D8; }
.product-card .review-count { color: var(--text-muted); }

.product-card .price-row {
    display: flex;
    align-items: baseline;
    gap: 0.4rem;
    flex-wrap: wrap;
    margin-bottom: 0.4rem;
    margin-top: auto;
}
.product-card .current {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--primary);
}
.product-card .original {
    font-size: 0.7rem;
    color: #B0B0B0;
    text-decoration: line-through;
}
.product-card .discount-pct {
    font-size: 0.6rem;
    font-weight: 700;
    color: #DC2626;
    background: #FEE2E2;
    padding: 0.05rem 0.35rem;
    border-radius: 4px;
}

/* Variants section */
.product-card .variants {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    margin-bottom: 0.4rem;
}
.product-card .variant-group {
    display: flex;
    flex-wrap: wrap;
    gap: 0.2rem;
    align-items: center;
    margin-right: 0.2rem;
}
.product-card .variant-label {
    font-size: 0.6rem;
    font-weight: 600;
    color: var(--text-muted);
    margin-right: 0.1rem;
}
.product-card .variant-btn {
    padding: 0.1rem 0.5rem;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--white);
    font-size: 0.6rem;
    font-weight: 500;
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition);
    line-height: 1.6;
}
.product-card .variant-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.product-card .variant-btn.active {
    background: var(--primary);
    border-color: var(--primary);
    color: var(--white);
}
.product-card .variant-btn.out-of-stock {
    opacity: 0.4;
    cursor: not-allowed;
    text-decoration: line-through;
}

.product-card .add-btn {
    width: 100%;
    padding: 0.6rem;
    background: var(--primary);
    color: var(--white);
    border: none;
    border-radius: var(--radius-full);
    font-family: var(--font-primary);
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
}
.product-card .add-btn:hover:not(:disabled) {
    background: var(--gold);
    color: var(--dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 20px var(--gold-glow);
}
.product-card .add-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.product-card .add-btn.added {
    background: var(--gold);
    color: var(--dark);
}

/* In-cart controls */
.cart-qty-ctrl {
    display: flex;
    align-items: center;
    border: 1.5px solid var(--primary);
    border-radius: var(--radius-full);
    overflow: hidden;
}
.qty-btn {
    width: 32px;
    min-height: 38px;
    background: var(--off-white);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--primary);
    transition: var(--transition);
    font-family: var(--font-primary);
    flex-shrink: 0;
}
.qty-btn:hover:not(:disabled) { background: var(--primary); color: #fff; }
.qty-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.qty-input {
    flex: 1;
    min-width: 30px;
    text-align: center;
    border: none;
    border-left: 1px solid var(--primary);
    border-right: 1px solid var(--primary);
    font-family: var(--font-primary);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text);
    background: var(--white);
    padding: 0.4rem 0.1rem;
    outline: none;
    -moz-appearance: textfield;
}
.qty-input::-webkit-outer-spin-button,
.qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

.remove-btn-small {
    width: 34px;
    min-height: 38px;
    background: var(--red-lt);
    border: 1.5px solid var(--red);
    border-radius: var(--radius-full);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    color: var(--red);
    transition: var(--transition);
    flex-shrink: 0;
}
.remove-btn-small:hover { background: var(--red); color: #fff; }

/* ─── PAGINATION ──────────────────────────────────────────────── */
.pagination {
    display: flex;
    justify-content: center;
    gap: 0.4rem;
    margin-top: 2.5rem;
    flex-wrap: wrap;
}
.pagination a, .pagination span {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 0.4rem;
    border-radius: 50%;
    font-size: 0.8rem;
    font-weight: 500;
    text-decoration: none;
}
.pagination a {
    background: var(--white);
    color: var(--text-secondary);
    border: 1.5px solid var(--border);
    transition: var(--transition);
}
.pagination a:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--off-white);
}
.pagination .active {
    background: var(--primary);
    color: var(--white);
    border-color: var(--primary);
}
.pagination .disabled {
    opacity: 0.35;
    pointer-events: none;
    background: var(--white);
    border: 1.5px solid var(--border);
    color: var(--text-muted);
}

/* ─── EMPTY STATE ────────────────────────────────────────────── */
.empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 4rem 2rem;
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
}
.empty-state i { font-size: 3rem; color: #ddd; margin-bottom: 1rem; display: block; }
.empty-state h3 {
    font-family: var(--font-display);
    font-size: 1.3rem;
    font-weight: 400;
    margin-bottom: 0.5rem;
}
.empty-state p {
    color: var(--text-muted);
    font-size: 0.85rem;
    margin-bottom: 1.5rem;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}
.btn-browse {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.7rem 1.8rem;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    border-radius: var(--radius-full);
    font-weight: 600;
    font-size: 0.85rem;
    transition: var(--transition);
}
.btn-browse:hover { background: var(--primary-dark); transform: translateY(-2px); }

/* ─── TOAST ────────────────────────────────────────────────────── */
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
.toast.error { border-left-color: #DC2626; }
@keyframes toastIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* ─── RESPONSIVE ──────────────────────────────────────────────── */
@media (max-width: 1200px) {
    .product-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 992px) {
    .product-grid { grid-template-columns: repeat(2, 1fr); }
    .filter-drawer { width: 300px; }
}

@media (max-width: 768px) {
    .shop-hero { padding: 2rem 0 1.5rem; }
    .shop-hero .container { padding: 0 1rem; }
    .shop-hero .hero-search { max-width: 100%; }
    .shop-hero .hero-search input { font-size: 0.8rem; padding: 0.6rem 1rem; }
    .shop-hero .hero-search button { padding: 0.6rem 1rem; font-size: 0.8rem; }
    .shop-main { padding: 0 1rem 3rem; }
    .product-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .product-card .info { padding: 0.6rem 0.7rem 0.8rem; }
    .product-card .name { font-size: 0.78rem; }
    .product-card .current { font-size: 0.9rem; }
    .product-card .add-btn { font-size: 0.65rem; padding: 0.4rem; }
    .product-card .variant-btn { font-size: 0.5rem; padding: 0.05rem 0.3rem; }
    .product-card .image-wrap .carousel-nav { display: flex; width: 22px; height: 22px; font-size: 0.6rem; }
    .filter-toggle-wrap { padding: 0 1rem; flex-direction: column; align-items: stretch; }
    .filter-toggle-left { justify-content: space-between; }
    .sort-select-wrap { justify-content: flex-end; }
    .category-pills { padding: 0 1rem; }
    .active-chips { padding: 0 1rem; }
    .top-bar-left span:not(:first-child) { display: none; }
    .top-bar-right .express-link span { display: none; }
}

@media (max-width: 480px) {
    .product-grid { grid-template-columns: 1fr 1fr; gap: 0.6rem; }
    .product-card .image-wrap { padding-top: 80%; }
    .product-card .name { font-size: 0.7rem; }
    .product-card .current { font-size: 0.8rem; }
    .product-card .add-btn { font-size: 0.6rem; padding: 0.35rem; }
    .product-card .badge { font-size: 0.45rem; padding: 0.1rem 0.4rem; }
    .shop-hero h1 { font-size: 1.4rem; }
    .pill-item { font-size: 0.7rem; padding: 0.4rem 0.8rem; }
    .filter-drawer { width: 280px; }
}
</style>


<!-- ─── SHOP HERO ───────────────────────────────────────────────── -->
<section class="shop-hero">
    <div class="container">
        <div class="hero-breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i></a>
            <span>›</span>
            <?php if ($active_cat_id && isset($all_cats[$active_cat_id])): 
                $ac = $all_cats[$active_cat_id];
            ?>
                <a href="shop.php">Shop</a>
                <span>›</span>
                <?php if (!empty($ac['parent_id']) && isset($all_cats[(int)$ac['parent_id']])): ?>
                    <a href="?category=<?php echo urlencode($all_cats[(int)$ac['parent_id']]['slug']); ?>">
                        <?php echo htmlspecialchars($all_cats[(int)$ac['parent_id']]['name']); ?>
                    </a>
                    <span>›</span>
                <?php endif; ?>
                <span class="current"><?php echo htmlspecialchars($ac['name']); ?></span>
            <?php elseif (!empty($search_query)): ?>
                <a href="shop.php">Shop</a>
                <span>›</span>
                <span class="current">Search: "<?php echo htmlspecialchars($search_query); ?>"</span>
            <?php else: ?>
                <span class="current">All Products</span>
            <?php endif; ?>
        </div>
        <h1>
            <?php if ($active_cat_id && isset($all_cats[$active_cat_id])): ?>
                <?php echo htmlspecialchars($all_cats[$active_cat_id]['name']); ?>
            <?php elseif (!empty($search_query)): ?>
                Results for <span class="highlight">"<?php echo htmlspecialchars($search_query); ?>"</span>
            <?php else: ?>
                Our <span class="highlight">Collection</span>
            <?php endif; ?>
        </h1>
        <p class="hero-sub"><?php echo number_format($total_products); ?> products available</p>
        <form class="hero-search" method="GET" action="shop.php">
            <?php foreach ($_GET as $k => $v): if (in_array($k, ['search','page'])) continue; ?>
                <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
            <?php endforeach; ?>
            <input type="search" name="search" placeholder="Search products, brands…" 
                   value="<?php echo htmlspecialchars($search_query); ?>" aria-label="Search products">
            <button type="submit"><i class="fas fa-search"></i> Search</button>
        </form>
    </div>
</section>

<!-- ─── CATEGORY PILLS ──────────────────────────────────────────── -->
<?php if (!empty($featured_categories)): ?>
<div class="category-pills">
    <div class="pills-scroll">
        <a href="shop.php" class="pill-item <?php echo empty($category_slug) ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> All
        </a>
        <?php foreach ($featured_categories as $fc): ?>
            <a href="?category=<?php echo urlencode($fc['slug']); ?>" 
               class="pill-item <?php echo $category_slug === $fc['slug'] ? 'active' : ''; ?>">
                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($fc['name']); ?>
                <span class="count">(<?php echo (int)$fc['product_count']; ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ─── FILTER TOGGLE ───────────────────────────────────────────── -->
<?php
$has_filters = !empty($category_slug) || $min_price > 0 || $max_price > 0 || $in_stock_only || $promotion_only || $featured_only || !empty($brand_filter);
$filter_count = 0;
if (!empty($category_slug)) $filter_count++;
if ($min_price > 0 || $max_price > 0) $filter_count++;
if ($in_stock_only) $filter_count++;
if ($promotion_only) $filter_count++;
if ($featured_only) $filter_count++;
if (!empty($brand_filter)) $filter_count++;
?>
<div class="filter-toggle-wrap">
    <div class="filter-toggle-left">
        <button class="filter-toggle-btn <?php echo $has_filters ? 'has-filters' : ''; ?>" onclick="openFilterDrawer()" aria-label="Open filters">
            <i class="fas fa-sliders-h"></i>
            <span>Filters</span>
            <span class="badge-dot"></span>
        </button>
        <span class="filter-count"><strong><?php echo number_format($total_products); ?></strong> products found</span>
    </div>
    <div class="sort-select-wrap">
        <label for="sortSelect">Sort:</label>
        <form method="GET" id="sortForm" style="display:flex;align-items:center;gap:0.3rem;">
            <?php foreach ($_GET as $k => $v): if (in_array($k, ['sort','page'])) continue; ?>
                <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
            <?php endforeach; ?>
            <select name="sort" class="sort-select" id="sortSelect" onchange="this.form.submit()">
                <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest</option>
                <option value="price_asc" <?php echo $sort_by === 'price_asc' ? 'selected' : ''; ?>>Price: Low→High</option>
                <option value="price_desc" <?php echo $sort_by === 'price_desc' ? 'selected' : ''; ?>>Price: High→Low</option>
                <option value="name_asc" <?php echo $sort_by === 'name_asc' ? 'selected' : ''; ?>>Name: A→Z</option>
                <option value="name_desc" <?php echo $sort_by === 'name_desc' ? 'selected' : ''; ?>>Name: Z→A</option>
                <option value="popular" <?php echo $sort_by === 'popular' ? 'selected' : ''; ?>>Popular</option>
                <option value="rating" <?php echo $sort_by === 'rating' ? 'selected' : ''; ?>>Top Rated</option>
            </select>
        </form>
    </div>
</div>

<!-- ─── ACTIVE FILTER CHIPS ─────────────────────────────────────── -->
<?php if ($has_filters): ?>
<div class="active-chips">
    <?php if (!empty($category_slug) && $active_cat_id && isset($all_cats[$active_cat_id])): ?>
        <span class="active-chip"><?php echo htmlspecialchars($all_cats[$active_cat_id]['name']); ?> <a href="?<?php echo _qs(['category','page']); ?>">&times;</a></span>
    <?php endif; ?>
    <?php if ($min_price > 0 || $max_price > 0): ?>
        <span class="active-chip">GH₵<?php echo $min_price ?: '0'; ?> – GH₵<?php echo $max_price ?: '∞'; ?> <a href="?<?php echo _qs(['min_price','max_price','page']); ?>">&times;</a></span>
    <?php endif; ?>
    <?php if (!empty($brand_filter)): ?>
        <span class="active-chip"><?php echo htmlspecialchars($brand_filter); ?> <a href="?<?php echo _qs(['brand','page']); ?>">&times;</a></span>
    <?php endif; ?>
    <?php if ($in_stock_only): ?>
        <span class="active-chip">In Stock <a href="?<?php echo _qs(['in_stock','page']); ?>">&times;</a></span>
    <?php endif; ?>
    <?php if ($promotion_only): ?>
        <span class="active-chip">On Sale <a href="?<?php echo _qs(['on_sale','page']); ?>">&times;</a></span>
    <?php endif; ?>
    <?php if ($featured_only): ?>
        <span class="active-chip">Featured <a href="?<?php echo _qs(['featured','page']); ?>">&times;</a></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ─── FILTER DRAWER ───────────────────────────────────────────── -->
<div class="filter-overlay" id="filterOverlay" onclick="closeFilterDrawer()"></div>
<div class="filter-drawer" id="filterDrawer">
    <div class="filter-drawer-header">
        <h3><i class="fas fa-sliders-h"></i> Filter Products</h3>
        <button class="filter-drawer-close" onclick="closeFilterDrawer()" aria-label="Close filters">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="filter-drawer-body">
        <form method="GET" action="shop.php" id="filterForm">
            
            <!-- Categories -->
            <?php if (!empty($cat_tree[0])): ?>
            <div class="filter-group">
                <div class="filter-group-title"><i class="fas fa-th-large"></i> Categories</div>
                <a href="shop.php" class="filter-cat-link <?php echo empty($category_slug) ? 'active' : ''; ?>">
                    All Products
                    <span class="cat-count"><?php echo array_sum($raw_cnt); ?></span>
                </a>
                <?php foreach ($cat_tree[0] as $par):
                    $pid = (int)$par['id'];
                    $kids = $cat_tree[$pid] ?? [];
                    $p_act = $active_cat_id === $pid;
                    $p_cnt = $cat_counts[$pid] ?? 0;
                ?>
                <a href="?<?php echo _qs(['category','page']); ?>&category=<?php echo urlencode($par['slug']); ?>"
                   class="filter-cat-link <?php echo $p_act ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($par['name']); ?>
                    <span class="cat-count"><?php echo $p_cnt; ?></span>
                </a>
                <?php foreach ($kids as $kid):
                    $kid_id = (int)$kid['id'];
                    $k_act = $active_cat_id === $kid_id;
                    $k_cnt = $cat_counts[$kid_id] ?? 0;
                ?>
                <a href="?<?php echo _qs(['category','page']); ?>&category=<?php echo urlencode($kid['slug']); ?>"
                   class="filter-cat-link child <?php echo $k_act ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($kid['name']); ?>
                    <span class="cat-count"><?php echo $k_cnt; ?></span>
                </a>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Price Range -->
            <div class="filter-group">
                <div class="filter-group-title"><i class="fas fa-tag"></i> Price (GH₵)</div>
                <div class="price-input-group">
                    <input type="number" name="min_price" placeholder="Min" 
                           value="<?php echo $min_price > 0 ? $min_price : ''; ?>" min="0" step="1">
                    <span>–</span>
                    <input type="number" name="max_price" placeholder="Max" 
                           value="<?php echo $max_price > 0 ? $max_price : ''; ?>" min="0" step="1">
                </div>
            </div>
            
            <!-- Quick Filters -->
            <div class="filter-group">
                <div class="filter-group-title"><i class="fas fa-bolt"></i> Quick Filters</div>
                <label class="filter-check">
                    <input type="checkbox" name="in_stock" value="1" <?php echo $in_stock_only ? 'checked' : ''; ?>>
                    In Stock Only
                </label>
                <label class="filter-check">
                    <input type="checkbox" name="on_sale" value="1" <?php echo $promotion_only ? 'checked' : ''; ?>>
                    On Sale
                </label>
                <label class="filter-check">
                    <input type="checkbox" name="featured" value="1" <?php echo $featured_only ? 'checked' : ''; ?>>
                    Featured Products
                </label>
            </div>
            
            <!-- Brands -->
            <?php if (!empty($brands)): ?>
            <div class="filter-group">
                <div class="filter-group-title"><i class="fas fa-trademark"></i> Brands</div>
                <label class="filter-radio">
                    <input type="radio" name="brand" value="" <?php echo empty($brand_filter) ? 'checked' : ''; ?>>
                    All Brands
                </label>
                <?php foreach ($brands as $b): ?>
                <label class="filter-radio">
                    <input type="radio" name="brand" value="<?php echo htmlspecialchars($b['name']); ?>"
                           <?php echo $brand_filter === $b['name'] ? 'checked' : ''; ?>>
                    <?php echo htmlspecialchars($b['name']); ?>
                    <span class="check-count">(<?php echo $b['n']; ?>)</span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Hidden fields -->
            <?php if (!empty($search_query)): ?>
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
            <?php endif; ?>
            <?php if ($sort_by !== 'newest'): ?>
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
            <?php endif; ?>
        </form>
    </div>
    <div class="filter-drawer-footer">
        <button class="btn-filter-apply" onclick="document.getElementById('filterForm').submit()">
            <i class="fas fa-check"></i> Apply Filters
        </button>
        <a href="shop.php" class="btn-filter-clear">
            <i class="fas fa-times"></i> Clear All
        </a>
    </div>
</div>

<!-- ─── PRODUCT GRID ─────────────────────────────────────────────── -->
<div class="shop-main">
    <?php if (!empty($error_message)): ?>
    <div style="text-align:center;padding:1.5rem;color:var(--red);background:var(--red-lt);border-radius:var(--radius);margin-bottom:1rem;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($products)): ?>
    <div class="product-grid" id="productGrid">
        <?php foreach ($products as $p): 
            $peff = _bm_fmt($p['eff']);
            $porig = _bm_fmt($p['orig']);
            $show_was = $p['has_disc'] && $p['orig'] > $p['eff'];
            $in_cart = $p['in_cart'];
            $cart_qty = $p['cart_qty'];
            $cart_key = $p['cart_key'];
            $default_tier_id = $p['default_tier_id'];
            $image_count = count($p['images']);
            $has_variants = $p['has_variants'];
            $variant_groups = $p['variant_groups'];
        ?>
        <div class="product-card" id="card-<?php echo $p['id']; ?>" data-pid="<?php echo $p['id']; ?>" data-default-tier="<?php echo $default_tier_id; ?>">
            
            <!-- Image Carousel -->
            <div class="image-wrap" onclick="window.location='product-detail.php?slug=<?php echo urlencode($p['slug']); ?>'">
                <div class="carousel" id="carousel-<?php echo $p['id']; ?>">
                    <?php foreach ($p['images'] as $idx => $img_url): ?>
                        <img src="<?php echo htmlspecialchars($img_url); ?>" 
                             alt="<?php echo htmlspecialchars($p['name']); ?>" 
                             loading="lazy"
                             onerror="this.style.display='none';this.parentElement.innerHTML='<div class=placeholder><i class=\'fas fa-box-open\'></i></div>';">
                    <?php endforeach; ?>
                    <?php if (empty($p['images'])): ?>
                        <div class="placeholder"><i class="fas fa-box-open"></i></div>
                    <?php endif; ?>
                </div>
                
                <?php if ($image_count > 1): ?>
                <div class="carousel-dots" id="dots-<?php echo $p['id']; ?>">
                    <?php for ($i = 0; $i < $image_count; $i++): ?>
                        <span class="dot <?php echo $i === 0 ? 'active' : ''; ?>" data-index="<?php echo $i; ?>"></span>
                    <?php endfor; ?>
                </div>
                <button class="carousel-nav prev" onclick="event.stopPropagation(); slideCarousel(<?php echo $p['id']; ?>, -1);"><i class="fas fa-chevron-left"></i></button>
                <button class="carousel-nav next" onclick="event.stopPropagation(); slideCarousel(<?php echo $p['id']; ?>, 1);"><i class="fas fa-chevron-right"></i></button>
                <?php endif; ?>
                
                <div class="badges">
                    <?php if ($p['has_disc'] && $p['dpct'] > 0): ?>
                        <span class="badge badge-sale">-<?php echo $p['dpct']; ?>%</span>
                    <?php endif; ?>
                    <?php if ($p['is_new']): ?>
                        <span class="badge badge-new">New</span>
                    <?php endif; ?>
                    <?php if ($p['featured']): ?>
                        <span class="badge badge-featured">Featured</span>
                    <?php endif; ?>
                    <?php if ($p['express']): ?>
                        <span class="badge badge-express"><i class="fas fa-bolt"></i> Express</span>
                    <?php endif; ?>
                    <?php if ($p['ss'] === 'low_stock' && $p['stock_qty'] > 0): ?>
                        <span class="badge badge-low-stock">Low Stock</span>
                    <?php endif; ?>
                </div>
                
                <button class="wishlist-btn <?php echo $p['in_wishlist'] ? 'liked' : ''; ?>"
                        onclick="event.stopPropagation(); toggleWishlist(<?php echo $p['id']; ?>, this)"
                        title="<?php echo $p['in_wishlist'] ? 'Remove from Wishlist' : 'Add to Wishlist'; ?>">
                    <i class="<?php echo $p['in_wishlist'] ? 'fas' : 'far'; ?> fa-heart"></i>
                </button>
            </div>
            
            <!-- Info -->
            <div class="info">
                <div class="vendor"><?php echo htmlspecialchars($p['vendor']); ?></div>
                <div class="name" onclick="window.location='product-detail.php?slug=<?php echo urlencode($p['slug']); ?>'">
                    <?php echo htmlspecialchars($p['name']); ?>
                </div>
                
                <?php if ($p['reviews'] > 0):
                    $rt = round($p['rating'] * 2) / 2;
                    $rf = (int)floor($rt);
                    $rh = ($rt - $rf) >= .5;
                    $re = 5 - $rf - ($rh ? 1 : 0);
                ?>
                <div class="rating">
                    <span class="stars">
                        <?php for ($s = 0; $s < $rf; $s++) echo '<i class="fas fa-star"></i>'; ?>
                        <?php if ($rh) echo '<i class="fas fa-star-half-alt"></i>'; ?>
                        <?php for ($s = 0; $s < $re; $s++) echo '<i class="far fa-star empty"></i>'; ?>
                    </span>
                    <span class="review-count">(<?php echo $p['reviews']; ?>)</span>
                </div>
                <?php endif; ?>
                
                <div class="price-row">
                    <span class="current" id="price-<?php echo $p['id']; ?>"><?php echo $peff; ?></span>
                    <?php if ($show_was): ?>
                        <span class="original" id="orig-<?php echo $p['id']; ?>"><?php echo $porig; ?></span>
                        <span class="discount-pct" id="disc-<?php echo $p['id']; ?>">-<?php echo $p['dpct']; ?>%</span>
                    <?php endif; ?>
                </div>
                
                <!-- Variants -->
                <?php if ($has_variants): ?>
                <div class="variants" id="variants-<?php echo $p['id']; ?>">
                    <?php foreach ($variant_groups as $group_name => $group_variants): ?>
                        <span class="variant-group">
                            <span class="variant-label"><?php echo htmlspecialchars($group_name); ?>:</span>
                            <?php foreach ($group_variants as $var): 
                                $is_out = $var['available_stock'] <= 0;
                                $var_eff = $var['unit_price'] > 0 ? 
                                    ($var['discount_price'] > 0 && $var['discount_price'] < $var['unit_price'] ? $var['discount_price'] : 
                                    ($var['discount_percentage'] > 0 ? round($var['unit_price'] * (1 - $var['discount_percentage']/100), 2) : $var['unit_price'])) : 0;
                                $var_price = _bm_fmt($var_eff);
                            ?>
                            <button class="variant-btn <?php echo $is_out ? 'out-of-stock' : ''; ?>" 
                                    data-pid="<?php echo $p['id']; ?>" 
                                    data-variant-id="<?php echo $var['id']; ?>"
                                    data-price="<?php echo $var_eff; ?>"
                                    data-tier-id="<?php echo $var['pricing_tier_id']; ?>"
                                    onclick="event.stopPropagation(); selectVariant(<?php echo $p['id']; ?>, this)"
                                    title="<?php echo $is_out ? 'Out of stock' : ''; ?>">
                                <?php echo htmlspecialchars($var['value']); ?>
                            </button>
                            <?php endforeach; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- CTA -->
                <div class="cta-wrap" id="cta-<?php echo $p['id']; ?>">
                    <?php if ($in_cart): ?>
                        <div style="display:flex;gap:0.4rem;width:100%;">
                            <div class="cart-qty-ctrl" style="flex:1;">
                                <button class="qty-btn" onclick="updateCartQty('<?php echo $cart_key; ?>', <?php echo $p['id']; ?>, <?php echo $cart_qty - 1; ?>)"
                                        <?php echo $cart_qty <= 1 ? 'disabled' : ''; ?>>−</button>
                                <input type="text" class="qty-input" value="<?php echo $cart_qty; ?>" readonly>
                                <button class="qty-btn" onclick="updateCartQty('<?php echo $cart_key; ?>', <?php echo $p['id']; ?>, <?php echo $cart_qty + 1; ?>)">+</button>
                            </div>
                            <button class="remove-btn-small" onclick="removeFromCart('<?php echo $cart_key; ?>', <?php echo $p['id']; ?>)" title="Remove">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    <?php else: ?>
                        <button class="add-btn" id="addbtn-<?php echo $p['id']; ?>" 
                                onclick="addToCart(<?php echo $p['id']; ?>, <?php echo $default_tier_id; ?>, this)"
                                <?php echo $p['ss'] === 'out_of_stock' ? 'disabled' : ''; ?>>
                            <i class="fas fa-shopping-bag"></i>
                            <?php echo $p['ss'] === 'out_of_stock' ? 'Out of Stock' : 'Add to Cart'; ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav class="pagination" aria-label="Product pages">
        <?php if ($page > 1): ?>
            <a href="?<?php echo _qs(['page']); ?>&page=<?php echo $page - 1; ?>" aria-label="Previous"><i class="fas fa-chevron-left"></i></a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i></span>
        <?php endif; ?>

        <?php
        $sp = max(1, $page - 2);
        $ep = min($total_pages, $page + 2);
        if ($sp > 1) {
            echo '<a href="?' . _qs(['page']) . '&page=1">1</a>';
            if ($sp > 2) echo '<span class="disabled">…</span>';
        }
        for ($i = $sp; $i <= $ep; $i++) {
            echo '<a href="?' . _qs(['page']) . '&page=' . $i . '" class="' . ($i === $page ? 'active' : '') . '">' . $i . '</a>';
        }
        if ($ep < $total_pages) {
            if ($ep < $total_pages - 1) echo '<span class="disabled">…</span>';
            echo '<a href="?' . _qs(['page']) . '&page=' . $total_pages . '">' . $total_pages . '</a>';
        }
        ?>

        <?php if ($page < $total_pages): ?>
            <a href="?<?php echo _qs(['page']); ?>&page=<?php echo $page + 1; ?>" aria-label="Next"><i class="fas fa-chevron-right"></i></a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php else: ?>
    <!-- Empty State -->
    <div class="empty-state">
        <i class="fas fa-box-open"></i>
        <h3>No Products Found</h3>
        <p>
            <?php if (!empty($search_query)): ?>
                Nothing matched <strong>"<?php echo htmlspecialchars($search_query); ?>"</strong>
            <?php else: ?>
                No products match your current filters.
            <?php endif; ?>
        </p>
        <a href="shop.php" class="btn-browse"><i class="fas fa-shopping-bag"></i> Browse All</a>
    </div>
    <?php endif; ?>
</div>

<!-- ─── TOAST CONTAINER ────────────────────────────────────────── -->
<div class="toast-container" id="toastContainer"></div>

<!-- ─── SCRIPTS ────────────────────────────────────────────────── -->
<script>
// ═══════════════════════════════════════════════════════════════════
// BLESS MALL SHOP SCRIPTS v11 — Ultra-Premium with Slideshow & Variants
// ═══════════════════════════════════════════════════════════════════

// ═══ FILTER DRAWER ═══
function openFilterDrawer() {
    document.getElementById('filterDrawer').classList.add('open');
    document.getElementById('filterOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeFilterDrawer() {
    document.getElementById('filterDrawer').classList.remove('open');
    document.getElementById('filterOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeFilterDrawer();
});

// ═══ CAROUSEL / SLIDESHOW ═══
var carouselIntervals = {};

function initCarousel(productId) {
    var carousel = document.getElementById('carousel-' + productId);
    var dots = document.getElementById('dots-' + productId);
    if (!carousel || !dots) return;
    var images = carousel.querySelectorAll('img');
    if (images.length <= 1) return;
    var total = images.length;
    var current = 0;
    
    // Dot click
    dots.querySelectorAll('.dot').forEach(function(dot, idx) {
        dot.addEventListener('click', function(e) {
            e.stopPropagation();
            goToSlide(productId, idx);
        });
    });
    
    // Touch support
    var startX = 0;
    carousel.parentElement.addEventListener('touchstart', function(e) {
        startX = e.touches[0].clientX;
    }, { passive: true });
    carousel.parentElement.addEventListener('touchend', function(e) {
        var endX = e.changedTouches[0].clientX;
        var diff = startX - endX;
        if (Math.abs(diff) > 40) {
            if (diff > 0) goToSlide(productId, current + 1);
            else goToSlide(productId, current - 1);
        }
    }, { passive: true });
    
    function goToSlide(pid, index) {
        var car = document.getElementById('carousel-' + pid);
        var dotsContainer = document.getElementById('dots-' + pid);
        if (!car) return;
        var imgs = car.querySelectorAll('img');
        var total = imgs.length;
        if (total === 0) return;
        if (index < 0) index = total - 1;
        if (index >= total) index = 0;
        current = index;
        car.style.transform = 'translateX(-' + (index * 100) + '%)';
        if (dotsContainer) {
            dotsContainer.querySelectorAll('.dot').forEach(function(d, i) {
                d.classList.toggle('active', i === index);
            });
        }
        resetAutoPlay(pid);
    }
    
    function resetAutoPlay(pid) {
        if (carouselIntervals[pid]) {
            clearInterval(carouselIntervals[pid]);
            delete carouselIntervals[pid];
        }
        // Auto-play every 5 seconds
        carouselIntervals[pid] = setInterval(function() {
            var c = document.getElementById('carousel-' + pid);
            if (!c) { clearInterval(carouselIntervals[pid]); return; }
            var imgs = c.querySelectorAll('img');
            if (imgs.length <= 1) { clearInterval(carouselIntervals[pid]); return; }
            var currentIdx = 0;
            var dotsContainer = document.getElementById('dots-' + pid);
            if (dotsContainer) {
                dotsContainer.querySelectorAll('.dot').forEach(function(d, i) {
                    if (d.classList.contains('active')) currentIdx = i;
                });
            }
            goToSlide(pid, currentIdx + 1);
        }, 5000);
    }
    
    // Store function globally
    window.slideCarousel = function(pid, dir) {
        var dotsContainer = document.getElementById('dots-' + pid);
        if (!dotsContainer) return;
        var dots = dotsContainer.querySelectorAll('.dot');
        var currentIdx = 0;
        dots.forEach(function(d, i) {
            if (d.classList.contains('active')) currentIdx = i;
        });
        goToSlide(pid, currentIdx + dir);
    };
    
    // Store goToSlide globally
    window.goToSlide = goToSlide;
    
    // Init
    goToSlide(productId, 0);
}

// Initialize all carousels after page load
document.addEventListener('DOMContentLoaded', function() {
    var cards = document.querySelectorAll('.product-card');
    cards.forEach(function(card) {
        var pid = parseInt(card.dataset.pid);
        if (pid) initCarousel(pid);
    });
});

// ═══ VARIANT SELECTION ═══
function selectVariant(productId, btn) {
    var group = btn.closest('.variant-group');
    var allBtns = group.querySelectorAll('.variant-btn');
    allBtns.forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    
    // Update price
    var price = parseFloat(btn.dataset.price) || 0;
    var priceElement = document.getElementById('price-' + productId);
    if (priceElement) {
        priceElement.textContent = 'GH₵ ' + price.toFixed(2);
    }
    
    // Update add to cart button with new tier_id
    var addBtn = document.getElementById('addbtn-' + productId);
    if (addBtn) {
        var tierId = parseInt(btn.dataset.tierId) || 0;
        if (tierId > 0) {
            addBtn.dataset.tierId = tierId;
            // Also update the onclick to use new tier
            addBtn.onclick = function() {
                addToCart(productId, tierId, this);
            };
        }
        // Disable if out of stock
        if (btn.classList.contains('out-of-stock')) {
            addBtn.disabled = true;
            addBtn.innerHTML = '<i class="fas fa-times"></i> Out of Stock';
        } else {
            addBtn.disabled = false;
            addBtn.innerHTML = '<i class="fas fa-shopping-bag"></i> Add to Cart';
        }
    }
}

// ═══ TOAST ═══
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

// ═══ UPDATE CART BADGE ═══
function updateCartBadge(count) {
    var badge = document.getElementById('cartCount');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }
}

// ═══ ADD TO CART (with variant tier support) ═══
function addToCart(productId, tierId, btn) {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;

    var data = new URLSearchParams();
    data.append('action', 'add_to_cart');
    data.append('product_id', productId);
    data.append('pricing_tier_id', tierId || 0);
    data.append('quantity', 1);

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            updateCartBadge(d.cart_count);
            btn.innerHTML = '<i class="fas fa-check"></i> Added!';
            btn.classList.add('added');
            showToast(d.message || 'Added to cart!');
            
            var cta = document.getElementById('cta-' + productId);
            if (cta) {
                var cartKey = d.cart_key || ('p_' + productId + '_t_' + (d.pricing_tier_id || tierId || 0));
                var qty = d.quantity || 1;
                cta.innerHTML = 
                    '<div style="display:flex;gap:0.4rem;width:100%;">' +
                        '<div class="cart-qty-ctrl" style="flex:1;">' +
                            '<button class="qty-btn" onclick="updateCartQty(\'' + cartKey + '\', ' + productId + ', ' + (qty - 1) + ')" ' + (qty <= 1 ? 'disabled' : '') + '>−</button>' +
                            '<input type="text" class="qty-input" value="' + qty + '" readonly>' +
                            '<button class="qty-btn" onclick="updateCartQty(\'' + cartKey + '\', ' + productId + ', ' + (qty + 1) + ')">+</button>' +
                        '</div>' +
                        '<button class="remove-btn-small" onclick="removeFromCart(\'' + cartKey + '\', ' + productId + ')" title="Remove">' +
                            '<i class="fas fa-trash-alt"></i>' +
                        '</button>' +
                    '</div>';
            }
            
            setTimeout(function() {
                btn.innerHTML = orig;
                btn.classList.remove('added');
                btn.disabled = false;
            }, 2000);
        } else {
            showToast(d.message || 'Failed to add', 'error');
            btn.innerHTML = orig;
            btn.disabled = false;
        }
    })
    .catch(function() {
        showToast('Connection error', 'error');
        btn.innerHTML = orig;
        btn.disabled = false;
    });
}

// ═══ UPDATE CART QUANTITY ═══
function updateCartQty(cartKey, productId, newQty) {
    newQty = Math.max(0, Math.min(999, parseInt(newQty) || 0));
    if (newQty === 0) { removeFromCart(cartKey, productId); return; }
    
    var cta = document.getElementById('cta-' + productId);
    if (!cta) return;
    
    var qtyInput = cta.querySelector('.qty-input');
    if (qtyInput) qtyInput.value = newQty;
    
    var data = new URLSearchParams();
    data.append('action', 'update_cart_quantity');
    data.append('cart_key', cartKey);
    data.append('quantity', newQty);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            updateCartBadge(d.cart_count);
        }
    })
    .catch(function(err) { console.error('Update cart error:', err); });
}

// ═══ REMOVE FROM CART ═══
function removeFromCart(cartKey, productId) {
    var cta = document.getElementById('cta-' + productId);
    if (!cta) return;
    
    var data = new URLSearchParams();
    data.append('action', 'remove_from_cart');
    data.append('cart_key', cartKey);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            updateCartBadge(d.cart_count);
            showToast('Removed from cart', 'success');
            
            var card = document.getElementById('card-' + productId);
            var defaultTier = card ? parseInt(card.dataset.defaultTier) : 0;
            
            // Check if product has variants, we need to know which tier is selected
            var activeVariant = cta.parentElement.querySelector('.variant-btn.active');
            var tierId = defaultTier;
            if (activeVariant) {
                tierId = parseInt(activeVariant.dataset.tierId) || defaultTier;
            }
            
            cta.innerHTML = 
                '<button class="add-btn" id="addbtn-' + productId + '" onclick="addToCart(' + productId + ', ' + tierId + ', this)">' +
                    '<i class="fas fa-shopping-bag"></i> Add to Cart' +
                '</button>';
        }
    })
    .catch(function(err) { console.error('Remove from cart error:', err); });
}

// ═══ TOGGLE WISHLIST ═══
function toggleWishlist(productId, btn) {
    <?php if (Auth::isLoggedIn()): ?>
    var icon = btn.querySelector('i');
    var prevClass = icon.className;
    icon.className = 'fas fa-spinner fa-spin';
    btn.disabled = true;
    
    var data = new URLSearchParams();
    data.append('action', 'toggle_wishlist');
    data.append('product_id', productId);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        btn.disabled = false;
        if (d.success) {
            if (d.action === 'added') {
                btn.classList.add('liked');
                icon.className = 'fas fa-heart';
                btn.title = 'Remove from Wishlist';
                showToast('Added to wishlist! ❤️');
            } else {
                btn.classList.remove('liked');
                icon.className = 'far fa-heart';
                btn.title = 'Add to Wishlist';
                showToast('Removed from wishlist');
            }
        } else if (d.redirect) {
            window.location.href = d.redirect;
        } else {
            icon.className = prevClass;
            showToast(d.message || 'Error', 'error');
        }
    })
    .catch(function() {
        icon.className = prevClass;
        btn.disabled = false;
        showToast('Connection error', 'error');
    });
    <?php else: ?>
    window.location.href = 'login.php?redirect=shop.php';
    <?php endif; ?>
}

// ═══ INIT ═══
document.addEventListener('DOMContentLoaded', function() {
    updateCartBadge(<?php echo $cart_count; ?>);
});

console.log('%c🛍️ Bless Mall Shop %cv11 - Slideshow + Variants',
    'font-size:1.2em;font-weight:bold;color:#0B6E3D;',
    'font-size:1em;color:#C9A227;');
console.log('%c✨ Image carousels • Variant selection • Ultra-premium', 'font-style:italic;color:#4A665C;');
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>