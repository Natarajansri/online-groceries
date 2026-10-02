<?php
/**
 * Customer and administrator sign-in.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/index.php' : 'dashboard.php');
}

$error    = '';
$email    = '';
$redirect = (string) ($_SESSION['redirect_after_login'] ?? 'dashboard.php');
unset($_SESSION['redirect_after_login']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $user = db_one(
        'SELECT id, name, email, password, role, is_active FROM customers WHERE email = ?',
        [$email]
    );

    // Hash the password regardless of the outcome to blunt timing leaks.
    $hash = $user['password'] ?? '$2y$10$usesomesillystringforsalt0000000000000000000000000000000000';
    $valid = password_verify($password, (string) $hash);

    if ($user === null || !$valid) {
        $error = 'That email and password combination is not recognised.';
        usleep(250000);
    } elseif ((int) $user['is_active'] !== 1) {
        $error = 'This account has been deactivated. Please contact support.';
    } else {
        login_user((int) $user['id'], (string) $user['name'], (string) $user['role'], (string) $user['email']);
        flash('success', 'Welcome back, ' . explode(' ', (string) $user['name'])[0] . '!');

        $target = safe_local_path($redirect);
        if ($target === null) {
            $target = $user['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php';
        }

        redirect($target);
    }
}

$page_title = 'Sign in';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <div class="fc-auth">
    <div class="fc-auth-form">
      <div class="fc-auth-visual d-none d-lg-block">
        <span class="fc-badge badge-soft-success mb-3"><i class="bi bi-shield-check"></i> Secure checkout</span>
        <h2 style="font-size:2rem;font-weight:800;line-height:1.2;color:#fff">
          Fresh groceries,<br>delivered today.
        </h2>
        <p style="color:rgba(255,255,255,.78);font-size:.95rem;max-width:26ch">
          Sign in to track orders, save your wishlist and check out in seconds.
        </p>
        <ul class="list-unstyled mt-4 d-flex flex-column gap-2" style="color:rgba(255,255,255,.85);font-size:.88rem">
          <li><i class="bi bi-check2-circle"></i> Live order tracking</li>
          <li><i class="bi bi-check2-circle"></i> Saved wishlist and addresses</li>
          <li><i class="bi bi-check2-circle"></i> Coupons at checkout</li>
        </ul>
      </div>

      <div class="fc-card p-4 p-md-5">
        <span class="fc-eyebrow"><i class="bi bi-box-arrow-in-right"></i> Welcome back</span>
        <h1 style="font-size:1.6rem;font-weight:800;color:var(--fc-ink)">Sign in</h1>
        <p class="text-muted" style="font-size:.9rem">Use your email and password to continue.</p>

        <?php if (isset($_GET['registered'])): ?>
          <div class="fc-alert fc-alert-success mb-3" data-autohide="6000">
            <i class="bi bi-check-circle"></i><div>Account created. You can sign in now.</div>
          </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="fc-alert fc-alert-danger mb-3">
            <i class="bi bi-exclamation-triangle"></i><div><?= h($error) ?></div>
          </div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
          <?= csrf_field() ?>

          <div class="fc-field">
            <label class="fc-label" for="email">Email address</label>
            <input class="fc-input" type="email" id="email" name="email" required autofocus
                   autocomplete="email" maxlength="120" value="<?= h($email) ?>"
                   placeholder="you@example.com">
          </div>

          <div class="fc-field">
            <div class="d-flex justify-content-between align-items-center">
              <label class="fc-label" for="password">Password</label>
              <small class="text-muted"><a href="#" data-toggle="password" data-target="#password">Show</a></small>
            </div>
            <input class="fc-input" type="password" id="password" name="password" required
                   autocomplete="current-password" placeholder="Your password">
          </div>

          <button class="btn btn-green btn-lg btn-block mt-2" type="submit">
            <i class="bi bi-box-arrow-in-right"></i> Sign in
          </button>
        </form>

        <div class="fc-divider"></div>

        <p class="text-center mb-0" style="font-size:.9rem">
          New to FreshCart?
          <a href="register.php" style="font-weight:600">Create an account</a>
        </p>

        <div class="fc-demo-hint mt-4">
          <strong style="font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;color:var(--fc-ink)">Demo logins</strong>
          <div style="font-size:.82rem" class="mt-1">
            Customer &middot; <code>demo@freshcart.test</code> / <code>Demo@1234</code>
          </div>
          <div style="font-size:.82rem">
            Admin &middot; <code>admin@freshcart.test</code> / <code>Admin@123</code>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('[data-toggle="password"]').forEach(function (link) {
  link.addEventListener('click', function (e) {
    e.preventDefault();
    var input = document.querySelector(link.dataset.target);
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    link.textContent = show ? 'Hide' : 'Show';
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>