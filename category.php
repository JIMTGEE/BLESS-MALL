<?php
/**
 * category.php - Bless Mall Category Products Page
 * Updated with complete pricing tiers and image fetching like index.php
 * Premium E-Commerce Experience for Ghana
 */

// Start session and load configuration
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

// ==================== AJAX HANDLER ====================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid request'];
    
    $conn = getConnection();
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    
    // ========== ADD TO CART ==========
    if ($action === 'add_to_cart') {
        $product_id = (int)($_REQUEST['product_id'] ?? 0);
        $pricing_tier_id = (int)($_REQUEST['pricing_tier_id'] ?? 0);
        $quantity = max(1, (int)($_REQUEST['quantity'] ?? 1));
        
        if ($pricing_tier_id > 0) {
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.is_active,
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
                SELECT p.id, p.name, p.is_active,
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
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
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
            $img_path = '';
            $imgStmt = $conn->prepare("
                SELECT image_path FROM product_images 
                WHERE product_id = ? 
                ORDER BY is_primary DESC, sort_order ASC 
                LIMIT 1
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
    
    // ========== UPDATE CART QUANTITY ==========
    if ($action === 'update_cart_quantity') {
        $cart_key = $_POST['cart_key'] ?? '';
        $new_quantity = max(0, (int)($_POST['quantity'] ?? 1));
        
        if (empty($cart_key) || !isset($_SESSION['cart'][$cart_key])) {
            echo json_encode(['success' => false, 'message' => 'Item not found']);
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
    
    // ========== REMOVE FROM CART ==========
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
    
    // ========== TOGGLE WISHLIST ==========
    if ($action === 'toggle_wishlist') {
        if (!Auth::isLoggedIn()) {
            echo json_encode([
                'success' => false, 
                'message' => 'Please login to manage wishlist',
                'redirect' => 'login.php?redirect=' . urlencode($_SERVER['HTTP_REFERER'] ?? 'category.php')
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

// ==================== HELPER FUNCTIONS ====================
function formatPrice($price) {
    if ($price === null || $price < 0) return 'GH₵ 0.00';
    return 'GH₵ ' . number_format((float)$price, 2);
}

function getCategoryIcon($category) {
    if (!empty($category['icon'])) {
        return $category['icon'];
    }
    
    $name = isset($category['name']) ? strtolower($category['name']) : '';
    $slug = isset($category['slug']) ? strtolower($category['slug']) : '';
    
    $icon_map = [
        'lifestyle' => 'fa-tshirt',
        'home-living' => 'fa-home',
        'electronics' => 'fa-laptop',
        'food-grocery' => 'fa-apple-alt',
        'health-wellness' => 'fa-heartbeat',
        'sports-outdoors' => 'fa-futbol',
        'automotive' => 'fa-car',
        'agriculture' => 'fa-seedling',
        'fashion' => 'fa-tshirt',
        'beauty' => 'fa-sparkles',
        'watches-jewelry' => 'fa-gem',
        'books-stationery' => 'fa-book',
        'home-kitchen' => 'fa-home',
        'baby-products' => 'fa-baby-carriage',
        'furniture' => 'fa-couch',
        'kitchen-appliances' => 'fa-utensils',
        'home-decor' => 'fa-palette',
        'cookware-dining' => 'fa-plate-wheat',
        'mobile-phones' => 'fa-mobile-alt',
        'laptops-computers' => 'fa-desktop',
        'audio-headphones' => 'fa-headphones',
        'electronics-accessories' => 'fa-plug',
        'food-beverages' => 'fa-apple-alt',
        'fresh-produce' => 'fa-carrot',
        'groceries' => 'fa-shopping-basket',
        'beverages' => 'fa-wine-bottle',
        'snacks-confectionery' => 'fa-cookie-bite',
        'health' => 'fa-heartbeat',
        'skincare' => 'fa-hand-sparkles',
        'hair-care' => 'fa-pump-soap',
        'makeup' => 'fa-palette',
        'fragrances' => 'fa-spray-can-sparkles',
        'fitness-equipment' => 'fa-dumbbell',
        'sportswear' => 'fa-tshirt',
        'camping-outdoors' => 'fa-campground',
        'car-accessories' => 'fa-car-side',
        'oils-fluids' => 'fa-oil-can',
        'seeds-seedlings' => 'fa-seedling',
        'farming-tools' => 'fa-tractor',
        'feeds-supplements' => 'fa-cow',
        'pesticides-fertilizers' => 'fa-biohazard',
        'mens-clothing' => 'fa-male',
        'womens-clothing' => 'fa-female',
        'shoes' => 'fa-shoe-prints',
        'bags-luggage' => 'fa-bag-shopping',
        'diapers-wipes' => 'fa-baby-carriage',
        'baby-food-formula' => 'fa-baby-bottle',
        'baby-toys' => 'fa-puzzle-piece'
    ];
    
    if (isset($icon_map[$slug])) return $icon_map[$slug];
    foreach ($icon_map as $key => $icon) {
        if (strpos($name, $key) !== false || strpos($slug, $key) !== false) {
            return $icon;
        }
    }
    return 'fa-tag';
}

/**
 * Get product image URL - SAME as index.php
 */
function getProductImageUrl($imagePath) {
    if (empty($imagePath)) {
        return '';
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

// ==================== PARAMETER HANDLING ====================
$category_slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$subcategory_slug = isset($_GET['sub']) ? trim($_GET['sub']) : '';
$detail_slug = isset($_GET['detail']) ? trim($_GET['detail']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$sort_by = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
$brand_filter = isset($_GET['brand']) ? trim($_GET['brand']) : '';
$in_stock_only = isset($_GET['in_stock']) ? true : false;
$promotion_only = isset($_GET['on_sale']) ? true : false;
$rating_filter = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;

$allowed_sorts = ['newest', 'oldest', 'price_asc', 'price_desc', 'name_asc', 'name_desc', 'popular', 'rating'];
if (!in_array($sort_by, $allowed_sorts)) $sort_by = 'newest';

$per_page = defined('PRODUCTS_PER_PAGE') ? (int)PRODUCTS_PER_PAGE : 24;
$offset = ($page - 1) * $per_page;

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

// ==================== BUILD WISHLIST MAP ====================
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

// ==================== FETCH CATEGORY DATA ====================
$conn = getConnection();

if (empty($category_slug)) {
    header('Location: ' . BASE_URL . 'shop.php');
    exit;
}

$category_query = "SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1";
$stmt = $conn->prepare($category_query);
$stmt->bind_param('s', $category_slug);
$stmt->execute();
$category_result = $stmt->get_result();

if ($category_result->num_rows === 0) {
    header('Location: ' . BASE_URL . 'shop.php');
    exit;
}

$category = $category_result->fetch_assoc();

// Get all categories for sidebar
$all_categories_query = "SELECT * FROM categories WHERE is_active = 1 ORDER BY COALESCE(parent_id, id) ASC, name ASC";
$all_categories_result = $conn->query($all_categories_query);
$all_categories = [];
if ($all_categories_result) {
    while ($row = $all_categories_result->fetch_assoc()) {
        $row['display_icon'] = getCategoryIcon($row);
        $all_categories[] = $row;
    }
}

$umbrella_categories = array_filter($all_categories, function($cat) {
    return $cat['parent_id'] === null;
});

// Determine category hierarchy
$current_category = $category;
$parent_category = null;
$umbrella_category = null;
$active_category_name = isset($category['name']) ? $category['name'] : '';
$category_ids = [];

if ($category['parent_id'] === null) {
    $umbrella_category = $category;
    $category_ids = [$category['id']];
    
    $sub_query = "SELECT id FROM categories WHERE parent_id = ? AND is_active = 1";
    $stmt = $conn->prepare($sub_query);
    $stmt->bind_param('i', $category['id']);
    $stmt->execute();
    $sub_result = $stmt->get_result();
    while ($row = $sub_result->fetch_assoc()) {
        $category_ids[] = $row['id'];
        $detail_query = "SELECT id FROM categories WHERE parent_id = ? AND is_active = 1";
        $detail_stmt = $conn->prepare($detail_query);
        $detail_stmt->bind_param('i', $row['id']);
        $detail_stmt->execute();
        $detail_result = $detail_stmt->get_result();
        while ($detail_row = $detail_result->fetch_assoc()) {
            $category_ids[] = $detail_row['id'];
        }
        $detail_stmt->close();
    }
    $stmt->close();
} else {
    $parent_query = "SELECT * FROM categories WHERE id = ? AND is_active = 1";
    $stmt = $conn->prepare($parent_query);
    $stmt->bind_param('i', $category['parent_id']);
    $stmt->execute();
    $parent_result = $stmt->get_result();
    if ($parent_result->num_rows > 0) {
        $parent_category = $parent_result->fetch_assoc();
        if ($parent_category['parent_id'] !== null) {
            $umbrella_query = "SELECT * FROM categories WHERE id = ? AND is_active = 1";
            $umbrella_stmt = $conn->prepare($umbrella_query);
            $umbrella_stmt->bind_param('i', $parent_category['parent_id']);
            $umbrella_stmt->execute();
            $umbrella_result = $umbrella_stmt->get_result();
            if ($umbrella_result->num_rows > 0) {
                $umbrella_category = $umbrella_result->fetch_assoc();
            }
            $umbrella_stmt->close();
        } else {
            $umbrella_category = $parent_category;
        }
    }
    $stmt->close();
    $category_ids = [$category['id']];
}

if (!empty($subcategory_slug)) {
    $sub_query = "SELECT id, name FROM categories WHERE slug = ? AND parent_id = ? AND is_active = 1";
    $stmt = $conn->prepare($sub_query);
    $stmt->bind_param('si', $subcategory_slug, $category['id']);
    $stmt->execute();
    $sub_result = $stmt->get_result();
    if ($sub_result->num_rows > 0) {
        $sub_cat = $sub_result->fetch_assoc();
        $category_ids = [$sub_cat['id']];
        $active_category_name = isset($sub_cat['name']) ? $sub_cat['name'] : $active_category_name;
        $detail_query = "SELECT id FROM categories WHERE parent_id = ? AND is_active = 1";
        $detail_stmt = $conn->prepare($detail_query);
        $detail_stmt->bind_param('i', $sub_cat['id']);
        $detail_stmt->execute();
        $detail_result = $detail_stmt->get_result();
        while ($detail_row = $detail_result->fetch_assoc()) {
            $category_ids[] = $detail_row['id'];
        }
        $detail_stmt->close();
    }
    $stmt->close();
}

if (!empty($detail_slug)) {
    $detail_query = "SELECT id, name FROM categories WHERE slug = ? AND is_active = 1";
    $stmt = $conn->prepare($detail_query);
    $stmt->bind_param('s', $detail_slug);
    $stmt->execute();
    $detail_result = $stmt->get_result();
    if ($detail_result->num_rows > 0) {
        $detail_cat = $detail_result->fetch_assoc();
        $category_ids = [$detail_cat['id']];
        $active_category_name = isset($detail_cat['name']) ? $detail_cat['name'] : $active_category_name;
    }
    $stmt->close();
}

// Get subcategories for display
$display_subcategories = [];
if ($category['parent_id'] === null && empty($subcategory_slug) && empty($detail_slug)) {
    $sub_query = "SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY name ASC";
    $stmt = $conn->prepare($sub_query);
    $stmt->bind_param('i', $category['id']);
    $stmt->execute();
    $sub_result = $stmt->get_result();
    while ($row = $sub_result->fetch_assoc()) {
        $row['display_icon'] = getCategoryIcon($row);
        $display_subcategories[] = $row;
    }
    $stmt->close();
} elseif (!empty($subcategory_slug) && empty($detail_slug)) {
    $detail_query = "SELECT * FROM categories WHERE parent_id = (SELECT id FROM categories WHERE slug = ?) AND is_active = 1 ORDER BY name ASC";
    $stmt = $conn->prepare($detail_query);
    $stmt->bind_param('s', $subcategory_slug);
    $stmt->execute();
    $detail_result = $stmt->get_result();
    while ($row = $detail_result->fetch_assoc()) {
        $row['display_icon'] = getCategoryIcon($row);
        $display_subcategories[] = $row;
    }
    $stmt->close();
}

// ==================== FETCH PRODUCTS WITH FULL PRICING TIERS ====================
$products = [];
$total_products = 0;
$price_range = ['min' => 0, 'max' => 1000];
$brands = [];

if (!empty($category_ids)) {
    $category_placeholders = implode(',', array_fill(0, count($category_ids), '?'));
    
    // Fetch brands
    $brand_sql = "
        SELECT DISTINCT p.brand, COUNT(DISTINCT p.id) as product_count
        FROM products p
        WHERE p.category_id IN ($category_placeholders) 
        AND p.is_active = 1 
        AND p.brand IS NOT NULL 
        AND p.brand != ''
        GROUP BY p.brand
        ORDER BY p.brand ASC
    ";
    $stmt = $conn->prepare($brand_sql);
    $stmt->bind_param(str_repeat('i', count($category_ids)), ...$category_ids);
    $stmt->execute();
    $brand_result = $stmt->get_result();
    while ($row = $brand_result->fetch_assoc()) {
        $brands[] = $row;
    }
    
    // Fetch price range
    $price_sql = "
        SELECT 
            MIN(COALESCE(ppt.min_discount_price, ppt.min_retail_price)) as min_price,
            MAX(ppt.max_retail_price) as max_price
        FROM products p
        INNER JOIN (
            SELECT 
                product_id,
                MIN(CASE WHEN pricing_type = 'retail' THEN unit_price END) as min_retail_price,
                MAX(CASE WHEN pricing_type = 'retail' THEN unit_price END) as max_retail_price,
                MIN(CASE WHEN discount_price IS NOT NULL AND discount_price > 0 THEN discount_price END) as min_discount_price
            FROM product_pricing_tiers
            WHERE is_active = 1
            GROUP BY product_id
        ) ppt ON p.id = ppt.product_id
        WHERE p.category_id IN ($category_placeholders) AND p.is_active = 1
    ";
    $stmt = $conn->prepare($price_sql);
    $stmt->bind_param(str_repeat('i', count($category_ids)), ...$category_ids);
    $stmt->execute();
    $price_result = $stmt->get_result();
    $price_row = $price_result->fetch_assoc();
    $price_range = [
        'min' => isset($price_row['min_price']) ? floor($price_row['min_price']) : 0,
        'max' => isset($price_row['max_price']) ? ceil($price_row['max_price']) : 1000
    ];
    
    // Build product query - SAME as index.php
    $base_from = "
        FROM products p
        INNER JOIN categories c ON p.category_id = c.id AND c.is_active = 1
        INNER JOIN vendors v ON p.vendor_id = v.id AND v.status = 'active'
        LEFT JOIN (
            SELECT pi.product_id, pi.image_path 
            FROM product_images pi
            WHERE pi.is_primary = 1
            ORDER BY pi.sort_order ASC
            LIMIT 1
        ) pi ON p.id = pi.product_id
        LEFT JOIN (
            SELECT 
                product_id,
                MIN(CASE WHEN pricing_type = 'retail' THEN unit_price END) as min_retail_price,
                MAX(CASE WHEN pricing_type = 'retail' THEN unit_price END) as max_retail_price,
                MIN(CASE WHEN discount_price IS NOT NULL AND discount_price > 0 THEN discount_price END) as min_discount_price
            FROM product_pricing_tiers
            WHERE is_active = 1
            GROUP BY product_id
        ) ppt ON p.id = ppt.product_id
        LEFT JOIN (
            SELECT 
                ppt2.product_id,
                SUM(pi_inv.stock_quantity) as total_stock,
                SUM(pi_inv.reserved_quantity) as total_reserved
            FROM product_inventory pi_inv
            INNER JOIN product_pricing_tiers ppt2 ON pi_inv.pricing_tier_id = ppt2.id
            WHERE ppt2.is_active = 1
            GROUP BY ppt2.product_id
        ) pi_inv ON p.id = pi_inv.product_id
        LEFT JOIN (
            SELECT 
                pp.product_id,
                prom.discount_type,
                prom.discount_value,
                prom.name as promotion_name
            FROM product_promotions pp
            INNER JOIN promotions prom ON pp.promotion_id = prom.id
            WHERE prom.is_active = 1 
            AND prom.start_date <= CURDATE() 
            AND prom.end_date >= CURDATE()
        ) active_promos ON p.id = active_promos.product_id
        LEFT JOIN (
            SELECT 
                product_id,
                COUNT(*) as review_count,
                AVG(rating) as avg_rating
            FROM reviews
            GROUP BY product_id
        ) rev_stats ON p.id = rev_stats.product_id
        WHERE p.is_active = 1 
        AND p.category_id IN ($category_placeholders)
    ";
    
    $where_conditions = [];
    $params = $category_ids;
    $types = str_repeat('i', count($category_ids));
    
    if ($min_price > 0) {
        $where_conditions[] = "(ppt.min_retail_price >= ? OR ppt.min_discount_price >= ?)";
        $params[] = $min_price;
        $params[] = $min_price;
        $types .= "dd";
    }
    
    if ($max_price > 0) {
        $where_conditions[] = "(ppt.min_retail_price <= ? OR ppt.min_discount_price <= ?)";
        $params[] = $max_price;
        $params[] = $max_price;
        $types .= "dd";
    }
    
    if (!empty($brand_filter)) {
        $where_conditions[] = "p.brand = ?";
        $params[] = $brand_filter;
        $types .= "s";
    }
    
    if ($in_stock_only) {
        $where_conditions[] = "COALESCE(pi_inv.total_stock, 0) - COALESCE(pi_inv.total_reserved, 0) > 0";
    }
    
    if ($promotion_only) {
        $where_conditions[] = "active_promos.product_id IS NOT NULL";
    }
    
    $where_clause = !empty($where_conditions) ? " AND " . implode(" AND ", $where_conditions) : "";
    
    $order_clause = " ORDER BY ";
    switch ($sort_by) {
        case 'newest': $order_clause .= "p.created_at DESC"; break;
        case 'oldest': $order_clause .= "p.created_at ASC"; break;
        case 'price_asc': $order_clause .= "COALESCE(ppt.min_discount_price, ppt.min_retail_price) ASC"; break;
        case 'price_desc': $order_clause .= "COALESCE(ppt.min_discount_price, ppt.min_retail_price) DESC"; break;
        case 'name_asc': $order_clause .= "p.name ASC"; break;
        case 'name_desc': $order_clause .= "p.name DESC"; break;
        case 'popular': $order_clause .= "rev_stats.review_count DESC, rev_stats.avg_rating DESC"; break;
        case 'rating': $order_clause .= "rev_stats.avg_rating DESC, rev_stats.review_count DESC"; break;
        default: $order_clause .= "p.created_at DESC";
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(DISTINCT p.id) as total " . $base_from . $where_clause;
    $stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total_products = $stmt->get_result()->fetch_assoc()['total'];
    
    // Get products
    $data_sql = "
        SELECT DISTINCT
            p.id, p.name, p.slug, p.short_description, p.sku, p.brand,
            p.created_at, p.featured,
            c.name as category_name, c.slug as category_slug,
            v.business_name as vendor_name, v.is_express as vendor_express,
            pi.image_path as primary_image,
            ppt.min_retail_price,
            ppt.max_retail_price,
            ppt.min_discount_price,
            COALESCE(pi_inv.total_stock, 0) as stock_quantity,
            COALESCE(pi_inv.total_reserved, 0) as reserved_quantity,
            active_promos.discount_type,
            active_promos.discount_value,
            COALESCE(rev_stats.review_count, 0) as review_count,
            COALESCE(rev_stats.avg_rating, 0) as avg_rating
    " . $base_from . $where_clause . $order_clause . " LIMIT ? OFFSET ?";
    
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($data_sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pids = [];
    $raw_products = [];
    while ($row = $result->fetch_assoc()) {
        $pids[] = (int)$row['id'];
        $raw_products[] = $row;
    }
    
    // Fetch ALL pricing tiers for loaded products (like index.php)
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
    
    // Build products array with full pricing tiers
    foreach ($raw_products as $row) {
        $pid = (int)$row['id'];
        $tiers = $tier_map[$pid] ?? [];
        $retail_tiers = array_values(array_filter($tiers, fn($t) => $t['pricing_type'] === 'retail'));
        $wholesale_tiers = array_values(array_filter($tiers, fn($t) => $t['pricing_type'] === 'wholesale'));
        
        $default_tier = !empty($retail_tiers) ? $retail_tiers[0] : (!empty($tiers) ? $tiers[0] : null);
        
        $original_price = isset($row['min_retail_price']) ? (float)$row['min_retail_price'] : 0;
        $effective_price = $original_price;
        $discount_percentage = 0;
        
        if (isset($row['min_discount_price']) && $row['min_discount_price'] && $row['min_discount_price'] < $original_price) {
            $effective_price = (float)$row['min_discount_price'];
            $discount_percentage = round((($original_price - $effective_price) / $original_price) * 100);
        }
        
        if (isset($row['discount_type']) && $row['discount_type']) {
            if ($row['discount_type'] === 'percentage') {
                $promo_price = $effective_price * (1 - ((float)$row['discount_value'] / 100));
                if ($promo_price < $effective_price) {
                    $effective_price = $promo_price;
                    $discount_percentage = (float)$row['discount_value'];
                }
            } elseif ($row['discount_type'] === 'fixed') {
                $promo_price = $effective_price - (float)$row['discount_value'];
                if ($promo_price > 0 && $promo_price < $effective_price) {
                    $effective_price = $promo_price;
                    $discount_percentage = round((($original_price - $effective_price) / $original_price) * 100);
                }
            }
        }
        
        $available = (isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0) - (isset($row['reserved_quantity']) ? (int)$row['reserved_quantity'] : 0);
        $stock_status = 'out_of_stock';
        if ($available > 10) {
            $stock_status = 'in_stock';
        } elseif ($available > 0) {
            $stock_status = 'low_stock';
        }
        
        // Get image URL - same as index.php
        $img = $row['primary_image'] ?? '';
        $img_url = getProductImageUrl($img);
        if (empty($img_url)) {
            $img_url = BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
        }
        
        $in_cart = isset($cart_map[$pid]);
        $cart_qty = $in_cart ? $cart_map[$pid]['total_qty'] : 0;
        $cart_key = $in_cart ? $cart_map[$pid]['cart_key'] : '';
        $cart_tier_id = $in_cart ? $cart_map[$pid]['pricing_tier_id'] : 0;
        
        // Calculate best price for display
        $best_price = null;
        $has_discount = false;
        $discount_percent = 0;
        $has_wholesale = !empty($wholesale_tiers);
        
        foreach ($retail_tiers as $tier) {
            $unit_price = (float)$tier['unit_price'];
            $disc_price = !empty($tier['discount_price']) ? (float)$tier['discount_price'] : null;
            $disc_pct = !empty($tier['discount_percentage']) ? (float)$tier['discount_percentage'] : null;
            
            $final_price = $unit_price;
            $has_disc = false;
            $disc_pct_display = 0;
            
            if ($disc_price && $disc_price > 0 && $disc_price < $unit_price) {
                $final_price = $disc_price;
                $has_disc = true;
                $disc_pct_display = round(($unit_price - $final_price) / $unit_price * 100);
            } elseif ($disc_pct && $disc_pct > 0) {
                $final_price = round($unit_price * (1 - $disc_pct / 100), 2);
                $has_disc = true;
                $disc_pct_display = round($disc_pct);
            }
            
            if ($best_price === null || $final_price < $best_price) {
                $best_price = $final_price;
                $has_discount = $has_disc;
                $discount_percent = $disc_pct_display;
            }
        }
        
        $products[] = [
            'id' => $pid,
            'name' => isset($row['name']) ? $row['name'] : '',
            'slug' => isset($row['slug']) ? $row['slug'] : '',
            'brand' => isset($row['brand']) ? $row['brand'] : '',
            'category_name' => isset($row['category_name']) ? $row['category_name'] : '',
            'category_slug' => isset($row['category_slug']) ? $row['category_slug'] : '',
            'vendor_name' => isset($row['vendor_name']) ? $row['vendor_name'] : '',
            'vendor_express' => isset($row['vendor_express']) ? $row['vendor_express'] : 0,
            'primary_image' => $img_url,
            'original_price' => $original_price,
            'effective_price' => $effective_price,
            'best_price' => $best_price,
            'discount_percentage' => $discount_percentage,
            'has_discount' => $has_discount,
            'discount_percent' => $discount_percent,
            'has_wholesale' => $has_wholesale,
            'stock_quantity' => $available,
            'stock_status' => $stock_status,
            'review_count' => isset($row['review_count']) ? (int)$row['review_count'] : 0,
            'avg_rating' => isset($row['avg_rating']) ? (float)$row['avg_rating'] : 0,
            'created_at' => isset($row['created_at']) ? $row['created_at'] : '',
            'featured' => isset($row['featured']) ? (bool)$row['featured'] : false,
            'pricing_tiers' => $tiers,
            'retail_tiers' => $retail_tiers,
            'wholesale_tiers' => $wholesale_tiers,
            'default_tier_id' => $default_tier ? $default_tier['id'] : 0,
            'in_cart' => $in_cart,
            'cart_qty' => $cart_qty,
            'cart_key' => $cart_key,
            'cart_tier_id' => $cart_tier_id,
            'in_wishlist' => isset($wishlist_map[$pid]),
            'is_new' => strtotime($row['created_at'] ?? '') > strtotime('-14 days'),
        ];
    }
}

$total_pages = $total_products > 0 ? ceil($total_products / $per_page) : 1;

function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return http_build_query($params);
}

// Cart count for header
$cart_count = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += (int)($item['quantity'] ?? 0);
    }
}

$wishlist_count = Auth::isLoggedIn() ? count($wishlist_map) : 0;
$page_title = htmlspecialchars($active_category_name) . ' - ' . SITE_NAME;

require_once __DIR__ . '/header.php';
?>

<style>
.category-page { background: #f9fafb; min-height: 100vh; }
.category-hero { background: linear-gradient(135deg, rgba(11, 110, 61, 0.1), rgba(212, 175, 55, 0.1)); padding: 3rem 0; }
.category-hero-content { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; }
.category-hero h1 { font-size: 2.5rem; font-weight: 700; color: #1a1a1a; margin-bottom: 0.5rem; font-family: 'Playfair Display', serif; }
.category-hero p { color: #6b7280; font-size: 1.1rem; }
.category-breadcrumb { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; flex-wrap: wrap; }
.category-breadcrumb a { color: #0B6E3D; text-decoration: none; }
.category-breadcrumb a:hover { text-decoration: underline; }
.category-breadcrumb span { color: #6b7280; }
.subcategory-pills { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 1.5rem auto 0; max-width: 1400px; padding: 0 1.5rem; }
.subcategory-pill { padding: 0.5rem 1.25rem; border-radius: 25px; text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; border: 2px solid #d1d5db; color: #4b5563; background: white; display: inline-flex; align-items: center; gap: 0.5rem; }
.subcategory-pill i { font-size: 0.85rem; color: #0B6E3D; }
.subcategory-pill:hover, .subcategory-pill.active { background: #0B6E3D; color: white; border-color: #0B6E3D; }
.subcategory-pill:hover i, .subcategory-pill.active i { color: white; }

.category-layout { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; display: grid; grid-template-columns: 280px 1fr; gap: 2rem; align-items: start; }
.category-sidebar { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06); position: sticky; top: 90px; max-height: calc(100vh - 120px); overflow-y: auto; }
.filter-section { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #e5e7eb; }
.filter-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.filter-title { font-weight: 600; font-size: 1rem; margin-bottom: 1rem; color: #1a1a1a; display: flex; align-items: center; gap: 0.5rem; }
.filter-title i { color: #0B6E3D; }
.category-list { list-style: none; padding: 0; margin: 0; }
.category-list li { margin-bottom: 0.5rem; }
.category-list a { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.75rem; color: #4b5563; text-decoration: none; border-radius: 6px; transition: all 0.2s; }
.category-list a i { width: 24px; color: #0B6E3D; font-size: 1rem; }
.category-list a .cat-name { flex: 1; margin-left: 0.5rem; }
.category-list a:hover, .category-list a.active { background: rgba(11, 110, 61, 0.08); color: #0B6E3D; }

.price-inputs { display: flex; gap: 0.5rem; align-items: center; }
.price-inputs input { width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; }
.btn-filter { width: 100%; padding: 0.75rem; background: #0B6E3D; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; }
.btn-filter:hover { background: #095A31; }
.btn-reset { width: 100%; padding: 0.75rem; background: transparent; color: #6b7280; border: 1px solid #d1d5db; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.2s; margin-top: 0.75rem; display: inline-block; text-align: center; text-decoration: none; }
.btn-reset:hover { background: #f9fafb; }
.checkbox-label { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; cursor: pointer; color: #4b5563; font-size: 0.9rem; }
.checkbox-label input { accent-color: #0B6E3D; }

.category-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; background: white; padding: 1rem 1.5rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.results-count { color: #6b7280; font-size: 0.9rem; }
.sort-select { padding: 0.5rem 2rem 0.5rem 1rem; border: 1px solid #d1d5db; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 0.9rem; cursor: pointer; background: white; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.75rem center; }

.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(265px, 1fr)); gap: 1.5rem; }
.product-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); transition: all 0.3s; position: relative; border: 1px solid #e5e7eb; }
.product-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.12); border-color: #D4AF37; }
.product-image-wrapper { position: relative; padding-top: 100%; background: #f9fafb; overflow: hidden; }
.product-image-wrapper img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
.product-card:hover .product-image-wrapper img { transform: scale(1.05); }
.product-badge { position: absolute; top: 12px; left: 12px; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem; font-weight: 700; z-index: 2; letter-spacing: 0.3px; }
.badge-sale { background: #EF4444; color: white; }
.badge-new { background: #10B981; color: white; }
.badge-express { background: #D4AF37; color: #1a1a1a; display: flex; align-items: center; gap: 4px; }
.badge-low-stock { background: #F59E0B; color: white; }
.badge-featured { background: #0B6E3D; color: white; }
.badge-wholesale { background: #7C3AED; color: white; display: flex; align-items: center; gap: 4px; }
.wishlist-btn { position: absolute; top: 12px; right: 12px; width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.95); border: 1px solid #e5e7eb; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.2s; z-index: 2; font-size: 0.9rem; color: #6b7280; }
.wishlist-btn:hover { background: #FEE2E5; border-color: #EF4444; color: #EF4444; transform: scale(1.1); }
.wishlist-btn.active { background: #FEE2E5; border-color: #EF4444; color: #EF4444; }

.product-info { padding: 1rem 1.25rem 1.25rem; display: flex; flex-direction: column; flex: 1; }
.product-category { font-size: 0.7rem; color: #0B6E3D; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 0.25rem; }
.product-name { font-weight: 600; font-size: 0.95rem; margin-bottom: 0.5rem; color: #1a1a1a; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.4; min-height: 2.6rem; }
.product-name a { color: inherit; text-decoration: none; }
.product-name a:hover { color: #0B6E3D; }
.product-rating { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; font-size: 0.8rem; }
.stars { color: #D4AF37; display: flex; gap: 1px; }
.rating-count { color: #6b7280; font-size: 0.75rem; }
.product-price { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; flex-wrap: wrap; }
.current-price { font-size: 1.15rem; font-weight: 700; color: #1a1a1a; }
.original-price { font-size: 0.85rem; color: #9ca3af; text-decoration: line-through; }
.discount-badge-card { font-size: 0.7rem; font-weight: 600; color: #EF4444; background: #FEE2E2; padding: 0.15rem 0.5rem; border-radius: 4px; }
.wholesale-tag { font-size: 0.65rem; font-weight: 600; color: #7C3AED; background: #EDE9FE; padding: 0.15rem 0.5rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.25rem; }
.stock-status { font-size: 0.75rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.3rem; }
.stock-status.in-stock { color: #10B981; }
.stock-status.low-stock { color: #F59E0B; }
.stock-status.out-of-stock { color: #EF4444; }

.btn-add-cart { width: 100%; padding: 0.7rem; background: #0B6E3D; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: 0.85rem; }
.btn-add-cart:hover:not(:disabled) { background: #D4AF37; color: #1a1a1a; transform: translateY(-1px); }
.btn-add-cart:disabled { background: #d1d5db; cursor: not-allowed; }

/* Cart quantity controls */
.cart-qty-ctrl { display: flex; align-items: center; border: 1.5px solid #0B6E3D; border-radius: 8px; overflow: hidden; flex: 1; }
.qty-btn { width: 32px; min-height: 38px; background: #f0faf4; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; font-weight: 700; color: #0B6E3D; transition: all 0.2s; font-family: 'Inter', sans-serif; }
.qty-btn:hover:not(:disabled) { background: #0B6E3D; color: white; }
.qty-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.qty-input { flex: 1; min-width: 30px; text-align: center; border: none; border-left: 1px solid #0B6E3D; border-right: 1px solid #0B6E3D; font-family: 'Inter', sans-serif; font-size: 0.8rem; font-weight: 600; color: #1a1a1a; background: white; padding: 0.5rem 0.2rem; outline: none; -moz-appearance: textfield; }
.qty-input::-webkit-outer-spin-button, .qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.remove-btn { width: 34px; min-height: 38px; background: #FEE2E2; border: 1.5px solid #EF4444; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; color: #EF4444; transition: all 0.2s; flex-shrink: 0; }
.remove-btn:hover { background: #EF4444; color: white; }
.card-actions { display: flex; gap: 0.4rem; align-items: center; margin-top: 0.25rem; }

.pagination { display: flex; justify-content: center; gap: 0.5rem; margin-top: 2rem; flex-wrap: wrap; }
.pagination a, .pagination span { display: flex; align-items: center; justify-content: center; min-width: 38px; height: 38px; border-radius: 8px; text-decoration: none; font-weight: 500; transition: all 0.2s; font-size: 0.85rem; }
.pagination a { background: white; color: #4b5563; border: 1px solid #e5e7eb; }
.pagination a:hover { background: rgba(11, 110, 61, 0.08); color: #0B6E3D; border-color: #0B6E3D; }
.pagination .active { background: #0B6E3D; color: white; border-color: #0B6E3D; }
.pagination .disabled { opacity: 0.4; pointer-events: none; background: white; border: 1px solid #e5e7eb; color: #6b7280; }

.empty-state { text-align: center; padding: 4rem 2rem; grid-column: 1 / -1; background: white; border-radius: 12px; }
.empty-state i { font-size: 4rem; color: #d1d5db; margin-bottom: 1rem; display: block; }
.empty-state h3 { font-size: 1.5rem; color: #1a1a1a; margin-bottom: 0.5rem; font-family: 'Playfair Display', serif; }
.active-filters { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
.filter-tag { display: flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0.75rem; background: rgba(11, 110, 61, 0.08); color: #0B6E3D; border-radius: 20px; font-size: 0.8rem; }
.filter-tag a { color: inherit; text-decoration: none; margin-left: 0.25rem; }

.toast-container { position: fixed; bottom: 1.5rem; right: 1rem; z-index: 99999; display: flex; flex-direction: column-reverse; gap: 0.5rem; pointer-events: none; max-width: calc(100vw - 2rem); }
.toast { background: white; border-radius: 12px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); padding: 0.85rem 1.1rem; display: flex; align-items: center; gap: 0.6rem; font-size: 0.84rem; font-weight: 500; pointer-events: auto; animation: tIn 0.35s cubic-bezier(.16,1,.3,1); border-left: 4px solid #0B6E3D; width: fit-content; }
.toast.error { border-left-color: #EF4444; }
.toast .tcl { margin-left: auto; background: none; border: none; cursor: pointer; color: #6b7280; font-size: 1.1rem; padding: 0 0 0 .5rem; line-height: 1; }
@keyframes tIn { from { transform: translateX(120%); opacity: 0; } to { opacity: 1; transform: none; } }

@media (max-width: 992px) { .category-layout { grid-template-columns: 1fr; } .category-sidebar { position: static; max-height: none; } .product-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); } .category-hero h1 { font-size: 2rem; } }
@media (max-width: 640px) { .product-grid { grid-template-columns: repeat(2, 1fr); gap: 0.85rem; } .category-hero h1 { font-size: 1.5rem; } .subcategory-pill { padding: 0.4rem 1rem; font-size: 0.8rem; } .category-toolbar { flex-direction: column; align-items: stretch; text-align: center; } .product-info { padding: 0.75rem; } .product-name { font-size: 0.85rem; min-height: 2.2rem; } .current-price { font-size: 1rem; } .btn-add-cart { font-size: 0.78rem; padding: 0.6rem; } }
@media (max-width: 480px) { .product-grid { grid-template-columns: 1fr; } }
</style>

<div class="category-page">
    
    <!-- Category Hero -->
    <div class="category-hero">
        <div class="category-hero-content">
            <div class="category-breadcrumb">
                <a href="<?php echo BASE_URL; ?>">Home</a>
                <span>/</span>
                <a href="<?php echo BASE_URL; ?>shop.php">Shop</a>
                <?php if ($umbrella_category && isset($umbrella_category['id']) && (!isset($category['id']) || $umbrella_category['id'] != $category['id'])): ?>
                    <span>/</span>
                    <a href="category.php?slug=<?php echo urlencode($umbrella_category['slug']); ?>">
                        <?php echo htmlspecialchars($umbrella_category['name']); ?>
                    </a>
                <?php endif; ?>
                <?php if ($parent_category && isset($parent_category['id']) && (!isset($category['id']) || $parent_category['id'] != $category['id'])): ?>
                    <span>/</span>
                    <a href="category.php?slug=<?php echo urlencode($parent_category['slug']); ?>">
                        <?php echo htmlspecialchars($parent_category['name']); ?>
                    </a>
                <?php endif; ?>
                <?php if (!empty($subcategory_slug) || !empty($detail_slug) || (isset($category['parent_id']) && $category['parent_id'] !== null)): ?>
                    <span>/</span>
                    <span><?php echo htmlspecialchars($active_category_name); ?></span>
                <?php endif; ?>
            </div>
            <h1><?php echo htmlspecialchars($active_category_name); ?></h1>
            <?php if (!empty($category['description'])): ?>
                <p><?php echo htmlspecialchars($category['description']); ?></p>
            <?php endif; ?>
            <p class="results-count"><?php echo number_format($total_products); ?> products</p>
        </div>
    </div>
    
    <!-- Subcategory Pills -->
    <?php if (!empty($display_subcategories)): ?>
    <div class="subcategory-pills">
        <?php foreach ($display_subcategories as $sub): ?>
            <?php
            $target_slug = $category_slug;
            $target_param = '';
            $target_value = '';
            if (isset($category['parent_id']) && $category['parent_id'] === null) {
                $target_param = 'sub';
                $target_value = isset($sub['slug']) ? $sub['slug'] : '';
            } else {
                $target_param = 'detail';
                $target_value = isset($sub['slug']) ? $sub['slug'] : '';
            }
            ?>
            <a href="category.php?slug=<?php echo urlencode($target_slug); ?>&<?php echo $target_param; ?>=<?php echo urlencode($target_value); ?>" 
               class="subcategory-pill">
                <i class="fas <?php echo isset($sub['display_icon']) ? htmlspecialchars($sub['display_icon']) : 'fa-tag'; ?>"></i>
                <?php echo htmlspecialchars(isset($sub['name']) ? $sub['name'] : ''); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div class="category-layout">
        
        <!-- Sidebar Filters -->
        <aside class="category-sidebar">
            <form method="GET" action="category.php" id="filterForm">
                <input type="hidden" name="slug" value="<?php echo htmlspecialchars($category_slug); ?>">
                <?php if (!empty($subcategory_slug)): ?>
                    <input type="hidden" name="sub" value="<?php echo htmlspecialchars($subcategory_slug); ?>">
                <?php endif; ?>
                <?php if (!empty($detail_slug)): ?>
                    <input type="hidden" name="detail" value="<?php echo htmlspecialchars($detail_slug); ?>">
                <?php endif; ?>
                
                <!-- Categories -->
                <div class="filter-section">
                    <h3 class="filter-title"><i class="fas fa-th-large"></i> Categories</h3>
                    <ul class="category-list">
                        <li><a href="shop.php"><i class="fas fa-store"></i><span class="cat-name">All Categories</span></a></li>
                        <?php foreach ($umbrella_categories as $umbrella): ?>
                            <li>
                                <a href="category.php?slug=<?php echo urlencode($umbrella['slug']); ?>" 
                                   class="<?php echo ($umbrella_category && isset($umbrella_category['id']) && $umbrella_category['id'] == $umbrella['id']) ? 'active' : ''; ?>">
                                    <i class="fas <?php echo htmlspecialchars($umbrella['display_icon']); ?>"></i>
                                    <span class="cat-name"><?php echo htmlspecialchars($umbrella['name']); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <!-- Price Range -->
                <div class="filter-section">
                    <h3 class="filter-title"><i class="fas fa-tag"></i> Price Range</h3>
                    <div class="price-inputs">
                        <input type="number" name="min_price" placeholder="₵<?php echo number_format($price_range['min'], 2); ?>" 
                               value="<?php echo $min_price > 0 ? $min_price : ''; ?>" min="0" step="0.01">
                        <span>to</span>
                        <input type="number" name="max_price" placeholder="₵<?php echo number_format($price_range['max'], 2); ?>" 
                               value="<?php echo $max_price > 0 ? $max_price : ''; ?>" min="0" step="0.01">
                    </div>
                </div>
                
                <!-- Brands -->
                <?php if (!empty($brands)): ?>
                <div class="filter-section">
                    <h3 class="filter-title"><i class="fas fa-trademark"></i> Brands</h3>
                    <?php foreach ($brands as $brand_item): ?>
                        <label class="checkbox-label">
                            <input type="radio" name="brand" value="<?php echo htmlspecialchars($brand_item['brand']); ?>"
                                   <?php echo $brand_filter === $brand_item['brand'] ? 'checked' : ''; ?>
                                   onchange="this.form.submit()">
                            <?php echo htmlspecialchars($brand_item['brand']); ?>
                            (<?php echo $brand_item['product_count']; ?>)
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Quick Filters -->
                <div class="filter-section">
                    <h3 class="filter-title"><i class="fas fa-sliders-h"></i> Quick Filters</h3>
                    <label class="checkbox-label">
                        <input type="checkbox" name="in_stock" value="1" 
                               <?php echo $in_stock_only ? 'checked' : ''; ?>
                               onchange="this.form.submit()"> In Stock Only
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="on_sale" value="1" 
                               <?php echo $promotion_only ? 'checked' : ''; ?>
                               onchange="this.form.submit()"> On Sale
                    </label>
                </div>
                
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply Filters</button>
                <a href="category.php?slug=<?php echo urlencode($category_slug); ?><?php echo !empty($subcategory_slug) ? '&sub=' . urlencode($subcategory_slug) : ''; ?><?php echo !empty($detail_slug) ? '&detail=' . urlencode($detail_slug) : ''; ?>" class="btn-reset">
                    <i class="fas fa-times"></i> Clear All
                </a>
            </form>
        </aside>
        
        <!-- Main Content -->
        <main>
            
            <!-- Active Filters -->
            <?php if ($min_price > 0 || $max_price > 0 || $in_stock_only || $promotion_only || !empty($brand_filter)): ?>
            <div class="active-filters">
                <?php if ($min_price > 0 || $max_price > 0): ?>
                    <span class="filter-tag">Price: ₵<?php echo $min_price ?: '0'; ?> - ₵<?php echo $max_price ?: '∞'; ?> <a href="?<?php echo buildQueryString(['min_price', 'max_price']); ?>">&times;</a></span>
                <?php endif; ?>
                <?php if (!empty($brand_filter)): ?>
                    <span class="filter-tag">Brand: <?php echo htmlspecialchars($brand_filter); ?> <a href="?<?php echo buildQueryString(['brand']); ?>">&times;</a></span>
                <?php endif; ?>
                <?php if ($in_stock_only): ?>
                    <span class="filter-tag">In Stock <a href="?<?php echo buildQueryString(['in_stock']); ?>">&times;</a></span>
                <?php endif; ?>
                <?php if ($promotion_only): ?>
                    <span class="filter-tag">On Sale <a href="?<?php echo buildQueryString(['on_sale']); ?>">&times;</a></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Toolbar -->
            <?php if ($total_products > 0): ?>
            <div class="category-toolbar">
                <span class="results-count">
                    Showing <?php echo $offset + 1; ?>–<?php echo min($total_products, $offset + $per_page); ?> of <?php echo number_format($total_products); ?> results
                </span>
                <form method="GET" id="sortForm" style="display: inline;">
                    <input type="hidden" name="slug" value="<?php echo htmlspecialchars($category_slug); ?>">
                    <?php if (!empty($subcategory_slug)): ?>
                        <input type="hidden" name="sub" value="<?php echo htmlspecialchars($subcategory_slug); ?>">
                    <?php endif; ?>
                    <?php if (!empty($detail_slug)): ?>
                        <input type="hidden" name="detail" value="<?php echo htmlspecialchars($detail_slug); ?>">
                    <?php endif; ?>
                    <?php foreach ($_GET as $key => $value): ?>
                        <?php if (!in_array($key, ['sort', 'page', 'slug', 'sub', 'detail']) && is_scalar($value)): ?>
                            <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <select name="sort" class="sort-select" onchange="this.form.submit()">
                        <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest</option>
                        <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                        <option value="price_asc" <?php echo $sort_by === 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="price_desc" <?php echo $sort_by === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                        <option value="name_asc" <?php echo $sort_by === 'name_asc' ? 'selected' : ''; ?>>Name: A to Z</option>
                        <option value="name_desc" <?php echo $sort_by === 'name_desc' ? 'selected' : ''; ?>>Name: Z to A</option>
                        <option value="popular" <?php echo $sort_by === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                        <option value="rating" <?php echo $sort_by === 'rating' ? 'selected' : ''; ?>>Highest Rated</option>
                    </select>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- Product Grid -->
            <?php if (!empty($products)): ?>
            <div class="product-grid">
                <?php foreach ($products as $product): 
                    $in_cart = $product['in_cart'];
                    $cart_qty = $product['cart_qty'];
                    $cart_key = $product['cart_key'];
                    $default_tier_id = $product['default_tier_id'];
                    $has_wholesale = $product['has_wholesale'];
                    $has_discount = $product['has_discount'];
                    $discount_percent = $product['discount_percent'];
                    $best_price = $product['best_price'] ?? $product['effective_price'];
                ?>
                <div class="product-card" id="card-<?php echo $product['id']; ?>" data-pid="<?php echo $product['id']; ?>" data-default-tier="<?php echo $default_tier_id; ?>">
                    <div class="product-image-wrapper">
                        <a href="product-detail.php?id=<?php echo $product['id']; ?>&slug=<?php echo urlencode($product['slug']); ?>">
                            <img src="<?php echo htmlspecialchars($product['primary_image']); ?>" 
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 loading="lazy"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="img-placeholder" style="display:none;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:3rem;color:#ddd;background:#f9fafb;">
                                <i class="fas fa-box-open"></i>
                            </div>
                        </a>
                        
                        <?php if ($has_discount && $discount_percent > 0): ?>
                            <span class="product-badge badge-sale">-<?php echo $discount_percent; ?>%</span>
                        <?php endif; ?>
                        
                        <?php if ($product['is_new']): ?>
                            <span class="product-badge badge-new">New</span>
                        <?php endif; ?>
                        
                        <?php if ($product['featured']): ?>
                            <span class="product-badge badge-featured">Featured</span>
                        <?php endif; ?>
                        
                        <?php if ($product['vendor_express']): ?>
                            <span class="product-badge badge-express"><i class="fas fa-bolt"></i> Express</span>
                        <?php endif; ?>
                        
                        <?php if ($has_wholesale && !$has_discount): ?>
                            <span class="product-badge badge-wholesale"><i class="fas fa-boxes"></i> Bulk</span>
                        <?php endif; ?>
                        
                        <?php if ($product['stock_status'] === 'low_stock' && $product['stock_quantity'] > 0): ?>
                            <span class="product-badge badge-low-stock">Low Stock</span>
                        <?php endif; ?>
                        
                        <button class="wishlist-btn <?php echo $product['in_wishlist'] ? 'active' : ''; ?>" 
                                onclick="event.stopPropagation(); toggleWishlist(<?php echo $product['id']; ?>, this)"
                                title="<?php echo $product['in_wishlist'] ? 'Remove from Wishlist' : 'Add to Wishlist'; ?>">
                            <i class="<?php echo $product['in_wishlist'] ? 'fas' : 'far'; ?> fa-heart"></i>
                        </button>
                    </div>
                    
                    <div class="product-info">
                        <div class="product-category"><?php echo htmlspecialchars($product['category_name']); ?></div>
                        
                        <h3 class="product-name">
                            <a href="product-detail.php?id=<?php echo $product['id']; ?>&slug=<?php echo urlencode($product['slug']); ?>">
                                <?php echo htmlspecialchars($product['name']); ?>
                            </a>
                        </h3>
                        
                        <?php if ($product['review_count'] > 0): ?>
                        <div class="product-rating">
                            <span class="stars">
                                <?php
                                $rating_val = round($product['avg_rating'] * 2) / 2;
                                $full = floor($rating_val);
                                $half = ($rating_val - $full) >= 0.5;
                                $empty = 5 - $full - ($half ? 1 : 0);
                                for ($i = 0; $i < $full; $i++) echo '<i class="fas fa-star"></i>';
                                if ($half) echo '<i class="fas fa-star-half-alt"></i>';
                                for ($i = 0; $i < $empty; $i++) echo '<i class="far fa-star"></i>';
                                ?>
                            </span>
                            <span class="rating-count">(<?php echo $product['review_count']; ?>)</span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="product-price">
                            <span class="current-price"><?php echo formatPrice($best_price); ?></span>
                            <?php if ($has_discount && $best_price < $product['original_price'] && $product['original_price'] > 0): ?>
                                <span class="original-price"><?php echo formatPrice($product['original_price']); ?></span>
                                <span class="discount-badge-card">-<?php echo $discount_percent; ?>%</span>
                            <?php endif; ?>
                            <?php if ($has_wholesale && !$has_discount): ?>
                                <span class="wholesale-tag"><i class="fas fa-boxes"></i> Wholesale</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="stock-status <?php echo $product['stock_status']; ?>">
                            <?php if ($product['stock_status'] === 'in_stock'): ?>
                                <i class="fas fa-check-circle"></i> In Stock
                            <?php elseif ($product['stock_status'] === 'low_stock'): ?>
                                <i class="fas fa-exclamation-circle"></i> Only <?php echo $product['stock_quantity']; ?> left
                            <?php else: ?>
                                <i class="fas fa-times-circle"></i> Out of Stock
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($in_cart): ?>
                            <div class="card-actions">
                                <div class="cart-qty-ctrl">
                                    <button class="qty-btn" onclick="updateCartQty('<?php echo $cart_key; ?>', <?php echo $product['id']; ?>, <?php echo $cart_qty - 1; ?>)" <?php echo $cart_qty <= 1 ? 'disabled' : ''; ?>>−</button>
                                    <input type="number" class="qty-input" value="<?php echo $cart_qty; ?>" min="1" max="999"
                                           onchange="updateCartQty('<?php echo $cart_key; ?>', <?php echo $product['id']; ?>, Math.max(1, parseInt(this.value) || 1))">
                                    <button class="qty-btn" onclick="updateCartQty('<?php echo $cart_key; ?>', <?php echo $product['id']; ?>, <?php echo $cart_qty + 1; ?>)">+</button>
                                </div>
                                <button class="remove-btn" onclick="removeFromCart('<?php echo $cart_key; ?>', <?php echo $product['id']; ?>)" title="Remove"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        <?php else: ?>
                            <button class="btn-add-cart" onclick="addToCart(<?php echo $product['id']; ?>, <?php echo $default_tier_id; ?>, this)" <?php echo $product['stock_status'] === 'out_of_stock' ? 'disabled' : ''; ?>>
                                <i class="fas fa-shopping-cart"></i>
                                <?php echo $product['stock_status'] === 'out_of_stock' ? 'Out of Stock' : 'Add to Cart'; ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $page - 1; ?>"><i class="fas fa-chevron-left"></i></a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                if ($start > 1) {
                    echo '<a href="?' . buildQueryString(['page']) . '&page=1">1</a>';
                    if ($start > 2) echo '<span class="disabled">...</span>';
                }
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $i; ?>" 
                       class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor;
                if ($end < $total_pages) {
                    if ($end < $total_pages - 1) echo '<span class="disabled">...</span>';
                    echo '<a href="?' . buildQueryString(['page']) . '&page=' . $total_pages . '">' . $total_pages . '</a>';
                }
                ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $page + 1; ?>"><i class="fas fa-chevron-right"></i></a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No Products Found</h3>
                <p>Try adjusting your filters or check out other categories.</p>
                <a href="shop.php" style="display: inline-block; text-decoration: none; padding: 0.75rem 2rem; background: #0B6E3D; color: white; border-radius: 8px; font-weight: 600; margin-top: 1rem;">
                    <i class="fas fa-shopping-bag"></i> Browse All Products
                </a>
            </div>
            <?php endif; ?>
            
        </main>
        
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<script>
// ============================================================
// BLESS MALL CATEGORY PAGE SCRIPTS
// ============================================================

function showToast(message, type) {
    type = type || 'ok';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    
    var toast = document.createElement('div');
    toast.className = 'toast ' + (type === 'error' ? 'error' : '');
    toast.innerHTML = '<span>' + message + '</span>' +
        '<button class="tcl" onclick="this.parentElement.remove()">&times;</button>';
    container.appendChild(toast);
    
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300);
    }, 4000);
    
    var toasts = container.querySelectorAll('.toast');
    while (toasts.length > 3) { toasts[0].remove(); toasts = container.querySelectorAll('.toast'); }
}

function updateAllCartBadges(count) {
    var cartLink = document.querySelector('a[href="cart.php"]');
    if (cartLink) {
        var badge = cartLink.querySelector('.badge-cart');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'badge-count badge-cart';
                cartLink.appendChild(badge);
            }
            badge.textContent = count;
        } else if (badge) {
            badge.remove();
        }
    }
    var headerBadge = document.getElementById('cartCount');
    if (headerBadge) {
        headerBadge.textContent = count;
        headerBadge.style.display = count > 0 ? 'inline-block' : 'none';
    }
}

function addToCart(productId, pricingTierId, button) {
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    button.disabled = true;
    
    var formData = new URLSearchParams();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('pricing_tier_id', pricingTierId || 0);
    formData.append('quantity', 1);
    
    fetch('category.php', {
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
            updateAllCartBadges(data.cart_count);
            showToast(data.message || 'Added to cart!', 'ok');
            
            var card = document.getElementById('card-' + productId);
            var cartKey = data.cart_key || ('p_' + productId + '_t_' + (data.pricing_tier_id || pricingTierId || 0));
            var qty = data.quantity || 1;
            
            if (card) {
                var info = card.querySelector('.product-info');
                var actions = info.querySelector('.card-actions') || info.querySelector('.btn-add-cart');
                if (actions) {
                    var parent = actions.parentNode;
                    var newHTML = '<div class="card-actions">' +
                        '<div class="cart-qty-ctrl">' +
                            '<button class="qty-btn" onclick="updateCartQty(\'' + cartKey + '\', ' + productId + ', ' + (qty - 1) + ')" ' + (qty <= 1 ? 'disabled' : '') + '>−</button>' +
                            '<input type="number" class="qty-input" value="' + qty + '" min="1" max="999" onchange="updateCartQty(\'' + cartKey + '\', ' + productId + ', Math.max(1, parseInt(this.value) || 1))">' +
                            '<button class="qty-btn" onclick="updateCartQty(\'' + cartKey + '\', ' + productId + ', ' + (qty + 1) + ')">+</button>' +
                        '</div>' +
                        '<button class="remove-btn" onclick="removeFromCart(\'' + cartKey + '\', ' + productId + ')" title="Remove"><i class="fas fa-trash-alt"></i></button>' +
                    '</div>';
                    actions.outerHTML = newHTML;
                }
            }
        } else {
            showToast(data.message || 'Failed to add to cart', 'error');
            button.innerHTML = originalHTML;
            button.disabled = false;
        }
    })
    .catch(function(err) {
        showToast('Connection error. Please try again.', 'error');
        button.innerHTML = originalHTML;
        button.disabled = false;
    });
}

function updateCartQty(cartKey, productId, newQty) {
    newQty = Math.max(0, Math.min(999, parseInt(newQty) || 0));
    if (newQty === 0) { removeFromCart(cartKey, productId); return; }
    
    var card = document.getElementById('card-' + productId);
    if (!card) return;
    
    var qtyInput = card.querySelector('.qty-input');
    if (qtyInput) qtyInput.value = newQty;
    var minusBtn = card.querySelector('.qty-btn:first-child');
    if (minusBtn) minusBtn.disabled = (newQty <= 1);
    
    var formData = new URLSearchParams();
    formData.append('action', 'update_cart_quantity');
    formData.append('cart_key', cartKey);
    formData.append('quantity', newQty);
    
    fetch('category.php', {
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
            updateAllCartBadges(data.cart_count);
        }
    })
    .catch(function(err) { console.error('Update cart error:', err); });
}

function removeFromCart(cartKey, productId) {
    var card = document.getElementById('card-' + productId);
    if (!card) return;
    
    var formData = new URLSearchParams();
    formData.append('action', 'remove_from_cart');
    formData.append('cart_key', cartKey);
    
    fetch('category.php', {
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
            updateAllCartBadges(data.cart_count);
            showToast('Removed from cart', 'ok');
            
            var info = card.querySelector('.product-info');
            var actions = info.querySelector('.card-actions');
            var isOutOfStock = card.querySelector('.stock-status.out-of-stock') !== null;
            var defaultTier = parseInt(card.dataset.defaultTier) || 0;
            
            if (actions) {
                actions.outerHTML = '<button class="btn-add-cart" onclick="addToCart(' + productId + ', ' + defaultTier + ', this)" ' + (isOutOfStock ? 'disabled' : '') + '>' +
                    '<i class="fas fa-shopping-cart"></i> ' + (isOutOfStock ? 'Out of Stock' : 'Add to Cart') +
                '</button>';
            }
        }
    })
    .catch(function(err) { console.error('Remove from cart error:', err); });
}

function toggleWishlist(productId, button) {
    <?php if (Auth::isLoggedIn()): ?>
    var icon = button.querySelector('i');
    var prevClass = icon.className;
    icon.className = 'fas fa-spinner fa-spin';
    button.disabled = true;
    
    var formData = new URLSearchParams();
    formData.append('action', 'toggle_wishlist');
    formData.append('product_id', productId);
    
    fetch('category.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        button.disabled = false;
        if (data.success) {
            if (data.action === 'added') {
                button.classList.add('active');
                icon.className = 'fas fa-heart';
                showToast('Added to wishlist! ❤️', 'ok');
            } else {
                button.classList.remove('active');
                icon.className = 'far fa-heart';
                showToast('Removed from wishlist', 'ok');
            }
        } else if (data.redirect) {
            window.location.href = data.redirect;
        } else {
            icon.className = prevClass;
            showToast(data.message || 'Error', 'error');
        }
    })
    .catch(function(err) {
        icon.className = prevClass;
        button.disabled = false;
        showToast('Connection error', 'error');
    });
    <?php else: ?>
    window.location.href = 'login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>';
    <?php endif; ?>
}

document.addEventListener('DOMContentLoaded', function() {
    var initialCartCount = <?php echo $cart_count; ?>;
    updateAllCartBadges(initialCartCount);
});

console.log('%c🛍️ Bless Mall Category %cv3',
    'font-size:1.2em;font-weight:bold;color:#0a6e3d;',
    'font-size:1em;color:#c9a227;');
console.log('%cFull pricing tiers & image support enabled', 'font-style:italic;color:#666;');
</script>

<?php require_once __DIR__ . '/footer.php'; ?>