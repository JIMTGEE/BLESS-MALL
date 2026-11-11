<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$message = '';
$messageType = 'success';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
        $message = 'Security validation failed. Please try again.';
        $messageType = 'error';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        if ($identifier === '') {
            $message = 'Enter the email address or phone number on your account.';
            $messageType = 'error';
        } else {
            try {
                $result = Auth::sendPasswordResetLink($identifier);
                $message = $result['message'];
            } catch (Throwable $exception) {
                error_log('Password reset request failed: ' . $exception->getMessage());
                $message = 'We could not process the request right now. Please try again later.';
                $messageType = 'error';
            }
        }
    }
}

$csrfToken = CSRFToken::generate();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password - <?php echo e(SITE_NAME); ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f4f7f5; color: #17221b; font: 16px Arial, sans-serif; }
        main { width: min(100%, 420px); padding: 32px; background: #fff; border: 1px solid #dce6df; border-radius: 8px; }
        h1 { margin: 0 0 10px; font-size: 1.6rem; }
        p { line-height: 1.55; color: #56645a; }
        label { display: block; margin: 22px 0 7px; font-weight: 600; }
        input { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #b8c6bc; border-radius: 4px; font: inherit; }
        button { margin-top: 16px; width: 100%; padding: 12px; border: 0; border-radius: 4px; background: #0b6e3d; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .message { padding: 12px; border-radius: 4px; background: #edf6ef; }
        .error { background: #fff0ee; color: #8b1e13; }
        a { color: #0b6e3d; }
    </style>
</head>
<body>
<main>
    <h1>Reset your password</h1>
    <p>Enter your account email or phone. If the account has a valid email address, we will send a one-hour reset link.</p>
    <?php if ($message !== ''): ?>
        <p class="message <?php echo $messageType === 'error' ? 'error' : ''; ?>" role="status"><?php echo e($message); ?></p>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <label for="identifier">Email or phone</label>
        <input id="identifier" name="identifier" type="text" autocomplete="username" required value="<?php echo e($identifier); ?>">
        <button type="submit">Send reset link</button>
    </form>
    <p><a href="<?php echo e(BASE_URL . 'login.php'); ?>">Back to login</a></p>
</main>
</body>
</html>