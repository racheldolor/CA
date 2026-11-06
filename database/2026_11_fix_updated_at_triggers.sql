-- Remove updated_at triggers from tables that do not have an updated_at column.
-- Run once against the Supabase PostgreSQL database.
DO $$
DECLARE
    table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'event_evaluations',
        'evaluation_summary',
        'question_analysis',
        'deleted_students',
        'admin_logs',
        'otp_challenges'
    ]
    LOOP
        IF to_regclass(format('public.%I', table_name)) IS NOT NULL THEN
            EXECUTE format('DROP TRIGGER IF EXISTS %I_updated_at ON public.%I', table_name, table_name);
        END IF;
    END LOOP;
END $$;
