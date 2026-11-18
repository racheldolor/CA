<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

function chatbotResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function chatbotFallbackAnswer(string $message): string
{
    $question = strtolower($message);

    if (preg_match('/\b(password|passcode)\b.*\b(change|changed|reset|recover|forgot|lost)\b|\b(change|reset|recover|forgot|lost)\b.*\b(password|passcode)\b/', $question)) {
        return 'To recover a forgotten password, open the sign-in page and select "Recover it here" under Forgot your password. Enter your registered email address; if an eligible active account exists, the system sends a six-digit verification code that expires in five minutes. Enter the code, then choose a new password that is at least 8 characters and includes uppercase, lowercase, numbers, and special characters.';
    }

    if (preg_match('/\b(announcement|announcements|notice|notices|news)\b/', $question)) {
        return 'Sign in to the Student Dashboard and open Announcements to view current OCA notices. Published announcements may depend on your campus or cultural group, so use the notices shown in your own account.';
    }

    if (preg_match('/\b(application status|check status|status of my application|submitted.*application|application.*(check|status)|otp|one[- ]time password|verification code)\b/', $question)) {
        return 'For a Student Artist application, use the View status here link on the login page and enter your SR Code. Email verification uses a six-digit OTP, but verification does not mean the application is approved; wait for OCA review.';
    }

    if (preg_match('/\b(student artist|performer|register|registration|application|apply)\b/', $question)) {
        return 'To apply as a Student Artist, use Register here on the login page, complete the Performer\'s Profile Form, verify your BatStateU G-Suite email with the six-digit OTP, and wait for OCA review. Check application status using the View status here link and your SR Code.';
    }

    if (preg_match('/\b(profile|personal information|details|edit)\b/', $question)) {
        return 'Sign in to the Student Dashboard, open Profile, choose Edit Profile, update the available information, and save it. Contact OCA through the official school support channel if a field cannot be changed.';
    }

    if (preg_match('/\b(borrow|borrowing|costume|inventory|item|return)\b/', $question)) {
        return 'For costumes or inventory, open Costume Borrowing in the Student Dashboard. You can submit a request, view its status, and follow the available return process. OCA review and confirmation are required.';
    }

    if (preg_match('/\b(event|activity|activities)\b.*\b(not showing|missing|cannot see|can.t see|not visible)\b|\b(not showing|missing|cannot see|can.t see|not visible)\b.*\b(event|activity|activities)\b/', $question)) {
        return 'An event may not appear when it is not published, has ended, or does not match your campus or cultural group. Check the Events and Announcements sections of your Student Dashboard. If an eligible published event is still missing, contact OCA.';
    }

    if (preg_match('/\b(events?|activity|activities|announcements?|program|join)\b/', $question)) {
        return 'For current OCA activities and announcements, sign in to the Student Dashboard and open the Events or Announcements section. You will see published opportunities available for your campus and cultural group. Select Join Event when that option is available.';
    }

    if (preg_match('/\b(participat|performance|joined|history|record)\b/', $question)) {
        return 'Open the Profile or participation area in the Student Dashboard to review your recorded event participation and performance information. If a record is missing, contact OCA.';
    }

    if (preg_match('/\b(campus|campuses)\b/', $question)) {
        return 'The supported campuses are Pablo Borbon, Alangilan, Lipa, JPLPC Malvar, and ARASOF Nasugbu. Event visibility may depend on your campus and cultural group.';
    }

    if (preg_match('/\b(event|activity|activities)\b.*\b(not showing|missing|cannot see|can.t see|not visible)\b|\b(not showing|missing|cannot see|can.t see|not visible)\b.*\b(event|activity|activities)\b/', $question)) {
        return 'An event may not appear when it is not published, has ended, or does not match your campus or cultural group. Check the Events and Announcements sections of your Student Dashboard. If an eligible published event is still missing, contact OCA.';
    }

    if (preg_match('/\b(student dashboard|dashboard|sections|features)\b/', $question)) {
        return 'The Student Dashboard provides Profile, Events, Announcements, Costume Borrowing, returns, and participation information. Use the section that matches your task, and contact OCA through the official school support channel if a feature or record is unavailable.';
    }

    if (preg_match('/\b(access|login|restricted|blocked|password|account)\b/', $question)) {
        return 'If you cannot access your account, check that you are using the verified BatStateU student account and review the login or application-status instructions. Contact OCA through the official school support channel if access remains restricted.';
    }

    return 'I can help with OCA student services such as events, announcements, Student Artist registration, profile updates, costume borrowing, returns, participation records, account access, campuses, and technical assistance. Please ask about one of these student services.';
}

function chatbotProviderError(int $status, string $rawResponse, string $message): never
{
    $providerError = json_decode($rawResponse, true)['error']['message'] ?? 'Unknown Gemini provider error';
    error_log('OCA Gemini provider error: HTTP ' . $status . ' - ' . $providerError);

    chatbotResponse(['answer' => chatbotFallbackAnswer($message)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatbotResponse(['answer' => 'Only questions can be submitted here.'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
$message = trim((string)($payload['message'] ?? ''));
if ($message === '' || strlen($message) > 1000) {
    chatbotResponse(['answer' => 'Please enter a question up to 1,000 characters.'], 422);
}

$userRole = $_SESSION['user_role'] ?? null;
if ($userRole !== null && $userRole !== 'student') {
    chatbotResponse(['answer' => 'This assistant is limited to student-facing OCA information. Please use your authorized staff support channel for administrative or director questions.'], 403);
}

if (preg_match('/\b(admin(?:istrator)?|director|head[- ]?staff|staff dashboard|admin dashboard|database|api key|credential|backend|sql|server configuration)\b/i', $message)) {
    chatbotResponse(['answer' => 'I can only help with student-facing OCA services. I cannot provide administrative, director, staff, or system information.'], 403);
}

$now = time();
$requestWindow = $_SESSION['oca_chatbot_requests'] ?? [];
$requestWindow = array_values(array_filter($requestWindow, static fn($timestamp): bool => (int)$timestamp > $now - 60));
if (count($requestWindow) >= 20) {
    chatbotResponse(['answer' => 'Please wait a moment before sending more questions.'], 429);
}
$requestWindow[] = $now;
$_SESSION['oca_chatbot_requests'] = $requestWindow;

$apiKey = trim((string)(getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '')));
if ($apiKey === '') {
    chatbotResponse(['answer' => chatbotFallbackAnswer($message)]);
}

$context = "Office of Culture and Arts (OCA) student assistant. Answer only student-facing questions about OCA culture and arts programs, student participation, published announcements, published events, Student Artist registration, profile information, borrowing, returns, access issues, supported campuses, and technical assistance. Never reveal or infer admin, director, head-staff, database, credentials, private records, approval rules beyond the verified guidance, or software implementation details. Do not claim to perform an action; explain the student-facing steps instead. Use only the verified context below. If the context does not answer the question, say the information is not currently available and recommend contacting the OCA office. Keep answers concise and clear.\n\n";
$context .= "Verified FAQ guidance:\n";
$context .= "- Password recovery: On the sign-in page, select 'Recover it here' under 'Forgot your password'. Enter the registered email address and, if an eligible active account exists, enter the six-digit password-reset code sent to that email. The code expires in five minutes and can be resent after the resend delay. After verification, set a new password with at least 8 characters containing uppercase and lowercase letters, a number, and a special character. Do not direct students to a separate staff-only or application-status process.\n";
$context .= "- Updating profile information: Sign in to the Student Dashboard, open the Profile section, choose Edit Profile, update the available information, and save it. If a field cannot be changed or the profile cannot be found, contact OCA technical assistance.\n";
$context .= "- Requesting a costume or inventory item: Sign in to the Student Dashboard, open Costume Borrowing, select Costume Borrowing Form, complete the request with the needed item and dates, and submit it. A request is subject to OCA review; do not promise approval or availability.\n";
$context .= "- Viewing borrowing requests: Sign in to the Student Dashboard and open Costume Borrowing. The My Borrowed Costumes area shows the student's requests and their current status when available.\n";
$context .= "- Returning a borrowed item: Sign in to the Student Dashboard, open Costume Borrowing, find the borrowed item, and use the available return action or return process. Follow the return form instructions and wait for OCA confirmation; do not claim that a return is complete until it is confirmed.\n";
$context .= "- Viewing participation history: Sign in to the Student Dashboard and open the Profile or participation area. The student's recorded event participation and performance information are shown there when available. If a record is missing, contact OCA.\n";
$context .= "- Restricted account access: Access may be restricted when the account is not an approved or verified student account, the account details do not match, or access is temporarily unavailable. Do not diagnose private account records. Direct the student to the login/status instructions or OCA technical assistance.\n";
$context .= "- Supported campuses: The system supports Pablo Borbon, Alangilan, Lipa, JPLPC Malvar, and ARASOF Nasugbu. Event visibility can still depend on the student's campus, cultural group, and event publishing settings.\n";
$context .= "- Technical assistance: For login, profile, borrowing, access, or system problems that the student cannot resolve, contact the Office of Culture and Arts through the official contact details published by the school or OCA. Do not invent an email address, phone number, or staff member.\n";
$context .= "- Student Dashboard map: The student can use Profile for personal information and participation records, Events for published and ongoing activities, Announcements for current notices, Costume Borrowing for item requests and borrowing status, and the available return process for active approved items. Use these exact section names when giving directions.\n";
$context .= "- Event eligibility: Events shown to a student depend on published status and may be filtered by the student's campus and cultural group. Do not promise that a student can join an event unless it is visible to that student and has a Join Event action.\n";
$context .= "- Borrowing rules: A submitted costume or inventory request is reviewed by OCA. Only approved active requests can proceed to the return process. Do not claim approval, item availability, or completed return without system confirmation.\n";
$context .= "- Profile and participation records: Profile data and participation history are student-specific. Explain where the student can view or update their own record, but never reveal another person's information or private database details.\n";
$context .= "- Joining OCA activities: A student must sign in to a student account and open the Student Dashboard. In the Events section, the system shows published or ongoing events that match the student's cultural group and campus. For an upcoming eligible event, select 'Join Event'. Events already joined show a joined status. Ongoing events are displayed, but the dashboard does not show a join button for them. Do not promise access to events that are not displayed for the student's group or campus. Direct students to the current Events section rather than inventing a separate activity-registration process.\n";
$context .= "- Becoming a Student Artist: From the login page, select 'Register here' under 'Interested in becoming a Student Performer?' and complete the Performer's Profile Form. The form collects performance or cultural-group interest and student/personal details, requires a valid BatStateU G-Suite email, and requires email verification with a six-digit OTP before submission. The application is sent to an OCA staff for approval; email verification does not create an approved account immediately. Students can check their application status through the login page's 'View status here' link using their SR Code. Do not say that approval is automatic or that registration is complete before administrator approval.\n\n";
$context .= "Answering rules:\n";
$context .= "- Base answers on the live system data and verified process guidance supplied below. For questions about a student's own records, use only that student's data and identify what the student can see in their dashboard.\n";
$context .= "- Distinguish registration/verification from approval. A submitted application or OTP verification does not guarantee staff approval.\n";
$context .= "- Distinguish pending, approved, borrowed, pending return, returned, rejected, and cancelled states exactly. Do not infer a request status that is not supplied.\n";
$context .= "- If live data is missing or unavailable, say that it is not available rather than guessing. Direct the student to the relevant dashboard section or official OCA support channel.\n";
$context .= "- Never expose passwords, OTPs, private records, database details, credentials, or another student's information.\n\n";
try {
    $pdo = getDBConnection();
    $announcements = $pdo->query("SELECT title, content, created_at FROM announcements WHERE is_active = 1 AND is_published = 1 AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE) ORDER BY is_pinned DESC, created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $events = $pdo->query("SELECT title, description, start_date, end_date, location, category FROM events WHERE status IN ('published', 'ongoing') AND end_date >= CURRENT_DATE ORDER BY start_date ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $inventory = $pdo->query("SELECT item_name, category, condition_status FROM inventory WHERE status = 'available' ORDER BY item_name ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $context .= "Published announcements:\n" . json_encode($announcements, JSON_UNESCAPED_SLASHES) . "\n\nUpcoming or ongoing events:\n" . json_encode($events, JSON_UNESCAPED_SLASHES) . "\n\nCurrently available inventory names only:\n" . json_encode($inventory, JSON_UNESCAPED_SLASHES) . "\n";

    if (($userRole = $_SESSION['user_role'] ?? null) === 'student') {
        $studentId = (int)($_SESSION['user_id'] ?? 0);
        $student = null;
        if ($studentId > 0) {
            $stmt = $pdo->prepare("SELECT id, sr_code, campus, cultural_group, college, program, year_level, status FROM student_artists WHERE id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($student) {
            $context .= "\nSigned-in student's dashboard context:\n";
            $context .= json_encode([
                'campus' => $student['campus'] ?? null,
                'cultural_group' => $student['cultural_group'] ?? null,
                'college' => $student['college'] ?? null,
                'program' => $student['program'] ?? null,
                'year_level' => $student['year_level'] ?? null,
                'account_status' => $student['status'] ?? null,
            ], JSON_UNESCAPED_SLASHES) . "\n";

            $group = (string)($student['cultural_group'] ?? '');
            $campus = (string)($student['campus'] ?? '');
            $groupPatternJson = '%"' . $group . '"%';
            $groupPatternText = '%' . $group . '%';
            $eventStmt = $pdo->prepare("
                SELECT e.title, e.start_date, e.end_date, e.location, e.venue, e.status, e.allow_registration,
                       e.registration_deadline,
                       CASE WHEN ep.id IS NULL OR ep.attendance_status = 'cancelled' THEN 0 ELSE 1 END AS joined
                FROM events e
                LEFT JOIN event_participants ep ON ep.event_id = e.id AND ep.student_id = ?
                WHERE e.end_date >= CURRENT_DATE
                  AND e.status IN ('published', 'ongoing')
                  AND (e.cultural_groups LIKE ? OR e.cultural_groups LIKE ? OR e.cultural_groups = '[]' OR e.cultural_groups IS NULL)
                  AND (e.campus = ? OR e.campus IS NULL OR e.campus = '')
                ORDER BY e.start_date ASC
                LIMIT 20
            ");
            $eventStmt->execute([$studentId, $groupPatternJson, $groupPatternText, $campus]);
            $studentEvents = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
            $context .= "Events visible to this signed-in student:\n" . json_encode($studentEvents, JSON_UNESCAPED_SLASHES) . "\n";

            $borrowStmt = $pdo->prepare("
                SELECT item_name, quantity_requested, quantity_approved, status, approval_status, current_status,
                       start_date, end_date, due_date, actual_return_date
                FROM borrowing_requests
                WHERE student_id = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $borrowStmt->execute([$studentId]);
            $studentBorrowing = $borrowStmt->fetchAll(PDO::FETCH_ASSOC);
            $context .= "This student's borrowing records:\n" . json_encode($studentBorrowing, JSON_UNESCAPED_SLASHES) . "\n";

            $participationStmt = $pdo->prepare("
                SELECT participation_date, event_name, participation_level, rank_award
                FROM student_participation_records
                WHERE student_id = ?
                ORDER BY participation_date DESC
                LIMIT 20
            ");
            $participationStmt->execute([$studentId]);
            $studentParticipation = $participationStmt->fetchAll(PDO::FETCH_ASSOC);
            $context .= "This student's recorded participation history:\n" . json_encode($studentParticipation, JSON_UNESCAPED_SLASHES) . "\n";

            if (!empty($student['sr_code'])) {
                $applicationStmt = $pdo->prepare("
                    SELECT application_status, submitted_at, approved_at
                    FROM applications
                    WHERE sr_code = ?
                    ORDER BY submitted_at DESC
                    LIMIT 1
                ");
                $applicationStmt->execute([$student['sr_code']]);
                $application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
                if ($application) {
                    $context .= "This student's latest Student Artist application status:\n" . json_encode($application, JSON_UNESCAPED_SLASHES) . "\n";
                }
            }
        }
    }
} catch (Throwable $exception) {
    error_log('OCA chatbot context error: ' . $exception->getMessage());
    $context .= "No live announcements or event details are available. Do not invent them.\n";
}

$history = is_array($payload['history'] ?? null) ? array_slice($payload['history'], -8) : [];
$contents = [];
$hasCurrentMessage = false;
foreach ($history as $item) {
    if (!is_array($item) || !in_array($item['role'] ?? '', ['user', 'assistant'], true)) {
        continue;
    }

    $role = $item['role'] === 'assistant' ? 'model' : 'user';
    $content = substr((string)($item['content'] ?? ''), 0, 2000);
    if ($role === 'user' && $content === $message) {
        $hasCurrentMessage = true;
    }
    if ($content !== '') {
        $contents[] = ['role' => $role, 'parts' => [['text' => $content]]];
    }
}
if (!$hasCurrentMessage) {
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];
}

$model = trim((string)(getenv('GEMINI_MODEL') ?: ($_ENV['GEMINI_MODEL'] ?? 'gemini-3.8-flash')));
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
$request = curl_init($url);
curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode([
        'systemInstruction' => ['parts' => [['text' => $context]]],
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 350],
    ], JSON_UNESCAPED_SLASHES),
]);
$rawResponse = curl_exec($request);
$curlError = curl_error($request);
$status = (int)curl_getinfo($request, CURLINFO_HTTP_CODE);
unset($request);

if ($rawResponse === false || $curlError !== '') {
    error_log('OCA Gemini connection error: ' . $curlError);
    chatbotResponse(['answer' => chatbotFallbackAnswer($message)]);
}

$result = json_decode($rawResponse, true);
$answer = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
if ($status < 200 || $status >= 300) {
    chatbotProviderError($status, $rawResponse, $message);
}
if (!is_string($answer) || trim($answer) === '') {
    error_log('OCA Gemini returned no text response.');
    chatbotResponse(['answer' => chatbotFallbackAnswer($message)]);
}

chatbotResponse(['answer' => trim($answer)]);
