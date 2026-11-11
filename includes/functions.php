<?php
/**
 * functions.php - Helper Functions
 * Bless Mall - Quality Chinese Products | Ghana
 * 
 * This file contains application-specific helper functions.
 * Config constants and database connection are loaded from config.php
 */

// Guard against direct access
if (!defined('BLESSMALL_CONFIG_INCLUDED')) {
    die('Config not loaded. Please include config.php first.');
}

/**
 * Check if user is logged in
 * @return bool
 */
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
}

/**
 * Get current user ID
 * @return int|null
 */
if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

/**
 * Get current user name
 * @return string|null
 */
if (!function_exists('getCurrentUserName')) {
    function getCurrentUserName() {
        return $_SESSION['user_name'] ?? null;
    }
}

/**
 * Get current user email
 * @return string|null
 */
if (!function_exists('getCurrentUserEmail')) {
    function getCurrentUserEmail() {
        return $_SESSION['user_email'] ?? null;
    }
}

/**
 * Get current user phone
 * @return string|null
 */
if (!function_exists('getCurrentUserPhone')) {
    function getCurrentUserPhone() {
        return $_SESSION['user_phone'] ?? null;
    }
}

/**
 * Check if user is admin
 * @return bool
 */
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return isLoggedIn() && isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === ROLE_ADMIN;
    }
}

/**
 * Check if user is seller
 * @return bool
 */
if (!function_exists('isSeller')) {
    function isSeller() {
        return isLoggedIn() && isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === ROLE_SELLER;
    }
}

/**
 * Check if user is buyer
 * @return bool
 */
if (!function_exists('isBuyer')) {
    function isBuyer() {
        return isLoggedIn() && isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === ROLE_BUYER;
    }
}

/**
 * Check if current user is a deliverer
 * @return bool
 */
if (!function_exists('isDeliverer')) {
    function isDeliverer() {
        return isLoggedIn()
            && ($_SESSION['role_name'] ?? '') === 'deliverer'
            && isset($_SESSION['deliverer_id']);
    }
}

/**
 * Get current deliverer ID
 * @return int|null
 */
if (!function_exists('getCurrentDelivererId')) {
    function getCurrentDelivererId() {
        return $_SESSION['deliverer_id'] ?? null;
    }
}

/**
 * Get current deliverer availability status
 * @return string|null
 */
if (!function_exists('getDelivererStatus')) {
    function getDelivererStatus() {
        return $_SESSION['deliverer_status'] ?? null;
    }
}

/**
 * Get current deliverer vehicle type
 * @return string|null
 */
if (!function_exists('getDelivererVehicleType')) {
    function getDelivererVehicleType() {
        return $_SESSION['deliverer_vehicle_type'] ?? null;
    }
}

/**
 * Redirect to a URL
 * @param string $url
 */
if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit;
    }
}

/**
 * Display flash message
 * @param string $message
 * @param string $type success|error|warning|info
 */
if (!function_exists('setFlashMessage')) {
    function setFlashMessage($message, $type = 'success') {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_message_type'] = $type;
    }
}

/**
 * Get and clear flash message
 * @return array|null
 */
if (!function_exists('getFlashMessage')) {
    function getFlashMessage() {
        if (isset($_SESSION['flash_message'])) {
            $message = [
                'text' => $_SESSION['flash_message'],
                'type' => $_SESSION['flash_message_type'] ?? 'success'
            ];
            unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
            return $message;
        }
        return null;
    }
}

/**
 * Generate pagination links
 * @param int $total_items
 * @param int $current_page
 * @param int $per_page
 * @param string $base_url
 * @return array
 */
if (!function_exists('generatePagination')) {
    function generatePagination($total_items, $current_page = 1, $per_page = 12, $base_url = '') {
        $total_pages = ceil($total_items / $per_page);
        $current_page = max(1, min($current_page, $total_pages));
        
        $pagination = [
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'current_page' => $current_page,
            'per_page' => $per_page,
            'has_previous' => $current_page > 1,
            'has_next' => $current_page < $total_pages,
            'previous_page' => $current_page - 1,
            'next_page' => $current_page + 1,
            'base_url' => $base_url,
            'pages' => []
        ];
        
        // Generate page numbers
        $start = max(1, $current_page - 2);
        $end = min($total_pages, $current_page + 2);
        
        for ($i = $start; $i <= $end; $i++) {
            $pagination['pages'][] = [
                'number' => $i,
                'is_current' => $i == $current_page
            ];
        }
        
        return $pagination;
    }
}

/**
 * Truncate text to specified length
 * @param string $text
 * @param int $length
 * @param string $suffix
 * @return string
 */
if (!function_exists('truncateText')) {
    function truncateText($text, $length = 100, $suffix = '...') {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . $suffix;
    }
}

/**
 * Generate product URL
 * @param int $product_id
 * @param string $slug
 * @return string
 */
if (!function_exists('getProductUrl')) {
    function getProductUrl($product_id, $slug = '') {
        if ($slug) {
            return BASE_URL . 'product/' . $slug;
        }
        return BASE_URL . 'product-detail.php?id=' . $product_id;
    }
}

/**
 * Get cart count from session
 * @return int
 */
if (!function_exists('getCartCount')) {
    function getCartCount() {
        $count = 0;
        if (isset($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as $item) {
                $count += $item['quantity'] ?? 0;
            }
        }
        return $count;
    }
}

/**
 * Calculate cart total
 * @return float
 */
if (!function_exists('getCartTotal')) {
    function getCartTotal() {
        $total = 0;
        if (isset($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as $item) {
                $total += ($item['price'] ?? 0) * ($item['quantity'] ?? 0);
            }
        }
        return $total;
    }
}

if (!function_exists('calculateDeliveryPricing')) {
    function calculateDeliveryPricing(array $cart, string $deliveryType = 'delivery', ?string $zone = null, float $subtotal = 0): array {
        $zone ??= $_SESSION['delivery_zone'] ?? 'legon_campus';
        if (!array_key_exists($zone, DELIVERY_ZONE_SURCHARGES)) {
            $zone = 'legon_campus';
        }

        $hasExpress = false;
        foreach ($cart as $item) {
            if (!empty($item['is_express'])) {
                $hasExpress = true;
                break;
            }
        }

        $isPickup = $deliveryType === 'pickup';
        $hasFreeDelivery = $isPickup || $subtotal >= FREE_DELIVERY_THRESHOLD;
        $baseFee = ($isPickup || $hasFreeDelivery) ? 0 : DELIVERY_FEE_BASE;
        $zoneSurcharge = $baseFee > 0 ? DELIVERY_ZONE_SURCHARGES[$zone] : 0;
        $expressSurcharge = $baseFee > 0 && $hasExpress ? DELIVERY_EXPRESS_SURCHARGE : 0;

        return [
            'base_fee' => $baseFee,
            'zone_surcharge' => $zoneSurcharge,
            'zone_name' => $zone,
            'express_surcharge' => $expressSurcharge,
            'has_express' => $hasExpress,
            'has_free_delivery' => $hasFreeDelivery,
            'total_delivery_fee' => $baseFee + $zoneSurcharge + $expressSurcharge,
        ];
    }
}

if (!function_exists('validateCheckoutCart')) {
    function validateCheckoutCart(PDO $pdo, array $cart): array {
        if (empty($cart)) {
            throw new DomainException('Your cart is empty.');
        }

        $stmt = $pdo->prepare("
            SELECT p.id AS product_id, p.name, p.slug, p.vendor_id, v.is_express,
                   ppt.id AS pricing_tier_id, ppt.pricing_type, ppt.tier_name,
                   ppt.unit_price, ppt.discount_price, ppt.discount_percentage,
                   ppt.min_quantity, ppt.max_quantity,
                   pi.stock_quantity, pi.reserved_quantity
            FROM products p
            JOIN vendors v ON v.id = p.vendor_id AND v.status = 'active' AND v.is_approved = 1
            JOIN product_pricing_tiers ppt ON ppt.product_id = p.id AND ppt.is_active = 1
            LEFT JOIN product_inventory pi ON pi.pricing_tier_id = ppt.id
            WHERE p.id = :product_id AND ppt.id = :tier_id AND p.is_active = 1
            LIMIT 1
        ");

        $validatedItems = [];
        $vendorId = null;
        $priceChanged = false;

        foreach ($cart as $cartKey => $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $tierId = (int)($item['pricing_tier_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            if ($productId < 1 || $tierId < 1 || $quantity < 1) {
                throw new DomainException('A cart item is invalid. Please review your cart.');
            }

            $stmt->execute([':product_id' => $productId, ':tier_id' => $tierId]);
            $product = $stmt->fetch();
            if (!$product || $product['stock_quantity'] === null) {
                throw new DomainException('A product is no longer available. Please review your cart.');
            }

            $minimumQuantity = (int)$product['min_quantity'];
            $maximumQuantity = $product['max_quantity'] === null ? null : (int)$product['max_quantity'];
            if ($quantity < $minimumQuantity || ($maximumQuantity !== null && $quantity > $maximumQuantity)) {
                throw new DomainException('A quantity no longer matches its pricing tier. Please review your cart.');
            }

            $availableQuantity = (int)$product['stock_quantity'] - (int)$product['reserved_quantity'];
            if ($quantity > $availableQuantity) {
                throw new DomainException('A product no longer has enough stock. Please review your cart.');
            }

            $unitPrice = (float)$product['unit_price'];
            $discountPrice = $product['discount_price'] === null ? null : (float)$product['discount_price'];
            $discountPercentage = $product['discount_percentage'] === null ? null : (float)$product['discount_percentage'];
            if ($unitPrice <= 0 || ($discountPercentage !== null && ($discountPercentage < 0 || $discountPercentage > 100))) {
                throw new DomainException('A product has invalid pricing. Please contact support.');
            }

            $price = $unitPrice;
            if ($discountPrice !== null && $discountPrice > 0 && $discountPrice < $unitPrice) {
                $price = $discountPrice;
            } elseif ($discountPercentage !== null && $discountPercentage > 0) {
                $price = round($unitPrice * (1 - $discountPercentage / 100), 2);
            }
            if ($price <= 0) {
                throw new DomainException('A product has invalid pricing. Please contact support.');
            }

            if (!isset($item['price']) || round((float)$item['price'], 2) !== round($price, 2)) {
                $priceChanged = true;
            }

            $productVendorId = (int)$product['vendor_id'];
            if ($vendorId !== null && $vendorId !== $productVendorId) {
                throw new DomainException('Your cart contains products from multiple stores. Please place separate orders.');
            }
            $vendorId = $productVendorId;

            $item['product_id'] = $productId;
            $item['pricing_tier_id'] = $tierId;
            $item['quantity'] = $quantity;
            $item['name'] = $product['name'];
            $item['slug'] = $product['slug'];
            $item['tier_name'] = $product['tier_name'];
            $item['pricing_type'] = $product['pricing_type'];
            $item['price'] = $price;
            $item['original_price'] = $unitPrice;
            $item['is_express'] = (int)$product['is_express'] === 1;
            $validatedItems[$cartKey] = $item;
        }

        return [
            'items' => $validatedItems,
            'vendor_id' => $vendorId,
            'price_changed' => $priceChanged,
        ];
    }
}

if (!function_exists('validatePaystackPaymentData')) {
    function validatePaystackPaymentData(array $payment, array $order): void {
        if (($payment['status'] ?? '') !== 'success') {
            throw new DomainException('Payment has not succeeded.');
        }
        if (($payment['reference'] ?? '') !== ($order['order_number'] ?? '')) {
            throw new DomainException('Payment reference does not match this order.');
        }
        if (strtoupper((string)($payment['currency'] ?? '')) !== 'GHS') {
            throw new DomainException('Payment currency does not match this order.');
        }
        $amountInPesewas = filter_var($payment['amount'] ?? null, FILTER_VALIDATE_INT);
        $expectedAmount = (int)round((float)($order['total_amount'] ?? 0) * 100);
        if ($amountInPesewas === false || $amountInPesewas !== $expectedAmount) {
            throw new DomainException('Payment amount does not match this order.');
        }
    }
}

/**
 * Generate star rating HTML
 * @param float $rating
 * @return string
 */
if (!function_exists('generateStarRating')) {
    function generateStarRating($rating) {
        $html = '';
        $full = floor($rating);
        $half = $rating - $full >= 0.5;
        $empty = 5 - $full - ($half ? 1 : 0);
        
        for ($i = 0; $i < $full; $i++) {
            $html .= '<i class="fas fa-star"></i>';
        }
        if ($half) {
            $html .= '<i class="fas fa-star-half-alt"></i>';
        }
        for ($i = 0; $i < $empty; $i++) {
            $html .= '<i class="far fa-star"></i>';
        }
        
        return $html;
    }
}

/**
 * Format date to readable format
 * @param string $date
 * @param string $format
 * @return string
 */
if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'M d, Y') {
        return date($format, strtotime($date));
    }
}

/**
 * Calculate time ago
 * @param string $datetime
 * @return string
 */
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M d, Y', $time);
        }
    }
}

/**
 * Generate CSRF token
 * @return string
 */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token) {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            return false;
        }
        unset($_SESSION['csrf_token']);
        return true;
    }
}

/**
 * Get image URL with fallback
 * @param string $image
 * @param string $type
 * @return string
 */
if (!function_exists('getImageUrl')) {
    function getImageUrl($image, $type = 'product') {
        if (empty($image)) {
            return ASSETS_URL . 'images/placeholder.jpg';
        }
        
        switch ($type) {
            case 'product':
                return PRODUCT_IMAGES_URL . $image;
            case 'banner':
                return UPLOADS_URL . 'banners/' . $image;
            case 'category':
                return UPLOADS_URL . 'categories/' . $image;
            default:
                return ASSETS_URL . 'images/' . $image;
        }
    }
}

/**
 * Sanitize output for display
 * @param string $string
 * @return string
 */
if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Get order status badge HTML
 * @param string $status
 * @return string
 */
if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status) {
        global $order_statuses;
        
        $colors = [
            'pending' => 'warning',
            'confirmed' => 'info',
            'processing' => 'info',
            'packed' => 'primary',
            'shipped' => 'primary',
            'in_transit' => 'primary',
            'delivered' => 'success',
            'cancelled' => 'danger',
            'refunded' => 'secondary'
        ];
        
        $color = $colors[$status] ?? 'secondary';
        $label = $order_statuses[$status] ?? ucfirst($status);
        
        return "<span class='badge badge-{$color}'>{$label}</span>";
    }
}

/**
 * Get delivery status badge HTML
 * @param string $status
 * @return string
 */
if (!function_exists('getDeliveryStatusBadge')) {
    function getDeliveryStatusBadge($status) {
        global $delivery_statuses;
        
        $colors = [
            'pending' => 'warning',
            'assigned' => 'info',
            'in_transit' => 'primary',
            'delivered' => 'success',
            'failed' => 'danger'
        ];
        
        $color = $colors[$status] ?? 'secondary';
        $label = $delivery_statuses[$status] ?? ucfirst($status);
        
        return "<span class='badge badge-{$color}'>{$label}</span>";
    }
}

/**
 * Get deliverer availability badge HTML
 * @param string $status
 * @return string
 */
if (!function_exists('getDelivererAvailabilityBadge')) {
    function getDelivererAvailabilityBadge($status) {
        switch ($status) {
            case 'available':
                return '<span class="badge badge-success">✓ Available</span>';
            case 'busy':
                return '<span class="badge badge-warning">⏳ Busy</span>';
            case 'offline':
                return '<span class="badge badge-secondary">● Offline</span>';
            default:
                return '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
        }
    }
}