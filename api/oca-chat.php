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

function chatbotProviderError(int $status, string $rawResponse): never
{
    $providerError = json_decode($rawResponse, true)['error']['message'] ?? 'Unknown Gemini provider error';
    error_log('OCA Gemini provider error: HTTP ' . $status . ' - ' . $providerError);

    $message = match ($status) {
        400 => 'The assistant could not process that question. Please try asking it another way.',
        401, 403 => 'The assistant is temporarily unavailable. Please contact the OCA office.',
        404 => 'The configured Gemini model is unavailable. Please contact the system administrator.',
        429 => 'The assistant has reached its free usage limit for now. Please try again later.',
        500, 502, 503 => 'The assistant is temporarily unavailable. Please try again later.',
        default => 'The assistant is temporarily unavailable. Please try again later.',
    };

    chatbotResponse(['answer' => $message], 502);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatbotResponse(['answer' => 'Only questions can be submitted here.'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
$message = trim((string)($payload['message'] ?? ''));
if ($message === '' || strlen($message) > 1000) {
    chatbotResponse(['answer' => 'Please enter a question up to 1,000 characters.'], 422);
}

$now = time();
$requestWindow = $_SESSION['oca_chatbot_requests'] ?? [];
$requestWindow = array_values(array_filter($requestWindow, static fn($timestamp): bool => (int)$timestamp > $now - 60));
if (count($requestWindow) >= 20) {
    chatbotResponse(['answer' => 'Please wait a moment before sending more questions.'], 429);
}
$requestWindow[] = $now;
$_SESSION['oca_chatbot_requests'] = $requestWindow;

$apiKey = trim((string)(getenv('GEMINI_API_KEY') ?: ''));
if ($apiKey === '') {
    chatbotResponse(['answer' => 'The OCA assistant is not configured yet. Please contact the OCA office for current information.'], 503);
}

$context = "Office of Culture and Arts (OCA) assistant. Answer only about OCA culture and arts programs, student participation, published announcements, published events, Student Artist registration, and related services. Do not discuss software implementation, databases, credentials, or private student/admin information. Use only the verified context below. If the context does not answer the question, say the information is not currently available and recommend contacting the OCA office. Keep answers concise and clear.\n\n";
try {
    $pdo = getDBConnection();
    $announcements = $pdo->query("SELECT title, content, created_at FROM announcements WHERE is_active = 1 AND is_published = 1 AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE) ORDER BY is_pinned DESC, created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $events = $pdo->query("SELECT title, description, start_date, end_date, location, category FROM events WHERE status IN ('published', 'ongoing') AND end_date >= CURRENT_DATE ORDER BY start_date ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $context .= "Published announcements:\n" . json_encode($announcements, JSON_UNESCAPED_SLASHES) . "\n\nUpcoming or ongoing events:\n" . json_encode($events, JSON_UNESCAPED_SLASHES);
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

$model = trim((string)(getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite'));
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
$request = curl_init($url);
curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
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
curl_close($request);

if ($rawResponse === false || $curlError !== '') {
    error_log('OCA Gemini connection error: ' . $curlError);
    chatbotResponse(['answer' => 'The assistant is temporarily unavailable. Please try again later.'], 502);
}

$result = json_decode($rawResponse, true);
$answer = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
if ($status < 200 || $status >= 300) {
    chatbotProviderError($status, $rawResponse);
}
if (!is_string($answer) || trim($answer) === '') {
    error_log('OCA Gemini returned no text response.');
    chatbotResponse(['answer' => 'The assistant could not produce an answer right now. Please try again later.'], 502);
}

chatbotResponse(['answer' => trim($answer)]);
