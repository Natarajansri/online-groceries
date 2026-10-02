<?php
/**
 * Single product page: gallery, reviews, related items.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    redirect('products.php');
}

$product = db_one(
    'SELECT p.*, c.name AS category, c.slug AS category_slug, c.icon AS category_icon
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.slug = ? AND p.is_active = 1',
    [$slug]
);

if ($product === null) {
    http_response_code(404);
    $page_title = 'Product not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="fc-container"><div class="fc-card fc-empty">'
       . '<div class="fc-empty-icon"><i class="bi bi-exclamation-circle"></i></div>'
       . '<h3>We could not find that product</h3>'
       . '<p>It may have been removed or is no longer available.</p>'
       . '<a class="btn btn-green" href="products.php">Back to shop</a>'
       . '</div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$productId = (int) $product['id'];
$stock     = (int) $product['stock'];
$price     = (float) $product['price'];
$save      = discount_percent($price, $product['mrp'] !== null ? (float) $product['mrp'] : null);

// Already in a cart? Remember where the shopper came from.
if (!isset($_SESSION['_from'])) {
    $_SESSION['_from'] = 'product.php?slug=' . rawurlencode($slug);
}

$related = db_all(
    'SELECT p.*, c.name AS category
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.category_id = ? AND p.id <> ? AND p.is_active = 1
      ORDER BY p.is_featured DESC, p.sold_count DESC
      LIMIT 4',
    [$product['category_id'], $productId]
);

/* ------------------------------------------------------------- reviews */

$reviewPage   = max(1, (int) ($_GET['rev_page'] ?? 1));
$reviewTotal  = (int) db_value('SELECT COUNT(*) FROM reviews WHERE product_id = ? AND is_approved = 1', [$productId]);
$reviewPages  = max(1, (int) ceil($reviewTotal / REVIEWS_PER_PAGE));
$reviewOffset = ($reviewPage - 1) * REVIEWS_PER_PAGE;

$reviews = db_all(
    'SELECT r.*, c.name AS customer_name
       FROM reviews r JOIN customers c ON c.id = r.customer_id
      WHERE r.product_id = ? AND r.is_approved = 1
      ORDER BY r.created_at DESC
      LIMIT ' . REVIEWS_PER_PAGE . ' OFFSET ' . $reviewOffset,
    [$productId]
);

$breakdown = db_all(
    'SELECT rating, COUNT(*) AS total
       FROM reviews WHERE product_id = ? AND is_approved = 1
      GROUP BY rating',
    [$productId]
);
$breakdownIndex = [];
foreach ($breakdown as $row) {
    $breakdownIndex[(int) $row['rating']] = (int) $row['total'];
}

$myReview = is_logged_in()
    ? db_one('SELECT * FROM reviews WHERE product_id = ? AND customer_id = ?', [$productId, current_user_id()])
    : null;

$purchased = is_logged_in()
    ? (int) db_value(
        "SELECT COUNT(*) FROM order_items oi
           JOIN orders o ON o.id = oi.order_id
          WHERE oi.product_id = ? AND o.customer_id = ? AND o.status <> 'Cancelled'",
        [$productId, current_user_id()]
      )
    : 0;

$page_title = (string) $product['name'];
$page_active = 'shop';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i>
    <a href="products.php">Shop</a> <i class="bi bi-chevron-right"></i>
    <a href="products.php?category=<?= h((string) $product['category_slug']) ?>"><?= h((string) $product['category']) ?></a>
    <i class="bi bi-chevron-right"></i>
    <span class="text-truncate"><?= h((string) $product['name']) ?></span>
  </nav>

  <div class="row g-4 mb-5">
    <div class="col-lg-5">
      <div class="fc-card overflow-hidden">
        <div style="aspect-ratio:1/1;background:linear-gradient(160deg,#f8fafc,#eef6f0)">
          <img src="<?= product_image($product['image']) ?>" alt="<?= h((string) $product['name']) ?>" decoding="async"<?= product_img_responsive($product['image'], '(max-width: 991px) 92vw, 42vw') ?>
               style="width:100%;height:100%;object-fit:cover" width="560" height="560">
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 mt-3">
        <span class="fc-badge badge-soft-success"><i class="bi bi-shield-check"></i> Quality checked</span>
        <?php if ($purchased > 0): ?>
          <span class="fc-badge badge-soft-teal"><i class="bi bi-bag-check"></i> You bought this</span>
        <?php endif; ?>
        <?php if ($product['is_featured']): ?>
          <span class="fc-badge badge-soft-warning"><i class="bi bi-star-fill"></i> Store favourite</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-7">
      <span class="fc-product-cat"><?= h((string) $product['category']) ?></span>
      <h1 class="mt-1 mb-2" style="font-size:clamp(1.5rem,3vw,2.1rem)"><?= h((string) $product['name']) ?></h1>

      <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <?php if ((float) $product['rating_avg'] > 0): ?>
          <?= star_rating((float) $product['rating_avg'], (int) $product['rating_count']) ?>
          <a href="#reviews" class="text-muted" style="font-size:.85rem">
            Read <?= (int) $product['rating_count'] ?> review<?= (int) $product['rating_count'] === 1 ? '' : 's' ?>
          </a>
        <?php else: ?>
          <span class="text-muted" style="font-size:.85rem">No reviews yet &mdash; be the first</span>
        <?php endif; ?>
        <?php if ((int) $product['sold_count'] > 0): ?>
          <span class="text-muted" style="font-size:.85rem"><i class="bi bi-bag-check"></i> <?= (int) $product['sold_count'] ?> sold</span>
        <?php endif; ?>
        <span class="text-muted" style="font-size:.85rem">SKU&nbsp;FC-<?= str_pad((string) $productId, 5, '0', STR_PAD_LEFT) ?></span>
      </div>

      <?php if (!empty($product['description'])): ?>
        <p style="font-size:.95rem"><?= h((string) $product['description']) ?></p>
      <?php endif; ?>

      <div class="d-flex flex-wrap gap-3 mb-4" style="font-size:.86rem">
        <span class="text-muted"><i class="bi bi-box-seam"></i> Pack size: <b class="text-body"><?= h((string) $product['unit']) ?></b></span>
        <span class="text-muted"><i class="bi bi-tag"></i> Brand: <b class="text-body"><?= h((string) $product['brand']) ?></b></span>
        <span class="text-muted"><i class="bi bi-geo-alt"></i> Delivered from FreshCart hub</span>
      </div>

      <div class="fc-card p-3 mb-3" style="background:var(--fc-bg-alt)">
        <div class="d-flex flex-wrap align-items-center gap-3">
          <div>
            <div class="d-flex align-items-baseline gap-2">
              <span style="font-size:2rem;font-weight:800;color:var(--fc-ink);line-height:1"><?= money($price) ?></span>
              <?php if ($save > 0): ?>
                <s class="text-muted"><?= money($product['mrp']) ?></s>
                <span class="fc-badge fc-badge-save">Save <?= money((float) $product['mrp'] - $price) ?></span>
              <?php endif; ?>
            </div>
            <small class="text-muted">Inclusive of all taxes</small>
          </div>

          <?php if ($stock > 0): ?>
            <span class="fc-badge badge-soft-success ms-auto">
              <i class="bi bi-check-circle"></i> <?= $stock <= 8 ? 'Only ' . $stock . ' left' : 'In stock' ?>
            </span>
          <?php else: ?>
            <span class="fc-badge badge-soft-danger ms-auto"><i class="bi bi-x-circle"></i> Out of stock</span>
          <?php endif; ?>
        </div>
      </div>

      <div data-qty-scope>
        <?php if ($stock > 0): ?>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <div class="fc-qty" data-stepper data-min="1" data-max="<?= $stock ?>">
              <button type="button" data-step="-1" aria-label="Decrease"><i class="bi bi-dash"></i></button>
              <input type="number" name="quantity" value="1" min="1" max="<?= $stock ?>" aria-label="Quantity">
              <button type="button" data-step="1" aria-label="Increase"><i class="bi bi-plus"></i></button>
            </div>

            <?php if (is_logged_in()): ?>
              <button class="btn btn-green btn-lg flex-grow-1" type="button"
                      data-add-cart="<?= $productId ?>" data-return-to="product.php">
                <i class="bi bi-cart-plus"></i> Add to cart
              </button>
            <?php else: ?>
              <a class="btn btn-green btn-lg flex-grow-1" href="<?= app_url('login.php') ?>">
                <i class="bi bi-box-arrow-in-right"></i> Sign in to order
              </a>
            <?php endif; ?>

            <?php if (is_logged_in()): ?>
              <button class="btn btn-ghost btn-lg<?= in_wishlist($productId) ? ' on' : '' ?>"
                      type="button" data-wishlist="<?= $productId ?>" title="Save for later">
                <i class="bi <?= in_wishlist($productId) ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
              </button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <button class="btn btn-ghost btn-lg flex-grow-1" disabled><i class="bi bi-x-circle"></i> Currently unavailable</button>
            <?php if (is_logged_in()): ?>
              <button class="btn btn-ghost btn-lg<?= in_wishlist($productId) ? ' on' : '' ?>" type="button" data-wishlist="<?= $productId ?>">
                <i class="bi <?= in_wishlist($productId) ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
              </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if (cart_in_cart($productId) > 0): ?>
        <div class="fc-alert fc-alert-info mb-3">
          <i class="bi bi-cart-check"></i>
          <div><?= cart_in_cart($productId) ?> unit(s) already in your <a href="cart.php">cart</a>.</div>
        </div>
      <?php endif; ?>

      <div class="row g-2">
        <div class="col-sm-6">
          <div class="fc-card p-3 h-100">
            <h6 class="mb-1" style="font-size:.88rem"><i class="bi bi-truck"></i> Fast delivery</h6>
            <small class="text-muted">Order before 6 PM for delivery today<?= $price + DELIVERY_FEE >= FREE_DELIVERY_ABOVE ? ', free on this order' : '' ?>.</small>
          </div>
        </div>
        <div class="col-sm-6">
          <div class="fc-card p-3 h-100">
            <h6 class="mb-1" style="font-size:.88rem"><i class="bi bi-arrow-counterclockwise"></i> Easy refunds</h6>
            <small class="text-muted">Cancel free of charge while your order is being packed.</small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- reviews -->
  <section id="reviews" class="fc-section pt-0">
    <div class="fc-section-head">
      <div>
        <span class="fc-eyebrow"><i class="bi bi-chat-left-text"></i> Feedback</span>
        <h2 class="fc-section-title">Customer reviews</h2>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="fc-card fc-card-pad">
          <div class="text-center mb-3">
            <div style="font-size:2.6rem;font-weight:800;color:var(--fc-ink);line-height:1">
              <?= number_format((float) $product['rating_avg'], 1) ?>
            </div>
            <div class="mt-1"><?= star_rating((float) $product['rating_avg'], 0, false) ?></div>
            <small class="text-muted"><?= (int) $product['rating_count'] ?> verified review(s)</small>
          </div>

          <?php for ($star = 5; $star >= 1; $star--):
              $count  = $breakdownIndex[$star] ?? 0;
              $pct    = $reviewTotal > 0 ? round($count / $reviewTotal * 100) : 0; ?>
            <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.8rem">
              <span class="text-muted" style="width:2.6rem"><?= $star ?> <i class="bi bi-star-fill" style="color:var(--fc-amber)"></i></span>
              <div class="flex-grow-1" style="height:6px;background:var(--fc-line-soft);border-radius:999px;overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:var(--fc-amber)"></div>
              </div>
              <span class="text-muted" style="width:1.8rem;text-align:right"><?= $count ?></span>
            </div>
          <?php endfor; ?>

          <?php if ($myReview): ?>
            <div class="fc-alert fc-alert-info mt-3" style="font-size:.82rem">
              <i class="bi bi-pencil"></i>
              <div>You reviewed this product. Submitting again replaces your review.</div>
            </div>
          <?php endif; ?>

          <?php if (is_logged_in()): ?>
            <form method="post" action="review_save.php" class="mt-3">
              <?= csrf_field() ?>
              <input type="hidden" name="product_id" value="<?= $productId ?>">

              <label class="fc-label" for="rating">Your rating</label>
              <div class="d-flex align-items-center gap-2 mb-3" data-rating-input="rating">
                <?php $current = (int) ($myReview['rating'] ?? 5); ?>
                <?php for ($s = 1; $s <= 5; $s++): ?>
                  <button type="button" data-value="<?= $s ?>" style="border:0;background:none;padding:0;font-size:1.35rem;color:#cbd5e1">
                    <i class="bi bi-<?= $s <= $current ? 'star-fill' : 'star' ?>"></i>
                  </button>
                <?php endfor; ?>
                <input type="hidden" name="rating" id="rating" value="<?= $current ?>">
              </div>

              <div class="fc-field">
                <label class="fc-label" for="title">Headline</label>
                <input class="fc-input" id="title" name="title" maxlength="120"
                       placeholder="Fresh and well packed" value="<?= h((string) ($myReview['title'] ?? '')) ?>">
              </div>
              <div class="fc-field">
                <label class="fc-label" for="comment">Your review</label>
                <textarea class="fc-textarea" id="comment" name="comment"
                          placeholder="How was the quality, packaging and delivery?"><?= h((string) ($myReview['comment'] ?? '')) ?></textarea>
              </div>
              <button class="btn btn-green btn-block btn-sm" type="submit">
                <i class="bi bi-send"></i> <?= $myReview ? 'Update review' : 'Post review' ?>
              </button>
            </form>
          <?php else: ?>
            <a class="btn btn-ghost btn-block btn-sm mt-3" href="login.php?redirect=product.php%3Fslug%3D<?= rawurlencode($slug) ?>">
              <i class="bi bi-person"></i> Login to write a review
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-8">
        <?php if (!$reviews): ?>
          <div class="fc-card fc-empty">
            <div class="fc-empty-icon"><i class="bi bi-chat-square"></i></div>
            <h3>No reviews yet</h3>
            <p>Be the first to share how this product turned out.</p>
          </div>
        <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($reviews as $review): ?>
              <article class="fc-card p-3">
                <div class="d-flex gap-3">
                  <span class="fc-avatar" style="width:38px;height:38px;flex:0 0 38px">
                    <?= h(strtoupper(substr((string) $review['customer_name'], 0, 1))) ?>
                  </span>
                  <div class="flex-grow-1">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                      <div>
                        <strong style="font-size:.92rem"><?= h((string) $review['customer_name']) ?></strong>
                        <span class="fc-badge badge-soft-success ms-1" style="font-size:.65rem">
                          <i class="bi bi-patch-check-fill"></i> Verified
                        </span>
                      </div>
                      <small class="text-muted"><?= h(time_ago((string) $review['created_at'])) ?></small>
                    </div>
                    <div class="mt-1"><?= star_rating((float) $review['rating'], 0, false) ?></div>
                    <?php if (!empty($review['title'])): ?>
                      <h6 class="mt-2 mb-1" style="font-size:.94rem"><?= h((string) $review['title']) ?></h6>
                    <?php endif; ?>
                    <?php if (!empty($review['comment'])): ?>
                      <p class="mb-0" style="font-size:.89rem"><?= h((string) $review['comment']) ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>

          <?php
          $revLink = static function (int $p) use ($slug): string {
              $qs = $_GET;
              $qs['slug'] = $slug;
              $qs['rev_page'] = $p;
              return 'product.php?' . http_build_query($qs) . '#reviews';
          };
          ?>
          <?php if ($reviewPages > 1): ?>
            <nav class="fc-pagination" aria-label="Review pages">
              <ul class="pagination mb-0">
                <li class="page-item<?= $reviewPage <= 1 ? ' disabled' : '' ?>">
                  <a class="page-link" href="<?= h($revLink($reviewPage - 1)) ?>"><i class="bi bi-chevron-left"></i></a>
                </li>
                <?php for ($p = 1; $p <= $reviewPages; $p++): ?>
                  <li class="page-item<?= $p === $reviewPage ? ' active' : '' ?>">
                    <a class="page-link" href="<?= h($revLink($p)) ?>"><?= $p ?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item<?= $reviewPage >= $reviewPages ? ' disabled' : '' ?>">
                  <a class="page-link" href="<?= h($revLink($reviewPage + 1)) ?>"><i class="bi bi-chevron-right"></i></a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($related): ?>
    <section class="fc-section pt-0">
      <div class="fc-section-head">
        <div>
          <span class="fc-eyebrow"><i class="bi bi-basket"></i> Complete the basket</span>
          <h2 class="fc-section-title">You might also like</h2>
        </div>
        <a class="btn btn-ghost btn-sm" href="products.php?category=<?= h((string) $product['category_slug']) ?>">
          More in <?= h((string) $product['category']) ?> <i class="bi bi-arrow-right"></i>
        </a>
      </div>
      <div class="fc-grid fc-grid-4">
        <?php foreach ($related as $product): require __DIR__ . '/includes/product_card.php'; endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>