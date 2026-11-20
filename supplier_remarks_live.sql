-- Remarks on a supplier (MariaDB-safe). Run once in phpMyAdmin after uploading the backend.

ALTER TABLE `suppliers`
  ADD COLUMN `remarks` TEXT NULL AFTER `nie`;

-- So `php artisan migrate` does not run it again.
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_19_000100_add_remarks_to_suppliers', MAX(`batch`) FROM `migrations`;
