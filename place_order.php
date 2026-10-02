<?php
/**
 * Place an order inside a single transaction with row-level stock locking.
 *
 * checkout.php now calls place_order_from_checkout() directly, because this
 * file previously could only be reached by a 302 GET redirect and refused
 * anything but POST - so the redirect bounced back to checkout.php without
 * ever creating an order. This remains as a POST-only fallback for any form
 * that still targets it directly.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('checkout.php');
}
verify_csrf();

$checkout = $_SESSION['checkout'] ?? null;

if ($checkout === null || !cart_lines()['items']) {
    flash('warning', 'Your checkout session expired. Please review your cart again.');
    redirect('cart.php');
}

try {
    $orderId = place_order_from_checkout($checkout);
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
    redirect('cart.php');
}

redirect('order_success.php?id=' . $orderId);