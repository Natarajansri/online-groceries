-- ============================================================
--  FreshCart · migration for an EXISTING online_grocery database
--  Brings the original 4-table mini project up to the modern schema.
--
--  Run this INSTEAD of database.sql if you already have data.
--    mysql -u root -p online_grocery < migrate.sql
--
--  Notes
--   * There is deliberately no "USE" statement: name the database on the
--     command line so you cannot migrate the wrong one by accident.
--   * Safe to run more than once: every structural change is guarded.
--   * Legacy ids were signed INT; the modern schema uses UNSIGNED, so
--     the ids are widened first while FK checks are briefly disabled.
--   * Take a copy of your data before running this on a live database.
-- ============================================================

SET @db = DATABASE();

-- No "USE" on purpose. If you forget to name a database the very first
-- CREATE TABLE below fails with "No database selected", which is the point.

SET @old_fk = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. customers: role, active flag, nullable phone/address, resets
-- ============================================================
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE customers
  MODIFY COLUMN phone VARCHAR(20) NULL,
  MODIFY COLUMN address VARCHAR(255) NULL,
  ADD COLUMN role ENUM(''customer'',''admin'') NOT NULL DEFAULT ''customer'' AFTER password,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
  ADD COLUMN reset_token VARCHAR(64) NULL AFTER is_active,
  ADD COLUMN reset_expires DATETIME NULL AFTER reset_token,
  ADD KEY idx_customers_role (role)') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customers' AND COLUMN_NAME='role');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- 2. products: modern columns
-- ============================================================
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE products
  ADD COLUMN slug VARCHAR(140) NOT NULL DEFAULT '''' AFTER name,
  ADD COLUMN brand VARCHAR(80) NOT NULL DEFAULT ''FreshCart'' AFTER slug,
  ADD COLUMN unit VARCHAR(24) NOT NULL DEFAULT ''1 pc'' AFTER brand,
  ADD COLUMN description TEXT NULL AFTER unit,
  ADD COLUMN mrp DECIMAL(10,2) NULL AFTER price,
  ADD COLUMN image VARCHAR(120) NOT NULL DEFAULT ''placeholder.svg'' AFTER stock,
  ADD COLUMN rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0.00 AFTER image,
  ADD COLUMN rating_count INT NOT NULL DEFAULT 0 AFTER rating_avg,
  ADD COLUMN sold_count INT NOT NULL DEFAULT 0 AFTER rating_count,
  ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER sold_count,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_featured') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='slug');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- 3. categories table, then products.category_id
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
  id         TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL,
  slug       VARCHAR(60) NOT NULL,
  icon       VARCHAR(16) NOT NULL DEFAULT 'basket',
  sort_order TINYINT     NOT NULL DEFAULT 0,
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the categories the legacy products actually use. Section 9a drops that
-- legacy column, so this has to be a no-op on a re-run.
SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='category') > 0,
    'INSERT INTO categories (name, slug, icon, sort_order)
     SELECT src.name,
            src.slug,
            CASE src.name
              WHEN ''Fruits''       THEN ''apple''
              WHEN ''Vegetables''   THEN ''carrot''
              WHEN ''Dairy''        THEN ''egg''
              WHEN ''Dairy & Eggs'' THEN ''egg''
              WHEN ''Bakery''       THEN ''cake''
              WHEN ''Essentials''   THEN ''droplet''
              WHEN ''Grains''       THEN ''basket''
              ELSE ''basket''
            END,
            99
     FROM (
       SELECT TRIM(category) AS name,
              LOWER(REPLACE(TRIM(category), '' '', ''-'')) AS slug
         FROM products
        WHERE category IS NOT NULL AND TRIM(category) <> ''''
        GROUP BY TRIM(category), LOWER(REPLACE(TRIM(category), '' '', ''-''))
     ) AS src
     WHERE NOT EXISTS (
       SELECT 1 FROM categories c
        WHERE c.slug = src.slug OR c.name = src.name
     )',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE products
  ADD COLUMN category_id TINYINT UNSIGNED NULL AFTER id,
  ADD KEY idx_products_category (category_id, is_active)') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='category_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Legacy rows only carry the free-text "category" column, which section 9a
-- drops. Guard the backfill so a second run (where the column is already gone)
-- is a no-op instead of an error.
SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='category') > 0,
    'UPDATE products p JOIN categories c ON c.name = TRIM(p.category)
        SET p.category_id = c.id
      WHERE p.category_id IS NULL',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE products SET category_id = (SELECT MIN(id) FROM categories)
WHERE category_id IS NULL;

SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE products
  MODIFY COLUMN category_id TINYINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id)
    REFERENCES categories (id) ON DELETE RESTRICT', 'DO 0') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='category_id'
    AND IS_NULLABLE='YES');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- 4. widen legacy ids to UNSIGNED so the new tables can reference them
--
--    The legacy project already had three foreign keys (orders_ibfk_1,
--    order_items_ibfk_1, order_items_ibfk_2). InnoDB refuses to change the
--    type of a column that takes part in a foreign key, even with
--    FOREIGN_KEY_CHECKS=0, so they are dropped first and recreated at the
--    end of this script with the modern names from database.sql.
-- ============================================================
DROP PROCEDURE IF EXISTS fc_drop_legacy_fks;

DELIMITER $$
CREATE PROCEDURE fc_drop_legacy_fks()
BEGIN
  DECLARE finished   INT DEFAULT 0;
  DECLARE child_tbl  VARCHAR(64);
  DECLARE child_key  VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT TABLE_NAME, CONSTRAINT_NAME
      FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND REFERENCED_TABLE_NAME IN ('customers', 'orders', 'products')
       AND CONSTRAINT_NAME NOT LIKE 'fk\_%';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = 1;

  OPEN cur;
  drop_loop: LOOP
    FETCH cur INTO child_tbl, child_key;
    IF finished = 1 THEN
      LEAVE drop_loop;
    END IF;
    SET @s := CONCAT('ALTER TABLE `', child_tbl, '` DROP FOREIGN KEY `', child_key, '`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;

CALL fc_drop_legacy_fks();
DROP PROCEDURE fc_drop_legacy_fks;

SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE customers MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customers' AND COLUMN_NAME='id'
    AND DATA_TYPE='int' AND LOCATE('unsigned', COLUMN_TYPE)=0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE products MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='id'
    AND DATA_TYPE='int' AND LOCATE('unsigned', COLUMN_TYPE)=0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE orders MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='id'
    AND DATA_TYPE='int' AND LOCATE('unsigned', COLUMN_TYPE)=0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE orders MODIFY COLUMN customer_id INT UNSIGNED NOT NULL', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='customer_id'
    AND DATA_TYPE='int' AND LOCATE('unsigned', COLUMN_TYPE)=0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE order_items MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='id'
    AND DATA_TYPE='int' AND LOCATE('unsigned', COLUMN_TYPE)=0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE order_items MODIFY COLUMN order_id INT UNSIGNED NOT NULL', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='order_id'
    AND DATA_TYPE='int' AND LOCATE('unsigned', COLUMN_TYPE)=0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE order_items MODIFY COLUMN product_id INT UNSIGNED NOT NULL', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='product_id'
    AND DATA_TYPE='int' AND LOCATE('unsigned', COLUMN_TYPE)=0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- legacy order_items stored quantity and price as INT; the modern app writes
-- paise-accurate money, so widen price to DECIMAL(10,2)
SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE order_items
  MODIFY COLUMN price DECIMAL(10,2) NOT NULL,
  MODIFY COLUMN quantity INT NOT NULL', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='price'
    AND DATA_TYPE IN ('int','bigint','smallint','tinyint','mediumint'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- 5. supporting tables
-- ============================================================
CREATE TABLE IF NOT EXISTS addresses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  label       VARCHAR(40)  NOT NULL DEFAULT 'Home',
  full_name   VARCHAR(100) NOT NULL,
  phone       VARCHAR(20)  NOT NULL,
  line1       VARCHAR(160) NOT NULL,
  line2       VARCHAR(160) NULL,
  city        VARCHAR(80)  NOT NULL,
  state_name  VARCHAR(80)  NOT NULL,
  pincode     VARCHAR(10)  NOT NULL,
  is_default  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_addresses_customer (customer_id),
  CONSTRAINT fk_addresses_customer FOREIGN KEY (customer_id)
    REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)   NOT NULL,
  description  VARCHAR(120)  NOT NULL DEFAULT '',
  kind         ENUM('percent','flat') NOT NULL DEFAULT 'percent',
  value        DECIMAL(10,2) NOT NULL,
  min_order    DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_discount DECIMAL(10,2) NULL,
  usage_limit  INT           NULL,
  used_count   INT           NOT NULL DEFAULT 0,
  starts_at    DATETIME      NULL,
  expires_at   DATETIME      NULL,
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_history (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  status     VARCHAR(40)  NOT NULL,
  note       VARCHAR(160) NOT NULL DEFAULT '',
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_history_order (order_id, created_at),
  CONSTRAINT fk_history_order FOREIGN KEY (order_id)
    REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  rating      TINYINT UNSIGNED NOT NULL,
  title       VARCHAR(120) NOT NULL DEFAULT '',
  comment     TEXT         NULL,
  is_approved TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reviews_user_product (product_id, customer_id),
  KEY idx_reviews_product (product_id, is_approved),
  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id)
    REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wishlist (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wishlist (customer_id, product_id),
  KEY idx_wishlist_customer (customer_id),
  CONSTRAINT fk_wishlist_customer FOREIGN KEY (customer_id)
    REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(150) NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  used       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_resets_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. orders: code, money breakdown, delivery, coupon, payment
-- ============================================================
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE orders
  ADD COLUMN order_code VARCHAR(20) NOT NULL DEFAULT '''' AFTER id,
  ADD COLUMN address_snapshot TEXT NOT NULL AFTER customer_id,
  ADD COLUMN coupon_id INT UNSIGNED NULL AFTER address_snapshot,
  ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER coupon_id,
  ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER delivery_fee,
  ADD COLUMN payment_method ENUM(''COD'',''UPI'',''Card'') NOT NULL DEFAULT ''COD'' AFTER total_amount,
  ADD COLUMN note VARCHAR(255) NULL AFTER status,
  ADD KEY idx_orders_status (status)') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='order_code');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE orders o
  JOIN customers c ON c.id = o.customer_id
SET o.address_snapshot = CONCAT(c.name, ' | ', COALESCE(c.phone, ''), ' | ', COALESCE(c.address, ''))
WHERE o.address_snapshot IS NULL OR o.address_snapshot = '';

UPDATE orders SET order_code = CONCAT('FC-', LPAD(id, 6, '0')) WHERE order_code = '';

SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE orders ADD UNIQUE KEY uq_orders_code (order_code)')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND INDEX_NAME='uq_orders_code');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE orders o SET o.subtotal = (SELECT COALESCE(SUM(oi.subtotal), 0)
                                    FROM order_items oi WHERE oi.order_id = o.id)
WHERE o.subtotal = 0;

UPDATE orders o SET o.status = 'Delivered'
WHERE o.status = 'Processing' AND o.total_amount IS NOT NULL;

-- ============================================================
-- 7. order_items: snapshot product name and image
-- ============================================================
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE order_items
  ADD COLUMN product_name VARCHAR(120) NOT NULL DEFAULT '''' AFTER product_id,
  ADD COLUMN image VARCHAR(120) NOT NULL DEFAULT ''placeholder.svg'' AFTER product_name') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='product_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE order_items oi JOIN products p ON p.id = oi.product_id
SET oi.product_name = p.name, oi.image = p.image
WHERE oi.product_name = '' OR oi.image = 'placeholder.svg';

-- ============================================================
-- 8. widen the order status enum to the full flow
-- ============================================================
SET @s := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE orders
  MODIFY COLUMN status ENUM(''Placed'',''Confirmed'',''Packed'',''Shipped'',
                            ''Out for Delivery'',''Delivered'',''Cancelled'')
    NOT NULL DEFAULT ''Placed''', 'DO 0') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='status'
    AND COLUMN_TYPE NOT LIKE '%Out for Delivery%');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- 9. seed data: images, slugs, coupons, demo logins
-- ============================================================
UPDATE products SET slug = LOWER(TRIM(REPLACE(REPLACE(name, ' ', '-'), '/', '-'))) WHERE slug = '';
UPDATE products SET unit  = '1 pc'  WHERE unit  IS NULL OR unit  = '';
UPDATE products SET brand = 'FreshCart' WHERE brand IS NULL OR brand = '';
UPDATE products SET mrp = ROUND(price * 1.35, 2) WHERE mrp IS NULL OR mrp = 0;

-- legacy names carry the pack size ("Premium Rice 5kg", "Cooking Oil 1L"),
-- so lift it out and use it as the unit label
UPDATE products
   SET unit = COALESCE(
         NULLIF(REGEXP_SUBSTR(name,
           '[0-9]+(\\.[0-9]+)?[[:space:]]*(kg|Kg|KG|KG|kgs|g|gm|G|ml|ML|L|lt|litre|liter|dozen|Dozen|pack|Pack|bottles?|Bottles?|cans?|Cans?|pcs?|Pcs?)$'), ''),
         CASE
           WHEN name LIKE '%dozen' THEN 'per dozen'
           WHEN name LIKE '%pack'  THEN 'per pack'
           WHEN name LIKE '%bottle' THEN 'per bottle'
           ELSE 'per piece'
         END)
 WHERE unit = '1 pc';

UPDATE products SET image = 'rice-5kg.jpg'         WHERE name LIKE '%Rice%';
UPDATE products SET image = 'atta-1kg.jpg'         WHERE name LIKE '%Flour%' OR name LIKE '%Atta%';
UPDATE products SET image = 'sugar-1kg.jpg'        WHERE name LIKE '%Sugar%';
UPDATE products SET image = 'cooking-oil-1l.jpg'   WHERE name LIKE '%Oil%';
UPDATE products SET image = 'salt-1kg.jpg'         WHERE name LIKE '%Salt%';
UPDATE products SET image = 'milk-1l.jpg'          WHERE name LIKE '%Milk%';
UPDATE products SET image = 'curd-500g.jpg'        WHERE name LIKE '%Curd%' OR name LIKE '%Yogurt%';
UPDATE products SET image = 'butter-500g.jpg'      WHERE name LIKE '%Butter%';
UPDATE products SET image = 'cheese-200g.jpg'      WHERE name LIKE '%Cheese%';
UPDATE products SET image = 'eggs-12.jpg'          WHERE name LIKE '%Egg%';
UPDATE products SET image = 'bread-whole-wheat.jpg' WHERE name LIKE '%Bread%';
UPDATE products SET image = 'chapati-5-pack.jpg'   WHERE name LIKE '%Chapati%' OR name LIKE '%Roti%';
UPDATE products SET image = 'biscuits-pack.jpg'    WHERE name LIKE '%Biscuit%';
UPDATE products SET image = 'tomato-1kg.jpg'       WHERE name LIKE '%Tomato%';
UPDATE products SET image = 'potato-1kg.jpg'       WHERE name LIKE '%Potato%';
UPDATE products SET image = 'onion-1kg.jpg'        WHERE name LIKE '%Onion%';
UPDATE products SET image = 'carrot-1kg.jpg'       WHERE name LIKE '%Carrot%';
UPDATE products SET image = 'spinach-1kg.jpg'      WHERE name LIKE '%Spinach%' OR name LIKE '%Palak%';
UPDATE products SET image = 'broccoli-1kg.jpg'     WHERE name LIKE '%Broccoli%';
UPDATE products SET image = 'apple-1kg.jpg'        WHERE name LIKE '%Apple%';
UPDATE products SET image = 'banana-1dozen.jpg'    WHERE name LIKE '%Banana%';
UPDATE products SET image = 'orange-1kg.jpg'       WHERE name LIKE '%Orange%';
UPDATE products SET image = 'mango-1kg.jpg'        WHERE name LIKE '%Mango%';
UPDATE products SET image = 'blueberry-250g.jpg'   WHERE name LIKE '%Blueberr%';
UPDATE products SET image = 'detergent-1l.jpg'     WHERE name LIKE '%Dishwash%' OR name LIKE '%Detergent%';

UPDATE products SET is_featured = 1 WHERE id % 3 = 0;

INSERT INTO coupons (code, description, kind, value, min_order, max_discount, usage_limit, expires_at)
SELECT * FROM (
  SELECT 'FRESH50'   AS a,'Flat Rs.50 off on orders above Rs.400' AS b,'flat'    AS c,50.00  AS d,400.00 AS e,NULL     AS f,100 AS g,DATE_ADD(NOW(), INTERVAL 90 DAY)  AS h
  UNION ALL SELECT 'SAVE10',   '10% off your order',                  'percent',10.00, 300.00,150.00, NULL,        DATE_ADD(NOW(), INTERVAL 60 DAY)
  UNION ALL SELECT 'WELCOME25','Rs.25 off for new customers',         'flat',    25.00, 150.00,NULL,     500,         DATE_ADD(NOW(), INTERVAL 180 DAY)
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM coupons c2 WHERE c2.code = seed.a);

INSERT INTO customers (name, email, phone, address, password, role)
SELECT 'FreshCart Admin', 'admin@freshcart.test', '9000000001',
       'FreshCart HQ, Market Road, Bengaluru',
       '$2y$10$Er07DN2IdWeuvagPo381DOjUNz4uv/GhKGp2KNESG6MfIMT.FA7Ri', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM customers WHERE email = 'admin@freshcart.test');

INSERT INTO customers (name, email, phone, address, password, role)
SELECT 'Demo Customer', 'demo@freshcart.test', '9000000002',
       '42 Garden Street, Bengaluru 560001',
       '$2y$10$pD57.yq5Bwcmp2EBgLsXu.Sgr6v41U1thoPK.LOmm/4MpJvzXidRW', 'customer'
WHERE NOT EXISTS (SELECT 1 FROM customers WHERE email = 'demo@freshcart.test');

-- refresh cached rating columns
UPDATE products p SET
  rating_avg   = (SELECT ROUND(AVG(r.rating), 2) FROM reviews r
                   WHERE r.product_id = p.id AND r.is_approved = 1),
  rating_count = (SELECT COUNT(*) FROM reviews r
                   WHERE r.product_id = p.id AND r.is_approved = 1)
WHERE EXISTS (SELECT 1 FROM reviews r WHERE r.product_id = p.id);

-- ============================================================
-- 9a. reconcile the products table with the fresh schema
--
-- The legacy table kept a free-text "category" column and a narrower
-- "name"; the legacy slug index was not UNIQUE. Left alone, every admin
-- INSERT fails on products.category (NOT NULL, no default) and duplicate
-- slugs become possible, so fix them here.
-- ============================================================

-- 1. make sure every slug is unique before the UNIQUE key goes on
SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM (SELECT slug FROM products
        GROUP BY slug HAVING COUNT(*) > 1) dup) > 0,
    'UPDATE products p JOIN (SELECT slug, MIN(id) AS keep_id FROM products
        GROUP BY slug HAVING COUNT(*) > 1) d
      ON d.slug = p.slug
      SET p.slug = CONCAT(p.slug, ''-'', p.id)
      WHERE p.id <> d.keep_id',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. drop the legacy free-text category column
SET @s := (SELECT IF(COUNT(*) = 0, 'DO 0', 'ALTER TABLE products DROP COLUMN category')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='category');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. widen the name column to match the fresh schema
SET @s := (SELECT IF(CHARACTER_MAXIMUM_LENGTH = 120, 'DO 0', 'ALTER TABLE products
  MODIFY COLUMN name VARCHAR(120) NOT NULL')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. slug must be unique, and featured products need an index
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE products
  ADD UNIQUE KEY uq_products_slug (slug)')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='slug' AND NON_UNIQUE=0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE products
  ADD KEY idx_products_featured (is_featured)')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='is_featured');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- 9b. restore the foreign keys dropped in section 4
-- ============================================================
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE orders
  ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id)
    REFERENCES customers (id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_orders_coupon FOREIGN KEY (coupon_id)
    REFERENCES coupons (id) ON DELETE SET NULL')
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND CONSTRAINT_NAME='fk_orders_customer');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0', 'ALTER TABLE order_items
  ADD CONSTRAINT fk_order_items_order FOREIGN KEY (order_id)
    REFERENCES orders (id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_order_items_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE RESTRICT')
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND CONSTRAINT_NAME='fk_order_items_order');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET FOREIGN_KEY_CHECKS = @old_fk;

-- ============================================================
-- 10. verify
-- ============================================================
SELECT 'migration complete' AS status,
       (SELECT COUNT(*) FROM products)    AS products,
       (SELECT COUNT(*) FROM categories)  AS categories,
       (SELECT COUNT(*) FROM customers)   AS customers,
       (SELECT COUNT(*) FROM coupons)     AS coupons,
       (SELECT COUNT(*) FROM addresses)   AS addresses,
       (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA=@db AND REFERENCED_TABLE_NAME IS NOT NULL) AS foreign_keys,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=@db AND COLUMN_NAME='id'
           AND LOCATE('unsigned', COLUMN_TYPE)>0
           AND TABLE_NAME IN ('customers','products','orders','order_items')) AS widened_ids;
