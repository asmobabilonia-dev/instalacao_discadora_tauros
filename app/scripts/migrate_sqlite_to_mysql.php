<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (!Database::isMysql()) {
    fwrite(STDERR, "Configure o driver mysql antes de migrar.\n");
    exit(1);
}

$config = require __DIR__ . '/../config/config.php';
$sqlitePath = $config['database']['sqlite_path'] ?? (__DIR__ . '/../data/app.sqlite');
if (!is_file($sqlitePath)) {
    fwrite(STDERR, "SQLite nao encontrado em {$sqlitePath}\n");
    exit(1);
}

$mysql = Database::conn();
$sqlite = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tables = [
    'users',
    'settings',
    'sip_accounts',
    'extensions',
    'campaigns',
    'call_jobs',
    'audit_logs',
    'agent_call_logs',
    'auto_agendas',
    'auto_agenda_numbers',
    'auto_audios',
    'campaign_extensions',
    'magnus_rates',
    'credit_transactions',
];

$quote = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
$sqliteTables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$mysqlTables = $mysql->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

$mysql->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (array_reverse($tables) as $table) {
    if (in_array($table, $mysqlTables, true)) {
        $mysql->exec('TRUNCATE TABLE ' . $quote($table));
    }
}
$mysql->exec('SET FOREIGN_KEY_CHECKS=1');

$imported = [];
foreach ($tables as $table) {
    if (!in_array($table, $sqliteTables, true) || !in_array($table, $mysqlTables, true)) {
        continue;
    }

    $sqliteColumns = array_map(static fn(array $row): string => (string)$row['name'], $sqlite->query('PRAGMA table_info(' . $table . ')')->fetchAll());
    $mysqlColumns = array_map(static fn(array $row): string => (string)$row['Field'], $mysql->query('DESCRIBE ' . $quote($table))->fetchAll());
    $columns = array_values(array_intersect($sqliteColumns, $mysqlColumns));
    if (!$columns) {
        continue;
    }

    $rows = $sqlite->query('SELECT ' . implode(', ', array_map(static fn(string $col): string => '"' . str_replace('"', '""', $col) . '"', $columns)) . ' FROM "' . str_replace('"', '""', $table) . '"')->fetchAll();
    if (!$rows) {
        $imported[$table] = 0;
        continue;
    }

    $sql = 'INSERT INTO ' . $quote($table)
        . ' (' . implode(', ', array_map($quote, $columns)) . ') VALUES '
        . '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $stmt = $mysql->prepare($sql);
    $count = 0;
    foreach ($rows as $row) {
        $stmt->execute(array_map(static fn(string $col): mixed => $row[$col] ?? null, $columns));
        $count++;
    }
    $imported[$table] = $count;
}

run_migrations($config);

foreach ($imported as $table => $count) {
    echo "{$table}\t{$count}\n";
}

