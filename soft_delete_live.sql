-- Soft delete for invoices, delivery notes, quotations and proformas (MariaDB-safe). Run once, in this order.

ALTER TABLE `sales_documents`
  ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `voided_by`,
  ADD COLUMN `deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`,
  ADD COLUMN `deleted_source_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `deleted_by`,
  ADD KEY `sales_documents_deleted_at_index` (`deleted_at`),
  ADD CONSTRAINT `sales_documents_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- The new Delete tick: whoever already has the manager-level tick gets it (their own ticks).
UPDATE `users` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'invoices.delete')
 WHERE `permissions` IS NOT NULL AND JSON_SEARCH(`permissions`, 'one', 'invoices.void') IS NOT NULL AND JSON_SEARCH(`permissions`, 'one', 'invoices.delete') IS NULL;
UPDATE `users` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'delivery_notes.delete')
 WHERE `permissions` IS NOT NULL AND JSON_SEARCH(`permissions`, 'one', 'delivery_notes.void') IS NOT NULL AND JSON_SEARCH(`permissions`, 'one', 'delivery_notes.delete') IS NULL;
UPDATE `users` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'quotes.delete')
 WHERE `permissions` IS NOT NULL AND JSON_SEARCH(`permissions`, 'one', 'quotes.update') IS NOT NULL AND JSON_SEARCH(`permissions`, 'one', 'quotes.delete') IS NULL;
UPDATE `users` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'proformas.delete')
 WHERE `permissions` IS NOT NULL AND JSON_SEARCH(`permissions`, 'one', 'proformas.create') IS NOT NULL AND JSON_SEARCH(`permissions`, 'one', 'proformas.delete') IS NULL;

-- ...and in a company's saved roles (manager, cashier, accountant).
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.manager', 'invoices.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'invoices.void', NULL, '$.manager') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'invoices.delete', NULL, '$.manager') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.manager', 'delivery_notes.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'delivery_notes.void', NULL, '$.manager') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'delivery_notes.delete', NULL, '$.manager') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.manager', 'quotes.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'quotes.update', NULL, '$.manager') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'quotes.delete', NULL, '$.manager') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.manager', 'proformas.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'proformas.create', NULL, '$.manager') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'proformas.delete', NULL, '$.manager') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.cashier', 'invoices.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'invoices.void', NULL, '$.cashier') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'invoices.delete', NULL, '$.cashier') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.cashier', 'delivery_notes.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'delivery_notes.void', NULL, '$.cashier') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'delivery_notes.delete', NULL, '$.cashier') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.cashier', 'quotes.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'quotes.update', NULL, '$.cashier') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'quotes.delete', NULL, '$.cashier') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.cashier', 'proformas.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'proformas.create', NULL, '$.cashier') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'proformas.delete', NULL, '$.cashier') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.accountant', 'invoices.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'invoices.void', NULL, '$.accountant') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'invoices.delete', NULL, '$.accountant') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.accountant', 'delivery_notes.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'delivery_notes.void', NULL, '$.accountant') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'delivery_notes.delete', NULL, '$.accountant') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.accountant', 'quotes.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'quotes.update', NULL, '$.accountant') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'quotes.delete', NULL, '$.accountant') IS NULL;
UPDATE `companies` SET `role_presets` = JSON_ARRAY_APPEND(`role_presets`, '$.accountant', 'proformas.delete')
 WHERE `role_presets` IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'proformas.create', NULL, '$.accountant') IS NOT NULL AND JSON_SEARCH(`role_presets`, 'one', 'proformas.delete', NULL, '$.accountant') IS NULL;

-- So `php artisan migrate` does not run them again.
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_16_000100_add_soft_delete_to_sales_documents', MAX(`batch`) FROM `migrations`;
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_11_16_000200_add_document_delete_permissions', MAX(`batch`) FROM `migrations`;
