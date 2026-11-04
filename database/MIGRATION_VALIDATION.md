# Supabase PostgreSQL Migration

## Implemented

- `config/database.php` now provides one environment-based PDO PostgreSQL connection.
- `database/supabase_schema.sql` contains the 23 tables from `FINALDATABASE.sql` plus `otp_challenges`, indexes, foreign keys, unique constraints, identity columns, and `updated_at` triggers.
- `database/supabase_data.sql` contains the real source records as PostgreSQL `INSERT` statements for manual paste after the schema.
- MySQLi dependencies and active MySQL SQL syntax were removed from the application paths.
- Existing SMTP configuration in `config/mail.php` and `config/secrets.php` was left unchanged.
- `database/migrate_mysql_to_supabase.php` transfers shared columns without deleting source data, preserves IDs, skips optional source tables that do not exist, and writes row-count results to `database/migration_validation.json`.

## Local environment

Create a local `.env` file, which is ignored by Git:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=db.zxjvyaogmjwgeubghomx.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=<Supabase database password>
DB_SSLMODE=require

SOURCE_DB_HOST=127.0.0.1
SOURCE_DB_PORT=3306
SOURCE_DB_DATABASE=capstone_culture_arts
SOURCE_DB_USERNAME=<source database user>
SOURCE_DB_PASSWORD=<source database password>
```

Use the database password from Supabase, not an anon key or service-role key. Keep the real values only in `.env` or the hosting secret store. The committed `.env.example` contains placeholders and leaves the existing SMTP values unchanged.

## Migration order

1. Apply `database/supabase_schema.sql` to the empty Supabase database.
2. Apply `database/supabase_data.sql` in the same SQL Editor session.
3. Verify the destination schema and required extensions/roles.
4. Review the counts in `database/supabase_data_counts.json` and compare every destination row count.
5. Run the application smoke tests before changing production traffic.

The two paste files are therefore, in order: `database/supabase_schema.sql`, then `database/supabase_data.sql`. The generated data file is ignored by Git because it contains real application records.

The transfer script uses `ON CONFLICT DO NOTHING`, so it is safe to rerun for already imported IDs. It does not drop, truncate, overwrite, or delete source records.

## Validation performed in this workspace

- PostgreSQL schema applied successfully to a fresh local PostgreSQL 17 validation database.
- The schema was applied a second time successfully, confirming repeatable DDL.
- PHP syntax checks passed for all 317 non-vendor PHP files.
- Workspace diagnostics reported no errors.
- The transfer script was executed against the local MariaDB source and isolated PostgreSQL validation database; all 23 legacy tables and `otp_challenges` matched source/destination row counts. The resulting local report is `database/migration_validation.json`.
- The local source database and migration code are ready, but the live Supabase pooler connection timed out repeatedly on ports 5432 and 6543 during the final transfer attempt. The schema/data were therefore not claimed as live-migrated.
- The bundled XAMPP PHP `pdo_pgsql` extension is enabled locally. Ensure the same extension is enabled on the deployment runtime.

## Cutover checks

After installing `pdo_pgsql` and configuring both database connections, verify login, registration/OTP, approvals, student profiles, events, announcements, inventory borrowing/returns, reports, PDF exports, search, pagination, and admin logs. Do not treat the migration as production-complete until the row-count report and these workflow checks pass against Supabase.
