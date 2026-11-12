<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

$pdo = getDBConnection();
$student_id = $_SESSION['user_id'];

try {
    // Get user table information
    $user_table = $_SESSION['user_table'] ?? 'users';

    // Resolve account identifiers first
    $user_email = null;
    $sr_code = null;

    if ($user_table === 'student_artists') {
        $stmt = $pdo->prepare("SELECT email, sr_code FROM student_artists WHERE id = ?");
        $stmt->execute([$student_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_email = $user_data['email'] ?? null;
        $sr_code = $user_data['sr_code'] ?? null;
    } else {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$student_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_email = $user_data['email'] ?? null;

        if ($user_email) {
            $stmt = $pdo->prepare("SELECT sr_code FROM student_artists WHERE email = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_email]);
            $sr_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $sr_code = $sr_data['sr_code'] ?? null;
        }
    }

    if (!$user_email && !$sr_code) {
        die('User profile not found');
    }

    // Get student data from student_artists table
    $app = null;

    if ($user_table === 'student_artists') {
        $stmt = $pdo->prepare("SELECT * FROM student_artists WHERE id = ?");
        $stmt->execute([$student_id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$app && $user_email) {
        $stmt = $pdo->prepare("SELECT * FROM student_artists WHERE TRIM(LOWER(email)) = TRIM(LOWER(?)) ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_email]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$app && $sr_code) {
        $stmt = $pdo->prepare("SELECT * FROM student_artists WHERE TRIM(sr_code) = TRIM(?) ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sr_code]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$app) {
        die('No approved student profile found');
    }

    // Get all student_ids for this email/sr_code to consolidate records from re-registrations
    $studentIds = [$app['id']];
    if ($user_email) {
        $stmt = $pdo->prepare("SELECT id FROM student_artists WHERE TRIM(LOWER(email)) = TRIM(LOWER(?)) AND id != ?");
        $stmt->execute([$user_email, $app['id']]);
        $otherIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $studentIds = array_merge($studentIds, $otherIds);
    }

    // Fetch participation records from all student entries
    $participations = [];
    if (!empty($studentIds)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare("SELECT participation_date as date, event_name, participation_level as level, rank_award FROM student_participation_records WHERE student_id IN ($placeholders) ORDER BY participation_date DESC");
        $stmt->execute($studentIds);
        $participations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch competition records from all student entries
    $competitions = [];
    if (!empty($studentIds)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare("SELECT competition_date as date, event_name, competition_level as level, rank_award FROM student_competition_records WHERE student_id IN ($placeholders) ORDER BY competition_date DESC");
        $stmt->execute($studentIds);
        $competitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Merge and sort by date descending for a single achievements table
    $allParticipations = array_merge($participations, $competitions);
    usort($allParticipations, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    // Fetch affiliation records from all student entries
    $affiliations = [];
    if (!empty($studentIds)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare("SELECT position, organization, years_active FROM student_affiliation_records WHERE student_id IN ($placeholders) ORDER BY created_at DESC");
        $stmt->execute($studentIds);
        $affiliations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get assigned cultural group first (authoritative for export)
    $assigned_cultural_group = trim((string)($app['cultural_group'] ?? ''));

    // Get original performance type from latest application (fallback only)
    $application_performance_type = '';
    if (!empty($app['sr_code'])) {
        $stmt = $pdo->prepare("SELECT performance_type FROM applications WHERE sr_code = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$app['sr_code']]);
        $latestApplication = $stmt->fetch(PDO::FETCH_ASSOC);
        $application_performance_type = trim((string)($latestApplication['performance_type'] ?? ''));
    }

    $performance_type = $application_performance_type;

} catch (Exception $e) {
    die('Error fetching profile data: ' . $e->getMessage());
}

// Generate PDF using the same layout as head-staff export
require_once 'tcpdf/tcpdf.php';

class PerformerProfilePDF extends TCPDF {
    public function Header() {
        // Empty - we'll create custom header
    }

    public function Footer() {
        // Empty footer for cleaner look
    }
}

// Create PDF instance with LONG PAPER size (8.5 x 13 inches = 215.9 x 330.2 mm)
$pdf = new PerformerProfilePDF('P', 'mm', array(215.9, 330.2), true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BatStateU Culture & Arts Management System');
$pdf->SetAuthor($app['first_name'] . ' ' . $app['last_name']);
$pdf->SetTitle("Performer's Profile Form - " . ($app['sr_code'] ?? 'Student'));
$pdf->SetSubject("Performer's Profile Form");

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins (slightly smaller bottom margin to fit the attachments line)
$pdf->SetMargins(5.6, 5.6, 5.6);
$pdf->SetAutoPageBreak(true, 3.0);

// Add page
$pdf->AddPage();

// ========================================
// HEADER SECTION WITH LOGO AND INFO
// ========================================
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(5.6, 5.6);

// Define cell dimensions to match official template exactly
$headerHeight = 12;
$logoCell = 30;
$refCell = 60;
$effCell = 70;
$revCell = 44.4;

// Draw header table cells
$pdf->Cell($logoCell, $headerHeight, '', 1, 0, 'C');
$pdf->Cell($refCell, $headerHeight, 'Reference No.: BatStateU-FO-OCA-03', 1, 0, 'L');
$pdf->Cell($effCell, $headerHeight, 'Effectivity Date: August 01, 2023', 1, 0, 'L');
$pdf->Cell($revCell, $headerHeight, 'Revision No.: 01', 1, 1, 'L');

// Place logo INSIDE the first cell
$logoPaths = [
    ['path' => '../assets/logo.jpg', 'type' => 'JPEG'],
    ['path' => '../assets/bsu.png', 'type' => 'PNG'],
    ['path' => '../assets/bsu.jpg', 'type' => 'JPEG']
];

foreach ($logoPaths as $logoInfo) {
    if (file_exists($logoInfo['path'])) {
        try {
            $logoSize = 10;
            $logoX = 5.6 + ($logoCell - $logoSize) / 2;
            $logoY = 5.6 + ($headerHeight - $logoSize) / 2;
            $pdf->Image($logoInfo['path'], $logoX, $logoY, $logoSize, $logoSize, $logoInfo['type']);
            break;
        } catch (Exception $e) {
            continue;
        }
    }
}

// Title centered below header with border
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, "PERFORMER'S PROFILE FORM", 1, 1, 'C');

$pdf->Ln(1);

// ========================================
// TYPE OF PERFORMANCE SECTION
// ========================================
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(42, 5.5, 'Type of Performance: ', 'LTB', 0, 'L', true);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(162.8, 5.5, '(Include the name of Cultural Group you are interested in joining:)', 'TRB', 1, 'L', true);

$pdf->SetFont('helvetica', '', 8);
$performance_types = ['Performing Arts', 'Music', 'Dance', 'Theater', 'Visual Arts', 'Literary Arts'];
$normalizeTypeToken = function($value) {
    $normalized = strtolower(trim((string)$value));
    $normalized = str_replace(['-', ' '], '_', $normalized);
    return $normalized;
};

$performanceTypeMap = [
    'performing_arts' => 'Performing Arts',
    'music' => 'Music',
    'dance' => 'Dance',
    'theater' => 'Theater',
    'visual_arts' => 'Visual Arts',
    'literary_arts' => 'Literary Arts'
];

$groupToTypeMap = [
    'dulaang batangan' => 'Theater',
    'batstateu dance company' => 'Dance',
    'diwayanis dance theatre' => 'Dance',
    'batstateu band' => 'Music',
    'indak yaman dance varsity' => 'Dance',
    'ritmo voice' => 'Music',
    'sandugo dance group' => 'Dance',
    'areglo band' => 'Music',
    'teatro aliwana' => 'Theater',
    'the levites' => 'Music',
    'melophiles' => 'Music',
    'sindayog' => 'Dance'
];

$selectedPerformanceType = '';
$groupNameOnly = '';
$rawPerformanceType = trim((string)($performance_type ?? ''));

if ($assigned_cultural_group !== '') {
    $groupNameOnly = $assigned_cultural_group;
    $groupKey = strtolower($assigned_cultural_group);
    $selectedPerformanceType = $groupToTypeMap[$groupKey] ?? '';

    if ($selectedPerformanceType === '') {
        if (stripos($assigned_cultural_group, 'dance') !== false || stripos($assigned_cultural_group, 'indak') !== false) {
            $selectedPerformanceType = 'Dance';
        } elseif (stripos($assigned_cultural_group, 'band') !== false || stripos($assigned_cultural_group, 'voice') !== false || stripos($assigned_cultural_group, 'melo') !== false || stripos($assigned_cultural_group, 'levites') !== false) {
            $selectedPerformanceType = 'Music';
        } elseif (stripos($assigned_cultural_group, 'teatro') !== false || stripos($assigned_cultural_group, 'theater') !== false || stripos($assigned_cultural_group, 'dulaang') !== false) {
            $selectedPerformanceType = 'Theater';
        } else {
            $selectedPerformanceType = 'Performing Arts';
        }
    }
}

if ($groupNameOnly === '' && $rawPerformanceType !== '') {
    $groupNameOnly = $rawPerformanceType;

    if (preg_match('/^\s*([^:]+)\s*:\s*(.+)\s*$/', $rawPerformanceType, $matches)) {
        $typeKey = $normalizeTypeToken($matches[1]);
        if (isset($performanceTypeMap[$typeKey])) {
            $selectedPerformanceType = $performanceTypeMap[$typeKey];
            $groupNameOnly = trim($matches[2]);
        }
    }

    if ($selectedPerformanceType === '') {
        foreach ($performance_types as $type) {
            if (stripos($rawPerformanceType, $type) !== false) {
                $selectedPerformanceType = $type;
                $trimmedGroupName = trim(preg_replace('/^.*' . preg_quote($type, '/') . '\s*[:\-]?\s*/i', '', $rawPerformanceType));
                if ($trimmedGroupName !== '' && strcasecmp($trimmedGroupName, $type) !== 0) {
                    $groupNameOnly = $trimmedGroupName;
                } else {
                    $groupNameOnly = '';
                }
                break;
            }
        }
    }
}

foreach ($performance_types as $index => $type) {
    $checked = ($selectedPerformanceType === $type);

    $y = $pdf->GetY();
    $pdf->Rect(6.6, $y + 1, 3, 3);
    if ($checked) {
        $pdf->Line(7, $y + 2.2, 8, $y + 3.2);
        $pdf->Line(8, $y + 3.2, 9.6, $y + 1.2);
    }

    $pdf->Cell(5, 5, '', 'L', 0, 'L');
    $pdf->Cell(30, 5, ' ' . $type, 0, 0, 'L');
    if (!empty($groupNameOnly) && ($checked || ($selectedPerformanceType === '' && $index === 0))) {
        $fillText = ': ' . $groupNameOnly;
    } else {
        $fillText = ': ____________________________________________________________';
    }
    $borderType = ($index == count($performance_types) - 1) ? 'LBR' : 'LR';
    $pdf->Cell(169.4, 5, $fillText, $borderType, 1, 'L');
}

// Data Privacy Act Notice
$pdf->SetFont('helvetica', '', 6.5);
$privacyText = 'Pursuant to Republic Act No. 10173, also known as the Data Privacy Act of 2012, the Batangas State University, the National Engineering University, recognizes its commitment to protect and respect the privacy of its customers and/or stakeholders and ensure that all information collected from them are all processed in accordance with the principles of transparency, legitimate purpose and proportionality mandated under the Data Privacy Act of 2012.';
$pdf->MultiCell(0, 3, $privacyText, 'LTR', 'L');

// Consent of Data Subject
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 5.5, 'Consent of Data Subject', 1, 1, 'C', true);

// Draw Yes/No checkboxes
$y = $pdf->GetY();
$pdf->Rect(6.6, $y + 0.8, 3, 3);
$pdf->Line(7, $y + 2, 8, $y + 3);
$pdf->Line(8, $y + 3, 9.6, $y + 1);
$pdf->Rect(17, $y + 0.8, 3, 3);

$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(5, 4.5, '', 'L', 0, 'L');
$pdf->Cell(5, 4.5, 'Yes', 0, 0, 'L');
$pdf->Cell(5, 4.5, '', 0, 0, 'L');
$pdf->Cell(5, 4.5, 'No', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 7);
$consentText = 'I hereby give my consent and hereby authorize Batangas State University, the National Engineering University to share, disclose, or transfer my personal data with a third party in the pursuit of journalistic, artistic, literary, research or any legal purposes in compliance with the Data Privacy Act of 2012.';
$pdf->MultiCell(184.4, 3, $consentText, 'R', 'J');

$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(0, 4, '(Please fill out the form and submit to the respective trainer. Put N/A if not applicable.)', 'LBR', 1, 'C');

$pdf->Ln(2);

// ========================================
// I. PERSONAL INFORMATION
// ========================================
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 5.5, 'I. PERSONAL INFORMATION', 1, 1, 'L', true);

$pdf->SetFont('helvetica', '', 8);
$photoY = $pdf->GetY();

// Row 1: Name with photo cell (56.8mm height extends to Contact Number row)
$nameData = trim(($app['first_name'] ?? '') . ' ' . ($app['middle_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
$pdf->Cell(30, 6, 'Name:', 1, 0, 'L');
$pdf->Cell(120.4, 6, $nameData, 1, 0, 'L');
$pdf->Cell(54, 56.8, '', 1, 1, 'C');

// Row 2: Address
$pdf->SetXY(5.6, $photoY + 6);
$pdf->Cell(30, 6, 'Address:', 1, 0, 'L');
$pdf->Cell(120.4, 6, $app['address'] ?? $app['permanent_address'] ?? '', 1, 0, 'L');

// Row 3: Present Address
$pdf->SetXY(5.6, $photoY + 12);
$pdf->Cell(30, 6, 'Present Address:', 1, 0, 'L');
$pdf->Cell(120.4, 6, $app['present_address'] ?? '', 1, 0, 'L');

// Row 4: Date of Birth, Age, Gender
$pdf->SetXY(5.6, $photoY + 18);
$pdf->Cell(30, 6, 'Date of Birth:', 1, 0, 'L');
$pdf->Cell(50, 6, $app['date_of_birth'] ?? '', 1, 0, 'L');
$pdf->Cell(10, 6, 'Age:', 1, 0, 'L');
$pdf->Cell(12, 6, $app['age'] ?? '', 1, 0, 'C');
$pdf->Cell(15, 6, 'Gender:', 1, 0, 'L');
$pdf->Cell(33.4, 6, $app['gender'] ?? '', 1, 0, 'L');

// Row 5: Place of Birth
$pdf->SetXY(5.6, $photoY + 24);
$pdf->Cell(30, 6, 'Place of Birth:', 1, 0, 'L');
$pdf->Cell(120.4, 6, $app['place_of_birth'] ?? '', 1, 0, 'L');

// Row 6: Email and Contact
$pdf->SetXY(5.6, $photoY + 30);
$pdf->Cell(30, 6, 'Email Address:', 1, 0, 'L');
$pdf->Cell(60, 6, $app['email'] ?? '', 1, 0, 'L');
$pdf->Cell(30, 6, 'Contact Number:', 1, 0, 'L');
$pdf->Cell(30.4, 6, $app['contact_number'] ?? '', 1, 0, 'L');

// Row 7: Father's Name
$pdf->SetXY(5.6, $photoY + 36);
$pdf->Cell(30, 6, "Father's Name:", 1, 0, 'L');
$pdf->Cell(120.4, 6, $app['father_name'] ?? '', 1, 0, 'L');

// Photo image in photo cell
$photoX = 156;
if (!empty($app['profile_photo']) && file_exists('../' . $app['profile_photo'])) {
    try {
        $photoExt = strtolower(pathinfo($app['profile_photo'], PATHINFO_EXTENSION));
        $imageType = '';
        if (in_array($photoExt, ['jpg', 'jpeg'])) {
            $imageType = 'JPEG';
        } elseif ($photoExt === 'png') {
            $imageType = 'PNG';
        } elseif ($photoExt === 'gif') {
            $imageType = 'GIF';
        }
        $pdf->Image('../' . $app['profile_photo'], $photoX + 1.6, $photoY + 1.6, 50.8, 50.8, $imageType);
    } catch (Exception $e) {
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetXY($photoX, $photoY + 23);
        $pdf->Cell(54, 5, '(Attach 2x2 picture here)', 0, 0, 'C');
    }
} else {
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetXY($photoX, $photoY + 23);
    $pdf->Cell(54, 5, '(Attach 2x2 picture here)', 0, 0, 'C');
}

// Row 8: Mother's Name (still within photo cell height)
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(5.6, $photoY + 42);
$pdf->Cell(30, 4.4, "Mother's Name:", 1, 0, 'L');
$pdf->Cell(120.4, 4.4, $app['mother_name'] ?? '', 1, 0, 'L');

// Row 9: Guardian (still within photo cell height)
$pdf->SetXY(5.6, $photoY + 46.4);
$pdf->Cell(50, 4.4, 'Guardian (if not living with Parents):', 1, 0, 'L');
$pdf->Cell(100.4, 4.4, (!empty($app['guardian']) && $app['guardian'] != 'N/A') ? $app['guardian'] : '', 1, 0, 'L');

// Row 10: Contact Number (still within photo cell height)
$pdf->SetXY(5.6, $photoY + 50.8);
$pdf->Cell(50, 6, 'Contact Number (Parent/Guardian):', 1, 0, 'L');
$pdf->Cell(100.4, 6, $app['guardian_contact'] ?? $app['emergency_contact_number'] ?? '', 1, 1, 'L');

$pdf->Ln(2);

// ========================================
// II. EDUCATIONAL INFORMATION
// ========================================
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 5.5, 'II. EDUCATIONAL INFORMATION', 1, 1, 'L', true);

$pdf->SetFont('helvetica', '', 8);

// Campus, SR Code, Year Level
$pdf->Cell(18, 6, 'Campus:', 1, 0, 'L');
$pdf->Cell(82, 6, $app['campus'] ?? '', 1, 0, 'L');
$pdf->Cell(20, 6, 'SR Code:', 1, 0, 'L');
$pdf->Cell(35, 6, $app['sr_code'] ?? '', 1, 0, 'L');
$pdf->Cell(20, 6, 'Year Level:', 1, 0, 'L');
$pdf->Cell(29.4, 6, $app['year_level'] ?? '', 1, 1, 'C');

// College and Program
$pdf->Cell(18, 6, 'College:', 1, 0, 'L');
$pdf->Cell(82, 6, $app['college'] ?? '', 1, 0, 'L');
$pdf->Cell(20, 6, 'Program:', 1, 0, 'L');
$pdf->Cell(84.4, 6, $app['program'] ?? '', 1, 1, 'L');

// Number of Units
$y = $pdf->GetY();
$firstSemesterUnits = $app['first_semester_units'] ?? null;
$secondSemesterUnits = $app['second_semester_units'] ?? null;

$hasFirstSemesterUnits = false;
if (is_numeric($firstSemesterUnits)) {
    $hasFirstSemesterUnits = (float)$firstSemesterUnits > 0;
} elseif ($firstSemesterUnits !== null) {
    $hasFirstSemesterUnits = trim((string)$firstSemesterUnits) !== '';
}

$hasSecondSemesterUnits = false;
if (is_numeric($secondSemesterUnits)) {
    $hasSecondSemesterUnits = (float)$secondSemesterUnits > 0;
} elseif ($secondSemesterUnits !== null) {
    $hasSecondSemesterUnits = trim((string)$secondSemesterUnits) !== '';
}

$firstSemesterLabel = '      First Semester ';
$secondSemesterLabel = '      Second Semester ';
$firstSemesterLabelWidth = 35;
$secondSemesterLabelWidth = 35;
$firstSemesterValueWidth = 52;
$secondSemesterValueWidth = 52.4;

$pdf->Cell(30, 6, 'Number of Units:', 1, 0, 'L');

// Draw first checkbox and semester text together
$pdf->Rect(37.5, $y + 1.5, 3, 3);
if ($hasFirstSemesterUnits) {
    $pdf->Line(37.9, $y + 2.7, 38.9, $y + 3.7);
    $pdf->Line(38.9, $y + 3.7, 40.5, $y + 1.7);
}
$pdf->Cell($firstSemesterLabelWidth, 6, $firstSemesterLabel, 'LTB', 0, 'L');
$pdf->SetFont('helvetica', 'U', 8);
$firstSemesterValueText = $hasFirstSemesterUnits
    ? str_pad((string)$firstSemesterUnits, 10, ' ', STR_PAD_BOTH)
    : str_repeat(' ', 10);
$pdf->Cell($firstSemesterValueWidth, 6, $firstSemesterValueText, 'RTB', 0, 'L');
$pdf->SetFont('helvetica', '', 8);

// Draw second checkbox and semester text together
$pdf->Rect(124.5, $y + 1.5, 3, 3);
if ($hasSecondSemesterUnits) {
    $pdf->Line(124.9, $y + 2.7, 125.9, $y + 3.7);
    $pdf->Line(125.9, $y + 3.7, 127.5, $y + 1.7);
}
$pdf->Cell($secondSemesterLabelWidth, 6, $secondSemesterLabel, 'LTB', 0, 'L');
$pdf->SetFont('helvetica', 'U', 8);
$secondSemesterValueText = $hasSecondSemesterUnits
    ? str_pad((string)$secondSemesterUnits, 10, ' ', STR_PAD_BOTH)
    : str_repeat(' ', 10);
$pdf->Cell($secondSemesterValueWidth, 6, $secondSemesterValueText, 'RTB', 1, 'L');
$pdf->SetFont('helvetica', '', 8);

$pdf->Ln(2);

// ========================================
// III. PARTICIPATION IN THE FIELD OF INTEREST / ACHIEVEMENTS
// ========================================
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 5.5, 'III. PARTICIPATION IN THE FIELD OF INTEREST / ACHIEVEMENTS (Last Five Years)', 1, 1, 'L', true);

// Table headers
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(30, 5.5, 'Date', 1, 0, 'C', true);
$pdf->Cell(75, 5.5, 'Event', 1, 0, 'C', true);
$pdf->Cell(65, 5.5, 'Level', 1, 0, 'C', true);
$pdf->Cell(34.4, 5.5, 'Rank (Place)', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(30, 3.5, '', 1, 0, 'C');
$pdf->Cell(75, 3.5, '', 1, 0, 'C');
$pdf->SetFont('helvetica', 'I', 6.5);
$pdf->Cell(65, 3.5, '(Local, Regional, National, International)', 1, 0, 'C');
$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(34.4, 3.5, '', 1, 1, 'C');

// Participation data rows (show all, keep minimum of 4 rows)
$participationRowCount = max(4, count($participations));
for ($i = 0; $i < $participationRowCount; $i++) {
    if (isset($participations[$i])) {
        $p = $participations[$i];
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(30, 5.5, $p['date'] ?? '', 1, 0, 'L');
        $pdf->Cell(75, 5.5, substr($p['event_name'] ?? '', 0, 50), 1, 0, 'L');
        $pdf->Cell(65, 5.5, $p['level'] ?? '', 1, 0, 'L');
        $pdf->Cell(34.4, 5.5, $p['rank_award'] ?? '', 1, 1, 'L');
    } else {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(30, 5.5, '', 1, 0, 'L');
        $pdf->Cell(75, 5.5, '', 1, 0, 'L');
        $pdf->Cell(65, 5.5, '', 1, 0, 'L');
        $pdf->Cell(34.4, 5.5, '', 1, 1, 'L');
    }
}

$pdf->Ln(2);

// ========================================
// IV. COMPETITION PARTICIPATED
// ========================================
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 5.5, 'IV. COMPETITION PARTICIPATED', 1, 1, 'L', true);

// Table headers
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(30, 5.5, 'Date', 1, 0, 'C', true);
$pdf->Cell(75, 5.5, 'Event', 1, 0, 'C', true);
$pdf->Cell(65, 5.5, 'Level', 1, 0, 'C', true);
$pdf->Cell(34.4, 5.5, 'Rank (Place)', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(30, 3.5, '', 1, 0, 'C');
$pdf->Cell(75, 3.5, '', 1, 0, 'C');
$pdf->SetFont('helvetica', 'I', 6.5);
$pdf->Cell(65, 3.5, '(Local, Regional, National, International)', 1, 0, 'C');
$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(34.4, 3.5, '', 1, 1, 'C');

// Competition data rows (show all, keep minimum of 4 rows)
$competitionRowCount = max(4, count($competitions));
for ($i = 0; $i < $competitionRowCount; $i++) {
    if (isset($competitions[$i])) {
        $c = $competitions[$i];
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(30, 5.5, $c['date'] ?? '', 1, 0, 'L');
        $pdf->Cell(75, 5.5, substr($c['event_name'] ?? '', 0, 50), 1, 0, 'L');
        $pdf->Cell(65, 5.5, $c['level'] ?? '', 1, 0, 'L');
        $pdf->Cell(34.4, 5.5, $c['rank_award'] ?? '', 1, 1, 'L');
    } else {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(30, 5.5, '', 1, 0, 'L');
        $pdf->Cell(75, 5.5, '', 1, 0, 'L');
        $pdf->Cell(65, 5.5, '', 1, 0, 'L');
        $pdf->Cell(34.4, 5.5, '', 1, 1, 'L');
    }
}

$pdf->Ln(2);

// ========================================
// V. AFFILIATION TO ORGANIZATIONS
// ========================================
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 5.5, 'V. AFFILIATION TO ORGANIZATIONS', 1, 1, 'L', true);

// Table headers
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(50, 5.5, 'Position', 1, 0, 'C', true);
$pdf->Cell(110, 5.5, 'Name of Organization', 1, 0, 'C', true);
$pdf->Cell(44.4, 5.5, 'Inclusive Years', 1, 1, 'C', true);

// Affiliation data rows (show all, keep minimum of 4 rows)
$affiliationRowCount = max(4, count($affiliations));
for ($i = 0; $i < $affiliationRowCount; $i++) {
    if (isset($affiliations[$i])) {
        $a = $affiliations[$i];
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(50, 5.5, $a['position'] ?? '', 1, 0, 'L');
        $pdf->Cell(110, 5.5, substr($a['organization'] ?? '', 0, 60), 1, 0, 'L');
        $pdf->Cell(44.4, 5.5, $a['years_active'] ?? '', 1, 1, 'L');
    } else {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(50, 5.5, '', 1, 0, 'L');
        $pdf->Cell(110, 5.5, '', 1, 0, 'L');
        $pdf->Cell(44.4, 5.5, '', 1, 1, 'L');
    }
}

$pdf->Ln(1);

// ========================================
// CERTIFICATION AND SIGNATURE
// ========================================
$pdf->Ln(1);
$pdf->SetFont('helvetica', '', 8);

// Keep the certification/signature block together when it fits
$certText = '      I hereby certify that all information in this form is true and correct. Any misrepresentation of facts will render this invalid and immediately disqualifies my membership to the cultural group.';
$drawSignatureBlock = function($pdf, $certText) {
    $y = $pdf->GetY();
    $pdf->Rect(6.6, $y + 0.8, 3, 3);
    $pdf->Line(7, $y + 2, 8, $y + 3);
    $pdf->Line(8, $y + 3, 9.6, $y + 1);
    $pdf->MultiCell(0, 5, $certText, 0, 'L');

    $pdf->Ln(2);
    $pdf->Ln(3);
    $pdf->Cell(0, 5, '_______________________________________________', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(0, 5, 'Signature over Printed Name of Performer/Member', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 5, 'Date: ' . date('F d, Y'), 0, 1, 'C');

    $pdf->Ln(2);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->Cell(0, 4, '*Required Attachments: Certified True Copy of Certificates and Recognitions', 0, 1, 'L');
};

$startPage = $pdf->getPage();
$startY = $pdf->GetY();
$pdf->startTransaction();
$drawSignatureBlock($pdf, $certText);

if ($pdf->getPage() > $startPage) {
    $pdf->rollbackTransaction(true);
    $pdf->setPage($startPage);
    $pdf->SetY($startY);
    $pdf->AddPage();
    $drawSignatureBlock($pdf, $certText);
} else {
    $pdf->commitTransaction();
}

// Output PDF
$filename = 'Performers_Profile_Form_' . ($app['sr_code'] ?? 'Student') . '.pdf';
$pdf->Output($filename, 'D');
exit();
?>
