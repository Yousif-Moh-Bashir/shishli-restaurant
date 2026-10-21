-- Read-only, safe aggregate/schema output. Run BEFORE the release migration.
SELECT VERSION() AS mysql_version;
SELECT table_name, engine, table_rows,
       ROUND((data_length + index_length) / 1024 / 1024, 2) AS approximate_size_mb
FROM information_schema.tables WHERE table_schema = DATABASE()
AND table_name IN ('orders','users','admin_activity_logs','payments',
 'payment_transactions','driver_cash_collections','order_delivery_histories');
SELECT migration, batch FROM migrations
WHERE migration = '2026_10_21_201116_add_delivery_exception_management';
SELECT table_name, column_name, column_type, is_nullable, column_default
FROM information_schema.columns WHERE table_schema = DATABASE()
AND ((table_name = 'orders' AND column_name IN
 ('delivery_revision','delivery_task_closed_at','delivery_exception_requested_at','delivery_exception_reason'))
 OR (table_name = 'admin_activity_logs' AND column_name = 'description'));
SELECT 'orders' AS entity, COUNT(*) AS row_count FROM orders
UNION ALL SELECT 'payments', COUNT(*) FROM payments
UNION ALL SELECT 'payment_transactions', COUNT(*) FROM payment_transactions
UNION ALL SELECT 'driver_cash_collections', COUNT(*) FROM driver_cash_collections;
