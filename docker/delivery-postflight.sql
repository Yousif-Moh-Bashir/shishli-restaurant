-- Read-only. Run AFTER migration, with writers still paused, then after opening.
SELECT migration, batch FROM migrations
WHERE migration = '2026_10_21_201116_add_delivery_exception_management';
SELECT table_name, column_name, column_type, is_nullable, column_default
FROM information_schema.columns WHERE table_schema = DATABASE()
AND ((table_name = 'orders' AND column_name IN
 ('delivery_revision','delivery_task_closed_at','delivery_exception_requested_at','delivery_exception_reason'))
 OR (table_name = 'admin_activity_logs' AND column_name = 'description'));
SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_index
FROM information_schema.statistics WHERE table_schema = DATABASE()
AND table_name = 'delivery_operations' GROUP BY index_name, non_unique;
SELECT r.name AS role_name, p.name AS permission_name
FROM roles r JOIN role_has_permissions rp ON rp.role_id = r.id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.name LIKE 'delivery_exceptions.%' ORDER BY r.name, p.name;
SELECT 'orders' AS entity, COUNT(*) AS row_count FROM orders
UNION ALL SELECT 'payments', COUNT(*) FROM payments
UNION ALL SELECT 'payment_transactions', COUNT(*) FROM payment_transactions
UNION ALL SELECT 'driver_cash_collections', COUNT(*) FROM driver_cash_collections;
-- All three must be ZERO to consider the old pre-release image compatible.
SELECT COUNT(*) AS incompatible_delivery_states FROM orders
WHERE delivery_status IN ('returning','returned','released');
SELECT COUNT(*) AS incompatible_history_sources FROM order_status_histories
WHERE source = 'delivery_exception';
SELECT COUNT(*) AS closed_tasks_hidden_from_old_code FROM orders
WHERE delivery_task_closed_at IS NOT NULL
AND delivery_status IN ('assigned','picked_up','on_the_way','failed')
AND status NOT IN ('completed','cancelled');
