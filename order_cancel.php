<?php
/**
 * Customer-initiated order cancellation.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('orders.php');
}
verify_csrf();

$orderId = (int) ($_POST['order_id'] ?? 0);

$order = db_one(
    'SELECT * FROM orders WHERE id = ? AND customer_id = ?',
    [$orderId, current_user_id()]
);

if ($order === null) {
    flash('danger', 'That order could not be found.');
    redirect('orders.php');
}

if (!in_array($order['status'], ['Placed', 'Confirmed'], true)) {
    flash('warning', 'This order has already moved to "' . $order['status'] . '" and can no longer be cancelled online.');
    redirect('order_details.php?id=' . $orderId);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $locked = db_one('SELECT status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);

    if ($locked === null || !in_array($locked['status'], ['Placed', 'Confirmed'], true)) {
        throw new RuntimeException('This order can no longer be cancelled online.');
    }

    // Return the reserved stock to inventory.
    $lines = db_all('SELECT product_id, quantity FROM order_items WHERE order_id = ?', [$orderId]);
    foreach ($lines as $line) {
        db_run('UPDATE products SET stock = stock + ?, sold_count = GREATEST(sold_count - ?, 0) WHERE id = ?',
            [(int) $line['quantity'], (int) $line['quantity'], (int) $line['product_id']]);
    }

    db_run('UPDATE orders SET status = ? WHERE id = ?', ['Cancelled', $orderId]);

    db_run(
        'INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)',
        [$orderId, 'Cancelled', 'Cancelled by the customer.']
    );

    // Release a single-use coupon back to the pool.
    if ($order['coupon_id'] !== null) {
        db_run('UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?',
            [(int) $order['coupon_id']]);
    }

    $pdo->commit();
    flash('success', 'Order ' . $order['order_code'] . ' has been cancelled.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $e->getMessage());
}

redirect('order_details.php?id=' . $orderId);