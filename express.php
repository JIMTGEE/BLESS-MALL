<?php
/**
 * express.php – Bless Mall Express Delivery
 * Fast Delivery Shopping Experience for Ghana
 * 
 * Quality Chinese Products | 24-48hr Express Delivery
 * "Need It Fast? We've Got You Covered"
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP: Load core system files
// ------------------------------------------------------------
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// ------------------------------------------------------------
// 2. DATABASE CONNECTION & DATA FETCHING
// ------------------------------------------------------------
$conn = getConnection();

// ----- Get current page for pagination -----
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// ----- Get filters -----
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$priceMin = isset($_GET['price_min']) ? (float)$_GET['price_min'] : 0;
$priceMax = isset($_GET['price_max']) ? (float)$_GET['price_max'] : 0;
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// ----- Build WHERE conditions -----
$whereConditions = ["p.is_active = 1", "v.is_express = 1", "v.status = 'active'"];

if ($categoryFilter > 0) {
    $whereConditions[] = "p.category_id = " . (int)$categoryFilter;
}

if (!empty($search)) {
    $searchEscaped = $conn->real_escape_string($search);
    $whereConditions[] = "(p.name LIKE '%$searchEscaped%' OR p.short_description LIKE '%$searchEscaped%' OR p.brand LIKE '%$searchEscaped%')";
}

$whereClause = implode(' AND ', $whereConditions);

// Price filtering
$priceWhere = '';
if ($priceMin > 0 || $priceMax > 0) {
    $priceConditions = [];
    if ($priceMin > 0) {
        $priceConditions[] = "ppt.unit_price >= $priceMin";
    }
    if ($priceMax > 0) {
        $priceConditions[] = "ppt.unit_price <= $priceMax";
    }
    $priceWhere = 'AND (' . implode(' AND ', $priceConditions) . ')';
}

// ----- Sorting -----
$orderClause = "ORDER BY p.created_at DESC";
switch ($sortBy) {
    case 'price_low':
        $orderClause = "ORDER BY ppt.unit_price ASC";
        break;
    case 'price_high':
        $orderClause = "ORDER BY ppt.unit_price DESC";
        break;
    case 'rating':
        $orderClause = "ORDER BY avg_rating DESC";
        break;
    case 'popular':
        $orderClause = "ORDER BY review_count DESC";
        break;
    case 'newest':
    default:
        $orderClause = "ORDER BY p.created_at DESC";
        break;
}

// ----- Count total express products -----
$countQuery = "SELECT COUNT(DISTINCT p.id) AS total
               FROM products p
               INNER JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                   AND ppt.pricing_type = 'retail' AND ppt.is_active = 1
               LEFT JOIN vendors v ON p.vendor_id = v.id
               WHERE $whereClause $priceWhere";
$countRes = $conn->query($countQuery);
$totalProducts = 0;
if ($countRes && $countRes->num_rows > 0) {
    $countRow = $countRes->fetch_assoc();
    $totalProducts = (int)$countRow['total'];
}
$totalPages = ceil($totalProducts / $perPage);

// ----- Fetch Express Products -----
$expressProducts = [];
$exprQuery = "SELECT p.id, p.name, p.slug, p.short_description, p.sku, p.brand,
               ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
               ppt.pricing_type AS tier_type, ppt.tier_name,
               (SELECT pi.image_path FROM product_images pi 
                WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS image_path,
               v.business_name AS vendor_name, v.is_express,
               COALESCE(AVG(r.rating), 0) AS avg_rating,
               COUNT(r.id) AS review_count,
               c.name AS category_name, c.slug AS category_slug
               FROM products p
               INNER JOIN product_pricing_tiers ppt ON p.id = ppt.product_id 
                   AND ppt.pricing_type = 'retail' AND ppt.is_active = 1
               LEFT JOIN vendors v ON p.vendor_id = v.id
               LEFT JOIN reviews r ON p.id = r.product_id
               LEFT JOIN categories c ON p.category_id = c.id
               WHERE $whereClause $priceWhere
               GROUP BY p.id, ppt.id
               $orderClause
               LIMIT $offset, $perPage";
$exprRes = $conn->query($exprQuery);
if ($exprRes && $exprRes->num_rows > 0) {
    while ($row = $exprRes->fetch_assoc()) {
        $expressProducts[] = $row;
    }
}

// ----- Categories with express products -----
$expressCategories = [];
$catQuery = "SELECT DISTINCT c.id, c.name, c.slug, COUNT(p.id) AS product_count
             FROM categories c
             INNER JOIN products p ON c.id = p.category_id AND p.is_active = 1
             INNER JOIN vendors v ON p.vendor_id = v.id AND v.is_express = 1 AND v.status = 'active'
             WHERE c.is_active = 1
             GROUP BY c.id
             ORDER BY c.name";
$catRes = $conn->query($catQuery);
if ($catRes && $catRes->num_rows > 0) {
    while ($row = $catRes->fetch_assoc()) {
        $expressCategories[] = $row;
    }
}

// ----- Express vendor count -----
$expressVendorCount = 0;
$statsQuery = "SELECT COUNT(DISTINCT v.id) AS vendor_count
               FROM vendors v
               INNER JOIN products p ON v.id = p.vendor_id AND p.is_active = 1
               WHERE v.is_express = 1 AND v.status = 'active'";
$statsRes = $conn->query($statsQuery);
if ($statsRes && $statsRes->num_rows > 0) {
    $statsRow = $statsRes->fetch_assoc();
    $expressVendorCount = (int)($statsRow['vendor_count'] ?? 0);
}

// ----- Cart Count -----
$cart_count = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += (int)($item['quantity'] ?? 0);
    }
}

// ----- Fallback if no products with pricing tiers -----
$allExpressProducts = [];
if (empty($expressProducts)) {
    $fallbackQuery = "SELECT p.id, p.name, p.slug, p.short_description, p.sku, p.brand,
                       NULL AS unit_price, NULL AS discount_price, NULL AS discount_percentage,
                       'retail' AS tier_type, NULL AS tier_name,
                       (SELECT pi.image_path FROM product_images pi 
                        WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS image_path,
                       v.business_name AS vendor_name, v.is_express,
                       COALESCE(AVG(r.rating), 0) AS avg_rating,
                       COUNT(r.id) AS review_count,
                       c.name AS category_name, c.slug AS category_slug
                       FROM products p
                       LEFT JOIN vendors v ON p.vendor_id = v.id
                       LEFT JOIN reviews r ON p.id = r.product_id
                       LEFT JOIN categories c ON p.category_id = c.id
                       WHERE $whereClause
                       GROUP BY p.id
                       ORDER BY p.created_at DESC
                       LIMIT 12";
    $fallbackRes = $conn->query($fallbackQuery);
    if ($fallbackRes && $fallbackRes->num_rows > 0) {
        while ($row = $fallbackRes->fetch_assoc()) {
            $allExpressProducts[] = $row;
        }
    }
}

$displayProducts = !empty($expressProducts) ? $expressProducts : $allExpressProducts;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Bless Mall Express - Fast 24-48hr delivery across Ghana. Shop quality Chinese products with lightning-fast express shipping.">
    <meta name="keywords" content="Express delivery Ghana, fast shopping Accra, quick delivery Ghana, Bless Mall express">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph -->
    <meta property="og:title" content="Bless Mall Express – Fast Delivery in Ghana | 24-48hr Shipping">
    <meta property="og:description" content="Need it fast? Shop express delivery products. Quality Chinese products delivered within 24-48 hours across Ghana.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>express.php">
    
    <title>Bless Mall Express – Fast Delivery Ghana | 24-48hr Express Shipping</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- AOS Animation -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>header.css">
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>footer.css">
    
    <style>
        :root {
            --primary-green: #0B6E3D;
            --primary-green-dark: #085229;
            --primary-green-light: #0D8B4E;
            --accent-green: #1FAF5B;
            --light-green: #E8F5EE;
            --lighter-green: #F4FAF7;
            --white: #FFFFFF;
            --off-white: #FAFBFA;
            --dark: #1A2E24;
            --dark-secondary: #2D3B35;
            --text-primary: #1A2E24;
            --text-secondary: #4A665C;
            --text-muted: #7C9A8E;
            --border-light: #E2EDE8;
            --border-medium: #C8DDD4;
            --shadow-xs: 0 1px 3px rgba(0,0,0,0.04);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
            --shadow-md: 0 8px 24px rgba(0,0,0,0.08);
            --shadow-lg: 0 16px 48px rgba(0,0,0,0.10);
            --shadow-xl: 0 24px 64px rgba(0,0,0,0.12);
            --radius-xs: 6px;
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-xl: 32px;
            --radius-full: 9999px;
            --transition-fast: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-primary);
            line-height: 1.7;
            overflow-x: hidden;
            background: var(--white);
            -webkit-font-smoothing: antialiased;
        }

        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: var(--light-green); }
        ::-webkit-scrollbar-thumb { background: var(--primary-green); border-radius: 10px; }
        ::selection { background: var(--primary-green); color: white; }

        /* ---------- Top Bar ---------- */
        .top-bar {
            background: var(--primary-green-dark);
            color: rgba(255,255,255,0.9);
            font-size: 0.8rem;
            padding: 8px 0;
            z-index: 1001;
            position: relative;
        }
        .top-bar-inner {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .top-bar-left { display: flex; align-items: center; gap: 20px; }
        .top-bar-left span { display: flex; align-items: center; gap: 6px; }
        .top-bar-left i { color: var(--accent-green); }
        .top-bar-right { display: flex; align-items: center; gap: 16px; }
        .top-bar-right a {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            transition: color 0.2s;
            font-weight: 500;
        }
        .top-bar-right a:hover { color: var(--accent-green); }

        /* ---------- Compact Hero Section ---------- */
        .express-hero {
            background: linear-gradient(135deg, #0B6E3D 0%, #0D8B4E 30%, #1FAF5B 70%, #0B6E3D 100%);
            padding: 1.5rem 2rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .express-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(255,255,255,0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.08) 0%, transparent 50%);
            z-index: 0;
        }
        .express-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0.08;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='white' fill-opacity='1'%3E%3Ccircle cx='30' cy='30' r='2'/%3E%3C/g%3E%3C/svg%3E");
            background-size: 40px 40px;
            z-index: 0;
        }
        .express-hero-content {
            position: relative;
            z-index: 1;
            max-width: 1000px;
            margin: 0 auto;
        }
        .express-badge-large {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: var(--radius-full);
            padding: 5px 16px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-bottom: 0.8rem;
            backdrop-filter: blur(10px);
            letter-spacing: 1px;
        }
        .express-hero-content h1 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 800;
            margin-bottom: 0.5rem;
            line-height: 1.2;
        }
        .express-hero-content h1 .highlight {
            color: #D4E8DC;
            position: relative;
            border-bottom: 2px solid rgba(255,255,255,0.5);
            display: inline-block;
        }
        .express-hero-content p {
            font-size: 0.85rem;
            opacity: 0.95;
            margin-bottom: 1rem;
            max-width: 550px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
        }

        /* Express Stats Row - Compact */
        .express-stats-row {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .express-stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.1);
            padding: 6px 16px;
            border-radius: var(--radius-full);
            backdrop-filter: blur(5px);
        }
        .express-stat-icon {
            width: 32px;
            height: 32px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        .express-stat-text {
            text-align: left;
        }
        .express-stat-text .stat-value {
            font-weight: 800;
            font-size: 1rem;
            line-height: 1.2;
        }
        .express-stat-text .stat-label {
            font-size: 0.65rem;
            opacity: 0.85;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ---------- Breadcrumb ---------- */
        .breadcrumb {
            max-width: 1300px;
            margin: 1rem auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .breadcrumb a {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 500;
        }
        .breadcrumb a:hover { color: var(--primary-green-dark); }
        .breadcrumb .current { color: var(--primary-green); font-weight: 600; }

        /* ---------- Main Layout ---------- */
        .express-layout {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 2rem 3rem;
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
        }

        /* ---------- Sidebar Filters ---------- */
        .sidebar-filters {
            position: sticky;
            top: 100px;
            align-self: start;
        }
        .filter-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            transition: var(--transition-fast);
        }
        .filter-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--border-medium);
        }
        .filter-card h4 {
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
            border-left: 3px solid var(--primary-green);
            padding-left: 12px;
        }
        .filter-card h4 i { color: var(--primary-green); font-size: 0.9rem; }
        
        .category-list {
            list-style: none;
            max-height: 320px;
            overflow-y: auto;
        }
        .category-list li { margin-bottom: 4px; }
        .category-list li a {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--text-secondary);
            font-size: 0.85rem;
            transition: var(--transition-fast);
        }
        .category-list li a:hover,
        .category-list li a.active {
            background: var(--lighter-green);
            color: var(--primary-green);
            font-weight: 600;
        }
        .category-count {
            background: var(--light-green);
            color: var(--primary-green);
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: var(--radius-full);
        }

        .price-input-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 1rem;
        }
        .price-input-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: var(--transition-fast);
            background: var(--white);
        }
        .price-input-group input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(11,110,61,0.1);
        }
        .price-input-group span { color: var(--text-muted); font-weight: 600; }

        .btn-filter-apply {
            width: 100%;
            padding: 10px;
            background: var(--primary-green);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 700;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: var(--transition);
        }
        .btn-filter-apply:hover {
            background: var(--primary-green-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        .btn-filter-reset {
            display: block;
            text-align: center;
            margin-top: 10px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: color 0.2s;
        }
        .btn-filter-reset:hover { color: var(--primary-green); }

        /* ---------- Products Area ---------- */
        .products-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border-light);
        }
        .products-count {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .products-count strong { color: var(--dark); }
        
        .sort-select {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sort-select label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
            white-space: nowrap;
        }
        .sort-select select {
            padding: 8px 14px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif;
            font-size: 0.8rem;
            cursor: pointer;
            background: white;
            color: var(--text-primary);
            transition: var(--transition-fast);
        }
        .sort-select select:focus {
            outline: none;
            border-color: var(--primary-green);
        }

        /* Product Cards Grid */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .product-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            border: 1px solid var(--border-light);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .product-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary-green-light);
        }
        .product-image-wrap {
            height: 240px;
            background: var(--off-white);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .product-image-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.7s ease;
        }
        .product-card:hover .product-image-wrap img { transform: scale(1.08); }
        .product-image-placeholder {
            font-size: 3rem;
            color: var(--primary-green);
            opacity: 0.2;
        }
        
        /* Express Badge */
        .badge-express-card {
            position: absolute;
            top: 12px;
            left: 12px;
            background: var(--primary-green);
            color: white;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 4px 12px rgba(11,110,61,0.3);
            letter-spacing: 0.5px;
        }
        .badge-discount {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #E53935;
            color: white;
            font-size: 0.65rem;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: var(--radius-full);
            z-index: 2;
            box-shadow: 0 4px 12px rgba(229,57,53,0.3);
        }
        
        .product-body {
            padding: 1rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .product-vendor-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.4rem;
        }
        .product-vendor {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .delivery-time-badge {
            font-size: 0.6rem;
            background: var(--light-green);
            color: var(--primary-green);
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-weight: 700;
        }
        .product-body h4 {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            color: var(--dark);
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .product-body h4 a {
            text-decoration: none;
            color: inherit;
            transition: color 0.2s;
        }
        .product-body h4 a:hover { color: var(--primary-green); }
        
        .product-rating {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 0.5rem;
        }
        .stars {
            display: flex;
            gap: 2px;
            color: #F5B300;
            font-size: 0.7rem;
        }
        .stars .empty { color: #ddd; }
        .rating-text { font-size: 0.65rem; color: var(--text-muted); }
        
        .product-price-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-top: auto;
            margin-bottom: 0.75rem;
        }
        .current-price {
            font-weight: 800;
            font-size: 1.15rem;
            color: var(--primary-green);
        }
        .old-price {
            text-decoration: line-through;
            color: #bbb;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .btn-add-cart {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 0.7rem;
            background: var(--primary-green);
            color: white;
            border: none;
            border-radius: var(--radius-full);
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
            font-family: 'Inter', sans-serif;
        }
        .btn-add-cart:hover {
            background: var(--primary-green-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* ---------- Pagination ---------- */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 38px;
            padding: 0 12px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            transition: var(--transition-fast);
            border: 1px solid var(--border-light);
            color: var(--text-secondary);
            background: white;
        }
        .pagination a:hover {
            background: var(--light-green);
            border-color: var(--primary-green);
            color: var(--primary-green);
        }
        .pagination .active {
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
        }
        .pagination .disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* ---------- Empty State ---------- */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--off-white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
        }
        .empty-state-icon {
            font-size: 4rem;
            color: var(--primary-green-light);
            opacity: 0.4;
            margin-bottom: 1rem;
        }
        .empty-state h3 {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.75rem 1.8rem;
            border-radius: var(--radius-full);
            font-weight: 700;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-family: 'Inter', sans-serif;
        }
        .btn-primary {
            background: var(--primary-green);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-green-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .btn-outline {
            background: white;
            color: var(--primary-green);
            border: 2px solid var(--primary-green);
        }
        .btn-outline:hover {
            background: var(--light-green);
            transform: translateY(-2px);
        }

        /* ---------- Express Features Strip ---------- */
        .express-features-strip {
            background: var(--lighter-green);
            padding: 3rem 2rem;
            margin-top: 2rem;
            border-top: 1px solid var(--border-light);
            border-bottom: 1px solid var(--border-light);
        }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .feature-card {
            text-align: center;
            padding: 1.5rem;
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            transition: var(--transition);
        }
        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-green-light);
        }
        .feature-icon {
            width: 56px;
            height: 56px;
            background: var(--light-green);
            color: var(--primary-green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.3rem;
            transition: var(--transition);
        }
        .feature-card:hover .feature-icon {
            background: var(--primary-green);
            color: white;
            transform: scale(1.05);
        }
        .feature-card h5 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.4rem;
        }
        .feature-card p {
            font-size: 0.8rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        /* Section Header */
        .section-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .section-subtitle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-green);
            font-weight: 700;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 0.5rem;
        }
        .section-subtitle span {
            width: 25px;
            height: 2px;
            background: var(--primary-green);
            border-radius: 2px;
        }
        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(1.5rem, 3vw, 2rem);
            color: var(--dark);
            margin-bottom: 0;
        }
        .section-title span {
            color: var(--primary-green);
        }

        /* ---------- Search Bar ---------- */
        .search-bar-wrap {
            margin-bottom: 1.5rem;
        }
        .search-form {
            display: flex;
            gap: 8px;
        }
        .search-form input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid var(--border-light);
            border-radius: var(--radius-full);
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: var(--transition-fast);
        }
        .search-form input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(11,110,61,0.08);
        }
        .search-form button {
            padding: 12px 24px;
            background: var(--primary-green);
            color: white;
            border: none;
            border-radius: var(--radius-full);
            font-weight: 700;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
            white-space: nowrap;
        }
        .search-form button:hover {
            background: var(--primary-green-dark);
            transform: translateY(-1px);
        }

        /* ---------- Toast ---------- */
        .toast-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .toast {
            background: var(--primary-green);
            color: white;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            box-shadow: var(--shadow-lg);
            animation: slideInRight 0.4s ease;
            font-size: 0.85rem;
            max-width: 380px;
        }
        .toast.error { background: #E53935; }
        .toast.info { background: var(--primary-green); }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* ---------- Responsive ---------- */
        @media (max-width: 1024px) {
            .express-layout {
                grid-template-columns: 1fr;
            }
            .sidebar-filters {
                position: static;
            }
            .category-list {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                max-height: none;
            }
            .category-list li a {
                padding: 6px 14px;
                font-size: 0.8rem;
            }
        }
        @media (max-width: 768px) {
            .express-hero { padding: 1rem 1.5rem; }
            .express-hero-content h1 { font-size: 1.4rem; }
            .express-stats-row { gap: 0.8rem; flex-direction: row; flex-wrap: wrap; justify-content: center; }
            .express-stat-item { padding: 4px 12px; }
            .express-stat-icon { width: 28px; height: 28px; font-size: 0.8rem; }
            .express-stat-text .stat-value { font-size: 0.85rem; }
            .express-stat-text .stat-label { font-size: 0.55rem; }
            .products-header { flex-direction: column; align-items: stretch; }
            .sort-select { justify-content: space-between; }
            .product-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
            .product-image-wrap { height: 200px; }
            .express-layout { padding: 0 1rem 2rem; }
            .breadcrumb { padding: 0 1rem; margin: 0.75rem auto; }
        }
        @media (max-width: 480px) {
            .product-grid { grid-template-columns: 1fr; }
            .express-features-strip { padding: 2rem 1rem; }
            .features-grid { gap: 1rem; }
        }
    </style>
</head>
<body>

    <!-- ==================== TOP BAR ==================== -->
    <div class="top-bar">
        <div class="top-bar-inner">
            <div class="top-bar-left">
                <span><i class="fas fa-phone-alt"></i> <?php echo SITE_PHONE; ?></span>
                <span><i class="fab fa-whatsapp"></i> <?php echo SITE_WHATSAPP; ?></span>
            </div>
            <div class="top-bar-right">
                <a href="track-order.php"><i class="fas fa-box-tissue"></i> Track Order</a>
                <a href="express.php" style="color: #1FAF5B;"><i class="fas fa-bolt"></i> Express</a>
                <span><i class="fas fa-map-marker-alt"></i> 🇬🇭 Ghana</span>
            </div>
        </div>
    </div>

    <!-- ==================== HEADER ==================== -->
    <?php include __DIR__ . '/header.php'; ?>

    <!-- ==================== MAIN CONTENT ==================== -->
    <main>
        <!-- ===== COMPACT EXPRESS HERO ===== -->
        <section class="express-hero" data-aos="fade-in">
            <div class="express-hero-content">
                <div class="express-badge-large">
                    <i class="fas fa-bolt"></i> BLESS MALL EXPRESS
                    <i class="fas fa-truck-fast"></i>
                </div>
                <h1>
                    Need It <span class="highlight">Fast?</span> Get It <span class="highlight">Today!</span>
                </h1>
                <p>
                    Quality products delivered within 24-48 hours in Accra | 2-3 days nationwide
                </p>
                <div class="express-stats-row">
                    <div class="express-stat-item">
                        <div class="express-stat-icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <div class="express-stat-text">
                            <div class="stat-value"><?php echo $expressVendorCount; ?>+</div>
                            <div class="stat-label">Sellers</div>
                        </div>
                    </div>
                    <div class="express-stat-item">
                        <div class="express-stat-icon">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div class="express-stat-text">
                            <div class="stat-value"><?php echo $totalProducts; ?>+</div>
                            <div class="stat-label">Products</div>
                        </div>
                    </div>
                    <div class="express-stat-item">
                        <div class="express-stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="express-stat-text">
                            <div class="stat-value">24-48hrs</div>
                            <div class="stat-label">Delivery</div>
                        </div>
                    </div>
                    <div class="express-stat-item">
                        <div class="express-stat-icon">
                            <i class="fas fa-globe-africa"></i>
                        </div>
                        <div class="express-stat-text">
                            <div class="stat-value">16</div>
                            <div class="stat-label">Regions</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===== BREADCRUMB ===== -->
        <div class="breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Home</a>
            <i class="fas fa-chevron-right" style="font-size:0.6rem;"></i>
            <span class="current">Express Delivery</span>
        </div>

        <!-- ===== EXPRESS LAYOUT ===== -->
        <div class="express-layout">
            <!-- Sidebar Filters -->
            <aside class="sidebar-filters" data-aos="fade-right" data-aos-delay="100">
                <form method="get" action="express.php" id="filterForm">
                    <!-- Search -->
                    <div class="search-bar-wrap">
                        <div class="search-form">
                            <input type="text" name="q" placeholder="Search express products..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit"><i class="fas fa-search"></i></button>
                        </div>
                    </div>

                    <!-- Categories Filter -->
                    <?php if (!empty($expressCategories)): ?>
                    <div class="filter-card">
                        <h4><i class="fas fa-list-ul"></i> Categories</h4>
                        <ul class="category-list">
                            <li>
                                <a href="?<?php echo !empty($search) ? 'q=' . urlencode($search) : ''; ?>" 
                                   class="<?php echo $categoryFilter === 0 ? 'active' : ''; ?>">
                                    <span>All Categories</span>
                                    <span class="category-count"><?php echo $totalProducts; ?></span>
                                </a>
                            </li>
                            <?php foreach ($expressCategories as $cat): 
                                $catId = (int)$cat['id'];
                                $catName = htmlspecialchars($cat['name']);
                                $catCount = (int)$cat['product_count'];
                            ?>
                            <li>
                                <a href="?category=<?php echo $catId; ?><?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>" 
                                   class="<?php echo $categoryFilter === $catId ? 'active' : ''; ?>">
                                    <span><?php echo $catName; ?></span>
                                    <span class="category-count"><?php echo $catCount; ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- Price Range Filter -->
                    <div class="filter-card">
                        <h4><i class="fas fa-cedi-sign"></i> Price (GHS)</h4>
                        <div class="price-input-group">
                            <input type="number" name="price_min" placeholder="Min" value="<?php echo $priceMin > 0 ? $priceMin : ''; ?>" step="0.01">
                            <span>—</span>
                            <input type="number" name="price_max" placeholder="Max" value="<?php echo $priceMax > 0 ? $priceMax : ''; ?>" step="0.01">
                        </div>
                        <button type="submit" class="btn-filter-apply">
                            <i class="fas fa-sliders-h"></i> Apply Filters
                        </button>
                        <a href="express.php" class="btn-filter-reset">
                            <i class="fas fa-redo-alt"></i> Reset
                        </a>
                    </div>
                </form>
            </aside>

            <!-- Products Area -->
            <div class="products-area" data-aos="fade-left" data-aos-delay="200">
                <!-- Products Header -->
                <div class="products-header">
                    <div class="products-count">
                        <i class="fas fa-tag"></i> <strong><?php echo count($displayProducts); ?></strong> of <strong><?php echo $totalProducts; ?></strong> items
                        <?php if (!empty($search)): ?>
                             for "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        <?php endif; ?>
                    </div>
                    <div class="sort-select">
                        <label for="sort"><i class="fas fa-sort-amount-down-alt"></i> Sort:</label>
                        <select id="sort" name="sort" onchange="applySort(this.value)">
                            <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
                            <option value="price_low" <?php echo $sortBy === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                            <option value="price_high" <?php echo $sortBy === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                            <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>Top Rated</option>
                            <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>Popular</option>
                        </select>
                    </div>
                </div>

                <!-- Product Grid -->
                <?php if (!empty($displayProducts)): ?>
                <div class="product-grid">
                    <?php 
                    $productIndex = 0;
                    foreach ($displayProducts as $prod):
                        $prodId = (int)$prod['id'];
                        $prodName = htmlspecialchars($prod['name']);
                        $prodSlug = htmlspecialchars($prod['slug']);
                        $imagePath = $prod['image_path'] ?? '';
                        $vendorName = htmlspecialchars($prod['vendor_name'] ?? 'Express Vendor');
                        $origPrice = (float)($prod['unit_price'] ?? 0);
                        $discountPrice = !empty($prod['discount_price']) ? (float)$prod['discount_price'] : null;
                        $discountPerc = !empty($prod['discount_percentage']) ? (float)$prod['discount_percentage'] : null;
                        $avgRating = round((float)($prod['avg_rating'] ?? 4.5), 1);
                        $reviewCount = (int)($prod['review_count'] ?? 0);
                        
                        $finalPrice = $origPrice;
                        $hasDiscount = false;
                        if ($discountPrice !== null && $discountPrice > 0 && $discountPrice < $origPrice) {
                            $finalPrice = $discountPrice;
                            $hasDiscount = true;
                        } elseif ($discountPerc !== null && $discountPerc > 0) {
                            $finalPrice = $origPrice * (1 - $discountPerc/100);
                            $hasDiscount = true;
                        }
                        $finalPrice = round($finalPrice, 2);
                        $discountPercent = $hasDiscount ? round(($origPrice - $finalPrice) / $origPrice * 100) : 0;
                    ?>
                    <div class="product-card" data-aos="fade-up" data-aos-delay="<?php echo min(200, $productIndex * 40); ?>">
                        <span class="badge-express-card">
                            <i class="fas fa-bolt"></i> EXPRESS
                        </span>
                        <?php if ($hasDiscount && $discountPercent > 0): ?>
                            <span class="badge-discount">-<?php echo $discountPercent; ?>%</span>
                        <?php endif; ?>
                        
                        <a href="product-detail.php?slug=<?php echo $prodSlug; ?>" class="product-image-wrap">
                            <?php if ($imagePath && file_exists(BASE_PATH . ltrim($imagePath, '/'))): ?>
                                <img src="<?php echo BASE_URL . ltrim($imagePath, '/'); ?>" 
                                     alt="<?php echo $prodName; ?>" loading="lazy">
                            <?php else: ?>
                                <div class="product-image-placeholder">
                                    <i class="fas fa-box-open"></i>
                                </div>
                            <?php endif; ?>
                        </a>
                        
                        <div class="product-body">
                            <div class="product-vendor-info">
                                <span class="product-vendor">
                                    <i class="fas fa-store"></i> <?php echo mb_strlen($vendorName) > 20 ? mb_substr($vendorName, 0, 20) . '...' : $vendorName; ?>
                                </span>
                                <span class="delivery-time-badge">
                                    <i class="fas fa-clock"></i> 24-48hrs
                                </span>
                            </div>
                            <h4>
                                <a href="product-detail.php?slug=<?php echo $prodSlug; ?>">
                                    <?php echo mb_strlen($prodName) > 50 ? mb_substr($prodName, 0, 50) . '...' : $prodName; ?>
                                </a>
                            </h4>
                            <div class="product-rating">
                                <div class="stars">
                                    <?php 
                                    $fullStars = floor($avgRating);
                                    $hasHalf = ($avgRating - $fullStars) >= 0.5;
                                    for ($i = 1; $i <= 5; $i++): 
                                        if ($i <= $fullStars):
                                    ?>
                                        <i class="fas fa-star"></i>
                                    <?php elseif ($hasHalf && $i == $fullStars + 1): ?>
                                        <i class="fas fa-star-half-alt"></i>
                                    <?php else: ?>
                                        <i class="far fa-star"></i>
                                    <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                                <span class="rating-text">(<?php echo $reviewCount; ?>)</span>
                            </div>
                            <div class="product-price-row">
                                <span class="current-price"><?php echo $finalPrice > 0 ? formatPrice($finalPrice) : 'Contact'; ?></span>
                                <?php if ($hasDiscount && $finalPrice < $origPrice): ?>
                                    <span class="old-price"><?php echo formatPrice($origPrice); ?></span>
                                <?php endif; ?>
                            </div>
                            <button class="btn-add-cart" onclick="quickAddToCart(<?php echo $prodId; ?>)">
                                <i class="fas fa-shopping-cart"></i> Add to Cart
                            </button>
                        </div>
                    </div>
                    <?php 
                        $productIndex++;
                    endforeach; 
                    ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php 
                    $queryParams = $_GET;
                    unset($queryParams['page']);
                    $baseQuery = http_build_query($queryParams);
                    $baseUrl = 'express.php' . ($baseQuery ? '?' . $baseQuery . '&' : '?');
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1) {
                        echo '<a href="' . $baseUrl . 'page=1">1</a>';
                        if ($startPage > 2) echo '<span class="disabled">...</span>';
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>" 
                           class="<?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1) echo '<span class="disabled">...</span>'; ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state" data-aos="fade-up">
                    <div class="empty-state-icon">
                        <i class="fas fa-truck-fast"></i>
                    </div>
                    <h3>No Express Products Found</h3>
                    <p>
                        <?php if (!empty($search)): ?>
                            No items matching "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        <?php else: ?>
                            Check back soon for express delivery items
                        <?php endif; ?>
                    </p>
                    <div style="display: flex; gap: 0.8rem; justify-content: center; flex-wrap: wrap;">
                        <a href="express.php" class="btn btn-outline">
                            <i class="fas fa-bolt"></i> Reset
                        </a>
                        <a href="shop.php" class="btn btn-primary">
                            <i class="fas fa-shopping-bag"></i> Shop All
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== EXPRESS FEATURES STRIP ===== -->
        <section class="express-features-strip" data-aos="fade-up">
            <div class="section-header">
                <div class="section-subtitle">
                    <span></span>
                    Why Express
                    <span></span>
                </div>
                <h2 class="section-title">
                    The <span>Express Advantage</span>
                </h2>
            </div>
            <div class="features-grid">
                <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-icon">
                        <i class="fas fa-rocket"></i>
                    </div>
                    <h5>Lightning Fast</h5>
                    <p>24-48 hours in Accra<br>2-3 days nationwide</p>
                </div>
                <div class="feature-card" data-aos="fade-up" data-aos-delay="150">
                    <div class="feature-icon">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <h5>Priority Handling</h5>
                    <p>Express orders get priority processing</p>
                </div>
                <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-icon">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <h5>Real-Time Tracking</h5>
                    <p>Live GPS tracking updates</p>
                </div>
                <div class="feature-card" data-aos="fade-up" data-aos-delay="250">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h5>Verified Vendors</h5>
                    <p>100% verified sellers</p>
                </div>
            </div>
        </section>
    </main>

    <!-- ==================== FOOTER ==================== -->
    <?php include __DIR__ . '/footer.php'; ?>

    <!-- ==================== SCRIPTS ==================== -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 600,
            easing: 'ease-out-cubic',
            once: true,
            offset: 60
        });

        // Header scroll effect
        const headerEl = document.querySelector('.main-header');
        if (headerEl) {
            window.addEventListener('scroll', () => {
                if (window.scrollY > 50) {
                    headerEl.classList.add('scrolled');
                } else {
                    headerEl.classList.remove('scrolled');
                }
            });
        }

        // Sort function
        function applySort(sortValue) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('sort', sortValue);
            urlParams.set('page', '1');
            window.location.href = 'express.php?' + urlParams.toString();
        }

        // Quick Add to Cart
        function quickAddToCart(productId) {
            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('quantity', 1);
            formData.append('csrf_token', '<?php echo CSRFToken::generate(); ?>');

            fetch('api/add-to-cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('✓ Added to cart!', 'success');
                    const cartCount = document.getElementById('cartCountDisplay');
                    if (cartCount) {
                        cartCount.textContent = data.cart_count || (parseInt(cartCount.textContent || 0) + 1);
                    }
                } else {
                    showToast(data.message || 'Failed to add item', 'error');
                }
            })
            .catch(err => {
                console.error('Add to cart error:', err);
                showToast('An error occurred. Please try again.', 'error');
            });
        }

        // Toast Notification System
        function showToast(message, type = 'success') {
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<span>${message}</span>`;
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Price filter - allow Enter key to submit
        const priceInputs = document.querySelectorAll('.price-input-group input');
        if (priceInputs.length) {
            priceInputs.forEach(input => {
                input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        document.getElementById('filterForm').submit();
                    }
                });
            });
        }
    </script>
</body>
</html>