-- Read-only, aggregate output. Does not expose account or setting values.
SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;
START TRANSACTION READ ONLY;
SELECT 'branches', COUNT(*) FROM branches
UNION ALL SELECT 'products', COUNT(*) FROM products
UNION ALL SELECT 'categories', COUNT(*) FROM categories
UNION ALL SELECT 'product_images', COUNT(*) FROM product_images
UNION ALL SELECT 'branch_products', COUNT(*) FROM branch_products
UNION ALL SELECT 'option_groups', COUNT(*) FROM option_groups
UNION ALL SELECT 'option_values', COUNT(*) FROM option_values
UNION ALL SELECT 'product_option_groups', COUNT(*) FROM product_option_groups
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'roles', COUNT(*) FROM roles
UNION ALL SELECT 'permissions', COUNT(*) FROM permissions
UNION ALL SELECT 'model_has_roles', COUNT(*) FROM model_has_roles
UNION ALL SELECT 'model_has_permissions', COUNT(*) FROM model_has_permissions
UNION ALL SELECT 'role_has_permissions', COUNT(*) FROM role_has_permissions
UNION ALL SELECT 'restaurant_settings', COUNT(*) FROM restaurant_settings;
SELECT 'invalid_product_category', COUNT(*) FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.category_id IS NOT NULL AND c.id IS NULL
UNION ALL SELECT 'invalid_image_product', COUNT(*) FROM product_images i LEFT JOIN products p ON p.id=i.product_id WHERE p.id IS NULL
UNION ALL SELECT 'invalid_branch_product', COUNT(*) FROM branch_products x LEFT JOIN products p ON p.id=x.product_id LEFT JOIN branches b ON b.id=x.branch_id WHERE p.id IS NULL OR b.id IS NULL
UNION ALL SELECT 'invalid_option_value', COUNT(*) FROM option_values v LEFT JOIN option_groups g ON g.id=v.option_group_id WHERE g.id IS NULL
UNION ALL SELECT 'invalid_product_option', COUNT(*) FROM product_option_groups x LEFT JOIN products p ON p.id=x.product_id LEFT JOIN option_groups g ON g.id=x.option_group_id WHERE p.id IS NULL OR g.id IS NULL
UNION ALL SELECT 'invalid_role_permission', COUNT(*) FROM role_has_permissions x LEFT JOIN roles r ON r.id=x.role_id LEFT JOIN permissions p ON p.id=x.permission_id WHERE r.id IS NULL OR p.id IS NULL
UNION ALL SELECT 'invalid_user_role', COUNT(*) FROM model_has_roles x LEFT JOIN roles r ON r.id=x.role_id LEFT JOIN users u ON u.id=x.model_id WHERE r.id IS NULL OR (x.model_type=CONCAT('App',CHAR(92),'Models',CHAR(92),'User') AND u.id IS NULL)
UNION ALL SELECT 'invalid_user_permission', COUNT(*) FROM model_has_permissions x LEFT JOIN permissions p ON p.id=x.permission_id LEFT JOIN users u ON u.id=x.model_id WHERE p.id IS NULL OR (x.model_type=CONCAT('App',CHAR(92),'Models',CHAR(92),'User') AND u.id IS NULL)
UNION ALL SELECT 'negative_product_price', COUNT(*) FROM products WHERE base_price < 0
UNION ALL SELECT 'negative_branch_price', COUNT(*) FROM branch_products WHERE price_override < 0;
ROLLBACK;
