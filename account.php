<?php
/**
 * Profile management: details, password change and account overview.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$customerId = current_user_id();
$customer   = db_one('SELECT * FROM customers WHERE id = ?', [$customerId]);

if ($customer === null) {
    logout_user();
    flash('warning', 'Your account could not be found. Please sign in again.');
    redirect('login.php');
}

$errors  = [];
$section = (string) ($_GET['section'] ?? 'profile');
if (!in_array($section, ['profile', 'password', 'danger'], true)) {
    $section = 'profile';
}

$stats = db_one(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN total_amount END), 0) AS spent,
            MAX(order_date) AS last_order
       FROM orders WHERE customer_id = ?",
    [$customerId]
) ?? ['orders' => 0, 'spent' => 0, 'last_order' => null];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    /* ---------------------------------------------------- profile ---- */
    if ($action === 'profile') {
        $name    = trim((string) ($_POST['name'] ?? ''));
        $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone   = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors[] = 'Name must be between 2 and 80 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
            $errors[] = 'Enter a valid email address.';
        } elseif (db_value('SELECT 1 FROM customers WHERE email = ? AND id <> ?', [$email, $customerId])) {
            $errors[] = 'That email is already used by another account.';
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,18}$/', $phone)) {
            $errors[] = 'Enter a valid phone number.';
        }

        if (!$errors) {
            db_run(
                'UPDATE customers SET name = ?, email = ?, phone = ?, address = ? WHERE id = ?',
                [
                    $name,
                    $email,
                    $phone !== '' ? $phone : null,
                    $address !== '' ? mb_substr($address, 0, 255) : null,
                    $customerId,
                ]
            );
            $_SESSION['customer_name']  = $name;
            $_SESSION['customer_email'] = $email;
            flash('success', 'Profile updated.');
            redirect('account.php');
        }
        $section = 'profile';
        $customer = array_merge($customer, ['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address]);
    }

    /* --------------------------------------------------- password ---- */
    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $next    = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!password_verify($current, (string) $customer['password'])) {
            $errors[] = 'Your current password is not correct.';
        }
        if (mb_strlen($next) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        } elseif (!preg_match('/[A-Za-z]/', $next) || !preg_match('/[0-9]/', $next)) {
            $errors[] = 'New password must contain both letters and numbers.';
        } elseif ($next !== $confirm) {
            $errors[] = 'The two new passwords do not match.';
        } elseif ($current === $next) {
            $errors[] = 'Choose a password different from your current one.';
        }

        if (!$errors) {
            db_run('UPDATE customers SET password = ? WHERE id = ?',
                [password_hash($next, PASSWORD_DEFAULT), $customerId]);
            session_regenerate_id(true);
            flash('success', 'Password changed successfully.');
            redirect('account.php');
        }
        $section = 'password';
    }

    /* ------------------------------------------------ deactivate ---- */
    if ($action === 'deactivate') {
        $confirmText = (string) ($_POST['confirm_text'] ?? '');
        if (mb_strtoupper($confirmText) !== 'DELETE') {
            $errors[] = 'Type DELETE to confirm.';
        } else {
            $open = (int) db_value(
                "SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status NOT IN ('Delivered','Cancelled')",
                [$customerId]
            );
            if ($open > 0) {
                $errors[] = 'You still have ' . $open . ' order(s) in progress. Wait for them to finish first.';
            } else {
                db_run('UPDATE customers SET is_active = 0 WHERE id = ?', [$customerId]);
                logout_user();
                flash('info', 'Your account has been deactivated. Contact support to reactivate it.');
                redirect('index.php');
            }
        }
        $section = 'danger';
    }
}

$recent = db_all(
    'SELECT id, order_code, total_amount, status, order_date FROM orders
      WHERE customer_id = ? ORDER BY order_date DESC LIMIT 3',
    [$customerId]
);

$addressCount = (int) db_value('SELECT COUNT(*) FROM addresses WHERE customer_id = ?', [$customerId]);

$page_title  = 'My profile';
$page_active = 'account';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i>
    <a href="dashboard.php">Dashboard</a> <i class="bi bi-chevron-right"></i> <span>Profile</span>
  </nav>

  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-person-gear"></i> Account</span>
      <h1 class="fc-section-title">My profile</h1>
      <p class="fc-section-sub">Keep your contact details current so deliveries reach you.</p>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="fc-alert fc-alert-danger mb-4">
      <i class="bi bi-exclamation-triangle"></i>
      <div><?php foreach ($errors as $message): ?><div><?= h($message) ?></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="fc-card mb-4">
        <div class="fc-card-body text-center">
          <span class="fc-avatar" style="width:74px;height:74px;font-size:1.75rem;margin:0 auto .75rem">
            <?= h(strtoupper(substr((string) $customer['name'], 0, 1))) ?>
          </span>
          <h3 style="font-size:1.15rem;color:var(--fc-ink);margin:0"><?= h((string) $customer['name']) ?></h3>
          <small class="text-muted d-block mb-3"><?= h((string) $customer['email']) ?></small>
          <div class="d-flex flex-column gap-2 text-start">
            <div class="d-flex justify-content-between">
              <small class="text-muted">Member since</small>
              <small style="font-weight:600"><?= h(date('M Y', strtotime((string) $customer['created_at']))) ?></small>
            </div>
            <div class="d-flex justify-content-between">
              <small class="text-muted">Orders</small>
              <small style="font-weight:600"><?= number_format((int) $stats['orders']) ?></small>
            </div>
            <div class="d-flex justify-content-between">
              <small class="text-muted">Lifetime value</small>
              <small style="font-weight:600"><?= money($stats['spent']) ?></small>
            </div>
            <div class="d-flex justify-content-between">
              <small class="text-muted">Saved addresses</small>
              <small style="font-weight:600"><?= $addressCount ?></small>
            </div>
            <div class="d-flex justify-content-between">
              <small class="text-muted">Last order</small>
              <small style="font-weight:600"><?= $stats['last_order'] ? h(nice_date((string) $stats['last_order'])) : '—' ?></small>
            </div>
          </div>
        </div>
      </div>

      <?php if ($recent): ?>
        <div class="fc-card mb-4">
          <div class="fc-card-head"><h3><i class="bi bi-clock-history"></i> Recent orders</h3></div>
          <div class="fc-card-body d-flex flex-column gap-2">
            <?php foreach ($recent as $order): ?>
              <a class="fc-card p-3 d-flex align-items-center justify-content-between text-decoration-none"
                 href="order_details.php?id=<?= (int) $order['id'] ?>">
                <span>
                  <strong style="font-size:.85rem;color:var(--fc-ink)"><?= h((string) $order['order_code']) ?></strong>
                  <small class="d-block text-muted" style="font-size:.76rem"><?= h(nice_date((string) $order['order_date'])) ?></small>
                </span>
                <span class="text-end">
                  <?= money($order['total_amount']) ?><br>
                  <?= status_badge((string) $order['status']) ?>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="fc-card">
        <div class="fc-card-body d-flex flex-column gap-2">
          <a class="btn btn-ghost btn-block" href="addresses.php"><i class="bi bi-geo-alt"></i> Address book</a>
          <a class="btn btn-ghost btn-block" href="wishlist.php"><i class="bi bi-heart"></i> Wishlist</a>
          <a class="btn btn-ghost btn-block" href="orders.php"><i class="bi bi-receipt"></i> Order history</a>
          <?php if (is_admin()): ?>
            <a class="btn btn-ghost btn-block" href="admin/index.php"><i class="bi bi-shield-lock"></i> Admin panel</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="fc-card">
        <div class="fc-tabs px-3 pt-2" data-tabs>
          <button class="fc-tab<?= $section === 'profile' ? ' active' : '' ?>" type="button" data-tab="profile"
                  onclick="location.href='account.php?section=profile'">
            <i class="bi bi-person"></i> Profile
          </button>
          <button class="fc-tab<?= $section === 'password' ? ' active' : '' ?>" type="button" data-tab="password"
                  onclick="location.href='account.php?section=password'">
            <i class="bi bi-key"></i> Password
          </button>
          <button class="fc-tab<?= $section === 'danger' ? ' active' : '' ?>" type="button" data-tab="danger"
                  onclick="location.href='account.php?section=danger'">
            <i class="bi bi-sliders"></i> Account
          </button>
        </div>

        <div class="fc-card-body">
          <?php if ($section === 'profile'): ?>
            <h3 style="font-size:1.05rem;color:var(--fc-ink)">Personal details</h3>
            <p class="text-muted" style="font-size:.88rem">Used on your orders and for delivery updates.</p>

            <form method="post" action="account.php?section=profile">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="profile">

              <div class="row g-3">
                <div class="col-sm-6">
                  <div class="fc-field">
                    <label class="fc-label" for="name">Full name</label>
                    <input class="fc-input" type="text" id="name" name="name" required maxlength="80"
                           value="<?= h((string) $customer['name']) ?>">
                  </div>
                </div>
                <div class="col-sm-6">
                  <div class="fc-field">
                    <label class="fc-label" for="email">Email address</label>
                    <input class="fc-input" type="email" id="email" name="email" required maxlength="120"
                           value="<?= h((string) $customer['email']) ?>">
                  </div>
                </div>
                <div class="col-sm-6">
                  <div class="fc-field">
                    <label class="fc-label" for="phone">Phone</label>
                    <input class="fc-input" type="tel" id="phone" name="phone" maxlength="18"
                           value="<?= h((string) ($customer['phone'] ?? '')) ?>">
                  </div>
                </div>
                <div class="col-12">
                  <div class="fc-field">
                    <label class="fc-label" for="address">Default address (used at checkout)</label>
                    <textarea class="fc-textarea" id="address" name="address" maxlength="255" style="min-height:78px"><?= h((string) ($customer['address'] ?? '')) ?></textarea>
                    <p class="fc-hint mb-0">
                      For precise delivery add a full address in the
                      <a href="addresses.php">address book</a>.
                    </p>
                  </div>
                </div>
              </div>

              <button class="btn btn-green" type="submit"><i class="bi bi-check2"></i> Save changes</button>
            </form>

          <?php elseif ($section === 'password'): ?>
            <h3 style="font-size:1.05rem;color:var(--fc-ink)">Change password</h3>
            <p class="text-muted" style="font-size:.88rem">Use at least 8 characters with letters and numbers.</p>

            <form method="post" action="account.php?section=password" style="max-width:460px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="password">

              <div class="fc-field">
                <label class="fc-label" for="current_password">Current password</label>
                <input class="fc-input" type="password" id="current_password" name="current_password"
                       required autocomplete="current-password">
              </div>
              <div class="fc-field">
                <label class="fc-label" for="new_password">New password</label>
                <input class="fc-input" type="password" id="new_password" name="new_password"
                       required autocomplete="new-password">
              </div>
              <div class="fc-field">
                <label class="fc-label" for="new_password_confirm">Confirm new password</label>
                <input class="fc-input" type="password" id="new_password_confirm" name="new_password_confirm"
                       required autocomplete="new-password">
              </div>

              <button class="btn btn-green" type="submit"><i class="bi bi-shield-lock"></i> Update password</button>
            </form>

          <?php else: ?>
            <h3 style="font-size:1.05rem;color:var(--fc-ink)">Account status</h3>
            <p class="text-muted" style="font-size:.88rem">
              Your account is <span class="badge badge-soft-success">Active</span>. Orders and addresses stay saved
              for your next visit.
            </p>

            <div class="fc-alert fc-alert-warning mb-4">
              <i class="bi bi-exclamation-triangle"></i>
              <div>
                <strong>Deactivate this account</strong> to stop ordering. You will not be able to sign in until an
                administrator reactivates it. Wait for any in-progress orders to finish first.
              </div>
            </div>

            <form method="post" action="account.php?section=danger" style="max-width:460px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="deactivate">

              <div class="fc-field">
                <label class="fc-label" for="confirm_text">Type <code>DELETE</code> to confirm</label>
                <input class="fc-input" type="text" id="confirm_text" name="confirm_text"
                       placeholder="DELETE" autocomplete="off">
              </div>

              <button class="btn btn-danger" type="submit" data-confirm="Deactivate your account?">
                <i class="bi bi-power"></i> Deactivate account
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>