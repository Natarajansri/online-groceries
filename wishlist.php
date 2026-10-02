<?php
/**
 * Saved items (wishlist).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$sort = (string) ($_GET['sort'] ?? 'recent');
if (!in_array($sort, ['recent', 'price_low', 'price_high', 'name'], true)) {
    $sort = 'recent';
}

$orderBy = match ($sort) {
    'price_low'  => 'p.price ASC',
    'price_high' => 'p.price DESC',
    'name'       => 'p.name ASC',
    default      => 'w.created_at DESC',
};

$items = db_all(
    "SELECT p.*, c.name AS category, w.created_at AS saved_at,
            (SELECT ROUND(AVG(r.rating),2) FROM reviews r
              WHERE r.product_id = p.id AND r.is_approved = 1) AS rating_avg,
            (SELECT COUNT(*) FROM reviews r
              WHERE r.product_id = p.id AND r.is_approved = 1) AS rating_count
       FROM wishlist w
       JOIN products p ON p.id = w.product_id
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE w.customer_id = ? AND p.is_active = 1
      ORDER BY $orderBy",
    [current_user_id()]
);

// Drop wishlist rows that point at products which are gone or hidden.
db_run(
    'DELETE w FROM wishlist w LEFT JOIN products p ON p.id = w.product_id
      WHERE w.customer_id = ? AND (p.id IS NULL OR p.is_active = 0)',
    [current_user_id()]
);

$total = count($items);
$cart  = cart_lines();
$potential = 0.0;
foreach ($items as $item) {
    $potential += (float) $item['price'];
}

$page_title  = 'Wishlist';
$page_active = 'wishlist';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i> <span>Wishlist</span>
  </nav>

  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-heart"></i> Saved for later</span>
      <h1 class="fc-section-title">My wishlist</h1>
      <p class="fc-section-sub">
        <?= $total ?> item<?= $total === 1 ? '' : 's' ?> saved
        <?= $total > 0 ? ' · worth ' . money($potential) : '' ?>
      </p>
    </div>
    <a class="btn btn-green btn-sm" href="products.php"><i class="bi bi-bag"></i> Continue shopping</a>
  </div>

  <?php if ($total > 1): ?>
    <div class="fc-chip-row mb-4">
      <a class="fc-chip<?= $sort === 'recent' ? ' active' : '' ?>" href="wishlist.php?sort=recent">Recently saved</a>
      <a class="fc-chip<?= $sort === 'price_low' ? ' active' : '' ?>" href="wishlist.php?sort=price_low">Price: low to high</a>
      <a class="fc-chip<?= $sort === 'price_high' ? ' active' : '' ?>" href="wishlist.php?sort=price_high">Price: high to low</a>
      <a class="fc-chip<?= $sort === 'name' ? ' active' : '' ?>" href="wishlist.php?sort=name">Name A-Z</a>
    </div>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div class="fc-card fc-empty">
      <div class="fc-empty-icon"><i class="bi bi-heart"></i></div>
      <h3>Your wishlist is empty</h3>
      <p>Tap the heart on any product to save it here for later.</p>
      <a class="btn btn-green" href="products.php"><i class="bi bi-search"></i> Browse products</a>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <div class="col-lg-9">
        <div class="fc-grid fc-grid-3">
          <?php foreach ($items as $product):
              include __DIR__ . '/includes/product_card.php'; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-lg-3">
        <div class="fc-card fc-sticky-side">
          <div class="fc-card-head"><h3><i class="bi bi-bag-check"></i> Summary</h3></div>
          <div class="fc-card-body">
            <div class="d-flex flex-column gap-2" style="font-size:.9rem">
              <div class="d-flex justify-content-between">
                <span class="text-muted">Saved items</span><span><?= $total ?></span>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Combined value</span><span><?= money($potential) ?></span>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">In cart</span><span><?= (int) $cart['count'] ?> item(s)</span>
              </div>
            </div>

            <?php if ($potential >= FREE_DELIVERY_ABOVE): ?>
              <div class="fc-alert fc-alert-success mt-3 mb-0" style="font-size:.82rem">
                <i class="bi bi-truck"></i>
                <div>A cart worth <?= money($potential) ?> qualifies for free delivery.</div>
              </div>
            <?php else: ?>
              <div class="fc-alert fc-alert-info mt-3 mb-0" style="font-size:.82rem">
                <i class="bi bi-info-circle"></i>
                <div>Add <?= money(FREE_DELIVERY_ABOVE - $potential) ?> more to unlock free delivery.</div>
              </div>
            <?php endif; ?>

            <a class="btn btn-green btn-block mt-3" href="products.php">
              <i class="bi bi-bag"></i> Shop saved items
            </a>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>