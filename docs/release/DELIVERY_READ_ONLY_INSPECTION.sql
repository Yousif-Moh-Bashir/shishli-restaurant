-- Prepared for review, NOT executed. MySQL 8.x, existing SELECT-only account.
-- Replace every REPLACE_ORDER_UUID with the same validated order UUID.
-- Confirm the intended database in the approved connection before running.
-- Stop on any error; do not use mysql --force. Close the session on failure.
-- No names, phones, addresses, free-text notes, payloads, tokens or provider references.
-- Internal numeric IDs are pseudonymous correlation keys; keep results restricted.
START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY;

-- 1. One target only. All following queries must use this same UUID.
SELECT COUNT(*) AS target_count
FROM orders WHERE uuid = 'REPLACE_ORDER_UUID';

SELECT id AS order_key, type, status, delivery_status, payment_method,
       payment_status, total, currency, branch_id AS branch_key,
       driver_id AS driver_key, placed_at, updated_at, driver_accepted_at,
       delivery_picked_up_at, delivery_on_the_way_at, delivery_delivered_at,
       delivery_failed_at, completed_at, cancelled_at,
       (cancellation_reason IS NOT NULL) AS has_cancellation_reason
FROM orders WHERE uuid = 'REPLACE_ORDER_UUID';

-- 2. Assignment/account flags; do not select personal identity fields.
SELECT u.id AS driver_key, u.is_active, u.driver_enabled, u.driver_available,
       u.driver_branch_id AS current_driver_branch_key,
       (u.driver_branch_id = o.branch_id) AS same_branch,
       (b.id IS NOT NULL AND b.deleted_at IS NULL) AS driver_branch_exists
FROM orders o LEFT JOIN users u ON u.id = o.driver_id
LEFT JOIN branches b ON b.id = u.driver_branch_id
WHERE o.uuid = 'REPLACE_ORDER_UUID';

-- 3. Exact active-task predicate used by the LOCAL backend, across all branches.
SELECT a.id AS blocking_order_key, a.branch_id AS branch_key, a.status,
       a.delivery_status, a.payment_method, a.payment_status, a.placed_at
FROM orders a JOIN orders target ON a.driver_id = target.driver_id
WHERE target.uuid = 'REPLACE_ORDER_UUID'
  AND a.delivery_status IN ('assigned', 'picked_up', 'on_the_way', 'failed')
  AND a.status NOT IN ('completed', 'cancelled')
ORDER BY a.id;

-- 4. Every payment attempt, including refunded amounts. No gateway references.
SELECT p.id AS payment_key, p.method, p.status, p.amount, p.currency,
       (p.provider IS NOT NULL) AS has_provider, p.refunded_amount,
       p.paid_at, p.failed_at, p.cancelled_at, p.created_at, p.updated_at
FROM payments p JOIN orders o ON o.id = p.order_id
WHERE o.uuid = 'REPLACE_ORDER_UUID' ORDER BY p.id;

SELECT t.id AS transaction_key, t.payment_id AS payment_key, t.type,
       t.status, t.amount, t.processed_at, t.created_at
FROM payment_transactions t JOIN payments p ON p.id = t.payment_id
JOIN orders o ON o.id = p.order_id
WHERE o.uuid = 'REPLACE_ORDER_UUID' ORDER BY t.id;

-- Events without a payment_id cannot be attributed to this order by this query.
-- Do not dump all webhook payloads to search for them.
SELECT e.id AS event_key, e.payment_id AS payment_key, e.signature_valid,
       e.status, (e.failure_message IS NOT NULL) AS has_failure,
       e.processed_at, e.created_at
FROM payment_webhook_events e JOIN payments p ON p.id = e.payment_id
JOIN orders o ON o.id = p.order_id
WHERE o.uuid = 'REPLACE_ORDER_UUID' ORDER BY e.id;

-- 5. Collection remains attributed to the collector, not the current assignee.
-- settlement_total may include collections for OTHER orders; do not equate it
-- to this order's collection amount.
SELECT c.id AS collection_key, c.driver_id AS collecting_driver_key,
       c.payment_id AS payment_key, c.amount, c.currency, c.collected_at,
       c.settlement_id AS settlement_key, c.settled_at,
       s.cashier_id AS cashier_key, s.amount AS settlement_total,
       s.currency AS settlement_currency, s.created_at AS settlement_created_at
FROM driver_cash_collections c JOIN orders o ON o.id = c.order_id
LEFT JOIN driver_cash_settlements s ON s.id = c.settlement_id
WHERE o.uuid = 'REPLACE_ORDER_UUID' ORDER BY c.id;

-- 6. Histories: flags only for free text, because it may contain personal data.
SELECT h.id AS history_key, h.from_status, h.to_status,
       h.changed_by AS actor_key, h.source, (h.note IS NOT NULL) AS has_note,
       h.created_at
FROM order_status_histories h JOIN orders o ON o.id = h.order_id
WHERE o.uuid = 'REPLACE_ORDER_UUID' ORDER BY h.id;

SELECT h.id AS history_key, h.driver_id AS driver_key,
       h.previous_driver_id AS previous_driver_key, h.changed_by AS actor_key,
       h.from_status, h.to_status, (h.reason IS NOT NULL) AS has_reason,
       h.created_at
FROM order_delivery_histories h JOIN orders o ON o.id = h.order_id
WHERE o.uuid = 'REPLACE_ORDER_UUID' ORDER BY h.id;

SELECT l.id AS audit_key, l.user_id AS actor_key, l.action, l.subject_type,
       (l.description IS NOT NULL) AS has_description, l.created_at
FROM admin_activity_logs l JOIN orders o ON o.uuid = l.subject_uuid
WHERE o.uuid = 'REPLACE_ORDER_UUID' AND l.subject_type = 'order'
ORDER BY l.id;

-- 7. Diagnostic predicates, NOT financial approval or proof of actual receipt.
SELECT o.status, o.delivery_status, o.payment_status,
       (o.status = 'out_for_delivery' AND o.delivery_status = 'on_the_way'
        AND o.payment_status = 'paid') AS local_delivery_state_gate_passes,
       EXISTS(SELECT 1 FROM payments p WHERE p.order_id = o.id
              AND p.status = 'paid' AND p.currency = o.currency
              AND p.amount = o.total) AS has_matching_paid_attempt,
       EXISTS(SELECT 1 FROM payment_transactions t
              JOIN payments p ON p.id = t.payment_id
              WHERE p.order_id = o.id AND t.type = 'sale'
                AND t.status = 'succeeded' AND t.amount = o.total
                AND p.currency = o.currency) AS has_matching_successful_sale
FROM orders o WHERE o.uuid = 'REPLACE_ORDER_UUID';

ROLLBACK;
