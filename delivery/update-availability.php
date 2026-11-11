<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireDeliverer();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Security validation failed. Please try again.', 'error');
    redirect(BASE_URL . 'delivery/index.php');
}

$status = $_POST['status'] ?? '';
if (!in_array($status, ['available', 'busy', 'offline'], true)) {
    setFlashMessage('Invalid availability status.', 'error');
    redirect(BASE_URL . 'delivery/index.php');
}

$stmt = $conn->prepare('UPDATE deliverers SET availability_status = ? WHERE id = ? AND user_id = ?');
$delivererId = (int)getCurrentDelivererId();
$userId = (int)getCurrentUserId();
$stmt->bind_param('sii', $status, $delivererId, $userId);
if ($stmt->execute()) {
    $_SESSION['deliverer_status'] = $status;
    setFlashMessage('Availability updated.', 'success');
} else {
    error_log('Deliverer availability update failed: ' . $stmt->error);
    setFlashMessage('Availability could not be updated.', 'error');
}
$stmt->close();
redirect(BASE_URL . 'delivery/index.php');
