-- IVA on a whole purchase (MariaDB-safe). Run once in phpMyAdmin after uploading the backend.

ALTER TABLE `purchases`
  ADD COLUMN `iva_mode` VARCHAR(10) NOT NULL DEFAULT 'none' AFTER `notes`,
  ADD COLUMN `iva_percent` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `iva_mode`,
  ADD COLUMN `base_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `iva_percent`,
  ADD COLUMN `iva_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `base_cents`;

-- What was written before has no IVA: its base is its total.
UPDATE `purchases` SET `base_cents` = `total_cents`;

-- So `php artisan migrate` does not run it again.
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_18_000100_add_iva_to_purchases', MAX(`batch`) FROM `migrations`;
