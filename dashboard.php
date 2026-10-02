<?php
/**
 * Customer dashboard: stats, recent orders, wishlist preview.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$customerId = current_user_id();

$customer = db_one('SELECT * FROM customers WHERE id = ?', [$customerId]) ?? [];

$stats = db_one(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN total_amount END), 0) AS spent,
            COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN discount END), 0) AS saved
       FROM orders WHERE customer_id = ?",
    [$customerId]
) ?? ['orders' => 0, 'spent' => 0, 'saved' => 0];

$activeOrders = db_all(
    "SELECT * FROM orders
      WHERE customer_id = ? AND status NOT IN ('Delivered','Cancelled')
      ORDER BY order_date DESC LIMIT 4",
    [$customerId]
);

$recentOrders = db_all(
    'SELECT * FROM orders WHERE customer_id = ? ORDER BY order_date DESC LIMIT 5',
    [$customerId]
);

$wishlist = db_all(
    'SELECT p.* FROM wishlist w
       JOIN products p ON p.id = w.product_id
      WHERE w.customer_id = ? AND p.is_active = 1
      ORDER BY w.created_at DESC LIMIT 4',
    [$customerId]
);

$wishTotal = (int) db_value('SELECT COUNT(*) FROM wishlist WHERE customer_id = ?', [$customerId]);

$cart     = cart_lines();
$addresses = db_all(
    'SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC',
    [$customerId]
);

$firstName = explode(' ', current_user_name())[0];

$page_title  = 'Dashboard';
$page_active = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <div class="fc-hero mb-4">
    <div>
      <span class="fc-badge badge-soft-success mb-2"><i class="bi bi-sun"></i> <?= h(date('l, d M Y')) ?></span>
      <h1 style="font-size:clamp(1.5rem,3vw,2.1rem);font-weight:800;color:#fff;margin:0">
        Hello, <?= h($firstName) ?>
      </h1>
      <p style="color:rgba(255,255,255,.8);margin:.35rem 0 0;font-size:.95rem">
        <?= count($activeOrders) > 0
            ? 'You have ' . count($activeOrders) . ' order(s) on the way. Here is the latest update.'
            : 'No active deliveries right now. Ready to fill your basket?' ?>
      </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-white" href="products.php"><i class="bi bi-bag"></i> Shop now</a>
      <a class="btn btn-ghost btn-sm" style="background:rgba(255,255,255,.14);color:#fff;border-color:rgba(255,255,255,.28)"
         href="orders.php">View orders</a>
    </div>
  </div>

  <div class="fc-stat-grid mb-4">
    <div class="fc-card fc-stat">
      <span class="fc-stat-icon bg-soft-primary"><i class="bi bi-receipt"></i></span>
      <div>
        <span class="fc-stat-label">Total orders</span>
        <span class="fc-stat-value" data-count="<?= (int) $stats['orders'] ?>"><?= number_format((int) $stats['orders']) ?></span>
      </div>
    </div>
    <div class="fc-card fc-stat">
      <span class="fc-stat-icon bg-soft-success"><i class="bi bi-wallet2"></i></span>
      <div>
        <span class="fc-stat-label">Total spent</span>
        <span class="fc-stat-value"><?= money($stats['spent']) ?></span>
      </div>
    </div>
    <div class="fc-card fc-stat">
      <span class="fc-stat-icon bg-soft-warning"><i class="bi bi-tag"></i></span>
      <div>
        <span class="fc-stat-label">Total saved</span>
        <span class="fc-stat-value"><?= money($stats['saved']) ?></span>
      </div>
    </div>
    <div class="fc-card fc-stat">
      <span class="fc-stat-icon bg-soft-danger"><i class="bi bi-heart"></i></span>
      <div>
        <span class="fc-stat-label">Wishlist items</span>
        <span class="fc-stat-value"><?= $wishTotal ?></span>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <?php if ($activeOrders): ?>
        <div class="fc-card mb-4">
          <div class="fc-card-head">
            <h3><i class="bi bi-truck"></i> Active deliveries</h3>
            <a class="btn btn-ghost btn-sm" href="orders.php">All orders</a>
          </div>
          <div class="fc-card-body d-flex flex-column gap-3">
            <?php foreach ($activeOrders as $order):
                $stepIndex = array_search($order['status'], ORDER_FLOW, true);
                $pct = (int) round((($stepIndex === false ? 0 : $stepIndex) + 1) / count(ORDER_FLOW) * 100); ?>
              <div class="fc-card p-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                  <div>
                    <strong style="color:var(--fc-ink)"><?= h((string) $order['order_code']) ?></strong>
                    <small class="text-muted ms-2"><?= h(nice_date((string) $order['order_date'])) ?></small>
                  </div>
                  <?= status_badge((string) $order['status']) ?>
                </div>
                <div class="d-flex align-items-center gap-2 mb-2">
                  <div class="flex-grow-1" style="height:6px;background:var(--fc-line-soft);border-radius:999px;overflow:hidden">
                    <div style="height:100%;width:<?= max($pct, 4) ?>%;background:var(--fc-green)"></div>
                  </div>
                  <small class="text-muted" style="font-size:.78rem"><?= $pct ?>%</small>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                  <small class="text-muted fc-truncate" style="max-width:60%"><?= h((string) $order['address_snapshot']) ?></small>
                  <a class="btn btn-ghost btn-sm" href="order_details.php?id=<?= (int) $order['id'] ?>">Track</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="fc-card mb-4">
        <div class="fc-card-head">
          <h3><i class="bi bi-clock-history"></i> Recent orders</h3>
          <a class="btn btn-ghost btn-sm" href="orders.php">View all</a>
        </div>

        <?php if (!$recentOrders): ?>
          <div class="fc-card-body text-center py-5">
            <div class="fc-empty-icon" style="width:70px;height:70px;font-size:1.8rem;margin:0 auto 1rem">
              <i class="bi bi-bag"></i>
            </div>
            <h4 style="font-size:1.1rem;color:var(--fc-ink)">No orders yet</h4>
            <p class="text-muted" style="font-size:.9rem">Your order history will show up here.</p>
            <a class="btn btn-green" href="products.php">Start shopping</a>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table fc-table mb-0 align-middle">
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th class="text-end">Total</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentOrders as $order): ?>
                  <tr>
                    <td>
                      <strong style="font-size:.88rem"><?= h((string) $order['order_code']) ?></strong>
                      <small class="d-block text-muted" style="font-size:.76rem"><?= h((string) $order['payment_method']) ?></small>
                    </td>
                    <td class="text-muted" style="font-size:.85rem"><?= h(nice_date((string) $order['order_date'])) ?></td>
                    <td><?= status_badge((string) $order['status']) ?></td>
                    <td class="text-end" style="font-weight:600"><?= money($order['total_amount']) ?></td>
                    <td class="text-end">
                      <a class="btn btn-ghost btn-sm" href="order_details.php?id=<?= (int) $order['id'] ?>">
                        <i class="bi bi-chevron-right"></i>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($wishlist): ?>
        <div class="fc-card">
          <div class="fc-card-head">
            <h3><i class="bi bi-heart"></i> From your wishlist</h3>
            <a class="btn btn-ghost btn-sm" href="wishlist.php">View all</a>
          </div>
          <div class="fc-grid fc-grid-4 p-3">
            <?php foreach ($wishlist as $item):
                include __DIR__ . '/includes/product_card.php'; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-4">
      <div class="fc-card mb-4">
        <div class="fc-card-head"><h3><i class="bi bi-person"></i> Your details</h3></div>
        <div class="fc-card-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <span class="fc-avatar" style="width:48px;height:48px;font-size:1.15rem">
              <?= h(strtoupper(substr((string) ($customer['name'] ?? current_user_name()), 0, 1))) ?>
            </span>
            <div style="min-width:0">
              <strong class="fc-truncate d-block" style="color:var(--fc-ink)"><?= h((string) ($customer['name'] ?? current_user_name())) ?></strong>
              <small class="text-muted fc-truncate d-block"><?= h((string) ($customer['email'] ?? current_user_email())) ?></small>
            </div>
          </div>
          <a class="btn btn-ghost btn-sm btn-block" href="account.php">
            <i class="bi bi-pencil"></i> Edit profile
          </a>
        </div>
      </div>

      <div class="fc-card mb-4">
        <div class="fc-card-head">
          <h3><i class="bi bi-cart3"></i> Your cart</h3>
          <a class="btn btn-ghost btn-sm" href="cart.php">View</a>
        </div>
        <div class="fc-card-body text-center">
          <div style="font-size:2.4rem;font-weight:800;color:var(--fc-ink);line-height:1">
            <?= (int) $cart['count'] ?>
          </div>
          <small class="text-muted">item<?= $cart['count'] === 1 ? '' : 's' ?> · <?= money($cart['subtotal']) ?></small>
          <?php if ($cart['items']): ?>
            <a class="btn btn-green btn-sm btn-block mt-3" href="checkout.php">
              <i class="bi bi-bag-check"></i> Checkout
            </a>
          <?php else: ?>
            <a class="btn btn-ghost btn-sm btn-block mt-3" href="products.php">Fill my cart</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="fc-card mb-4">
        <div class="fc-card-head">
          <h3><i class="bi bi-geo-alt"></i> Addresses</h3>
          <a class="btn btn-ghost btn-sm" href="addresses.php">Manage</a>
        </div>
        <div class="fc-card-body">
          <?php if (!$addresses): ?>
            <p class="text-muted mb-3" style="font-size:.88rem">
              No saved addresses. Add one so checkout takes seconds.
            </p>
          <?php else: ?>
            <div class="d-flex flex-column gap-2">
              <?php foreach (array_slice($addresses, 0, 2) as $addr): ?>
                <div class="fc-card p-3">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <b style="font-size:.86rem"><?= h((string) $addr['label']) ?></b>
                    <?php if ((int) $addr['is_default'] === 1): ?>
                      <span class="fc-badge badge-soft-success" style="font-size:.62rem">Default</span>
                    <?php endif; ?>
                  </div>
                  <small class="text-muted d-block" style="font-size:.8rem;white-space:pre-line"><?= h(format_address($addr)) ?></small>
                </div>
              <?php endforeach; ?>
              <?php if (count($addresses) > 2): ?>
                <small class="text-muted">+<?= count($addresses) - 2 ?> more</small>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="fc-card">
        <div class="fc-card-head"><h3><i class="bi bi-megaphone"></i> Offers</h3></div>
        <div class="fc-card-body">
          <p class="text-muted mb-2" style="font-size:.88rem">Try these codes at checkout.</p>
          <?php
          $offers = db_all(
              'SELECT code FROM coupons
                WHERE is_active = 1
                  AND (starts_at IS NULL OR starts_at <= NOW())
                  AND (expires_at IS NULL OR expires_at >= NOW())
                ORDER BY id LIMIT 3'
          );
          ?>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($offers as $offer): ?>
              <span class="fc-coupon-code"><?= h((string) $offer['code']) ?></span>
            <?php endforeach; ?>
            <?php if (!$offers): ?>
              <span class="text-muted" style="font-size:.85rem">No active coupons right now.</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>