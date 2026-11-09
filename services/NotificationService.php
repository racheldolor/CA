<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/EmailService.php';

final class NotificationService
{
    private const APP_URL = 'http://localhost/capstone';

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

    public static function sendAccountActivation(PDO $pdo, string $table, int $userId): void
    {
        self::sendAccountStatus($pdo, $table, $userId, 'account_activated');
    }

    public static function sendAccountSuspension(PDO $pdo, string $table, int $userId, ?string $reason = null): void
    {
        self::sendAccountStatus($pdo, $table, $userId, 'account_suspended', $reason);
    }

    private static function sendAccountStatus(PDO $pdo, string $table, int $userId, string $type, ?string $reason = null): void
    {
        self::ensureSchema($pdo);
        if (!in_array($table, ['users', 'student_artists'], true)) {
            return;
        }
        $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM \"$table\" WHERE id = ? AND status = '" . ($type === 'account_activated' ? 'active' : 'suspended') . "'");
        $stmt->execute([$userId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || !filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $name = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? '')) ?: 'OCA user';
        $subject = $type === 'account_activated' ? 'Your OCA account is now active' : 'Your OCA account has been suspended';
        $date = date('F j, Y');
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
        self::deliver($pdo, [[
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
        $groups = array_values(array_filter(array_map(static fn($value): string => strtolower(trim((string)$value)), $groups), static fn(string $value): bool => $value !== '' && $value !== 'all'));
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

    private static function deliver(PDO $pdo, array $recipients, string $type, int $relatedId, string $subject, string $html, string $text, string $key): void
    {
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
                $pdo->prepare("UPDATE notification_logs SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$logId]);
            } catch (Throwable $exception) {
                $pdo->prepare("UPDATE notification_logs SET status = 'failed', error_message = ? WHERE id = ?")->execute([substr($exception->getMessage(), 0, 1000), $logId]);
                error_log('Notification delivery failed: ' . $exception->getMessage());
            }
        }
    }

    private static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_logs (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, recipient_email varchar(255) NOT NULL, recipient_user_id integer, recipient_table varchar(32), notification_type varchar(50) NOT NULL, related_id integer, subject varchar(255) NOT NULL, notification_key varchar(255) NOT NULL UNIQUE, status varchar(20) NOT NULL DEFAULT 'pending', error_message text, sent_at timestamp, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notification_logs_recipient ON notification_logs(recipient_email)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notification_logs_related ON notification_logs(notification_type, related_id)');
    }

    private static function decodeTargets($value): array
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
        $publishedAt = strtotime((string)($announcement['publish_date'] ?? ''));
        $date = self::escape($publishedAt ? date('F j, Y', $publishedAt) : date('F j, Y'));
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
        return '<!doctype html><html><body style="margin:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#243047"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;background:#f5f6f8"><tr><td align="center"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #e4e7ec"><tr><td style="background:#c93636;padding:24px 28px;color:#fff;font-size:22px;font-weight:bold">OCA Notification System</td></tr><tr><td style="padding:30px 28px;font-size:15px;line-height:1.6">' . $content . '</td></tr><tr><td style="border-top:1px solid #edf0f3;padding:18px 28px;color:#7a8492;font-size:12px">This is an automated message from the OCA Notification System.</td></tr></table></td></tr></table></body></html>';
    }

    private static function appUrl(): string
    {
        return rtrim(getenv('OCA_APP_URL') ?: self::APP_URL, '/');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
