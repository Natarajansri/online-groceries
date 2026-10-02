<?php
/**
 * Checkout: choose a delivery address and payment method.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$cart = cart_lines();
if (!$cart['items']) {
    flash('info', 'Your cart is empty.');
    redirect('cart.php');
}

$discount = 0.0;
$coupon   = (string) ($_SESSION['coupon_code'] ?? '');
$applied  = null;
if ($coupon !== '') {
    $check = evaluate_coupon($coupon, $cart['subtotal']);
    if ($check['ok']) {
        $applied  = $check['coupon'];
        $discount = $check['discount'];
    } else {
        unset($_SESSION['coupon_code']);
        $coupon = '';
    }
}
$totals = order_totals($cart['items'], $discount);

$addresses = db_all(
    'SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC',
    [current_user_id()]
);

$profile = db_one(
    'SELECT name, email, phone, address FROM customers WHERE id = ?',
    [current_user_id()]
) ?? [];

// Pick a default: the flagged default, else the first saved, else build one from the profile.
$defaultAddressId = 0;
foreach ($addresses as $addr) {
    if ((int) $addr['is_default'] === 1) {
        $defaultAddressId = (int) $addr['id'];
        break;
    }
}
if ($defaultAddressId === 0 && $addresses) {
    $defaultAddressId = (int) $addresses[0]['id'];
}

/** Fallback virtual address built from the customer's profile row. */
$profileAddress = [
    'id'         => 0,
    'label'      => 'Profile address',
    'full_name'  => $profile['name'] ?? '',
    'phone'      => $profile['phone'] ?? '',
    'line1'      => $profile['address'] ?? '',
    'line2'      => '',
    'city'       => 'Bengaluru',
    'state_name' => 'Karnataka',
    'pincode'    => '560001',
    'is_default' => 0,
];

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    remember_old($_POST);

    $addressId = (int) ($_POST['address_id'] ?? 0);
    $payment   = (string) ($_POST['payment_method'] ?? 'COD');
    $note      = trim((string) ($_POST['note'] ?? ''));

    $selected = null;
    if ($addressId === 0) {
        $selected = $profileAddress;
        if (($profile['name'] ?? '') === '' || ($profile['phone'] ?? '') === '' || ($profile['address'] ?? '') === '') {
            $selected = null;
            $error    = 'Add your name, phone and address to your profile before ordering.';
        }
    } else {
        $selected = db_one(
            'SELECT * FROM addresses WHERE id = ? AND customer_id = ?',
            [$addressId, current_user_id()]
        );
        if ($selected === null) {
            $error = 'That delivery address could not be found. Please pick another.';
        }
    }

    if (!in_array($payment, ['COD', 'UPI', 'Card'], true)) {
        $payment = 'COD';
    }

    if (strlen($note) > 255) {
        $note = substr($note, 0, 255);
    }

    if ($error === '' && $selected !== null) {
        $orderId = 0;
        try {
            $orderId = place_order_from_checkout([
                'address_id'      => $addressId,
                'address_snapshot' => format_address($selected),
                'payment_method'  => $payment,
                'note'            => $note,
                'coupon_code'     => $applied['code'] ?? '',
            ]);
        } catch (Throwable $e) {
            flash('danger', $e->getMessage());
            redirect('checkout.php');
        }
        clear_old();
        redirect('order_success.php?id=' . $orderId);
    }

    clear_old();
}

$page_title  = 'Checkout';
$page_active = 'cart';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i>
    <a href="cart.php">Cart</a> <i class="bi bi-chevron-right"></i> <span>Checkout</span>
  </nav>

  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-credit-card"></i> Step 2 of 3</span>
      <h1 class="fc-section-title">Confirm &amp; pay</h1>
      <p class="fc-section-sub">Choose where we deliver and how you would like to pay.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="cart.php"><i class="bi bi-arrow-left"></i> Edit cart</a>
  </div>

  <?php if ($error !== ''): ?>
    <div class="fc-alert fc-alert-danger mb-4">
      <i class="bi bi-exclamation-triangle"></i><div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <form method="post" action="checkout.php">
    <?= csrf_field() ?>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="fc-card mb-4">
          <div class="fc-card-head">
            <h3><i class="bi bi-geo-alt"></i> Delivery address</h3>
            <a class="btn btn-ghost btn-sm" href="addresses.php">
              <i class="bi bi-plus-lg"></i> Add address
            </a>
          </div>

          <div class="fc-card-body">
            <?php if ($addresses): ?>
              <div class="d-flex flex-column gap-2 mb-4">
                <?php foreach ($addresses as $addr):
                    $checked = (int) $addr['id'] === $defaultAddressId; ?>
                  <label class="fc-card p-3 d-flex gap-3 align-items-start"
                         style="cursor:pointer;border-color:<?= $checked ? 'var(--fc-green)' : 'var(--fc-line)' ?>">
                    <input class="form-check-input mt-1 flex-shrink-0" type="radio" name="address_id"
                           value="<?= (int) $addr['id'] ?>" <?= $checked ? 'checked' : '' ?>
                           style="width:1.05rem;height:1.05rem">
                    <span class="flex-grow-1">
                      <span class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <b style="font-size:.92rem"><?= h((string) $addr['label']) ?></b>
                        <?php if ((int) $addr['is_default'] === 1): ?>
                          <span class="fc-badge badge-soft-success" style="font-size:.65rem">Default</span>
                        <?php endif; ?>
                      </span>
                      <span class="d-block" style="font-size:.87rem"><?= h((string) $addr['full_name']) ?></span>
                      <span class="d-block text-muted" style="font-size:.83rem">
                        <?= h(format_address($addr)) ?>
                      </span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <label class="fc-card p-3 d-flex gap-3 align-items-start"
                   style="cursor:pointer;border-color:<?= $addresses ? 'var(--fc-line)' : 'var(--fc-green)' ?>">
              <input class="form-check-input mt-1 flex-shrink-0" type="radio" name="address_id" value="0"
                     <?= $addresses ? '' : 'checked' ?> style="width:1.05rem;height:1.05rem">
              <span class="flex-grow-1">
                <b style="font-size:.92rem">Use my profile address</b>
                <span class="d-block text-muted" style="font-size:.83rem"><?= h((string) format_address($profileAddress)) ?></span>
                <?php if (($profile['address'] ?? '') === ''): ?>
                  <span class="d-block mt-1" style="font-size:.8rem;color:#b91c1c">
                    <i class="bi bi-exclamation-triangle"></i> Your profile address is incomplete.
                  </span>
                <?php endif; ?>
              </span>
            </label>
          </div>
        </div>

        <div class="fc-card mb-4">
          <div class="fc-card-head"><h3><i class="bi bi-cash-stack"></i> Payment method</h3></div>
          <div class="fc-card-body">
            <div class="d-flex flex-column gap-2">
              <?php
              $methods = [
                  ['COD',  'Cash on delivery', 'Pay the delivery agent in cash or by UPI',            'cash-stack', 'badge-soft-success'],
                  ['UPI',  'UPI',               'Approve the collect request on your phone',          'phone',      'badge-soft-info'],
                  ['Card', 'Card on delivery',  'Agent carries a card terminal for swipe or tap',      'credit-card','badge-soft-purple'],
              ];
              $selectedPay = old('payment_method', 'COD');
              foreach ($methods as $i => [$code, $label, $help, $icon, $badge]):
                  $checked = $selectedPay === $code || ($i === 0 && $selectedPay === ''); ?>
                <label class="fc-card p-3 d-flex gap-3 align-items-center"
                       style="cursor:pointer;border-color:<?= $checked ? 'var(--fc-green)' : 'var(--fc-line)' ?>">
                  <input class="form-check-input flex-shrink-0" type="radio" name="payment_method"
                         value="<?= h($code) ?>" <?= $checked ? 'checked' : '' ?>
                         style="width:1.05rem;height:1.05rem">
                  <span class="badge <?= h($badge) ?>"><i class="bi bi-<?= h($icon) ?>"></i></span>
                  <span class="flex-grow-1">
                    <b style="font-size:.9rem"><?= h($label) ?></b>
                    <span class="d-block text-muted" style="font-size:.82rem"><?= h($help) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>

            <div class="fc-field mt-4">
              <label class="fc-label" for="note">Delivery instructions (optional)</label>
              <textarea class="fc-textarea" id="note" name="note" maxlength="255"
                        style="min-height:72px"
                        placeholder="Gate code, preferred delivery time, leave with the concierge&hellip;"><?= old('note') ?></textarea>
              <p class="fc-hint mb-0">Up to 255 characters.</p>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="fc-card fc-sticky-side">
          <div class="fc-card-head"><h3><i class="bi bi-receipt-cutoff"></i> Your order</h3></div>

          <div class="fc-card-body" style="max-height:340px;overflow:auto">
            <?php foreach ($cart['items'] as $line): ?>
              <div class="d-flex gap-3 py-2 border-bottom">
                <span class="fc-product-media" style="width:52px;flex:0 0 52px;border-radius:9px">
                  <img src="<?= product_image($line['image']) ?>" alt="" loading="lazy" decoding="async" width="52" height="52"<?= product_img_responsive($line['image'], '52px') ?>
                       style="width:100%;height:100%;object-fit:cover">
                </span>
                <span class="flex-grow-1" style="min-width:0">
                  <span class="d-block fc-truncate" style="font-size:.87rem;color:var(--fc-ink)">
                    <?= h((string) $line['name']) ?>
                  </span>
                  <span class="text-muted" style="font-size:.79rem">
                    <?= (int) $line['qty'] ?> &times; <?= money($line['price']) ?>
                  </span>
                </span>
                <span style="font-size:.87rem;color:var(--fc-ink);white-space:nowrap">
                  <?= money($line['subtotal']) ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="fc-card-body border-top">
            <div class="d-flex flex-column gap-2" style="font-size:.9rem">
              <div class="d-flex justify-content-between">
                <span class="text-muted">Subtotal</span><span><?= money($totals['subtotal']) ?></span>
              </div>
              <?php if ($discount > 0): ?>
                <div class="d-flex justify-content-between text-green">
                  <span>Coupon <b><?= h((string) $applied['code']) ?></b></span>
                  <span>&minus; <?= money($discount) ?></span>
                </div>
              <?php endif; ?>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Delivery</span>
                <?php if ($totals['delivery_fee'] > 0): ?>
                  <span><?= money($totals['delivery_fee']) ?></span>
                <?php else: ?>
                  <span class="text-green">FREE</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="fc-divider"></div>

            <div class="d-flex justify-content-between align-items-baseline mb-3">
              <strong style="color:var(--fc-ink)">Payable</strong>
              <span style="font-size:1.6rem;font-weight:800;color:var(--fc-ink)"><?= money($totals['total']) ?></span>
            </div>

            <button class="btn btn-green btn-lg btn-block" type="submit">
              <i class="bi bi-bag-check"></i> Place order
            </button>

            <p class="fc-hint text-center mb-0">
              <i class="bi bi-lock"></i> Stock is re-checked and locked while your order is placed.
            </p>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<?php
clear_old();
require __DIR__ . '/includes/footer.php';
?>