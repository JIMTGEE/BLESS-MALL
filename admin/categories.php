<?php
/**
 * Bless Mall - Admin Categories Management
 * Premium Admin Dashboard - Category CRUD Operations
 * 
 * Features:
 * - Full CRUD (Create, Read, Update, Delete) for categories
 * - Parent/Sub-category hierarchy management
 * - Image upload with preview (store in uploads/categories/)
 * - Drag-and-drop sorting (visual)
 * - Bulk actions (activate, deactivate, delete)
 * - Search and filter capabilities
 * - Product count per category
 * - Inline editing with AJAX
 * - Activity logging
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP & AUTHENTICATION
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) session_start();

// Load config if not already loaded
if (!defined('BLESSMALL_CONFIG_INCLUDED')) {
    require_once dirname(__DIR__) . '/includes/config.php';
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Only allow admin (role_id = 1) access
$role_id = (int)$_SESSION['role_id'];
if ($role_id !== 1) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

// ------------------------------------------------------------
// 2. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ------------------------------------------------------------
// 3. HANDLE AJAX & FORM ACTIONS
// ------------------------------------------------------------
$message = '';
$message_type = '';
$edit_category = null;

// ----- Handle AJAX Requests (unchanged) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];

    switch ($action) {
        case 'toggle_status':
            $category_id = (int)($_POST['category_id'] ?? 0);
            $status = (int)($_POST['is_active'] ?? 0);
            if ($category_id > 0) {
                $stmt = $conn->prepare("UPDATE categories SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $status, $category_id);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Status updated'];
                } else {
                    $response = ['success' => false, 'message' => 'Update failed'];
                }
                $stmt->close();
            }
            break;

        case 'quick_update':
            $category_id = (int)($_POST['category_id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = trim($_POST['value'] ?? '');
            $allowed_fields = ['name', 'slug', 'description', 'icon'];
            if ($category_id > 0 && in_array($field, $allowed_fields)) {
                $stmt = $conn->prepare("UPDATE categories SET {$field} = ? WHERE id = ?");
                $stmt->bind_param("si", $value, $category_id);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Updated successfully'];
                } else {
                    $response = ['success' => false, 'message' => $conn->error];
                }
                $stmt->close();
            }
            break;

        case 'delete_category':
            $category_id = (int)($_POST['category_id'] ?? 0);
            if ($category_id > 0) {
                // Check if category has subcategories
                $check = $conn->query("SELECT COUNT(*) as cnt FROM categories WHERE parent_id = $category_id");
                $has_children = $check->fetch_assoc()['cnt'] > 0;

                // Check if category has products
                $check2 = $conn->query("SELECT COUNT(*) as cnt FROM products WHERE category_id = $category_id");
                $has_products = $check2->fetch_assoc()['cnt'] > 0;

                if ($has_children) {
                    $response = ['success' => false, 'message' => 'Cannot delete: category has subcategories'];
                } elseif ($has_products) {
                    $response = ['success' => false, 'message' => 'Cannot delete: category has products'];
                } else {
                    // Get image path before deletion
                    $img_query = $conn->query("SELECT image FROM categories WHERE id = $category_id");
                    $img_row = $img_query->fetch_assoc();
                    $image_path = $img_row['image'] ?? null;

                    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                    $stmt->bind_param("i", $category_id);
                    if ($stmt->execute()) {
                        // Delete image file if exists
                        if (!empty($image_path)) {
                            $full_path = dirname(__DIR__) . '/uploads/categories/' . basename($image_path);
                            if (file_exists($full_path)) {
                                unlink($full_path);
                            }
                        }
                        $response = ['success' => true, 'message' => 'Category deleted'];
                    }
                    $stmt->close();
                }
            }
            break;

        case 'bulk_action':
            $bulk_action = $_POST['bulk_action'] ?? '';
            $category_ids = $_POST['category_ids'] ?? [];
            if (!empty($category_ids) && is_array($category_ids)) {
                $ids = array_map('intval', $category_ids);
                $id_list = implode(',', $ids);
                switch ($bulk_action) {
                    case 'activate':
                        $conn->query("UPDATE categories SET is_active = 1 WHERE id IN ($id_list)");
                        $response = ['success' => true, 'message' => count($ids) . ' categories activated'];
                        break;
                    case 'deactivate':
                        $conn->query("UPDATE categories SET is_active = 0 WHERE id IN ($id_list)");
                        $response = ['success' => true, 'message' => count($ids) . ' categories deactivated'];
                        break;
                    case 'delete':
                        // Delete associated images first
                        $img_result = $conn->query("SELECT image FROM categories WHERE id IN ($id_list)");
                        while ($row = $img_result->fetch_assoc()) {
                            if (!empty($row['image'])) {
                                $full_path = dirname(__DIR__) . '/uploads/categories/' . basename($row['image']);
                                if (file_exists($full_path)) {
                                    unlink($full_path);
                                }
                            }
                        }
                        $conn->query("DELETE FROM categories WHERE id IN ($id_list) AND id NOT IN (SELECT DISTINCT parent_id FROM categories WHERE parent_id IS NOT NULL)");
                        $response = ['success' => true, 'message' => 'Categories processed'];
                        break;
                }
            }
            break;
    }

    echo json_encode($response);
    exit;
}

// ----- Handle Regular Form Submission (with file upload) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? 'fa-tag');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $remove_image = isset($_POST['remove_image']) ? true : false;

    // Auto-generate slug if empty
    if (empty($slug) && !empty($name)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $slug = trim($slug, '-');
    }

    // Validation
    $errors = [];
    if (empty($name)) $errors[] = "Category name is required";
    if (empty($slug)) $errors[] = "Slug is required";

    // Check for duplicate slug
    $slug_check = $conn->prepare("SELECT id FROM categories WHERE slug = ? AND id != ?");
    $slug_check->bind_param("si", $slug, $category_id);
    $slug_check->execute();
    if ($slug_check->get_result()->num_rows > 0) {
        $errors[] = "A category with this slug already exists";
    }
    $slug_check->close();

    // Handle file upload
    $image_path = null;
    $upload_error = null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 2 * 1024 * 1024; // 2MB

        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = "Invalid image type. Allowed: JPG, PNG, GIF, WebP.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "Image size exceeds 2MB limit.";
        } else {
            // Determine upload directory
            $upload_dir = dirname(__DIR__) . '/uploads/categories/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'category_' . ($category_id > 0 ? $category_id : time()) . '_' . uniqid() . '.' . $ext;
            $destination = $upload_dir . $filename;

            // Move file
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $image_path = 'categories/' . $filename; // relative path to store in DB
            } else {
                $errors[] = "Failed to upload image. Check folder permissions.";
            }
        }
    } elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = "Error uploading file: " . $_FILES['image']['error'];
    }

    // If remove_image is checked, we'll delete existing image later
    if ($remove_image) {
        $image_path = null; // will trigger deletion of old file
    }

    if (empty($errors)) {
        if ($category_id > 0) {
            // Update existing category
            // Fetch old image to delete if new image uploaded or removal requested
            $old_img_result = $conn->query("SELECT image FROM categories WHERE id = $category_id");
            $old_img = $old_img_result->fetch_assoc()['image'] ?? null;

            $stmt = $conn->prepare("UPDATE categories SET name=?, slug=?, parent_id=?, description=?, icon=?, is_active=? WHERE id=?");
            $stmt->bind_param("ssissii", $name, $slug, $parent_id, $description, $icon, $is_active, $category_id);

            if ($stmt->execute()) {
                // Handle image update
                if ($image_path !== null) {
                    // New image uploaded – delete old if exists
                    if (!empty($old_img)) {
                        $old_full = dirname(__DIR__) . '/uploads/' . $old_img;
                        if (file_exists($old_full)) {
                            unlink($old_full);
                        }
                    }
                    // Update DB with new path
                    $conn->query("UPDATE categories SET image = '$image_path' WHERE id = $category_id");
                } elseif ($remove_image && !empty($old_img)) {
                    // Remove image
                    $old_full = dirname(__DIR__) . '/uploads/' . $old_img;
                    if (file_exists($old_full)) {
                        unlink($old_full);
                    }
                    $conn->query("UPDATE categories SET image = NULL WHERE id = $category_id");
                }
                $message = "Category updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error: " . $conn->error;
                $message_type = "error";
            }
            $stmt->close();
        } else {
            // Create new category
            $stmt = $conn->prepare("INSERT INTO categories (name, slug, parent_id, description, icon, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssissi", $name, $slug, $parent_id, $description, $icon, $is_active);

            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                // Handle image upload for new category
                if ($image_path !== null) {
                    // Update image with new ID
                    $conn->query("UPDATE categories SET image = '$image_path' WHERE id = $new_id");
                }
                $message = "Category created successfully!";
                $message_type = "success";
                // Clear form
                $_POST = [];
            } else {
                $message = "Error: " . $conn->error;
                $message_type = "error";
            }
            $stmt->close();
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// ----- Handle Edit (Load data) -----
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_category = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ------------------------------------------------------------
// 4. FETCH ALL CATEGORIES WITH STATS
// ------------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$parent_filter = isset($_GET['parent']) ? $_GET['parent'] : 'all';

$where = [];
if (!empty($search)) {
    $search_escaped = $conn->real_escape_string($search);
    $where[] = "(c.name LIKE '%$search_escaped%' OR c.slug LIKE '%$search_escaped%' OR c.description LIKE '%$search_escaped%')";
}
if ($status_filter === 'active') {
    $where[] = "c.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where[] = "c.is_active = 0";
}
if ($parent_filter === 'parent') {
    $where[] = "c.parent_id IS NULL";
} elseif ($parent_filter === 'child') {
    $where[] = "c.parent_id IS NOT NULL";
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$query = "
    SELECT 
        c.*,
        COALESCE(p_count.product_count, 0) as product_count,
        COALESCE(child_count.child_count, 0) as subcategory_count,
        parent.name as parent_name
    FROM categories c
    LEFT JOIN (
        SELECT category_id, COUNT(*) as product_count 
        FROM products 
        WHERE is_active = 1 
        GROUP BY category_id
    ) p_count ON c.id = p_count.category_id
    LEFT JOIN (
        SELECT parent_id, COUNT(*) as child_count 
        FROM categories 
        GROUP BY parent_id
    ) child_count ON c.id = child_count.parent_id
    LEFT JOIN categories parent ON c.parent_id = parent.id
    $where_clause
    ORDER BY COALESCE(c.parent_id, c.id), c.parent_id IS NULL DESC, c.name ASC
";

$result = $conn->query($query);
$categories = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Get parent categories (for dropdown)
$parents = [];
$parent_query = "SELECT id, name FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY name";
$parent_result = $conn->query($parent_query);
if ($parent_result && $parent_result->num_rows > 0) {
    while ($row = $parent_result->fetch_assoc()) {
        $parents[] = $row;
    }
}

// Stats
$total_categories = count($categories);
$active_categories = 0;
$inactive_categories = 0;
$parent_categories = 0;
$child_categories = 0;
$total_products_in_categories = 0;

foreach ($categories as $cat) {
    if ($cat['is_active']) $active_categories++;
    else $inactive_categories++;
    if ($cat['parent_id']) $child_categories++;
    else $parent_categories++;
    $total_products_in_categories += $cat['product_count'];
}

// Page title
$page_title = "Categories Management - Bless Mall Admin";
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $page_title; ?></title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        /* ========== ADMIN THEME (Consistent with your admin header/footer) ========== */
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
            --admin-primary-glow: rgba(59, 130, 246, 0.15);
            
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
            
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.5);
            
            --transition-fast: 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* ========== PAGE SPECIFIC STYLES ========== */
        .categories-container {
            padding: 1.5rem;
        }
        
        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            transition: var(--transition);
        }
        
        .stat-card:hover {
            border-color: var(--admin-primary);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.1);
        }
        
        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        
        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        
        .stat-icon.blue { background: var(--admin-primary-glow); color: var(--admin-primary); }
        .stat-icon.green { background: var(--admin-success-bg); color: var(--admin-success); }
        .stat-icon.amber { background: var(--admin-warning-bg); color: var(--admin-warning); }
        .stat-icon.red { background: var(--admin-danger-bg); color: var(--admin-danger); }
        
        .stat-value {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Toolbar */
        .toolbar {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 1.1rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid transparent;
            text-decoration: none;
            white-space: nowrap;
            font-family: 'Inter', sans-serif;
        }
        
        .btn-primary {
            background: var(--admin-primary);
            color: white;
            border-color: var(--admin-primary);
        }
        
        .btn-primary:hover {
            background: var(--admin-primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--text-secondary);
            border-color: var(--admin-border-light);
        }
        
        .btn-outline:hover {
            border-color: var(--admin-primary);
            color: var(--text-primary);
            background: var(--admin-surface-hover);
        }
        
        .btn-danger {
            background: transparent;
            color: var(--admin-danger);
            border-color: var(--admin-danger);
        }
        
        .btn-danger:hover {
            background: var(--admin-danger-bg);
        }
        
        .btn-sm {
            padding: 0.35rem 0.7rem;
            font-size: 0.7rem;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
        }
        
        /* Search */
        .search-box {
            position: relative;
        }
        
        .search-box input {
            padding: 0.55rem 0.55rem 0.55rem 2.5rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 0.85rem;
            width: 260px;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
        }
        
        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        /* Filter Selects */
        .filter-select {
            padding: 0.55rem 2rem 0.55rem 0.75rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 0.8rem;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748B' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.6rem center;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--admin-primary);
        }
        
        /* Table */
        .table-container {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            overflow: hidden;
        }
        
        .categories-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .categories-table thead {
            background: var(--admin-bg-light);
        }
        
        .categories-table th {
            padding: 0.85rem 1rem;
            text-align: left;
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 600;
            border-bottom: 1px solid var(--admin-border);
            white-space: nowrap;
        }
        
        .categories-table td {
            padding: 0.85rem 1rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--admin-border);
        }
        
        .categories-table tbody tr {
            transition: var(--transition-fast);
        }
        
        .categories-table tbody tr:hover {
            background: var(--admin-surface-hover);
        }
        
        .categories-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .categories-table .checkbox-cell {
            width: 40px;
        }
        
        .categories-table input[type="checkbox"] {
            accent-color: var(--admin-primary);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        /* Category Name Cell */
        .category-name-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .category-icon-cell {
            width: 38px;
            height: 38px;
            background: var(--admin-bg);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--admin-primary);
            flex-shrink: 0;
            border: 1px solid var(--admin-border);
        }
        
        .category-info {
            display: flex;
            flex-direction: column;
        }
        
        .category-info .name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
        }
        
        .category-info .slug {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
        }
        
        /* Category Image Thumbnail */
        .category-thumb {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-sm);
            overflow: hidden;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .category-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .category-thumb .no-image {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-align: center;
            padding: 0.2rem;
        }
        
        .subcategory-indent {
            padding-left: 2rem;
            position: relative;
        }
        
        .subcategory-indent::before {
            content: '└';
            position: absolute;
            left: 0.5rem;
            color: var(--admin-border-light);
        }
        
        /* Status Toggle */
        .status-toggle {
            position: relative;
            width: 44px;
            height: 24px;
            display: inline-block;
        }
        
        .status-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .status-toggle .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--admin-bg-lighter);
            border-radius: var(--radius-full);
            transition: var(--transition);
        }
        
        .status-toggle .slider::before {
            content: '';
            position: absolute;
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: var(--transition);
        }
        
        .status-toggle input:checked + .slider {
            background: var(--admin-success);
        }
        
        .status-toggle input:checked + .slider::before {
            transform: translateX(20px);
        }
        
        /* Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: var(--radius-full);
            font-size: 0.65rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-products {
            background: var(--admin-primary-glow);
            color: var(--admin-primary-light);
        }
        
        .badge-children {
            background: rgba(139, 92, 246, 0.15);
            color: #A78BFA;
        }
        
        /* Actions */
        .actions-cell {
            display: flex;
            gap: 0.35rem;
        }
        
        .action-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--admin-border);
            background: var(--admin-bg);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition-fast);
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        
        .action-btn:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
            background: var(--admin-surface-hover);
        }
        
        .action-btn.delete:hover {
            border-color: var(--admin-danger);
            color: var(--admin-danger);
            background: var(--admin-danger-bg);
        }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 650px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: modalIn 0.3s ease;
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--admin-surface);
            z-index: 1;
        }
        
        .modal-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-close {
            width: 32px;
            height: 32px;
            border: 1px solid var(--admin-border);
            background: var(--admin-bg);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-muted);
            transition: var(--transition-fast);
        }
        
        .modal-close:hover {
            color: var(--text-primary);
            border-color: var(--admin-primary);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--admin-border);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            position: sticky;
            bottom: 0;
            background: var(--admin-surface);
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 1.25rem;
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
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
            transition: var(--transition-fast);
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            accent-color: var(--admin-primary);
        }
        
        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
            font-weight: 500;
        }
        
        .form-hint {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.3rem;
        }
        
        /* File upload preview */
        .file-upload-preview {
            margin-top: 0.5rem;
            width: 100%;
            max-width: 200px;
            aspect-ratio: 1/1;
            border: 2px dashed var(--admin-border);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: var(--admin-bg);
        }
        
        .file-upload-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .file-upload-preview .empty {
            color: var(--text-muted);
            font-size: 0.8rem;
            text-align: center;
            padding: 0.5rem;
        }
        
        /* Alert Messages */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.85rem;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        .alert-info {
            background: var(--admin-primary-glow);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: var(--admin-primary-light);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }
        
        .empty-state i {
            font-size: 3.5rem;
            color: var(--text-muted);
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        
        .empty-state h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        /* Bulk Actions Bar */
        .bulk-actions-bar {
            display: none;
            background: var(--admin-primary-glow);
            border: 1px solid var(--admin-primary);
            border-radius: var(--radius-md);
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            align-items: center;
            gap: 1rem;
            animation: slideDown 0.3s ease;
        }
        
        .bulk-actions-bar.active {
            display: flex;
        }
        
        .bulk-actions-bar span {
            color: var(--admin-primary-light);
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        /* Inline Edit */
        .inline-edit {
            cursor: pointer;
            padding: 0.15rem 0.3rem;
            border-radius: 4px;
            transition: var(--transition-fast);
        }
        
        .inline-edit:hover {
            background: var(--admin-primary-glow);
        }
        
        .inline-edit-input {
            background: var(--admin-bg) !important;
            border: 1px solid var(--admin-primary) !important;
            padding: 0.3rem 0.5rem !important;
            border-radius: 4px !important;
            color: var(--text-primary) !important;
            font-size: 0.85rem !important;
            width: 100%;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .categories-table {
                font-size: 0.8rem;
            }
            
            .categories-table th:nth-child(5),
            .categories-table td:nth-child(5),
            .categories-table th:nth-child(6),
            .categories-table td:nth-child(6) {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .toolbar-left,
            .toolbar-right {
                justify-content: center;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .categories-table th:nth-child(3),
            .categories-table td:nth-child(3) {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    
    <!-- Main Content -->
    <div class="categories-container">
        
        <!-- Breadcrumb -->
        <div class="admin-breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right" style="font-size:0.6rem; margin: 0 4px;"></i>
            <a href="products.php">Products</a>
            <i class="fas fa-chevron-right" style="font-size:0.6rem; margin: 0 4px;"></i>
            <span class="current">Categories</span>
        </div>
        
        <!-- Page Header -->
        <div class="admin-page-header">
            <h1><i class="fas fa-th-large"></i> Categories Management</h1>
            <p>Create, edit, and organize your product categories and subcategories</p>
        </div>
        
        <!-- Alert Messages -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo $message; ?>
        </div>
        <?php endif; ?>
        
        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_categories; ?></div>
                        <div class="stat-label">Total Categories</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-layer-group"></i></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $active_categories; ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $parent_categories; ?></div>
                        <div class="stat-label">Parent Categories</div>
                    </div>
                    <div class="stat-icon amber"><i class="fas fa-folder-tree"></i></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $child_categories; ?></div>
                        <div class="stat-label">Subcategories</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-indent"></i></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_products_in_categories; ?></div>
                        <div class="stat-label">Products Assigned</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-boxes"></i></div>
                </div>
            </div>
        </div>
        
        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <button class="btn btn-primary" onclick="openCategoryModal()">
                    <i class="fas fa-plus"></i> Add New Category
                </button>
                
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search categories..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            
            <div class="toolbar-right">
                <select class="filter-select" id="statusFilter" onchange="applyFilters()">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                
                <select class="filter-select" id="parentFilter" onchange="applyFilters()">
                    <option value="all" <?php echo $parent_filter === 'all' ? 'selected' : ''; ?>>All Levels</option>
                    <option value="parent" <?php echo $parent_filter === 'parent' ? 'selected' : ''; ?>>Parent Categories</option>
                    <option value="child" <?php echo $parent_filter === 'child' ? 'selected' : ''; ?>>Subcategories</option>
                </select>
                
                <button class="btn btn-outline btn-sm" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
        
        <!-- Bulk Actions Bar -->
        <div class="bulk-actions-bar" id="bulkActionsBar">
            <span id="selectedCount">0 selected</span>
            <button class="btn btn-outline btn-sm" onclick="bulkAction('activate')">
                <i class="fas fa-check-circle"></i> Activate
            </button>
            <button class="btn btn-outline btn-sm" onclick="bulkAction('deactivate')">
                <i class="fas fa-pause-circle"></i> Deactivate
            </button>
            <button class="btn btn-danger btn-sm" onclick="bulkAction('delete')">
                <i class="fas fa-trash"></i> Delete Selected
            </button>
            <button class="btn btn-outline btn-sm" onclick="clearSelection()">
                Clear Selection
            </button>
        </div>
        
        <!-- Categories Table -->
        <div class="table-container">
            <?php if (!empty($categories)): ?>
            <table class="categories-table">
                <thead>
                    <tr>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                        </th>
                        <th>Image</th>
                        <th>Category</th>
                        <th>Parent</th>
                        <th>Products</th>
                        <th>Subcategories</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): 
                        $has_parent = !empty($category['parent_id']);
                        $is_active = (bool)$category['is_active'];
                        $image_url = !empty($category['image']) ? BASE_URL . 'uploads/' . $category['image'] : null;
                    ?>
                    <tr class="<?php echo $has_parent ? 'subcategory-indent' : ''; ?>" data-id="<?php echo $category['id']; ?>">
                        <td class="checkbox-cell">
                            <input type="checkbox" class="category-checkbox" value="<?php echo $category['id']; ?>" onchange="updateBulkBar()">
                        </td>
                        <td>
                            <div class="category-thumb">
                                <?php if ($image_url): ?>
                                    <img src="<?php echo htmlspecialchars($image_url); ?>" alt="<?php echo htmlspecialchars($category['name']); ?>">
                                <?php else: ?>
                                    <div class="no-image">No image</div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="category-name-cell">
                                <div class="category-icon-cell">
                                    <i class="fas <?php echo htmlspecialchars($category['icon'] ?? 'fa-tag'); ?>"></i>
                                </div>
                                <div class="category-info">
                                    <span class="name inline-edit" data-id="<?php echo $category['id']; ?>" data-field="name" title="Click to edit">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </span>
                                    <span class="slug"><?php echo htmlspecialchars($category['slug']); ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($category['parent_name']): ?>
                                <span class="badge badge-children">
                                    <i class="fas fa-level-up-alt fa-rotate-90"></i>
                                    <?php echo htmlspecialchars($category['parent_name']); ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 0.75rem;">— Root —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-products">
                                <i class="fas fa-box"></i>
                                <?php echo $category['product_count']; ?> products
                            </span>
                        </td>
                        <td>
                            <?php if ($category['subcategory_count'] > 0): ?>
                                <span class="badge badge-children">
                                    <i class="fas fa-folder"></i>
                                    <?php echo $category['subcategory_count']; ?> subs
                                </span>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 0.75rem;">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <label class="status-toggle" title="Toggle active status">
                                <input type="checkbox" <?php echo $is_active ? 'checked' : ''; ?> 
                                       onchange="toggleStatus(<?php echo $category['id']; ?>, this.checked)">
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td>
                            <div class="actions-cell">
                                <button class="action-btn" title="Edit Category" onclick="editCategory(<?php echo $category['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-btn" title="View Products" onclick="viewProducts('<?php echo $category['slug']; ?>')">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="action-btn delete" title="Delete Category" onclick="confirmDelete(<?php echo $category['id']; ?>, '<?php echo addslashes(htmlspecialchars($category['name'])); ?>')">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h4>No categories found</h4>
                <p>Start by creating your first product category</p>
                <button class="btn btn-primary" style="margin-top: 1rem;" onclick="openCategoryModal()">
                    <i class="fas fa-plus"></i> Create First Category
                </button>
            </div>
            <?php endif; ?>
        </div>
        
    </div>
    
    <!-- ==================== CATEGORY MODAL ==================== -->
    <div class="modal-overlay" id="categoryModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-th-large"></i> <span id="modalTitle">Add New Category</span></h3>
                <button class="modal-close" onclick="closeCategoryModal()">&times;</button>
            </div>
            <form id="categoryForm" method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="category_id" id="category_id" value="">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Category Name <span class="required">*</span></label>
                        <input type="text" name="name" id="cat_name" required 
                               placeholder="e.g., Electronics, Fashion, Home & Kitchen"
                               oninput="autoGenerateSlug()">
                    </div>
                    
                    <div class="form-group">
                        <label>Slug <span class="required">*</span></label>
                        <input type="text" name="slug" id="cat_slug" required 
                               placeholder="e.g., electronics, fashion, home-kitchen">
                        <p class="form-hint">URL-friendly name. Auto-generated from category name.</p>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Parent Category</label>
                            <select name="parent_id" id="parent_id">
                                <option value="">None (Top-Level Category)</option>
                                <?php foreach ($parents as $parent): ?>
                                    <option value="<?php echo $parent['id']; ?>" 
                                        <?php echo (isset($edit_category) && $edit_category['parent_id'] == $parent['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($parent['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-hint">Leave empty if this is a main category</p>
                        </div>
                        
                        <div class="form-group">
                            <label>Icon (Font Awesome class)</label>
                            <input type="text" name="icon" id="cat_icon" placeholder="e.g., fa-laptop, fa-tshirt"
                                   value="<?php echo htmlspecialchars($edit_category['icon'] ?? 'fa-tag'); ?>">
                            <p class="form-hint">Enter Font Awesome class like <code>fa-laptop</code></p>
                        </div>
                    </div>
                    
                    <!-- Image Upload -->
                    <div class="form-group">
                        <label>Category Image</label>
                        <input type="file" name="image" id="cat_image" accept="image/*" onchange="previewImage(event)">
                        <p class="form-hint">Allowed: JPG, PNG, GIF, WebP. Max size: 2MB.</p>
                        <div class="file-upload-preview" id="imagePreview">
                            <?php if (isset($edit_category) && !empty($edit_category['image'])): ?>
                                <img src="<?php echo BASE_URL . 'uploads/' . $edit_category['image']; ?>" alt="Current image">
                            <?php else: ?>
                                <div class="empty">No image selected</div>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($edit_category) && !empty($edit_category['image'])): ?>
                        <div class="checkbox-group" style="margin-top: 0.5rem;">
                            <input type="checkbox" name="remove_image" id="remove_image" value="1">
                            <label for="remove_image">Remove current image</label>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="cat_description" placeholder="Brief description of this category (optional)"><?php echo htmlspecialchars($edit_category['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active" value="1" 
                                   <?php echo (!isset($edit_category) || $edit_category['is_active']) ? 'checked' : ''; ?>>
                            <label for="is_active">Active (visible on storefront)</label>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeCategoryModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- ==================== DELETE CONFIRMATION MODAL ==================== -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle" style="color: var(--admin-danger);"></i> Delete Category</h3>
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color: var(--text-secondary); margin-bottom: 0.5rem;">
                    Are you sure you want to delete <strong id="deleteCategoryName" style="color: var(--text-primary);"></strong>?
                </p>
                <p style="color: var(--admin-danger); font-size: 0.8rem;">
                    <i class="fas fa-exclamation-circle"></i> This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn" onclick="deleteCategory()">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/footer.php'; ?>
    
    <script>
        let deleteCategoryId = null;
        
        // ==================== MODAL FUNCTIONS ====================
        function openCategoryModal() {
            document.getElementById('modalTitle').textContent = 'Add New Category';
            document.getElementById('category_id').value = '';
            document.getElementById('categoryForm').reset();
            document.getElementById('is_active').checked = true;
            // Reset image preview
            const preview = document.getElementById('imagePreview');
            preview.innerHTML = '<div class="empty">No image selected</div>';
            document.getElementById('remove_image')?.parentElement?.style?.display = 'none';
            document.getElementById('categoryModal').classList.add('active');
        }
        
        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('active');
        }
        
        function editCategory(id) {
            // Fetch category data and populate modal
            fetch(`categories.php?edit=${id}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(() => {
                window.location.href = `categories.php?edit=${id}#categoryModal`;
                document.getElementById('categoryModal').classList.add('active');
            });
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            deleteCategoryId = null;
        }
        
        function confirmDelete(id, name) {
            deleteCategoryId = id;
            document.getElementById('deleteCategoryName').textContent = name;
            document.getElementById('deleteModal').classList.add('active');
        }
        
        // ==================== IMAGE PREVIEW ====================
        function previewImage(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('imagePreview');
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                };
                reader.readAsDataURL(file);
            }
        }
        
        // ==================== CRUD OPERATIONS ====================
        function deleteCategory() {
            if (!deleteCategoryId) return;
            
            const formData = new FormData();
            formData.append('action', 'delete_category');
            formData.append('category_id', deleteCategoryId);
            
            fetch('categories.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeDeleteModal();
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message, 'error');
                    closeDeleteModal();
                }
            })
            .catch(error => {
                showToast('An error occurred', 'error');
                closeDeleteModal();
            });
        }
        
        function toggleStatus(categoryId, isActive) {
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('category_id', categoryId);
            formData.append('is_active', isActive ? 1 : 0);
            
            fetch('categories.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Category ${isActive ? 'activated' : 'deactivated'}`, 'success');
                } else {
                    showToast(data.message, 'error');
                    // Revert toggle
                    const checkbox = document.querySelector(`tr[data-id="${categoryId}"] .status-toggle input`);
                    if (checkbox) checkbox.checked = !isActive;
                }
            });
        }
        
        // ==================== BULK ACTIONS ====================
        function updateBulkBar() {
            const checked = document.querySelectorAll('.category-checkbox:checked');
            const count = checked.length;
            const bar = document.getElementById('bulkActionsBar');
            const countEl = document.getElementById('selectedCount');
            
            if (count > 0) {
                bar.classList.add('active');
                countEl.textContent = `${count} selected`;
            } else {
                bar.classList.remove('active');
            }
            
            // Update select all checkbox
            const selectAll = document.getElementById('selectAll');
            const totalCheckboxes = document.querySelectorAll('.category-checkbox').length;
            selectAll.checked = count === totalCheckboxes && count > 0;
            selectAll.indeterminate = count > 0 && count < totalCheckboxes;
        }
        
        function toggleSelectAll(checkbox) {
            document.querySelectorAll('.category-checkbox').forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateBulkBar();
        }
        
        function clearSelection() {
            document.querySelectorAll('.category-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateBulkBar();
        }
        
        function bulkAction(action) {
            const checked = document.querySelectorAll('.category-checkbox:checked');
            const ids = Array.from(checked).map(cb => cb.value);
            
            if (ids.length === 0) return;
            
            if (action === 'delete' && !confirm(`Delete ${ids.length} categories? This cannot be undone.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'bulk_action');
            formData.append('bulk_action', action);
            ids.forEach(id => formData.append('category_ids[]', id));
            
            fetch('categories.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message, 'error');
                }
            });
        }
        
        // ==================== INLINE EDITING ====================
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('inline-edit')) {
                const el = e.target;
                const id = el.dataset.id;
                const field = el.dataset.field;
                const currentValue = el.textContent.trim();
                
                const input = document.createElement('input');
                input.type = 'text';
                input.value = currentValue;
                input.className = 'inline-edit-input';
                
                el.replaceWith(input);
                input.focus();
                input.select();
                
                const saveEdit = () => {
                    const newValue = input.value.trim();
                    if (newValue && newValue !== currentValue) {
                        const formData = new FormData();
                        formData.append('action', 'quick_update');
                        formData.append('category_id', id);
                        formData.append('field', field);
                        formData.append('value', newValue);
                        
                        fetch('categories.php', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                input.replaceWith(createInlineEditElement(id, field, newValue));
                                showToast('Updated successfully', 'success');
                            } else {
                                input.replaceWith(createInlineEditElement(id, field, currentValue));
                                showToast(data.message, 'error');
                            }
                        });
                    } else {
                        input.replaceWith(createInlineEditElement(id, field, currentValue));
                    }
                };
                
                input.addEventListener('blur', saveEdit);
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        input.blur();
                    }
                    if (e.key === 'Escape') {
                        input.value = currentValue;
                        input.blur();
                    }
                });
            }
        });
        
        function createInlineEditElement(id, field, value) {
            const span = document.createElement('span');
            span.className = 'inline-edit';
            span.dataset.id = id;
            span.dataset.field = field;
            span.textContent = value;
            span.title = 'Click to edit';
            return span;
        }
        
        // ==================== HELPERS ====================
        function autoGenerateSlug() {
            const name = document.getElementById('cat_name').value;
            const slugInput = document.getElementById('cat_slug');
            
            if (!slugInput.value || slugInput.dataset.auto === 'true') {
                slugInput.value = name
                    .toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .trim();
                slugInput.dataset.auto = 'true';
            }
        }
        
        document.getElementById('cat_slug')?.addEventListener('input', function() {
            this.dataset.auto = 'false';
        });
        
        function applyFilters() {
            const search = document.getElementById('searchInput')?.value || '';
            const status = document.getElementById('statusFilter')?.value || 'all';
            const parent = document.getElementById('parentFilter')?.value || 'all';
            
            const params = new URLSearchParams();
            if (search) params.set('search', search);
            if (status !== 'all') params.set('status', status);
            if (parent !== 'all') params.set('parent', parent);
            
            window.location.href = 'categories.php' + (params.toString() ? '?' + params.toString() : '');
        }
        
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
        
        function viewProducts(slug) {
            window.open(`../category.php?slug=${slug}`, '_blank');
        }
        
        function showToast(message, type = 'success') {
            if (typeof window.showToast === 'function') {
                window.showToast(message, type);
            } else {
                alert(message);
            }
        }
        
        // ==================== INITIALIZATION ====================
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
        
        <?php if ($edit_category): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('category_id').value = '<?php echo $edit_category['id']; ?>';
            document.getElementById('cat_name').value = '<?php echo addslashes($edit_category['name']); ?>';
            document.getElementById('cat_slug').value = '<?php echo addslashes($edit_category['slug']); ?>';
            document.getElementById('cat_description').value = '<?php echo addslashes($edit_category['description'] ?? ''); ?>';
            document.getElementById('cat_icon').value = '<?php echo addslashes($edit_category['icon'] ?? 'fa-tag'); ?>';
            document.getElementById('parent_id').value = '<?php echo $edit_category['parent_id'] ?? ''; ?>';
            document.getElementById('is_active').checked = <?php echo $edit_category['is_active'] ? 'true' : 'false'; ?>;
            // Image preview
            const preview = document.getElementById('imagePreview');
            <?php if (!empty($edit_category['image'])): ?>
                preview.innerHTML = `<img src="<?php echo BASE_URL . 'uploads/' . $edit_category['image']; ?>" alt="Current image">`;
                document.getElementById('remove_image')?.parentElement?.style?.display = 'block';
            <?php else: ?>
                preview.innerHTML = '<div class="empty">No image selected</div>';
            <?php endif; ?>
            document.getElementById('categoryModal').classList.add('active');
        });
        <?php endif; ?>
    </script>
</body>
</html>