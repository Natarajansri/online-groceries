<?php
/**
 * Full detail for a single order, including the tracking timeline.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$orderId = (int) ($_GET['id'] ?? 0);

$order = db_one(
    'SELECT o.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone
       FROM orders o JOIN customers c ON c.id = o.customer_id
      WHERE o.id = ? AND o.customer_id = ?',
    [$orderId, current_user_id()]
);

if ($order === null) {
    flash('danger', 'That order could not be found.');
    redirect('orders.php');
}

$items = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);

$history = db_all(
    'SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC, id ASC',
    [$orderId]
);

$canCancel = in_array($order['status'], ['Placed', 'Confirmed'], true);

$page_title  = 'Order ' . $order['order_code'];
$page_active = 'orders';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i>
    <a href="orders.php">My orders</a> <i class="bi bi-chevron-right"></i>
    <span><?= h((string) $order['order_code']) ?></span>
  </nav>

  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-receipt-cutoff"></i> Order detail</span>
      <h1 class="fc-section-title"><?= h((string) $order['order_code']) ?></h1>
      <p class="fc-section-sub">
        Placed <?= h(nice_date((string) $order['order_date'])) ?>
        &middot; <?= h((string) $order['payment_method']) ?>
        &middot; <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>
      </p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-ghost btn-sm" type="button" onclick="window.print()">
        <i class="bi bi-printer"></i> Print
      </button>
      <?php if ($canCancel): ?>
        <form method="post" action="order_cancel.php">
          <?= csrf_field() ?>
          <input type="hidden" name="order_id" value="<?= $orderId ?>">
          <button class="btn btn-danger btn-sm" data-confirm="Cancel this order? This cannot be undone.">
            <i class="bi bi-x-circle"></i> Cancel order
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="fc-card mb-4">
        <div class="fc-card-head">
          <h3><i class="bi bi-bag-check"></i> Items</h3>
          <?= status_badge((string) $order['status']) ?>
        </div>

        <div>
          <?php foreach ($items as $item): ?>
            <div class="fc-product-row">
              <span class="fc-product-media" style="width:74px;flex:0 0 74px;border-radius:10px">
                <img src="<?= product_image($item['image']) ?>" alt="" loading="lazy" decoding="async" width="74" height="74"<?= product_img_responsive($item['image'], '74px') ?>
                     style="width:100%;height:100%;object-fit:cover">
              </span>
              <div class="fc-product-body">
                <span style="font-size:.92rem;color:var(--fc-ink);font-weight:600"><?= h((string) $item['product_name']) ?></span>
                <span class="d-block text-muted" style="font-size:.83rem">
                  <?= (int) $item['quantity'] ?> &times; <?= money($item['price']) ?>
                </span>
              </div>
              <div class="text-end" style="white-space:nowrap">
                <strong style="color:var(--fc-ink)"><?= money($item['subtotal']) ?></strong>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="fc-card-foot">
          <div class="d-flex flex-column gap-2" style="font-size:.9rem">
            <div class="d-flex justify-content-between">
              <span class="text-muted">Subtotal</span><span><?= money($order['subtotal']) ?></span>
            </div>
            <?php if ((float) $order['discount'] > 0): ?>
              <div class="d-flex justify-content-between text-green">
                <span>Discount</span><span>&minus; <?= money($order['discount']) ?></span>
              </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between">
              <span class="text-muted">Delivery</span>
              <?php if ((float) $order['delivery_fee'] > 0): ?>
                <span><?= money($order['delivery_fee']) ?></span>
              <?php else: ?>
                <span class="text-green">FREE</span>
              <?php endif; ?>
            </div>
            <div class="d-flex justify-content-between pt-2 mt-1 border-top" style="font-size:1.05rem">
              <strong style="color:var(--fc-ink)">Total</strong>
              <strong style="color:var(--fc-ink)"><?= money($order['total_amount']) ?></strong>
            </div>
          </div>
        </div>
      </div>

      <div class="fc-card">
        <div class="fc-card-head"><h3><i class="bi bi-signpost-split"></i> Tracking timeline</h3></div>
        <div class="fc-card-body">
          <?php if ($order['status'] === 'Cancelled'): ?>
            <div class="fc-alert fc-alert-danger">
              <i class="bi bi-x-circle"></i>
              <div>This order was cancelled<?= $history ? ' on ' . h(nice_date((string) end($history)['created_at'])) : '' ?>.</div>
            </div>
          <?php else: ?>
            <ol class="list-unstyled mb-0" style="position:relative;padding-left:1.7rem">
              <?php foreach (ORDER_FLOW as $step):
                  $reached   = array_search($step, array_column($history, 'status'), true) !== false;
                  $isCurrent = $step === $order['status'];
                  $stamp     = null;
                  foreach ($history as $entry) {
                      if ($entry['status'] === $step) {
                          $stamp = $entry;
                          break;
                      }
                  } ?>
                <li style="position:relative;padding-bottom:1.15rem">
                  <span style="position:absolute;left:-1.7rem;top:.2rem;width:14px;height:14px;border-radius:50%;
                               background:<?= $reached ? 'var(--fc-green)' : '#e2e8f0' ?>;
                               border:2px solid <?= $isCurrent ? 'var(--fc-green)' : 'transparent' ?>;
                               box-shadow:0 0 0 3px <?= $isCurrent ? 'var(--fc-green-light)' : 'transparent' ?>"></span>
                  <div class="d-flex flex-wrap align-items-center gap-2">
                    <span style="font-size:.92rem;font-weight:<?= $isCurrent ? '700' : '500' ?>;
                                 color:<?= $reached ? 'var(--fc-ink)' : 'var(--fc-faint)' ?>">
                      <?= h($step) ?>
                    </span>
                    <?php if ($isCurrent): ?>
                      <span class="fc-badge badge-soft-success">Current</span>
                    <?php endif; ?>
                    <?php if ($stamp !== null): ?>
                      <small class="text-muted ms-auto" style="font-size:.78rem"><?= h(nice_date((string) $stamp['created_at'])) ?></small>
                    <?php endif; ?>
                  </div>
                  <?php if ($stamp !== null && $stamp['note'] !== ''): ?>
                    <small class="d-block text-muted" style="font-size:.8rem"><?= h((string) $stamp['note']) ?></small>
                  <?php elseif (!empty(status_meta($step)['hint'])): ?>
                    <small class="d-block text-muted" style="font-size:.8rem;opacity:.75"><?= h(status_meta($step)['hint']) ?></small>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="fc-card mb-4">
        <div class="fc-card-head"><h3><i class="bi bi-geo-alt"></i> Delivery address</h3></div>
        <div class="fc-card-body">
          <p class="mb-0" style="font-size:.88rem;white-space:pre-line"><?= h((string) $order['address_snapshot']) ?></p>
          <?php if (!empty($order['note'])): ?>
            <div class="fc-divider"></div>
            <small class="text-muted d-block">Instructions</small>
            <p class="mb-0" style="font-size:.88rem"><?= h((string) $order['note']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="fc-card mb-4">
        <div class="fc-card-head"><h3><i class="bi bi-person"></i> Customer</h3></div>
        <div class="fc-card-body">
          <strong style="font-size:.9rem"><?= h((string) $order['customer_name']) ?></strong>
          <small class="d-block text-muted" style="font-size:.85rem"><?= h((string) $order['customer_email']) ?></small>
          <small class="d-block text-muted" style="font-size:.85rem"><?= h((string) $order['customer_phone']) ?></small>
        </div>
      </div>

      <div class="fc-card">
        <div class="fc-card-head"><h3><i class="bi bi-cash-stack"></i> Payment</h3></div>
        <div class="fc-card-body">
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Method</span>
            <span class="badge badge-soft-info"><?= h((string) $order['payment_method']) ?></span>
          </div>
          <?php if ($order['status'] === 'Delivered'): ?>
            <div class="fc-alert fc-alert-success mb-0">
              <i class="bi bi-check-circle"></i><div>Payment collected on delivery.</div>
            </div>
          <?php else: ?>
            <div class="fc-alert fc-alert-warning mb-0">
              <i class="bi bi-hourglass-split"></i><div>Payable on delivery: <b><?= money($order['total_amount']) ?></b></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>