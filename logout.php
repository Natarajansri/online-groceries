<?php
/**
 * Sign out and return to the storefront.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

logout_user();
redirect('index.php');