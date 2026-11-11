<?php
/**
 * checkout.php - Bless Mall Secure Checkout v2.0
 * 
 * Features:
 * - Multi-step checkout (Delivery → Address → Payment → Review)
 * - Guest checkout with automatic account creation
 * - Saved addresses for logged-in users
 * - Paystack inline payment integration
 * - Mobile Money, Card, Bank Transfer, Cash on Delivery
 * - Real-time delivery fee calculation
 * - Order creation with proper foreign key handling
 * - Mobile-responsive premium UI
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load config
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

define('PAYSTACK_CALLBACK_URL', BASE_URL . '/checkout.php?callback=1');

// Redirect to cart if empty
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: cart.php');
    exit;
}

// ════════════════════════════════════════════════════════════════════
//  HANDLE AJAX REQUESTS (No separate API folder needed)
// ════════════════════════════════════════════════════════════════════
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['initiate_payment', 'verify_payment'], true)
        && !validateCheckoutCsrfToken($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'message' => 'Security validation failed. Please refresh and try again.']);
        exit;
    }
    
    // ═══ INITIALIZE PAYSTACK PAYMENT & CREATE ORDER ═══
    if ($action === 'initiate_payment') {
        try {
            $pdo = getAuthPDO();
            
            // Validate cart
            if (empty($_SESSION['cart'])) {
                echo json_encode(['success' => false, 'message' => 'Your cart is empty']);
                exit;
            }
            
            // Collect form data
            $delivery_type = $_POST['delivery_type'] ?? 'delivery';
            if (!in_array($delivery_type, ['delivery', 'pickup'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid delivery type']);
                exit;
            }
            $address_line1 = trim($_POST['address_line1'] ?? '');
            $address_line2 = trim($_POST['address_line2'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $region = trim($_POST['region'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $payment_method = $_POST['payment_method'] ?? 'momo';
            $save_address = isset($_POST['save_address']) && $_POST['save_address'] === '1';

            if (!in_array($payment_method, ['momo', 'card', 'bank_transfer', 'cash_on_delivery'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
                exit;
            }
            
            // Validate required fields
            $errors = [];
            if ($delivery_type === 'delivery') {
                if (empty($address_line1)) $errors[] = 'Address is required';
                if (empty($city)) $errors[] = 'City is required';
                if (empty($region)) $errors[] = 'Region is required';
            }
            if (empty($phone)) $errors[] = 'Phone number is required';
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'A valid email address is required';
            }
            
            if (!empty($errors)) {
                echo json_encode(['success' => false, 'message' => implode('. ', $errors)]);
                exit;
            }

            if ($payment_method !== 'cash_on_delivery' && PAYSTACK_SECRET_KEY === '') {
                echo json_encode(['success' => false, 'message' => 'Online payments are temporarily unavailable. Please choose cash on delivery.']);
                exit;
            }

            $cartValidation = validateCheckoutCart($pdo, $_SESSION['cart']);
            $_SESSION['cart'] = $cartValidation['items'];
            if ($cartValidation['price_changed']) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A product price changed. Please review your cart before checking out.',
                    'redirect' => 'cart.php',
                ]);
                exit;
            }
            
            // ═══ HANDLE USER (Guest or Logged-in) ═══
            $user_id = null;
            $user_name = 'Guest';
            
            if (Auth::isLoggedIn()) {
                $user_id = (int)$_SESSION['user_id'];
                $user_name = $_SESSION['user_name'] ?? 'Customer';
            } else {
                $stmt = $pdo->prepare("
                    SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1
                ");
                $stmt->execute([':email' => $email, ':phone' => $phone]);
                $existingUser = $stmt->fetch();

                if ($existingUser) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'An account already uses this email or phone. Please log in to continue.',
                        'redirect' => 'login.php?redirect=checkout.php',
                    ]);
                    exit;
                }

                $guestPassword = bin2hex(random_bytes(16));
                $hashedPassword = Auth::hashPassword($guestPassword);
                $guestName = !empty($address_line1) ? 'Guest - ' . $address_line1 : 'Guest Customer';

                $stmt = $pdo->prepare("
                    INSERT INTO users (role_id, name, email, phone, password, is_active, is_guest, account_type, created_at)
                    VALUES (:role, :name, :email, :phone, :pw, 1, 1, 'regular', NOW())
                ");
                $stmt->execute([
                    ':role' => ROLE_BUYER,
                    ':name' => substr($guestName, 0, 150),
                    ':email' => $email,
                    ':phone' => $phone,
                    ':pw' => $hashedPassword,
                ]);
                $user_id = (int)$pdo->lastInsertId();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_name'] = $user_name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_phone'] = $phone;
                $_SESSION['role_id'] = ROLE_BUYER;
                $_SESSION['account_type'] = 'regular';
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            }
            
            // Calculate order totals
            $cartData = calculateCheckoutTotals($delivery_type);
            $total_amount = $cartData['total'];
            
            // Generate order number
            $order_number = 'BLM-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            $vendor_id = $cartValidation['vendor_id'];
            
            // ═══ BEGIN TRANSACTION ═══
            $pdo->beginTransaction();
            
            // Create order
            $order_status = 'pending';
            $payment_status = 'unpaid';
            
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, user_id, vendor_id, subtotal, delivery_fee, 
                    discount_amount, total_amount, order_status, payment_status, 
                    payment_method, delivery_type, notes, created_at
                ) VALUES (
                    :order_number, :user_id, :vendor_id, :subtotal, :delivery_fee,
                    :discount, :total, :order_status, :payment_status,
                    :payment_method, :delivery_type, :notes, NOW()
                )
            ");
            
            $stmt->execute([
                ':order_number'    => $order_number,
                ':user_id'         => $user_id,
                ':vendor_id'       => $vendor_id,
                ':subtotal'        => $cartData['subtotal'],
                ':delivery_fee'    => $cartData['delivery_fee'],
                ':discount'        => $cartData['discount_amount'],
                ':total'           => $total_amount,
                ':order_status'    => $order_status,
                ':payment_status'  => $payment_status,
                ':payment_method'  => $payment_method,
                ':delivery_type'   => $delivery_type,
                ':notes'           => $notes,
            ]);
            
            $order_id = (int)$pdo->lastInsertId();
            
            // Save order address
            $stmt = $pdo->prepare("
                INSERT INTO order_addresses (
                    order_id, address_line1, address_line2, city, region, phone, created_at
                ) VALUES (:oid, :addr1, :addr2, :city, :region, :phone, NOW())
            ");
            $stmt->execute([
                ':oid'   => $order_id,
                ':addr1' => $address_line1,
                ':addr2' => $address_line2,
                ':city'  => $city,
                ':region'=> $region,
                ':phone' => $phone,
            ]);

            $stmt = $pdo->prepare("INSERT INTO deliveries (order_id, delivery_type, status, created_at) VALUES (:order_id, :delivery_type, 'pending', NOW())");
            $stmt->execute([
                ':order_id' => $order_id,
                ':delivery_type' => $delivery_type,
            ]);
            
            // Save to user's address book if requested and delivery
            if ($save_address && $delivery_type === 'delivery') {
                $stmt = $pdo->prepare("
                    INSERT INTO addresses (user_id, address_line1, address_line2, city, region, phone, is_default, created_at)
                    VALUES (:uid, :addr1, :addr2, :city, :region, :phone, 0, NOW())
                ");
                $stmt->execute([
                    ':uid'   => $user_id,
                    ':addr1' => $address_line1,
                    ':addr2' => $address_line2,
                    ':city'  => $city,
                    ':region'=> $region,
                    ':phone' => $phone,
                ]);
            }
            
            // Insert order items
            foreach ($_SESSION['cart'] as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                $pricing_tier_id = (int)($item['pricing_tier_id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? 1);
                $unit_price = (float)($item['price'] ?? 0);
                $item_subtotal = $unit_price * $quantity;
                
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, pricing_tier_id, quantity, unit_price, subtotal
                    ) VALUES (:oid, :pid, :tid, :qty, :price, :subtotal)
                ");
                $stmt->execute([
                    ':oid'      => $order_id,
                    ':pid'      => $product_id,
                    ':tid'      => $pricing_tier_id,
                    ':qty'      => $quantity,
                    ':price'    => $unit_price,
                    ':subtotal' => $item_subtotal,
                ]);
                
                // Update inventory
                if ($pricing_tier_id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE product_inventory
                        SET reserved_quantity = reserved_quantity + :reserve_qty, last_updated = NOW()
                        WHERE pricing_tier_id = :tier_id
                          AND stock_quantity - reserved_quantity >= :available_qty
                    ");
                    $stmt->execute([
                        ':reserve_qty' => $quantity,
                        ':tier_id' => $pricing_tier_id,
                        ':available_qty' => $quantity,
                    ]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException('Stock changed while placing your order. Please review your cart.');
                    }
                }
            }
            
            // Add order status history
            $stmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, new_status, notes, changed_by, created_at)
                VALUES (:oid, 'pending', 'Order placed via checkout', :uid, NOW())
            ");
            $stmt->execute([':oid' => $order_id, ':uid' => $user_id]);
            
            // ═══ HANDLE CASH ON DELIVERY ═══
            if ($payment_method === 'cash_on_delivery') {
                $pdo->commit();
                
                // Clear cart
                unset($_SESSION['cart']);
                unset($_SESSION['applied_coupon']);
                
                echo json_encode([
                    'success'      => true,
                    'message'      => 'Order placed successfully!',
                    'order_id'     => $order_id,
                    'order_number' => $order_number,
                    'payment_method' => 'cash_on_delivery',
                    'redirect'     => 'orders.php?success=1&order=' . urlencode($order_number)
                ]);
                exit;
            }
            
            // ═══ INITIALIZE PAYSTACK PAYMENT ═══
            $paystack_data = [
                'email'        => $email,
                'amount'       => (int)($total_amount * 100), // Convert to pesewas
                'reference'    => $order_number,
                'callback_url' => PAYSTACK_CALLBACK_URL,
                'metadata'     => [
                    'order_id'       => $order_id,
                    'order_number'   => $order_number,
                    'user_id'        => $user_id,
                    'delivery_type'  => $delivery_type,
                    'payment_method' => $payment_method,
                ],
                'channels'     => ['card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'],
                'currency'     => 'GHS',
            ];
            
            $ch = curl_init('https://api.paystack.co/transaction/initialize');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($paystack_data),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
                    'Content-Type: application/json',
                    'Cache-Control: no-cache',
                ],
                CURLOPT_TIMEOUT        => 30,
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_error) {
                throw new Exception('Payment gateway connection error. Please try again.');
            }
            
            $paystack_response = json_decode($response, true);
            
            if (!$paystack_response || !isset($paystack_response['status'])) {
                throw new Exception('Invalid response from payment gateway.');
            }
            
            if ($paystack_response['status'] === true && isset($paystack_response['data']['authorization_url'])) {
                // Store order reference in session for callback verification
                $_SESSION['pending_order_ref'] = $order_number;
                $_SESSION['pending_order_id'] = $order_id;
                
                $pdo->commit();
                
                echo json_encode([
                    'success'            => true,
                    'message'            => 'Payment initialized',
                    'authorization_url'  => $paystack_response['data']['authorization_url'],
                    'access_code'        => $paystack_response['data']['access_code'] ?? '',
                    'reference'          => $order_number,
                    'order_id'           => $order_id,
                    'order_number'       => $order_number,
                ]);
            } else {
                throw new Exception($paystack_response['message'] ?? 'Payment initialization failed. Please try again.');
            }
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Checkout Error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    // ═══ VERIFY PAYSTACK PAYMENT ═══
    if ($action === 'verify_payment') {
        $reference = $_POST['reference'] ?? '';

        if (PAYSTACK_SECRET_KEY === '') {
            echo json_encode(['success' => false, 'message' => 'Online payment verification is unavailable. Please contact support.']);
            exit;
        }
        
        if (empty($reference)) {
            echo json_encode(['success' => false, 'message' => 'No reference provided']);
            exit;
        }

        if (!Auth::isLoggedIn()) {
            echo json_encode(['success' => false, 'message' => 'Please log in to verify this payment.']);
            exit;
        }

        try {
            completePaystackOrder(getAuthPDO(), $reference, (int)$_SESSION['user_id']);
            unset($_SESSION['cart'], $_SESSION['applied_coupon'], $_SESSION['pending_order_ref'], $_SESSION['pending_order_id']);
            echo json_encode([
                'success' => true,
                'message' => 'Payment verified successfully!',
                'order_number' => $reference,
                'redirect' => 'orders.php?success=1&order=' . urlencode($reference),
            ]);
        } catch (DomainException $exception) {
            echo json_encode([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            error_log('Payment verification failed: ' . $exception->getMessage());
            echo json_encode(['success' => false, 'message' => 'Payment verification failed. Please contact support.']);
        }
        exit;
    }
    
    // ═══ GET USER SAVED ADDRESSES ═══
    if ($action === 'get_user_addresses') {
        if (!Auth::isLoggedIn()) {
            echo json_encode(['success' => false, 'addresses' => []]);
            exit;
        }
        
        $pdo = getAuthPDO();
        $stmt = $pdo->prepare("
            SELECT id, address_line1, address_line2, city, region, phone, is_default
            FROM addresses 
            WHERE user_id = :uid 
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([':uid' => $_SESSION['user_id']]);
        $addresses = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'addresses' => $addresses]);
        exit;
    }
    
    // ═══ CALCULATE DELIVERY FEE ═══
    if ($action === 'calculate_delivery') {
        $delivery_type = $_POST['delivery_type'] ?? 'delivery';
        if (!in_array($delivery_type, ['delivery', 'pickup'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid delivery type']);
            exit;
        }

        $cartData = calculateCheckoutTotals($delivery_type);
        echo json_encode([
            'success'              => true,
            'delivery_fee'         => $cartData['delivery_fee'],
            'delivery_fee_formatted' => _bm_fmt($cartData['delivery_fee']),
            'has_free_delivery'    => $cartData['has_free_delivery'],
        ]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ════════════════════════════════════════════════════════════════════
//  HANDLE PAYSTACK CALLBACK (Redirect after payment)
// ════════════════════════════════════════════════════════════════════
if (isset($_GET['callback']) && $_GET['callback'] === '1') {
    $reference = $_GET['reference'] ?? '';

    if (PAYSTACK_SECRET_KEY === '') {
        header('Location: checkout.php?error=payment_unavailable');
        exit;
    }
    
    if (!Auth::isLoggedIn() || $reference === '') {
        header('Location: checkout.php?error=payment_failed');
        exit;
    }

    try {
        completePaystackOrder(getAuthPDO(), $reference, (int)$_SESSION['user_id']);
        unset($_SESSION['cart'], $_SESSION['applied_coupon'], $_SESSION['pending_order_ref'], $_SESSION['pending_order_id']);
        header('Location: orders.php?success=1&order=' . urlencode($reference));
    } catch (Throwable $exception) {
        error_log('Paystack callback failed: ' . $exception->getMessage());
        header('Location: checkout.php?error=payment_failed');
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════════════

/**
 * Format price in GHS
 */
function _bm_fmt($v): string {
    $n = (float) $v;
    if ($n <= 0) return 'GH₵ 0.00';
    return 'GH₵ ' . number_format($n, 2, '.', ',');
}

function validateCheckoutCsrfToken($token): bool {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function completePaystackOrder(PDO $pdo, string $reference, int $userId): void {
    if (PAYSTACK_SECRET_KEY === '' || $userId < 1) {
        throw new DomainException('Payment verification is unavailable. Please contact support.');
    }

    $ch = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Cache-Control: no-cache',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException('Paystack verification request failed.');
    }

    $result = json_decode($response, true);
    if (!is_array($result) || ($result['status'] ?? false) !== true || !is_array($result['data'] ?? null)) {
        throw new RuntimeException('Paystack returned an invalid verification response.');
    }
    $payment = $result['data'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, user_id, order_number, total_amount, payment_method, payment_status, order_status FROM orders WHERE order_number = :reference LIMIT 1 FOR UPDATE');
        $stmt->execute([':reference' => $reference]);
        $order = $stmt->fetch();
        if (!$order || (int)$order['user_id'] !== $userId) {
            throw new DomainException('Order not found or access denied.');
        }

        validatePaystackPaymentData($payment, $order);

        if ($order['payment_status'] === 'paid') {
            $pdo->commit();
            return;
        }
        if ($order['payment_status'] !== 'unpaid' || $order['order_status'] !== 'pending') {
            throw new DomainException('This order is not awaiting payment. Please contact support.');
        }

        $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'paid', order_status = 'confirmed', updated_at = NOW() WHERE id = :id AND payment_status = 'unpaid'");
        $stmt->execute([':id' => $order['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Order payment state changed during verification.');
        }

        $stmt = $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, transaction_id, status, paid_at, created_at) VALUES (:order_id, :amount, :method, :transaction_id, 'successful', NOW(), NOW())");
        $stmt->execute([
            ':order_id' => $order['id'],
            ':amount' => $payment['amount'] / 100,
            ':method' => $order['payment_method'],
            ':transaction_id' => (string)($payment['id'] ?? ''),
        ]);

        $stmt = $pdo->prepare('INSERT INTO order_status_history (order_id, old_status, new_status, notes, created_at) VALUES (:order_id, :old_status, :new_status, :notes, NOW())');
        $stmt->execute([
            ':order_id' => $order['id'],
            ':old_status' => $order['order_status'],
            ':new_status' => 'confirmed',
            ':notes' => 'Payment confirmed via Paystack (' . (string)($payment['channel'] ?? 'unknown') . ')',
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Calculate checkout totals including delivery
 */
function calculateCheckoutTotals(string $deliveryType = 'delivery'): array {
    $subtotal = 0;
    $total_items = 0;
    
    if (!empty($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            $subtotal += $price * $qty;
            $total_items += $qty;
        }
    }
    
    // Apply coupon discount
    $discount_amount = 0;
    if (isset($_SESSION['applied_coupon'])) {
        $coupon = $_SESSION['applied_coupon'];
        if ($coupon['type'] === 'percentage') {
            $discount_amount = $subtotal * ($coupon['value'] / 100);
        } elseif ($coupon['type'] === 'fixed') {
            $discount_amount = min($coupon['value'], $subtotal);
        }
    }
    
    $subtotal_after_discount = max(0, $subtotal - $discount_amount);
    
    $delivery = calculateDeliveryPricing($_SESSION['cart'] ?? [], $deliveryType, null, $subtotal_after_discount);
    $delivery_fee = $delivery['total_delivery_fee'];
    
    $total = $subtotal_after_discount + $delivery_fee;
    
    return [
        'subtotal'                 => round($subtotal, 2),
        'total_items'             => $total_items,
        'discount_amount'         => round($discount_amount, 2),
        'subtotal_after_discount' => round($subtotal_after_discount, 2),
        'delivery_fee'            => round($delivery_fee, 2),
        'total'                   => round($total, 2),
        'has_free_delivery'       => $delivery['has_free_delivery'],
        'delivery_breakdown'      => $delivery,
    ];
}

// Page metadata
$page_title = 'Secure Checkout — Bless Mall';
$current_page = 'checkout';

// Calculate cart data
$cartData = calculateCheckoutTotals();
$user = Auth::isLoggedIn() ? [
    'name'  => $_SESSION['user_name'] ?? '',
    'email' => $_SESSION['user_email'] ?? '',
    'phone' => $_SESSION['user_phone'] ?? '',
] : null;

// Include header
require_once __DIR__ . '/header.php';
?>

<style>
/* ════════════════════════════════════════════════════════════════════
   BLESS MALL — CHECKOUT PAGE v2.0 Premium Styles
   Mobile-first responsive design
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
    --sh-sm: 0 1px 3px rgba(0,0,0,.06);
    --sh-md: 0 4px 12px rgba(0,0,0,.08);
    --sh-lg: 0 8px 24px rgba(0,0,0,.12);
    --fh: 'Playfair Display', Georgia, serif;
    --fb: 'Inter', system-ui, sans-serif;
    --radius: 12px;
    --radius-sm: 8px;
    --radius-full: 9999px;
    --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--paper); font-family: var(--fb); color: var(--ink); line-height: 1.6; }

.checkout-page { padding: 2rem 0; min-height: 80vh; }
.checkout-container { max-width: 1200px; margin: 0 auto; padding: 0 1.25rem; }

.checkout-breadcrumb {
    display: flex; align-items: center; gap: .5rem; margin-bottom: 1.5rem;
    font-size: .82rem; color: var(--ink-4); flex-wrap: wrap;
}
.checkout-breadcrumb a { color: var(--green); text-decoration: none; font-weight: 500; }
.checkout-breadcrumb a:hover { text-decoration: underline; }

.checkout-header { margin-bottom: 2rem; }
.checkout-header h1 {
    font-family: var(--fh); font-size: clamp(1.5rem, 4vw, 2.2rem); font-weight: 400;
    display: flex; align-items: center; gap: .75rem;
}
.checkout-header h1 i { color: var(--green); }

.checkout-layout { display: grid; grid-template-columns: 1fr 420px; gap: 1.5rem; align-items: start; }

/* Form Card */
.checkout-form-card {
    background: var(--white); border-radius: var(--radius); border: 1px solid var(--line);
    box-shadow: var(--sh-sm); padding: 1.75rem;
}

.section-header {
    display: flex; align-items: center; gap: .75rem; margin-bottom: 1.5rem;
    padding-bottom: 1rem; border-bottom: 1px solid var(--line);
}
.section-number {
    width: 32px; height: 32px; background: var(--green); color: #fff;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: .85rem; font-weight: 700; flex-shrink: 0;
}
.section-header h2 { font-family: var(--fh); font-size: 1.1rem; font-weight: 600; }

.form-section { margin-bottom: 1.75rem; }
.form-section:last-child { margin-bottom: 0; }

/* Delivery Type Toggle */
.delivery-type-toggle { display: flex; gap: .75rem; margin-bottom: 1.5rem; }
.delivery-option {
    flex: 1; padding: .85rem; border: 2px solid var(--line); border-radius: var(--radius-sm);
    cursor: pointer; text-align: center; transition: var(--transition);
    display: flex; flex-direction: column; align-items: center; gap: .35rem;
}
.delivery-option:hover { border-color: var(--green); background: var(--green-lt); }
.delivery-option.active { border-color: var(--green); background: #f0faf4; }
.delivery-option i { font-size: 1.3rem; color: var(--ink-3); }
.delivery-option.active i { color: var(--green); }
.delivery-option span { font-size: .82rem; font-weight: 600; }
.delivery-option small { font-size: .7rem; color: var(--ink-4); }

/* Saved Addresses */
.saved-addresses { margin-bottom: 1rem; }
.saved-address-item {
    padding: .85rem; border: 1px solid var(--line); border-radius: var(--radius-sm);
    margin-bottom: .5rem; cursor: pointer; transition: var(--transition);
    display: flex; align-items: flex-start; gap: .75rem;
}
.saved-address-item:hover { border-color: var(--green); background: #fafdfb; }
.saved-address-item.selected { border-color: var(--green); background: #f0faf4; }
.saved-address-item input[type="radio"] { margin-top: .2rem; accent-color: var(--green); }
.saved-address-item .address-details { font-size: .82rem; }
.saved-address-item .address-details strong { display: block; font-size: .85rem; margin-bottom: .15rem; }
.saved-address-default {
    background: var(--green-lt); color: var(--green); font-size: .65rem;
    padding: .1rem .5rem; border-radius: 10px; font-weight: 600; display: inline-block; margin-left: .4rem;
}

/* Form Fields */
.form-group { margin-bottom: 1rem; }
.form-group label {
    display: block; font-size: .82rem; font-weight: 600; color: var(--ink-2); margin-bottom: .35rem;
}
.form-group label .required { color: var(--red); }
.form-control {
    width: 100%; padding: .65rem .85rem; border: 1px solid var(--line); border-radius: var(--radius-sm);
    font-size: .85rem; font-family: var(--fb); color: var(--ink); transition: var(--transition);
    background: var(--white); outline: none;
}
.form-control:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(10,110,61,.08); }
.form-control::placeholder { color: #c4c4c4; }
select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23666' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .85rem center; padding-right: 2rem; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .85rem; }
textarea.form-control { resize: vertical; min-height: 80px; }

/* Payment Methods */
.payment-methods-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.payment-method-option {
    padding: .85rem; border: 2px solid var(--line); border-radius: var(--radius-sm);
    cursor: pointer; text-align: center; transition: var(--transition);
    display: flex; flex-direction: column; align-items: center; gap: .35rem;
}
.payment-method-option:hover { border-color: var(--green); }
.payment-method-option.active { border-color: var(--green); background: #f0faf4; }
.payment-method-option i { font-size: 1.4rem; color: var(--ink-3); }
.payment-method-option.active i { color: var(--green); }
.payment-method-option span { font-size: .8rem; font-weight: 600; }

.checkbox-group { display: flex; align-items: center; gap: .5rem; margin: 1rem 0; }
.checkbox-group input[type="checkbox"] { accent-color: var(--green); width: 16px; height: 16px; }
.checkbox-group label { font-size: .82rem; color: var(--ink-3); }

/* Order Summary Sidebar */
.order-summary { position: sticky; top: 90px; }
.summary-card {
    background: var(--white); border-radius: var(--radius); border: 1px solid var(--line);
    box-shadow: var(--sh-sm); padding: 1.5rem;
}
.summary-card h3 {
    font-family: var(--fh); font-size: 1.1rem; font-weight: 600;
    margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid var(--line);
}

.order-items-mini { max-height: 300px; overflow-y: auto; margin-bottom: 1rem; }
.order-item-mini {
    display: flex; gap: .65rem; padding: .6rem 0; border-bottom: 1px solid #f3f4f6; align-items: center;
}
.order-item-mini:last-child { border-bottom: none; }
.order-item-mini-img {
    width: 48px; height: 48px; border-radius: 6px; background: #f5f5f5;
    flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; color: #ddd;
}
.order-item-mini-img img { width: 100%; height: 100%; object-fit: cover; }
.order-item-mini-details { flex: 1; min-width: 0; }
.order-item-mini-name {
    font-size: .78rem; font-weight: 500; display: -webkit-box;
    -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden;
}
.order-item-mini-meta { font-size: .7rem; color: var(--ink-4); }
.order-item-mini-price { font-size: .8rem; font-weight: 600; text-align: right; }

.summary-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: .6rem 0; font-size: .88rem; color: var(--ink-3);
}
.summary-row.total {
    border-top: 2px solid var(--line); margin-top: .5rem; padding-top: 1rem;
    font-size: 1.1rem; font-weight: 700; color: var(--ink);
}
.summary-row.total .amount { color: var(--green); font-size: 1.2rem; }
.summary-row .free-badge {
    background: #d1fae5; color: #065f46; padding: .1rem .55rem;
    border-radius: 12px; font-size: .72rem; font-weight: 600;
}

/* Place Order Button */
.btn-place-order {
    width: 100%; padding: .9rem; background: var(--green); color: #fff;
    border: none; border-radius: 25px; font-size: .95rem; font-weight: 600;
    cursor: pointer; transition: var(--transition); font-family: var(--fb);
    display: flex; align-items: center; justify-content: center; gap: .5rem; margin-top: 1rem;
}
.btn-place-order:hover { background: var(--green-dk); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(10,110,61,.3); }
.btn-place-order:disabled { background: #d1d5db; cursor: not-allowed; transform: none; box-shadow: none; }

.secure-badge {
    text-align: center; margin-top: .75rem; font-size: .72rem; color: var(--ink-4);
    display: flex; align-items: center; justify-content: center; gap: .4rem;
}
.secure-badge i { color: #10b981; }

.back-link {
    display: inline-flex; align-items: center; gap: .4rem; color: var(--ink-4);
    text-decoration: none; font-size: .8rem; font-weight: 500; margin-bottom: 1rem; transition: var(--transition);
}
.back-link:hover { color: var(--green); }

/* Toast */
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
.toast.warning { border-left-color: var(--amber); }
.toast .close-toast {
    margin-left: auto; background: none; border: none; cursor: pointer;
    color: var(--ink-4); font-size: 1rem; padding: 0; line-height: 1;
}
@keyframes toastIn { from { transform: translateX(110%); opacity: 0; } to { opacity: 1; transform: none; } }

/* Loading Overlay */
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

/* Responsive */
@media (max-width: 992px) {
    .checkout-layout { grid-template-columns: 1fr; }
    .order-summary { position: static; }
}
@media (max-width: 640px) {
    .checkout-page { padding: 1rem 0; }
    .checkout-container { padding: 0 .75rem; }
    .checkout-header h1 { font-size: 1.4rem; }
    .checkout-form-card { padding: 1.25rem; }
    .form-row { grid-template-columns: 1fr; }
    .delivery-type-toggle { flex-direction: column; }
    .payment-methods-grid { grid-template-columns: 1fr 1fr; }
    .section-header h2 { font-size: 1rem; }
}
@media (max-width: 380px) {
    .payment-methods-grid { grid-template-columns: 1fr; }
    .checkout-form-card { padding: 1rem; }
}
</style>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
</div>

<!-- Checkout Page -->
<div class="checkout-page">
    <div class="checkout-container">
        
        <!-- Breadcrumb -->
        <div class="checkout-breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Home</a>
            <span>›</span>
            <a href="cart.php">Cart</a>
            <span>›</span>
            <span>Checkout</span>
        </div>
        
        <!-- Back to Cart -->
        <a href="cart.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Cart
        </a>
        
        <!-- Header -->
        <div class="checkout-header">
            <h1><i class="fas fa-lock"></i> Secure Checkout</h1>
        </div>
        
        <div class="checkout-layout">
            
            <!-- Checkout Form -->
            <div class="checkout-form-card" id="checkoutForm">
                
                <!-- Section 1: Delivery Method -->
                <div class="form-section">
                    <div class="section-header">
                        <span class="section-number">1</span>
                        <h2>Delivery Method</h2>
                    </div>
                    <div class="delivery-type-toggle" id="deliveryTypeToggle">
                        <div class="delivery-option active" data-type="delivery" onclick="selectDeliveryType('delivery')">
                            <i class="fas fa-truck"></i>
                            <span>Home Delivery</span>
                            <small id="deliveryFeeLabel">
                                <?php echo $cartData['has_free_delivery'] ? 'FREE' : _bm_fmt($cartData['delivery_fee']); ?>
                            </small>
                        </div>
                        <div class="delivery-option" data-type="pickup" onclick="selectDeliveryType('pickup')">
                            <i class="fas fa-store-alt"></i>
                            <span>Store Pickup</span>
                            <small>FREE</small>
                        </div>
                    </div>
                </div>
                
                <!-- Section 2: Shipping Address -->
                <div class="form-section" id="shippingAddressSection">
                    <div class="section-header">
                        <span class="section-number">2</span>
                        <h2>Shipping Address</h2>
                    </div>
                    
                    <?php if (Auth::isLoggedIn()): ?>
                    <div class="saved-addresses" id="savedAddresses" style="display:none;">
                        <p style="font-size:.78rem;color:var(--ink-4);margin-bottom:.5rem;">Select a saved address:</p>
                        <div id="savedAddressList"></div>
                        <button type="button" onclick="showNewAddressForm()" 
                                style="background:none;border:none;color:var(--green);cursor:pointer;font-size:.78rem;font-weight:600;padding:0;margin-top:.5rem;">
                            <i class="fas fa-plus"></i> Use a new address instead
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <div id="addressFormFields">
                        <div class="form-group">
                            <label>Address Line 1 <span class="required">*</span></label>
                            <input type="text" class="form-control" id="address_line1" 
                                   placeholder="House number, street name" required>
                        </div>
                        <div class="form-group">
                            <label>Address Line 2 (Landmark) <span class="required">*</span></label>
                            <input type="text" class="form-control" id="address_line2" 
                                   placeholder="Landmark, apartment, etc.">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>City <span class="required">*</span></label>
                                <input type="text" class="form-control" id="city" placeholder="e.g., Accra" required>
                            </div>
                            <div class="form-group">
                                <label>Region <span class="required">*</span></label>
                                <select class="form-control" id="region" required>
                                    <option value="">Select Region</option>
                                    <option value="Greater Accra">Greater Accra</option>
                                    <option value="Ashanti">Ashanti</option>
                                    <option value="Western">Western</option>
                                    <option value="Eastern">Eastern</option>
                                    <option value="Central">Central</option>
                                    <option value="Volta">Volta</option>
                                    <option value="Northern">Northern</option>
                                    <option value="Upper East">Upper East</option>
                                    <option value="Upper West">Upper West</option>
                                    <option value="Bono">Bono</option>
                                    <option value="Bono East">Bono East</option>
                                    <option value="Ahafo">Ahafo</option>
                                    <option value="Savannah">Savannah</option>
                                    <option value="North East">North East</option>
                                    <option value="Western North">Western North</option>
                                    <option value="Oti">Oti</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Phone Number <span class="required">*</span></label>
                            <input type="tel" class="form-control" id="phone" 
                                   placeholder="e.g., 024 000 0000" 
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                        </div>
                        <?php if (Auth::isLoggedIn()): ?>
                        <div class="checkbox-group">
                            <input type="checkbox" id="save_address">
                            <label for="save_address">Save this address for future orders</label>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Section 3: Payment Method -->
                <div class="form-section">
                    <div class="section-header">
                        <span class="section-number">3</span>
                        <h2>Payment Method</h2>
                    </div>
                    <div class="payment-methods-grid" id="paymentMethodsGrid">
                        <div class="payment-method-option active" data-method="momo" onclick="selectPaymentMethod('momo')">
                            <i class="fas fa-mobile-alt"></i>
                            <span>Mobile Money</span>
                        </div>
                        <div class="payment-method-option" data-method="card" onclick="selectPaymentMethod('card')">
                            <i class="fas fa-credit-card"></i>
                            <span>Bank Card</span>
                        </div>
                        <div class="payment-method-option" data-method="bank_transfer" onclick="selectPaymentMethod('bank_transfer')">
                            <i class="fas fa-university"></i>
                            <span>Bank Transfer</span>
                        </div>
                        <div class="payment-method-option" data-method="cash_on_delivery" onclick="selectPaymentMethod('cash_on_delivery')">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Cash on Delivery</span>
                        </div>
                    </div>
                </div>
                
                <!-- Section 4: Contact Information -->
                <div class="form-section">
                    <div class="section-header">
                        <span class="section-number">4</span>
                        <h2>Contact Information</h2>
                    </div>
                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" class="form-control" id="email" 
                               placeholder="your@email.com" 
                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                        <small style="color:var(--ink-4);font-size:.72rem;">
                            Order confirmation will be sent to this email
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Order Notes (Optional)</label>
                        <textarea class="form-control" id="notes" 
                                  placeholder="Special instructions for delivery..."></textarea>
                    </div>
                </div>
                
            </div>
            
            <!-- Order Summary Sidebar -->
            <div class="order-summary">
                <div class="summary-card" id="summaryCard">
                    <h3>Order Summary</h3>
                    
                    <!-- Order Items Mini -->
                    <div class="order-items-mini" id="orderItemsMini">
                        <?php foreach ($_SESSION['cart'] as $item): 
                            $item_total = (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
                            $img = getImageUrl($item['image'] ?? '', 'product');
                        ?>
                        <div class="order-item-mini">
                            <div class="order-item-mini-img">
                                <?php if (!empty($item['image'])): ?>
                                    <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($item['name'] ?? ''); ?>" loading="lazy" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-box-open\'></i>'">
                                <?php else: ?>
                                    <i class="fas fa-box-open"></i>
                                <?php endif; ?>
                            </div>
                            <div class="order-item-mini-details">
                                <div class="order-item-mini-name"><?php echo htmlspecialchars($item['name'] ?? 'Product'); ?></div>
                                <div class="order-item-mini-meta">
                                    Qty: <?php echo (int)($item['quantity'] ?? 1); ?>
                                    <?php if (!empty($item['tier_name'])): ?>
                                        • <?php echo htmlspecialchars($item['tier_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="order-item-mini-price"><?php echo _bm_fmt($item_total); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Summary Rows -->
                    <div class="summary-row">
                        <span>Subtotal (<?php echo $cartData['total_items']; ?> items)</span>
                        <span id="summarySubtotal"><?php echo _bm_fmt($cartData['subtotal']); ?></span>
                    </div>
                    
                    <?php if ($cartData['discount_amount'] > 0): ?>
                    <div class="summary-row" style="color: #059669;">
                        <span>Discount</span>
                        <span id="summaryDiscount">-<?php echo _bm_fmt($cartData['discount_amount']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="summary-row">
                        <span>Delivery Fee</span>
                        <span id="summaryDelivery">
                            <?php if ($cartData['has_free_delivery']): ?>
                                <span class="free-badge">FREE</span>
                            <?php else: ?>
                                <?php echo _bm_fmt($cartData['delivery_fee']); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <div class="summary-row total">
                        <span>Total</span>
                        <span class="amount" id="summaryTotal"><?php echo _bm_fmt($cartData['total']); ?></span>
                    </div>
                    
                    <!-- Place Order Button -->
                    <button class="btn-place-order" id="btnPlaceOrder" onclick="placeOrder()">
                        <i class="fas fa-lock"></i> Place Order & Pay
                    </button>
                    
                    <div class="secure-badge">
                        <i class="fas fa-shield-alt"></i> 
                        Secured by <strong>Paystack</strong> • 256-bit SSL
                    </div>
                    
                    <div style="text-align:center;margin-top:.5rem;">
                        <span style="font-size:.65rem;color:var(--ink-4);display:flex;align-items:center;justify-content:center;gap:.3rem;">
                            <i class="fas fa-check-circle" style="color:#10b981;"></i>
                            Payments processed securely by Paystack
                        </span>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<!-- Paystack Inline Script -->
<script src="https://js.paystack.co/v1/inline.js"></script>

<script>
// ═══════════════════════════════════════════════════════════════════
// CHECKOUT SCRIPTS v2.0
// ═══════════════════════════════════════════════════════════════════

var checkoutState = {
    csrf_token: <?php echo json_encode(CSRFToken::generate()); ?>,
    delivery_type: 'delivery',
    payment_method: 'momo',
    use_saved_address: false,
    saved_address_id: null,
    delivery_fee: <?php echo $cartData['delivery_fee']; ?>,
    has_free_delivery: <?php echo $cartData['has_free_delivery'] ? 'true' : 'false'; ?>,
    subtotal: <?php echo $cartData['subtotal']; ?>,
    discount: <?php echo $cartData['discount_amount']; ?>,
    is_processing: false
};

// ═══ TOAST ═══
function showToast(message, type) {
    type = type || 'info';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    var icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    toast.innerHTML = 
        '<span>' + (icons[type] || '') + '</span>' +
        '<span style="flex:1;">' + message + '</span>' +
        '<button class="close-toast" onclick="this.parentElement.remove()">&times;</button>';
    container.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300);
    }, 5000);
    var toasts = container.querySelectorAll('.toast');
    while (toasts.length > 3) { toasts[0].remove(); }
}

// ═══ LOADING ═══
function showLoading() { document.getElementById('loadingOverlay').classList.add('active'); }
function hideLoading() { document.getElementById('loadingOverlay').classList.remove('active'); }

// ═══ SELECT DELIVERY TYPE ═══
function selectDeliveryType(type) {
    checkoutState.delivery_type = type;
    document.querySelectorAll('.delivery-option').forEach(function(el) {
        el.classList.toggle('active', el.dataset.type === type);
    });
    
    var addressSection = document.getElementById('shippingAddressSection');
    if (addressSection) {
        addressSection.style.display = type === 'pickup' ? 'none' : '';
    }
    
    var formData = new URLSearchParams();
    formData.append('action', 'calculate_delivery');
    formData.append('delivery_type', type);
    
    fetch('checkout.php', {
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
            checkoutState.delivery_fee = data.delivery_fee;
            checkoutState.has_free_delivery = data.has_free_delivery;
            updateSummary();
        }
    });
}

// ═══ SELECT PAYMENT METHOD ═══
function selectPaymentMethod(method) {
    checkoutState.payment_method = method;
    document.querySelectorAll('.payment-method-option').forEach(function(el) {
        el.classList.toggle('active', el.dataset.method === method);
    });
}

// ═══ UPDATE SUMMARY ═══
function updateSummary() {
    var deliveryFee = checkoutState.delivery_fee;
    var total = checkoutState.subtotal - checkoutState.discount + deliveryFee;
    
    var delEl = document.getElementById('summaryDelivery');
    if (delEl) {
        delEl.innerHTML = deliveryFee === 0 ? '<span class="free-badge">FREE</span>' : 'GH₵ ' + deliveryFee.toFixed(2);
    }
    
    var totalEl = document.getElementById('summaryTotal');
    if (totalEl) totalEl.textContent = 'GH₵ ' + total.toFixed(2);
    
    var feeLabel = document.getElementById('deliveryFeeLabel');
    if (feeLabel) feeLabel.textContent = deliveryFee === 0 ? 'FREE' : 'GH₵ ' + deliveryFee.toFixed(2);
}

// ═══ LOAD SAVED ADDRESSES ═══
function loadSavedAddresses() {
    <?php if (Auth::isLoggedIn()): ?>
    var formData = new URLSearchParams();
    formData.append('action', 'get_user_addresses');
    
    fetch('checkout.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success && data.addresses && data.addresses.length > 0) {
            var container = document.getElementById('savedAddresses');
            var list = document.getElementById('savedAddressList');
            if (container && list) {
                var html = '';
                data.addresses.forEach(function(addr, index) {
                    html += 
                        '<div class="saved-address-item' + (index === 0 ? ' selected' : '') + '" onclick="selectSavedAddress(' + addr.id + ', this)">' +
                            '<input type="radio" name="saved_address" value="' + addr.id + '" ' + (index === 0 ? 'checked' : '') + '>' +
                            '<div class="address-details">' +
                                '<strong>' + escapeHtml(addr.address_line1) + (addr.is_default == 1 ? '<span class="saved-address-default">Default</span>' : '') + '</strong>' +
                                (addr.address_line2 ? '<div>' + escapeHtml(addr.address_line2) + '</div>' : '') +
                                '<div>' + escapeHtml(addr.city) + ', ' + escapeHtml(addr.region) + '</div>' +
                                '<div>' + escapeHtml(addr.phone) + '</div>' +
                            '</div>' +
                        '</div>';
                });
                list.innerHTML = html;
                container.style.display = 'block';
                document.getElementById('addressFormFields').style.display = 'none';
                
                var defaultAddr = data.addresses[0];
                fillAddressForm(defaultAddr);
                checkoutState.use_saved_address = true;
                checkoutState.saved_address_id = defaultAddr.id;
            }
        }
    });
    <?php endif; ?>
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function selectSavedAddress(id, element) {
    document.querySelectorAll('.saved-address-item').forEach(function(el) {
        el.classList.remove('selected');
    });
    element.classList.add('selected');
    element.querySelector('input[type="radio"]').checked = true;
    checkoutState.use_saved_address = true;
    checkoutState.saved_address_id = id;
}

function showNewAddressForm() {
    document.getElementById('savedAddresses').style.display = 'none';
    document.getElementById('addressFormFields').style.display = '';
    ['address_line1', 'address_line2', 'city', 'region', 'phone'].forEach(function(id) {
        document.getElementById(id).value = '';
    });
    checkoutState.use_saved_address = false;
    checkoutState.saved_address_id = null;
}

function fillAddressForm(addr) {
    document.getElementById('address_line1').value = addr.address_line1 || '';
    document.getElementById('address_line2').value = addr.address_line2 || '';
    document.getElementById('city').value = addr.city || '';
    document.getElementById('region').value = addr.region || '';
    document.getElementById('phone').value = addr.phone || '';
}

// ═══ VALIDATE FORM ═══
function validateForm() {
    var errors = [];
    
    if (checkoutState.delivery_type === 'delivery') {
        if (!document.getElementById('address_line1').value.trim()) errors.push('Address is required');
        if (!document.getElementById('city').value.trim()) errors.push('City is required');
        if (!document.getElementById('region').value.trim()) errors.push('Region is required');
        if (!document.getElementById('phone').value.trim()) errors.push('Phone number is required');
    }
    
    if (checkoutState.delivery_type === 'pickup') {
        if (!document.getElementById('phone').value.trim()) errors.push('Phone number is required for pickup');
    }
    
    var email = document.getElementById('email').value.trim();
    if (!email) {
        errors.push('Email is required');
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.push('Please enter a valid email address');
    }
    
    return errors;
}

// ═══ PLACE ORDER ═══
function placeOrder() {
    if (checkoutState.is_processing) return;
    
    var errors = validateForm();
    if (errors.length > 0) {
        showToast(errors.join('. '), 'error');
        return;
    }
    
    checkoutState.is_processing = true;
    var btn = document.getElementById('btnPlaceOrder');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    showLoading();
    
    var formData = new URLSearchParams();
    formData.append('action', 'initiate_payment');
    formData.append('csrf_token', checkoutState.csrf_token);
    formData.append('delivery_type', checkoutState.delivery_type);
    formData.append('address_line1', document.getElementById('address_line1').value.trim());
    formData.append('address_line2', document.getElementById('address_line2').value.trim());
    formData.append('city', document.getElementById('city').value.trim());
    formData.append('region', document.getElementById('region').value.trim());
    formData.append('phone', document.getElementById('phone').value.trim());
    formData.append('email', document.getElementById('email').value.trim());
    formData.append('notes', document.getElementById('notes').value.trim());
    formData.append('payment_method', checkoutState.payment_method);
    formData.append('save_address', document.getElementById('save_address')?.checked ? '1' : '0');
    
    fetch('checkout.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        hideLoading();
        
        if (data.success) {
            if (data.payment_method === 'cash_on_delivery') {
                showToast('Order placed successfully! Redirecting...', 'success');
                setTimeout(function() {
                    window.location.href = data.redirect || 'orders.php';
                }, 1500);
            } else {
                payWithPaystack(data);
            }
        } else {
            showToast(data.message || 'Error placing order. Please try again.', 'error');
            if (data.redirect) {
                setTimeout(function() {
                    window.location.href = data.redirect;
                }, 1200);
                return;
            }
            resetButton();
        }
    })
    .catch(function(err) {
        hideLoading();
        console.error('Checkout error:', err);
        showToast('Connection error. Please check your internet and try again.', 'error');
        resetButton();
    });
}

// ═══ PAY WITH PAYSTACK ═══
function payWithPaystack(data) {
    if (data.access_code) {
        var handler = PaystackPop.setup({
            access_code: data.access_code,
            onSuccess: function(transaction) {
                verifyPayment(transaction.reference);
            },
            onCancel: function() {
                showToast('Payment cancelled. Your order is pending. You can retry payment from your orders page.', 'warning');
                resetButton();
                setTimeout(function() {
                    window.location.href = 'orders.php';
                }, 3000);
            },
            onError: function(error) {
                console.error('Paystack error:', error);
                showToast('Payment error occurred. Please try again.', 'error');
                resetButton();
            }
        });
        handler.openIframe();
    } else if (data.authorization_url) {
        // Fallback: redirect to Paystack page
        window.location.href = data.authorization_url;
    } else {
        showToast('Unable to initialize payment. Please try again.', 'error');
        resetButton();
    }
}

// ═══ VERIFY PAYMENT ═══
function verifyPayment(reference) {
    showLoading();
    
    var formData = new URLSearchParams();
    formData.append('action', 'verify_payment');
    formData.append('csrf_token', checkoutState.csrf_token);
    formData.append('reference', reference);
    
    fetch('checkout.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        hideLoading();
        if (data.success) {
            showToast('Payment successful! Redirecting to your order...', 'success');
            setTimeout(function() {
                window.location.href = data.redirect || 'orders.php';
            }, 1500);
        } else {
            showToast(data.message || 'Payment verification failed. Please contact support.', 'error');
            resetButton();
        }
    })
    .catch(function() {
        hideLoading();
        showToast('Error verifying payment. Please contact support.', 'error');
        resetButton();
    });
}

// ═══ RESET BUTTON ═══
function resetButton() {
    checkoutState.is_processing = false;
    var btn = document.getElementById('btnPlaceOrder');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-lock"></i> Place Order & Pay';
}

// ═══ INIT ═══
document.addEventListener('DOMContentLoaded', function() {
    loadSavedAddresses();
    
    // Handle URL error parameter
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('error') === 'payment_failed') {
        showToast('Payment was not successful. Please try again or use a different payment method.', 'error');
    }
});

console.log('%c🛍️ Bless Mall Checkout %cv2.0 %cReady',
    'font-size:1.2em;font-weight:bold;color:#0a6e3d;',
    'font-size:1em;color:#c9a227;',
    'font-size:0.8em;color:#666;');
</script>

<?php require_once __DIR__ . '/footer.php'; ?>