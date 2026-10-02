=====================================================================
 FRESHCART - ONLINE GROCERY STOREFRONT + ADMIN
=====================================================================

A self-contained grocery shop built on plain PHP 8 + MariaDB/MySQL with
Bootstrap 5. No Composer, no build step, no internet connection needed at
runtime: every stylesheet, icon font and script is served from assets/.

Storefront: catalogue with search/filters, product detail, cart, coupons,
wishlist, reviews, address book, checkout and order tracking.
Admin: dashboard, products, categories, reviews, orders, coupons, customers.

---------------------------------------------------------------------
 1. REQUIREMENTS
---------------------------------------------------------------------
  PHP      8.0 or newer (built and tested on 8.2.12) with pdo_mysql
  Database MariaDB 10.4+ or MySQL 5.7+ (tested on MariaDB 10.4.32)
  Web      Apache with mod_rewrite and php enabled (tested on 2.4.58)

  Check your PHP first:
      C:\xampp\php\php.exe -v

---------------------------------------------------------------------
 2. INSTALL
---------------------------------------------------------------------
  1. Copy this folder to your web root so that the URL ends in
     "online-grocery":
         C:\xampp\htdocs\online-grocery

  2. Start Apache and MySQL.

  3. Pick ONE of the two database options below (3a or 3b).

  4. Open http://localhost/online-grocery/

---------------------------------------------------------------------
 3. DATABASE
---------------------------------------------------------------------
 3a. FRESH INSTALL (no data yet - use this first)

     Import through phpMyAdmin:
         http://localhost/phpmyadmin  ->  select "online_grocery"  ->  Import

     or from the command line:
         C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE online_grocery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
         C:\xampp\mysql\bin\mysql.exe -u root online_grocery < database.sql

     WARNING: database.sql starts with DROP TABLE statements. Never run it
     on a database that already holds data.

 3b. EXISTING LEGACY DATABASE (the old 4-table mini project)

     Keep your data and bring it up to the modern schema with migrate.sql:
         C:\xampp\mysql\bin\mysql.exe -u root online_grocery < migrate.sql

     Notes
       * There is deliberately no "USE" statement in migrate.sql. Always name
         the database on the command line - that way you cannot migrate the
         wrong one by accident. Forgetting the name fails immediately with
         "No database selected".
       * Run it from the mysql CLI. It uses DELIMITER and prepared statements,
         which the phpMyAdmin importer and most PHP one-liners cannot handle.
       * Take a backup first:
             C:\xampp\mysql\bin\mysqldump.exe -u root online_grocery > backup.sql
       * It is safe to run more than once - every structural change is guarded.
       * What it does: creates the missing tables, moves products.category text
         into a proper category_id foreign key, widens the legacy signed INT ids
         to UNSIGNED, generates slugs/units/order codes, seeds coupons and the
         two demo logins, and finally reconciles the products table with the
         fresh schema (drops the leftover category column, widens name to 120,
         makes slug UNIQUE).

     It ends with a self-check:
         status | products | categories | customers | coupons |
         addresses | foreign_keys | widened_ids

---------------------------------------------------------------------
 4. CONFIGURATION
---------------------------------------------------------------------
  Everything is in config/config.php:

      DB_HOST, DB_NAME, DB_USER, DB_PASS   database connection
      DELIVERY_FEE                         flat delivery charge
      FREE_DELIVERY_ABOVE                  free delivery threshold
      ITEMS_PER_PAGE, REVIEWS_PER_PAGE     pagination
      MAX_UPLOAD_BYTES                     product photo size limit
      ORDER_FLOW                           the order status pipeline
      STATUS_META                          badge/icon text per status

  If the app lives in a sub-folder, app_base() in includes/url_helper.php
  detects it automatically - no other file needs editing.

---------------------------------------------------------------------
 5. DEMO LOGINS
---------------------------------------------------------------------
  Admin    admin@freshcart.test   Admin@123
  Customer demo@freshcart.test    Demo@1234

  Both are seeded by database.sql (fresh) and by migrate.sql (legacy).
  Change or delete them before putting the app on a public host.

---------------------------------------------------------------------
 6. FILES
---------------------------------------------------------------------
  index.php  products.php  product.php  cart.php         storefront
  login.php  register.php  logout.php  dashboard.php     auth + account
  account.php  addresses.php  wishlist.php               account area
  checkout.php  place_order.php  order_success.php        checkout flow
  orders.php  order_details.php  order_cancel.php         order tracking
  coupon_apply.php  review_save.php                      form posts

  ajax/     cart_add, cart_update, cart_remove,
            wishlist_toggle, coupon_check                 JSON endpoints
  admin/    index, products, product_form, categories, reviews,
            orders, order_view, coupons, coupon_form, customers
  includes/ functions, auth, db helpers, address formatting,
            product queries, header/footer, product card
  config/   config.php (settings), db.php (PDO + error handling)
  assets/   css, js, img/products (seeded), img/uploads (admin photos)

  database.sql   fresh schema + seed data
  migrate.sql    legacy -> modern upgrade (see section 3b)

---------------------------------------------------------------------
 7. HOW THE IMPORTANT PARTS WORK
---------------------------------------------------------------------
  Security
    * PDO prepared statements everywhere (no string-built SQL).
    * password_hash / password_verify, never plain text.
    * Session id regenerated on login.
    * CSRF token required on every POST; roles checked in admin/bootstrap.php.
    * Login ?redirect= is validated by safe_local_path() - it only accepts
      same-app paths, so it cannot be used as an open redirect.
    * Product uploads are validated by extension/MIME/size and confined to
      assets/img/uploads; delete_uploaded_image() refuses paths that try to
      escape that folder.
    * config/db.php installs a global exception handler, so a bug never prints
      a stack trace to the browser - the detail goes to the PHP error log.

  Checkout
    checkout.php validates the address and payment method, then hands over to
    place_order.php, which:
      1. locks the product rows with SELECT ... FOR UPDATE,
      2. re-reads prices and stock inside the transaction,
      3. re-evaluates the coupon against the real server-side cart subtotal,
      4. writes orders, order_items and order_status_history,
      5. decrements stock and increments sold_count,
      6. commits - or rolls everything back on failure.

  Coupons
    ajax/coupon_check.php never trusts a subtotal sent by the browser; it
    always prices the coupon against cart_lines() on the server.
    Cancelling an order returns the stock and releases the coupon usage.
    A delivered order is final - record returns or refunds in the order
    notes rather than cancelling it.

---------------------------------------------------------------------
 8. TROUBLESHOOTING
---------------------------------------------------------------------
  "Database connection failed..."
      MySQL is not running. Start it and reload.

  Every page returns 503
      Same cause - the database is down, so the app refuses to render.

  Styles or icons look broken
      The app must be reachable through a web server (not opened as a
      file:// path) so the relative asset URLs resolve.

  "No database selected" when running migrate.sql
      Include the database name:
          mysql.exe -u root online_grocery < migrate.sql

  Apache returns 403 for a folder
      Check that C:/xampp/htdocs has "Require all granted" and that
      AllowOverride allows .htaccess.

---------------------------------------------------------------------
 9. BEFORE GOING LIVE
---------------------------------------------------------------------
  [ ] Change the two demo passwords, or delete the demo rows.
  [ ] Turn display_errors off and log_errors on in php.ini.
  [ ] Serve the site over HTTPS.
  [ ] Move uploads out of the docroot or block direct execution in
      assets/img/uploads.
  [ ] Back up the database on a schedule.
=====================================================================