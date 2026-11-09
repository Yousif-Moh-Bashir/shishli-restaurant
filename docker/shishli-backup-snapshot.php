<?php

// Generate read-only SQL or fingerprint its JSONL results; never bootstrap Laravel.
function snapshotPlan(array $columns): string
{
    $tables = [];
    foreach ($columns as $column) {
        if (!is_array($column) || count($column) !== 6) { throw new RuntimeException('Invalid discovery.'); }
        [$table, $type, $name, $ordinal, $dataType, $nullable] = $column;
        if (!is_string($table) || !is_string($name) || !preg_match('/^[a-z0-9_]+\z/i', $table)
            || !preg_match('/^[a-z0-9_]+\z/i', $name) || !in_array($type, ['BASE TABLE','VIEW'], true)) {
            throw new RuntimeException('Schema identifier requires review.');
        }
        $tables[$table]['type'] = $type;
        $tables[$table]['columns'][$name] = [$dataType, $nullable];
    }
    $required = ['branches','products','categories','product_images','branch_products','option_groups','option_values',
        'product_option_groups','users','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions','restaurant_settings'];
    foreach ($required as $table) {
        if (($tables[$table]['type'] ?? null) !== 'BASE TABLE') { throw new RuntimeException('Required table absent.'); }
    }
    foreach (['id','product_id','disk','path'] as $field) {
        $definition = $tables['product_images']['columns'][$field] ?? null;
        if ($definition === null || $definition[1] !== 'NO'
            || !in_array($definition[0], in_array($field, ['id','product_id'], true) ? ['bigint','int'] : ['varchar','char','text'], true)) {
            throw new RuntimeException('Unsupported product_images schema.');
        }
    }
    $sql = "SET time_zone='+00:00';\nSET TRANSACTION ISOLATION LEVEL REPEATABLE READ;\nSTART TRANSACTION READ ONLY;\nSELECT JSON_ARRAY('start','1',JSON_ARRAY());\n";
    $metadata = [
        'database' => "SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()",
        'tables' => "SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()",
        'columns' => "SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,HEX(COLUMN_DEFAULT),EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()",
        'indexes' => "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,COLLATION,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()",
        'foreign_keys' => "SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE()",
        'views' => "SELECT TABLE_NAME,VIEW_DEFINITION,CHECK_OPTION,IS_UPDATABLE,DEFINER,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()",
        'routines' => "SELECT ROUTINE_NAME,ROUTINE_TYPE,DATA_TYPE,ROUTINE_DEFINITION,SQL_DATA_ACCESS,IS_DETERMINISTIC,SECURITY_TYPE,DEFINER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()",
        'triggers' => "SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT,DEFINER,SQL_MODE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()",
        'events' => "SELECT EVENT_NAME,EVENT_DEFINITION,EVENT_TYPE,EXECUTE_AT,INTERVAL_VALUE,INTERVAL_FIELD,STARTS,ENDS,STATUS,ON_COMPLETION,DEFINER,SQL_MODE,TIME_ZONE FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()",
    ];
    foreach ($metadata as $name => $query) {
        [$select, $from] = explode(' FROM ', substr($query, 7), 2);
        $sql .= "SELECT JSON_ARRAY('schema','$name',JSON_ARRAY($select)) FROM $from;\n";
    }
    foreach ($tables as $table => $definition) {
        if ($definition['type'] !== 'BASE TABLE') { continue; }
        $names = array_keys($definition['columns']);
        $identifier = '`'.str_replace('`', '``', $table).'`';
        $literal = "'".str_replace("'", "''", $table)."'";
        $fields = array_map(static function (string $name): string {
            $column = '`'.str_replace('`', '``', $name).'`';
            return "IF($column IS NULL,NULL,HEX(CAST($column AS BINARY)))";
        }, $names);
        $header = str_replace("'", "''", json_encode($names, JSON_THROW_ON_ERROR));
        $sql .= "SELECT JSON_ARRAY('table',$literal,CAST('$header' AS JSON));\n";
        $sql .= "SELECT JSON_ARRAY('row',$literal,JSON_ARRAY(".implode(',', $fields).")) FROM $identifier;\n";
    }
    return $sql."SELECT JSON_ARRAY('end','1',JSON_ARRAY());\nROLLBACK;\n";
}

function snapshotDigest(iterable $lines, string $key): array
{
    if (!preg_match('/^[a-f0-9]{64}\z/', trim($key))) { throw new RuntimeException('Invalid private reference key.'); }
    $schemas = []; $tables = []; $started = false; $ended = false;
    $hash = static fn (mixed $value): string => hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), trim($key));
    foreach ($lines as $line) {
        if (trim($line) === '') { continue; }
        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($record) || count($record) !== 3 || !is_array($record[2]) || $ended) { throw new RuntimeException('Invalid snapshot protocol.'); }
        [$kind, $name, $value] = $record;
        if ($kind === 'start' && !$started) { $started = true; continue; }
        if (!$started) { throw new RuntimeException('Missing snapshot start.'); }
        if ($kind === 'end') { $ended = true; continue; }
        if ($kind === 'schema') {
            $definitionIndex = ['views'=>1,'routines'=>3,'triggers'=>4,'events'=>1][$name] ?? null;
            if ($definitionIndex !== null && ($value[$definitionIndex] ?? null) === null) { throw new RuntimeException('Schema definition is not readable.'); }
            $schemas[$name][] = $hash($value);
        } elseif ($kind === 'table' && !isset($tables[$name])) {
            $tables[$name] = ['columns' => $value, 'rows' => []];
        } elseif ($kind === 'row' && isset($tables[$name]) && count($value) === count($tables[$name]['columns'])) {
            foreach ($value as &$cell) {
                if ($cell !== null && (!is_string($cell) || !preg_match('/^(?:[a-f0-9]{2})*\z/i', $cell))) { throw new RuntimeException('Invalid byte representation.'); }
                if (is_string($cell)) { $cell = strtoupper($cell); }
            }
            unset($cell);
            $tables[$name]['rows'][] = $hash($value);
        } else { throw new RuntimeException('Unexpected snapshot record.'); }
    }
    if (!$ended || !isset($tables['product_images']) || !isset($schemas['columns'], $schemas['tables'])) { throw new RuntimeException('Incomplete snapshot.'); }
    foreach ($schemas as &$values) { sort($values, SORT_STRING); $values = $hash($values); }
    unset($values);
    foreach ($tables as &$table) {
        sort($table['rows'], SORT_STRING);
        $table = ['rows' => count($table['rows']), 'hmac_sha256' => $hash([$table['columns'], $table['rows']])];
    }
    unset($table);
    ksort($schemas, SORT_STRING); ksort($tables, SORT_STRING);
    return ['schema' => $schemas, 'tables' => $tables];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (($argv[1] ?? '') === 'plan') {
            $lines = array_filter(explode("\n", stream_get_contents(STDIN)), static fn ($s) => trim($s) !== '');
            echo snapshotPlan(array_map(static fn ($s) => json_decode($s, true, flags: JSON_THROW_ON_ERROR), $lines));
        } elseif (($argv[1] ?? '') === 'digest') {
            $lines = (static function (): Generator { while (($line = fgets(STDIN)) !== false) { yield $line; } })();
            echo json_encode(snapshotDigest($lines, file_get_contents($argv[2] ?? '/review/reference.key')), JSON_THROW_ON_ERROR);
        } else { throw new RuntimeException('Unknown mode.'); }
    } catch (Throwable) {
        fwrite(STDERR, "Structured snapshot failed: schema, permissions, or input requires review.\n");
        exit(1);
    }
}
