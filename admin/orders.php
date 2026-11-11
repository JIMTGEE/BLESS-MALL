<?php
/**
 * Bless Mall - Admin Orders Management
 * Premium Order Management Dashboard with AJAX Actions
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP & AUTHENTICATION
// ------------------------------------------------------------
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Check authentication
if (!Auth::isLoggedIn() || (!Auth::isAdmin() && !Auth::isSeller())) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$is_admin = Auth::isAdmin();
$userId = (int)$_SESSION['user_id'];
$csrfToken = CSRFToken::generate();

// ------------------------------------------------------------
// 2. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 3. SMS FUNCTION
// ------------------------------------------------------------
function blessmallSendSMS($phone, $message) {
    $apiKey = SMS_API_KEY;
    $senderId = SMS_SENDER_ID;

    if ($apiKey === '') {
        error_log('SMS not sent: API key is not configured.');
        return false;
    }
    
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 10 && $phone[0] === '0') {
        $phone = '233' . substr($phone, 1);
    } elseif (strlen($phone) === 9 && $phone[0] !== '0') {
        $phone = '233' . $phone;
    }
    
    $url = 'https://sms.arkesel.com/sms/api?action=send-sms&api_key=' . $apiKey . '&to=' . $phone . '&from=' . $senderId . '&sms=' . urlencode($message);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Log to database
    $conn = $GLOBALS['conn'];
    $status = ($httpCode === 200 && strpos($response, 'OK') !== false) ? 'sent' : 'failed';
    $phoneEsc = $conn->real_escape_string($phone);
    $msgEsc = $conn->real_escape_string($message);
    $conn->query("INSERT INTO sms_notifications (phone, message, status, created_at) VALUES ('$phoneEsc', '$msgEsc', '$status', NOW())");
    
    return $status === 'sent';
}

function validateOrderCsrfToken($token): bool {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ------------------------------------------------------------
// 4. HANDLE AJAX REQUESTS
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];

    if (in_array($action, ['update_status', 'toggle_order', 'send_sms'], true)
        && !validateOrderCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security validation failed. Refresh the page and try again.']);
        exit;
    }

    if ($action === 'send_sms' && !$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only administrators can send manual SMS messages.']);
        exit;
    }
    
    // Get vendor ID for sellers
    $vendorId = null;
    if (!$is_admin) {
        $vendorResult = $conn->query("SELECT id FROM vendors WHERE user_id = $userId LIMIT 1");
        if ($vendorResult && $vendorResult->num_rows > 0) {
            $vendorId = (int)$vendorResult->fetch_assoc()['id'];
        } else {
            echo json_encode(['success' => false, 'message' => 'Vendor not found']);
            exit;
        }
    }
    
    // ---- UPDATE ORDER STATUS ----
    if ($action === 'update_status') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        $validStatuses = ['pending', 'confirmed', 'processing', 'packed', 'shipped', 'in_transit', 'delivered', 'cancelled', 'refunded'];
        
        if ($orderId > 0 && in_array($newStatus, $validStatuses)) {
            // Verify seller has access to this order
            $accessQuery = "SELECT o.*, u.name AS customer_name, u.phone AS customer_phone, u.email AS customer_email, o.order_number 
                            FROM orders o 
                            LEFT JOIN users u ON o.user_id = u.id 
                            WHERE o.id = $orderId";
            if (!$is_admin && $vendorId) {
                $accessQuery .= " AND o.vendor_id = $vendorId";
            }
            
            $currentResult = $conn->query($accessQuery);
            $currentOrder = $currentResult->fetch_assoc();
            
            if ($currentOrder) {
                $oldStatus = $currentOrder['order_status'];
                $orderNumber = $currentOrder['order_number'];
                
                if ($oldStatus !== $newStatus) {
                    $updateFields = "order_status = '$newStatus', updated_at = NOW()";
                    
                    if ($newStatus === 'delivered') {
                        $updateFields .= ", payment_status = 'paid'";
                    }
                    if ($newStatus === 'cancelled' && $currentOrder['payment_status'] === 'paid') {
                        $updateFields .= ", payment_status = 'refunded'";
                    }
                    if (in_array($oldStatus, ['cancelled', 'refunded']) && !in_array($newStatus, ['cancelled', 'refunded'])) {
                        if ($currentOrder['payment_status'] === 'refunded') {
                            $updateFields .= ", payment_status = 'unpaid'";
                        }
                    }
                    
                    if ($conn->query("UPDATE orders SET $updateFields WHERE id = $orderId")) {
                        $notesEsc = $conn->real_escape_string($notes);
                        $conn->query("INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, notes, created_at) VALUES ($orderId, '$oldStatus', '$newStatus', $userId, '$notesEsc', NOW())");
                        
                        // Update deliveries table
                        if (in_array($newStatus, ['shipped', 'in_transit', 'delivered'])) {
                            $dStatus = ($newStatus === 'shipped') ? 'assigned' : ($newStatus === 'in_transit' ? 'in_transit' : 'delivered');
                            $conn->query("UPDATE deliveries SET status = '$dStatus' WHERE order_id = $orderId");
                        } elseif ($newStatus === 'cancelled') {
                            $conn->query("UPDATE deliveries SET status = 'cancelled' WHERE order_id = $orderId");
                        } elseif (in_array($oldStatus, ['cancelled', 'refunded'])) {
                            $conn->query("UPDATE deliveries SET status = 'pending' WHERE order_id = $orderId");
                        }
                        
                        // Send SMS notification
                        $smsSent = false;
                        if ($currentOrder['customer_phone']) {
                            $smsMsg = '';
                            $name = $currentOrder['customer_name'] ?? 'Customer';
                            
                            $statusMessages = [
                                'confirmed' => "Your order #$orderNumber has been confirmed and is being processed.",
                                'processing' => "Great news! Your order #$orderNumber is now being processed.",
                                'packed' => "Your order #$orderNumber has been packed and is ready for pickup/delivery.",
                                'shipped' => "Your order #$orderNumber is on its way! Track it on our website.",
                                'in_transit' => "Your order #$orderNumber is in transit and will arrive soon.",
                                'delivered' => "Your order #$orderNumber has been delivered. Thank you for shopping with Bless Mall!",
                                'cancelled' => "We regret to inform you that your order #$orderNumber has been cancelled."
                            ];
                            
                            if (isset($statusMessages[$newStatus])) {
                                $smsMsg = "Dear $name, " . $statusMessages[$newStatus] . " - Bless Mall";
                                $smsSent = blessmallSendSMS($currentOrder['customer_phone'], $smsMsg);
                            }
                        }
                        
                        $response = [
                            'success' => true,
                            'message' => "Order #$orderNumber updated to " . ucfirst($newStatus),
                            'new_status' => $newStatus,
                            'old_status' => $oldStatus,
                            'sms_sent' => $smsSent,
                            'order_id' => $orderId,
                            'order_number' => $orderNumber
                        ];
                    } else {
                        $response = ['success' => false, 'message' => 'Database update failed'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Status is already ' . ucfirst($oldStatus)];
                }
            } else {
                $response = ['success' => false, 'message' => 'Order not found or access denied'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Invalid order ID or status'];
        }
    }
    
    // ---- TOGGLE ORDER (Cancel/Reactivate) ----
    if ($action === 'toggle_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        
        if ($orderId > 0) {
            $accessQuery = "SELECT o.*, u.name AS customer_name, u.phone AS customer_phone, u.email AS customer_email, o.order_number 
                            FROM orders o 
                            LEFT JOIN users u ON o.user_id = u.id 
                            WHERE o.id = $orderId";
            if (!$is_admin && $vendorId) {
                $accessQuery .= " AND o.vendor_id = $vendorId";
            }
            
            $currentResult = $conn->query($accessQuery);
            $currentOrder = $currentResult->fetch_assoc();
            
            if ($currentOrder) {
                $oldStatus = $currentOrder['order_status'];
                $orderNumber = $currentOrder['order_number'];
                
                if (in_array($oldStatus, ['cancelled', 'refunded'])) {
                    $newStatus = 'pending';
                    $actionLabel = 'reactivated';
                } elseif (!in_array($oldStatus, ['delivered'])) {
                    $newStatus = 'cancelled';
                    $actionLabel = 'cancelled';
                } else {
                    $response = ['success' => false, 'message' => 'Cannot modify delivered orders'];
                    echo json_encode($response);
                    exit;
                }
                
                $updateFields = "order_status = '$newStatus', updated_at = NOW()";
                if ($newStatus === 'cancelled' && $currentOrder['payment_status'] === 'paid') {
                    $updateFields .= ", payment_status = 'refunded'";
                }
                if ($newStatus === 'pending' && $currentOrder['payment_status'] === 'refunded') {
                    $updateFields .= ", payment_status = 'unpaid'";
                }
                
                if ($conn->query("UPDATE orders SET $updateFields WHERE id = $orderId")) {
                    $conn->query("INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, notes, created_at) VALUES ($orderId, '$oldStatus', '$newStatus', $userId, 'Order $actionLabel', NOW())");
                    
                    if ($newStatus === 'cancelled') {
                        $conn->query("UPDATE deliveries SET status = 'cancelled' WHERE order_id = $orderId");
                    } else {
                        $conn->query("UPDATE deliveries SET status = 'pending' WHERE order_id = $orderId");
                    }
                    
                    // Send SMS
                    $smsSent = false;
                    if ($currentOrder['customer_phone']) {
                        $name = $currentOrder['customer_name'] ?? 'Customer';
                        $smsMsg = ($newStatus === 'cancelled') 
                            ? "Dear $name, your order #$orderNumber has been cancelled. Contact support for inquiries. - Bless Mall"
                            : "Dear $name, your order #$orderNumber has been reactivated and is now pending processing. - Bless Mall";
                        $smsSent = blessmallSendSMS($currentOrder['customer_phone'], $smsMsg);
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => "Order #$orderNumber $actionLabel",
                        'new_status' => $newStatus,
                        'old_status' => $oldStatus,
                        'sms_sent' => $smsSent,
                        'order_id' => $orderId,
                        'order_number' => $orderNumber
                    ];
                } else {
                    $response = ['success' => false, 'message' => 'Database update failed'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Order not found or access denied'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Invalid order ID'];
        }
    }
    
    // ---- SEND MANUAL SMS ----
    if ($action === 'send_sms') {
        $phone = $_POST['phone'] ?? '';
        $smsMessage = $_POST['message'] ?? '';
        
        if (!empty($phone) && !empty($smsMessage)) {
            $sent = blessmallSendSMS($phone, $smsMessage);
            $response = [
                'success' => $sent,
                'message' => $sent ? 'SMS sent successfully' : 'Failed to send SMS',
                'phone' => $phone
            ];
        } else {
            $response = ['success' => false, 'message' => 'Phone number and message are required'];
        }
    }
    
    // ---- GET ORDER DETAILS ----
    if ($action === 'get_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        
        if ($orderId > 0) {
            $accessQuery = "SELECT o.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                            v.business_name AS vendor_name, v.contact_phone AS vendor_phone, v.whatsapp AS vendor_whatsapp,
                            d.tracking_ref, d.delivery_partner, d.status AS delivery_status, d.estimated_delivery_date, d.actual_delivery_date, d.rider_phone,
                            oa.address_line1, oa.address_line2, oa.city, oa.region, oa.phone AS delivery_phone,
                            p.amount AS paid_amount, p.transaction_id, p.paid_at
                            FROM orders o
                            LEFT JOIN users u ON o.user_id = u.id
                            LEFT JOIN vendors v ON o.vendor_id = v.id
                            LEFT JOIN deliveries d ON o.id = d.order_id
                            LEFT JOIN order_addresses oa ON o.id = oa.order_id
                            LEFT JOIN payments p ON o.id = p.order_id
                            WHERE o.id = $orderId";
            
            if (!$is_admin && $vendorId) {
                $accessQuery .= " AND o.vendor_id = $vendorId";
            }
            
            $oq = $conn->query($accessQuery);
            
            if ($oq && $oq->num_rows > 0) {
                $order = $oq->fetch_assoc();
                
                // Get order items
                $items = [];
                $iq = $conn->query("SELECT oi.*, p.name AS product_name, p.sku, ppt.tier_name 
                                    FROM order_items oi 
                                    JOIN products p ON oi.product_id = p.id 
                                    LEFT JOIN product_pricing_tiers ppt ON oi.pricing_tier_id = ppt.id 
                                    WHERE oi.order_id = $orderId");
                while ($item = $iq->fetch_assoc()) {
                    $items[] = $item;
                }
                $order['items'] = $items;
                
                // Get status history
                $history = [];
                $hq = $conn->query("SELECT osh.*, u.name AS changed_by_name 
                                    FROM order_status_history osh 
                                    LEFT JOIN users u ON osh.changed_by = u.id 
                                    WHERE osh.order_id = $orderId 
                                    ORDER BY osh.created_at DESC");
                while ($h = $hq->fetch_assoc()) {
                    $history[] = $h;
                }
                $order['status_history'] = $history;
                
                $response = ['success' => true, 'order' => $order];
            } else {
                $response = ['success' => false, 'message' => 'Order not found'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Invalid order ID'];
        }
    }
    
    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// 5. FILTER PARAMETERS
// ------------------------------------------------------------
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

$statusFilter = $_GET['status'] ?? '';
$paymentFilter = $_GET['payment'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$vendorFilter = $_GET['vendor'] ?? '';
$deliveryFilter = $_GET['delivery_type'] ?? '';

// ------------------------------------------------------------
// 6. GET VENDOR ID FOR SELLERS
// ------------------------------------------------------------
$vendorId = null;
if (!$is_admin) {
    $vendorResult = $conn->query("SELECT id, business_name FROM vendors WHERE user_id = $userId LIMIT 1");
    if ($vendorResult && $vendorResult->num_rows > 0) {
        $vendorData = $vendorResult->fetch_assoc();
        $vendorId = (int)$vendorData['id'];
        $vendorName = $vendorData['business_name'];
    }
}

// ------------------------------------------------------------
// 7. BUILD WHERE CONDITIONS
// ------------------------------------------------------------
$whereConditions = [];

if (!$is_admin && $vendorId) {
    $whereConditions[] = "o.vendor_id = $vendorId";
}

if (!empty($statusFilter)) {
    $whereConditions[] = "o.order_status = '" . $conn->real_escape_string($statusFilter) . "'";
}
if (!empty($paymentFilter)) {
    $whereConditions[] = "o.payment_status = '" . $conn->real_escape_string($paymentFilter) . "'";
}
if (!empty($deliveryFilter)) {
    $whereConditions[] = "o.delivery_type = '" . $conn->real_escape_string($deliveryFilter) . "'";
}
if (!empty($vendorFilter) && $is_admin) {
    $whereConditions[] = "v.business_name LIKE '%" . $conn->real_escape_string($vendorFilter) . "%'";
}
if (!empty($searchFilter)) {
    $searchEsc = $conn->real_escape_string($searchFilter);
    $whereConditions[] = "(o.order_number LIKE '%$searchEsc%' OR u.name LIKE '%$searchEsc%' OR u.email LIKE '%$searchEsc%' OR u.phone LIKE '%$searchEsc%')";
}
if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(o.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
}
if (!empty($dateTo)) {
    $whereConditions[] = "DATE(o.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// ------------------------------------------------------------
// 8. GET STATISTICS
// ------------------------------------------------------------
$statsQuery = "SELECT 
    COUNT(*) AS total_orders,
    SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
    SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
    SUM(CASE WHEN order_status = 'packed' THEN 1 ELSE 0 END) AS packed_count,
    SUM(CASE WHEN order_status = 'shipped' THEN 1 ELSE 0 END) AS shipped_count,
    SUM(CASE WHEN order_status = 'in_transit' THEN 1 ELSE 0 END) AS intransit_count,
    SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
    SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
    SUM(CASE WHEN order_status = 'refunded' THEN 1 ELSE 0 END) AS refunded_count,
    SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
    COALESCE(SUM(CASE WHEN order_status NOT IN ('cancelled','refunded') THEN total_amount ELSE 0 END), 0) AS total_revenue,
    COALESCE(SUM(CASE WHEN payment_status = 'unpaid' AND order_status NOT IN ('cancelled','refunded') THEN total_amount ELSE 0 END), 0) AS pending_payment
FROM orders o 
LEFT JOIN users u ON o.user_id = u.id 
LEFT JOIN vendors v ON o.vendor_id = v.id 
$whereClause";

$statsResult = $conn->query($statsQuery);
$stats = $statsResult->fetch_assoc();

// ------------------------------------------------------------
// 9. GET ORDERS (Sorted by time - newest first)
// ------------------------------------------------------------
$countQuery = "SELECT COUNT(DISTINCT o.id) AS total FROM orders o LEFT JOIN users u ON o.user_id = u.id LEFT JOIN vendors v ON o.vendor_id = v.id $whereClause";
$totalOrders = $conn->query($countQuery)->fetch_assoc()['total'];
$totalPages = ceil($totalOrders / $perPage);

$ordersQuery = "SELECT 
    o.id, o.order_number, o.subtotal, o.delivery_fee, o.discount_amount, o.total_amount,
    o.order_status, o.payment_status, o.payment_method, o.delivery_type, o.created_at, o.updated_at,
    u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
    v.business_name AS vendor_name, v.id AS vendor_id,
    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count,
    (SELECT d.tracking_ref FROM deliveries d WHERE d.order_id = o.id LIMIT 1) AS tracking_ref
FROM orders o
LEFT JOIN users u ON o.user_id = u.id
LEFT JOIN vendors v ON o.vendor_id = v.id
$whereClause
ORDER BY o.created_at DESC
LIMIT $offset, $perPage";

$ordersResult = $conn->query($ordersQuery);
$orders = [];
while ($ordersResult && $row = $ordersResult->fetch_assoc()) {
    $orders[] = $row;
}

// ------------------------------------------------------------
// 10. HELPER FUNCTIONS
// ------------------------------------------------------------
function getStatusBadge($status) {
    $badges = [
        'pending' => ['class' => 'status-pending', 'label' => '⏳ Pending', 'icon' => '🕐'],
        'confirmed' => ['class' => 'status-confirmed', 'label' => '✅ Confirmed', 'icon' => '✓'],
        'processing' => ['class' => 'status-processing', 'label' => '⚙️ Processing', 'icon' => '⚡'],
        'packed' => ['class' => 'status-packed', 'label' => '📦 Packed', 'icon' => '📦'],
        'shipped' => ['class' => 'status-shipped', 'label' => '🚚 Shipped', 'icon' => '🚛'],
        'in_transit' => ['class' => 'status-intransit', 'label' => '🚛 In Transit', 'icon' => '🚚'],
        'delivered' => ['class' => 'status-delivered', 'label' => '✓ Delivered', 'icon' => '🏠'],
        'cancelled' => ['class' => 'status-cancelled', 'label' => '✕ Cancelled', 'icon' => '❌'],
        'refunded' => ['class' => 'status-refunded', 'label' => '↩ Refunded', 'icon' => '💰']
    ];
    $b = $badges[$status] ?? ['class' => 'status-default', 'label' => ucfirst(str_replace('_', ' ', $status)), 'icon' => '📋'];
    return '<span class="status-badge ' . $b['class'] . '" data-status="' . $status . '">' . $b['icon'] . ' ' . $b['label'] . '</span>';
}

function getPaymentBadge($status) {
    $badges = [
        'unpaid' => ['class' => 'payment-unpaid', 'label' => 'Unpaid', 'icon' => '⚠️'],
        'paid' => ['class' => 'payment-paid', 'label' => 'Paid', 'icon' => '✓'],
        'partially_paid' => ['class' => 'payment-partial', 'label' => 'Partial', 'icon' => '🔄'],
        'refunded' => ['class' => 'payment-refunded', 'label' => 'Refunded', 'icon' => '↩']
    ];
    $b = $badges[$status] ?? ['class' => 'payment-default', 'label' => ucfirst($status), 'icon' => '💰'];
    return '<span class="payment-badge ' . $b['class'] . '">' . $b['icon'] . ' ' . $b['label'] . '</span>';
}

function formatDate($date) {
    return date('M d, Y', strtotime($date));
}
function formatTime($date) {
    return date('h:i A', strtotime($date));
}
function formatDateTime($date) {
    return date('M d, Y h:i A', strtotime($date));
}
function formatCurrency($amount) {
    return '₵ ' . number_format($amount, 2);
}
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

$page_title = $is_admin ? 'Orders Management - Bless Mall Admin' : 'Store Orders - ' . ($vendorName ?? 'Bless Mall');
require_once __DIR__ . '/header.php';
?>

<style>
/* Modern Admin Styles */
:root {
    --admin-primary: #0B6E3D;
    --admin-primary-light: #1FAF5B;
    --admin-bg: #0F1117;
    --admin-surface: #1A1D23;
    --admin-border: #2A2F3A;
    --text-primary: #F1F5F9;
    --text-secondary: #94A3B8;
    --text-muted: #64748B;
}

/* Notification Toast */
.toast-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10000;
    animation: slideInRight 0.3s ease;
}
@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.toast-notification .toast {
    background: #1A1D23;
    border-radius: 12px;
    padding: 14px 20px;
    min-width: 280px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.3);
    border-left: 4px solid;
}
.toast-notification .toast.success { border-left-color: #10b981; }
.toast-notification .toast.error { border-left-color: #ef4444; }
.toast-notification .toast.warning { border-left-color: #f59e0b; }
.toast-notification .toast.info { border-left-color: #3b82f6; }
.toast-message { color: var(--text-primary); font-size: 14px; }
.toast-close { cursor: pointer; opacity: 0.7; transition: opacity 0.2s; }
.toast-close:hover { opacity: 1; }

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    padding: 12px;
    text-decoration: none;
    color: inherit;
    display: block;
    cursor: pointer;
    transition: all 0.2s;
}
.stat-card:hover {
    border-color: var(--admin-primary);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.stat-card-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    margin-bottom: 10px;
}
.stat-card-value {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-primary);
}
.stat-card-label {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
}

/* Filter Bar */
.filter-bar {
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    padding: 15px 20px;
    margin-bottom: 24px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
}
.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.filter-select, .filter-input {
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    color: var(--text-primary);
    padding: 8px 32px 8px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
}
.filter-select {
    appearance: none;
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2394A3B8' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    min-width: 130px;
}
.filter-input {
    min-width: 150px;
}
.filter-input:focus, .filter-select:focus {
    outline: none;
    border-color: var(--admin-primary);
}
.btn-filter, .btn-reset {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: inherit;
}
.btn-filter {
    background: var(--admin-primary);
    color: white;
    border: none;
}
.btn-filter:hover {
    background: var(--admin-primary-light);
    transform: translateY(-1px);
}
.btn-reset {
    background: transparent;
    border: 1px solid var(--admin-border);
    color: var(--text-secondary);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.btn-reset:hover {
    border-color: var(--admin-primary);
    color: var(--admin-primary);
}

/* Table Styles */
.table-container {
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    overflow: hidden;
}
.table-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.table-header h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}
.orders-table {
    width: 100%;
    border-collapse: collapse;
}
.orders-table th {
    text-align: left;
    padding: 12px 15px;
    background: var(--admin-bg);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--admin-border);
    white-space: nowrap;
}
.orders-table td {
    padding: 12px 15px;
    border-bottom: 1px solid var(--admin-border);
    font-size: 13px;
    vertical-align: middle;
}
.orders-table tbody tr {
    transition: background 0.2s;
    cursor: pointer;
}
.orders-table tbody tr:hover {
    background: rgba(11, 110, 61, 0.05);
}
.orders-table tr.row-cancelled {
    opacity: 0.6;
}
.order-number {
    font-weight: 600;
    color: var(--admin-primary);
    font-family: monospace;
    font-size: 14px;
}
.customer-name {
    font-weight: 500;
}
.customer-contact {
    font-size: 11px;
    color: var(--text-muted);
    display: block;
}

/* Badges */
.status-badge, .payment-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.status-pending { background: rgba(245,158,11,0.15); color: #f59e0b; }
.status-confirmed { background: rgba(59,130,246,0.15); color: #3b82f6; }
.status-processing { background: rgba(139,92,246,0.15); color: #8b5cf6; }
.status-packed { background: rgba(6,182,212,0.15); color: #06b6d4; }
.status-shipped { background: rgba(6,182,212,0.15); color: #06b6d4; }
.status-intransit { background: rgba(6,182,212,0.15); color: #06b6d4; }
.status-delivered { background: rgba(16,185,129,0.15); color: #10b981; }
.status-cancelled { background: rgba(239,68,68,0.15); color: #ef4444; }
.status-refunded { background: rgba(100,116,139,0.15); color: #64748b; }

.payment-unpaid { background: rgba(245,158,11,0.15); color: #f59e0b; }
.payment-paid { background: rgba(16,185,129,0.15); color: #10b981; }
.payment-partial { background: rgba(139,92,246,0.15); color: #8b5cf6; }
.payment-refunded { background: rgba(100,116,139,0.15); color: #64748b; }

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.btn-action {
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    font-family: inherit;
}
.btn-view {
    background: rgba(59,130,246,0.15);
    color: #3b82f6;
}
.btn-view:hover {
    background: rgba(59,130,246,0.25);
    transform: translateY(-1px);
}
.btn-cancel {
    background: rgba(239,68,68,0.15);
    color: #ef4444;
}
.btn-cancel:hover {
    background: rgba(239,68,68,0.25);
}
.btn-reactivate {
    background: rgba(16,185,129,0.15);
    color: #10b981;
}
.btn-reactivate:hover {
    background: rgba(16,185,129,0.25);
}
.btn-sms {
    background: rgba(139,92,246,0.15);
    color: #8b5cf6;
}
.btn-sms:hover {
    background: rgba(139,92,246,0.25);
}
.btn-update-status {
    background: var(--admin-primary);
    color: white;
    padding: 8px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
}
.btn-update-status:hover {
    background: var(--admin-primary-light);
    transform: translateY(-1px);
}
.status-btn {
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid var(--admin-border);
    background: var(--admin-surface);
    color: var(--text-secondary);
    font-family: inherit;
    transition: all 0.2s;
}
.status-btn:hover {
    border-color: var(--admin-primary);
    color: var(--admin-primary);
}
.status-btn.active-status {
    border-color: var(--admin-primary);
    background: rgba(11, 110, 61, 0.15);
    color: var(--admin-primary);
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.85);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.active {
    display: flex;
}
.modal-dialog {
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: 16px;
    max-width: 950px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    animation: modalIn 0.25s ease;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.modal-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: var(--admin-surface);
    z-index: 1;
    border-radius: 16px 16px 0 0;
}
.modal-header h3 {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
}
.modal-close {
    width: 36px;
    height: 36px;
    border: 1px solid var(--admin-border);
    background: transparent;
    border-radius: 8px;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 20px;
    transition: all 0.2s;
}
.modal-close:hover {
    background: rgba(239,68,68,0.15);
    color: #ef4444;
    border-color: #ef4444;
}
.modal-body {
    padding: 24px;
}
.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.detail-card {
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: 10px;
    padding: 14px;
}
.detail-label {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.detail-value {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-primary);
}
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}
.items-table th {
    text-align: left;
    padding: 10px 12px;
    background: var(--admin-bg);
    font-size: 11px;
    text-transform: uppercase;
    color: var(--text-muted);
    border-bottom: 1px solid var(--admin-border);
}
.items-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--admin-border);
    font-size: 13px;
}
.status-update-section {
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: 10px;
    padding: 16px;
    margin-top: 20px;
}
.status-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 15px;
}
.timeline {
    position: relative;
    padding-left: 24px;
    margin-top: 15px;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--admin-border);
}
.timeline-item {
    position: relative;
    padding-bottom: 16px;
}
.timeline-dot {
    position: absolute;
    left: -20px;
    top: 3px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--admin-surface);
    border: 2px solid var(--admin-primary);
}
.timeline-dot.success { border-color: #10b981; }
.timeline-dot.danger { border-color: #ef4444; }
.timeline-dot.warning { border-color: #f59e0b; }
.timeline-content {
    font-size: 12px;
}
.tl-status {
    font-weight: 600;
}
.tl-date {
    font-size: 11px;
    color: var(--text-muted);
}
.tl-notes {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 4px;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 24px;
}
.pagination a, .pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 12px;
    border-radius: 8px;
    font-size: 13px;
    text-decoration: none;
    border: 1px solid var(--admin-border);
    color: var(--text-secondary);
    background: var(--admin-surface);
    transition: all 0.2s;
}
.pagination a:hover {
    border-color: var(--admin-primary);
    color: var(--admin-primary);
}
.pagination .active {
    background: var(--admin-primary);
    color: white;
    border-color: var(--admin-primary);
}
.pagination .disabled {
    opacity: 0.4;
    pointer-events: none;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}
.empty-state-icon {
    font-size: 48px;
    margin-bottom: 16px;
}
.empty-state h4 {
    margin: 10px 0 4px;
    color: var(--text-primary);
    font-size: 18px;
}
.empty-state p {
    font-size: 13px;
}

.loading-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid var(--admin-border);
    border-top-color: var(--admin-primary);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .filter-bar { flex-direction: column; }
    .filter-select, .filter-input { width: 100%; }
    .orders-table { display: block; overflow-x: auto; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .detail-grid { grid-template-columns: 1fr; }
}
</style>

<div class="admin-container" style="padding: 20px;">
    
    <!-- Breadcrumb -->
    <div class="admin-breadcrumb" style="margin-bottom: 20px; font-size: 13px; color: var(--text-muted);">
        <a href="index.php" style="color: var(--admin-primary); text-decoration: none;">Dashboard</a> 
        <span>›</span> Orders Management
        <?php if (!$is_admin && isset($vendorName)): ?>
        <span>›</span> <span style="color: var(--text-primary);"><?= e($vendorName) ?></span>
        <?php endif; ?>
    </div>
    
    <!-- Page Header -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 24px; margin: 0 0 8px 0;">📋 Orders Management</h1>
        <p style="color: var(--text-muted); margin: 0;">Manage customer orders, update statuses, send SMS notifications</p>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <a href="?status=pending" class="stat-card">
            <div class="stat-card-icon" style="background: rgba(245,158,11,0.15);">⏳</div>
            <div class="stat-card-value"><?= number_format($stats['pending_count'] ?? 0) ?></div>
            <div class="stat-card-label">Pending</div>
        </a>
        <a href="?status=confirmed" class="stat-card">
            <div class="stat-card-icon" style="background: rgba(59,130,246,0.15);">✅</div>
            <div class="stat-card-value"><?= number_format($stats['confirmed_count'] ?? 0) ?></div>
            <div class="stat-card-label">Confirmed</div>
        </a>
        <a href="?status=processing" class="stat-card">
            <div class="stat-card-icon" style="background: rgba(139,92,246,0.15);">⚙️</div>
            <div class="stat-card-value"><?= number_format($stats['processing_count'] ?? 0) ?></div>
            <div class="stat-card-label">Processing</div>
        </a>
        <a href="?status=packed" class="stat-card">
            <div class="stat-card-icon" style="background: rgba(6,182,212,0.15);">📦</div>
            <div class="stat-card-value"><?= number_format($stats['packed_count'] ?? 0) ?></div>
            <div class="stat-card-label">Packed</div>
        </a>
        <a href="?status=shipped" class="stat-card">
            <div class="stat-card-icon" style="background: rgba(6,182,212,0.15);">🚚</div>
            <div class="stat-card-value"><?= number_format(($stats['shipped_count'] ?? 0) + ($stats['intransit_count'] ?? 0)) ?></div>
            <div class="stat-card-label">Shipped/Transit</div>
        </a>
        <a href="?status=delivered" class="stat-card">
            <div class="stat-card-icon" style="background: rgba(16,185,129,0.15);">✓</div>
            <div class="stat-card-value"><?= number_format($stats['delivered_count'] ?? 0) ?></div>
            <div class="stat-card-label">Delivered</div>
        </a>
        <a href="?status=cancelled" class="stat-card">
            <div class="stat-card-icon" style="background: rgba(239,68,68,0.15);">✕</div>
            <div class="stat-card-value"><?= number_format(($stats['cancelled_count'] ?? 0) + ($stats['refunded_count'] ?? 0)) ?></div>
            <div class="stat-card-label">Cancelled/Refunded</div>
        </a>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: rgba(59,130,246,0.15);">💰</div>
            <div class="stat-card-value"><?= formatCurrency($stats['total_revenue'] ?? 0) ?></div>
            <div class="stat-card-label">Revenue</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: rgba(245,158,11,0.15);">⚠️</div>
            <div class="stat-card-value"><?= formatCurrency($stats['pending_payment'] ?? 0) ?></div>
            <div class="stat-card-label">Pending Payment</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: rgba(16,185,129,0.15);">📊</div>
            <div class="stat-card-value"><?= number_format($stats['total_orders'] ?? 0) ?></div>
            <div class="stat-card-label">Total Orders</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <form id="filterForm" method="GET" action="orders.php" class="filter-bar">
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
            <option value="processing" <?= $statusFilter === 'processing' ? 'selected' : '' ?>>Processing</option>
            <option value="packed" <?= $statusFilter === 'packed' ? 'selected' : '' ?>>Packed</option>
            <option value="shipped" <?= $statusFilter === 'shipped' ? 'selected' : '' ?>>Shipped</option>
            <option value="in_transit" <?= $statusFilter === 'in_transit' ? 'selected' : '' ?>>In Transit</option>
            <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            <option value="refunded" <?= $statusFilter === 'refunded' ? 'selected' : '' ?>>Refunded</option>
        </select>
        
        <select name="payment" class="filter-select" onchange="this.form.submit()">
            <option value="">All Payments</option>
            <option value="unpaid" <?= $paymentFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
            <option value="paid" <?= $paymentFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="partially_paid" <?= $paymentFilter === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
            <option value="refunded" <?= $paymentFilter === 'refunded' ? 'selected' : '' ?>>Refunded</option>
        </select>
        
        <select name="delivery_type" class="filter-select" onchange="this.form.submit()">
            <option value="">All Types</option>
            <option value="delivery" <?= $deliveryFilter === 'delivery' ? 'selected' : '' ?>>Delivery</option>
            <option value="pickup" <?= $deliveryFilter === 'pickup' ? 'selected' : '' ?>>Pickup</option>
        </select>
        
        <input type="date" name="date_from" class="filter-input" value="<?= e($dateFrom) ?>" onchange="this.form.submit()">
        <span style="color: var(--text-muted);">to</span>
        <input type="date" name="date_to" class="filter-input" value="<?= e($dateTo) ?>" onchange="this.form.submit()">
        
        <div class="filter-group" style="flex: 1;">
            <input type="text" name="search" class="filter-input" value="<?= e($searchFilter) ?>" placeholder="Order #, customer..." style="width: 100%;">
            <button type="submit" class="btn-filter">🔍 Search</button>
        </div>
        
        <a href="orders.php" class="btn-reset">↻ Reset</a>
    </form>
    
    <!-- Orders Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>📝 Orders</h3>
            <span style="font-size: 13px; color: var(--text-muted);"><?= number_format($totalOrders) ?> total orders</span>
        </div>
        
        <?php if (!empty($orders)): ?>
        <div style="overflow-x: auto;">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <?php if ($is_admin): ?>
                        <th>Vendor</th>
                        <?php endif; ?>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Type</th>
                        <th>Date & Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="ordersTableBody">
                    <?php foreach ($orders as $order): 
                        $isCancelled = in_array($order['order_status'], ['cancelled', 'refunded']);
                        $isDelivered = $order['order_status'] === 'delivered';
                    ?>
                    <tr data-order-id="<?= $order['id'] ?>" data-order-status="<?= $order['order_status'] ?>" class="<?= $isCancelled ? 'row-cancelled' : '' ?>">
                        <td>
                            <span class="order-number" onclick="viewOrder(<?= $order['id'] ?>)">
                                #<?= e($order['order_number']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="customer-name"><?= e($order['customer_name'] ?? 'Guest') ?></div>
                            <div class="customer-contact"><?= e($order['customer_phone'] ?? 'No phone') ?></div>
                        </td>
                        <?php if ($is_admin): ?>
                        <td><?= e($order['vendor_name'] ?? 'N/A') ?></td>
                        <?php endif; ?>
                        <td><strong><?= $order['item_count'] ?></strong> items</td>
                        <td><strong><?= formatCurrency($order['total_amount']) ?></strong></td>
                        <td><?= getStatusBadge($order['order_status']) ?></td>
                        <td><?= getPaymentBadge($order['payment_status']) ?></td>
                        <td style="text-transform: capitalize;"><?= $order['delivery_type'] ?></td>
                        <td>
                            <div><?= formatDate($order['created_at']) ?></div>
                            <div style="font-size: 11px; color: var(--text-muted);"><?= formatTime($order['created_at']) ?></div>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-action btn-view" onclick="viewOrder(<?= $order['id'] ?>)">👁 View</button>
                                
                                <?php if (!$isDelivered): ?>
                                    <?php if ($isCancelled): ?>
                                        <button class="btn-action btn-reactivate" onclick="toggleOrder(<?= $order['id'] ?>, 'reactivate')">↩ Accept</button>
                                    <?php else: ?>
                                        <button class="btn-action btn-cancel" onclick="toggleOrder(<?= $order['id'] ?>, 'cancel')">✕ Cancel</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">📭</div>
            <h4>No Orders Found</h4>
            <p>No orders match your current filters. Try adjusting your search criteria.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php 
        $qp = $_GET;
        unset($qp['page']);
        $bq = http_build_query($qp);
        $bu = 'orders.php' . ($bq ? '?' . $bq . '&' : '?');
        
        if ($page > 1): ?>
            <a href="<?= $bu ?>page=<?= $page - 1 ?>">‹ Prev</a>
        <?php else: ?>
            <span class="disabled">‹ Prev</span>
        <?php endif;
        
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        if ($startPage > 1): ?>
            <a href="<?= $bu ?>page=1">1</a>
            <?php if ($startPage > 2): ?>
                <span class="disabled">...</span>
            <?php endif;
        endif;
        
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="<?= $bu ?>page=<?= $i ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor;
        
        if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <span class="disabled">...</span>
            <?php endif; ?>
            <a href="<?= $bu ?>page=<?= $totalPages ?>"><?= $totalPages ?></a>
        <?php endif;
        
        if ($page < $totalPages): ?>
            <a href="<?= $bu ?>page=<?= $page + 1 ?>">Next ›</a>
        <?php else: ?>
            <span class="disabled">Next ›</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Order Detail Modal -->
<div class="modal-overlay" id="orderModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>📋 Order <span id="modalOrderNum" style="color: var(--admin-primary);"></span></h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body" id="modalContent">
            <div style="text-align: center; padding: 40px;">
                <div class="loading-spinner"></div>
                <p style="margin-top: 15px; color: var(--text-muted);">Loading order details...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Global state
let currentOrderId = null;

// Helper: Show toast notification
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toastContainer = document.createElement('div');
    toastContainer.className = 'toast-notification';
    toastContainer.innerHTML = `
        <div class="toast ${type}">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px;">
                <div class="toast-message">${message}</div>
                <div class="toast-close" onclick="this.closest('.toast-notification').remove()">✕</div>
            </div>
        </div>
    `;
    document.body.appendChild(toastContainer);
    
    setTimeout(() => {
        if (toastContainer) toastContainer.remove();
    }, 5000);
}

// Helper: Update order row status without refresh
function updateOrderRowStatus(orderId, newStatus, oldStatus, orderNumber) {
    const row = document.querySelector(`tr[data-order-id="${orderId}"]`);
    if (!row) return;
    
    // Update data attribute
    row.setAttribute('data-order-status', newStatus);
    
    // Update status badge
    const statusCell = row.querySelector('td:nth-child(' + (document.querySelector('th:nth-child(6)')?.cellIndex + 1) + ')');
    if (statusCell) {
        const isCancelled = newStatus === 'cancelled' || newStatus === 'refunded';
        const isDelivered = newStatus === 'delivered';
        
        // Update badge
        let badgeHtml = '';
        const badges = {
            pending: { class: 'status-pending', label: '⏳ Pending', icon: '🕐' },
            confirmed: { class: 'status-confirmed', label: '✅ Confirmed', icon: '✓' },
            processing: { class: 'status-processing', label: '⚙️ Processing', icon: '⚡' },
            packed: { class: 'status-packed', label: '📦 Packed', icon: '📦' },
            shipped: { class: 'status-shipped', label: '🚚 Shipped', icon: '🚛' },
            in_transit: { class: 'status-intransit', label: '🚛 In Transit', icon: '🚚' },
            delivered: { class: 'status-delivered', label: '✓ Delivered', icon: '🏠' },
            cancelled: { class: 'status-cancelled', label: '✕ Cancelled', icon: '❌' },
            refunded: { class: 'status-refunded', label: '↩ Refunded', icon: '💰' }
        };
        const b = badges[newStatus] || { class: 'status-default', label: newStatus.replace(/_/g, ' '), icon: '📋' };
        badgeHtml = `<span class="status-badge ${b.class}" data-status="${newStatus}">${b.icon} ${b.label}</span>`;
        statusCell.innerHTML = badgeHtml;
        
        // Update row class
        if (isCancelled) {
            row.classList.add('row-cancelled');
        } else {
            row.classList.remove('row-cancelled');
        }
        
        // Update action buttons
        const actionsCell = row.querySelector('td:last-child');
        if (actionsCell && !isDelivered) {
            const cancelBtn = actionsCell.querySelector('.btn-cancel, .btn-reactivate');
            if (cancelBtn) {
                if (isCancelled) {
                    cancelBtn.className = 'btn-action btn-reactivate';
                    cancelBtn.textContent = '↩ Accept';
                    cancelBtn.setAttribute('onclick', `toggleOrder(${orderId}, 'reactivate')`);
                } else {
                    cancelBtn.className = 'btn-action btn-cancel';
                    cancelBtn.textContent = '✕ Cancel';
                    cancelBtn.setAttribute('onclick', `toggleOrder(${orderId}, 'cancel')`);
                }
            }
        }
    }
    
    showToast(`Order #${orderNumber} updated successfully!`, 'success');
}

const orderCsrfToken = <?php echo json_encode($csrfToken); ?>;

// View order details
function viewOrder(orderId) {
    currentOrderId = orderId;
    const modal = document.getElementById('orderModal');
    const content = document.getElementById('modalContent');
    modal.classList.add('active');
    
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading-spinner"></div><p style="margin-top: 15px; color: var(--text-muted);">Loading order details...</p></div>';
    
    fetch('orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_order&order_id=' + orderId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderOrderModal(data.order);
        } else {
            content.innerHTML = `<div style="text-align: center; padding: 40px; color: #ef4444;">❌ ${data.message}</div>`;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">❌ Failed to load order details</div>';
    });
}

// Render order modal
function renderOrderModal(order) {
    const content = document.getElementById('modalContent');
    document.getElementById('modalOrderNum').textContent = '#' + order.order_number;
    
    // Items HTML
    let itemsHtml = '';
    if (order.items && order.items.length > 0) {
        order.items.forEach(item => {
            itemsHtml += `
                <tr>
                    <td><strong>${escapeHtml(item.product_name)}</strong>${item.tier_name ? `<br><small style="color: var(--text-muted);">${escapeHtml(item.tier_name)}</small>` : ''}</td>
                    <td>${item.quantity}</td>
                    <td>${formatCurrency(item.unit_price)}</td>
                    <td><strong>${formatCurrency(item.subtotal)}</strong></td>
                </tr>
            `;
        });
    } else {
        itemsHtml = '<tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No items found</td></tr>';
    }
    
    // Status history HTML
    let historyHtml = '';
    if (order.status_history && order.status_history.length > 0) {
        order.status_history.forEach(h => {
            let dotClass = '';
            if (h.new_status === 'delivered') dotClass = 'success';
            else if (h.new_status === 'cancelled') dotClass = 'danger';
            else if (h.new_status === 'pending') dotClass = 'warning';
            historyHtml += `
                <div class="timeline-item">
                    <div class="timeline-dot ${dotClass}"></div>
                    <div class="timeline-content">
                        <div class="tl-status">${formatStatusLabel(h.new_status)} ${h.changed_by_name ? `<span style="font-weight: normal; color: var(--text-muted);">by ${escapeHtml(h.changed_by_name)}</span>` : ''}</div>
                        <div class="tl-date">${formatDateTime(h.created_at)}</div>
                        ${h.notes ? `<div class="tl-notes">📝 ${escapeHtml(h.notes)}</div>` : ''}
                    </div>
                </div>
            `;
        });
    } else {
        historyHtml = '<p style="font-size: 13px; color: var(--text-muted); text-align: center;">No status changes yet</p>';
    }
    
    // Status buttons
    const statuses = ['pending', 'confirmed', 'processing', 'packed', 'shipped', 'in_transit', 'delivered', 'cancelled', 'refunded'];
    let statusBtns = '';
    statuses.forEach(s => {
        const active = order.order_status === s ? 'active-status' : '';
        statusBtns += `<button type="button" class="status-btn ${active}" onclick="selectStatus(this, '${s}')">${formatStatusLabel(s)}</button>`;
    });
    
    // Determine if status update is allowed
    const isDelivered = order.order_status === 'delivered';
    const isCancelled = order.order_status === 'cancelled' || order.order_status === 'refunded';
    
    content.innerHTML = `
        <div class="detail-grid">
            <div class="detail-card">
                <div class="detail-label">👤 Customer</div>
                <div class="detail-value">${escapeHtml(order.customer_name || 'N/A')}</div>
                ${order.customer_email ? `<div style="font-size: 12px; color: var(--text-muted);">📧 ${escapeHtml(order.customer_email)}</div>` : ''}
                ${order.customer_phone ? `<div style="font-size: 12px; color: var(--text-muted);">📱 ${escapeHtml(order.customer_phone)}</div>` : ''}
                ${order.customer_phone ? `<button class="btn-action btn-sms" onclick="sendManualSMS('${escapeHtml(order.customer_phone)}')" style="margin-top: 10px; width: 100%;">💬 Send SMS</button>` : ''}
            </div>
            <div class="detail-card">
                <div class="detail-label">🏪 Vendor</div>
                <div class="detail-value">${escapeHtml(order.vendor_name || 'N/A')}</div>
                ${order.vendor_phone ? `<div style="font-size: 12px; color: var(--text-muted);">📞 ${escapeHtml(order.vendor_phone)}</div>` : ''}
            </div>
            <div class="detail-card">
                <div class="detail-label">📅 Order Date</div>
                <div class="detail-value">${formatDateTime(order.created_at)}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">💳 Payment</div>
                <div class="detail-value">${order.payment_method ? order.payment_method.replace(/_/g, ' ').toUpperCase() : 'N/A'}</div>
                ${order.transaction_id ? `<div style="font-size: 12px; color: var(--text-muted);">Ref: ${escapeHtml(order.transaction_id)}</div>` : ''}
                ${order.paid_at ? `<div style="font-size: 12px; color: #10b981;">✓ Paid: ${formatDateTime(order.paid_at)}</div>` : ''}
            </div>
            <div class="detail-card">
                <div class="detail-label">🚚 Delivery</div>
                <div class="detail-value">${order.delivery_type === 'delivery' ? 'Home Delivery' : 'Store Pickup'}</div>
                ${order.tracking_ref ? `<div style="font-size: 12px; color: var(--admin-primary);">Tracking: ${escapeHtml(order.tracking_ref)}</div>` : ''}
                ${order.rider_phone ? `<div style="font-size: 12px;">Rider: 📱 ${escapeHtml(order.rider_phone)}</div>` : ''}
            </div>
            ${order.address_line1 ? `
            <div class="detail-card">
                <div class="detail-label">📍 Address</div>
                <div class="detail-value">${escapeHtml(order.address_line1)}${order.address_line2 ? ', ' + escapeHtml(order.address_line2) : ''}</div>
                <div style="font-size: 12px; color: var(--text-muted);">${escapeHtml(order.city || '')} ${escapeHtml(order.region || '')}</div>
                ${order.delivery_phone ? `<div style="font-size: 12px;">📱 ${escapeHtml(order.delivery_phone)}</div>` : ''}
            </div>
            ` : ''}
        </div>
        
        <div class="detail-grid" style="grid-template-columns: repeat(4, 1fr);">
            <div class="detail-card"><div class="detail-label">Subtotal</div><div class="detail-value">${formatCurrency(order.subtotal)}</div></div>
            <div class="detail-card"><div class="detail-label">Delivery Fee</div><div class="detail-value">${formatCurrency(order.delivery_fee)}</div></div>
            <div class="detail-card"><div class="detail-label">Discount</div><div class="detail-value" style="color: #ef4444;">-${formatCurrency(order.discount_amount)}</div></div>
            <div class="detail-card"><div class="detail-label">Total</div><div class="detail-value" style="color: #10b981; font-size: 16px;">${formatCurrency(order.total_amount)}</div></div>
        </div>
        
        <h4 style="margin: 20px 0 12px 0;">🛒 Order Items</h4>
        <table class="items-table">
            <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
            <tbody>${itemsHtml}</tbody>
        </table>
        
        ${!isDelivered ? `
        <div class="status-update-section">
            <h4 style="margin-bottom: 12px;">🔄 Update Status</h4>
            <div class="status-buttons" id="statusButtons">
                ${statusBtns}
            </div>
            <form id="statusUpdateForm" onsubmit="updateOrderStatus(event, ${order.id})">
                <input type="hidden" name="new_status" id="selectedStatus" value="${order.order_status}">
                <div class="status-update-notes">
                    <textarea name="notes" id="statusNotes" placeholder="Add notes for this status update (optional)..." rows="2" style="width: 100%; padding: 10px; background: var(--admin-surface); border: 1px solid var(--admin-border); color: var(--text-primary); border-radius: 8px; resize: vertical;"></textarea>
                </div>
                <button type="submit" class="btn-update-status">💾 Update Status</button>
            </form>
        </div>
        ` : `
        <div class="status-update-section">
            <h4 style="color: #10b981;">✅ This order has been delivered</h4>
        </div>
        `}
        
        <h4 style="margin: 20px 0 12px 0;">📜 Status History</h4>
        <div class="timeline">${historyHtml}</div>
    `;
}

// Select status button
function selectStatus(btn, status) {
    const buttons = document.querySelectorAll('.status-btn');
    buttons.forEach(b => b.classList.remove('active-status'));
    btn.classList.add('active-status');
    document.getElementById('selectedStatus').value = status;
}

// Update order status via AJAX
function updateOrderStatus(event, orderId) {
    event.preventDefault();
    
    const newStatus = document.getElementById('selectedStatus').value;
    const notes = document.getElementById('statusNotes').value;
    const currentStatusBtn = document.querySelector('.status-btn.active-status');
    
    if (!currentStatusBtn) {
        showToast('Please select a status', 'warning');
        return;
    }
    
    const updateBtn = event.submitter;
    const originalText = updateBtn.textContent;
    updateBtn.textContent = '⏳ Updating...';
    updateBtn.disabled = true;
    
    fetch('orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=update_status&csrf_token=${encodeURIComponent(orderCsrfToken)}&order_id=${orderId}&new_status=${newStatus}&notes=${encodeURIComponent(notes)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            updateOrderRowStatus(orderId, newStatus, data.old_status, data.order_number);
            if (data.sms_sent) {
                showToast('SMS notification sent to customer', 'info');
            }
            // Refresh modal content
            viewOrder(orderId);
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to update order status', 'error');
    })
    .finally(() => {
        updateBtn.textContent = originalText;
        updateBtn.disabled = false;
    });
}

// Toggle order (cancel/reactivate)
function toggleOrder(orderId, action) {
    const confirmMsg = action === 'cancel' 
        ? 'Are you sure you want to CANCEL this order? This action will notify the customer.' 
        : 'Are you sure you want to REACTIVATE this order?';
    
    if (!confirm(confirmMsg)) return;
    
    const row = document.querySelector(`tr[data-order-id="${orderId}"]`);
    const toggleBtn = row?.querySelector(action === 'cancel' ? '.btn-cancel' : '.btn-reactivate');
    if (toggleBtn) {
        toggleBtn.textContent = '⏳ Processing...';
        toggleBtn.disabled = true;
    }
    
    fetch('orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=toggle_order&csrf_token=${encodeURIComponent(orderCsrfToken)}&order_id=${orderId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            updateOrderRowStatus(orderId, data.new_status, data.old_status, data.order_number);
            if (data.sms_sent) {
                showToast('SMS notification sent to customer', 'info');
            }
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to process request', 'error');
    })
    .finally(() => {
        if (toggleBtn) {
            toggleBtn.textContent = action === 'cancel' ? '✕ Cancel' : '↩ Accept';
            toggleBtn.disabled = false;
        }
    });
}

// Send manual SMS
function sendManualSMS(phone) {
    const message = prompt(`Enter SMS message to send to ${phone}:\n\nSender: BLESSMALLGH`);
    if (!message || !message.trim()) return;
    
    fetch('orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=send_sms&csrf_token=${encodeURIComponent(orderCsrfToken)}&phone=${encodeURIComponent(phone)}&message=${encodeURIComponent(message)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('SMS sent successfully!', 'success');
        } else {
            showToast(data.message || 'Failed to send SMS', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to send SMS', 'error');
    });
}

// Helper functions
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function formatCurrency(amount) {
    return '₵ ' + parseFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDateTime(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    return date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
}

function formatStatusLabel(status) {
    const labels = {
        'pending': 'Pending',
        'confirmed': 'Confirmed',
        'processing': 'Processing',
        'packed': 'Packed',
        'shipped': 'Shipped',
        'in_transit': 'In Transit',
        'delivered': 'Delivered',
        'cancelled': 'Cancelled',
        'refunded': 'Refunded'
    };
    return labels[status] || status.replace(/_/g, ' ');
}

function closeModal() {
    document.getElementById('orderModal').classList.remove('active');
}

// Modal close handlers
document.getElementById('orderModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>