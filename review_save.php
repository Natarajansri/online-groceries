<?php
/**
 * Create or update a product review.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('products.php');
}
verify_csrf();

$productId = (int) ($_POST['product_id'] ?? 0);
$rating    = (int) ($_POST['rating'] ?? 0);
$title     = trim((string) ($_POST['title'] ?? ''));
$comment   = trim((string) ($_POST['comment'] ?? ''));

$product = db_one(
    'SELECT id, slug FROM products WHERE id = ? AND is_active = 1',
    [$productId]
);

if ($product === null) {
    flash('danger', 'That product is not available for review.');
    redirect('products.php');
}

$back = 'product.php?slug=' . rawurlencode((string) $product['slug']);

if ($rating < 1 || $rating > 5) {
    flash('warning', 'Choose a rating between 1 and 5 stars.');
    redirect($back . '#reviews');
}
if (mb_strlen($title) > 120) {
    $title = mb_substr($title, 0, 120);
}
if ($comment !== '' && mb_strlen($comment) > 2000) {
    flash('warning', 'Your review is too long (2000 characters maximum).');
    redirect($back . '#reviews');
}
if ($title === '' && $comment === '') {
    flash('warning', 'Add a headline or a few words about the product.');
    redirect($back . '#reviews');
}

$customerId = current_user_id();

$existing = db_one(
    'SELECT id FROM reviews WHERE product_id = ? AND customer_id = ?',
    [$productId, $customerId]
);

if ($existing !== null) {
    db_run(
        'UPDATE reviews SET rating = ?, title = ?, comment = ?, is_approved = 1, created_at = NOW()
          WHERE id = ?',
        [$rating, $title, $comment !== '' ? $comment : null, (int) $existing['id']]
    );
    flash('success', 'Your review has been updated.');
} else {
    db_run(
        'INSERT INTO reviews (product_id, customer_id, rating, title, comment, is_approved)
         VALUES (?, ?, ?, ?, ?, 1)',
        [$productId, $customerId, $rating, $title, $comment !== '' ? $comment : null]
    );
    flash('success', 'Thanks for sharing your review.');
}

// Keep the cached rating columns on the product in sync.
db_run(
    'UPDATE products p SET
        rating_avg   = (SELECT ROUND(AVG(r.rating), 2) FROM reviews r
                         WHERE r.product_id = p.id AND r.is_approved = 1),
        rating_count = (SELECT COUNT(*) FROM reviews r
                         WHERE r.product_id = p.id AND r.is_approved = 1)
      WHERE p.id = ?',
    [$productId]
);

redirect($back . '#reviews');