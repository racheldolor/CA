<?php
/**
 * Export the local source database as PostgreSQL INSERT SQL.
 * This is for manual paste into Supabase after supabase_schema.sql.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$sourceDsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('SOURCE_DB_HOST') ?: '127.0.0.1',
    (int)(getenv('SOURCE_DB_PORT') ?: 3306),
    getenv('SOURCE_DB_DATABASE') ?: 'capstone_culture_arts'
);
$source = new PDO($sourceDsn, getenv('SOURCE_DB_USERNAME') ?: 'root', getenv('SOURCE_DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tables = [
    'users', 'student_artists', 'announcements', 'applications',
    'application_affiliations', 'application_competition', 'application_participation',
    'events', 'event_participants', 'event_evaluations', 'evaluation_summary',
    'question_analysis', 'inventory', 'borrowing_requests', 'return_requests',
    'repair_items', 'student_certificates', 'student_affiliation_records',
    'student_competition_records', 'student_participation_records', 'deleted_students',
    'system_settings', 'admin_logs', 'otp_challenges',
];

$quoteIdentifier = static fn(string $name): string => '"' . str_replace('"', '""', $name) . '"';
$quoteValue = static function (mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return "E'" . str_replace(["\\", "'", "\0"], ["\\\\", "''", ''], (string)$value) . "'";
};

$output = [
    '-- Generated from the local capstone_culture_arts database.',
    '-- Paste after database/supabase_schema.sql. Review counts before committing.',
    'BEGIN;',
];
$counts = [];

foreach ($tables as $table) {
    $exists = (bool)$source->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $source->quote($table)
    )->fetchColumn();
    if (!$exists) {
        continue;
    }

    $sourceColumns = $source->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    // The PostgreSQL schema preserves the source table and column names.
    $columns = $sourceColumns;

    $rows = $source->query("SELECT `" . implode('`, `', $columns) . "` FROM `{$table}`");
    $count = 0;
    $output[] = '';
    $output[] = '-- ' . $table;
    while ($row = $rows->fetch(PDO::FETCH_NUM)) {
        $values = implode(', ', array_map($quoteValue, $row));
        $output[] = 'INSERT INTO ' . $quoteIdentifier($table) . ' (' . implode(', ', array_map($quoteIdentifier, $columns)) . ') VALUES (' . $values . ') ON CONFLICT DO NOTHING;';
        $count++;
    }
    $counts[$table] = $count;
}

$output[] = '';
$output[] = '-- Synchronize generated identity sequences after preserved IDs are inserted.';
foreach (array_keys($counts) as $table) {
    $output[] = "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT COALESCE(MAX(id), 1) FROM \"{$table}\"), 1), true);";
}
$output[] = 'COMMIT;';

file_put_contents(__DIR__ . '/supabase_data.sql', implode(PHP_EOL, $output) . PHP_EOL);
file_put_contents(__DIR__ . '/supabase_data_counts.json', json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
foreach ($counts as $table => $count) {
    printf("%-35s %d\n", $table, $count);
}
