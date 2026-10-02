<?php
/**
 * Address book: add, edit, default and delete delivery addresses.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();

$customerId = current_user_id();
$errors     = [];
$editingId  = (int) ($_GET['edit'] ?? 0);

/* ------------------------------------------------------------ actions */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['address_id'] ?? 0);

    if ($action === 'delete') {
        $owned = db_one('SELECT id FROM addresses WHERE id = ? AND customer_id = ?', [$id, $customerId]);
        if ($owned === null) {
            flash('danger', 'That address could not be found.');
        } else {
            db_run('DELETE FROM addresses WHERE id = ? AND customer_id = ?', [$id, $customerId]);
            if ((int) $owned['is_default'] === 1) {
                $next = db_one('SELECT id FROM addresses WHERE customer_id = ? ORDER BY created_at LIMIT 1',
                    [$customerId]);
                if ($next !== null) {
                    db_run('UPDATE addresses SET is_default = 1 WHERE id = ?', [(int) $next['id']]);
                }
            }
            flash('success', 'Address removed.');
        }
        redirect('addresses.php');
    }

    if ($action === 'default') {
        $owned = db_one('SELECT id FROM addresses WHERE id = ? AND customer_id = ?', [$id, $customerId]);
        if ($owned === null) {
            flash('danger', 'That address could not be found.');
        } else {
            db_run('UPDATE addresses SET is_default = 0 WHERE customer_id = ?', [$customerId]);
            db_run('UPDATE addresses SET is_default = 1 WHERE id = ?', [$id]);
            flash('success', 'Default delivery address updated.');
        }
        redirect('addresses.php');
    }

    if ($action === 'save') {
        $label     = trim((string) ($_POST['label'] ?? 'Home'));
        $fullName  = trim((string) ($_POST['full_name'] ?? ''));
        $phone     = trim((string) ($_POST['phone'] ?? ''));
        $line1     = trim((string) ($_POST['line1'] ?? ''));
        $line2     = trim((string) ($_POST['line2'] ?? ''));
        $city      = trim((string) ($_POST['city'] ?? ''));
        $stateName = trim((string) ($_POST['state_name'] ?? ''));
        $pincode   = trim((string) ($_POST['pincode'] ?? ''));
        $makeDefault = (int) ($_POST['is_default'] ?? 0) === 1;

        if ($label === '') {
            $label = 'Home';
        }
        if (mb_strlen($label) > 40) {
            $label = mb_substr($label, 0, 40);
        }
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            $errors[] = 'Enter the full name for this address.';
        }
        if (!preg_match('/^[0-9+\-\s()]{7,18}$/', $phone)) {
            $errors[] = 'Enter a valid 10-digit phone number.';
        }
        if ($line1 === '' || mb_strlen($line1) > 160) {
            $errors[] = 'Enter the flat / house number and street.';
        }
        if ($city === '') {
            $errors[] = 'Enter the city.';
        }
        if ($stateName === '') {
            $errors[] = 'Enter the state.';
        }
        if (!preg_match('/^[0-9]{4,10}$/', $pincode)) {
            $errors[] = 'Enter a valid PIN code (4 to 10 digits).';
        }
        if ($line2 !== '' && mb_strlen($line2) > 160) {
            $errors[] = 'The second address line is too long.';
        }

        $total = (int) db_value('SELECT COUNT(*) FROM addresses WHERE customer_id = ?', [$customerId]);
        if ($id === 0 && $total >= 10) {
            $errors[] = 'You can save up to 10 addresses. Delete one before adding another.';
        }
        if ($id !== 0 && db_value('SELECT 1 FROM addresses WHERE id = ? AND customer_id = ?', [$id, $customerId]) === null) {
            $errors[] = 'That address could not be found.';
        }

        if (!$errors) {
            if ($id === 0) {
                if ($makeDefault || $total === 0) {
                    db_run('UPDATE addresses SET is_default = 0 WHERE customer_id = ?', [$customerId]);
                }
                db_run(
                    'INSERT INTO addresses (customer_id, label, full_name, phone, line1, line2, city, state_name, pincode, is_default)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $customerId, $label, $fullName, $phone, $line1,
                        $line2 !== '' ? $line2 : null, $city, $stateName, $pincode,
                        ($makeDefault || $total === 0) ? 1 : 0,
                    ]
                );
                flash('success', 'Address saved.');
            } else {
                $isDefault = (int) db_value('SELECT is_default FROM addresses WHERE id = ?', [$id]);
                if ($makeDefault) {
                    db_run('UPDATE addresses SET is_default = 0 WHERE customer_id = ?', [$customerId]);
                }
                db_run(
                    'UPDATE addresses
                        SET label = ?, full_name = ?, phone = ?, line1 = ?, line2 = ?,
                            city = ?, state_name = ?, pincode = ?, is_default = ?
                      WHERE id = ? AND customer_id = ?',
                    [
                        $label, $fullName, $phone, $line1,
                        $line2 !== '' ? $line2 : null, $city, $stateName, $pincode,
                        $makeDefault ? 1 : $isDefault, $id, $customerId,
                    ]
                );
                flash('success', 'Address updated.');
            }
            redirect('addresses.php');
        }

        $editingId = $id;
    }
}

/* -------------------------------------------------------------- view */
$addresses = db_all(
    'SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC',
    [$customerId]
);

$edit = $editingId > 0
    ? db_one('SELECT * FROM addresses WHERE id = ? AND customer_id = ?', [$editingId, $customerId])
    : null;

$profile = db_one('SELECT name, phone, address FROM customers WHERE id = ?', [$customerId]) ?? [];

$page_title  = 'Address book';
$page_active = 'account';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i>
    <a href="account.php">Profile</a> <i class="bi bi-chevron-right"></i> <span>Address book</span>
  </nav>

  <div class="fc-section-head">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-geo-alt"></i> Delivery addresses</span>
      <h1 class="fc-section-title">Address book</h1>
      <p class="fc-section-sub">Save up to 10 addresses and pick one at checkout.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="account.php"><i class="bi bi-arrow-left"></i> Profile</a>
  </div>

  <?php if ($errors): ?>
    <div class="fc-alert fc-alert-danger mb-4">
      <i class="bi bi-exclamation-triangle"></i>
      <div><?php foreach ($errors as $message): ?><div><?= h($message) ?></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="d-flex flex-column gap-3">
        <?php foreach ($addresses as $addr): ?>
          <article class="fc-card p-3 p-md-4" style="border-color:<?= (int) $addr['is_default'] === 1 ? 'var(--fc-green)' : 'var(--fc-line)' ?>">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
              <div class="d-flex align-items-center gap-2">
                <span class="badge badge-soft-info"><i class="bi bi-geo-alt"></i> <?= h((string) $addr['label']) ?></span>
                <?php if ((int) $addr['is_default'] === 1): ?>
                  <span class="fc-badge badge-soft-success">Default</span>
                <?php endif; ?>
              </div>
              <small class="text-muted">Added <?= h(nice_date((string) $addr['created_at'])) ?></small>
            </div>

            <strong style="color:var(--fc-ink)"><?= h((string) $addr['full_name']) ?></strong>
            <p class="text-muted mb-3" style="font-size:.88rem;white-space:pre-line"><?= h(format_address($addr)) ?></p>

            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-ghost btn-sm" href="addresses.php?edit=<?= (int) $addr['id'] ?>#address-form">
                <i class="bi bi-pencil"></i> Edit
              </a>
              <?php if ((int) $addr['is_default'] !== 1): ?>
                <form method="post" action="addresses.php" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="default">
                  <input type="hidden" name="address_id" value="<?= (int) $addr['id'] ?>">
                  <button class="btn btn-ghost btn-sm"><i class="bi bi-star"></i> Make default</button>
                </form>
              <?php endif; ?>
              <form method="post" action="addresses.php" class="d-inline ms-auto"
                    data-confirm="Delete this address?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="address_id" value="<?= (int) $addr['id'] ?>">
                <button class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Delete</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>

        <?php if (!$addresses): ?>
          <div class="fc-card fc-empty">
            <div class="fc-empty-icon"><i class="bi bi-geo-alt"></i></div>
            <h3>No saved addresses</h3>
            <p>Add an address using the form so checkout takes a few seconds.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="fc-card fc-sticky-side" id="address-form">
        <div class="fc-card-head">
          <h3><i class="bi bi-<?= $edit ? 'pencil' : 'plus-lg' ?>"></i> <?= $edit ? 'Edit address' : 'Add an address' ?></h3>
          <?php if ($edit): ?>
            <a class="btn btn-ghost btn-sm" href="addresses.php">Cancel</a>
          <?php endif; ?>
        </div>

        <form method="post" action="addresses.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="address_id" value="<?= (int) ($edit['id'] ?? 0) ?>">

          <div class="fc-card-body">
            <div class="row g-3">
              <div class="col-6">
                <div class="fc-field">
                  <label class="fc-label" for="label">Label</label>
                  <input class="fc-input" type="text" id="label" name="label" maxlength="40"
                         value="<?= h((string) ($edit['label'] ?? 'Home')) ?>" placeholder="Home / Work">
                </div>
              </div>
              <div class="col-6">
                <div class="fc-field">
                  <label class="fc-label" for="phone">Phone</label>
                  <input class="fc-input" type="tel" id="phone" name="phone" required maxlength="18"
                         value="<?= h((string) ($edit['phone'] ?? ($profile['phone'] ?? ''))) ?>"
                         placeholder="98765 43210">
                </div>
              </div>
              <div class="col-12">
                <div class="fc-field">
                  <label class="fc-label" for="full_name">Full name</label>
                  <input class="fc-input" type="text" id="full_name" name="full_name" required maxlength="100"
                         value="<?= h((string) ($edit['full_name'] ?? current_user_name())) ?>">
                </div>
              </div>
              <div class="col-12">
                <div class="fc-field">
                  <label class="fc-label" for="line1">Flat / house, building, street</label>
                  <input class="fc-input" type="text" id="line1" name="line1" required maxlength="160"
                         value="<?= h((string) ($edit['line1'] ?? ($profile['address'] ?? ''))) ?>"
                         placeholder="Flat 4B, Green Residency, 12th Main">
                </div>
              </div>
              <div class="col-12">
                <div class="fc-field">
                  <label class="fc-label" for="line2">Landmark <span class="text-muted fw-normal">(optional)</span></label>
                  <input class="fc-input" type="text" id="line2" name="line2" maxlength="160"
                         value="<?= h((string) ($edit['line2'] ?? '')) ?>" placeholder="Opposite to the park">
                </div>
              </div>
              <div class="col-6">
                <div class="fc-field">
                  <label class="fc-label" for="city">City</label>
                  <input class="fc-input" type="text" id="city" name="city" required maxlength="80"
                         value="<?= h((string) ($edit['city'] ?? 'Bengaluru')) ?>">
                </div>
              </div>
              <div class="col-6">
                <div class="fc-field">
                  <label class="fc-label" for="state_name">State</label>
                  <input class="fc-input" type="text" id="state_name" name="state_name" required maxlength="80"
                         value="<?= h((string) ($edit['state_name'] ?? 'Karnataka')) ?>">
                </div>
              </div>
              <div class="col-6">
                <div class="fc-field">
                  <label class="fc-label" for="pincode">PIN code</label>
                  <input class="fc-input" type="text" id="pincode" name="pincode" required maxlength="10"
                         inputmode="numeric" pattern="[0-9]{4,10}"
                         value="<?= h((string) ($edit['pincode'] ?? '560001')) ?>">
                </div>
              </div>
              <div class="col-12">
                <label class="form-check d-flex align-items-center gap-2" style="font-size:.9rem">
                  <input class="form-check-input" type="checkbox" name="is_default" value="1"
                         style="width:1.05rem;height:1.05rem"
                         <?= (int) ($edit['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
                  <span>Use as my default delivery address</span>
                </label>
              </div>
            </div>
          </div>

          <div class="fc-card-foot d-flex gap-2">
            <button class="btn btn-green flex-grow-1" type="submit">
              <i class="bi bi-check2"></i> <?= $edit ? 'Save changes' : 'Add address' ?>
            </button>
            <?php if ($edit): ?>
              <a class="btn btn-ghost" href="addresses.php">Discard</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>