<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/EmailService.php';

final class NotificationService
{
    private const APP_URL = 'http://localhost/CA';

    public static function sendAnnouncement(PDO $pdo, int $announcementId): void
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
        $stmt->execute([$announcementId]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$announcement || (int)$announcement['is_published'] !== 1 || (int)$announcement['is_active'] !== 1) {
            return;
        }

        $recipients = self::recipientsForTarget(
            $pdo,
            (string)($announcement['target_audience'] ?? 'all'),
            (string)($announcement['target_campus'] ?? 'all'),
            self::decodeTargets($announcement['target_cultural_group'] ?? null)
        );
        $title = (string)$announcement['title'];
        $subject = 'OCA Announcement: ' . $title;
        $body = self::announcementBody($announcement);
        $key = 'announcement:' . $announcementId . ':' . hash('sha256', $title . '|' . (string)$announcement['content'] . '|' . (string)$announcement['publish_date']);
        self::deliver($pdo, $recipients, 'announcement', $announcementId, $subject, $body['html'], $body['text'], $key);
    }

    public static function sendEvent(PDO $pdo, int $eventId, string $notificationType = 'event_published', ?string $version = null): void
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event || !in_array((string)$event['status'], ['published', 'ongoing'], true)) {
            return;
        }

        $recipients = self::recipientsForTarget(
            $pdo,
            'students',
            (string)($event['campus'] ?? 'all'),
            self::decodeTargets($event['cultural_groups'] ?? null)
        );
        $title = (string)$event['title'];
        $subject = ($notificationType === 'event_updated' ? 'OCA Event Updated: ' : 'OCA Event: ') . $title;
        $body = self::eventBody($event, $notificationType === 'event_updated');
        $fingerprint = $version ?: hash('sha256', implode('|', [
            $title,
            (string)$event['description'],
            (string)$event['start_date'],
            (string)$event['end_date'],
            (string)($event['start_time'] ?? ''),
            (string)($event['end_time'] ?? ''),
            (string)$event['location'],
            (string)$event['campus'],
            (string)$event['cultural_groups']
        ]));
        $key = 'event:' . $eventId . ':' . $notificationType . ':' . $fingerprint;
        self::deliver($pdo, $recipients, $notificationType, $eventId, $subject, $body['html'], $body['text'], $key);
    }

    public static function sendApplicationDecision(PDO $pdo, int $applicationId, string $status): bool
    {
        self::ensureSchema($pdo);
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT id, email, first_name, middle_name, last_name, full_name, sr_code FROM applications WHERE id = ?');
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$application || !filter_var($application['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            error_log('Application decision email skipped: application has no valid recipient.');
            return false;
        }

        $name = trim(implode(' ', array_filter([
            $application['first_name'] ?? '',
            $application['middle_name'] ?? '',
            $application['last_name'] ?? ''
        ]))) ?: trim((string)($application['full_name'] ?? 'Student Artist')) ?: 'Student Artist';
        $approved = $status === 'approved';
        $subject = $approved
            ? 'Your OCA Student Artist Application Was Accepted'
            : 'Update on Your OCA Student Artist Application';
        $safeName = self::escape($name);
        $statusText = $approved ? 'accepted' : 'rejected';
        $statusHeading = $approved ? 'Application accepted' : 'Application rejected';
        $nextSteps = $approved
            ? 'An administrator must activate your account before you can sign in. After your account is activated, please wait a few minutes and then try signing in using your registered email address and your SR-code as the default password. You can change your password after signing in.'
            : 'You may reapply in the next semester. Please contact the Office of Culture and Arts if you need feedback about this decision.';
        $html = self::layout(
            '<h1>' . $statusHeading . '</h1>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your OCA Student Artist application has been <strong>' . $statusText . '</strong> by the Office of Culture and Arts.</p>'
            . '<p>' . self::escape($nextSteps) . '</p>'
            . '<p><a href="' . self::appUrl() . '/student/check-status.php">Check your application status</a></p>'
        );
        $text = "Hello {$name},\n\nYour OCA Student Artist application has been {$statusText} by the Office of Culture and Arts.\n\n{$nextSteps}\n\nCheck your application status at " . self::appUrl() . '/student/check-status.php';

        return self::deliver($pdo, [[
            'id' => null,
            'email' => strtolower(trim($application['email'])),
            'table' => 'applications',
            'first_name' => $application['first_name'] ?? null,
            'last_name' => $application['last_name'] ?? null
        ]], 'application_decision', $applicationId, $subject, $html, $text, 'application_decision:' . $applicationId . ':' . $status);
    }

    public static function sendAccountActivation(PDO $pdo, string $table, int $userId): bool
    {
        return self::sendAccountStatus($pdo, $table, $userId, 'account_activated');
    }

    public static function sendAccountSuspension(PDO $pdo, string $table, int $userId, ?string $reason = null): bool
    {
        return self::sendAccountStatus($pdo, $table, $userId, 'account_suspended', $reason);
    }

    private static function sendAccountStatus(PDO $pdo, string $table, int $userId, string $type, ?string $reason = null): bool
    {
        self::ensureSchema($pdo);
        if (!in_array($table, ['users', 'student_artists'], true)) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM \"$table\" WHERE id = ? AND status = '" . ($type === 'account_activated' ? 'active' : 'suspended') . "'");
        $stmt->execute([$userId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || !filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $name = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? '')) ?: 'OCA user';
        $subject = $type === 'account_activated'
            ? 'Your OCA Account Has Been Activated'
            : 'Important: Your OCA Account Has Been Suspended';
        $date = self::now()->format('F j, Y');
        $safeName = self::escape($name);
        $safeReason = self::escape(trim((string)$reason));
        if ($type === 'account_activated') {
            $html = '<h1>Account approved</h1><p>Hello ' . $safeName . ',</p><p>Your OCA Culture and Arts Management System account is now active.</p><p>You may sign in here: <a href="' . self::appUrl() . '/index.php">Open OCA login</a></p>';
            $text = "Hello {$name},\n\nYour OCA account is now active. Sign in at " . self::appUrl() . "/index.php";
        } else {
            $reasonHtml = $safeReason !== '' ? '<p>Reason: ' . $safeReason . '</p>' : '';
            $html = '<h1>Account suspended</h1><p>Hello ' . $safeName . ',</p><p>Your OCA account was suspended on ' . self::escape($date) . '.</p>' . $reasonHtml . '<p>Please contact the OCA administrator for assistance.</p>';
            $text = "Hello {$name},\n\nYour OCA account was suspended on {$date}." . ($reason ? "\nReason: {$reason}" : '') . "\nPlease contact the OCA administrator for assistance.";
        }
        $key = $type . ':' . $table . ':' . $userId . ':' . hash('sha256', $type . '|' . $date . '|' . (string)$reason);
        return self::deliver($pdo, [[
            'id' => (int)$account['id'],
            'email' => $account['email'],
            'table' => $table,
            'first_name' => $account['first_name'],
            'last_name' => $account['last_name']
        ]], $type, $userId, $subject, self::layout($html), $text, $key);
    }

    private static function recipientsForTarget(PDO $pdo, string $audience, string $campus, array $groups): array
    {
        $stmt = $pdo->query("SELECT id, email, first_name, last_name, campus, NULL::text AS cultural_group, role, 'users' AS source_table FROM users WHERE status = 'active' AND email IS NOT NULL AND email <> '' UNION ALL SELECT id, email, first_name, last_name, campus, cultural_group, 'student' AS role, 'student_artists' AS source_table FROM student_artists WHERE status = 'active' AND email IS NOT NULL AND email <> ''");
        $recipients = [];
        $seen = [];
        $audience = strtolower(trim($audience));
        $campus = strtolower(trim($campus));
        $groups = array_values(array_filter(array_map(static fn(mixed $value): string => strtolower(trim((string)$value)), $groups), static fn(string $value): bool => $value !== '' && $value !== 'all'));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
            $email = strtolower(trim((string)$recipient['email']));
            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $isStudent = $recipient['role'] === 'student' || $recipient['source_table'] === 'student_artists';
            if (in_array($audience, ['students', 'student'], true) && !$isStudent) {
                continue;
            }
            if (in_array($audience, ['staff', 'admins', 'administrators'], true) && $isStudent) {
                continue;
            }
            if ($campus !== '' && !in_array($campus, ['all', 'public'], true) && !self::campusMatches($campus, (string)$recipient['campus'])) {
                continue;
            }
            if ($groups !== [] && !in_array(strtolower(trim((string)$recipient['cultural_group'])), $groups, true)) {
                continue;
            }
            $seen[$email] = true;
            $recipient['email'] = $email;
            $recipients[] = $recipient;
        }
        return $recipients;
    }

    private static function deliver(PDO $pdo, array $recipients, string $type, int $relatedId, string $subject, string $html, string $text, string $key): bool
    {
        $allSent = true;
        foreach ($recipients as $recipient) {
            $recipientKey = hash('sha256', $key . ':' . strtolower($recipient['email']));
            $stmt = $pdo->prepare('INSERT INTO notification_logs (recipient_email, recipient_user_id, recipient_table, notification_type, related_id, subject, notification_key, status) VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\') ON CONFLICT (notification_key) DO NOTHING RETURNING id');
            $stmt->execute([$recipient['email'], $recipient['id'] ?? null, $recipient['table'] ?? null, $type, $relatedId, $subject, $recipientKey]);
            $logId = $stmt->fetchColumn();
            if (!$logId) {
                continue;
            }
            try {
                EmailService::sendNotification($recipient['email'], $subject, $html, $text);
                $sentAt = self::now()->format('Y-m-d H:i:s');
                $pdo->prepare("UPDATE notification_logs SET status = 'sent', sent_at = ? WHERE id = ?")->execute([$sentAt, $logId]);
            } catch (Throwable $exception) {
                $allSent = false;
                $pdo->prepare("UPDATE notification_logs SET status = 'failed', error_message = ? WHERE id = ?")->execute([substr($exception->getMessage(), 0, 1000), $logId]);
                error_log('Notification delivery failed: ' . $exception->getMessage());
            }
        }
        return $allSent;
    }

    private static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_logs (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, recipient_email varchar(255) NOT NULL, recipient_user_id integer, recipient_table varchar(32), notification_type varchar(50) NOT NULL, related_id integer, subject varchar(255) NOT NULL, notification_key varchar(255) NOT NULL UNIQUE, status varchar(20) NOT NULL DEFAULT 'pending', error_message text, sent_at timestamp, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notification_logs_recipient ON notification_logs(recipient_email)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notification_logs_related ON notification_logs(notification_type, related_id)');
    }

    private static function decodeTargets(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [(string)$value];
    }

    private static function campusMatches(string $target, string $recipient): bool
    {
        $aliases = [
            'malvar' => 'jplpc malvar',
            'jplpc malvar' => 'jplpc malvar',
            'nasugbu' => 'arasof nasugbu',
            'arasof nasugbu' => 'arasof nasugbu'
        ];
        $target = strtolower(trim($target));
        $recipient = strtolower(trim($recipient));
        return ($aliases[$target] ?? $target) === ($aliases[$recipient] ?? $recipient);
    }

    private static function announcementBody(array $announcement): array
    {
        $title = self::escape((string)$announcement['title']);
        $content = nl2br(self::escape((string)$announcement['content']));
        $category = self::escape((string)($announcement['category'] ?? 'General'));
        $date = self::escape(self::now()->format('F j, Y g:i A'));
        $priority = self::escape(ucfirst((string)($announcement['priority'] ?? 'medium')));
        $audience = self::escape(ucfirst((string)($announcement['target_audience'] ?? 'all')));
        $campus = self::escape((string)($announcement['target_campus'] ?? 'all') === 'all' ? 'All campuses' : (string)$announcement['target_campus']);
        $url = self::appUrl() . '/student/dashboard.php#announcements';
        return ['html' => self::layout('<h1>' . $title . '</h1><p><strong>Category:</strong> ' . $category . '<br><strong>Priority:</strong> ' . $priority . '<br><strong>Published:</strong> ' . $date . '<br><strong>Audience:</strong> ' . $audience . '<br><strong>Campus:</strong> ' . $campus . '</p><p>' . $content . '</p><p><a href="' . $url . '">View the complete announcement</a></p>'), 'text' => "{$announcement['title']}\nCategory: {$category}\nPriority: {$priority}\nPublished: {$date}\nAudience: {$audience}\nCampus: {$campus}\n\n{$announcement['content']}\n\nView: {$url}"];
    }

    private static function eventBody(array $event, bool $updated): array
    {
        $title = self::escape((string)$event['title']);
        $description = nl2br(self::escape((string)$event['description']));
        $date = self::escape((string)$event['start_date'] . ((string)$event['end_date'] !== (string)$event['start_date'] ? ' to ' . $event['end_date'] : ''));
        $time = trim((string)($event['start_time'] ?? '') . ((string)($event['end_time'] ?? '') !== '' ? ' to ' . $event['end_time'] : ''));
        $timeHtml = $time !== '' ? '<br><strong>Time:</strong> ' . self::escape($time) : '';
        $location = self::escape((string)($event['location'] ?: $event['venue']));
        $requirements = trim((string)($event['requirements'] ?? ''));
        $registration = !empty($event['allow_registration']) ? 'Registration is available in the system.' : 'Registration is not required for this event.';
        if (!empty($event['registration_deadline'])) {
            $registration .= ' Registration deadline: ' . $event['registration_deadline'] . '.';
        }
        $url = self::appUrl() . '/student/dashboard.php#events';
        $heading = $updated ? 'Event updated' : 'New OCA event';
        $requirementsHtml = $requirements !== '' ? '<p><strong>Instructions:</strong><br>' . nl2br(self::escape($requirements)) . '</p>' : '';
        return ['html' => self::layout('<h1>' . $heading . '</h1><h2>' . $title . '</h2><p><strong>Date:</strong> ' . $date . $timeHtml . '<br><strong>Location:</strong> ' . $location . '</p><p>' . $description . '</p>' . $requirementsHtml . '<p>' . self::escape($registration) . '</p><p><a href="' . $url . '">View event details</a></p>'), 'text' => "{$heading}\n\n{$event['title']}\nDate: {$date}\n" . ($time !== '' ? "Time: {$time}\n" : '') . "Location: {$location}\n\n{$event['description']}\n" . ($requirements !== '' ? "\nInstructions: {$requirements}\n" : '') . "\n{$registration}\n\nView: {$url}"];
    }

    private static function layout(string $content): string
    {
        return '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
            . '<body style="margin:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#243047">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:24px 12px">'
            . '<tr><td align="center"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4e7ec">'
            . '<tr><td style="background:#c93636;padding:24px 28px;color:#ffffff"><div style="font-size:22px;font-weight:bold">OCA Notification System</div><div style="font-size:13px;margin-top:5px;opacity:.9">Culture and Arts Management System</div></td></tr>'
            . '<tr><td style="padding:32px 28px;font-size:15px;line-height:1.6">' . $content . '</td></tr>'
            . '<tr><td style="border-top:1px solid #edf0f3;padding:18px 28px;color:#7a8492;font-size:12px;line-height:1.5">This is an automated message from the OCA Notification System. Please do not reply.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function appUrl(): string
    {
        return rtrim(getenv('OCA_APP_URL') ?: self::APP_URL, '/');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
