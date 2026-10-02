<?php
/**
 * Session cart: line items, live totals, coupon entry and checkout entry.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$cart = cart_lines();

// Surface stock changes that happened while the cart sat idle.
foreach ($cart['issues'] as $issue) {
    flash('warning', $issue);
}

$subtotal     = $cart['subtotal'];
$count        = $cart['count'];

$applied = null;
$discount = 0.0;
$couponInput = trim((string) ($_SESSION['coupon_code'] ?? ''));

if ($couponInput !== '' && $subtotal > 0) {
    $result = evaluate_coupon($couponInput, $subtotal);
    if ($result['ok']) {
        $applied  = $result['coupon'];
        $discount = $result['discount'];
    } else {
        unset($_SESSION['coupon_code']);
    }
}

$totals   = order_totals($cart['items'], $discount);
$savings  = 0.0;
foreach ($cart['items'] as $line) {
    if ($line['mrp'] !== null && (float) $line['mrp'] > (float) $line['price']) {
        $savings += ((float) $line['mrp'] - (float) $line['price']) * $line['qty'];
    }
}

$page_title  = 'Shopping cart';
$page_active = 'cart';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i> <span>Cart</span>
  </nav>

  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-cart3"></i> Step 1 of 3</span>
      <h1 class="fc-section-title">Your shopping cart</h1>
      <p class="fc-section-sub">
        <?php if ($count > 0): ?>
          <?= $count ?> item<?= $count === 1 ? '' : 's' ?> &middot; review quantities before checkout.
        <?php else: ?>
          Add the products you need and they will show up here.
        <?php endif; ?>
      </p>
    </div>
    <?php if ($cart['items']): ?>
      <a class="btn btn-ghost btn-sm" href="products.php"><i class="bi bi-arrow-left"></i> Continue shopping</a>
    <?php endif; ?>
  </div>

  <?php if (!$cart['items']): ?>
    <div class="fc-card fc-empty">
      <div class="fc-empty-icon"><i class="bi bi-cart-x"></i></div>
      <h3>Your cart is empty</h3>
      <p>Browse the catalogue and add a few essentials to get started.</p>
      <div class="d-flex gap-2 justify-content-center flex-wrap">
        <a class="btn btn-green" href="products.php"><i class="bi bi-bag"></i> Browse products</a>
        <?php if (is_admin()): ?>
          <a class="btn btn-ghost" href="wishlist.php"><i class="bi bi-heart"></i> View wishlist</a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="fc-card">
          <div class="fc-card-head">
            <h3><i class="bi bi-list-ul"></i> Items</h3>
            <span class="text-muted" style="font-size:.86rem"><?= count($cart['items']) ?> product(s)</span>
          </div>

          <div>
            <?php foreach ($cart['items'] as $line):
                $maxQty = (int) $line['stock']; ?>
              <div class="fc-product-row" data-cart-row="<?= (int) $line['id'] ?>">
                <a class="fc-product-media" href="product.php?slug=<?= rawurlencode((string) $line['slug']) ?>">
                  <span class="fc-product-img">
                    <img src="<?= product_image($line['image']) ?>" alt="<?= h((string) $line['name']) ?>" loading="lazy" decoding="async"<?= product_img_responsive($line['image'], '96px') ?>
                         loading="lazy" width="96" height="96">
                  </span>
                </a>

                <div class="fc-product-body">
                  <div class="d-flex justify-content-between gap-2">
                    <div style="min-width:0">
                      <span class="fc-product-cat"><?= h((string) ($line['category'] ?? '')) ?></span>
                      <a class="fc-product-name d-block" style="min-height:0;-webkit-line-clamp:2"
                         href="product.php?slug=<?= rawurlencode((string) $line['slug']) ?>">
                        <?= h((string) $line['name']) ?>
                      </a>
                      <small class="text-muted"><?= h((string) $line['unit']) ?> &middot; <?= money($line['price']) ?> each</small>
                    </div>
                    <div class="text-end" style="white-space:nowrap">
                      <strong style="color:var(--fc-ink)" data-row-subtotal><?= money($line['subtotal']) ?></strong>
                    </div>
                  </div>

                  <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <div class="fc-qty" data-stepper data-min="1" data-max="<?= $maxQty ?>">
                      <button type="button" data-step="-1" aria-label="Decrease"><i class="bi bi-dash"></i></button>
                      <input type="number" name="quantity" value="<?= (int) $line['qty'] ?>"
                             min="1" max="<?= $maxQty ?>" data-cart-qty-input aria-label="Quantity for <?= h((string) $line['name']) ?>">
                      <button type="button" data-step="1" aria-label="Increase"><i class="bi bi-plus"></i></button>
                    </div>

                    <button class="btn btn-ghost btn-sm" type="button" data-cart-qty="1" title="Add one more">
                      <i class="bi bi-plus-circle"></i> Add one
                    </button>

                    <button class="btn btn-danger btn-sm ms-auto" type="button" data-cart-remove
                            title="Remove <?= h((string) $line['name']) ?>">
                      <i class="bi bi-trash"></i> Remove
                    </button>
                  </div>

                  <?php if ($maxQty <= 8): ?>
                    <small class="text-muted mt-2 d-block" style="font-size:.76rem">
                      <i class="bi bi-exclamation-triangle"></i> Only <?= $maxQty ?> in stock
                    </small>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($savings > 0): ?>
          <div class="fc-alert fc-alert-success mt-3">
            <i class="bi bi-piggy-bank"></i>
            <div>You are saving <b data-savings><?= money($savings) ?></b> on this order compared to the marked price.</div>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-4">
        <div class="fc-card fc-sticky-side">
          <div class="fc-card-head"><h3><i class="bi bi-receipt"></i> Order summary</h3></div>

          <div class="fc-card-body">
            <?php if ($applied): ?>
              <div class="fc-alert fc-alert-success mb-3">
                <i class="bi bi-ticket-perforated"></i>
                <div>
                  <b><?= h((string) $applied['code']) ?></b> applied &mdash; you save <?= money($discount) ?>.
                </div>
              </div>
              <a class="btn btn-plain btn-sm btn-block mb-3" href="cart.php?remove_coupon=1">
                <i class="bi bi-x"></i> Remove coupon
              </a>
            <?php else: ?>
              <form method="post" action="coupon_apply.php" class="mb-3">
                <?= csrf_field() ?>
                <label class="fc-label" for="code">Have a coupon?</label>
                <div class="fc-input-group">
                  <input class="fc-input text-uppercase" type="text" id="code" name="code"
                         maxlength="30" placeholder="FRESH50" value="<?= h($couponInput) ?>">
                  <button class="btn btn-green" type="submit">Apply</button>
                </div>
                <p class="fc-hint mb-0">Try <b>FRESH50</b>, <b>SAVE10</b> or <b>WELCOME25</b>.</p>
              </form>
            <?php endif; ?>

            <div class="d-flex flex-column gap-2" style="font-size:.9rem">
              <div class="d-flex justify-content-between">
                <span class="text-muted">Subtotal</span>
                <span data-order-total><?= money($totals['subtotal']) ?></span>
              </div>
              <?php if ($discount > 0): ?>
                <div class="d-flex justify-content-between text-green">
                  <span>Coupon discount</span>
                  <span>&minus; <?= money($discount) ?></span>
                </div>
              <?php endif; ?>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Delivery</span>
                <?php if ($totals['delivery_fee'] > 0): ?>
                  <span data-delivery-fee><?= money($totals['delivery_fee']) ?></span>
                <?php else: ?>
                  <span class="text-green" data-delivery-fee>FREE</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="fc-divider"></div>

            <div class="d-flex justify-content-between align-items-baseline mb-3">
              <strong style="color:var(--fc-ink)">Total</strong>
              <span style="font-size:1.5rem;font-weight:800;color:var(--fc-ink)" data-order-total>
                <?= money($totals['total']) ?>
              </span>
            </div>

            <a class="btn btn-green btn-lg btn-block mb-2" href="checkout.php">
              Proceed to checkout <i class="bi bi-arrow-right"></i>
            </a>
            <a class="btn btn-ghost btn-block" href="products.php">Keep shopping</a>

            <?php if ($totals['delivery_fee'] > 0): ?>
              <p class="fc-hint text-center mb-0">
                Add <?= money(FREE_DELIVERY_ABOVE - ($totals['subtotal'] - $totals['discount'])) ?> more for free delivery.
              </p>
            <?php else: ?>
              <p class="fc-hint text-center mb-0"><i class="bi bi-check-circle text-green"></i> Free delivery unlocked.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>