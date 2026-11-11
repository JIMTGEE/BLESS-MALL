-- Bless Mall portal completion migration
-- Target: MySQL/MariaDB (StormerHost/cPanel)
-- Take a full database backup before running. Apply once, after confirming the
-- current database is blessmal_db. This migration preserves existing rows.
-- Existing orders/vendors/deliverers/deliveries/order_courier_assignments tables
-- are already present in the supplied schema snapshot and are not recreated.

-- Newsletter signups currently fail closed because this table is absent.
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(150) NOT NULL,
    `subscribed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('active', 'unsubscribed') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_newsletter_subscribers_email` (`email`),
    KEY `idx_newsletter_subscribers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replace erroneous per-column wishlist uniqueness with one user/product pair.
-- Discover and drop only single-column UNIQUE indexes on these two columns;
-- preserve primary keys, foreign keys, and unrelated/composite indexes.
SELECT GROUP_CONCAT(
           CONCAT('DROP INDEX `', REPLACE(`index_name`, '`', '``'), '`')
           ORDER BY `index_name` SEPARATOR ', '
       )
INTO @wishlist_drop_indexes
FROM (
    SELECT `index_name`
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'wishlist'
      AND `non_unique` = 0
      AND `index_name` <> 'PRIMARY'
    GROUP BY `index_name`
    HAVING COUNT(*) = 1
       AND MAX(`column_name`) IN ('user_id', 'product_id')
) AS `wishlist_indexes_to_drop`;

SET @wishlist_alter = CONCAT(
    'ALTER TABLE `wishlist` ',
    IF(@wishlist_drop_indexes IS NULL, '', CONCAT(@wishlist_drop_indexes, ', ')),
    'ADD UNIQUE KEY `uq_wishlist_user_product` (`user_id`, `product_id`), ',
    'ADD KEY `idx_wishlist_product_id` (`product_id`)'
);
PREPARE `wishlist_migration_stmt` FROM @wishlist_alter;
EXECUTE `wishlist_migration_stmt`;
DEALLOCATE PREPARE `wishlist_migration_stmt`;

-- Allow multiple variants per product and repeated names/values across products.
-- Retain uniqueness for a variant's name/value combination within its product.
SELECT GROUP_CONCAT(
           CONCAT('DROP INDEX `', REPLACE(`index_name`, '`', '``'), '`')
           ORDER BY `index_name` SEPARATOR ', '
       )
INTO @variant_drop_indexes
FROM (
    SELECT `index_name`
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'product_variants'
      AND `non_unique` = 0
      AND `index_name` <> 'PRIMARY'
    GROUP BY `index_name`
    HAVING COUNT(*) = 1
       AND MAX(`column_name`) IN ('product_id', 'variant_name', 'variant_value')
) AS `variant_indexes_to_drop`;

SET @variant_alter = CONCAT(
    'ALTER TABLE `product_variants` ',
    IF(@variant_drop_indexes IS NULL, '', CONCAT(@variant_drop_indexes, ', ')),
    'ADD UNIQUE KEY `uq_product_variants_product_name_value` (`product_id`, `variant_name`, `variant_value`)'
);
PREPARE `variant_migration_stmt` FROM @variant_alter;
EXECUTE `variant_migration_stmt`;
DEALLOCATE PREPARE `variant_migration_stmt`;

-- Post-migration verification.
SELECT `table_name`, `index_name`, `non_unique`, `column_name`, `seq_in_index`
FROM `information_schema`.`statistics`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN ('newsletter_subscribers', 'wishlist', 'product_variants')
ORDER BY `table_name`, `index_name`, `seq_in_index`;
