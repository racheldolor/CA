<?php
/**
 * Non-destructive MySQL/MariaDB to Supabase PostgreSQL data transfer.
 *
 * Required environment variables:
 *   SOURCE_DB_HOST, SOURCE_DB_PORT, SOURCE_DB_DATABASE, SOURCE_DB_USERNAME, SOURCE_DB_PASSWORD
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_SSLMODE
 *
 * Run from the project root after applying supabase_schema.sql:
 *   php database/migrate_mysql_to_supabase.php
 *
 * The script preserves source IDs, never drops source data, and writes a JSON
 * row-count report to database/migration_validation.json.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$sourceRequired = [
    'SOURCE_DB_HOST',
    'SOURCE_DB_DATABASE',
    'SOURCE_DB_USERNAME',
    'SOURCE_DB_PASSWORD',
];

foreach ($sourceRequired as $name) {
    if (getenv($name) === false) {
        fwrite(STDERR, "Missing required source variable: {$name}\n");
        exit(1);
    }
}

$sourceDsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('SOURCE_DB_HOST'),
    (int)(getenv('SOURCE_DB_PORT') ?: 3306),
    getenv('SOURCE_DB_DATABASE')
);

try {
    $source = new PDO($sourceDsn, getenv('SOURCE_DB_USERNAME'), getenv('SOURCE_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $destination = getDBConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    exit(1);
}

$tables = [
    'users', 'student_artists', 'announcements', 'applications',
    'application_affiliations', 'application_competition', 'application_participation',
    'events', 'event_participants', 'event_evaluations', 'evaluation_summary',
    'question_analysis', 'inventory', 'borrowing_requests', 'return_requests',
    'repair_items', 'student_certificates', 'student_affiliation_records',
    'student_competition_records', 'student_participation_records', 'deleted_students',
    'system_settings', 'admin_logs', 'otp_challenges',
];

$report = [
    'generated_at' => gmdate('c'),
    'tables' => [],
];

foreach ($tables as $table) {
    $tableExists = (bool)$source->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $source->quote($table)
    )->fetchColumn();
    if (!$tableExists) {
        $report['tables'][$table] = [
            'source_rows' => null,
            'inserted_rows' => 0,
            'destination_rows_after' => null,
            'status' => 'skipped_source_table_missing',
        ];
        printf("%-35s skipped (source table missing)\n", $table);
        continue;
    }

    $sourceColumns = $source->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $destinationColumns = $destination->query(
        'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ' . $destination->quote($table)
    )->fetchAll(PDO::FETCH_COLUMN);
    $columns = array_values(array_intersect($sourceColumns, $destinationColumns));

    if ($columns === []) {
        throw new RuntimeException("No shared columns found for {$table}.");
    }

    $quotedColumns = implode(', ', array_map(static fn(string $column): string => '"' . str_replace('"', '""', $column) . '"', $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $insert = $destination->prepare("INSERT INTO \"{$table}\" ({$quotedColumns}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING");
    $rows = $source->query("SELECT `" . implode('`, `', $columns) . "` FROM `{$table}`");
    $sourceCount = 0;
    $inserted = 0;

    $destination->beginTransaction();
    try {
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $sourceCount++;
            $insert->execute($row);
            $inserted += $insert->rowCount();
        }
        $destination->commit();
    } catch (Throwable $e) {
        $destination->rollBack();
        throw $e;
    }

    $destinationCount = (int)$destination->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
    $report['tables'][$table] = [
        'source_rows' => $sourceCount,
        'inserted_rows' => $inserted,
        'destination_rows_after' => $destinationCount,
        'status' => $destinationCount >= $sourceCount ? 'ok' : 'review',
    ];
    printf("%-35s source=%d inserted=%d destination=%d\n", $table, $sourceCount, $inserted, $destinationCount);

    $destination->exec("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT COALESCE(MAX(id), 1) FROM \"{$table}\"), 1), true)");
}

$reportPath = __DIR__ . '/migration_validation.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
printf("Validation report written to %s\n", $reportPath);
