<?php
/**
 * Coupon apply / remove (non-JSON fallback from the cart form).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

if (($_GET['remove_coupon'] ?? '') === '1') {
    unset($_SESSION['coupon_code']);
    flash('info', 'Coupon removed.');
    redirect('cart.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('cart.php');
}
verify_csrf();

$code = strtoupper(trim((string) ($_POST['code'] ?? '')));
$cart = cart_lines();

if ($code === '') {
    unset($_SESSION['coupon_code']);
    flash('warning', 'Enter a coupon code.');
    redirect('cart.php');
}

if ($cart['subtotal'] <= 0) {
    flash('warning', 'Add items to your cart before applying a coupon.');
    redirect('cart.php');
}

$result = evaluate_coupon($code, $cart['subtotal']);

if ($result['ok']) {
    $_SESSION['coupon_code'] = $code;
    flash('success', $result['message']);
} else {
    unset($_SESSION['coupon_code']);
    flash('danger', $result['message']);
}

redirect('cart.php');