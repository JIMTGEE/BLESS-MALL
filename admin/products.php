<?php
/**
 * Bless Mall - Admin Products Management with Variations
 * Complete Self-Contained Product CRUD Operations with Multi-Variant Support
 * All logic embedded in this file - No external API calls
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP: Load core system files
// ------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ------------------------------------------------------------
// 2. AUTHENTICATION & AUTHORIZATION
// ------------------------------------------------------------
if (!Auth::isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role_id = (int)$_SESSION['role_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';

// Only admin (1) and seller (2) can access
if (!in_array($role_id, [ROLE_ADMIN, ROLE_SELLER])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$is_admin = ($role_id === ROLE_ADMIN);
$csrfToken = CSRFToken::generate();

// ------------------------------------------------------------
// 3. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 5. GET VENDOR INFO
// ------------------------------------------------------------
$vendor_id = null;
$vendor_info = null;

if ($is_admin) {
    $vendor_filter = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
} else {
    $vendor_query = "SELECT id, business_name, is_approved, status FROM vendors WHERE user_id = ? LIMIT 1";
    $stmt = $conn->prepare($vendor_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $vendor_info = $stmt->get_result()->fetch_assoc();
    
    if (!$vendor_info || $vendor_info['status'] !== 'active' || !$vendor_info['is_approved']) {
        $_SESSION['error_message'] = 'Your vendor account is not active or approved yet.';
        header('Location: ' . BASE_URL . 'admin/index.php');
        exit;
    }
    
    $vendor_id = (int)$vendor_info['id'];
    $vendor_filter = $vendor_id;
}

// ------------------------------------------------------------
// 6. DEFINE UPLOAD DIRECTORY CONSTANTS
// ------------------------------------------------------------
define('UPLOAD_BASE_DIR', dirname(__DIR__) . '/uploads/');
define('PRODUCT_UPLOAD_DIR', UPLOAD_BASE_DIR . 'products/');
define('PRODUCT_UPLOAD_URL', BASE_URL . 'uploads/products/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'gif']);

// Create base upload directory if it doesn't exist
if (!is_dir(PRODUCT_UPLOAD_DIR)) {
    mkdir(PRODUCT_UPLOAD_DIR, 0755, true);
}

// ------------------------------------------------------------
// 7. PAGINATION & FILTERS
// ------------------------------------------------------------
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : 'all';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

$allowed_sorts = ['newest', 'oldest', 'name_asc', 'name_desc', 'price_asc', 'price_desc', 'stock_asc', 'stock_desc', 'featured'];
if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'newest';
}

// ------------------------------------------------------------
// 8. MESSAGE HANDLING
// ------------------------------------------------------------
$success_message = '';
$error_message = '';
$edit_product = null;

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// ------------------------------------------------------------
// 9. HELPER FUNCTIONS
// ------------------------------------------------------------
function getProductDirectories($product_id) {
    return [
        'product' => PRODUCT_UPLOAD_DIR . $product_id . '/',
        'primary' => PRODUCT_UPLOAD_DIR . $product_id . '/primary/',
        'secondary' => PRODUCT_UPLOAD_DIR . $product_id . '/secondary/'
    ];
}

function createProductDirectories($product_id) {
    $dirs = getProductDirectories($product_id);
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    return $dirs;
}

function getProductById($conn, $product_id) {
    $sql = "SELECT p.*, 
            c.name as category_name,
            v.business_name as vendor_name,
            (SELECT image_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as primary_image,
            (SELECT COUNT(*) FROM product_images WHERE product_id = p.id) as image_count
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN vendors v ON p.vendor_id = v.id
            WHERE p.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if ($product) {
        // Get variants with their pricing tiers
        $variants_sql = "SELECT pv.*, 
                         COALESCE(SUM(pi_inv.stock_quantity), 0) as total_stock,
                         COALESCE(MIN(ppt.unit_price), 0) as min_price,
                         COALESCE(MAX(ppt.unit_price), 0) as max_price
                         FROM product_variants pv
                         LEFT JOIN product_pricing_tiers ppt ON pv.id = ppt.product_variant_id
                         LEFT JOIN product_inventory pi_inv ON ppt.id = pi_inv.pricing_tier_id
                         WHERE pv.product_id = ?
                         GROUP BY pv.id
                         ORDER BY pv.variant_name ASC, pv.variant_value ASC";
        $stmt = $conn->prepare($variants_sql);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product['variants'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get pricing tiers with inventory and variant info
        $tiers_sql = "SELECT ppt.*, 
                      COALESCE(pi_inv.stock_quantity, 0) as stock_quantity,
                      COALESCE(pi_inv.reserved_quantity, 0) as reserved_quantity,
                      COALESCE(pi_inv.low_stock_threshold, 10) as low_stock_threshold,
                      pi_inv.id as inventory_id,
                      pv.variant_name,
                      pv.variant_value
                      FROM product_pricing_tiers ppt
                      LEFT JOIN product_inventory pi_inv ON ppt.id = pi_inv.pricing_tier_id
                      LEFT JOIN product_variants pv ON ppt.product_variant_id = pv.id
                      WHERE ppt.product_id = ?
                      ORDER BY pv.variant_name ASC, pv.variant_value ASC, ppt.min_quantity ASC";
        $stmt = $conn->prepare($tiers_sql);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product['pricing_tiers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get all images
        $img_sql = "SELECT id, image_path, image_type, is_primary, sort_order, created_at 
                    FROM product_images WHERE product_id = ? 
                    ORDER BY is_primary DESC, sort_order ASC";
        $stmt = $conn->prepare($img_sql);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product['images'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    return $product;
}

function updateInventory($conn, $pricing_tier_id, $stock_quantity, $low_stock_threshold) {
    $check_sql = "SELECT id FROM product_inventory WHERE pricing_tier_id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("i", $pricing_tier_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        $sql = "UPDATE product_inventory SET 
                stock_quantity = ?, low_stock_threshold = ?, last_updated = NOW()
                WHERE pricing_tier_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $stock_quantity, $low_stock_threshold, $pricing_tier_id);
    } else {
        $sql = "INSERT INTO product_inventory 
                (pricing_tier_id, stock_quantity, reserved_quantity, low_stock_threshold, last_updated)
                VALUES (?, ?, 0, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $pricing_tier_id, $stock_quantity, $low_stock_threshold);
    }
    
    $stmt->execute();
}

function handlePricingTiers($conn, $product_id, $variants = []) {
    // First, clear existing tiers for this product to avoid duplicates
    $conn->query("DELETE FROM product_pricing_tiers WHERE product_id = $product_id");
    $conn->query("DELETE FROM product_inventory WHERE pricing_tier_id IN (SELECT id FROM product_pricing_tiers WHERE product_id = $product_id)");
    
    if (isset($_POST['tier_pricing_type']) && is_array($_POST['tier_pricing_type'])) {
        $tier_count = count($_POST['tier_pricing_type']);
        
        for ($i = 0; $i < $tier_count; $i++) {
            $pricing_type = $conn->real_escape_string($_POST['tier_pricing_type'][$i]);
            $tier_name = $conn->real_escape_string($_POST['tier_name'][$i] ?? 'Standard');
            $variant_id = isset($_POST['tier_variant_id'][$i]) && !empty($_POST['tier_variant_id'][$i]) 
                         ? (int)$_POST['tier_variant_id'][$i] 
                         : null;
            $min_quantity = (int)($_POST['tier_min_quantity'][$i] ?? 1);
            $max_quantity = !empty($_POST['tier_max_quantity'][$i]) ? (int)$_POST['tier_max_quantity'][$i] : null;
            $unit_price = (float)($_POST['tier_unit_price'][$i] ?? 0);
            $discount_price = !empty($_POST['tier_discount_price'][$i]) ? (float)$_POST['tier_discount_price'][$i] : null;
            $discount_percentage = !empty($_POST['tier_discount_percentage'][$i]) ? (float)$_POST['tier_discount_percentage'][$i] : null;
            $stock_quantity = (int)($_POST['tier_stock_quantity'][$i] ?? 0);
            $low_stock_threshold = !empty($_POST['tier_low_stock_threshold'][$i]) ? (int)$_POST['tier_low_stock_threshold'][$i] : 10;
            
            // Skip empty rows (no unit price)
            if ($unit_price <= 0) continue;
            
            $sql = "INSERT INTO product_pricing_tiers 
                    (product_id, product_variant_id, pricing_type, tier_name, min_quantity, max_quantity,
                     unit_price, discount_price, discount_percentage, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iissiisdd", 
                $product_id, $variant_id, $pricing_type, $tier_name, $min_quantity, $max_quantity,
                $unit_price, $discount_price, $discount_percentage
            );
            
            if ($stmt->execute()) {
                $new_tier_id = $stmt->insert_id;
                updateInventory($conn, $new_tier_id, $stock_quantity, $low_stock_threshold);
            }
        }
    }
}

function handleVariants($conn, $product_id) {
    // Get existing variants to check for updates
    $existing_variants = [];
    $variant_query = "SELECT id, variant_name, variant_value, sku_suffix FROM product_variants WHERE product_id = $product_id";
    $result = $conn->query($variant_query);
    while ($row = $result->fetch_assoc()) {
        $existing_variants[$row['id']] = $row;
    }
    
    $processed_ids = [];
    
    if (isset($_POST['variant_name']) && is_array($_POST['variant_name'])) {
        $variant_count = count($_POST['variant_name']);
        
        for ($i = 0; $i < $variant_count; $i++) {
            $variant_name = trim($conn->real_escape_string($_POST['variant_name'][$i]));
            $variant_value = trim($conn->real_escape_string($_POST['variant_value'][$i]));
            $sku_suffix = trim($conn->real_escape_string($_POST['variant_sku_suffix'][$i] ?? ''));
            $variant_id = isset($_POST['variant_id'][$i]) && !empty($_POST['variant_id'][$i]) 
                         ? (int)$_POST['variant_id'][$i] 
                         : 0;
            
            // Skip empty variants
            if (empty($variant_name) || empty($variant_value)) continue;
            
            // Check if variant already exists
            $check_sql = "SELECT id FROM product_variants WHERE product_id = ? AND variant_name = ? AND variant_value = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("issi", $product_id, $variant_name, $variant_value, $variant_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                continue; // Skip duplicate
            }
            
            if ($variant_id > 0 && isset($existing_variants[$variant_id])) {
                // Update existing variant
                $update_sql = "UPDATE product_variants SET variant_name = ?, variant_value = ?, sku_suffix = ?, updated_at = NOW() WHERE id = ? AND product_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sssii", $variant_name, $variant_value, $sku_suffix, $variant_id, $product_id);
                $update_stmt->execute();
                $processed_ids[] = $variant_id;
            } else {
                // Insert new variant
                $insert_sql = "INSERT INTO product_variants (product_id, variant_name, variant_value, sku_suffix, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("isss", $product_id, $variant_name, $variant_value, $sku_suffix);
                $insert_stmt->execute();
                $new_variant_id = $insert_stmt->insert_id;
                $processed_ids[] = $new_variant_id;
            }
        }
    }
    
    // Delete variants that were removed
    $ids_to_delete = array_diff(array_keys($existing_variants), $processed_ids);
    if (!empty($ids_to_delete)) {
        $delete_ids = implode(',', array_map('intval', $ids_to_delete));
        $conn->query("DELETE FROM product_variants WHERE id IN ($delete_ids) AND product_id = $product_id");
    }
    
    return $processed_ids;
}

function handleProductImages($conn, $product_id) {
    $dirs = getProductDirectories($product_id);
    createProductDirectories($product_id);
    
    $max_sort = $conn->query("SELECT COALESCE(MAX(sort_order), 0) as max_sort FROM product_images WHERE product_id = $product_id")->fetch_assoc()['max_sort'];
    
    // Handle primary image upload
    if (isset($_FILES['primary_image']) && $_FILES['primary_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['primary_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, ALLOWED_EXTENSIONS) && $file['size'] < MAX_FILE_SIZE) {
            // Remove old primary images
            $old_primaries = $conn->query("SELECT id, image_path FROM product_images WHERE product_id = $product_id AND is_primary = 1");
            while ($old = $old_primaries->fetch_assoc()) {
                $old_file_path = PRODUCT_UPLOAD_DIR . $old['image_path'];
                if (file_exists($old_file_path)) {
                    unlink($old_file_path);
                }
                $conn->query("DELETE FROM product_images WHERE id = " . $old['id']);
            }
            
            $filename = 'primary_' . time() . '_' . uniqid() . '.' . $ext;
            $filepath = $dirs['primary'] . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $db_path = $product_id . '/primary/' . $filename;
                $sql = "INSERT INTO product_images (product_id, image_path, image_type, is_primary, sort_order, created_at) 
                        VALUES (?, ?, 'primary', 1, 0, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("is", $product_id, $db_path);
                $stmt->execute();
            }
        }
    }
    
    // Handle additional images
    if (isset($_FILES['additional_images']) && is_array($_FILES['additional_images']['name'])) {
        foreach ($_FILES['additional_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['additional_images']['error'][$key] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['additional_images']['name'][$key], PATHINFO_EXTENSION));
                
                if (in_array($ext, ALLOWED_EXTENSIONS) && $_FILES['additional_images']['size'][$key] < MAX_FILE_SIZE) {
                    $filename = 'secondary_' . time() . '_' . uniqid() . '.' . $ext;
                    $filepath = $dirs['secondary'] . $filename;
                    
                    if (move_uploaded_file($tmp_name, $filepath)) {
                        $db_path = $product_id . '/secondary/' . $filename;
                        $max_sort++;
                        
                        $sql = "INSERT INTO product_images (product_id, image_path, image_type, is_primary, sort_order, created_at) 
                                VALUES (?, ?, 'secondary', 0, ?, NOW())";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("isi", $product_id, $db_path, $max_sort);
                        $stmt->execute();
                    }
                }
            }
        }
    }
}

function formatCurrency($amount) {
    return CURRENCY_SYMBOL . ' ' . number_format($amount, DECIMAL_PLACES);
}

function getStatusBadge($is_active) {
    if ($is_active) {
        return '<span class="status-active"><i class="fas fa-check-circle"></i> Active</span>';
    }
    return '<span class="status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
}

function getStockBadge($total_stock) {
    if ($total_stock > 10) {
        return '<span class="stock-in-stock"><i class="fas fa-check-circle"></i> In Stock (' . $total_stock . ')</span>';
    } elseif ($total_stock > 0) {
        return '<span class="stock-low"><i class="fas fa-exclamation-triangle"></i> Low Stock (' . $total_stock . ')</span>';
    }
    return '<span class="stock-out"><i class="fas fa-times-circle"></i> Out of Stock</span>';
}

function adminBuildQuery($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return http_build_query($params);
}

// ------------------------------------------------------------
// 10. HANDLE FORM ACTIONS
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !CSRFToken::validate($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Security validation failed. Refresh the page and try again.');
}

// Handle Delete/Deactivate Product
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['product_id'])) {
    $product_id = (int)$_POST['product_id'];
    
    $check_sql = "SELECT id, vendor_id, name FROM products WHERE id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if ($product && ($is_admin || (int)$product['vendor_id'] === $vendor_id)) {
        $delete_sql = "UPDATE products SET is_active = 0, updated_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($delete_sql);
        $stmt->bind_param("i", $product_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Product "' . htmlspecialchars($product['name']) . '" deactivated successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to deactivate product.';
        }
    } else {
        $_SESSION['error_message'] = 'You don\'t have permission to delete this product.';
    }
    
    header('Location: products.php');
    exit;
}

// Handle Reactivate Product
if (isset($_POST['action']) && $_POST['action'] === 'reactivate' && isset($_POST['product_id'])) {
    $product_id = (int)$_POST['product_id'];
    
    $check_sql = "SELECT id, vendor_id, name FROM products WHERE id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if ($product && ($is_admin || (int)$product['vendor_id'] === $vendor_id)) {
        $update_sql = "UPDATE products SET is_active = 1, updated_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("i", $product_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Product "' . htmlspecialchars($product['name']) . '" reactivated successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to reactivate product.';
        }
    } else {
        $_SESSION['error_message'] = 'You don\'t have permission to reactivate this product.';
    }
    
    header('Location: products.php');
    exit;
}

// Handle Set Primary Image
if (isset($_POST['action']) && $_POST['action'] === 'set_primary_image' && isset($_POST['image_id']) && isset($_POST['product_id'])) {
    $image_id = (int)$_POST['image_id'];
    $product_id = (int)$_POST['product_id'];
    
    $check_sql = "SELECT id, vendor_id FROM products WHERE id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if ($product && ($is_admin || (int)$product['vendor_id'] === $vendor_id)) {
        $conn->begin_transaction();
        
        try {
            // Reset all images for this product to secondary
            $reset_sql = "UPDATE product_images SET is_primary = 0, image_type = 'secondary' WHERE product_id = ?";
            $stmt = $conn->prepare($reset_sql);
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            
            // Set the selected image as primary
            $update_sql = "UPDATE product_images SET image_type = 'primary', is_primary = 1 WHERE id = ? AND product_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ii", $image_id, $product_id);
            $stmt->execute();
            
            $conn->commit();
            $_SESSION['success_message'] = 'Primary image updated successfully!';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = 'Failed to set primary image: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = 'You don\'t have permission to modify this product.';
    }
    
    header('Location: products.php?edit=' . $product_id);
    exit;
}

// Handle Delete Image
if (isset($_POST['action']) && $_POST['action'] === 'delete_image' && isset($_POST['image_id']) && isset($_POST['product_id'])) {
    $image_id = (int)$_POST['image_id'];
    $product_id = (int)$_POST['product_id'];
    
    $check_sql = "SELECT id, vendor_id FROM products WHERE id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if ($product && ($is_admin || (int)$product['vendor_id'] === $vendor_id)) {
        $img_sql = "SELECT * FROM product_images WHERE id = ? AND product_id = ?";
        $stmt = $conn->prepare($img_sql);
        $stmt->bind_param("ii", $image_id, $product_id);
        $stmt->execute();
        $image = $stmt->get_result()->fetch_assoc();
        
        if ($image) {
            $conn->begin_transaction();
            
            try {
                // Delete physical file
                $file_path = PRODUCT_UPLOAD_DIR . $image['image_path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                
                // Delete from database
                $delete_img_sql = "DELETE FROM product_images WHERE id = ?";
                $stmt = $conn->prepare($delete_img_sql);
                $stmt->bind_param("i", $image_id);
                $stmt->execute();
                
                // If we deleted the primary image, set the next available as primary
                if ($image['is_primary']) {
                    $next_img = $conn->query("SELECT id FROM product_images WHERE product_id = $product_id ORDER BY sort_order ASC LIMIT 1")->fetch_assoc();
                    if ($next_img) {
                        $promote_sql = "UPDATE product_images SET image_type = 'primary', is_primary = 1 WHERE id = " . $next_img['id'];
                        $conn->query($promote_sql);
                    }
                }
                
                $conn->commit();
                $_SESSION['success_message'] = 'Image deleted successfully!';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = 'Failed to delete image.';
            }
        }
    } else {
        $_SESSION['error_message'] = 'You don\'t have permission to modify this product.';
    }
    
    header('Location: products.php?edit=' . $product_id);
    exit;
}

// Handle Save Product (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_product') {
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $short_description = trim($_POST['short_description'] ?? '');
    $full_description = trim($_POST['full_description'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
    $dimensions = trim($_POST['dimensions'] ?? '');
    $featured = isset($_POST['featured']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Determine vendor_id
    if ($is_admin) {
        $product_vendor_id = isset($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0;
        if ($product_vendor_id === 0) {
            $_SESSION['error_message'] = 'Please select a vendor.';
            header('Location: products.php');
            exit;
        }
    } else {
        $product_vendor_id = $vendor_id;
    }
    
    // Generate slug if empty
    if (empty($slug)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $slug = trim($slug, '-');
    }
    
    // Ensure slug uniqueness
    $slug_check_sql = "SELECT id FROM products WHERE slug = ? AND id != ?";
    $stmt = $conn->prepare($slug_check_sql);
    $stmt->bind_param("si", $slug, $product_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $slug .= '-' . uniqid();
    }
    
    // Validation
    $errors = [];
    if (empty($name)) $errors[] = "Product name is required.";
    if ($category_id <= 0) $errors[] = "Please select a category.";
    if ($product_vendor_id <= 0) $errors[] = "Vendor is required.";
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            if ($product_id > 0) {
                // Update existing product
                $update_sql = "UPDATE products SET 
                    name = ?, slug = ?, category_id = ?, vendor_id = ?,
                    short_description = ?, full_description = ?, sku = ?, brand = ?,
                    weight = ?, dimensions = ?, featured = ?, is_active = ?,
                    updated_at = NOW()
                    WHERE id = ?";
                
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("ssiissssddsii", 
                    $name, $slug, $category_id, $product_vendor_id,
                    $short_description, $full_description, $sku, $brand,
                    $weight, $dimensions, $featured, $is_active,
                    $product_id
                );
                $stmt->execute();
                
                // Handle variants
                $variant_ids = handleVariants($conn, $product_id);
                
                // Handle pricing tiers
                handlePricingTiers($conn, $product_id, $variant_ids);
                
                // Handle images
                handleProductImages($conn, $product_id);
                
                $conn->commit();
                $_SESSION['success_message'] = 'Product updated successfully!';
                header('Location: products.php?edit=' . $product_id . '&success=1');
                exit;
            } else {
                // Insert new product
                $insert_sql = "INSERT INTO products (
                    vendor_id, category_id, name, slug, short_description, 
                    full_description, sku, brand, weight, dimensions, 
                    featured, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->bind_param("iissssssddsi", 
                    $product_vendor_id, $category_id, $name, $slug, 
                    $short_description, $full_description, $sku, $brand,
                    $weight, $dimensions, $featured, $is_active
                );
                $stmt->execute();
                
                $new_product_id = $stmt->insert_id;
                
                // Create directories for this product
                createProductDirectories($new_product_id);
                
                // Handle variants
                $variant_ids = handleVariants($conn, $new_product_id);
                
                // Handle pricing tiers
                handlePricingTiers($conn, $new_product_id, $variant_ids);
                
                // Handle images
                handleProductImages($conn, $new_product_id);
                
                $conn->commit();
                $_SESSION['success_message'] = 'Product created successfully!';
                header('Location: products.php?edit=' . $new_product_id . '&success=1');
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = 'Failed to save product: ' . $e->getMessage();
            header('Location: products.php');
            exit;
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
        header('Location: products.php');
        exit;
    }
}

// Load product for editing if requested
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_product = getProductById($conn, $edit_id);
    
    if (!$is_admin && $edit_product && (int)$edit_product['vendor_id'] !== $vendor_id) {
        $edit_product = null;
        $_SESSION['error_message'] = 'You don\'t have permission to edit this product.';
    }
}

if (isset($_GET['success'])) {
    $success_message = 'Product saved successfully!';
}

// ------------------------------------------------------------
// 11. BUILD QUERY FOR PRODUCTS LIST
// ------------------------------------------------------------
$where_conditions = [];
$params = [];
$types = "";

if ($is_admin) {
    if ($vendor_filter > 0) {
        $where_conditions[] = "p.vendor_id = ?";
        $params[] = $vendor_filter;
        $types .= "i";
    }
} else {
    $where_conditions[] = "p.vendor_id = ?";
    $params[] = $vendor_id;
    $types .= "i";
}

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($category_filter > 0) {
    $where_conditions[] = "p.category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

if ($status_filter === 'active') {
    $where_conditions[] = "p.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "p.is_active = 0";
}

if ($stock_filter === 'in_stock') {
    $where_conditions[] = "COALESCE(inv.total_stock, 0) > 0";
} elseif ($stock_filter === 'out_of_stock') {
    $where_conditions[] = "COALESCE(inv.total_stock, 0) <= 0";
} elseif ($stock_filter === 'low_stock') {
    $where_conditions[] = "COALESCE(inv.total_stock, 0) > 0 AND COALESCE(inv.total_stock, 0) <= COALESCE(inv.low_stock_threshold, 10)";
}

$where_clause = "";
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Sorting
$order_clause = "ORDER BY p.created_at DESC";
switch ($sort_by) {
    case 'oldest': $order_clause = "ORDER BY p.created_at ASC"; break;
    case 'name_asc': $order_clause = "ORDER BY p.name ASC"; break;
    case 'name_desc': $order_clause = "ORDER BY p.name DESC"; break;
    case 'price_asc': $order_clause = "ORDER BY COALESCE(ppt.min_price, 0) ASC"; break;
    case 'price_desc': $order_clause = "ORDER BY COALESCE(ppt.min_price, 0) DESC"; break;
    case 'stock_asc': $order_clause = "ORDER BY COALESCE(inv.total_stock, 0) ASC"; break;
    case 'stock_desc': $order_clause = "ORDER BY COALESCE(inv.total_stock, 0) DESC"; break;
    case 'featured': $order_clause = "ORDER BY p.featured DESC, p.created_at DESC"; break;
}

// Count total
$count_sql = "SELECT COUNT(DISTINCT p.id) as total FROM products p 
              LEFT JOIN (
                  SELECT pi_inv.pricing_tier_id, ppt2.product_id,
                         SUM(pi_inv.stock_quantity) as total_stock,
                         MIN(pi_inv.low_stock_threshold) as low_stock_threshold
                  FROM product_inventory pi_inv
                  JOIN product_pricing_tiers ppt2 ON pi_inv.pricing_tier_id = ppt2.id
                  GROUP BY ppt2.product_id
              ) inv ON p.id = inv.product_id
              $where_clause";

$stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_products = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_products / $per_page);

// Fetch products
$products_sql = "SELECT p.*, 
                c.name as category_name,
                v.business_name as vendor_name,
                COALESCE(inv.total_stock, 0) as total_stock,
                COALESCE(ppt.min_price, 0) as min_price,
                (SELECT image_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as primary_image,
                (SELECT COUNT(*) FROM product_images WHERE product_id = p.id) as image_count,
                (SELECT COUNT(*) FROM product_variants WHERE product_id = p.id) as variant_count
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN vendors v ON p.vendor_id = v.id
                LEFT JOIN (
                    SELECT pi_inv.pricing_tier_id, ppt2.product_id,
                           SUM(pi_inv.stock_quantity) as total_stock,
                           MIN(pi_inv.low_stock_threshold) as low_stock_threshold
                    FROM product_inventory pi_inv
                    JOIN product_pricing_tiers ppt2 ON pi_inv.pricing_tier_id = ppt2.id
                    GROUP BY ppt2.product_id
                ) inv ON p.id = inv.product_id
                LEFT JOIN (
                    SELECT product_id, MIN(unit_price) as min_price
                    FROM product_pricing_tiers
                    WHERE is_active = 1
                    GROUP BY product_id
                ) ppt ON p.id = ppt.product_id
                $where_clause
                $order_clause
                LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($products_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products_result = $stmt->get_result();

$products = [];
while ($row = $products_result->fetch_assoc()) {
    $products[] = $row;
}

// ------------------------------------------------------------
// 12. FETCH CATEGORIES & VENDORS FOR DROPDOWNS
// ------------------------------------------------------------
$categories = [];
$parent_result = $conn->query("SELECT id, name, slug, parent_id FROM categories WHERE is_active = 1 AND parent_id IS NULL ORDER BY name");
while ($parent = $parent_result->fetch_assoc()) {
    $parent['children'] = [];
    $child_result = $conn->query("SELECT id, name, slug, parent_id FROM categories WHERE is_active = 1 AND parent_id = " . (int)$parent['id'] . " ORDER BY name");
    while ($child = $child_result->fetch_assoc()) {
        $parent['children'][] = $child;
    }
    $categories[] = $parent;
}

$vendors = [];
if ($is_admin) {
    $ven_result = $conn->query("SELECT id, business_name, is_express, status FROM vendors WHERE status = 'active' ORDER BY business_name");
    while ($ven = $ven_result->fetch_assoc()) {
        $vendors[] = $ven;
    }
}

$page_title = 'Products Management - Bless Mall Admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $page_title; ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --admin-bg: #0F172A;
            --admin-bg-light: #1E293B;
            --admin-bg-lighter: #334155;
            --admin-surface: #1E293B;
            --admin-surface-hover: #273548;
            --admin-border: #334155;
            --admin-border-light: #475569;
            --admin-primary: #3B82F6;
            --admin-primary-dark: #2563EB;
            --admin-primary-light: #60A5FA;
            --admin-success: #10B981;
            --admin-success-bg: rgba(16, 185, 129, 0.1);
            --admin-warning: #F59E0B;
            --admin-warning-bg: rgba(245, 158, 11, 0.1);
            --admin-danger: #EF4444;
            --admin-danger-bg: rgba(239, 68, 68, 0.1);
            --text-primary: #F1F5F9;
            --text-secondary: #94A3B8;
            --text-muted: #64748B;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-full: 9999px;
            --transition-fast: 0.15s ease;
            --transition: 0.25s ease;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            background: var(--admin-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }

        .admin-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .admin-breadcrumb a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition-fast);
        }
        .admin-breadcrumb a:hover {
            color: var(--admin-primary);
        }
        .admin-breadcrumb .separator {
            font-size: 0.6rem;
        }
        .admin-breadcrumb .current {
            color: var(--text-primary);
        }

        .admin-page-header {
            padding: 0 1.5rem 1.5rem;
        }
        .admin-page-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .admin-page-header p {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .products-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 0 1.5rem;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-family: 'Inter', sans-serif;
            text-decoration: none;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: var(--admin-primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--admin-primary-dark);
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--admin-border-light);
            color: var(--text-secondary);
        }
        .btn-outline:hover {
            border-color: var(--admin-primary);
            color: var(--text-primary);
        }
        
        .btn-danger {
            background: var(--admin-danger);
            color: white;
        }
        .btn-danger:hover {
            background: #DC2626;
        }
        
        .btn-success {
            background: var(--admin-success);
            color: white;
        }
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }
        
        .btn-xs {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 6px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid var(--admin-border);
            background: transparent;
            color: var(--text-secondary);
            text-decoration: none;
        }
        .btn-icon:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
            background: var(--admin-surface-hover);
        }
        .btn-icon.delete:hover {
            border-color: var(--admin-danger);
            color: var(--admin-danger);
            background: var(--admin-danger-bg);
        }
        .btn-icon.reactivate:hover {
            border-color: var(--admin-success);
            color: var(--admin-success);
            background: var(--admin-success-bg);
        }
        .btn-icon.star:hover {
            border-color: #F59E0B;
            color: #F59E0B;
            background: rgba(245, 158, 11, 0.1);
        }

        .filter-bar {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            margin: 0 1.5rem 1.5rem;
        }
        
        .filter-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.4rem;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.55rem 0.75rem;
            background: var(--admin-bg-lighter);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: var(--transition-fast);
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .filter-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2394A3B8' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2rem;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin: 0 1.5rem 1.5rem;
        }
        
        .stat-mini {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            text-align: center;
        }
        
        .stat-mini .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }
        
        .stat-mini .stat-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 0.25rem;
        }

        .table-container {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin: 0 1.5rem 1.5rem;
        }
        
        .table-container table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-container thead {
            background: var(--admin-bg-lighter);
        }
        
        .table-container th {
            padding: 0.85rem 1rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--admin-border);
            white-space: nowrap;
        }
        
        .table-container td {
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--admin-border);
            vertical-align: middle;
        }
        
        .table-container tr:last-child td {
            border-bottom: none;
        }
        
        .table-container tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .product-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .product-thumb {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            background: var(--admin-bg-lighter);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .product-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .product-thumb i {
            font-size: 1.2rem;
            color: var(--text-muted);
        }
        
        .product-cell .product-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.15rem;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .product-cell .product-meta {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .status-active, .status-inactive, .stock-in-stock, .stock-low, .stock-out {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .status-active {
            background: var(--admin-success-bg);
            color: var(--admin-success);
        }
        
        .status-inactive {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
        }
        
        .stock-in-stock {
            background: var(--admin-success-bg);
            color: var(--admin-success);
        }
        
        .stock-low {
            background: var(--admin-warning-bg);
            color: var(--admin-warning);
        }
        
        .stock-out {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
        }
        
        .badge-featured {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            background: rgba(139, 92, 246, 0.15);
            color: #A78BFA;
        }

        .badge-image-count {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.65rem;
            font-weight: 600;
            background: rgba(100, 116, 139, 0.15);
            color: var(--text-muted);
        }

        .badge-variant-count {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.65rem;
            font-weight: 600;
            background: rgba(139, 92, 246, 0.15);
            color: #A78BFA;
        }

        .actions-cell {
            display: flex;
            gap: 0.35rem;
            justify-content: flex-end;
        }

        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .image-card {
            background: var(--admin-bg);
            border: 2px solid var(--admin-border);
            border-radius: var(--radius-md);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }
        
        .image-card:hover {
            border-color: var(--admin-primary);
            transform: translateY(-2px);
        }
        
        .image-card.primary-image {
            border-color: var(--admin-primary);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.15);
        }
        
        .image-card .image-preview {
            width: 100%;
            height: 160px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--admin-bg-lighter);
            cursor: pointer;
        }
        
        .image-card .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .image-card:hover .image-preview img {
            transform: scale(1.05);
        }
        
        .image-card .image-preview i {
            font-size: 2.5rem;
            color: var(--text-muted);
            opacity: 0.5;
        }
        
        .image-card .image-info {
            padding: 0.6rem 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        
        .image-card .image-type-badge {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .image-card .image-actions {
            display: flex;
            gap: 0.25rem;
        }

        .image-lightbox {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.9);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        .image-lightbox.active {
            display: flex;
        }
        
        .image-lightbox img {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
            border-radius: var(--radius-md);
        }
        
        .image-lightbox .close-lightbox {
            position: absolute;
            top: 1rem;
            right: 1rem;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            z-index: 2001;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.5);
            border-radius: 50%;
            border: none;
        }

        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--admin-border);
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .pagination-info {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .pagination-buttons {
            display: flex;
            gap: 0.35rem;
        }
        
        .pagination-buttons a,
        .pagination-buttons span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition-fast);
            border: 1px solid var(--admin-border);
            color: var(--text-secondary);
        }
        
        .pagination-buttons a:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
            background: var(--admin-surface-hover);
        }
        
        .pagination-buttons .active {
            background: var(--admin-primary);
            color: white;
            border-color: var(--admin-primary);
        }
        
        .pagination-buttons .disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            padding: 2rem;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--admin-bg-light);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 1100px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn 0.25s ease;
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--admin-border);
            position: sticky;
            top: 0;
            background: var(--admin-bg-light);
            z-index: 1;
        }
        
        .modal-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .modal-close {
            width: 32px;
            height: 32px;
            border: 1px solid var(--admin-border);
            background: transparent;
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--text-muted);
            font-size: 1rem;
            transition: var(--transition-fast);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-close:hover {
            border-color: var(--admin-danger);
            color: var(--admin-danger);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--admin-border);
            position: sticky;
            bottom: 0;
            background: var(--admin-bg-light);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.4rem;
        }
        
        .form-group label .required {
            color: var(--admin-danger);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.65rem 0.85rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: var(--transition-fast);
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-group .help-text {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            accent-color: var(--admin-primary);
            cursor: pointer;
        }
        
        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }

        /* Variant Styles */
        .variant-section {
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-top: 1.5rem;
        }
        
        .variant-section h4 {
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .variant-row {
            display: grid;
            grid-template-columns: 140px 160px 100px 40px;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--admin-border);
        }
        
        .variant-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .variant-row input,
        .variant-row select {
            width: 100%;
            padding: 0.45rem 0.5rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 0.8rem;
            font-family: 'Inter', sans-serif;
        }
        
        .variant-header {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .btn-add-variant {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.8rem;
            background: transparent;
            border: 1px dashed var(--admin-border-light);
            color: var(--text-muted);
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            cursor: pointer;
            transition: var(--transition-fast);
            margin-top: 0.5rem;
        }
        .btn-add-variant:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
        }
        
        .btn-remove-variant {
            background: none;
            border: none;
            color: var(--admin-danger);
            cursor: pointer;
            padding: 0.25rem;
            font-size: 0.85rem;
        }

        .pricing-tiers-section {
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .pricing-tiers-section h4 {
            font-size: 0.9rem;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }
        
        .tier-row {
            display: grid;
            grid-template-columns: 100px 120px 140px 60px 80px 90px 80px 80px 60px;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--admin-border);
        }
        
        .tier-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .tier-row input,
        .tier-row select {
            width: 100%;
            padding: 0.45rem 0.5rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 0.75rem;
            font-family: 'Inter', sans-serif;
        }
        
        .tier-row select {
            cursor: pointer;
        }
        
        .tier-header {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .btn-add-tier {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.8rem;
            background: transparent;
            border: 1px dashed var(--admin-border-light);
            color: var(--text-muted);
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            cursor: pointer;
            transition: var(--transition-fast);
            margin-top: 0.5rem;
        }
        .btn-add-tier:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
        }
        
        .btn-remove-tier {
            background: none;
            border: none;
            color: var(--admin-danger);
            cursor: pointer;
            padding: 0.25rem;
            font-size: 0.85rem;
        }

        .alert {
            padding: 0.85rem 1rem;
            border-radius: var(--radius-md);
            margin: 0 1.5rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: var(--admin-success-bg);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--admin-success);
        }
        
        .alert-error {
            background: var(--admin-danger-bg);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--admin-danger);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 1.1rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }

        /* Toast notification */
        .toast-container {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-width: 400px;
            width: 100%;
        }
        
        .toast {
            padding: 1rem 1.25rem;
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 0.85rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            animation: slideUp 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .toast-success {
            border-left: 4px solid var(--admin-success);
        }
        
        .toast-error {
            border-left: 4px solid var(--admin-danger);
        }
        
        .toast-info {
            border-left: 4px solid var(--admin-primary);
        }
        
        .toast .toast-icon {
            font-size: 1.2rem;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .variant-row {
                grid-template-columns: 1fr 1fr 1fr;
                gap: 0.5rem;
            }
            
            .tier-row {
                grid-template-columns: 1fr 1fr 1fr;
                gap: 0.5rem;
            }
            
            .image-gallery {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .table-container {
                overflow-x: auto;
                margin: 0 0.5rem 1.5rem;
            }
            
            .table-container table {
                min-width: 900px;
            }
            
            .products-toolbar {
                flex-direction: column;
                align-items: stretch;
                padding: 0 0.5rem;
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .variant-row {
                grid-template-columns: 1fr 1fr;
            }
            
            .tier-row {
                grid-template-columns: 1fr 1fr;
            }
            
            .stats-row {
                margin: 0 0.5rem 1.5rem;
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-bar {
                margin: 0 0.5rem 1.5rem;
            }
            
            .alert {
                margin: 0 0.5rem 1.5rem;
            }
            
            .image-gallery {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>

<div class="admin-breadcrumb">
    <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <span class="separator"><i class="fas fa-chevron-right"></i></span>
    <span class="current">Products</span>
</div>

<div class="admin-page-header">
    <h1><i class="fas fa-box-open"></i> Products Management</h1>
    <p>Add, edit, and manage your product catalog with multi-image and multi-variant support</p>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success" id="successAlert">
    <i class="fas fa-check-circle"></i>
    <span><?php echo $success_message; ?></span>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;color:inherit;cursor:pointer;margin-left:auto;">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-error" id="errorAlert">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $error_message; ?></span>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;color:inherit;cursor:pointer;margin-left:auto;">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php endif; ?>

<div class="stats-row">
    <div class="stat-mini">
        <div class="stat-value"><?php echo $total_products; ?></div>
        <div class="stat-label">Total Products</div>
    </div>
    <div class="stat-mini">
        <div class="stat-value"><?php 
            $active_sql = "SELECT COUNT(*) as cnt FROM products WHERE is_active = 1" . 
                          (!$is_admin ? " AND vendor_id = " . $vendor_id : "");
            echo $conn->query($active_sql)->fetch_assoc()['cnt'];
        ?></div>
        <div class="stat-label">Active</div>
    </div>
    <div class="stat-mini">
        <div class="stat-value"><?php 
            $feat_sql = "SELECT COUNT(*) as cnt FROM products WHERE featured = 1 AND is_active = 1" . 
                        (!$is_admin ? " AND vendor_id = " . $vendor_id : "");
            echo $conn->query($feat_sql)->fetch_assoc()['cnt'];
        ?></div>
        <div class="stat-label">Featured</div>
    </div>
    <div class="stat-mini">
        <div class="stat-value"><?php 
            $low_sql = "SELECT COUNT(DISTINCT p.id) as cnt FROM products p
                        JOIN product_pricing_tiers ppt ON p.id = ppt.product_id
                        JOIN product_inventory pi_inv ON ppt.id = pi_inv.pricing_tier_id
                        WHERE p.is_active = 1 AND pi_inv.stock_quantity > 0 
                        AND pi_inv.stock_quantity <= pi_inv.low_stock_threshold" .
                        (!$is_admin ? " AND p.vendor_id = " . $vendor_id : "");
            echo $conn->query($low_sql)->fetch_assoc()['cnt'];
        ?></div>
        <div class="stat-label">Low Stock</div>
    </div>
</div>

<div class="products-toolbar">
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.25rem; font-weight: 600;">
            All Products
        </h2>
        <span style="font-size: 0.8rem; color: var(--text-muted);">
            (<?php echo $total_products; ?> total)
        </span>
    </div>
    <button class="btn btn-primary" onclick="openAddProductModal()">
        <i class="fas fa-plus"></i> Add New Product
    </button>
</div>

<div class="filter-bar">
    <form method="GET" action="products.php" class="filter-row">
        <div class="filter-group" style="flex: 2; min-width: 200px;">
            <label><i class="fas fa-search"></i> Search</label>
            <input type="text" name="search" placeholder="Search products..." 
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <?php if ($is_admin): ?>
        <div class="filter-group">
            <label><i class="fas fa-store"></i> Vendor</label>
            <select name="vendor_id" onchange="this.form.submit()">
                <option value="0">All Vendors</option>
                <?php foreach ($vendors as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $vendor_filter === (int)$v['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($v['business_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <div class="filter-group">
            <label><i class="fas fa-th-large"></i> Category</label>
            <select name="category_id" onchange="this.form.submit()">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $parent): ?>
                    <optgroup label="<?php echo htmlspecialchars($parent['name']); ?>">
                        <?php foreach ($parent['children'] as $child): ?>
                            <option value="<?php echo $child['id']; ?>" <?php echo $category_filter === (int)$child['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($child['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group">
            <label><i class="fas fa-toggle-on"></i> Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label><i class="fas fa-boxes"></i> Stock</label>
            <select name="stock" onchange="this.form.submit()">
                <option value="all" <?php echo $stock_filter === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="in_stock" <?php echo $stock_filter === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                <option value="low_stock" <?php echo $stock_filter === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                <option value="out_of_stock" <?php echo $stock_filter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label><i class="fas fa-sort"></i> Sort</label>
            <select name="sort" onchange="this.form.submit()">
                <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest</option>
                <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                <option value="name_asc" <?php echo $sort_by === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                <option value="name_desc" <?php echo $sort_by === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                <option value="price_asc" <?php echo $sort_by === 'price_asc' ? 'selected' : ''; ?>>Price ↑</option>
                <option value="price_desc" <?php echo $sort_by === 'price_desc' ? 'selected' : ''; ?>>Price ↓</option>
            </select>
        </div>
        
        <div class="filter-group" style="display: flex; align-items: flex-end; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-filter"></i> Apply
            </button>
            <a href="products.php" class="btn btn-outline btn-sm">
                <i class="fas fa-redo-alt"></i> Reset
            </a>
        </div>
    </form>
</div>

<div class="table-container">
    <?php if (!empty($products)): ?>
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU / Brand</th>
                <th>Category</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Variants</th>
                <th>Status</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): 
                $display_image_url = '';
                if (!empty($product['primary_image'])) {
                    $full_path = PRODUCT_UPLOAD_DIR . $product['primary_image'];
                    if (file_exists($full_path)) {
                        $display_image_url = PRODUCT_UPLOAD_URL . $product['primary_image'];
                    }
                }
                $image_count = (int)($product['image_count'] ?? 0);
                $variant_count = (int)($product['variant_count'] ?? 0);
                $is_active = (int)$product['is_active'];
            ?>
            <tr>
                <td>
                    <div class="product-cell">
                        <div class="product-thumb">
                            <?php if ($display_image_url): ?>
                                <img src="<?php echo htmlspecialchars($display_image_url); ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                     loading="lazy">
                            <?php else: ?>
                                <i class="fas fa-box-open"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="product-name" title="<?php echo htmlspecialchars($product['name']); ?>">
                                <?php echo htmlspecialchars($product['name']); ?>
                            </div>
                            <div class="product-meta">
                                ID: #<?php echo $product['id']; ?>
                                <?php if ($product['featured']): ?>
                                    <span class="badge-featured" style="margin-left: 0.5rem;">
                                        <i class="fas fa-star"></i> Featured
                                    </span>
                                <?php endif; ?>
                                <?php if (!$is_active): ?>
                                    <span style="margin-left: 0.5rem; color: var(--admin-danger); font-size: 0.65rem;">
                                        <i class="fas fa-eye-slash"></i> Hidden
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div style="font-family: 'JetBrains Mono', monospace; font-size: 0.8rem;">
                        <?php echo $product['sku'] ? htmlspecialchars($product['sku']) : '<span style="color: var(--text-muted);">N/A</span>'; ?>
                    </div>
                    <?php if ($product['brand']): ?>
                        <div style="font-size: 0.7rem; color: var(--text-muted);">
                            <?php echo htmlspecialchars($product['brand']); ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                <td>
                    <span style="font-weight: 600; color: var(--admin-success);">
                        <?php echo $product['min_price'] > 0 ? formatCurrency($product['min_price']) : '<span style="color: var(--text-muted);">No price</span>'; ?>
                    </span>
                </td>
                <td><?php echo getStockBadge($product['total_stock']); ?></td>
                <td>
                    <?php if ($variant_count > 0): ?>
                        <span class="badge-variant-count">
                            <i class="fas fa-cubes"></i> <?php echo $variant_count; ?>
                        </span>
                    <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 0.75rem;">No variants</span>
                    <?php endif; ?>
                </td>
                <td><?php echo getStatusBadge($is_active); ?></td>
                <td>
                    <div class="actions-cell">
                        <a href="<?php echo BASE_URL; ?>product-detail.php?id=<?php echo $product['id']; ?>" 
                           class="btn-icon" title="View on Site" target="_blank">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="products.php?edit=<?php echo $product['id']; ?>" class="btn-icon" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php if ($is_active): ?>
                        <button class="btn-icon delete" title="Deactivate"
                                onclick="confirmDelete(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['name'])); ?>')">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                        <?php else: ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Reactivate this product? It will become visible in the shop.')">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="reactivate">
                            <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                            <button type="submit" class="btn-icon reactivate" title="Reactivate">
                                <i class="fas fa-undo-alt"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <?php if ($total_pages > 1): ?>
    <div class="pagination-bar">
        <div class="pagination-info">
            Showing <?php echo min($total_products, $offset + 1); ?>–<?php echo min($total_products, $offset + $per_page); ?> 
            of <?php echo $total_products; ?> products
        </div>
        <div class="pagination-buttons">
            <?php if ($page > 1): ?>
                <a href="?<?php echo adminBuildQuery(['page']); ?>&page=<?php echo $page - 1; ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            
            if ($start > 1) {
                echo '<a href="?' . adminBuildQuery(['page']) . '&page=1">1</a>';
                if ($start > 2) echo '<span class="disabled">...</span>';
            }
            
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?<?php echo adminBuildQuery(['page']); ?>&page=<?php echo $i; ?>" 
                   class="<?php echo $i === $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor;
            
            if ($end < $total_pages) {
                if ($end < $total_pages - 1) echo '<span class="disabled">...</span>';
                echo '<a href="?' . adminBuildQuery(['page']) . '&page=' . $total_pages . '">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?<?php echo adminBuildQuery(['page']); ?>&page=<?php echo $page + 1; ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-box-open"></i>
        <h3>No Products Found</h3>
        <p>Get started by adding your first product to the catalog.</p>
        <button class="btn btn-primary" onclick="openAddProductModal()">
            <i class="fas fa-plus"></i> Add New Product
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Product Modal (Add/Edit) -->
<div class="modal-overlay" id="productModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fas fa-box-open"></i> <?php echo $edit_product ? 'Edit Product' : 'Add New Product'; ?></h3>
            <button class="modal-close" onclick="closeProductModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="productForm" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="product_id" id="modalProductId" value="<?php echo $edit_product ? $edit_product['id'] : ''; ?>">
            
            <div class="modal-body">
                <!-- Product Details -->
                <div class="form-grid">
                    <div class="form-group">
                        <label><span class="required">*</span> Product Name</label>
                        <input type="text" name="name" id="prodName" required placeholder="Enter product name"
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Slug (auto-generated)</label>
                        <input type="text" name="slug" id="prodSlug" placeholder="product-slug"
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['slug']) : ''; ?>">
                        <span class="help-text">Leave empty to auto-generate from name</span>
                    </div>
                    
                    <div class="form-group">
                        <label><span class="required">*</span> Category</label>
                        <select name="category_id" id="prodCategory" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $parent): ?>
                                <optgroup label="<?php echo htmlspecialchars($parent['name']); ?>">
                                    <?php foreach ($parent['children'] as $child): ?>
                                        <option value="<?php echo $child['id']; ?>" 
                                            <?php echo ($edit_product && (int)$edit_product['category_id'] === (int)$child['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($child['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($is_admin): ?>
                    <div class="form-group">
                        <label><span class="required">*</span> Vendor</label>
                        <select name="vendor_id" id="prodVendor" required>
                            <option value="">Select vendor</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?php echo $v['id']; ?>"
                                    <?php echo ($edit_product && (int)$edit_product['vendor_id'] === (int)$v['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($v['business_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="vendor_id" value="<?php echo $vendor_id; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>SKU</label>
                        <input type="text" name="sku" id="prodSku" placeholder="Product SKU"
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['sku']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Brand</label>
                        <input type="text" name="brand" id="prodBrand" placeholder="Brand name"
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['brand']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Weight (kg)</label>
                        <input type="number" name="weight" id="prodWeight" step="0.01" placeholder="0.00"
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['weight']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Dimensions</label>
                        <input type="text" name="dimensions" id="prodDimensions" placeholder="e.g., 10x5x3 cm"
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['dimensions']) : ''; ?>">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Short Description</label>
                        <textarea name="short_description" id="prodShortDesc" rows="2" 
                                  placeholder="Brief description for product cards"><?php echo $edit_product ? htmlspecialchars($edit_product['short_description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Full Description</label>
                        <textarea name="full_description" id="prodFullDesc" rows="5" 
                                  placeholder="Detailed product description"><?php echo $edit_product ? htmlspecialchars($edit_product['full_description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="featured" id="prodFeatured" value="1"
                                <?php echo ($edit_product && $edit_product['featured']) ? 'checked' : ''; ?>>
                            <label for="prodFeatured">Featured Product</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="prodActive" value="1" checked
                                <?php echo ($edit_product && !$edit_product['is_active']) ? '' : 'checked'; ?>>
                            <label for="prodActive">Active (visible on shop)</label>
                            <span class="help-text" style="margin-left: 0.5rem; display: inline;">Products are active by default</span>
                        </div>
                    </div>
                </div>
                
                <!-- Variants Section -->
                <div class="variant-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <h4><i class="fas fa-cubes"></i> Product Variants</h4>
                        <span style="font-size: 0.7rem; color: var(--text-muted);">Add sizes, colors, or other product variations</span>
                    </div>
                    <div id="variantsContainer">
                        <div class="variant-row variant-header">
                            <span>Variant Name</span>
                            <span>Variant Value</span>
                            <span>SKU Suffix</span>
                            <span></span>
                        </div>
                        <?php if ($edit_product && !empty($edit_product['variants'])): ?>
                            <?php foreach ($edit_product['variants'] as $variant): ?>
                            <div class="variant-row">
                                <input type="text" name="variant_name[]" value="<?php echo htmlspecialchars($variant['variant_name']); ?>" placeholder="e.g., Size, Color">
                                <input type="text" name="variant_value[]" value="<?php echo htmlspecialchars($variant['variant_value']); ?>" placeholder="e.g., Large, Red">
                                <input type="text" name="variant_sku_suffix[]" value="<?php echo htmlspecialchars($variant['sku_suffix'] ?? ''); ?>" placeholder="e.g., -L, -RED">
                                <input type="hidden" name="variant_id[]" value="<?php echo $variant['id']; ?>">
                                <button type="button" class="btn-remove-variant" onclick="this.closest('.variant-row').remove()" title="Remove variant">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="variant-row">
                                <input type="text" name="variant_name[]" placeholder="e.g., Size" value="">
                                <input type="text" name="variant_value[]" placeholder="e.g., Large" value="">
                                <input type="text" name="variant_sku_suffix[]" placeholder="e.g., -L" value="">
                                <input type="hidden" name="variant_id[]" value="">
                                <button type="button" class="btn-remove-variant" onclick="this.closest('.variant-row').remove()" title="Remove variant">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                            <div class="variant-row">
                                <input type="text" name="variant_name[]" placeholder="e.g., Color" value="">
                                <input type="text" name="variant_value[]" placeholder="e.g., Red" value="">
                                <input type="text" name="variant_sku_suffix[]" placeholder="e.g., -RED" value="">
                                <input type="hidden" name="variant_id[]" value="">
                                <button type="button" class="btn-remove-variant" onclick="this.closest('.variant-row').remove()" title="Remove variant">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-add-variant" onclick="addVariant()">
                        <i class="fas fa-plus"></i> Add Variant
                    </button>
                </div>
                
                <!-- Images Upload Section -->
                <div style="margin-top: 1.5rem; padding: 1rem; background: var(--admin-bg); border-radius: var(--radius-md); border: 1px solid var(--admin-border);">
                    <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem; color: var(--text-primary);">
                        <i class="fas fa-images"></i> Product Images
                    </h4>
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 1rem;">
                        Images organized at <code style="background: var(--admin-bg-lighter); padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.7rem;">uploads/products/{id}/</code>
                        — One primary + multiple secondary images supported
                    </p>
                    
                    <div class="form-grid" style="margin-top: 0.5rem;">
                        <div class="form-group">
                            <label>Primary Image</label>
                            <input type="file" name="primary_image" accept="image/*" id="primaryImageInput">
                            <span class="help-text">Main product image (800x800px, max 10MB)</span>
                        </div>
                        
                        <div class="form-group">
                            <label>Additional Images</label>
                            <input type="file" name="additional_images[]" accept="image/*" multiple id="additionalImagesInput">
                            <span class="help-text">Secondary images (multi-select, max 10MB each)</span>
                        </div>
                    </div>
                    
                    <div class="image-gallery" id="existingImagesGallery" style="margin-top: 1rem;">
                        <?php if ($edit_product && !empty($edit_product['images'])): ?>
                            <?php foreach ($edit_product['images'] as $image): 
                                $isPrimary = $image['is_primary'] == 1;
                                $imageUrl = PRODUCT_UPLOAD_URL . $image['image_path'];
                            ?>
                            <div class="image-card <?php echo $isPrimary ? 'primary-image' : ''; ?>">
                                <div class="image-preview" onclick="openLightbox('<?php echo $imageUrl; ?>')" title="Click to enlarge">
                                    <img src="<?php echo $imageUrl; ?>" alt="Product image" loading="lazy"
                                         onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\\'fas fa-image\\'></i>'">
                                </div>
                                <div class="image-info">
                                    <span class="image-type-badge" style="color: <?php echo $isPrimary ? 'var(--admin-primary-light)' : 'var(--text-muted)'; ?>">
                                        <?php echo $isPrimary ? '⭐ PRIMARY' : '📷 Secondary'; ?>
                                    </span>
                                    <div class="image-actions">
                                        <?php if (!$isPrimary): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Set this image as the primary product image?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="set_primary_image">
                                                <input type="hidden" name="product_id" value="<?php echo $edit_product['id']; ?>">
                                                <input type="hidden" name="image_id" value="<?php echo $image['id']; ?>">
                                                <button type="submit" class="btn-icon star btn-xs" title="Set as Primary" style="width: 28px; height: 28px;">
                                                    <i class="fas fa-star"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this image permanently?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="delete_image">
                                            <input type="hidden" name="product_id" value="<?php echo $edit_product['id']; ?>">
                                            <input type="hidden" name="image_id" value="<?php echo $image['id']; ?>">
                                            <button type="submit" class="btn-icon delete btn-xs" title="Delete Image" style="width: 28px; height: 28px;">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color: var(--text-muted); font-size: 0.8rem; text-align: center; grid-column: 1 / -1;">
                                No images uploaded yet. Upload primary and secondary images above.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Pricing Tiers -->
                <div class="pricing-tiers-section" style="margin-top: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <h4 style="margin: 0;"><i class="fas fa-tags"></i> Pricing Tiers & Inventory</h4>
                        <span style="font-size: 0.7rem; color: var(--text-muted);">Each tier can be linked to a specific variant</span>
                    </div>
                    <div id="pricingTiersContainer">
                        <div class="tier-row tier-header">
                            <span>Type</span>
                            <span>Tier Name</span>
                            <span>Variant</span>
                            <span>Min Qty</span>
                            <span>Max Qty</span>
                            <span>Unit Price (GH₵)</span>
                            <span>Stock</span>
                            <span>Low Stock Alert</span>
                            <span></span>
                        </div>
                        <?php if ($edit_product && !empty($edit_product['pricing_tiers'])): ?>
                            <?php foreach ($edit_product['pricing_tiers'] as $tier): ?>
                            <div class="tier-row">
                                <select name="tier_pricing_type[]">
                                    <option value="retail" <?php echo $tier['pricing_type'] === 'retail' ? 'selected' : ''; ?>>Retail</option>
                                    <option value="wholesale" <?php echo $tier['pricing_type'] === 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                                </select>
                                <input type="text" name="tier_name[]" value="<?php echo htmlspecialchars($tier['tier_name']); ?>" placeholder="e.g., Standard">
                                <select name="tier_variant_id[]">
                                    <option value="">None</option>
                                    <?php if ($edit_product && !empty($edit_product['variants'])): ?>
                                        <?php foreach ($edit_product['variants'] as $variant): ?>
                                            <option value="<?php echo $variant['id']; ?>" <?php echo ($tier['product_variant_id'] == $variant['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($variant['variant_name'] . ': ' . $variant['variant_value']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <input type="number" name="tier_min_quantity[]" value="<?php echo $tier['min_quantity']; ?>" min="1" required>
                                <input type="number" name="tier_max_quantity[]" value="<?php echo $tier['max_quantity']; ?>" placeholder="∞">
                                <input type="number" name="tier_unit_price[]" value="<?php echo $tier['unit_price']; ?>" step="0.01" placeholder="0.00" required>
                                <input type="number" name="tier_stock_quantity[]" value="<?php echo $tier['stock_quantity']; ?>" min="0">
                                <input type="number" name="tier_low_stock_threshold[]" value="<?php echo $tier['low_stock_threshold']; ?>" min="1">
                                <input type="hidden" name="tier_id[]" value="<?php echo $tier['id']; ?>">
                                <button type="button" class="btn-remove-tier" onclick="this.closest('.tier-row').remove()" title="Remove tier">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="tier-row">
                                <select name="tier_pricing_type[]">
                                    <option value="retail" selected>Retail</option>
                                    <option value="wholesale">Wholesale</option>
                                </select>
                                <input type="text" name="tier_name[]" value="Standard" placeholder="e.g., Standard">
                                <select name="tier_variant_id[]">
                                    <option value="">None</option>
                                </select>
                                <input type="number" name="tier_min_quantity[]" value="1" min="1" required>
                                <input type="number" name="tier_max_quantity[]" placeholder="∞">
                                <input type="number" name="tier_unit_price[]" step="0.01" placeholder="0.00" required>
                                <input type="number" name="tier_stock_quantity[]" value="0" min="0">
                                <input type="number" name="tier_low_stock_threshold[]" value="10" min="1">
                                <input type="hidden" name="tier_id[]" value="">
                                <button type="button" class="btn-remove-tier" onclick="this.closest('.tier-row').remove()" title="Remove tier">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-add-tier" onclick="addPricingTier()">
                        <i class="fas fa-plus"></i> Add Pricing Tier
                    </button>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeProductModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveProductBtn">
                    <i class="fas fa-save"></i> <?php echo $edit_product ? 'Update Product' : 'Create Product'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal" style="max-width: 450px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: var(--admin-warning);"></i> Deactivate Product</h3>
            <button class="modal-close" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <p style="color: var(--text-secondary);">Are you sure you want to deactivate this product?</p>
            <p style="font-weight: 600; color: var(--text-primary); margin-top: 0.5rem;" id="deleteProductName"></p>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.75rem;">
                <i class="fas fa-info-circle"></i> The product will be hidden from the shop but can be reactivated later. Images and variants are preserved.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="product_id" id="deleteProductId" value="">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Deactivate Product
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Image Lightbox -->
<div class="image-lightbox" id="imageLightbox" onclick="closeLightbox()">
    <button class="close-lightbox" onclick="closeLightbox()">&times;</button>
    <img id="lightboxImage" src="" alt="Product Image Preview">
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script>
// ------------------------------------------------------------
// PRODUCT MODAL FUNCTIONS
// ------------------------------------------------------------
const productModal = document.getElementById('productModal');
const modalTitle = document.getElementById('modalTitle');

function openAddProductModal() {
    modalTitle.innerHTML = '<i class="fas fa-box-open"></i> Add New Product';
    document.getElementById('modalProductId').value = '';
    document.getElementById('productForm').reset();
    document.getElementById('prodActive').checked = true;
    
    <?php if (!$is_admin): ?>
    document.getElementById('prodVendor').value = '<?php echo $vendor_id; ?>';
    <?php endif; ?>
    
    // Clear all variant rows except the header
    const variantContainer = document.getElementById('variantsContainer');
    const variantHeader = variantContainer.querySelector('.variant-header');
    variantContainer.innerHTML = '';
    variantContainer.appendChild(variantHeader);
    addVariant();
    addVariant();
    
    // Clear all tier rows except the header
    const tierContainer = document.getElementById('pricingTiersContainer');
    const tierHeader = tierContainer.querySelector('.tier-header');
    tierContainer.innerHTML = '';
    tierContainer.appendChild(tierHeader);
    addPricingTier('retail', 'Standard', 1);
    
    // Reset gallery
    document.getElementById('existingImagesGallery').innerHTML = 
        '<p style="color: var(--text-muted); font-size: 0.8rem; text-align: center; grid-column: 1 / -1;">No images uploaded yet. Upload primary and secondary images above.</p>';
    
    productModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeProductModal() {
    productModal.classList.remove('active');
    document.body.style.overflow = '';
    // Remove the edit parameter from URL if present
    if (window.location.search.includes('edit=')) {
        window.history.replaceState({}, document.title, 'products.php');
    }
}

// Auto-open modal if editing
<?php if ($edit_product): ?>
document.addEventListener('DOMContentLoaded', function() {
    productModal.classList.add('active');
    document.body.style.overflow = 'hidden';
});
<?php endif; ?>

// ------------------------------------------------------------
// IMAGE LIGHTBOX
// ------------------------------------------------------------
const imageLightbox = document.getElementById('imageLightbox');
const lightboxImage = document.getElementById('lightboxImage');

function openLightbox(url) {
    lightboxImage.src = url;
    imageLightbox.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    imageLightbox.classList.remove('active');
    document.body.style.overflow = '';
}

// ------------------------------------------------------------
// VARIANT FUNCTIONS
// ------------------------------------------------------------
function addVariant(variantName = '', variantValue = '', skuSuffix = '', variantId = '') {
    const container = document.getElementById('variantsContainer');
    
    const variantRow = document.createElement('div');
    variantRow.className = 'variant-row';
    variantRow.innerHTML = `
        <input type="text" name="variant_name[]" value="${escapeHtml(variantName)}" placeholder="e.g., Size, Color">
        <input type="text" name="variant_value[]" value="${escapeHtml(variantValue)}" placeholder="e.g., Large, Red">
        <input type="text" name="variant_sku_suffix[]" value="${escapeHtml(skuSuffix)}" placeholder="e.g., -L, -RED">
        <input type="hidden" name="variant_id[]" value="${escapeHtml(variantId)}">
        <button type="button" class="btn-remove-variant" onclick="this.closest('.variant-row').remove()" title="Remove variant">
            <i class="fas fa-times-circle"></i>
        </button>
    `;
    
    container.appendChild(variantRow);
}

// ------------------------------------------------------------
// PRICING TIERS FUNCTIONS
// ------------------------------------------------------------
function addPricingTier(type = 'retail', name = 'Standard', minQty = 1, maxQty = '', unitPrice = '', 
                        stock = 0, lowStock = 10, tierId = '', variantId = '') {
    const container = document.getElementById('pricingTiersContainer');
    
    // Get available variants for dropdown
    const variantSelects = document.querySelectorAll('select[name="tier_variant_id[]"]');
    let variantOptions = '';
    <?php if ($edit_product && !empty($edit_product['variants'])): ?>
        variantOptions = `<option value="">None</option>
        <?php foreach ($edit_product['variants'] as $v): ?>
            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['variant_name'] . ': ' . $v['variant_value']); ?></option>
        <?php endforeach; ?>`;
    <?php else: ?>
        variantOptions = `<option value="">None</option>`;
    <?php endif; ?>
    
    const tierRow = document.createElement('div');
    tierRow.className = 'tier-row';
    tierRow.innerHTML = `
        <select name="tier_pricing_type[]">
            <option value="retail" ${type === 'retail' ? 'selected' : ''}>Retail</option>
            <option value="wholesale" ${type === 'wholesale' ? 'selected' : ''}>Wholesale</option>
        </select>
        <input type="text" name="tier_name[]" value="${escapeHtml(name)}" placeholder="e.g., Standard">
        <select name="tier_variant_id[]">
            ${variantOptions}
        </select>
        <input type="number" name="tier_min_quantity[]" value="${minQty}" min="1" required>
        <input type="number" name="tier_max_quantity[]" value="${maxQty}" placeholder="∞">
        <input type="number" name="tier_unit_price[]" value="${unitPrice}" step="0.01" placeholder="0.00" required>
        <input type="number" name="tier_stock_quantity[]" value="${stock}" min="0">
        <input type="number" name="tier_low_stock_threshold[]" value="${lowStock}" min="1">
        <input type="hidden" name="tier_id[]" value="${tierId}">
        <button type="button" class="btn-remove-tier" onclick="this.closest('.tier-row').remove()" title="Remove tier">
            <i class="fas fa-times-circle"></i>
        </button>
    `;
    
    container.appendChild(tierRow);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ------------------------------------------------------------
// DELETE CONFIRMATION
// ------------------------------------------------------------
const deleteModal = document.getElementById('deleteModal');

function confirmDelete(productId, productName) {
    document.getElementById('deleteProductId').value = productId;
    document.getElementById('deleteProductName').textContent = productName;
    deleteModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    deleteModal.classList.remove('active');
    document.body.style.overflow = '';
}

// Close modals on outside click
window.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// Close on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        productModal.classList.remove('active');
        deleteModal.classList.remove('active');
        imageLightbox.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// ------------------------------------------------------------
// AUTO-GENERATE SLUG FROM NAME
// ------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {
    const nameInput = document.getElementById('prodName');
    const slugInput = document.getElementById('prodSlug');
    
    if (nameInput && slugInput) {
        // Set initial auto-generated flag
        if (slugInput.value) {
            slugInput.dataset.autoGenerated = 'false';
        } else {
            slugInput.dataset.autoGenerated = 'true';
        }
        
        nameInput.addEventListener('input', function() {
            if (!slugInput.value || slugInput.dataset.autoGenerated === 'true') {
                slugInput.value = this.value
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '');
                slugInput.dataset.autoGenerated = 'true';
            }
        });
        
        slugInput.addEventListener('input', function() {
            this.dataset.autoGenerated = 'false';
        });
    }
});

// Auto-close alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 500);
        });
    }, 5000);
});
</script>

</body>
</html>