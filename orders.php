<?php
/**
 * Customer order history with status filter, search and pagination.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$customerId = current_user_id();
$status     = trim((string) ($_GET['status'] ?? ''));
$search     = trim((string) ($_GET['q'] ?? ''));
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 10;

$validStatuses = array_keys(STATUS_META);

$where  = ['o.customer_id = ?'];
$params = [$customerId];

if ($status !== '' && in_array($status, $validStatuses, true)) {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(o.order_code LIKE ? OR o.address_snapshot LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total     = (int) db_value("SELECT COUNT(*) FROM orders o $whereSql", $params);
$totalPages = max(1, (int) ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$orders = db_all(
    "SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
       FROM orders o
       $whereSql
      ORDER BY o.order_date DESC, o.id DESC
      LIMIT $perPage OFFSET $offset",
    $params
);

// Counts for the filter tabs.
$counts = [];
foreach (db_all(
    'SELECT status, COUNT(*) AS total FROM orders WHERE customer_id = ? GROUP BY status',
    [$customerId]
) as $row) {
    $counts[(string) $row['status']] = (int) $row['total'];
}
$allCount = array_sum($counts);

$summary = db_one(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(total_amount),0) AS spent,
            COALESCE(SUM(discount),0) AS saved
       FROM orders WHERE customer_id = ? AND status <> 'Cancelled'",
    [$customerId]
) ?? ['orders' => 0, 'spent' => 0, 'saved' => 0];

$page_title  = 'My orders';
$page_active = 'orders';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i> <span>My orders</span>
  </nav>

  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-receipt"></i> Order history</span>
      <h1 class="fc-section-title">My orders</h1>
      <p class="fc-section-sub">
        <?= number_format($allCount) ?> order<?= $allCount === 1 ? '' : 's' ?> placed ·
        <?= money($summary['spent']) ?> spent<?= (float) $summary['saved'] > 0 ? ' · ' . money($summary['saved']) . ' saved' : '' ?>
      </p>
    </div>
    <a class="btn btn-green btn-sm" href="products.php"><i class="bi bi-bag"></i> Shop again</a>
  </div>

  <div class="fc-chip-row mb-3">
    <a class="fc-chip<?= $status === '' ? ' active' : '' ?>" href="<?= h(url_with('orders.php', ['status' => null, 'page' => null])) ?>">
      All <span class="opacity-75"><?= $allCount ?></span>
    </a>
    <?php foreach ($validStatuses as $code): ?>
      <?php $n = $counts[$code] ?? 0; if ($n === 0) { continue; } ?>
      <a class="fc-chip<?= $status === $code ? ' active' : '' ?>" href="<?= h(url_with('orders.php', ['status' => $code, 'page' => null])) ?>">
        <?= h($code) ?> <span class="opacity-75"><?= $n ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="orders.php" class="d-flex gap-2 mb-4" style="max-width:520px">
    <?php if ($status !== ''): ?>
      <input type="hidden" name="status" value="<?= h($status) ?>">
    <?php endif; ?>
    <div class="fc-search flex-grow-1" style="max-width:none">
      <i class="bi bi-search fc-search-icon"></i>
      <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search by order code or address">
      <button class="fc-search-go" type="submit" aria-label="Search"><i class="bi bi-arrow-right"></i></button>
    </div>
    <?php if ($search !== '' || $status !== ''): ?>
      <a class="btn btn-ghost btn-sm" href="orders.php">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (!$orders): ?>
    <div class="fc-card fc-empty">
      <div class="fc-empty-icon"><i class="bi bi-receipt"></i></div>
      <h3><?= $search !== '' || $status !== '' ? 'No matching orders' : 'No orders yet' ?></h3>
      <p>
        <?= $search !== '' || $status !== ''
            ? 'Try a different search term or clear the status filter.'
            : 'When you place an order it will appear here with live tracking.' ?>
      </p>
      <a class="btn btn-green" href="products.php"><i class="bi bi-bag"></i> Start shopping</a>
    </div>
  <?php else: ?>
    <div class="d-flex flex-column gap-3">
      <?php foreach ($orders as $order):
          $canCancel = in_array($order['status'], ['Placed', 'Confirmed'], true);
          $isOpen    = !in_array($order['status'], ['Delivered', 'Cancelled'], true); ?>
        <article class="fc-card">
          <div class="fc-card-head">
            <div class="d-flex flex-wrap align-items-center gap-3">
              <div>
                <strong style="color:var(--fc-ink)"><?= h((string) $order['order_code']) ?></strong>
                <small class="text-muted d-block"><?= h(nice_date((string) $order['order_date'])) ?></small>
              </div>
              <?= status_badge((string) $order['status']) ?>
              <span class="text-muted" style="font-size:.82rem">
                <i class="bi bi-box"></i> <?= (int) $order['item_count'] ?> item(s) &middot;
                <i class="bi bi-wallet2"></i> <?= h((string) $order['payment_method']) ?>
              </span>
            </div>

            <div class="d-flex align-items-center gap-3">
              <div class="text-end">
                <strong style="font-size:1.15rem;color:var(--fc-ink)"><?= money($order['total_amount']) ?></strong>
                <?php if ((float) $order['discount'] > 0): ?>
                  <small class="d-block text-green" style="font-size:.75rem">saved <?= money($order['discount']) ?></small>
                <?php endif; ?>
              </div>
              <a class="btn btn-ghost btn-sm" href="order_details.php?id=<?= (int) $order['id'] ?>">Details</a>
              <?php if ($canCancel): ?>
                <form method="post" action="order_cancel.php" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <button class="btn btn-danger btn-sm" data-confirm="Cancel this order? This cannot be undone.">
                    Cancel
                  </button>
                </form>
              <?php elseif ($isOpen): ?>
                <span class="text-muted" style="font-size:.78rem"><i class="bi bi-hourglass-split"></i> In progress</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="fc-card-body py-3">
            <div class="d-flex align-items-center gap-2 mb-2" style="font-size:.82rem">
              <span class="text-muted">Progress</span>
              <div class="flex-grow-1" style="height:6px;background:var(--fc-line-soft);border-radius:999px;overflow:hidden">
                <?php
                $stepIndex  = array_search($order['status'], ORDER_FLOW, true);
                $pct = $order['status'] === 'Delivered' ? 100
                     : ($order['status'] === 'Cancelled' ? 0
                     : (int) round((($stepIndex === false ? 0 : $stepIndex) + 1) / count(ORDER_FLOW) * 100));
                ?>
                <div style="height:100%;width:<?= max($pct, 4) ?>%;background:<?= $order['status'] === 'Cancelled' ? 'var(--fc-rose)' : 'var(--fc-green)' ?>"></div>
              </div>
              <span class="text-muted" style="white-space:nowrap"><?= $pct ?>%</span>
            </div>
            <small class="text-muted d-block fc-truncate"><?= h((string) $order['address_snapshot']) ?></small>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <?= pagination('orders.php', $page, $totalPages) ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>