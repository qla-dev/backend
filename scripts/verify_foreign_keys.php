<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/verify_foreign_keys.php <sqlite-database>\n");
    exit(2);
}

$pdo = new PDO('sqlite:'.$argv[1]);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
$missing = [];
$foreignKeyCount = 0;

foreach ($tables as $table) {
    $columns = $pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC);
    $foreignKeys = $pdo->query("PRAGMA foreign_key_list(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC);
    $foreignKeyColumns = array_column($foreignKeys, 'from');
    $foreignKeyCount += count($foreignKeys);

    foreach ($columns as $column) {
        $columnName = $column['name'];
        $isIdentifierRatherThanRelation = $columnName === 'public_id';
        $isSanctumPolymorphicKey = $table === 'personal_access_tokens' && $columnName === 'tokenable_id';
        if (str_ends_with($columnName, '_id') && ! in_array($columnName, $foreignKeyColumns, true) && ! $isIdentifierRatherThanRelation && ! $isSanctumPolymorphicKey) {
            $missing[] = "{$table}.{$columnName}";
        }
    }
}

echo 'tables='.count($tables).PHP_EOL;
echo "foreign_keys={$foreignKeyCount}".PHP_EOL;
echo 'unconstrained_business_ids='.($missing === [] ? 'none' : implode(',', $missing)).PHP_EOL;

exit($missing === [] ? 0 : 1);
