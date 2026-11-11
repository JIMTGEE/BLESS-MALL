<?php
/**
 * index.php – Bless Mall Homepage v21
 * Shows active categories (including sub-categories) and active catalog products
 * Dynamic Hero + Big Category Grid + Product Variants
 */

// ─── BOOTSTRAP ─────────────────────────────────────────────────────
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

// ─── AJAX HANDLER ──────────────────────────────────────────────────
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $conn = getConnection();
    
    // ─── ADD TO CART (supports variant) ────────────────────────────
    if ($action === 'add_to_cart') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $pricing_tier_id = (int)($_POST['pricing_tier_id'] ?? 0);
        $variant_id = (int)($_POST['variant_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        
        // If variant_id given but tier not, find the retail tier for that variant
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
                SELECT p.id, p.name, p.slug,
                       ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                       ppt.unit_price, ppt.discount_price, ppt.discount_percentage
                FROM products p
                JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                WHERE p.id = ? AND ppt.id = ? AND p.is_active = 1 AND ppt.is_active = 1
                LIMIT 1
            ");
            $stmt->bind_param("ii", $product_id, $pricing_tier_id);
        } else {
            // fallback to any retail tier
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.slug,
                       ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                       ppt.unit_price, ppt.discount_price, ppt.discount_percentage
                FROM products p
                JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                WHERE p.id = ? AND ppt.pricing_type = 'retail' AND p.is_active = 1 AND ppt.is_active = 1
                ORDER BY ppt.unit_price ASC LIMIT 1
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
            $_SESSION['cart'][$cart_key] = [
                'product_id' => $product_id,
                'pricing_tier_id' => (int)$product['pricing_tier_id'],
                'name' => $product['name'],
                'slug' => $product['slug'],
                'tier_name' => $product['tier_name'] ?? 'Standard',
                'pricing_type' => $product['pricing_type'] ?? 'retail',
                'price' => $final_price,
                'original_price' => $unit_price,
                'quantity' => $quantity,
                'image' => '',
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
            'cart_count' => $total_items
        ]);
        exit;
    }
    
    // ─── TOGGLE WISHLIST ────────────────────────────────────────
    if ($action === 'toggle_wishlist') {
        if (!Auth::isLoggedIn()) {
            echo json_encode(['success' => false, 'message' => 'Please login', 'redirect' => 'login.php']);
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
            $ins = $conn->prepare("INSERT INTO wishlist (user_id, product_id, created_at) VALUES (?, ?, NOW())");
            $ins->bind_param("ii", $user_id, $product_id);
            $ins->execute();
            $ins->close();
            echo json_encode(['success' => true, 'action' => 'added', 'message' => 'Added to wishlist! ❤️']);
        }
        exit;
    }
    
    // ─── SUBSCRIBE NEWSLETTER ──────────────────────────────────
    if ($action === 'subscribe') {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
            exit;
        }
        
        // Check if newsletter_subscribers table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'newsletter_subscribers'");
        if ($tableCheck->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Newsletter signup is temporarily unavailable.']);
            exit;
        }
        
        $checkStmt = $conn->prepare("SELECT id FROM newsletter_subscribers WHERE email = ?");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        
        if ($exists) {
            echo json_encode(['success' => true, 'message' => 'You are already subscribed!']);
            exit;
        }
        
        $insStmt = $conn->prepare("INSERT INTO newsletter_subscribers (email, subscribed_at, status) VALUES (?, NOW(), 'active')");
        $insStmt->bind_param("s", $email);
        $insStmt->execute();
        $insStmt->close();
        
        echo json_encode(['success' => true, 'message' => '🎉 Thank you for subscribing!']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ─── FETCH DATA ──────────────────────────────────────────────────
$conn = getConnection();

// ─── CATEGORIES: FETCH ALL CATEGORIES (NO FILTERS) ──────────────
$categories = [];
$catQuery = "
    SELECT 
        c.id, c.name, c.slug, c.icon, c.image, c.description, c.parent_id,
        (SELECT COUNT(*) FROM products p
         INNER JOIN vendors v ON v.id = p.vendor_id AND v.status = 'active'
         WHERE p.category_id = c.id AND p.is_active = 1) as product_count
    FROM categories c
    WHERE c.is_active = 1
    ORDER BY 
        CASE WHEN c.parent_id IS NULL THEN 0 ELSE 1 END,
        COALESCE(c.parent_id, c.id) ASC,
        c.name ASC
";
$catResult = $conn->query($catQuery);
if ($catResult) {
    while ($row = $catResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

// ─── TOTAL PRODUCTS COUNT ──────────────────────────────────────
$totalProducts = 0;
$tpQuery = "
    SELECT COUNT(*) as cnt
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
    INNER JOIN vendors v ON v.id = p.vendor_id AND v.status = 'active'
    WHERE p.is_active = 1
";
$tpResult = $conn->query($tpQuery);
if ($tpResult && $row = $tpResult->fetch_assoc()) {
    $totalProducts = (int)$row['cnt'];
}

// ─── TOTAL VENDORS COUNT ──────────────────────────────────────
$totalVendors = 0;
$tvQuery = "SELECT COUNT(*) as cnt FROM vendors WHERE status = 'active'";
$tvResult = $conn->query($tvQuery);
if ($tvResult && $row = $tvResult->fetch_assoc()) {
    $totalVendors = (int)$row['cnt'];
}

// ─── HERO SLIDES: FEATURED PRODUCTS + CATEGORIES + PROMOTIONS ──
$hero_slides = [];

// Get featured products for hero
$heroProductsQuery = "
    SELECT 
        p.id, p.name, p.slug, p.short_description, p.brand,
        (SELECT pi.image_path FROM product_images pi 
         WHERE pi.product_id = p.id 
         ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) as image,
        COALESCE(v.business_name, 'Bless Mall') as vendor_name,
        COALESCE(v.is_express, 0) as is_express,
        COALESCE((SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.product_id = p.id), 0) as avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id) as review_count,
        'product' as slide_type
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
    INNER JOIN vendors v ON v.id = p.vendor_id AND v.status = 'active'
    WHERE p.is_active = 1 AND p.featured = 1
    ORDER BY RAND()
    LIMIT 6
";
$heroResult = $conn->query($heroProductsQuery);
if ($heroResult) {
    while ($row = $heroResult->fetch_assoc()) {
        $product_id = (int)$row['id'];
        
        // Get pricing
        $priceStmt = $conn->prepare("
            SELECT id, pricing_type, unit_price, discount_price, discount_percentage
            FROM product_pricing_tiers 
            WHERE product_id = ? AND is_active = 1 AND pricing_type = 'retail'
            ORDER BY unit_price ASC LIMIT 1
        ");
        $priceStmt->bind_param("i", $product_id);
        $priceStmt->execute();
        $priceResult = $priceStmt->get_result();
        $priceData = $priceResult->fetch_assoc();
        $priceStmt->close();
        
        if ($priceData) {
            $unit_price = (float)$priceData['unit_price'];
            $discount_price = !empty($priceData['discount_price']) ? (float)$priceData['discount_price'] : null;
            $discount_percentage = !empty($priceData['discount_percentage']) ? (float)$priceData['discount_percentage'] : null;
            
            $final_price = $unit_price;
            $has_discount = false;
            $discount_pct = 0;
            
            if ($discount_price && $discount_price > 0 && $discount_price < $unit_price) {
                $final_price = $discount_price;
                $has_discount = true;
                $discount_pct = round(($unit_price - $final_price) / $unit_price * 100);
            } elseif ($discount_percentage && $discount_percentage > 0) {
                $final_price = round($unit_price * (1 - $discount_percentage / 100), 2);
                $has_discount = true;
                $discount_pct = round($discount_percentage);
            }
            
            $row['price'] = $final_price;
            $row['original_price'] = $unit_price;
            $row['has_discount'] = $has_discount;
            $row['discount_pct'] = $discount_pct;
            $row['pricing_tier_id'] = $priceData['id'];
        } else {
            $row['price'] = 0;
            $row['original_price'] = 0;
            $row['has_discount'] = false;
            $row['discount_pct'] = 0;
            $row['pricing_tier_id'] = 0;
        }
        
        $hero_slides[] = $row;
    }
}

// Get categories for hero slides (if not enough products)
if (count($hero_slides) < 4) {
    $catSlideQuery = "
        SELECT 
            id, name, slug, image, icon, description,
            'category' as slide_type,
            (SELECT COUNT(*) FROM products p
             INNER JOIN vendors v ON v.id = p.vendor_id AND v.status = 'active'
             WHERE p.category_id = c.id AND p.is_active = 1) as product_count
        FROM categories c
        WHERE c.is_active = 1
        ORDER BY RAND()
        LIMIT " . (6 - count($hero_slides))
    ;
    $catSlideResult = $conn->query($catSlideQuery);
    if ($catSlideResult) {
        while ($row = $catSlideResult->fetch_assoc()) {
            $hero_slides[] = $row;
        }
    }
}

// ─── FEATURED PRODUCTS (with variants) ──────────────────────────
$featured_products = [];
$fpQuery = "
    SELECT 
        p.id, p.name, p.slug, p.short_description, p.brand, p.featured,
        (SELECT pi.image_path FROM product_images pi 
         WHERE pi.product_id = p.id 
         ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) as image,
        COALESCE(v.business_name, 'Bless Mall') as vendor_name,
        COALESCE(v.is_express, 0) as is_express,
        COALESCE((SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.product_id = p.id), 0) as avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id) as review_count
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
    INNER JOIN vendors v ON v.id = p.vendor_id AND v.status = 'active'
    WHERE p.is_active = 1 AND p.featured = 1
    ORDER BY p.created_at DESC
    LIMIT 8
";
$fpResult = $conn->query($fpQuery);
if ($fpResult) {
    while ($row = $fpResult->fetch_assoc()) {
        $product_id = (int)$row['id'];
        
        // Fetch variants with pricing
        $variants = [];
        $varStmt = $conn->prepare("
            SELECT pv.id, pv.variant_name, pv.variant_value, pv.sku_suffix,
                   ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                   ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
                   COALESCE(pi.stock_quantity - pi.reserved_quantity, 0) as available_stock
            FROM product_variants pv
            LEFT JOIN product_pricing_tiers ppt ON pv.id = ppt.product_variant_id AND ppt.is_active = 1 AND ppt.pricing_type = 'retail'
            LEFT JOIN product_inventory pi ON ppt.id = pi.pricing_tier_id
            WHERE pv.product_id = ?
            ORDER BY pv.variant_name ASC, pv.variant_value ASC
        ");
        $varStmt->bind_param("i", $product_id);
        $varStmt->execute();
        $varResult = $varStmt->get_result();
        while ($var = $varResult->fetch_assoc()) {
            // Calculate effective price for this variant
            $unit_price = (float)$var['unit_price'];
            $discount_price = !empty($var['discount_price']) ? (float)$var['discount_price'] : null;
            $discount_percentage = !empty($var['discount_percentage']) ? (float)$var['discount_percentage'] : null;
            
            $final_price = $unit_price;
            $has_discount = false;
            $discount_pct = 0;
            
            if ($discount_price && $discount_price > 0 && $discount_price < $unit_price) {
                $final_price = $discount_price;
                $has_discount = true;
                $discount_pct = round(($unit_price - $final_price) / $unit_price * 100);
            } elseif ($discount_percentage && $discount_percentage > 0) {
                $final_price = round($unit_price * (1 - $discount_percentage / 100), 2);
                $has_discount = true;
                $discount_pct = round($discount_percentage);
            }
            
            $var['effective_price'] = $final_price;
            $var['original_price'] = $unit_price;
            $var['has_discount'] = $has_discount;
            $var['discount_pct'] = $discount_pct;
            $variants[] = $var;
        }
        $varStmt->close();
        
        // Get pricing tiers for default price (when no variant selected)
        $tiers = [];
        $ptStmt = $conn->prepare("
            SELECT id, pricing_type, tier_name, min_quantity,
                   unit_price, discount_price, discount_percentage
            FROM product_pricing_tiers 
            WHERE product_id = ? AND is_active = 1
            ORDER BY FIELD(pricing_type, 'retail', 'wholesale'), min_quantity ASC
        ");
        $ptStmt->bind_param("i", $product_id);
        $ptStmt->execute();
        $ptResult = $ptStmt->get_result();
        while ($tier = $ptResult->fetch_assoc()) {
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
            $tiers[] = $tier;
        }
        $ptStmt->close();
        
        $best_price = 0;
        $default_tier_id = 0;
        foreach ($tiers as $tier) {
            if ($tier['pricing_type'] === 'retail' && ($best_price === 0 || $tier['final_price'] < $best_price)) {
                $best_price = $tier['final_price'];
                $default_tier_id = $tier['id'];
            }
        }
        if ($best_price === 0 && !empty($tiers)) {
            $best_price = $tiers[0]['final_price'];
            $default_tier_id = $tiers[0]['id'];
        }
        
        $row['pricing_tiers'] = $tiers;
        $row['best_price'] = $best_price;
        $row['default_tier_id'] = $default_tier_id;
        $row['variants'] = $variants;
        $featured_products[] = $row;
    }
}

// ─── LATEST PRODUCTS (with variants) ────────────────────────────
$latest_products = [];
$lpQuery = "
    SELECT 
        p.id, p.name, p.slug, p.short_description, p.brand,
        (SELECT pi.image_path FROM product_images pi 
         WHERE pi.product_id = p.id 
         ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) as image,
        COALESCE(v.business_name, 'Bless Mall') as vendor_name,
        COALESCE(v.is_express, 0) as is_express,
        COALESCE((SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.product_id = p.id), 0) as avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id) as review_count
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
    INNER JOIN vendors v ON v.id = p.vendor_id AND v.status = 'active'
    WHERE p.is_active = 1
    ORDER BY p.created_at DESC
    LIMIT 12
";
$lpResult = $conn->query($lpQuery);
if ($lpResult) {
    while ($row = $lpResult->fetch_assoc()) {
        $product_id = (int)$row['id'];
        
        // Fetch variants with pricing
        $variants = [];
        $varStmt = $conn->prepare("
            SELECT pv.id, pv.variant_name, pv.variant_value, pv.sku_suffix,
                   ppt.id as pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                   ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
                   COALESCE(pi.stock_quantity - pi.reserved_quantity, 0) as available_stock
            FROM product_variants pv
            LEFT JOIN product_pricing_tiers ppt ON pv.id = ppt.product_variant_id AND ppt.is_active = 1 AND ppt.pricing_type = 'retail'
            LEFT JOIN product_inventory pi ON ppt.id = pi.pricing_tier_id
            WHERE pv.product_id = ?
            ORDER BY pv.variant_name ASC, pv.variant_value ASC
        ");
        $varStmt->bind_param("i", $product_id);
        $varStmt->execute();
        $varResult = $varStmt->get_result();
        while ($var = $varResult->fetch_assoc()) {
            $unit_price = (float)$var['unit_price'];
            $discount_price = !empty($var['discount_price']) ? (float)$var['discount_price'] : null;
            $discount_percentage = !empty($var['discount_percentage']) ? (float)$var['discount_percentage'] : null;
            
            $final_price = $unit_price;
            $has_discount = false;
            $discount_pct = 0;
            
            if ($discount_price && $discount_price > 0 && $discount_price < $unit_price) {
                $final_price = $discount_price;
                $has_discount = true;
                $discount_pct = round(($unit_price - $final_price) / $unit_price * 100);
            } elseif ($discount_percentage && $discount_percentage > 0) {
                $final_price = round($unit_price * (1 - $discount_percentage / 100), 2);
                $has_discount = true;
                $discount_pct = round($discount_percentage);
            }
            
            $var['effective_price'] = $final_price;
            $var['original_price'] = $unit_price;
            $var['has_discount'] = $has_discount;
            $var['discount_pct'] = $discount_pct;
            $variants[] = $var;
        }
        $varStmt->close();
        
        // Get pricing tiers for default price
        $tiers = [];
        $ptStmt = $conn->prepare("
            SELECT id, pricing_type, tier_name, min_quantity,
                   unit_price, discount_price, discount_percentage
            FROM product_pricing_tiers 
            WHERE product_id = ? AND is_active = 1
            ORDER BY FIELD(pricing_type, 'retail', 'wholesale'), min_quantity ASC
        ");
        $ptStmt->bind_param("i", $product_id);
        $ptStmt->execute();
        $ptResult = $ptStmt->get_result();
        while ($tier = $ptResult->fetch_assoc()) {
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
            $tiers[] = $tier;
        }
        $ptStmt->close();
        
        $best_price = 0;
        $default_tier_id = 0;
        foreach ($tiers as $tier) {
            if ($tier['pricing_type'] === 'retail' && ($best_price === 0 || $tier['final_price'] < $best_price)) {
                $best_price = $tier['final_price'];
                $default_tier_id = $tier['id'];
            }
        }
        if ($best_price === 0 && !empty($tiers)) {
            $best_price = $tiers[0]['final_price'];
            $default_tier_id = $tiers[0]['id'];
        }
        
        $row['pricing_tiers'] = $tiers;
        $row['best_price'] = $best_price;
        $row['default_tier_id'] = $default_tier_id;
        $row['variants'] = $variants;
        $latest_products[] = $row;
    }
}

// ─── CART COUNT ──────────────────────────────────────────────────
$cartCount = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += (int)($item['quantity'] ?? 0);
    }
}

// ─── HELPERS ─────────────────────────────────────────────────────
function formatPrice($price) {
    if ($price === null || $price < 0) return 'GH₵ 0.00';
    return 'GH₵ ' . number_format((float)$price, 2, '.', ',');
}

function getProductImageUrl($imagePath) {
    if (empty($imagePath)) return '';
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) return $imagePath;
    
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
    return '';
}

/**
 * Get category image: use uploaded image if exists, otherwise fallback to Unsplash
 */
function getCategoryImage($categoryName, $categoryImage = null) {
    // If category has its own image, use it
    if (!empty($categoryImage)) {
        // If it's a full URL, return as is
        if (filter_var($categoryImage, FILTER_VALIDATE_URL)) {
            return $categoryImage;
        }
        // Otherwise, treat as relative path
        return BASE_URL . '/' . ltrim($categoryImage, '/');
    }
    
    // Map category names to Unsplash search terms with curated image IDs
    $imageMap = [
        'electronics' => 'https://images.unsplash.com/photo-1498049794561-7780e7231661?w=600&h=400&fit=crop',
        'phones' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?w=600&h=400&fit=crop',
        'fashion' => 'https://images.unsplash.com/photo-1445205170230-053b83016050?w=600&h=400&fit=crop',
        'beauty' => 'https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9?w=600&h=400&fit=crop',
        'home' => 'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=600&h=400&fit=crop',
        'kitchen' => 'https://images.unsplash.com/photo-1556911220-bff31c812dba?w=600&h=400&fit=crop',
        'food' => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=600&h=400&fit=crop',
        'health' => 'https://images.unsplash.com/photo-1559757175-5700dde675bc?w=600&h=400&fit=crop',
        'sports' => 'https://images.unsplash.com/photo-1461896836934-bd5e0788c76f?w=600&h=400&fit=crop',
        'automotive' => 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?w=600&h=400&fit=crop',
        'agriculture' => 'https://images.unsplash.com/photo-1500937386664-56d1dfef3854?w=600&h=400&fit=crop',
        'toys' => 'https://images.unsplash.com/photo-1566576912321-d58ddd7a6088?w=600&h=400&fit=crop',
        'books' => 'https://images.unsplash.com/photo-1495446815901-a7297e633e8d?w=600&h=400&fit=crop',
        'jewelry' => 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=600&h=400&fit=crop',
        'tools' => 'https://images.unsplash.com/photo-1530124566582-a618bc2615dc?w=600&h=400&fit=crop',
        'baby' => 'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=600&h=400&fit=crop',
        'garden' => 'https://images.unsplash.com/photo-1585320806297-9794b3e4eeae?w=600&h=400&fit=crop',
        'office' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=600&h=400&fit=crop',
        'pet' => 'https://images.unsplash.com/photo-1548199973-03cce0bbc87b?w=600&h=400&fit=crop',
        'music' => 'https://images.unsplash.com/photo-1511379938547-c1f69419868d?w=600&h=400&fit=crop',
        'camera' => 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?w=600&h=400&fit=crop',
        'watch' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&h=400&fit=crop',
        'bag' => 'https://images.unsplash.com/photo-1547949003-9792a18a2601?w=600&h=400&fit=crop',
        'shoe' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=600&h=400&fit=crop',
        'lifestyle' => 'https://images.unsplash.com/photo-1511988617509-a57c8f2882b7?w=600&h=400&fit=crop',
        'default' => 'https://images.unsplash.com/photo-1472851294608-062f824d29cc?w=600&h=400&fit=crop'
    ];
    
    $name_lower = strtolower($categoryName);
    foreach ($imageMap as $key => $url) {
        if (strpos($name_lower, $key) !== false) {
            return $url;
        }
    }
    return $imageMap['default'];
}

function getCategoryIcon($name) {
    $map = [
        'agriculture' => 'fa-seedling',
        'automotive' => 'fa-car',
        'electronics' => 'fa-microchip',
        'food' => 'fa-apple-alt',
        'health' => 'fa-heartbeat',
        'home' => 'fa-home',
        'lifestyle' => 'fa-leaf',
        'sports' => 'fa-running',
        'phones' => 'fa-mobile-alt',
        'fashion' => 'fa-tshirt',
        'beauty' => 'fa-spa',
        'toys' => 'fa-gamepad',
        'books' => 'fa-book-open',
        'jewelry' => 'fa-gem',
        'tools' => 'fa-tools',
        'kitchen' => 'fa-utensils',
        'baby' => 'fa-baby',
        'garden' => 'fa-tree',
        'office' => 'fa-building',
        'pet' => 'fa-paw',
        'music' => 'fa-music',
        'camera' => 'fa-camera',
        'watch' => 'fa-clock',
        'bag' => 'fa-shopping-bag',
        'shoe' => 'fa-shoe-prints',
        'default' => 'fa-tag'
    ];
    
    $name_lower = strtolower($name);
    foreach ($map as $key => $icon) {
        if (strpos($name_lower, $key) !== false) {
            return $icon;
        }
    }
    return $map['default'];
}

// ─── PAGE METADATA ─────────────────────────────────────────────
$page_title = 'Bless Mall – Premium Shopping in Ghana';
require_once __DIR__ . '/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Bless Mall – Premium Shopping in Ghana</title>
    <meta name="description" content="Shop quality products at Blessed Mall Ghana. Get the best prices on electronics, fashion, home goods and more with fast delivery across Ghana.">
    <meta name="format-detection" content="telephone=no">
    <meta name="color-scheme" content="only light">
    
    <!-- Open Graph -->
    <meta property="og:title" content="Bless Mall – Premium Shopping in Ghana">
    <meta property="og:description" content="Shop quality products at Blessed Mall Ghana. Best prices on electronics, fashion, home goods and more.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>">
    <meta property="og:image" content="<?php echo BASE_URL; ?>assets/images/social/og-image.jpg">
    <meta property="og:site_name" content="Bless Mall">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Bless Mall – Premium Shopping in Ghana">
    <meta name="twitter:description" content="Shop quality products at Blessed Mall Ghana.">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,900;1,400;1,700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo BASE_URL; ?>favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="<?php echo BASE_URL; ?>favicon.ico">
    
    <style>
    /* ═══════════════════════════════════════════════════════════════
       BLESS MALL — ULTRA-PREMIUM HOMEPAGE v21
       ALL categories shown (no filter) + Dynamic Hero + Variants
    ═══════════════════════════════════════════════════════════════ */
    
    /* ─── CSS VARIABLES ──────────────────────────────────────── */
    :root {
        --primary: #0B6E3D;
        --primary-dark: #085229;
        --primary-light: #1FAF5B;
        --primary-glow: rgba(11,110,61,0.3);
        --gold: #C9A227;
        --gold-glow: rgba(201,162,39,0.3);
        --gold-dark: #A6861F;
        --dark: #0A1A12;
        --dark-bg: #060E0A;
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
        --shadow-2xl: 0 40px 80px rgba(0,0,0,0.15);
        --radius-sm: 8px;
        --radius: 16px;
        --radius-lg: 24px;
        --radius-xl: 32px;
        --radius-full: 9999px;
        --transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        --transition-slow: 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        --font-primary: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        --font-display: 'Playfair Display', Georgia, serif;
    }

    /* ─── RESET ───────────────────────────────────────────────── */
    *, *::before, *::after {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    html {
        scroll-behavior: smooth;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    
    body {
        font-family: var(--font-primary);
        background: var(--off-white);
        color: var(--text);
        line-height: 1.6;
        overflow-x: hidden;
    }
    
    ::selection {
        background: var(--primary);
        color: var(--white);
    }
    
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    ::-webkit-scrollbar-track {
        background: var(--off-white);
    }
    ::-webkit-scrollbar-thumb {
        background: var(--primary);
        border-radius: 10px;
    }

    /* ─── UTILITY ─────────────────────────────────────────────── */
    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }
    
    .section-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--primary);
        background: rgba(11, 110, 61, 0.08);
        padding: 0.3rem 1rem;
        border-radius: var(--radius-full);
        margin-bottom: 0.75rem;
    }
    .section-badge .line {
        width: 24px;
        height: 2px;
        background: var(--primary);
        border-radius: 2px;
    }
    
    .section-title {
        font-family: var(--font-display);
        font-size: clamp(1.8rem, 4vw, 2.8rem);
        font-weight: 700;
        color: var(--dark);
        line-height: 1.1;
        margin-bottom: 0.5rem;
    }
    .section-title .highlight {
        color: var(--primary);
        position: relative;
    }
    .section-title .highlight::after {
        content: '';
        position: absolute;
        bottom: 2px;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--gold);
        border-radius: 4px;
        opacity: 0.4;
    }
    .section-subtitle {
        font-size: 1rem;
        color: var(--text-secondary);
        max-width: 560px;
        line-height: 1.7;
    }
    
    .section-header {
        text-align: center;
        margin-bottom: 2.5rem;
    }
    .section-header .section-subtitle {
        margin: 0 auto;
    }

    .btn-primary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.9rem 2.2rem;
        background: var(--primary);
        color: var(--white);
        border: none;
        border-radius: var(--radius-full);
        font-family: var(--font-primary);
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        box-shadow: 0 4px 24px var(--primary-glow);
    }
    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-3px) scale(1.02);
        box-shadow: 0 8px 40px var(--primary-glow);
    }
    
    .btn-outline {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.85rem 2rem;
        background: transparent;
        color: var(--primary);
        border: 2px solid var(--primary);
        border-radius: var(--radius-full);
        font-family: var(--font-primary);
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
    }
    .btn-outline:hover {
        background: var(--primary);
        color: var(--white);
        transform: translateY(-3px);
        box-shadow: 0 8px 30px var(--primary-glow);
    }
    
    .btn-gold {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.9rem 2.2rem;
        background: var(--gold);
        color: var(--dark);
        border: none;
        border-radius: var(--radius-full);
        font-family: var(--font-primary);
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        box-shadow: 0 4px 24px var(--gold-glow);
    }
    .btn-gold:hover {
        background: var(--gold-dark);
        transform: translateY(-3px) scale(1.02);
        box-shadow: 0 8px 40px var(--gold-glow);
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
    .top-bar-left i {
        color: var(--primary-light);
    }
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
    .top-bar-right a:hover {
        color: var(--gold);
    }
    .top-bar-right .express-link {
        color: var(--gold);
    }

    @media (min-width: 768px) {
        .top-bar { display: block; }
    }

    /* ─── HERO SLIDESHOW ──────────────────────────────────────── */
    .hero-slideshow {
        position: relative;
        width: 100%;
        height: 90vh;
        min-height: 600px;
        max-height: 900px;
        overflow: hidden;
        background: var(--dark-bg);
    }
    
    .hero-slide {
        position: absolute;
        inset: 0;
        opacity: 0;
        visibility: hidden;
        transition: opacity 1.2s ease, transform 1.2s ease;
        transform: scale(1.05);
        will-change: transform, opacity;
    }
    
    .hero-slide.active {
        opacity: 1;
        visibility: visible;
        transform: scale(1);
    }
    
    .hero-slide-bg {
        position: absolute;
        inset: 0;
        background-size: cover;
        background-position: center;
        filter: brightness(0.4);
        transition: filter 1.5s ease;
    }
    
    .hero-slide.active .hero-slide-bg {
        filter: brightness(0.5);
    }
    
    /* Gradient overlay */
    .hero-slide .overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, 
            rgba(6,14,10,0.85) 0%, 
            rgba(10,31,21,0.6) 40%, 
            rgba(13,42,28,0.3) 70%,
            rgba(6,14,10,0.7) 100%
        );
        z-index: 1;
    }
    
    .hero-slide-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        width: 100%;
        height: 100%;
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 3rem;
    }
    
    .hero-slide-text {
        max-width: 600px;
        animation: slideUp 0.8s ease 0.3s both;
    }
    
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(40px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .hero-slide-text .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: var(--radius-full);
        padding: 0.3rem 1rem 0.3rem 0.6rem;
        font-size: 0.7rem;
        color: rgba(255,255,255,0.7);
        font-weight: 500;
        margin-bottom: 1rem;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }
    .hero-slide-text .badge .dot {
        width: 8px;
        height: 8px;
        background: #22C55E;
        border-radius: 50%;
        animation: pulse-dot 2s infinite;
    }
    @keyframes pulse-dot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.4; transform: scale(0.7); }
    }
    
    .hero-slide-text h2 {
        font-family: var(--font-display);
        font-size: clamp(2rem, 5vw, 3.5rem);
        font-weight: 700;
        color: var(--white);
        line-height: 1.05;
        margin-bottom: 0.5rem;
    }
    .hero-slide-text h2 .highlight {
        color: var(--gold);
        position: relative;
        display: inline-block;
    }
    .hero-slide-text h2 .highlight::after {
        content: '';
        position: absolute;
        bottom: 4px;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--gold);
        border-radius: 4px;
        opacity: 0.3;
    }
    
    .hero-slide-text p {
        font-size: 1.05rem;
        color: rgba(255,255,255,0.6);
        margin-bottom: 1.5rem;
        line-height: 1.7;
        max-width: 440px;
    }
    
    .hero-slide-text .price {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--gold);
        margin-bottom: 0.5rem;
    }
    .hero-slide-text .price .original {
        font-size: 1rem;
        color: rgba(255,255,255,0.3);
        text-decoration: line-through;
        margin-left: 0.5rem;
        font-weight: 400;
    }
    .hero-slide-text .price .discount {
        font-size: 0.75rem;
        background: #DC2626;
        color: #fff;
        padding: 0.1rem 0.6rem;
        border-radius: 20px;
        margin-left: 0.5rem;
        font-weight: 700;
    }
    
    .hero-slide-text .actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-top: 1.5rem;
    }
    
    /* Hero navigation dots */
    .hero-dots {
        position: absolute;
        bottom: 2rem;
        left: 50%;
        transform: translateX(-50%);
        z-index: 10;
        display: flex;
        gap: 0.75rem;
        padding: 0.5rem 1rem;
        background: rgba(0,0,0,0.3);
        border-radius: var(--radius-full);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
    .hero-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: rgba(255,255,255,0.3);
        cursor: pointer;
        transition: var(--transition);
        border: none;
        padding: 0;
    }
    .hero-dot.active {
        background: var(--gold);
        width: 30px;
        border-radius: 10px;
    }
    .hero-dot:hover {
        background: rgba(255,255,255,0.6);
    }
    .hero-dot.active:hover {
        background: var(--gold);
    }
    
    /* Hero arrows */
    .hero-arrow {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        z-index: 10;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        color: rgba(255,255,255,0.6);
        font-size: 1.2rem;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
    .hero-arrow:hover {
        background: rgba(255,255,255,0.1);
        color: var(--white);
        transform: translateY(-50%) scale(1.05);
    }
    .hero-arrow.prev {
        left: 1.5rem;
    }
    .hero-arrow.next {
        right: 1.5rem;
    }
    
    @media (max-width: 768px) {
        .hero-slideshow {
            height: 70vh;
            min-height: 450px;
            max-height: 650px;
        }
        .hero-slide-content {
            padding: 0 1.5rem;
            justify-content: center;
            text-align: center;
        }
        .hero-slide-text {
            max-width: 100%;
        }
        .hero-slide-text p {
            max-width: 100%;
            font-size: 0.9rem;
        }
        .hero-slide-text .price {
            font-size: 1.4rem;
        }
        .hero-slide-text .actions {
            justify-content: center;
        }
        .hero-arrow {
            display: none;
        }
        .hero-dots {
            bottom: 1rem;
            gap: 0.5rem;
            padding: 0.3rem 0.75rem;
        }
        .hero-dot {
            width: 8px;
            height: 8px;
        }
        .hero-dot.active {
            width: 24px;
        }
    }

    /* ─── BIG CATEGORY GRID (ALL CATEGORIES) ──────────────────── */
    .categories-section {
        padding: 3rem 0 4rem;
        background: var(--white);
    }
    
    .categories-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
    }
    
    .category-card {
        position: relative;
        border-radius: var(--radius);
        overflow: hidden;
        aspect-ratio: 3/2;
        text-decoration: none;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        cursor: pointer;
        background: #f5f5f5;
    }
    .category-card:hover {
        transform: translateY(-8px) scale(1.01);
        box-shadow: var(--shadow-xl);
        border-color: var(--gold);
    }
    
    .category-card .cat-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.6s ease;
    }
    .category-card:hover .cat-image {
        transform: scale(1.08);
    }
    
    .category-card .cat-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 1.5rem 1.25rem;
        background: linear-gradient(0deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0) 100%);
        color: var(--white);
        text-align: left;
    }
    .category-card .cat-overlay .cat-name {
        font-size: 1.2rem;
        font-weight: 700;
        display: block;
        text-shadow: 0 2px 8px rgba(0,0,0,0.3);
        line-height: 1.2;
    }
    .category-card .cat-overlay .cat-count {
        font-size: 0.8rem;
        opacity: 0.85;
        display: block;
        text-shadow: 0 1px 4px rgba(0,0,0,0.3);
        margin-top: 0.1rem;
    }
    /* Subcategory indicator */
    .category-card .cat-overlay .cat-sub {
        font-size: 0.65rem;
        opacity: 0.6;
        display: block;
        margin-top: 0.2rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .category-card .cat-icon {
        position: absolute;
        top: 1rem;
        right: 1rem;
        width: 40px;
        height: 40px;
        background: rgba(255,255,255,0.92);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        color: var(--primary);
        box-shadow: var(--shadow-sm);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        transition: var(--transition);
    }
    .category-card:hover .cat-icon {
        background: var(--primary);
        color: var(--white);
    }
    
    .category-card .view-all {
        position: absolute;
        inset: 0;
        background: rgba(11,110,61,0.06);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border: 2px dashed var(--border);
        border-radius: var(--radius);
        color: var(--primary);
        font-weight: 600;
        transition: var(--transition);
    }
    .category-card .view-all i {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
        opacity: 0.6;
    }
    .category-card:hover .view-all {
        background: rgba(11,110,61,0.1);
        border-color: var(--primary);
    }
    
    @media (max-width: 992px) {
        .categories-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
    }
    @media (max-width: 768px) {
        .categories-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .category-card .cat-overlay {
            padding: 1rem 0.8rem;
        }
        .category-card .cat-overlay .cat-name {
            font-size: 0.95rem;
        }
        .category-card .cat-overlay .cat-count {
            font-size: 0.7rem;
        }
        .category-card .cat-icon {
            width: 32px;
            height: 32px;
            font-size: 0.85rem;
            top: 0.6rem;
            right: 0.6rem;
        }
    }
    @media (max-width: 480px) {
        .categories-grid {
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem;
        }
        .category-card .cat-overlay .cat-name {
            font-size: 0.85rem;
        }
        .category-card .cat-overlay .cat-count {
            font-size: 0.6rem;
        }
        .category-card .cat-icon {
            width: 26px;
            height: 26px;
            font-size: 0.7rem;
            top: 0.4rem;
            right: 0.4rem;
        }
    }

    /* ─── EXPRESS BANNER ──────────────────────────────────────── */
    .express-banner {
        margin: 2.5rem 0;
        background: linear-gradient(135deg, #0B6E3D 0%, #1FAF5B 50%, #0D8B4E 100%);
        border-radius: var(--radius-lg);
        padding: 2.5rem 3rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1.5rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(11,110,61,0.25);
    }
    .express-banner::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: 
            radial-gradient(circle at 20% 50%, rgba(255,255,255,0.05) 0%, transparent 50%),
            radial-gradient(circle at 80% 50%, rgba(255,255,255,0.03) 0%, transparent 50%);
        pointer-events: none;
    }
    .express-banner .banner-icon {
        font-size: 2.8rem;
        color: var(--gold);
        flex-shrink: 0;
        animation: floatBanner 3s ease-in-out infinite;
    }
    @keyframes floatBanner {
        0%, 100% { transform: translateY(0) rotate(0deg); }
        50% { transform: translateY(-10px) rotate(-3deg); }
    }
    .express-banner .content {
        flex: 1;
        color: var(--white);
    }
    .express-banner .content h3 {
        font-family: var(--font-display);
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 0.2rem;
    }
    .express-banner .content p {
        font-size: 0.9rem;
        opacity: 0.85;
        margin: 0;
    }
    .express-banner .btn-express {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.7rem 1.8rem;
        background: var(--gold);
        color: var(--dark);
        border-radius: var(--radius-full);
        font-weight: 700;
        text-decoration: none;
        transition: var(--transition);
        font-size: 0.85rem;
        flex-shrink: 0;
        box-shadow: 0 4px 20px rgba(201,162,39,0.3);
    }
    .express-banner .btn-express:hover {
        transform: translateY(-3px) scale(1.02);
        box-shadow: 0 8px 40px rgba(201,162,39,0.4);
    }

    @media (max-width: 768px) {
        .express-banner {
            padding: 1.5rem;
            flex-direction: column;
            text-align: center;
        }
        .express-banner .banner-icon {
            font-size: 2rem;
        }
        .express-banner .content h3 {
            font-size: 1.1rem;
        }
    }

    /* ─── PRODUCT GRID ────────────────────────────────────────── */
    .products-section {
        padding: 3rem 0 4rem;
    }
    
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 1.5rem;
    }
    
    /* ─── PRODUCT CARD (with variants) ────────────────────────── */
    .product-card {
        background: var(--white);
        border-radius: var(--radius);
        overflow: hidden;
        border: 1px solid var(--border);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        position: relative;
        opacity: 0;
        transform: translateY(30px);
    }
    .product-card.visible {
        opacity: 1;
        transform: translateY(0);
    }
    .product-card:hover {
        transform: translateY(-10px);
        box-shadow: var(--shadow-2xl);
        border-color: var(--gold);
    }
    
    .product-card .image-wrap {
        position: relative;
        padding-top: 100%;
        background: #F5F5F5;
        overflow: hidden;
        cursor: pointer;
        flex-shrink: 0;
    }
    .product-card .image-wrap img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.7s ease;
    }
    .product-card:hover .image-wrap img {
        transform: scale(1.06);
    }
    .product-card .image-wrap .placeholder {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
        color: #ddd;
    }
    
    .product-card .badges {
        position: absolute;
        top: 10px;
        left: 10px;
        display: flex;
        flex-direction: column;
        gap: 4px;
        z-index: 2;
        pointer-events: none;
    }
    .product-card .badge {
        padding: 0.2rem 0.7rem;
        border-radius: 20px;
        font-size: 0.6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        width: fit-content;
    }
    .badge-sale { background: #DC2626; color: #fff; }
    .badge-new { background: var(--dark); color: #fff; }
    .badge-express { background: var(--gold); color: var(--dark); display: flex; align-items: center; gap: 3px; }
    .badge-featured { background: var(--primary); color: #fff; }
    
    .product-card .wishlist-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(255,255,255,0.92);
        border: 1px solid var(--border);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        color: var(--text-muted);
        transition: var(--transition);
        z-index: 3;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
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
        padding: 0.9rem 1rem 1.1rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .product-card .vendor {
        font-size: 0.6rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-muted);
        font-weight: 600;
        margin-bottom: 0.1rem;
    }
    .product-card .name {
        font-size: 0.9rem;
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
    .product-card .name:hover {
        color: var(--primary);
    }
    
    .product-card .rating {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.7rem;
        margin-bottom: 0.4rem;
    }
    .product-card .stars {
        color: #F59E0B;
        display: flex;
        gap: 1px;
    }
    .product-card .stars .empty {
        color: #E5E0D8;
    }
    .product-card .review-count {
        color: var(--text-muted);
    }
    
    .product-card .price-row {
        display: flex;
        align-items: baseline;
        gap: 0.4rem;
        flex-wrap: wrap;
        margin-bottom: 0.5rem;
        margin-top: auto;
    }
    .product-card .current {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--primary);
    }
    .product-card .original {
        font-size: 0.75rem;
        color: #B0B0B0;
        text-decoration: line-through;
    }
    .product-card .discount-pct {
        font-size: 0.65rem;
        font-weight: 700;
        color: #DC2626;
        background: #FEE2E2;
        padding: 0.05rem 0.4rem;
        border-radius: 4px;
    }
    
    /* Variants on product card */
    .product-card .variants {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
        margin-bottom: 0.5rem;
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
        padding: 0.7rem;
        background: var(--primary);
        color: var(--white);
        border: none;
        border-radius: var(--radius-full);
        font-family: var(--font-primary);
        font-size: 0.8rem;
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
        box-shadow: 0 4px 20px rgba(201,162,39,0.3);
    }
    .product-card .add-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .product-card .add-btn.added {
        background: var(--gold);
        color: var(--dark);
    }

    @media (max-width: 768px) {
        .products-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .product-card .image-wrap {
            padding-top: 80%;
        }
        .product-card .info {
            padding: 0.6rem 0.7rem 0.9rem;
        }
        .product-card .name {
            font-size: 0.78rem;
        }
        .product-card .current {
            font-size: 0.95rem;
        }
        .product-card .add-btn {
            font-size: 0.7rem;
            padding: 0.5rem;
        }
        .product-card .variant-btn {
            font-size: 0.5rem;
            padding: 0.05rem 0.3rem;
        }
    }
    
    @media (max-width: 480px) {
        .products-grid {
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem;
        }
        .product-card .image-wrap {
            padding-top: 75%;
        }
        .product-card .name {
            font-size: 0.72rem;
        }
        .product-card .current {
            font-size: 0.85rem;
        }
        .product-card .add-btn {
            font-size: 0.6rem;
            padding: 0.4rem;
        }
        .product-card .info {
            padding: 0.5rem 0.6rem 0.7rem;
        }
    }

    /* ─── WHY CHOOSE US ───────────────────────────────────────── */
    .why-section {
        padding: 4rem 0;
        background: var(--white);
        border-top: 1px solid var(--border);
        border-bottom: 1px solid var(--border);
    }
    
    .why-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 2rem;
    }
    .why-card {
        text-align: center;
        padding: 1.5rem;
        border-radius: var(--radius);
        transition: var(--transition);
        background: var(--off-white);
        border: 1px solid var(--border);
        opacity: 0;
        transform: translateY(30px);
    }
    .why-card.visible {
        opacity: 1;
        transform: translateY(0);
    }
    .why-card:hover {
        transform: translateY(-6px);
        border-color: var(--primary);
        box-shadow: var(--shadow-lg);
    }
    .why-card .icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 0.75rem;
        background: rgba(11, 110, 61, 0.08);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        color: var(--primary);
        transition: var(--transition);
    }
    .why-card:hover .icon {
        background: var(--primary);
        color: var(--white);
        transform: scale(1.05);
    }
    .why-card h4 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 0.2rem;
    }
    .why-card p {
        font-size: 0.8rem;
        color: var(--text-secondary);
        line-height: 1.5;
        margin: 0;
    }

    @media (max-width: 768px) {
        .why-grid {
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        .why-card {
            padding: 1rem;
        }
        .why-card .icon {
            width: 44px;
            height: 44px;
            font-size: 1rem;
        }
        .why-card h4 {
            font-size: 0.82rem;
        }
        .why-card p {
            font-size: 0.72rem;
        }
    }
    
    @media (max-width: 480px) {
        .why-grid {
            grid-template-columns: 1fr;
            gap: 0.6rem;
        }
    }

    /* ─── NEWSLETTER ──────────────────────────────────────────── */
    .newsletter-section {
        padding: 4rem 0;
        background: linear-gradient(135deg, #060E0A 0%, #0A1F15 100%);
        border-radius: var(--radius-lg);
        margin: 2rem auto;
        max-width: 1200px;
        text-align: center;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    }
    .newsletter-section::before {
        content: '';
        position: absolute;
        inset: 0;
        background: 
            radial-gradient(ellipse 60% 40% at 50% 100%, rgba(11,110,61,0.15) 0%, transparent 60%),
            radial-gradient(ellipse 30% 30% at 85% 10%, rgba(201,162,39,0.05) 0%, transparent 50%);
        pointer-events: none;
    }
    .newsletter-section .container {
        position: relative;
        z-index: 1;
    }
    .newsletter-section h2 {
        font-family: var(--font-display);
        font-size: clamp(1.5rem, 3vw, 2.2rem);
        color: var(--white);
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    .newsletter-section p {
        color: rgba(255,255,255,0.6);
        font-size: 0.95rem;
        margin-bottom: 1.5rem;
        max-width: 500px;
        margin-left: auto;
        margin-right: auto;
    }
    .newsletter-form {
        display: flex;
        max-width: 480px;
        margin: 0 auto;
        gap: 0.5rem;
    }
    .newsletter-form input {
        flex: 1;
        padding: 0.8rem 1.2rem;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: var(--radius-full);
        color: var(--white);
        font-family: var(--font-primary);
        font-size: 0.9rem;
        outline: none;
        transition: var(--transition);
    }
    .newsletter-form input::placeholder {
        color: rgba(255,255,255,0.3);
    }
    .newsletter-form input:focus {
        border-color: var(--gold);
        background: rgba(255,255,255,0.1);
        box-shadow: 0 0 0 3px rgba(201,162,39,0.1);
    }
    .newsletter-form button {
        padding: 0.8rem 1.8rem;
        background: var(--gold);
        color: var(--dark);
        border: none;
        border-radius: var(--radius-full);
        font-family: var(--font-primary);
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
        white-space: nowrap;
    }
    .newsletter-form button:hover {
        transform: scale(1.03);
        box-shadow: 0 8px 30px rgba(201,162,39,0.3);
    }
    .newsletter-success {
        color: #22C55E;
        font-size: 0.9rem;
        margin-top: 0.75rem;
        font-weight: 500;
        animation: fadeInUp 0.4s ease;
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 768px) {
        .newsletter-section {
            padding: 2.5rem 1rem;
            margin: 1rem;
            border-radius: var(--radius);
        }
        .newsletter-form {
            flex-direction: column;
            max-width: 100%;
        }
        .newsletter-form button {
            width: 100%;
            justify-content: center;
        }
    }

    /* ─── TOAST ────────────────────────────────────────────────── */
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
        padding: 0.85rem 1.2rem;
        border-radius: var(--radius-sm);
        box-shadow: var(--shadow-2xl);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        font-size: 0.85rem;
        pointer-events: auto;
        animation: toastIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        border-left: 4px solid var(--primary);
    }
    .toast.error {
        border-left-color: #DC2626;
    }
    .toast.info {
        border-left-color: var(--gold);
    }
    @keyframes toastIn {
        from { transform: translateX(120%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    </style>
</head>
<body>

<!-- ─── TOP BAR ────────────────────────────────────────────────── -->
<div class="top-bar">
    <div class="top-bar-inner">
        <div class="top-bar-left">
            <span><i class="fas fa-phone-alt"></i> <?php echo SITE_PHONE; ?></span>
            <span><i class="fab fa-whatsapp"></i> <?php echo SITE_WHATSAPP; ?></span>
            <span><i class="fas fa-clock"></i> Mon-Fri 8AM-6PM</span>
        </div>
        <div class="top-bar-right">
            <a href="track-order.php"><i class="fas fa-box-tissue"></i> Track Order</a>
            <a href="express.php" class="express-link"><i class="fas fa-bolt"></i> Express Delivery</a>
            <span><i class="fas fa-map-marker-alt"></i> 🇬🇭 Ghana</span>
        </div>
    </div>
</div>

<!-- ─── DYNAMIC HERO SLIDESHOW ─────────────────────────────────── -->
<section class="hero-slideshow" id="heroSlideshow">
    <?php 
    $slideIndex = 0;
    foreach ($hero_slides as $slide): 
        $slideType = $slide['slide_type'] ?? 'product';
        $isActive = $slideIndex === 0;
        $imageUrl = '';
        $title = '';
        $subtitle = '';
        $price = '';
        $originalPrice = '';
        $discountPct = '';
        $linkUrl = '';
        $badgeText = '';
        
        if ($slideType === 'product') {
            $imageUrl = getProductImageUrl($slide['image'] ?? '');
            $title = $slide['name'] ?? '';
            $subtitle = $slide['short_description'] ?? 'Quality product at an amazing price';
            $price = formatPrice($slide['price'] ?? 0);
            $originalPrice = isset($slide['original_price']) && $slide['original_price'] > 0 ? formatPrice($slide['original_price']) : '';
            $discountPct = isset($slide['discount_pct']) && $slide['discount_pct'] > 0 ? '-' . $slide['discount_pct'] . '%' : '';
            $linkUrl = 'product-detail.php?slug=' . urlencode($slide['slug'] ?? '');
            $badgeText = '🛍️ Featured Product';
            
            if (empty($imageUrl)) {
                $imageUrl = 'https://images.unsplash.com/photo-1472851294608-062f824d29cc?w=1200&h=800&fit=crop';
            }
        } elseif ($slideType === 'category') {
            $imageUrl = getCategoryImage($slide['name'] ?? '', $slide['image'] ?? '');
            $title = $slide['name'] ?? '';
            $subtitle = ($slide['product_count'] ?? 0) . ' products available';
            $linkUrl = 'shop.php?category=' . urlencode($slide['slug'] ?? '');
            $badgeText = '📂 Shop Category';
        } elseif ($slideType === 'discount') {
            $imageUrl = getProductImageUrl($slide['image'] ?? '');
            $title = $slide['name'] ?? '';
            $subtitle = 'Limited time offer - grab yours now!';
            $price = formatPrice($slide['price'] ?? 0);
            $originalPrice = isset($slide['original_price']) && $slide['original_price'] > 0 ? formatPrice($slide['original_price']) : '';
            $discountPct = isset($slide['discount_pct']) && $slide['discount_pct'] > 0 ? '-' . $slide['discount_pct'] . '%' : '';
            $linkUrl = 'product-detail.php?slug=' . urlencode($slide['slug'] ?? '');
            $badgeText = '🔥 Flash Sale';
            
            if (empty($imageUrl)) {
                $imageUrl = 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=1200&h=800&fit=crop';
            }
        }
        
        if (empty($imageUrl)) {
            $imageUrl = 'https://images.unsplash.com/photo-1472851294608-062f824d29cc?w=1200&h=800&fit=crop';
        }
    ?>
    <div class="hero-slide <?php echo $isActive ? 'active' : ''; ?>" data-index="<?php echo $slideIndex; ?>">
        <div class="hero-slide-bg" style="background-image: url('<?php echo htmlspecialchars($imageUrl); ?>');"></div>
        <div class="overlay"></div>
        <div class="hero-slide-content">
            <div class="hero-slide-text">
                <div class="badge">
                    <span class="dot"></span>
                    <span><?php echo $badgeText; ?></span>
                </div>
                <h2>
                    <?php 
                    $words = explode(' ', $title, 3);
                    if (count($words) >= 3) {
                        $lastWord = array_pop($words);
                        echo htmlspecialchars(implode(' ', $words)) . ' <span class="highlight">' . htmlspecialchars($lastWord) . '</span>';
                    } else {
                        echo htmlspecialchars($title);
                    }
                    ?>
                </h2>
                <p><?php echo htmlspecialchars($subtitle); ?></p>
                <?php if (!empty($price)): ?>
                <div class="price">
                    <?php echo $price; ?>
                    <?php if (!empty($originalPrice)): ?>
                        <span class="original"><?php echo $originalPrice; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($discountPct)): ?>
                        <span class="discount"><?php echo $discountPct; ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="actions">
                    <a href="<?php echo $linkUrl; ?>" class="btn-primary">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    <?php if ($slideType === 'product' || $slideType === 'discount'): ?>
                        <button class="btn-gold" onclick="addToCart(<?php echo (int)($slide['id'] ?? 0); ?>, <?php echo (int)($slide['pricing_tier_id'] ?? 0); ?>, this)">
                            <i class="fas fa-shopping-cart"></i> Add to Cart
                        </button>
                    <?php else: ?>
                        <a href="<?php echo $linkUrl; ?>" class="btn-gold">
                            <i class="fas fa-arrow-right"></i> Explore
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php 
    $slideIndex++;
    endforeach; 
    ?>
    
    <!-- Navigation Dots -->
    <div class="hero-dots" id="heroDots">
        <?php for ($i = 0; $i < count($hero_slides); $i++): ?>
            <button class="hero-dot <?php echo $i === 0 ? 'active' : ''; ?>" data-index="<?php echo $i; ?>" aria-label="Slide <?php echo $i + 1; ?>"></button>
        <?php endfor; ?>
    </div>
    
    <!-- Navigation Arrows -->
    <button class="hero-arrow prev" id="heroPrev" aria-label="Previous slide">
        <i class="fas fa-chevron-left"></i>
    </button>
    <button class="hero-arrow next" id="heroNext" aria-label="Next slide">
        <i class="fas fa-chevron-right"></i>
    </button>
</section>

<!-- ─── BIG CATEGORY GRID (ALL CATEGORIES) ────────────────────── -->
<section class="categories-section">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">
                <span class="line"></span>
                Shop by Category
                <span class="line"></span>
            </div>
            <h2 class="section-title">Browse <span class="highlight">Categories</span></h2>
            <p class="section-subtitle">Find exactly what you need from our curated categories</p>
        </div>
        
        <div class="categories-grid">
            <?php foreach ($categories as $cat): 
                $icon = getCategoryIcon($cat['name']);
                $productCount = isset($cat['product_count']) ? (int)$cat['product_count'] : 0;
                // Use category image from DB, fallback to Unsplash
                $imageUrl = getCategoryImage($cat['name'], $cat['image'] ?? '');
                $isSub = !empty($cat['parent_id']);
                $parentName = '';
                if ($isSub) {
                    // fetch parent name if needed
                    $parentQuery = $conn->prepare("SELECT name FROM categories WHERE id = ?");
                    $parentQuery->bind_param("i", $cat['parent_id']);
                    $parentQuery->execute();
                    $parentResult = $parentQuery->get_result();
                    if ($p = $parentResult->fetch_assoc()) {
                        $parentName = $p['name'];
                    }
                    $parentQuery->close();
                }
            ?>
            <a href="shop.php?category=<?php echo urlencode($cat['slug']); ?>" class="category-card">
                <img src="<?php echo htmlspecialchars($imageUrl); ?>" 
                     alt="<?php echo htmlspecialchars($cat['name']); ?>" 
                     class="cat-image"
                     loading="lazy"
                     onerror="this.src='https://images.unsplash.com/photo-1472851294608-062f824d29cc?w=600&h=400&fit=crop'">
                <div class="cat-overlay">
                    <span class="cat-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                    <span class="cat-count"><?php echo $productCount; ?> products</span>
                    <?php if ($isSub && !empty($parentName)): ?>
                        <span class="cat-sub">in <?php echo htmlspecialchars($parentName); ?></span>
                    <?php endif; ?>
                </div>
                <div class="cat-icon">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
            </a>
            <?php endforeach; ?>
            
            <a href="shop.php" class="category-card">
                <div class="view-all">
                    <i class="fas fa-arrow-right"></i>
                    <span>View All</span>
                    <span style="font-size:0.7rem;opacity:0.6;">See all categories</span>
                </div>
            </a>
        </div>
    </div>
</section>

<!-- ─── EXPRESS BANNER ────────────────────────────────────────── -->
<div class="container">
    <div class="express-banner">
        <div class="banner-icon">
            <i class="fas fa-rocket"></i>
        </div>
        <div class="content">
            <h3><i class="fas fa-bolt" style="color:var(--gold);"></i> Express Delivery</h3>
            <p>Get your orders within 24-48 hours in Accra • 2-3 days nationwide</p>
        </div>
        <a href="express.php" class="btn-express">
            <i class="fas fa-bolt"></i> Shop Express
            <i class="fas fa-arrow-right"></i>
        </a>
    </div>
</div>

<!-- ─── FEATURED PRODUCTS (with variants) ─────────────────────── -->
<section class="products-section">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">
                <span class="line"></span>
                Featured
                <span class="line"></span>
            </div>
            <h2 class="section-title">Handpicked <span class="highlight">Favorites</span></h2>
            <p class="section-subtitle">Our top picks for you — quality products at great prices</p>
        </div>
        
        <?php if (!empty($featured_products)): ?>
        <div class="products-grid" id="featuredGrid">
            <?php foreach ($featured_products as $index => $product):
                $prodId = (int)$product['id'];
                $name = htmlspecialchars($product['name']);
                $slug = htmlspecialchars($product['slug']);
                $vendor = htmlspecialchars($product['vendor_name']);
                $isExpress = (int)($product['is_express'] ?? 0);
                $rating = round((float)($product['avg_rating'] ?? 0), 1);
                $reviews = (int)($product['review_count'] ?? 0);
                $price = (float)($product['best_price'] ?? 0);
                $tierId = (int)($product['default_tier_id'] ?? 0);
                $imgUrl = getProductImageUrl($product['image'] ?? '');
                $variants = $product['variants'] ?? [];
                $hasVariants = !empty($variants);
                $showWas = false;
                $origPrice = $price;
                $discountPct = 0;
                if (!empty($product['pricing_tiers'])) {
                    foreach ($product['pricing_tiers'] as $tier) {
                        if ($tier['pricing_type'] === 'retail' && $tier['has_discount']) {
                            $showWas = true;
                            $origPrice = $tier['unit_price'];
                            $discountPct = $tier['discount_percent_display'];
                            break;
                        }
                    }
                }
            ?>
            <div class="product-card" id="product-<?php echo $prodId; ?>" data-delay="<?php echo $index * 80; ?>">
                <div class="image-wrap" onclick="window.location='product-detail.php?slug=<?php echo $slug; ?>'">
                    <?php if (!empty($imgUrl)): ?>
                        <img src="<?php echo htmlspecialchars($imgUrl); ?>" 
                             alt="<?php echo $name; ?>" 
                             loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="placeholder" style="display:none;"><i class="fas fa-box-open"></i></div>
                    <?php else: ?>
                        <div class="placeholder"><i class="fas fa-box-open"></i></div>
                    <?php endif; ?>
                    
                    <div class="badges">
                        <?php if ($showWas && $discountPct > 0): ?>
                            <span class="badge badge-sale">-<?php echo $discountPct; ?>%</span>
                        <?php endif; ?>
                        <?php if ($isExpress): ?>
                        <span class="badge badge-express"><i class="fas fa-bolt"></i> Express</span>
                        <?php endif; ?>
                        <?php if ($index < 2): ?>
                        <span class="badge badge-featured">Featured</span>
                        <?php endif; ?>
                    </div>
                    
                    <button class="wishlist-btn" onclick="event.stopPropagation(); toggleWishlist(<?php echo $prodId; ?>, this)" title="Wishlist">
                        <i class="far fa-heart"></i>
                    </button>
                </div>
                
                <div class="info">
                    <div class="vendor"><?php echo $vendor; ?></div>
                    <div class="name" onclick="window.location='product-detail.php?slug=<?php echo $slug; ?>'">
                        <?php echo $name; ?>
                    </div>
                    
                    <?php if ($reviews > 0): ?>
                    <div class="rating">
                        <span class="stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?php echo $i > $rating ? ' empty' : ''; ?>"></i>
                            <?php endfor; ?>
                        </span>
                        <span class="review-count">(<?php echo $reviews; ?>)</span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="price-row">
                        <span class="current" id="price-<?php echo $prodId; ?>"><?php echo formatPrice($price); ?></span>
                        <?php if ($showWas && $origPrice > $price): ?>
                            <span class="original" id="orig-<?php echo $prodId; ?>"><?php echo formatPrice($origPrice); ?></span>
                            <span class="discount-pct" id="disc-<?php echo $prodId; ?>">-<?php echo $discountPct; ?>%</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($hasVariants): ?>
                    <div class="variants" id="variants-<?php echo $prodId; ?>">
                        <?php 
                        // Group variants by name
                        $grouped = [];
                        foreach ($variants as $v) {
                            $grouped[$v['variant_name']][] = $v;
                        }
                        foreach ($grouped as $gName => $gVars): 
                        ?>
                        <span class="variant-group">
                            <span class="variant-label"><?php echo htmlspecialchars($gName); ?>:</span>
                            <?php foreach ($gVars as $var): 
                                $isOut = $var['available_stock'] <= 0;
                                $effPrice = $var['effective_price'] ?? 0;
                                $tierIdVar = (int)$var['pricing_tier_id'];
                            ?>
                                <button class="variant-btn <?php echo $isOut ? 'out-of-stock' : ''; ?>" 
                                        data-pid="<?php echo $prodId; ?>" 
                                        data-variant-id="<?php echo $var['id']; ?>"
                                        data-price="<?php echo $effPrice; ?>"
                                        data-tier-id="<?php echo $tierIdVar; ?>"
                                        onclick="event.stopPropagation(); selectVariant(<?php echo $prodId; ?>, this)"
                                        title="<?php echo $isOut ? 'Out of stock' : ''; ?>">
                                    <?php echo htmlspecialchars($var['variant_value']); ?>
                                </button>
                            <?php endforeach; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="cta-wrap" id="cta-<?php echo $prodId; ?>">
                        <button class="add-btn" id="addbtn-<?php echo $prodId; ?>" 
                                onclick="addToCart(<?php echo $prodId; ?>, <?php echo $tierId; ?>, this)">
                            <i class="fas fa-shopping-cart"></i> Add to Cart
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:3rem 1rem;background:var(--white);border-radius:var(--radius);border:1px solid var(--border);">
            <i class="fas fa-star" style="font-size:2.5rem;color:#ddd;margin-bottom:1rem;display:block;"></i>
            <p style="color:var(--text-muted);">No featured products yet. Check back soon!</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ─── WHY CHOOSE US ──────────────────────────────────────────── -->
<section class="why-section">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">
                <span class="line"></span>
                Why Choose Us
                <span class="line"></span>
            </div>
            <h2 class="section-title">The <span class="highlight">Bless Mall</span> Advantage</h2>
        </div>
        
        <div class="why-grid" id="whyGrid">
            <div class="why-card" data-delay="0">
                <div class="icon"><i class="fas fa-shield-alt"></i></div>
                <h4>Quality Guaranteed</h4>
                <p>All products are verified for quality and authenticity</p>
            </div>
            <div class="why-card" data-delay="100">
                <div class="icon"><i class="fas fa-truck-fast"></i></div>
                <h4>Fast Delivery</h4>
                <p>Express delivery within 24-48 hours in Accra</p>
            </div>
            <div class="why-card" data-delay="200">
                <div class="icon"><i class="fas fa-credit-card"></i></div>
                <h4>Secure Payments</h4>
                <p>Multiple payment options with SSL security</p>
            </div>
            <div class="why-card" data-delay="300">
                <div class="icon"><i class="fas fa-headset"></i></div>
                <h4>24/7 Support</h4>
                <p>Dedicated customer support ready to help you</p>
            </div>
        </div>
    </div>
</section>

<!-- ─── LATEST PRODUCTS (with variants) ────────────────────────── -->
<section class="products-section" style="padding-top:0;">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">
                <span class="line"></span>
                New Arrivals
                <span class="line"></span>
            </div>
            <h2 class="section-title">Latest <span class="highlight">Products</span></h2>
            <p class="section-subtitle">Fresh arrivals from top vendors across Ghana</p>
        </div>
        
        <?php if (!empty($latest_products)): ?>
        <div class="products-grid" id="latestGrid">
            <?php 
            $latestIndex = 0;
            foreach ($latest_products as $product):
                $prodId = (int)$product['id'];
                $name = htmlspecialchars($product['name']);
                $slug = htmlspecialchars($product['slug']);
                $vendor = htmlspecialchars($product['vendor_name']);
                $isExpress = (int)($product['is_express'] ?? 0);
                $rating = round((float)($product['avg_rating'] ?? 0), 1);
                $reviews = (int)($product['review_count'] ?? 0);
                $price = (float)($product['best_price'] ?? 0);
                $tierId = (int)($product['default_tier_id'] ?? 0);
                $imgUrl = getProductImageUrl($product['image'] ?? '');
                $variants = $product['variants'] ?? [];
                $hasVariants = !empty($variants);
                $isNew = $latestIndex < 2;
            ?>
            <div class="product-card" id="product-<?php echo $prodId; ?>" data-delay="<?php echo $latestIndex * 80; ?>">
                <div class="image-wrap" onclick="window.location='product-detail.php?slug=<?php echo $slug; ?>'">
                    <?php if (!empty($imgUrl)): ?>
                        <img src="<?php echo htmlspecialchars($imgUrl); ?>" 
                             alt="<?php echo $name; ?>" 
                             loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="placeholder" style="display:none;"><i class="fas fa-box-open"></i></div>
                    <?php else: ?>
                        <div class="placeholder"><i class="fas fa-box-open"></i></div>
                    <?php endif; ?>
                    
                    <div class="badges">
                        <?php if ($isNew): ?>
                        <span class="badge badge-new">New</span>
                        <?php endif; ?>
                        <?php if ($isExpress): ?>
                        <span class="badge badge-express"><i class="fas fa-bolt"></i> Express</span>
                        <?php endif; ?>
                    </div>
                    
                    <button class="wishlist-btn" onclick="event.stopPropagation(); toggleWishlist(<?php echo $prodId; ?>, this)" title="Wishlist">
                        <i class="far fa-heart"></i>
                    </button>
                </div>
                
                <div class="info">
                    <div class="vendor"><?php echo $vendor; ?></div>
                    <div class="name" onclick="window.location='product-detail.php?slug=<?php echo $slug; ?>'">
                        <?php echo $name; ?>
                    </div>
                    
                    <?php if ($reviews > 0): ?>
                    <div class="rating">
                        <span class="stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?php echo $i > $rating ? ' empty' : ''; ?>"></i>
                            <?php endfor; ?>
                        </span>
                        <span class="review-count">(<?php echo $reviews; ?>)</span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="price-row">
                        <span class="current" id="price-<?php echo $prodId; ?>"><?php echo formatPrice($price); ?></span>
                    </div>
                    
                    <?php if ($hasVariants): ?>
                    <div class="variants" id="variants-<?php echo $prodId; ?>">
                        <?php 
                        $grouped = [];
                        foreach ($variants as $v) {
                            $grouped[$v['variant_name']][] = $v;
                        }
                        foreach ($grouped as $gName => $gVars): 
                        ?>
                        <span class="variant-group">
                            <span class="variant-label"><?php echo htmlspecialchars($gName); ?>:</span>
                            <?php foreach ($gVars as $var): 
                                $isOut = $var['available_stock'] <= 0;
                                $effPrice = $var['effective_price'] ?? 0;
                                $tierIdVar = (int)$var['pricing_tier_id'];
                            ?>
                                <button class="variant-btn <?php echo $isOut ? 'out-of-stock' : ''; ?>" 
                                        data-pid="<?php echo $prodId; ?>" 
                                        data-variant-id="<?php echo $var['id']; ?>"
                                        data-price="<?php echo $effPrice; ?>"
                                        data-tier-id="<?php echo $tierIdVar; ?>"
                                        onclick="event.stopPropagation(); selectVariant(<?php echo $prodId; ?>, this)"
                                        title="<?php echo $isOut ? 'Out of stock' : ''; ?>">
                                    <?php echo htmlspecialchars($var['variant_value']); ?>
                                </button>
                            <?php endforeach; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="cta-wrap" id="cta-<?php echo $prodId; ?>">
                        <button class="add-btn" id="addbtn-<?php echo $prodId; ?>" 
                                onclick="addToCart(<?php echo $prodId; ?>, <?php echo $tierId; ?>, this)">
                            <i class="fas fa-shopping-cart"></i> Add to Cart
                        </button>
                    </div>
                </div>
            </div>
            <?php 
            $latestIndex++;
            endforeach; 
            ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:3rem 1rem;background:var(--white);border-radius:var(--radius);border:1px solid var(--border);">
            <i class="fas fa-box-open" style="font-size:2.5rem;color:#ddd;margin-bottom:1rem;display:block;"></i>
            <p style="color:var(--text-muted);">No products available yet. Check back soon!</p>
        </div>
        <?php endif; ?>
        
        <div style="text-align:center;margin-top:2.5rem;">
            <a href="shop.php" class="btn-outline">
                <i class="fas fa-store"></i> View All Products
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<!-- ─── NEWSLETTER ────────────────────────────────────────────── -->
<section class="newsletter-section" id="newsletter">
    <div class="container">
        <h2>Stay in the <span style="color:var(--gold);">Loop</span></h2>
        <p>Subscribe to get special offers, new arrivals, and exclusive deals</p>
        <form class="newsletter-form" id="newsletterForm" onsubmit="return handleNewsletter(event)">
            <input type="email" id="newsletterEmail" placeholder="Enter your email address" required autocomplete="email">
            <button type="submit"><i class="fas fa-paper-plane"></i> Subscribe</button>
        </form>
        <div id="newsletterMessage"></div>
        <p style="font-size:0.7rem;color:rgba(255,255,255,0.3);margin-top:1rem;">
            No spam. Unsubscribe anytime.
        </p>
    </div>
</section>

<!-- ─── TOAST CONTAINER ────────────────────────────────────────── -->
<div class="toast-container" id="toastContainer"></div>

<!-- ─── SCRIPTS ────────────────────────────────────────────────── -->
<script>
// ─── HERO SLIDESHOW ─────────────────────────────────────────────
(function() {
    const slides = document.querySelectorAll('.hero-slide');
    const dots = document.querySelectorAll('.hero-dot');
    const prevBtn = document.getElementById('heroPrev');
    const nextBtn = document.getElementById('heroNext');
    let currentSlide = 0;
    let totalSlides = slides.length;
    let slideInterval;
    
    function goToSlide(index) {
        if (index < 0) index = totalSlides - 1;
        if (index >= totalSlides) index = 0;
        
        slides.forEach((slide, i) => {
            slide.classList.toggle('active', i === index);
        });
        
        dots.forEach((dot, i) => {
            dot.classList.toggle('active', i === index);
        });
        
        currentSlide = index;
    }
    
    function nextSlide() {
        goToSlide(currentSlide + 1);
    }
    
    function prevSlide() {
        goToSlide(currentSlide - 1);
    }
    
    function startSlideshow() {
        stopSlideshow();
        slideInterval = setInterval(nextSlide, 6000);
    }
    
    function stopSlideshow() {
        if (slideInterval) {
            clearInterval(slideInterval);
            slideInterval = null;
        }
    }
    
    // Event listeners
    if (prevBtn) prevBtn.addEventListener('click', function() { prevSlide(); startSlideshow(); });
    if (nextBtn) nextBtn.addEventListener('click', function() { nextSlide(); startSlideshow(); });
    
    dots.forEach((dot) => {
        dot.addEventListener('click', function() {
            const index = parseInt(this.dataset.index);
            goToSlide(index);
            startSlideshow();
        });
    });
    
    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') { prevSlide(); startSlideshow(); }
        if (e.key === 'ArrowRight') { nextSlide(); startSlideshow(); }
    });
    
    // Pause on hover
    const heroSection = document.getElementById('heroSlideshow');
    if (heroSection) {
        heroSection.addEventListener('mouseenter', stopSlideshow);
        heroSection.addEventListener('mouseleave', startSlideshow);
        heroSection.addEventListener('touchstart', stopSlideshow);
        heroSection.addEventListener('touchend', startSlideshow);
    }
    
    startSlideshow();
})();

// ─── TOAST ──────────────────────────────────────────────────────
function showToast(message, type) {
    type = type || 'success';
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'toast' + (type === 'error' ? ' error' : '') + (type === 'info' ? ' info' : '');
    const icons = { success: '✅', error: '❌', info: 'ℹ️' };
    toast.innerHTML = (icons[type] || '✅') + ' ' + message;
    container.appendChild(toast);
    setTimeout(() => { 
        toast.style.opacity = '0'; 
        toast.style.transform = 'translateX(120%)'; 
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300); 
    }, 4000);
}

// ─── VARIANT SELECTION ──────────────────────────────────────────
function selectVariant(productId, btn) {
    const group = btn.closest('.variant-group');
    const allBtns = group.querySelectorAll('.variant-btn');
    allBtns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Update price
    const price = parseFloat(btn.dataset.price) || 0;
    const priceElement = document.getElementById('price-' + productId);
    if (priceElement) {
        priceElement.textContent = 'GH₵ ' + price.toFixed(2);
    }
    
    // Update add-to-cart button with new tier_id
    const addBtn = document.getElementById('addbtn-' + productId);
    if (addBtn) {
        const tierId = parseInt(btn.dataset.tierId) || 0;
        if (tierId > 0) {
            addBtn.dataset.tierId = tierId;
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
            addBtn.innerHTML = '<i class="fas fa-shopping-cart"></i> Add to Cart';
        }
    }
}

// ─── ADD TO CART (supports variant tier) ──────────────────────
function addToCart(productId, tierId, btn) {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    btn.disabled = true;

    const data = new URLSearchParams();
    data.append('action', 'add_to_cart');
    data.append('product_id', productId);
    data.append('pricing_tier_id', tierId || 0);
    data.append('quantity', 1);

    fetch(window.location.href, {
        method: 'POST',
        headers: { 
            'X-Requested-With': 'XMLHttpRequest', 
            'Content-Type': 'application/x-www-form-urlencoded' 
        },
        body: data.toString()
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            const badge = document.getElementById('cartCount');
            if (badge && d.cart_count !== undefined) {
                badge.textContent = d.cart_count;
                badge.style.display = d.cart_count > 0 ? 'inline-block' : 'none';
            }
            btn.innerHTML = '<i class="fas fa-check"></i> Added!';
            btn.classList.add('added');
            showToast(d.message || 'Added to cart!');
            setTimeout(() => { 
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
    .catch(() => { 
        showToast('Connection error', 'error'); 
        btn.innerHTML = orig; 
        btn.disabled = false; 
    });
}

// ─── WISHLIST ──────────────────────────────────────────────────
function toggleWishlist(productId, btn) {
    const icon = btn.querySelector('i');
    const data = new URLSearchParams();
    data.append('action', 'toggle_wishlist');
    data.append('product_id', productId);

    fetch(window.location.href, {
        method: 'POST',
        headers: { 
            'X-Requested-With': 'XMLHttpRequest', 
            'Content-Type': 'application/x-www-form-urlencoded' 
        },
        body: data.toString()
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            if (d.action === 'added') {
                icon.className = 'fas fa-heart';
                btn.classList.add('liked');
                showToast('Added to wishlist! ❤️');
            } else {
                icon.className = 'far fa-heart';
                btn.classList.remove('liked');
                showToast('Removed from wishlist');
            }
        } else if (d.redirect) {
            window.location.href = d.redirect;
        } else {
            showToast(d.message || 'Error', 'error');
        }
    })
    .catch(() => showToast('Connection error', 'error'));
}

// ─── NEWSLETTER ────────────────────────────────────────────────
function handleNewsletter(e) {
    e.preventDefault();
    const input = document.getElementById('newsletterEmail');
    const email = input.value.trim();
    if (!email) return false;
    
    const data = new URLSearchParams();
    data.append('action', 'subscribe');
    data.append('email', email);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: data.toString()
    })
    .then(r => r.json())
    .then(d => {
        const msg = document.getElementById('newsletterMessage');
        if (d.success) {
            msg.className = 'newsletter-success';
            msg.textContent = d.message || '🎉 Thank you for subscribing!';
            input.value = '';
            setTimeout(() => { msg.textContent = ''; }, 5000);
        } else {
            showToast(d.message || 'Error', 'error');
        }
    })
    .catch(() => showToast('Connection error', 'error'));
    return false;
}

// ─── SCROLL ANIMATIONS ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const productObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const delay = parseInt(entry.target.dataset.delay) || 0;
                setTimeout(() => {
                    entry.target.classList.add('visible');
                }, delay);
                productObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '50px' });
    
    document.querySelectorAll('.product-card').forEach(el => {
        productObserver.observe(el);
    });
    
    const whyObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const delay = parseInt(entry.target.dataset.delay) || 0;
                setTimeout(() => {
                    entry.target.classList.add('visible');
                }, delay);
                whyObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    
    document.querySelectorAll('.why-card').forEach(el => {
        whyObserver.observe(el);
    });
});

console.log('%c🛍️ Bless Mall %cv21 - ALL Categories (no filter)',
    'font-size:1.4em;font-weight:bold;color:#0B6E3D;',
    'font-size:1em;color:#C9A227;');
console.log('%c📂 Every category (parent & child) is now shown', 'font-style:italic;color:#4A665C;');
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>