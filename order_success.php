<?php
/**
 * Post-order confirmation with tracking timeline.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$orderId = (int) ($_GET['id'] ?? 0);

$order = db_one(
    'SELECT * FROM orders WHERE id = ? AND customer_id = ?',
    [$orderId, current_user_id()]
);

if ($order === null) {
    flash('danger', 'That order could not be found.');
    redirect('orders.php');
}

$items = db_all(
    'SELECT * FROM order_items WHERE order_id = ? ORDER BY id',
    [$orderId]
);

$history = db_all(
    'SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC, id ASC',
    [$orderId]
);

$etaDays = $order['status'] === 'Delivered' ? 0 : 2;

$page_title = 'Order confirmed';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <div class="fc-card p-4 p-md-5 text-center mb-4">
    <div class="fc-empty-icon" style="width:92px;height:92px;font-size:2.6rem;background:var(--fc-green-light)">
      <i class="bi bi-check-lg" style="color:var(--fc-green)"></i>
    </div>
    <h1 class="mt-3" style="font-size:clamp(1.4rem,3vw,2rem)">Order placed successfully</h1>
    <p class="text-muted mb-1">
      Thanks<?= current_user_name() !== '' ? ', ' . h(explode(' ', current_user_name())[0]) : '' ?>.
      Your order code is <b style="color:var(--fc-ink)"><?= h((string) $order['order_code']) ?></b>.
    </p>
    <p class="text-muted" style="font-size:.9rem">
      A confirmation has been recorded on your account. You can cancel it free of charge while it
      is still <?= h(status_meta('Placed')['hint'] === '' ? 'Placed' : 'Placed or Confirmed') ?>.
    </p>

    <div class="d-flex flex-wrap gap-4 justify-content-center mt-4">
      <div class="text-center">
        <small class="text-muted d-block" style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase">Items</small>
        <strong style="font-size:1.25rem;color:var(--fc-ink)"><?= count($items) ?></strong>
      </div>
      <div class="text-center">
        <small class="text-muted d-block" style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase">Paid</small>
        <strong style="font-size:1.25rem;color:var(--fc-ink)"><?= money($order['total_amount']) ?></strong>
      </div>
      <div class="text-center">
        <small class="text-muted d-block" style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase">Payment</small>
        <strong style="font-size:1.25rem;color:var(--fc-ink)"><?= h((string) $order['payment_method']) ?></strong>
      </div>
      <div class="text-center">
        <small class="text-muted d-block" style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase">Status</small>
        <div class="mt-1"><?= status_badge((string) $order['status']) ?></div>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 justify-content-center mt-4">
      <a class="btn btn-green" href="order_details.php?id=<?= $orderId ?>">
        <i class="bi bi-receipt"></i> View order details
      </a>
      <a class="btn btn-ghost" href="products.php"><i class="bi bi-bag"></i> Keep shopping</a>
      <a class="btn btn-ghost" href="orders.php"><i class="bi bi-clock-history"></i> All orders</a>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="fc-card">
        <div class="fc-card-head"><h3><i class="bi bi-box-seam"></i> Items in this order</h3></div>
        <div>
          <?php foreach ($items as $item): ?>
            <div class="fc-product-row">
              <span class="fc-product-media" style="width:74px;flex:0 0 74px;border-radius:10px">
                <img src="<?= product_image($item['image']) ?>" alt="" loading="lazy" decoding="async" width="74" height="74"<?= product_img_responsive($item['image'], '74px') ?>
                     style="width:100%;height:100%;object-fit:cover">
              </span>
              <div class="fc-product-body">
                <span style="font-size:.9rem;color:var(--fc-ink);font-weight:600"><?= h((string) $item['product_name']) ?></span>
                <span class="d-block text-muted" style="font-size:.82rem">
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
          <div class="d-flex flex-column gap-2" style="font-size:.88rem">
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
            <div class="d-flex justify-content-between pt-2 mt-1 border-top" style="font-size:1rem">
              <strong style="color:var(--fc-ink)">Total</strong>
              <strong style="color:var(--fc-ink)"><?= money($order['total_amount']) ?></strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="fc-card mb-4">
        <div class="fc-card-head"><h3><i class="bi bi-geo-alt"></i> Delivering to</h3></div>
        <div class="fc-card-body">
          <p class="mb-0" style="font-size:.88rem;white-space:pre-line"><?= h((string) $order['address_snapshot']) ?></p>
          <?php if (!empty($order['note'])): ?>
            <div class="fc-divider"></div>
            <small class="text-muted d-block">Delivery instructions</small>
            <p class="mb-0" style="font-size:.88rem"><?= h((string) $order['note']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="fc-card">
        <div class="fc-card-head"><h3><i class="bi bi-signpost-split"></i> Tracking</h3></div>
        <div class="fc-card-body">
          <?php if ($order['status'] === 'Cancelled'): ?>
            <div class="fc-alert fc-alert-danger">
              <i class="bi bi-x-circle"></i><div>This order was cancelled. Any amount paid will be refunded in 3-5 working days.</div>
            </div>
          <?php else: ?>
            <ol class="list-unstyled mb-0" style="position:relative;padding-left:1.6rem">
              <?php foreach (ORDER_FLOW as $step):
                  $done = array_search($step, array_column($history, 'status'), true) !== false;
                  $isCurrent = $step === $order['status'];
                  $stepNote = '';
                  foreach ($history as $entry) {
                      if ($entry['status'] === $step && $entry['note'] !== '') {
                          $stepNote = (string) $entry['note'];
                          break;
                      }
                  } ?>
                <li style="position:relative;padding-bottom:1rem">
                  <span style="position:absolute;left:-1.6rem;top:.15rem;width:13px;height:13px;border-radius:50%;
                               background:<?= $done ? 'var(--fc-green)' : '#e2e8f0' ?>;
                               border:2px solid <?= $isCurrent ? 'var(--fc-green)' : 'transparent' ?>;
                               box-shadow:0 0 0 3px <?= $isCurrent ? 'var(--fc-green-light)' : 'transparent' ?>"></span>
                  <span style="font-size:.89rem;color:<?= $done ? 'var(--fc-ink)' : 'var(--fc-faint)' ?>">
                    <?= h($step) ?>
                    <?php if ($isCurrent): ?><span class="fc-badge badge-soft-success ms-1">Current</span><?php endif; ?>
                  </span>
                  <?php if ($stepNote !== ''): ?>
                    <small class="d-block text-muted" style="font-size:.78rem"><?= h($stepNote) ?></small>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>

            <?php if ($etaDays > 0): ?>
              <div class="fc-alert fc-alert-info mt-2">
                <i class="bi bi-clock"></i>
                <div>Estimated delivery in <?= $etaDays ?> day<?= $etaDays === 1 ? '' : 's' ?>, between 8 AM and 10 PM.</div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>