<?php
/**
 * Create a customer account.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$name   = '';
$email  = '';
$phone  = '';
$address = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $name    = trim((string) ($_POST['name'] ?? ''));
    $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone   = trim((string) ($_POST['phone'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        $errors[] = 'Enter your full name (2 to 80 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,18}$/', $phone)) {
        $errors[] = 'Enter a valid phone number.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain both letters and numbers.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $taken = db_value('SELECT 1 FROM customers WHERE email = ?', [$email]);
        if ($taken) {
            $errors[] = 'An account with that email already exists. Try signing in instead.';
        }
    }

    if (!$errors) {
        try {
            db_run(
                'INSERT INTO customers (name, email, phone, address, password, role)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $name,
                    $email,
                    $phone !== '' ? $phone : null,
                    $address !== '' ? mb_substr($address, 0, 255) : null,
                    password_hash($password, PASSWORD_DEFAULT),
                    'customer',
                ]
            );
            login_user((int) db()->lastInsertId(), $name, 'customer', $email);
            flash('success', 'Welcome to FreshCart, ' . explode(' ', $name)[0] . '! Add your delivery address to speed up checkout.');
            redirect('account.php');
        } catch (PDOException $e) {
            if ((int) db_value('SELECT COUNT(*) FROM customers WHERE email = ?', [$email]) > 0) {
                $errors[] = 'An account with that email already exists. Try signing in instead.';
            } else {
                throw $e;
            }
        }
    }

    remember_old(['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address]);
}

$page_title = 'Create account';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <div class="fc-auth">
    <div class="fc-auth-form">
      <div class="fc-card p-4 p-md-5">
        <span class="fc-eyebrow"><i class="bi bi-person-plus"></i> Join FreshCart</span>
        <h1 style="font-size:1.6rem;font-weight:800;color:var(--fc-ink)">Create your account</h1>
        <p class="text-muted" style="font-size:.9rem">Free to join. Checkout takes seconds once you are set up.</p>

        <?php if ($errors): ?>
          <div class="fc-alert fc-alert-danger mb-3">
            <i class="bi bi-exclamation-triangle"></i>
            <div>
              <?php foreach ($errors as $message): ?>
                <div><?= h($message) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate>
          <?= csrf_field() ?>

          <div class="fc-field">
            <label class="fc-label" for="name">Full name</label>
            <input class="fc-input" type="text" id="name" name="name" required maxlength="80"
                   autocomplete="name" value="<?= old('name') ?>" placeholder="Priya Sharma">
          </div>

          <div class="row g-3">
            <div class="col-sm-6">
              <div class="fc-field">
                <label class="fc-label" for="email">Email address</label>
                <input class="fc-input" type="email" id="email" name="email" required maxlength="120"
                       autocomplete="email" value="<?= old('email') ?>" placeholder="you@example.com">
              </div>
            </div>
            <div class="col-sm-6">
              <div class="fc-field">
                <label class="fc-label" for="phone">Phone <span class="text-muted fw-normal">(optional)</span></label>
                <input class="fc-input" type="tel" id="phone" name="phone" maxlength="18"
                       autocomplete="tel" value="<?= old('phone') ?>" placeholder="+91 98765 43210">
              </div>
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="address">Default delivery address <span class="text-muted fw-normal">(optional)</span></label>
            <textarea class="fc-textarea" id="address" name="address" maxlength="255" style="min-height:70px"
                      placeholder="Flat / house, street, area"><?= old('address') ?></textarea>
            <p class="fc-hint mb-0">You can add more addresses later from your account.</p>
          </div>

          <div class="row g-3">
            <div class="col-sm-6">
              <div class="fc-field">
                <label class="fc-label" for="password">Password</label>
                <input class="fc-input" type="password" id="password" name="password" required
                       autocomplete="new-password" placeholder="At least 8 characters">
              </div>
            </div>
            <div class="col-sm-6">
              <div class="fc-field">
                <label class="fc-label" for="password_confirm">Confirm password</label>
                <input class="fc-input" type="password" id="password_confirm" name="password_confirm" required
                       autocomplete="new-password" placeholder="Repeat your password">
              </div>
            </div>
          </div>

          <button class="btn btn-green btn-lg btn-block" type="submit">
            <i class="bi bi-person-check"></i> Create account
          </button>
        </form>

        <div class="fc-divider"></div>

        <p class="text-center mb-0" style="font-size:.9rem">
          Already have an account?
          <a href="login.php" style="font-weight:600">Sign in</a>
        </p>
      </div>
    </div>
  </div>
</div>

<?php
clear_old();
require __DIR__ . '/includes/footer.php';
?>