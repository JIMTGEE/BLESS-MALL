<?php
/**
 * orders.php - Bless Mall Order Management v2.0
 * 
 * Features:
 * - View all orders for logged-in users
 * - Order status tracking with timeline
 * - Order detail modal with proper image display
 * - Re-order functionality
 * - Mobile-responsive premium UI
 * - Self-contained AJAX handlers
 * - Uses MySQLi (matches your system)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BLESSMALL_CONFIG_INCLUDED')) {
    require_once __DIR__ . '/includes/config.php';
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    define('BASE_URL', $protocol . $host . ($basePath ? '/' . ltrim($basePath, '/') : ''));
}

// Require login to view orders
Auth::requireLogin('login.php?redirect=orders.php');

// ════════════════════════════════════════════════════════════════════
//  HANDLE AJAX REQUESTS
// ════════════════════════════════════════════════════════════════════
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=utf-8');
    $conn = getConnection();
    
    $action = $_POST['action'] ?? '';
    
    // ═══ GET ORDER DETAILS ═══
    if ($action === 'get_order_details') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $user_id = (int)$_SESSION['user_id'];
        
        // Get order
        $stmt = $conn->prepare("
            SELECT o.*, 
                   oa.address_line1, oa.address_line2, oa.city, oa.region, oa.phone as shipping_phone,
                   v.business_name as vendor_name, v.logo as vendor_logo
            FROM orders o
            LEFT JOIN order_addresses oa ON o.id = oa.order_id
            LEFT JOIN vendors v ON o.vendor_id = v.id
            WHERE o.id = ? AND o.user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $order_id, $user_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        
        // Get order items
        $stmt = $conn->prepare("
            SELECT oi.*, p.name as product_name, p.slug as product_slug,
                   pi.image_path, ppt.tier_name, ppt.pricing_type
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            LEFT JOIN product_pricing_tiers ppt ON oi.pricing_tier_id = ppt.id
            LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
            WHERE oi.order_id = ?
            ORDER BY oi.id ASC
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Get status history
        $stmt = $conn->prepare("
            SELECT osh.*, u.name as changed_by_name
            FROM order_status_history osh
            LEFT JOIN users u ON osh.changed_by = u.id
            WHERE osh.order_id = ?
            ORDER BY osh.created_at ASC
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Get payment info
        $stmt = $conn->prepare("
            SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Get delivery info
        $stmt = $conn->prepare("
            SELECT * FROM deliveries WHERE order_id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $delivery = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items,
            'history' => $history,
            'payment' => $payment ?: null,
            'delivery' => $delivery ?: null
        ]);
        exit;
    }
    
    // ═══ RE-ORDER ═══
    if ($action === 'reorder') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $user_id = (int)$_SESSION['user_id'];
        
        // Get order items
        $stmt = $conn->prepare("
            SELECT oi.product_id, oi.pricing_tier_id, oi.quantity, oi.unit_price,
                   p.name, p.slug, p.is_active,
                   ppt.tier_name, ppt.pricing_type,
                   pi_inv.stock_quantity, pi_inv.reserved_quantity
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id AND o.user_id = ?
            LEFT JOIN products p ON oi.product_id = p.id
            LEFT JOIN product_pricing_tiers ppt ON oi.pricing_tier_id = ppt.id
            LEFT JOIN product_inventory pi_inv ON oi.pricing_tier_id = pi_inv.pricing_tier_id
            WHERE oi.order_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $order_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'Order not found or no items to re-order']);
            exit;
        }
        
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        $added_count = 0;
        $skipped_count = 0;
        
        foreach ($items as $item) {
            if (!$item['is_active']) {
                $skipped_count++;
                continue;
            }
            
            $available = max(0, (int)($item['stock_quantity'] ?? 0) - (int)($item['reserved_quantity'] ?? 0));
            if ($available <= 0) {
                $skipped_count++;
                continue;
            }
            
            $cart_key = 'p_' . $item['product_id'] . '_t_' . $item['pricing_tier_id'];
            
            if (isset($_SESSION['cart'][$cart_key])) {
                $_SESSION['cart'][$cart_key]['quantity'] = min(
                    $_SESSION['cart'][$cart_key]['quantity'] + $item['quantity'],
                    $available
                );
            } else {
                // Get product image
                $img_path = '';
                $imgStmt = $conn->prepare("
                    SELECT image_path FROM product_images 
                    WHERE product_id = ? 
                    ORDER BY is_primary DESC, sort_order ASC LIMIT 1
                ");
                $imgStmt->bind_param("i", $item['product_id']);
                $imgStmt->execute();
                $imgResult = $imgStmt->get_result();
                if ($imgRow = $imgResult->fetch_assoc()) {
                    $img_path = $imgRow['image_path'];
                }
                $imgStmt->close();
                
                $_SESSION['cart'][$cart_key] = [
                    'product_id' => (int)$item['product_id'],
                    'pricing_tier_id' => (int)$item['pricing_tier_id'],
                    'name' => $item['name'],
                    'slug' => $item['slug'],
                    'price' => (float)$item['unit_price'],
                    'original_price' => (float)$item['unit_price'],
                    'quantity' => min((int)$item['quantity'], $available),
                    'tier_name' => $item['tier_name'],
                    'pricing_type' => $item['pricing_type'],
                    'image' => $img_path,
                    'is_express' => 0
                ];
            }
            $added_count++;
        }
        
        $cart_count = 0;
        foreach ($_SESSION['cart'] as $cart_item) {
            $cart_count += (int)($cart_item['quantity'] ?? 0);
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Added {$added_count} item(s) to cart" . ($skipped_count > 0 ? ". {$skipped_count} unavailable." : ""),
            'added_count' => $added_count,
            'skipped_count' => $skipped_count,
            'cart_count' => $cart_count,
            'redirect' => $added_count > 0 ? 'cart.php' : null
        ]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ════════════════════════════════════════════════════════════════════
//  PAGE LOGIC
// ════════════════════════════════════════════════════════════════════

$conn = getConnection();
$user_id = (int)$_SESSION['user_id'];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Filter
$filter = $_GET['filter'] ?? 'all';
$filter_sql = '';
$filter_params = [];

if ($filter !== 'all') {
    $filter_sql = "AND o.order_status = ?";
    $filter_params[] = $filter;
}

// Get total orders count
$count_sql = "SELECT COUNT(*) FROM orders o WHERE o.user_id = ?";
if ($filter_sql) {
    $count_sql .= " " . $filter_sql;
}
$stmt = $conn->prepare($count_sql);
if ($filter_sql) {
    $stmt->bind_param("is", $user_id, $filter);
} else {
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$total_orders = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();
$total_pages = ceil($total_orders / $per_page);

// Get orders
$sql = "
    SELECT o.*, 
           COUNT(oi.id) as item_count,
           v.business_name as vendor_name,
           d.status as delivery_status, d.tracking_ref
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN vendors v ON o.vendor_id = v.id
    LEFT JOIN deliveries d ON o.id = d.order_id
    WHERE o.user_id = ?
";
if ($filter_sql) {
    $sql .= " " . $filter_sql;
}
$sql .= " GROUP BY o.id ORDER BY o.created_at DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if ($filter_sql) {
    $stmt->bind_param("isii", $user_id, $filter, $per_page, $offset);
} else {
    $stmt->bind_param("iii", $user_id, $per_page, $offset);
}
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ═══ HELPER FUNCTIONS ═══

function _bm_fmt($v): string {
    $n = (float) $v;
    if ($n <= 0) return 'GH₵ 0.00';
    return 'GH₵ ' . number_format($n, 2, '.', ',');
}

function getOrderStatusBadge(string $status): string {
    $badges = [
        'pending'    => ['label' => 'Pending',    'class' => 'badge-warning'],
        'confirmed'  => ['label' => 'Confirmed',  'class' => 'badge-info'],
        'processing' => ['label' => 'Processing', 'class' => 'badge-info'],
        'packed'     => ['label' => 'Packed',     'class' => 'badge-primary'],
        'shipped'    => ['label' => 'Shipped',    'class' => 'badge-primary'],
        'in_transit' => ['label' => 'In Transit', 'class' => 'badge-primary'],
        'delivered'  => ['label' => 'Delivered',  'class' => 'badge-success'],
        'cancelled'  => ['label' => 'Cancelled',  'class' => 'badge-danger'],
        'refunded'   => ['label' => 'Refunded',   'class' => 'badge-secondary'],
    ];
    
    $badge = $badges[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-secondary'];
    return "<span class='status-badge {$badge['class']}'>{$badge['label']}</span>";
}

function getPaymentStatusBadge(string $status): string {
    $badges = [
        'unpaid'          => ['label' => 'Unpaid',           'class' => 'badge-warning'],
        'paid'            => ['label' => 'Paid',             'class' => 'badge-success'],
        'partially_paid'  => ['label' => 'Partially Paid',  'class' => 'badge-info'],
        'refunded'        => ['label' => 'Refunded',         'class' => 'badge-secondary'],
    ];
    
    $badge = $badges[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-secondary'];
    return "<span class='status-badge {$badge['class']}'>{$badge['label']}</span>";
}

function getOrderStatusIcon(string $status): string {
    $icons = [
        'pending'    => 'fa-clock',
        'confirmed'  => 'fa-check-circle',
        'processing' => 'fa-cog',
        'packed'     => 'fa-box',
        'shipped'    => 'fa-shipping-fast',
        'in_transit' => 'fa-truck',
        'delivered'  => 'fa-box-open',
        'cancelled'  => 'fa-times-circle',
        'refunded'   => 'fa-undo',
    ];
    return $icons[$status] ?? 'fa-circle';
}

function timeAgo($datetime): string {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $time);
}

function getOrderImageUrl($imagePath) {
    if (empty($imagePath)) {
        return BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
    }
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        return $imagePath;
    }
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
    return BASE_URL . '/assets/images/placeholders/product-placeholder.jpg';
}

// Check for success message
$success_message = '';
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $order_number = $_GET['order'] ?? '';
    $success_message = 'Order <strong>#' . htmlspecialchars($order_number) . '</strong> placed successfully!';
}

$page_title = 'My Orders — Bless Mall';
$current_page = 'orders';

require_once __DIR__ . '/header.php';
?>

<style>
/* ════════════════════════════════════════════════════════════════════
   BLESS MALL — ORDERS PAGE v2.0 Premium Styles
════════════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,900;1,400;1,500&display=swap');

:root {
    --ink: #1a1a1a; --ink-2: #333; --ink-3: #666; --ink-4: #999;
    --paper: #f8f7f4; --white: #fff; --line: #e5e0d8;
    --green: #0a6e3d; --green-dk: #085530; --green-lt: #e6f5ee;
    --gold: #c9a227; --gold-dk: #a6861f;
    --red: #dc2626; --red-lt: #fee2e2;
    --amber: #d97706; --blue: #2563eb; --blue-lt: #dbeafe;
    --purple: #7c3aed; --purple-lt: #ede9fe;
    --sh-sm: 0 1px 3px rgba(0,0,0,.06);
    --sh-md: 0 4px 12px rgba(0,0,0,.08);
    --sh-lg: 0 8px 24px rgba(0,0,0,.12);
    --fh: 'Playfair Display', Georgia, serif;
    --fb: 'Inter', system-ui, sans-serif;
    --radius: 12px; --radius-sm: 8px; --radius-full: 9999px;
    --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--paper); font-family: var(--fb); color: var(--ink); line-height: 1.6; }

.orders-page { padding: 2rem 0; min-height: 80vh; }
.orders-container { max-width: 1100px; margin: 0 auto; padding: 0 1.25rem; }

.orders-breadcrumb {
    display: flex; align-items: center; gap: .5rem; margin-bottom: 1.5rem;
    font-size: .82rem; color: var(--ink-4); flex-wrap: wrap;
}
.orders-breadcrumb a { color: var(--green); text-decoration: none; font-weight: 500; }
.orders-breadcrumb a:hover { text-decoration: underline; }

.orders-header {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;
}
.orders-header h1 {
    font-family: var(--fh); font-size: clamp(1.5rem, 4vw, 2rem); font-weight: 400;
    display: flex; align-items: center; gap: .5rem;
}
.orders-header h1 i { color: var(--green); }
.orders-count { font-size: .82rem; color: var(--ink-4); font-family: var(--fb); font-weight: 400; }

.success-banner {
    background: #d1fae5; border: 1px solid #10b981; border-radius: var(--radius-sm);
    padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: .75rem;
    animation: fadeInUp .4s ease;
}
.success-banner i { font-size: 1.5rem; color: #10b981; }
.success-banner p { font-size: .88rem; color: #065f46; flex: 1; }
.success-banner .btn-close {
    background: none; border: none; cursor: pointer; color: #065f46; font-size: 1.2rem; padding: 0;
}
@keyframes fadeInUp { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: none; } }

.filter-tabs {
    display: flex; gap: .35rem; margin-bottom: 1.5rem; overflow-x: auto;
    padding-bottom: .5rem; -webkit-overflow-scrolling: touch;
}
.filter-tabs::-webkit-scrollbar { height: 0; }
.filter-tab {
    padding: .45rem 1rem; border: 1px solid var(--line); border-radius: 20px;
    font-size: .78rem; font-weight: 500; cursor: pointer; white-space: nowrap;
    text-decoration: none; color: var(--ink-3); transition: var(--transition);
    background: var(--white); font-family: var(--fb);
}
.filter-tab:hover { border-color: var(--green); color: var(--green); }
.filter-tab.active { background: var(--green); color: #fff; border-color: var(--green); }
.filter-tab .count { font-size: .7rem; opacity: .7; margin-left: .2rem; }

.order-card {
    background: var(--white); border-radius: var(--radius); border: 1px solid var(--line);
    box-shadow: var(--sh-sm); margin-bottom: 1rem; overflow: hidden; transition: var(--transition);
}
.order-card:hover { box-shadow: var(--sh-md); }

.order-card-header {
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;
    padding: 1rem 1.25rem; background: #fafbfc; border-bottom: 1px solid var(--line); gap: .5rem;
}
.order-id { font-weight: 600; font-size: .9rem; color: var(--ink); }
.order-id span { color: var(--ink-4); font-weight: 400; font-size: .78rem; }
.order-date { font-size: .78rem; color: var(--ink-4); }

.order-card-body {
    padding: 1.25rem; display: grid;
    grid-template-columns: 1fr auto; gap: 1rem; align-items: center;
}

.order-info { min-width: 0; }
.order-info-row {
    display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: .5rem;
}
.order-info-item { font-size: .8rem; color: var(--ink-3); }
.order-info-item strong { color: var(--ink); font-weight: 600; }

.order-amount { text-align: right; flex-shrink: 0; }
.order-total { font-size: 1.15rem; font-weight: 700; color: var(--green); }
.order-payment { font-size: .72rem; color: var(--ink-4); margin-top: .15rem; }

.order-card-footer {
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;
    padding: .85rem 1.25rem; border-top: 1px solid #f3f4f6; gap: .5rem;
}
.status-badges { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; }
.status-badge {
    font-size: .7rem; font-weight: 600; padding: .2rem .6rem; border-radius: 12px;
    display: inline-flex; align-items: center; gap: .25rem;
}
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-info { background: #dbeafe; color: #1e40af; }
.badge-primary { background: #ede9fe; color: #5b21b6; }
.badge-success { background: #d1fae5; color: #065f46; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-secondary { background: #f3f4f6; color: #6b7280; }

.order-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.btn-order {
    padding: .4rem .85rem; border-radius: 18px; font-size: .75rem; font-weight: 600;
    cursor: pointer; transition: var(--transition); text-decoration: none;
    display: inline-flex; align-items: center; gap: .3rem; border: none; font-family: var(--fb);
}
.btn-view { background: #f3f4f6; color: var(--ink-2); }
.btn-view:hover { background: #e5e7eb; }
.btn-reorder { background: var(--green-lt); color: var(--green); }
.btn-reorder:hover { background: #d1fae5; }
.btn-track { background: var(--blue-lt); color: var(--blue); }
.btn-track:hover { background: #bfdbfe; }

.empty-orders {
    text-align: center; padding: 4rem 2rem; background: var(--white);
    border-radius: var(--radius); border: 1px solid var(--line); box-shadow: var(--sh-sm);
}
.empty-orders-icon { font-size: 3.5rem; color: #d1d5db; margin-bottom: 1rem; }
.empty-orders h2 { font-family: var(--fh); font-size: 1.5rem; font-weight: 400; margin-bottom: .5rem; }
.empty-orders p { color: var(--ink-3); font-size: .88rem; margin-bottom: 1.5rem; }
.btn-shop {
    display: inline-block; padding: .75rem 2rem; background: var(--green); color: #fff;
    text-decoration: none; border-radius: 25px; font-weight: 600; font-size: .88rem; transition: var(--transition);
}
.btn-shop:hover { background: var(--green-dk); transform: translateY(-2px); }

.pagination { display: flex; justify-content: center; gap: .35rem; margin-top: 2rem; flex-wrap: wrap; }
.page-link {
    padding: .45rem .85rem; border: 1px solid var(--line); border-radius: 6px;
    font-size: .8rem; font-weight: 500; text-decoration: none; color: var(--ink-3);
    transition: var(--transition); background: var(--white);
}
.page-link:hover { border-color: var(--green); color: var(--green); }
.page-link.active { background: var(--green); color: #fff; border-color: var(--green); }
.page-link.disabled { opacity: .4; pointer-events: none; }

.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000;
    display: none; align-items: center; justify-content: center; padding: 1rem;
}
.modal-overlay.active { display: flex; }
.modal-content {
    background: var(--white); border-radius: var(--radius); max-width: 700px; width: 100%;
    max-height: 85vh; overflow-y: auto; box-shadow: var(--sh-lg);
    animation: modalIn .3s ease;
}
@keyframes modalIn { from { opacity: 0; transform: scale(.95); } to { opacity: 1; transform: none; } }

.modal-header {
    padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--line);
    display: flex; justify-content: space-between; align-items: center;
    position: sticky; top: 0; background: var(--white); z-index: 1; border-radius: var(--radius) var(--radius) 0 0;
}
.modal-header h3 { font-family: var(--fh); font-size: 1.1rem; }
.btn-modal-close {
    background: none; border: none; font-size: 1.5rem; cursor: pointer;
    color: var(--ink-4); padding: 0; line-height: 1; transition: var(--transition);
}
.btn-modal-close:hover { color: var(--red); }

.modal-body { padding: 1.5rem; }
.modal-section { margin-bottom: 1.5rem; }
.modal-section:last-child { margin-bottom: 0; }
.modal-section h4 {
    font-size: .85rem; font-weight: 600; color: var(--ink-2); margin-bottom: .75rem;
    padding-bottom: .5rem; border-bottom: 1px solid #f3f4f6;
}

.order-timeline { position: relative; padding-left: 1.5rem; }
.order-timeline::before {
    content: ''; position: absolute; left: 6px; top: 4px; bottom: 4px;
    width: 2px; background: var(--line);
}
.timeline-item {
    position: relative; padding-bottom: 1rem; font-size: .82rem;
}
.timeline-item:last-child { padding-bottom: 0; }
.timeline-dot {
    position: absolute; left: -1.35rem; top: 4px;
    width: 12px; height: 12px; border-radius: 50%; background: var(--line);
    border: 2px solid var(--white);
}
.timeline-item.completed .timeline-dot { background: var(--green); }
.timeline-item.current .timeline-dot { background: var(--gold); box-shadow: 0 0 0 3px rgba(201,162,39,.3); }
.timeline-item.cancelled .timeline-dot { background: var(--red); }
.timeline-date { font-size: .7rem; color: var(--ink-4); }
.timeline-note { font-size: .75rem; color: var(--ink-3); margin-top: .1rem; }

.modal-order-item {
    display: flex; gap: .75rem; padding: .65rem 0; border-bottom: 1px solid #f9fafb; align-items: center;
}
.modal-order-item:last-child { border-bottom: none; }
.modal-order-item-img {
    width: 52px; height: 52px; border-radius: 6px; background: #f5f5f5;
    flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center;
    font-size: 1rem; color: #ddd;
}
.modal-order-item-img img { width: 100%; height: 100%; object-fit: cover; }
.modal-order-item-info { flex: 1; min-width: 0; }
.modal-order-item-name {
    font-size: .82rem; font-weight: 500; display: -webkit-box;
    -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden;
}
.modal-order-item-meta { font-size: .7rem; color: var(--ink-4); }
.modal-order-item-price { font-size: .85rem; font-weight: 600; text-align: right; }

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
@keyframes toastIn { from { transform: translateX(110%); opacity: 0; } to { opacity: 1; transform: none; } }

.loading-overlay {
    position: fixed; inset: 0; background: rgba(255,255,255,.7);
    display: none; justify-content: center; align-items: center; z-index: 10000;
}
.loading-overlay.active { display: flex; }
.loading-spinner {
    width: 44px; height: 44px; border: 4px solid #e5e7eb;
    border-top-color: var(--green); border-radius: 50%; animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 640px) {
    .orders-page { padding: 1rem 0; }
    .orders-container { padding: 0 .75rem; }
    .orders-header h1 { font-size: 1.3rem; }
    .order-card-body { grid-template-columns: 1fr; }
    .order-amount { text-align: left; }
    .filter-tab { padding: .35rem .75rem; font-size: .72rem; }
    .modal-content { max-height: 90vh; border-radius: var(--radius-sm); }
    .modal-body { padding: 1rem; }
}
</style>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
</div>

<!-- Order Detail Modal -->
<div class="modal-overlay" id="orderModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Order Details</h3>
            <button class="btn-modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="loading-spinner" style="margin:2rem auto;"></div>
        </div>
    </div>
</div>

<!-- Orders Page -->
<div class="orders-page">
    <div class="orders-container">
        
        <!-- Breadcrumb -->
        <div class="orders-breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Home</a>
            <span>›</span>
            <a href="account.php">Account</a>
            <span>›</span>
            <span>My Orders</span>
        </div>
        
        <!-- Header -->
        <div class="orders-header">
            <div>
                <h1><i class="fas fa-shopping-bag"></i> My Orders</h1>
                <span class="orders-count"><?php echo $total_orders; ?> order<?php echo $total_orders !== 1 ? 's' : ''; ?></span>
            </div>
        </div>
        
        <!-- Success Banner -->
        <?php if ($success_message): ?>
        <div class="success-banner" id="successBanner">
            <i class="fas fa-check-circle"></i>
            <p><?php echo $success_message; ?></p>
            <button class="btn-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>
        
        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <?php 
            $filters = [
                'all' => 'All Orders',
                'pending' => 'Pending',
                'confirmed' => 'Confirmed',
                'processing' => 'Processing',
                'shipped' => 'Shipped',
                'delivered' => 'Delivered',
                'cancelled' => 'Cancelled',
            ];
            foreach ($filters as $key => $label): 
            ?>
            <a href="?filter=<?php echo $key; ?>" class="filter-tab <?php echo $filter === $key ? 'active' : ''; ?>">
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Orders List -->
        <?php if (empty($orders)): ?>
            <div class="empty-orders">
                <div class="empty-orders-icon"><i class="fas fa-shopping-bag"></i></div>
                <h2>No orders found</h2>
                <p>
                    <?php if ($filter !== 'all'): ?>
                        No orders with status "<?php echo htmlspecialchars($filter); ?>". 
                        <a href="orders.php" style="color:var(--green);">View all orders</a>
                    <?php else: ?>
                        You haven't placed any orders yet. Start shopping and come back here to track your orders!
                    <?php endif; ?>
                </p>
                <?php if ($filter === 'all'): ?>
                <a href="shop.php" class="btn-shop"><i class="fas fa-store"></i> Start Shopping</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($orders as $order): 
                $order_id = (int)$order['id'];
                $status = $order['order_status'] ?? 'pending';
                $payment_status = $order['payment_status'] ?? 'unpaid';
            ?>
            <div class="order-card" id="order-<?php echo $order_id; ?>">
                <div class="order-card-header">
                    <div>
                        <span class="order-id">
                            #<?php echo htmlspecialchars($order['order_number']); ?>
                            <span>• <?php echo $order['item_count']; ?> item<?php echo $order['item_count'] != 1 ? 's' : ''; ?></span>
                        </span>
                    </div>
                    <span class="order-date">
                        <i class="far fa-calendar-alt"></i> <?php echo timeAgo($order['created_at']); ?>
                    </span>
                </div>
                
                <div class="order-card-body">
                    <div class="order-info">
                        <div class="order-info-row">
                            <span class="order-info-item">
                                <strong>Status:</strong> <?php echo getOrderStatusBadge($status); ?>
                            </span>
                            <span class="order-info-item">
                                <strong>Payment:</strong> <?php echo getPaymentStatusBadge($payment_status); ?>
                            </span>
                            <span class="order-info-item">
                                <strong>Delivery:</strong> <?php echo ucfirst($order['delivery_type'] ?? 'delivery'); ?>
                            </span>
                            <?php if (!empty($order['vendor_name'])): ?>
                            <span class="order-info-item">
                                <strong>Sold by:</strong> <?php echo htmlspecialchars($order['vendor_name']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($order['tracking_ref'])): ?>
                        <div class="order-info-row">
                            <span class="order-info-item">
                                <strong>Tracking:</strong> 
                                <code style="background:#f3f4f6;padding:.1rem .4rem;border-radius:4px;">
                                    <?php echo htmlspecialchars($order['tracking_ref']); ?>
                                </code>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="order-amount">
                        <div class="order-total"><?php echo _bm_fmt($order['total_amount']); ?></div>
                        <div class="order-payment">
                            <?php echo htmlspecialchars($order['payment_method']); ?>
                        </div>
                    </div>
                </div>
                
                <div class="order-card-footer">
                    <div class="status-badges">
                        <?php echo getOrderStatusBadge($status); ?>
                        <?php echo getPaymentStatusBadge($payment_status); ?>
                    </div>
                    <div class="order-actions">
                        <button class="btn-order btn-view" onclick="viewOrder(<?php echo $order_id; ?>)">
                            <i class="fas fa-eye"></i> View Details
                        </button>
                        <?php if ($status === 'delivered' || $status === 'cancelled'): ?>
                        <button class="btn-order btn-reorder" onclick="reorderItems(<?php echo $order_id; ?>)">
                            <i class="fas fa-redo"></i> Re-order
                        </button>
                        <?php endif; ?>
                        <?php if (!empty($order['tracking_ref'])): ?>
                        <a href="track-order.php?ref=<?php echo urlencode($order['tracking_ref']); ?>" class="btn-order btn-track">
                            <i class="fas fa-truck"></i> Track
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>" class="page-link">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php else: ?>
                <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
                <?php endif; ?>
                
                <?php 
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): 
                ?>
                <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>" 
                   class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>" class="page-link">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php else: ?>
                <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
        <?php endif; ?>
        
    </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════
// ORDERS SCRIPTS v2.0
// ═══════════════════════════════════════════════════════════════════

function showToast(message, type) {
    type = type || 'info';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    var icon = type === 'error' ? '❌' : '✅';
    toast.innerHTML = '<span>' + icon + '</span>' +
        '<span style="flex:1;">' + message + '</span>' +
        '<button class="close-toast" onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:1rem;padding:0;">&times;</button>';
    container.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300);
    }, 4000);
}

function showLoading() { 
    document.getElementById('loadingOverlay').classList.add('active'); 
}

function hideLoading() { 
    document.getElementById('loadingOverlay').classList.remove('active'); 
}

// ═══ VIEW ORDER DETAILS ═══
function viewOrder(orderId) {
    showLoading();
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_order_details');
    formData.append('order_id', orderId);
    
    fetch('orders.php', {
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
            populateModal(data);
            document.getElementById('orderModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        } else {
            showToast(data.message || 'Error loading order', 'error');
        }
    })
    .catch(function(err) {
        console.error('Error:', err);
        showToast('Connection error', 'error');
    })
    .finally(function() {
        hideLoading();
    });
}

// ═══ POPULATE MODAL ═══
function populateModal(data) {
    var order = data.order;
    var items = data.items;
    var history = data.history;
    var payment = data.payment;
    var delivery = data.delivery;
    
    var statusBadges = {
        'pending': 'badge-warning', 'confirmed': 'badge-info', 'processing': 'badge-info',
        'packed': 'badge-primary', 'shipped': 'badge-primary', 'in_transit': 'badge-primary',
        'delivered': 'badge-success', 'cancelled': 'badge-danger', 'refunded': 'badge-secondary'
    };
    var paymentBadges = {
        'unpaid': 'badge-warning', 'paid': 'badge-success',
        'partially_paid': 'badge-info', 'refunded': 'badge-secondary'
    };
    
    var html = '';
    
    // Order Summary
    html += '<div class="modal-section">';
    html += '<h4>Order Summary</h4>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.82rem;">';
    html += '<div><strong>Order Number:</strong> ' + escapeHtml(order.order_number) + '</div>';
    html += '<div><strong>Date:</strong> ' + escapeHtml(order.created_at) + '</div>';
    html += '<div><strong>Status:</strong> <span class="status-badge ' + (statusBadges[order.order_status] || 'badge-secondary') + '">' + escapeHtml(order.order_status) + '</span></div>';
    html += '<div><strong>Payment:</strong> <span class="status-badge ' + (paymentBadges[order.payment_status] || 'badge-secondary') + '">' + escapeHtml(order.payment_status) + '</span></div>';
    html += '<div><strong>Delivery:</strong> ' + escapeHtml(order.delivery_type) + '</div>';
    html += '<div><strong>Payment Method:</strong> ' + escapeHtml(order.payment_method) + '</div>';
    html += '<div><strong>Subtotal:</strong> GH₵ ' + parseFloat(order.subtotal).toFixed(2) + '</div>';
    html += '<div><strong>Delivery Fee:</strong> GH₵ ' + parseFloat(order.delivery_fee).toFixed(2) + '</div>';
    if (parseFloat(order.discount_amount) > 0) {
        html += '<div><strong>Discount:</strong> -GH₵ ' + parseFloat(order.discount_amount).toFixed(2) + '</div>';
    }
    html += '<div style="font-weight:700;color:var(--green);"><strong>Total:</strong> GH₵ ' + parseFloat(order.total_amount).toFixed(2) + '</div>';
    html += '</div></div>';
    
    // Shipping Address
    if (order.address_line1) {
        html += '<div class="modal-section">';
        html += '<h4>Shipping Address</h4>';
        html += '<div style="font-size:.82rem;">';
        html += '<p>' + escapeHtml(order.address_line1) + '</p>';
        if (order.address_line2) html += '<p>' + escapeHtml(order.address_line2) + '</p>';
        html += '<p>' + escapeHtml(order.city) + ', ' + escapeHtml(order.region) + '</p>';
        html += '<p>' + escapeHtml(order.shipping_phone || order.phone) + '</p>';
        html += '</div></div>';
    }
    
    // Order Items
    html += '<div class="modal-section">';
    html += '<h4>Items (' + items.length + ')</h4>';
    items.forEach(function(item) {
        var imgUrl = '<?php echo BASE_URL; ?>/assets/images/placeholders/product-placeholder.jpg';
        if (item.image_path) {
            var imgPath = item.image_path;
            if (imgPath.indexOf('http') === 0) {
                imgUrl = imgPath;
            } else {
                var paths = [
                    '/assets/uploads/products/' + imgPath,
                    '/uploads/products/' + imgPath,
                    '/' + imgPath
                ];
                // Use the first path that exists - we'll let the image onerror handle fallback
                imgUrl = '<?php echo BASE_URL; ?>' + paths[0];
            }
        }
        html += '<div class="modal-order-item">';
        html += '<div class="modal-order-item-img">';
        html += '<img src="' + imgUrl + '" alt="' + escapeHtml(item.product_name || 'Product') + '" loading="lazy" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'<i class=\\"fas fa-box-open\\"></i>\'">';
        html += '</div>';
        html += '<div class="modal-order-item-info">';
        html += '<div class="modal-order-item-name">' + escapeHtml(item.product_name || 'Product') + '</div>';
        html += '<div class="modal-order-item-meta">';
        html += 'Qty: ' + item.quantity;
        if (item.tier_name) html += ' • ' + escapeHtml(item.tier_name);
        if (item.pricing_type) html += ' • ' + escapeHtml(item.pricing_type);
        html += '</div></div>';
        html += '<div class="modal-order-item-price">GH₵ ' + parseFloat(item.subtotal).toFixed(2) + '</div>';
        html += '</div>';
    });
    html += '</div>';
    
    // Status Timeline
    if (history && history.length > 0) {
        html += '<div class="modal-section">';
        html += '<h4>Order Timeline</h4>';
        html += '<div class="order-timeline">';
        history.forEach(function(h, index) {
            var isLast = index === history.length - 1;
            var itemClass = 'timeline-item';
            if (h.new_status === 'cancelled') itemClass += ' cancelled';
            else if (isLast && h.new_status !== 'delivered') itemClass += ' current';
            else itemClass += ' completed';
            
            html += '<div class="' + itemClass + '">';
            html += '<div class="timeline-dot"></div>';
            html += '<strong>' + escapeHtml(h.new_status) + '</strong>';
            html += '<div class="timeline-date">' + escapeHtml(h.created_at) + '</div>';
            if (h.notes) html += '<div class="timeline-note">' + escapeHtml(h.notes) + '</div>';
            html += '</div>';
        });
        html += '</div></div>';
    }
    
    // Payment Info
    if (payment) {
        html += '<div class="modal-section">';
        html += '<h4>Payment Information</h4>';
        html += '<div style="font-size:.82rem;">';
        html += '<p><strong>Transaction ID:</strong> ' + escapeHtml(payment.transaction_id || 'N/A') + '</p>';
        html += '<p><strong>Amount Paid:</strong> GH₵ ' + parseFloat(payment.amount).toFixed(2) + '</p>';
        html += '<p><strong>Status:</strong> ' + escapeHtml(payment.status) + '</p>';
        html += '</div></div>';
    }
    
    // Delivery Info
    if (delivery) {
        html += '<div class="modal-section">';
        html += '<h4>Delivery Information</h4>';
        html += '<div style="font-size:.82rem;">';
        html += '<p><strong>Status:</strong> ' + escapeHtml(delivery.status) + '</p>';
        if (delivery.tracking_ref) html += '<p><strong>Tracking:</strong> ' + escapeHtml(delivery.tracking_ref) + '</p>';
        if (delivery.delivery_partner) html += '<p><strong>Partner:</strong> ' + escapeHtml(delivery.delivery_partner) + '</p>';
        if (delivery.estimated_delivery_date) html += '<p><strong>Est. Delivery:</strong> ' + escapeHtml(delivery.estimated_delivery_date) + '</p>';
        html += '</div></div>';
    }
    
    // Notes
    if (order.notes) {
        html += '<div class="modal-section">';
        html += '<h4>Order Notes</h4>';
        html += '<p style="font-size:.82rem;">' + escapeHtml(order.notes) + '</p>';
        html += '</div>';
    }
    
    document.getElementById('modalTitle').textContent = 'Order #' + order.order_number;
    document.getElementById('modalBody').innerHTML = html;
}

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ═══ CLOSE MODAL ═══
function closeModal() {
    document.getElementById('orderModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal on overlay click
document.getElementById('orderModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Close modal on ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

// ═══ RE-ORDER ═══
function reorderItems(orderId) {
    if (!confirm('Add items from this order to your cart?')) return;
    
    showLoading();
    
    var formData = new URLSearchParams();
    formData.append('action', 'reorder');
    formData.append('order_id', orderId);
    
    fetch('orders.php', {
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
            showToast(data.message, 'success');
            if (data.redirect) {
                setTimeout(function() {
                    window.location.href = data.redirect;
                }, 1000);
            }
            // Update cart count in header
            var cartBadge = document.getElementById('cartCount');
            if (cartBadge && data.cart_count !== undefined) {
                cartBadge.textContent = data.cart_count;
                cartBadge.style.display = data.cart_count > 0 ? 'inline-block' : 'none';
            }
        } else {
            showToast(data.message || 'Error re-ordering', 'error');
        }
    })
    .catch(function(err) {
        console.error('Error:', err);
        showToast('Connection error', 'error');
    })
    .finally(function() {
        hideLoading();
    });
}

// ═══ INIT ═══
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide success banner after 8 seconds
    var banner = document.getElementById('successBanner');
    if (banner) {
        setTimeout(function() {
            banner.style.transition = 'opacity 0.5s';
            banner.style.opacity = '0';
            setTimeout(function() { if (banner.parentNode) banner.remove(); }, 500);
        }, 8000);
    }
});

console.log('%c📦 Bless Mall Orders %cv2.0',
    'font-size:1.2em;font-weight:bold;color:#0a6e3d;',
    'font-size:1em;color:#c9a227;');
console.log('%c✅ Fixed AJAX, MySQLi integration, and modal display', 'font-style:italic;color:#666;');
</script>

<?php require_once __DIR__ . '/footer.php'; ?>