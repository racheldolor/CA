<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'director'])) {
    header('Location: ../index.php');
    exit();
}

$pdo = getDBConnection();

// Get user information
$user_role = $_SESSION['user_role'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';
$user_campus = $_SESSION['user_campus'] ?? 'Pablo Borbon';
$user_id = $_SESSION['user_id'] ?? null;

// Normalize campus names to full format
$campus_name_map = [
    'Malvar' => 'JPLPC Malvar',
    'Nasugbu' => 'ARASOF Nasugbu',
    'Pablo Borbon' => 'Pablo Borbon',
    'Alangilan' => 'Alangilan',
    'Lipa' => 'Lipa',
    'JPLPC Malvar' => 'JPLPC Malvar',
    'ARASOF Nasugbu' => 'ARASOF Nasugbu'
];
$user_campus = $campus_name_map[$user_campus] ?? $user_campus;

// Director role should display Pablo Borbon
if ($user_role === 'director') {
    $display_campus = 'Pablo Borbon';
} else {
    $display_campus = $user_campus;
}

// For announcements module, directors have full management access
// (different from other modules where director is view-only)
$isDirector = ($user_role === 'director');
$isHead = ($user_role === 'head');
$canManage = true; // All roles (head, director) can manage announcements

// Get user's full name
$user_name = '';
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_info) {
        $user_name = trim($user_info['first_name'] . ' ' . $user_info['last_name']);
    }
} catch (Exception $e) {
    error_log("Error fetching user info: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/jpeg" href="../assets/logo.jpg?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net;">
    <title>Announcements - Staff Dashboard - Culture and Arts - BatStateU TNEU</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        /* Prevent background scrolling when modals are open */
        body.modal-open {
            overflow: hidden;
            position: fixed;
            width: 100%;
        }

        /* Header */
        .header {
            background: white;
            color: #dc2626;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid #e0e0e0;
            width: 100%;
            max-width: 100vw;
            box-sizing: border-box;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }

        .header-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logout-btn {
            background: #dc2626;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #b91c1c;
        }

        /* Main Container */
        .main-container {
            display: flex;
            min-height: calc(100vh - 70px);
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            max-width: 280px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            min-height: calc(100vh - 70px);
            position: fixed;
            top: 70px;
            left: 0;
            z-index: 50;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-greeting {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            position: relative;
            overflow: hidden;
        }

        .sidebar-greeting::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .sidebar-greeting::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
        }

        .sidebar-greeting h3 {
            margin: 0 0 0.5rem 0;
            color: white;
            font-weight: 700;
            position: relative;
            z-index: 1;
            line-height: 1.2;
        }

        .greeting-hi {
            font-size: 1.8rem;
            font-weight: 400;
        }

        .greeting-name {
            font-size: 1.8rem;
        }

        .sidebar-greeting p {
            margin: 0 0 0 0.5rem;
            font-style: bold;
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.95);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .nav-menu {
            list-style: none;
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.5rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: #555;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-link:hover {
            background: #f8f9fa;
            color: #dc2626;
            border-left-color: #dc2626;
        }

        .nav-link.active {
            background: #fee2e2;
            color: #dc2626;
            border-left-color: #dc2626;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100vw - 280px);
            overflow-y: auto;
            overflow-x: hidden;
            box-sizing: border-box;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #333;
        }

        .page-subtitle {
            color: #666;
            font-size: 1rem;
            margin-top: 0.5rem;
        }

        .add-btn {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .add-btn span {
            font-size: 1.5rem;
            line-height: 1;
        }

        /* Content Panel */
        .content-panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .panel-header {
            background: #dc2626;
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin: 0;
        }

        .panel-content {
            padding: 1.5rem;
            min-height: 200px;
        }

        /* Announcements List */
        .announcements-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .announcement-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1.5rem;
            background: white;
            transition: all 0.3s ease;
            position: relative;
        }

        .announcement-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .announcement-item.pinned {
            background-color: #fff9e6;
            border-color: #ffc107;
        }

        .pinned-badge {
            color: #dc2626;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: inline-block;
        }

        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.75rem;
        }

        .announcement-title {
            color: #dc2626;
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .announcement-meta {
            text-align: right;
            font-size: 0.85rem;
            color: #666;
        }

        .announcement-content {
            color: #333;
            line-height: 1.6;
            white-space: pre-line;
            margin-bottom: 1rem;
        }

        .announcement-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid #e0e0e0;
            font-size: 0.85rem;
            color: #666;
        }

        .announcement-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tag {
            background: #f3f4f6;
            color: #374151;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
        }

        .announcement-actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .action-btn.edit {
            background: transparent;
            color: #2563eb;
            border: 1px solid #2563eb;
        }

        .action-btn.edit:hover {
            background: #eff6ff;
        }

        .action-btn.delete {
            background: transparent;
            color: #dc2626;
            border: 1px solid #dc2626;
        }

        .action-btn.delete:hover {
            background: #fee2e2;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #888;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .empty-state small {
            color: #999;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            margin: 2rem auto;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE/Edge */
        }

        .modal-content::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Edge */
        }

        .modal-header {
            position: sticky;
            top: 0;
            z-index: 10;
            padding: 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #dc2626;
            color: white;
            border-radius: 12px 12px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: white;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            transition: transform 0.3s ease;
        }

        .close-btn:hover {
            transform: scale(1.1);
        }

        .modal-body {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-primary {
            background: #dc2626;
            color: white;
        }

        .btn-primary:hover {
            background: #b91c1c;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .multi-select-container {
            position: relative;
        }

        .multi-select-display {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: border-color 0.3s ease;
        }

        .multi-select-display:hover {
            border-color: #ccc;
        }

        .multi-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-top: 0.25rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 10;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            transition: background 0.2s;
        }

        .checkbox-item:hover {
            background: #f3f4f6;
        }

        .checkbox-item input[type="checkbox"] {
            margin: 0 0.5rem 0 0;
            width: 16px;
            height: 16px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .checkbox-item span {
            line-height: 16px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../assets/logo.jpg" alt="OCA Logo" class="logo">
            <div class="header-title">Culture and Arts - Dashboard</div>
        </div>
        <div class="header-right">
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-greeting">
                <h3>
                    <span class="greeting-hi">Hi,</span>
                    <span class="greeting-name"><?php echo htmlspecialchars(explode(' ', $user_name)[0] ?? 'Staff'); ?></span>
                </h3>
                <p><?php echo strtoupper($user_role); ?><?php if (!$isDirector): ?> - <?php echo strtoupper($display_campus); ?><?php endif; ?></p>
            </div>
            <nav>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="announcements.php" class="nav-link active">
                            Announcements
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="dashboard.php?section=student-profiles" class="nav-link">
                            Student Artist Profiles
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="dashboard.php?section=events-trainings" class="nav-link">
                            Events & Trainings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="dashboard.php?section=reports-analytics" class="nav-link">
                            Reports & Analytics
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="inventory.php" class="nav-link">
                            Inventory
                        </a>
                    </li>
                    <?php if ($canManage): ?>
                    <li class="nav-item">
                        <a href="archives.php" class="nav-link">
                            Archives
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Announcements</h1>
                    <p class="page-subtitle">Manage announcements for students and staff</p>
                </div>
                <?php if ($user_role !== 'head'): ?>
                <button class="add-btn" onclick="openAnnouncementModal()">
                    <span>+</span>
                    Create Announcement
                </button>
                <?php endif; ?>
            </div>

            <div class="content-panel">
                <div class="panel-header">
                    <h3 class="panel-title">All Announcements</h3>
                </div>
                <div class="panel-content" id="announcementsContent">
                    <div class="empty-state">
                        <p>Loading announcements...</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Announcement Modal -->
    <div id="announcementModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Create Announcement</h3>
                <button class="close-btn" onclick="closeAnnouncementModal()">&times;</button>
            </div>
            <form id="announcementForm">
                <input type="hidden" id="announcementId" name="id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" required placeholder="Enter announcement title">
                    </div>

                    <div class="form-group">
                        <label for="content">Content *</label>
                        <textarea id="content" name="content" required placeholder="Enter announcement content"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="targetAudience">Target Audience *</label>
                            <select id="targetAudience" name="target_audience" required>
                                <option value="all">All</option>
                                <option value="students">Students Only</option>
                                <option value="staff">Staff Only</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="targetCampus">Target Campus *</label>
                            <select id="targetCampus" name="target_campus" required>
                                <option value="all">All Campuses</option>
                                <option value="Pablo Borbon">Pablo Borbon</option>
                                <option value="Alangilan">Alangilan</option>
                                <option value="Lipa">Lipa</option>
                                <option value="ARASOF Nasugbu">ARASOF Nasugbu</option>
                                <option value="JPLPC Malvar">JPLPC Malvar</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="culturalGroups">Target Cultural Groups (Optional)</label>
                        <div class="multi-select-container">
                            <div class="multi-select-display" id="culturalGroupsDisplay" onclick="toggleDropdown()">
                                <span class="placeholder">All Groups</span>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="multi-select-dropdown" id="culturalGroupsDropdown" style="display: none;">
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="all" checked>
                                    <span>All Groups</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Dulaang Batangan">
                                    <span>Dulaang Batangan</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="BatStateU Dance Company">
                                    <span>BatStateU Dance Company</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Diwayanis Dance Theatre">
                                    <span>Diwayanis Dance Theatre</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="BatStateU Band">
                                    <span>BatStateU Band</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Indak Yaman Dance Varsity">
                                    <span>Indak Yaman Dance Varsity</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Ritmo Voice">
                                    <span>Ritmo Voice</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Sandugo Dance Group">
                                    <span>Sandugo Dance Group</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Areglo Band">
                                    <span>Areglo Band</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Teatro Aliwana">
                                    <span>Teatro Aliwana</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="The Levites">
                                    <span>The Levites</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Melophiles">
                                    <span>Melophiles</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Sindayog">
                                    <span>Sindayog</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="publishDate">Publish Date</label>
                            <input type="date" id="publishDate" name="publish_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="expiryDate">Expiry Date (Optional)</label>
                            <input type="date" id="expiryDate" name="expiry_date">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="isPinned" name="is_pinned" value="1">
                        <label for="isPinned">Pin this announcement to the top</label>
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="isPublished" name="is_published" value="1" checked>
                        <label for="isPublished">Publish immediately</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAnnouncementModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Announcement</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Event Modal (for Head users to publish events) -->
    <div class="modal" id="eventModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="eventModalTitle">Publish Event</h3>
                <button class="close-btn" onclick="closeEventModal()">&times;</button>
            </div>
            <form id="eventForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="eventTitle">Event Title*</label>
                        <input type="text" id="eventTitle" name="title" placeholder="Enter event title" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="eventDescription">Description*</label>
                        <textarea id="eventDescription" name="description" placeholder="Enter event description" required></textarea>
                    </div>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="startDate">Start Date*</label>
                            <input type="date" id="startDate" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label for="endDate">End Date*</label>
                            <input type="date" id="endDate" name="end_date" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="eventLocation">Location*</label>
                        <input type="text" id="eventLocation" name="location" placeholder="Enter event location" required>
                    </div>

                    <div class="form-group">
                        <label for="eventCampus">Campus*</label>
                        <select id="eventCampus" name="municipality" required>
                            <option value="Pablo Borbon">Pablo Borbon</option>
                            <option value="Alangilan">Alangilan</option>
                            <option value="Lipa">Lipa</option>
                            <option value="ARASOF Nasugbu">ARASOF Nasugbu</option>
                            <option value="JPLPC Malvar">JPLPC Malvar</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="eventCulturalGroups">Cultural Group(s) Concerned</label>
                        <div class="multi-select-container">
                            <div class="multi-select-display" id="eventCulturalGroupsDisplay" onclick="toggleEventCulturalGroupsDropdown()">
                                <span class="placeholder">Select cultural groups...</span>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="multi-select-dropdown" id="eventCulturalGroupsDropdown" style="display: none;">
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Dulaang Batangan">
                                    <span>Dulaang Batangan</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="BatStateU Dance Company">
                                    <span>BatStateU Dance Company</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Diwayanis Dance Theatre">
                                    <span>Diwayanis Dance Theatre</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="BatStateU Band">
                                    <span>BatStateU Band</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Indak Yaman Dance Varsity">
                                    <span>Indak Yaman Dance Varsity</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Ritmo Voice">
                                    <span>Ritmo Voice</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Sandugo Dance Group">
                                    <span>Sandugo Dance Group</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Areglo Band">
                                    <span>Areglo Band</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Teatro Aliwana">
                                    <span>Teatro Aliwana</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="The Levites">
                                    <span>The Levites</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Melophiles">
                                    <span>Melophiles</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cultural_groups[]" value="Sindayog">
                                    <span>Sindayog</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="eventCategory">Category*</label>
                        <select id="eventCategory" name="category" required>
                            <option value="">Select category</option>
                            <option value="Training">Training</option>
                            <option value="Performance">Performance</option>
                            <option value="Competition">Competition</option>
                            <option value="Workshop">Workshop</option>
                            <option value="Cultural Event">Cultural Event</option>
                            <option value="Festival">Festival</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEventModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Event</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const userId = <?php echo $user_id; ?>;
        const isHead = <?php echo $isHead ? 'true' : 'false'; ?>;
        let editingAnnouncementId = null;

        // Load announcements on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAnnouncements();
        });

        // Load announcements
        function loadAnnouncements() {
            const content = document.getElementById('announcementsContent');
            content.innerHTML = '<div class="empty-state"><p>Loading announcements...</p></div>';

            fetch('get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayAnnouncements(data.announcements);
                    } else {
                        content.innerHTML = '<div class="empty-state"><p>Error loading announcements</p><small>' + data.message + '</small></div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = '<div class="empty-state"><p>Error loading announcements</p><small>Please try again later</small></div>';
                });
        }

        // Display announcements
        function displayAnnouncements(announcements) {
            const content = document.getElementById('announcementsContent');

            if (announcements.length === 0) {
                content.innerHTML = '<div class="empty-state"><p>No announcements available</p><small>Create a new announcement to get started</small></div>';
                return;
            }

            let html = '<div class="announcements-list">';
            announcements.forEach(announcement => {
                const isPinned = announcement.is_pinned == 1;
                const isPublished = announcement.is_published == 1;
                const createdDate = new Date(announcement.created_at).toLocaleDateString();
                const expiryDate = announcement.expiry_date ? new Date(announcement.expiry_date).toLocaleDateString() : 'No expiry';
                
                html += `
                    <div class="announcement-item ${isPinned ? 'pinned' : ''}">
                        ${isPinned ? '<div class="pinned-badge">📌 PINNED</div>' : ''}
                        <div class="announcement-header">
                            <h4 class="announcement-title">${announcement.title}</h4>
                            <div class="announcement-meta">
                                <div>${createdDate}</div>
                                ${!isPublished ? '<div style="color: #ff9800; font-weight: 600;">DRAFT</div>' : ''}
                            </div>
                        </div>
                        <div class="announcement-content">${announcement.content}</div>
                        <div class="announcement-footer">
                            <div class="announcement-tags">
                                <span class="tag">👥 ${announcement.target_audience}</span>
                                <span class="tag">🏫 ${announcement.target_campus}</span>
                                ${(announcement.priority === 'high' || announcement.priority === 'urgent') ? `<span class="tag" style="background: #fee2e2; color: #dc2626;">⚠️ ${announcement.priority.toUpperCase()}</span>` : ''}
                            </div>
                            <div class="announcement-actions">
                                ${isHead ? 
                                    `<button class="action-btn edit" onclick="publishAnnouncement(${announcement.id})">Publish</button>` :
                                    `<button class="action-btn edit" onclick="editAnnouncement(${announcement.id})">Edit</button>
                                    <button class="action-btn delete" onclick="deleteAnnouncement(${announcement.id}, '${announcement.title.replace(/'/g, "\\'")}')">Delete</button>`
                                }
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            content.innerHTML = html;
        }

        // Open announcement modal
        function openAnnouncementModal() {
            editingAnnouncementId = null;
            document.getElementById('modalTitle').textContent = 'Create Announcement';
            document.getElementById('announcementForm').reset();
            document.getElementById('announcementId').value = '';
            document.getElementById('publishDate').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('isPublished').checked = true;
            document.getElementById('announcementModal').classList.add('active');
            document.body.classList.add('modal-open');
        }

        // Close announcement modal
        function closeAnnouncementModal() {
            document.getElementById('announcementModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }

        // Edit announcement
        function editAnnouncement(id) {
            fetch('get_announcements.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.announcement) {
                        const announcement = data.announcement;
                        editingAnnouncementId = id;
                        
                        document.getElementById('modalTitle').textContent = 'Edit Announcement';
                        document.getElementById('announcementId').value = announcement.id;
                        document.getElementById('title').value = announcement.title;
                        document.getElementById('content').value = announcement.content;
                        document.getElementById('targetAudience').value = announcement.target_audience;
                        document.getElementById('targetCampus').value = announcement.target_campus;
                        document.getElementById('publishDate').value = announcement.publish_date;
                        document.getElementById('expiryDate').value = announcement.expiry_date || '';
                        document.getElementById('priority').value = announcement.priority;
                        document.getElementById('isPinned').checked = announcement.is_pinned == 1;
                        document.getElementById('isPublished').checked = announcement.is_published == 1;
                        
                        document.getElementById('announcementModal').classList.add('active');
                        document.body.classList.add('modal-open');
                    } else {
                        alert('Error loading announcement details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading announcement details');
                });
        }

        // Delete announcement
        function deleteAnnouncement(id, title) {
            if (!confirm(`Are you sure you want to delete "${title}"?`)) {
                return;
            }

            fetch('delete_announcement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Announcement deleted successfully');
                    loadAnnouncements();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting announcement');
            });
        }

        // Publish announcement as event (for head users)
        function publishAnnouncement(id) {
            fetch('get_announcements.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.announcement) {
                        const announcement = data.announcement;
                        
                        // Parse announcement content to extract event details
                        const content = announcement.content;
                        let eventData = {
                            title: announcement.title.replace('New Event: ', ''),
                            description: '',
                            startDate: '',
                            endDate: '',
                            location: '',
                            category: ''
                        };

                        // Try to parse the content for event details
                        const lines = content.split('\n');
                        let descriptionLines = [];
                        let detailsStarted = false;

                        lines.forEach(line => {
                            line = line.trim();
                            if (line.includes('Event Details:')) {
                                detailsStarted = true;
                            } else if (detailsStarted) {
                                if (line.startsWith('Date:')) {
                                    const dateRange = line.replace('Date:', '').trim();
                                    const dates = dateRange.split(' - ');
                                    if (dates.length === 2) {
                                        eventData.startDate = parseDateString(dates[0].trim());
                                        eventData.endDate = parseDateString(dates[1].trim());
                                    } else {
                                        eventData.startDate = parseDateString(dates[0].trim());
                                        eventData.endDate = eventData.startDate;
                                    }
                                } else if (line.startsWith('Location:')) {
                                    eventData.location = line.replace('Location:', '').trim();
                                } else if (line.startsWith('Category:')) {
                                    eventData.category = line.replace('Category:', '').trim();
                                }
                            } else if (line && !line.includes('Event:')) {
                                descriptionLines.push(line);
                            }
                        });

                        eventData.description = descriptionLines.join('\n').trim();

                        // Pre-fill the event form
                        document.getElementById('eventTitle').value = eventData.title;
                        document.getElementById('eventDescription').value = eventData.description;
                        document.getElementById('startDate').value = eventData.startDate;
                        document.getElementById('endDate').value = eventData.endDate;
                        document.getElementById('eventLocation').value = eventData.location;
                        document.getElementById('eventCategory').value = eventData.category;
                        document.getElementById('eventCampus').value = announcement.target_campus;

                        // Pre-select cultural groups
                        try {
                            const culturalGroups = JSON.parse(announcement.target_cultural_group);
                            const checkboxes = document.querySelectorAll('#eventCulturalGroupsDropdown input[type="checkbox"]');
                            checkboxes.forEach(cb => {
                                cb.checked = culturalGroups.includes(cb.value);
                            });
                            updateEventCulturalGroupsDisplay();
                        } catch (e) {
                            console.error('Error parsing cultural groups:', e);
                        }

                        // Open the event modal
                        document.getElementById('eventModal').classList.add('active');
                        document.body.classList.add('modal-open');
                    } else {
                        alert('Error loading announcement details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading announcement details');
                });
        }

        // Helper function to parse date string
        function parseDateString(dateStr) {
            const date = new Date(dateStr);
            if (!isNaN(date.getTime())) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }
            return '';
        }

        // Close event modal
        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
            document.body.classList.remove('modal-open');
            document.getElementById('eventForm').reset();
        }

        // Toggle event cultural groups dropdown
        function toggleEventCulturalGroupsDropdown() {
            const dropdown = document.getElementById('eventCulturalGroupsDropdown');
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        }

        // Update event cultural groups display
        function updateEventCulturalGroupsDisplay() {
            const selected = Array.from(document.querySelectorAll('#eventCulturalGroupsDropdown input:checked'))
                .map(cb => cb.nextElementSibling.textContent);
            const display = document.querySelector('#eventCulturalGroupsDisplay .placeholder');
            if (selected.length > 0) {
                display.textContent = selected.join(', ');
            } else {
                display.textContent = 'Select cultural groups...';
            }
        }

        // Event form submission
        document.getElementById('eventForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch('save_event.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Event published successfully!');
                    closeEventModal();
                    // Optionally redirect to dashboard events section
                    window.location.href = 'dashboard.php?section=events-trainings';
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error publishing event');
            });
        });

        // Add change listener to event cultural groups checkboxes
        document.querySelectorAll('#eventCulturalGroupsDropdown input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', updateEventCulturalGroupsDisplay);
        });

        // Close event dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const eventContainer = document.querySelector('#eventCulturalGroupsDisplay');
            const eventDropdown = document.getElementById('eventCulturalGroupsDropdown');
            if (eventContainer && eventDropdown && !eventContainer.contains(e.target) && !eventDropdown.contains(e.target)) {
                eventDropdown.style.display = 'none';
            }
        });

        // Submit announcement form
        document.getElementById('announcementForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            
            // Handle cultural groups selection
            const selectedGroups = Array.from(document.querySelectorAll('input[name="cultural_groups[]"]:checked'))
                .map(cb => cb.value);
            formData.delete('cultural_groups[]');
            formData.append('target_cultural_group', JSON.stringify(selectedGroups));

            fetch('save_announcement.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(editingAnnouncementId ? 'Announcement updated successfully' : 'Announcement created successfully');
                    closeAnnouncementModal();
                    loadAnnouncements();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error saving announcement');
            });
        });

        // Toggle cultural groups dropdown
        function toggleDropdown() {
            const dropdown = document.getElementById('culturalGroupsDropdown');
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const container = document.querySelector('.multi-select-container');
            if (container && !container.contains(e.target)) {
                document.getElementById('culturalGroupsDropdown').style.display = 'none';
            }
        });

        // Handle "All Groups" checkbox
        document.querySelector('input[value="all"]').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="cultural_groups[]"]:not([value="all"])');
            if (this.checked) {
                checkboxes.forEach(cb => {
                    cb.checked = false;
                    cb.disabled = true;
                });
            } else {
                checkboxes.forEach(cb => cb.disabled = false);
            }
        });

        // Close modal when clicking on backdrop
        document.getElementById('announcementModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAnnouncementModal();
            }
        });

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
    </script>
</body>
</html>
