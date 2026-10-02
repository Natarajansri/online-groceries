<?php
/**
 * FreshCart storefront landing page.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/product_query.php';

$featured = db_all(
    'SELECT p.*, c.name AS category
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.is_active = 1 AND p.is_featured = 1 AND p.stock > 0
      ORDER BY p.sold_count DESC, p.id
      LIMIT 8'
);

$deals = db_all(
    'SELECT p.*, c.name AS category
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.is_active = 1 AND p.stock > 0 AND p.mrp IS NOT NULL AND p.mrp > p.price
      ORDER BY (p.mrp - p.price) / p.mrp DESC, p.id
      LIMIT 4'
);

$fresh = db_all(
    'SELECT p.*, c.name AS category
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.is_active = 1 AND p.stock > 0
      ORDER BY p.created_at DESC, p.id DESC
      LIMIT 4'
);

$categories = categories_with_counts();

$stats = [
    'products' => (int) db_value('SELECT COUNT(*) FROM products WHERE is_active = 1'),
    'sold'     => (int) db_value('SELECT COALESCE(SUM(sold_count),0) FROM products'),
    'rating'   => (float) (db_value('SELECT AVG(rating_avg) FROM products WHERE rating_count > 0') ?? 0),
];

$page_title = 'Fresh groceries delivered fast';
require __DIR__ . '/includes/header.php';
?>

<section class="fc-container">
  <div class="fc-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-7">
        <span class="fc-badge" style="background:rgba(255,255,255,.16);color:#fff">
          <i class="bi bi-lightning-charge-fill"></i> Same-day delivery slots
        </span>
        <h1 class="mt-3">Fresh groceries,<br>smarter ordering.</h1>
        <p>
          Pick from <?= number_format($stats['products']) ?> everyday essentials, build your cart in
          seconds and track every order from packing to your doorstep.
        </p>
        <div class="d-flex flex-wrap gap-2 mt-4">
          <a class="btn btn-white btn-lg" href="products.php">
            <i class="bi bi-bag-check"></i> Start shopping
          </a>
          <?php if (!is_logged_in()): ?>
            <a class="btn btn-outline-white btn-lg" href="register.php">Create free account</a>
          <?php else: ?>
            <a class="btn btn-outline-white btn-lg" href="orders.php">Track my orders</a>
          <?php endif; ?>
        </div>
        <div class="fc-hero-stats">
          <div><strong><?= number_format($stats['products']) ?>+</strong><span>Products in stock</span></div>
          <div><strong><?= number_format($stats['sold']) ?>+</strong><span>Items delivered</span></div>
          <div><strong><?= $stats['rating'] > 0 ? number_format($stats['rating'], 1) : '4.8' ?>/5</strong><span>Average rating</span></div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="fc-hero-card">
          <h3 class="mb-1" style="font-size:1.1rem">Why shop with <?= h(SITE_NAME) ?></h3>
          <p class="text-muted mb-3" style="font-size:.86rem">Everything you need, handled properly.</p>
          <ul>
            <li><i class="bi bi-shield-lock-fill"></i> <span><b>Safe checkout.</b> Stock is locked while your order is placed, so nothing oversells.</span></li>
            <li><i class="bi bi-geo-alt-fill"></i> <span><b>Live order tracking.</b> See each step from confirmed to delivered.</span></li>
            <li><i class="bi bi-cash-coin"></i> <span><b>Coupons &amp; savings.</b> Apply discount codes right in your cart.</span></li>
            <li><i class="bi bi-heart-fill"></i> <span><b>Wishlist &amp; reviews.</b> Save favourites and read what buyers say.</span></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if ($categories): ?>
<section class="fc-container fc-section">
  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-grid"></i> Browse</span>
      <h2 class="fc-section-title">Shop by category</h2>
    </div>
    <a class="btn btn-ghost btn-sm" href="products.php">View all <i class="bi bi-arrow-right"></i></a>
  </div>

  <div class="fc-grid fc-grid-3">
    <?php foreach ($categories as $cat): ?>
      <a class="fc-feature d-block" href="products.php?category=<?= h($cat['slug']) ?>">
        <div class="d-flex align-items-center gap-3">
          <span class="fc-feature-icon mb-0"><i class="bi bi-<?= h($cat['icon'] ?: 'basket') ?>"></i></span>
          <span>
            <h4 class="mb-1"><?= h($cat['name']) ?></h4>
            <small class="text-muted"><?= (int) $cat['product_count'] ?> products</small>
          </span>
          <i class="bi bi-arrow-right ms-auto text-muted"></i>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($featured): ?>
<section class="fc-container fc-section pt-0">
  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-star-fill"></i> Popular</span>
      <h2 class="fc-section-title">Customer favourites</h2>
      <p class="fc-section-sub">The items our shoppers keep coming back for.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="products.php?sort=popular">See all favourites <i class="bi bi-arrow-right"></i></a>
  </div>

  <div class="fc-grid fc-grid-4">
    <?php foreach ($featured as $product): require __DIR__ . '/includes/product_card.php'; endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($deals): ?>
<section class="fc-container fc-section pt-0">
  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-tag-fill"></i> Best value</span>
      <h2 class="fc-section-title">Today's deals</h2>
    </div>
  </div>
  <div class="fc-grid fc-grid-4">
    <?php foreach ($deals as $product): require __DIR__ . '/includes/product_card.php'; endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="fc-container fc-section pt-0">
  <div class="fc-grid fc-grid-2">
    <div class="fc-feature">
      <div class="d-flex gap-3 align-items-start">
        <span class="fc-feature-icon mb-0"><i class="bi bi-truck"></i></span>
        <div>
          <h4>Free delivery over <?= money(FREE_DELIVERY_ABOVE) ?></h4>
          <p>Flat <?= money(DELIVERY_FEE) ?> otherwise, applied automatically at checkout. No code needed.</p>
        </div>
      </div>
    </div>
    <div class="fc-feature">
      <div class="d-flex gap-3 align-items-start">
        <span class="fc-feature-icon mb-0"><i class="bi bi-arrow-counterclockwise"></i></span>
        <div>
          <h4>Cancel before packing</h4>
          <p>Changed your mind? Cancel any order while it is still in the Placed or Confirmed stage.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if ($fresh): ?>
<section class="fc-container fc-section pt-0">
  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-clock-history"></i> Just added</span>
      <h2 class="fc-section-title">New on the shelves</h2>
    </div>
  </div>
  <div class="fc-grid fc-grid-4">
    <?php foreach ($fresh as $product): require __DIR__ . '/includes/product_card.php'; endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="fc-container fc-section pt-0">
  <div class="fc-card fc-card-pad text-center" style="background:linear-gradient(135deg,#f0fdf4,#f8fafc)">
    <h2 class="fc-section-title">Ready to fill your basket?</h2>
    <p class="fc-section-sub mx-auto mb-4">
      <?php if (is_logged_in()): ?>
        You are signed in as <?= h(current_user_name()) ?>. Pick up where you left off.
      <?php else: ?>
        Create an account to save your cart, track orders and use coupons at checkout.
      <?php endif; ?>
    </p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
      <a class="btn btn-green btn-lg" href="products.php"><i class="bi bi-bag"></i> Browse the store</a>
      <?php if (!is_logged_in()): ?>
        <a class="btn btn-ghost btn-lg" href="register.php">Create account</a>
      <?php else: ?>
        <a class="btn btn-ghost btn-lg" href="wishlist.php">My wishlist</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>