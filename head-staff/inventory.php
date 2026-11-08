<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin', 'director'])) {
    header('Location: ../index.php');
    exit();
}

// RBAC: Get user's campus and determine access level
$user_role = $_SESSION['user_role'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';
$user_campus_raw = $_SESSION['user_campus'] ?? null;

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
$user_campus = $campus_name_map[$user_campus_raw] ?? $user_campus_raw;

// Display campus name
// Director role should display Pablo Borbon
if ($user_role === 'director') {
    $display_campus = 'Pablo Borbon';
} else {
    $display_campus = $user_campus;
}

// Director role has view-only access to all campuses
$isDirector = ($user_role === 'director');

// Pablo Borbon Head users have full access to all campuses
$isPabloBorbonHead = ($user_role === 'head' && $user_campus === 'Pablo Borbon');

$canViewAll = ($user_role === 'admin' || $isDirector || $isPabloBorbonHead);
$canManage = !$isDirector; // Director is view-only

$pdo = getDBConnection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - Culture and Arts - BatStateU TNEU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8f9fa;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            color: #333;
        }
        
        .user-info span {
            white-space: nowrap;
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

        /* Inventory Grid */
        .inventory-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            width: 100%;
        }

        .inventory-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .panel-title-section {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }

        .panel-title {
            margin: 0;
            color: #333;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .table-header {
            display: grid;
            grid-template-columns: 2fr 60px 90px 90px 120px;
            font-size: 0.8rem;
            padding: 0.75rem 0.5rem;
            background: #dc2626;
            color: white;
            font-weight: 600;
        }

        .table-header > div {
            padding: 0 0.3rem;
        }

        .table-header > div:nth-child(2),
        .table-header > div:nth-child(3),
        .table-header > div:nth-child(4),
        .table-header > div:nth-child(5) {
            text-align: center;
        }

        .table-body {
            max-height: 500px;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 200px;
        }

        .table-row {
            display: grid;
            grid-template-columns: 2fr 60px 90px 90px 100px 80px;
            padding: 0.65rem 0.5rem;
            border-bottom: 1px solid #e0e0e0;
            align-items: center;
            font-size: 0.85rem;
        }

        .table-row > div {
            padding: 0 0.3rem;
            word-wrap: break-word;
            overflow-wrap: break-word;
            line-height: 1.3;
        }

        .table-row > div:nth-child(1) {
            font-weight: 500;
        }

        .table-row > div:nth-child(2) {
            text-align: center;
            font-weight: 600;
        }

        .table-row > div:nth-child(3),
        .table-row > div:nth-child(4) {
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .empty-state {
            padding: 2rem;
            text-align: center;
            color: #666;
        }

        /* Badges */
        .badge {
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-size: 0.7rem;
            white-space: nowrap;
            display: inline-block;
            font-weight: 600;
        }

        .badge-good {
            background: #28a745;
            color: white;
        }

        .badge-worn {
            background: #dc3545;
            color: white;
        }

        .badge-available {
            background: #28a745;
            color: white;
        }

        .badge-borrowed {
            background: #6c757d;
            color: white;
        }

        .badge-maintenance {
            background: #fd7e14;
            color: white;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 90%;
        }
        
        .modal-content.scrollable {
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            background-color: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }

        .modal-header h2 {
            margin: 0;
            color: #333;
            font-size: 1.5rem;
        }

        .close {
            color: #666;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            color: #ff5a5a;
            background-color: #f0f0f0;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }

        .form-group textarea {
            min-height: 80px;
            resize: vertical;
            font-family: inherit;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
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

        /* Floating Action Buttons */
        .floating-actions {
            position: fixed;
            right: 2rem;
            top: 50%;
            transform: translateY(-50%);
            z-index: 90;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .floating-actions.visible {
            opacity: 1;
            pointer-events: all;
        }

        .fab {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .fab::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .fab:hover::before {
            width: 100%;
            height: 100%;
        }

        .fab:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
        }

        .fab:active {
            transform: scale(0.95);
        }

        .fab-add {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
        }

        .fab-borrow {
            background: linear-gradient(135deg, #2563eb, #1e40af);
        }

        .fab-return {
            background: linear-gradient(135deg, #059669, #047857);
        }

        .fab-repair {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .fab-tooltip {
            position: absolute;
            right: 70px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            font-size: 0.9rem;
        }

        .fab:hover .fab-tooltip {
            opacity: 1;
        }

        /* Action Buttons in Table */
        .item-actions {
            display: flex;
            gap: 0.3rem;
            justify-content: center;
        }

        .icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .icon-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .icon-btn-edit {
            background: #3b82f6;
            color: white;
        }

        .icon-btn-edit:hover {
            background: #2563eb;
        }

        .icon-btn-archive {
            background: #f59e0b;
            color: white;
        }

        .icon-btn-archive:hover {
            background: #d97706;
        }

        .icon-btn-info {
            background: #8b5cf6;
            color: white;
        }

        .icon-btn-info:hover {
            background: #7c3aed;
        }

        .icon-btn-delete {
            background: #ef4444;
            color: white;
        }

        .icon-btn-delete:hover {
            background: #dc2626;
        }

        /* Update table header to include actions column */
        .table-header {
            display: grid;
            grid-template-columns: 2fr 60px 90px 90px 120px;
            font-size: 0.8rem;
            padding: 0.75rem 0.5rem;
            background: #dc2626;
            color: white;
            font-weight: 600;
        }
        
        .table-header > div:nth-child(2),
        .table-header > div:nth-child(3),
        .table-header > div:nth-child(4),
        .table-header > div:nth-child(5) {
            text-align: center;
        }

        .table-row {
            display: grid;
            grid-template-columns: 2fr 60px 90px 90px 120px;
            padding: 0.65rem 0.5rem;
            border-bottom: 1px solid #e0e0e0;
            align-items: center;
            font-size: 0.85rem;
        }

        /* Validation Error Styles */
        .error-message {
            color: #dc2626;
            font-size: 0.85rem;
            margin-top: 0.25rem;
            display: none;
        }

        .form-group.error input,
        .form-group.error select {
            border-color: #dc2626;
        }

        .form-group.error .error-message {
            display: block;
        }

        /* Badge for unavailable status */
        .badge-unavailable {
            background: #dc2626;
            color: white;
        }

        /* Action button styles */
        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }
        
        .action-btn.small {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            border-radius: 4px;
            margin: 0 0.25rem;
        }
        
        .pagination-btn:hover {
            background: #f8f9fa;
        }
        
        .pagination-btn.active {
            background: #dc2626;
            color: white;
            border-color: #dc2626;
        }

        /* Search Styles */
        .search-container {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: flex-start;
            align-items: center;
        }

        .search-wrapper {
            position: relative;
            width: 100%;
            max-width: 500px;
        }

        .search-input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 3rem;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 500;
            background: white;
            color: #374151;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .search-input:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 1.1rem;
        }

        .search-input:focus + .search-icon {
            color: #dc2626;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../assets/OCA Logo.png" alt="BatStateU Logo" class="logo">
            <h1 class="header-title">Culture and Arts - Dashboard</h1>
        </div>
        <div class="header-right">
            <?php 
            $first_name = explode(' ', $_SESSION['user_name'])[0];
            $role_display = strtoupper($user_role);
            ?>
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
                    <span class="greeting-name"><?= htmlspecialchars($first_name) ?></span>
                </h3>
                <p><?= htmlspecialchars($role_display) ?><?php if (!$isDirector): ?> - <?= htmlspecialchars($display_campus) ?><?php endif; ?></p>
            </div>
            <nav>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="announcements.php" class="nav-link">
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
                        <a href="inventory.php" class="nav-link active">
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
            <div class="page-header" id="pageHeader">
                <h1 class="page-title">Inventory</h1>
                <?php if ($canManage): ?>
                <div style="display: flex; gap: 1rem;">
                    <button class="add-btn" onclick="openAddItemModal()">
                        <i class="fas fa-plus"></i>
                        Add Item
                    </button>
                    <button class="add-btn" onclick="openBorrowRequests()" style="background: linear-gradient(135deg, #2563eb, #1e40af);">
                        <i class="fas fa-hand-holding"></i>
                        Borrow Requests
                    </button>
                    <button class="add-btn" onclick="openReturns()" style="background: linear-gradient(135deg, #059669, #047857);">
                        <i class="fas fa-undo"></i>
                        Returns
                    </button>
                    <button class="add-btn" onclick="openUnderRepair()" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <i class="fas fa-tools"></i>
                        Under Repair
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Floating Action Buttons (shown when header is scrolled out of view) -->
            <?php if ($canManage): ?>
            <div class="floating-actions">
                <button class="fab fab-add" onclick="openAddItemModal()" title="Add Item">
                    <span class="fab-tooltip">Add Item</span>
                    <i class="fas fa-plus"></i>
                </button>
                <button class="fab fab-borrow" onclick="openBorrowRequests()" title="Borrow Requests">
                    <span class="fab-tooltip">Borrow Requests</span>
                    <i class="fas fa-hand-holding"></i>
                </button>
                <button class="fab fab-return" onclick="openReturns()" title="Returns">
                    <span class="fab-tooltip">Returns</span>
                    <i class="fas fa-undo"></i>
                </button>
                <button class="fab fab-repair" onclick="openUnderRepair()" title="Under Repair">
                    <span class="fab-tooltip">Under Repair</span>
                    <i class="fas fa-tools"></i>
                </button>
            </div>
            <?php endif; ?>

            <!-- Search Section -->
            <div class="search-container">
                <div class="search-wrapper">
                    <input type="text" id="inventorySearchInput" class="search-input" placeholder="Search by item name or status..." oninput="performInventorySearch()">
                    <i class="fas fa-search search-icon"></i>
                </div>
            </div>

            <!-- Inventory Grid -->
            <div class="inventory-grid">
                <!-- Costumes Table -->
                <div>
                    <div class="inventory-panel">
                        <div class="panel-title-section">
                            <h3 class="panel-title">Costumes</h3>
                        </div>
                        <div class="table-header">
                            <div>NAME</div>
                            <div>QTY</div>
                            <div>CONDITION</div>
                            <div>STATUS</div>
                            <div>ACTIONS</div>
                        </div>
                        <div class="table-body" id="costumesTableBody">
                            <div class="empty-state">
                                <p>Loading costumes...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Equipment Table -->
                <div>
                    <div class="inventory-panel">
                        <div class="panel-title-section">
                            <h3 class="panel-title">Equipment</h3>
                        </div>
                        <div class="table-header">
                            <div>NAME</div>
                            <div>QTY</div>
                            <div>CONDITION</div>
                            <div>STATUS</div>
                            <div>ACTIONS</div>
                        </div>
                        <div class="table-body" id="equipmentTableBody">
                            <div class="empty-state">
                                <p>Loading equipment...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </main>
    </div>

    <!-- Add Item Modal -->
    <div id="addItemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Costume/Equipment</h2>
                <span class="close" onclick="closeAddItemModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addItemForm">
                    <div class="form-group">
                        <label for="itemName">Name*</label>
                        <input type="text" id="itemName" name="name" placeholder="Enter item name" required>
                        <div class="error-message"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="itemCategory">Category*</label>
                        <select id="itemCategory" name="category" required>
                            <option value="">Select category</option>
                            <option value="costume">Costume</option>
                            <option value="equipment">Equipment</option>
                        </select>
                        <div class="error-message"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="itemQuantity">Quantity*</label>
                        <input type="number" id="itemQuantity" name="quantity" placeholder="Enter quantity" required min="0" value="0" step="1">
                        <div class="error-message"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="itemCondition">Condition*</label>
                        <select id="itemCondition" name="condition" required>
                            <option value="">Select condition</option>
                            <option value="good">Good</option>
                            <option value="worn-out">Worn-out</option>
                            <option value="bad">Bad</option>
                        </select>
                        <div class="error-message"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="itemDescription">Description (Optional)</label>
                        <textarea id="itemDescription" name="description" placeholder="Enter item description"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeAddItemModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Global variables for search
        let allCostumes = [];
        let allEquipment = [];
        let searchTimeout;
        const canManage = <?php echo $canManage ? 'true' : 'false'; ?>;

        // Modal scroll prevention functions
        function preventBackgroundScroll() {
            document.body.classList.add('modal-open');
        }

        function allowBackgroundScroll() {
            document.body.classList.remove('modal-open');
        }

        // Load inventory items
        function loadInventoryItems() {
            console.log('Loading inventory items...');
            
            fetch('get_inventory_items.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Store original data for search
                        allCostumes = data.costumes || [];
                        allEquipment = data.equipment || [];
                        
                        displayCostumes(allCostumes);
                        displayEquipment(allEquipment);
                    } else {
                        console.error('Error loading inventory:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading inventory:', error);
                });
        }

        // Search functionality
        function performInventorySearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const searchTerm = document.getElementById('inventorySearchInput').value.toLowerCase().trim();
                
                if (!searchTerm) {
                    // Show all items if search is empty
                    displayCostumes(allCostumes);
                    displayEquipment(allEquipment);
                    return;
                }
                
                // Filter costumes
                const filteredCostumes = allCostumes.filter(costume => {
                    const itemName = (costume.item_name || costume.name || '').toLowerCase();
                    // Check all possible quantity fields - prioritize total quantity
                    const displayQty = costume.quantity || costume.total_quantity || costume.available_quantity || 0;
                    
                    // Determine status based on quantity (same logic as display functions)
                    const statusText = displayQty > 0 ? 'available' : 'unavailable';
                    
                    // Also check condition status
                    const conditionText = (costume.condition_status || costume.condition || '').toLowerCase();
                    
                    // Special handling for "available" search - only show items that are actually available
                    if (searchTerm === 'available') {
                        return statusText === 'available';
                    }
                    
                    return itemName.includes(searchTerm) || 
                           statusText.includes(searchTerm) ||
                           conditionText.includes(searchTerm);
                });
                
                // Filter equipment  
                const filteredEquipment = allEquipment.filter(item => {
                    const itemName = (item.item_name || item.name || '').toLowerCase();
                    // Check all possible quantity fields
                    const displayQty = item.available_quantity || item.quantity || item.total_quantity || 0;
                    
                    // Determine status based on quantity (same logic as display functions)
                    const statusText = displayQty > 0 ? 'available' : 'unavailable';
                    
                    // Also check condition status
                    const conditionText = (item.condition_status || item.condition || '').toLowerCase();
                    
                    // Special handling for "available" search - only show items that are actually available
                    if (searchTerm === 'available') {
                        return statusText === 'available';
                    }
                    
                    return itemName.includes(searchTerm) || 
                           statusText.includes(searchTerm) ||
                           conditionText.includes(searchTerm);
                });
                
                displayCostumes(filteredCostumes);
                displayEquipment(filteredEquipment);
            }, 300);
        }

        function displayCostumes(costumes) {
            const tableBody = document.getElementById('costumesTableBody');
            if (!costumes || costumes.length === 0) {
                const searchTerm = document.getElementById('inventorySearchInput').value.trim();
                const message = searchTerm ? 'No costumes match your search.' : 'No costumes found.';
                const subMessage = searchTerm
                    ? 'Try different search terms.'
                    : <?= $canManage ? "'Click \\\"Add Item\\\" to get started.'" : "''" ?>;
                tableBody.innerHTML = `<div class="empty-state"><p>${message}</p><small>${subMessage}</small></div>`;
                return;
            }
            
            let html = '';
            costumes.forEach(costume => {
                const displayQty = costume.quantity || costume.total_quantity || costume.available_quantity || 0;
                const totalQty = costume.total_quantity || costume.quantity || 0;
                const autoStatus = displayQty <= 0 ? 'unavailable' : 'available';
                const conditionStatus = costume.condition_status || costume.condition || 'good';
                
                html += '<div class="table-row">';
                html += '<div>' + (costume.item_name || costume.name || 'Unnamed Item') + '</div>';
                html += '<div>' + displayQty + '</div>';
                html += '<div>' + getConditionBadge(conditionStatus) + '</div>';
                html += '<div>' + getInventoryStatusBadge(autoStatus, displayQty) + '</div>';
                html += '<div class="item-actions">';
                html += '<button class="icon-btn icon-btn-info" onclick="viewBorrowerInfo(' + costume.id + ', \'' + (costume.item_name || costume.name || 'this item') + '\')" title="View Info"><i class="fas fa-info-circle"></i></button>';
                if (canManage) {
                    html += '<button class="icon-btn icon-btn-edit" onclick="editItem(' + costume.id + ', \'costume\')" title="Edit"><i class="fas fa-edit"></i></button>';
                    html += '<button class="icon-btn icon-btn-archive" onclick="archiveItem(' + costume.id + ', \'' + (costume.item_name || costume.name || 'this item') + '\')" title="Archive"><i class="fas fa-archive"></i></button>';
                }
                html += '</div>';
                html += '</div>';
            });
            tableBody.innerHTML = html;
        }

        function displayEquipment(equipment) {
            const tableBody = document.getElementById('equipmentTableBody');
            if (!equipment || equipment.length === 0) {
                const searchTerm = document.getElementById('inventorySearchInput').value.trim();
                const message = searchTerm ? 'No equipment matches your search.' : 'No equipment found.';
                const subMessage = searchTerm
                    ? 'Try different search terms.'
                    : <?= $canManage ? "'Click \\\"Add Item\\\" to get started.'" : "''" ?>;
                tableBody.innerHTML = `<div class="empty-state"><p>${message}</p><small>${subMessage}</small></div>`;
                return;
            }
            
            let html = '';
            equipment.forEach(item => {
                const displayQty = item.quantity || item.total_quantity || item.available_quantity || 0;
                const totalQty = item.total_quantity || item.quantity || 0;
                const autoStatus = displayQty <= 0 ? 'unavailable' : 'available';
                const conditionStatus = item.condition_status || item.condition || 'good';
                
                html += '<div class="table-row">';
                html += '<div>' + (item.item_name || item.name || 'Unnamed Item') + '</div>';
                html += '<div>' + displayQty + '</div>';
                html += '<div>' + getConditionBadge(conditionStatus) + '</div>';
                html += '<div>' + getInventoryStatusBadge(autoStatus, displayQty) + '</div>';
                html += '<div class="item-actions">';
                html += '<button class="icon-btn icon-btn-info" onclick="viewBorrowerInfo(' + item.id + ', \'' + (item.item_name || item.name || 'this item') + '\')" title="View Info"><i class="fas fa-info-circle"></i></button>';
                if (canManage) {
                    html += '<button class="icon-btn icon-btn-edit" onclick="editItem(' + item.id + ', \'equipment\')" title="Edit"><i class="fas fa-edit"></i></button>';
                    html += '<button class="icon-btn icon-btn-archive" onclick="archiveItem(' + item.id + ', \'' + (item.item_name || item.name || 'this item') + '\')" title="Archive"><i class="fas fa-archive"></i></button>';
                }
                html += '</div>';
                html += '</div>';
            });
            tableBody.innerHTML = html;
        }

        function getBorrowerInfo(item) {
            // Show borrower count if there are active borrowers (regardless of status)
            if (item.borrowers && item.borrowers.length > 0) {
                const borrowerCount = item.borrowers.length;
                return `<span style="color: #666; text-align: center; display: block; font-size: 0.85rem;">${borrowerCount} borrower${borrowerCount > 1 ? 's' : ''}</span>`;
            } else if (item.borrower_name) {
                // Fallback for single borrower
                return `<span style="color: #666; text-align: center; display: block; font-size: 0.85rem;">1 borrower</span>`;
            }
            // Default: show dash for items with no borrowers
            return '<span style="color: #aaa; text-align: center; display: block; font-size: 0.8rem;">-</span>';
        }

        function getConditionBadge(condition) {
            const badges = {
                'good': '<span class="badge badge-good">Good</span>',
                'worn-out': '<span class="badge badge-worn">Worn-out</span>',
                'bad': '<span class="badge badge-worn">Bad</span>',
                'excellent': '<span class="badge badge-good">Excellent</span>'
            };
            return badges[condition] || `<span style="color: #666; font-size: 0.7rem;">${condition || 'Unknown'}</span>`;
        }

        function getInventoryStatusBadge(status, quantity) {
            // Display based on quantity and status
            // If quantity is 0, show "Out of Stock" regardless of status
            if (quantity !== undefined && quantity <= 0) {
                return '<span class="badge badge-unavailable">Out of Stock</span>';
            }
            
            // For quantity > 0, show actual status
            const badges = {
                'available': '<span class="badge badge-available">Available</span>',
                'borrowed': '<span class="badge badge-maintenance">Borrowed</span>',
                'maintenance': '<span class="badge badge-maintenance">Maintenance</span>',
                'reserved': '<span class="badge badge-worn">Reserved</span>',
                'retired': '<span class="badge badge-unavailable">Retired</span>',
                'archived': '<span class="badge badge-unavailable">Archived</span>'
            };
            return badges[status] || `<span style="color: #666; font-size: 0.7rem;">${status || 'Available'}</span>`;
        }

        // Modal functions
        function openAddItemModal() {
            document.getElementById('addItemModal').classList.add('show');
            document.getElementById('addItemForm').reset();
            // Reset form to add mode
            document.querySelector('#addItemModal h2').textContent = 'Add Costume/Equipment';
            document.getElementById('addItemForm').removeAttribute('data-edit-id');
            clearFormErrors();
            // Prevent background scroll
            preventBackgroundScroll();
        }

        function closeAddItemModal() {
            document.getElementById('addItemModal').classList.remove('show');
            document.getElementById('addItemForm').reset();
            document.getElementById('addItemForm').removeAttribute('data-edit-id');
            clearFormErrors();
            // Restore background scroll
            allowBackgroundScroll();
        }

        // Edit item function
        function editItem(itemId, category) {
            // Fetch item details
            fetch(`get_inventory_items.php`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const items = category === 'costume' ? data.costumes : data.equipment;
                        const item = items.find(i => i.id == itemId);
                        
                        if (item) {
                            // Populate form with item data
                            document.getElementById('itemName').value = item.item_name || item.name || '';
                            document.getElementById('itemCategory').value = item.category || category;
                            document.getElementById('itemQuantity').value = item.quantity || 0;
                            document.getElementById('itemCondition').value = item.condition_status || '';
                            document.getElementById('itemDescription').value = item.description || '';
                            
                            // Set form to edit mode
                            document.querySelector('#addItemModal h2').textContent = 'Edit ' + (item.item_name || item.name || 'Item');
                            document.getElementById('addItemForm').setAttribute('data-edit-id', itemId);
                            
                            // Open modal
                            document.getElementById('addItemModal').classList.add('show');
                            // Prevent background scroll
                            preventBackgroundScroll();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading item:', error);
                    alert('Error loading item details');
                });
        }

        // Archive item function
        function archiveItem(itemId, itemName) {
            if (confirm(`Are you sure you want to archive "${itemName}"?\n\nArchived items can be restored from the Archives module.`)) {
                fetch('archive_inventory_item.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ item_id: itemId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Item archived successfully!');
                        loadInventoryItems();
                    } else {
                        alert('Error archiving item: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error archiving item:', error);
                    alert('Error archiving item. Please try again.');
                });
            }
        }

        // Form validation functions
        function validateForm() {
            clearFormErrors();
            let isValid = true;

            const name = document.getElementById('itemName');
            const category = document.getElementById('itemCategory');
            const quantity = document.getElementById('itemQuantity');
            const condition = document.getElementById('itemCondition');

            // Validate name
            if (!name.value.trim()) {
                showError(name, 'Item name is required');
                isValid = false;
            }

            // Validate category
            if (!category.value) {
                showError(category, 'Please select a category');
                isValid = false;
            }

            // Validate quantity
            if (quantity.value === '' || quantity.value < 0) {
                showError(quantity, 'Quantity must be 0 or greater');
                isValid = false;
            }

            // Validate condition
            if (!condition.value) {
                showError(condition, 'Please select a condition');
                isValid = false;
            }

            return isValid;
        }

        function showError(input, message) {
            const formGroup = input.closest('.form-group');
            formGroup.classList.add('error');
            const errorDiv = formGroup.querySelector('.error-message') || createErrorMessage(formGroup);
            errorDiv.textContent = message;
        }

        function createErrorMessage(formGroup) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            formGroup.appendChild(errorDiv);
            return errorDiv;
        }

        function clearFormErrors() {
            document.querySelectorAll('.form-group.error').forEach(group => {
                group.classList.remove('error');
            });
        }

        // Real-time validation
        document.addEventListener('DOMContentLoaded', function() {
            const quantityInput = document.getElementById('itemQuantity');
            
            // Prevent negative numbers
            quantityInput.addEventListener('input', function() {
                if (this.value < 0) {
                    this.value = 0;
                }
            });

            // Clear error on input
            document.querySelectorAll('#addItemForm input, #addItemForm select, #addItemForm textarea').forEach(input => {
                input.addEventListener('input', function() {
                    this.closest('.form-group').classList.remove('error');
                });
            });
        });

        function openBorrowRequests() {
            const modal = document.getElementById('borrowRequestsModal');
            modal.style.display = 'flex';
            loadBorrowRequests();
            // Prevent background scroll
            preventBackgroundScroll();
        }

        function closeBorrowRequestsModal() {
            const modal = document.getElementById('borrowRequestsModal');
            modal.style.display = 'none';
            // Restore background scroll
            allowBackgroundScroll();
        }

        function openReturns() {
            const modal = document.getElementById('returnsModal');
            modal.style.display = 'flex';
            loadReturnRequests();
            // Prevent background scroll
            preventBackgroundScroll();
        }

        function closeReturnsModal() {
            const modal = document.getElementById('returnsModal');
            modal.style.display = 'none';
            // Restore background scroll
            allowBackgroundScroll();
        }

        // Add item form submission
        document.getElementById('addItemForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validate form
            if (!validateForm()) {
                return;
            }
            
            const formData = new FormData(this);
            const data = Object.fromEntries(formData);
            
            // Check if editing existing item
            const editId = this.getAttribute('data-edit-id');
            if (editId) {
                data.id = editId;
            }
            
            // Ensure quantity is non-negative
            if (data.quantity < 0) {
                alert('Quantity cannot be negative');
                return;
            }
            
            fetch('save_inventory_item.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || (editId ? 'Item updated successfully!' : 'Item added successfully!'));
                    closeAddItemModal();
                    loadInventoryItems();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error saving item: ' + error.message);
            });
        });

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }

        // Borrow Requests Functions
        function loadBorrowRequests(page = 1) {
            const loadingDiv = document.getElementById('borrowRequestsLoading');
            const contentDiv = document.getElementById('borrowRequestsContent');
            const tableBody = document.getElementById('borrowRequestsTableBody');
            
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            const status = document.getElementById('statusRequestFilter').value;
            const search = document.getElementById('requestSearchInput') ? document.getElementById('requestSearchInput').value : '';
            
            const params = new URLSearchParams({ page: page, limit: 10 });
            if (status) params.append('status', status);
            if (search) params.append('search', search);
            
            fetch(`get_borrowing_requests.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    
                    if (data.success) {
                        displayBorrowRequests(data.data);
                        displayRequestsPagination(data.pagination);
                    } else {
                        tableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 2rem; color: #dc3545;">Error: ${data.error}</td></tr>`;
                    }
                })
                .catch(error => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    tableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 2rem; color: #dc3545;">Error loading requests</td></tr>`;
                });
        }
        
        function displayBorrowRequests(requests) {
            const tableBody = document.getElementById('borrowRequestsTableBody');
            if (!requests || requests.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 2rem;">No requests found</td></tr>`;
                return;
            }
            
            let html = '';
            requests.forEach(request => {
                let equipmentList = request.item_name || 'Not specified';
                const statusBadge = getStatusBadge(request.status);
                const actions = getRequestActions(request.id, request.status);
                
                html += `
                    <tr>
                        <td><div style="font-weight: 600;">${request.student_name || 'Unknown'}</div>
                            <div style="font-size: 0.8rem; color: #666;">${request.student_email || ''}</div>
                            <div style="font-size: 0.8rem; color: #666;">${request.student_campus || ''}</div></td>
                        <td>${equipmentList}</td>
                        <td>${request.dates_of_use || 'Not specified'}</td>
                        <td>${statusBadge}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            });
            tableBody.innerHTML = html;
        }
        
        function getStatusBadge(status) {
            const badges = {
                'pending': '<span style="background: #fef3c7; color: #f59e0b; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500;">Pending</span>',
                'approved': '<span style="background: #d1fae5; color: #10b981; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500;">Approved</span>',
                'rejected': '<span style="background: #fee2e2; color: #ef4444; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500;">Rejected</span>'
            };
            return badges[status] || status;
        }
        
        function getRequestActions(requestId, status) {
            if (status === 'pending') {
                return `
                    <button class="action-btn small" onclick="approveRequest(${requestId})" style="background: #10b981; color: white; margin-right: 0.5rem;">Approve</button>
                    <button class="action-btn small" onclick="rejectRequest(${requestId})" style="background: #ef4444; color: white;">Reject</button>
                `;
            } else {
                return `<button class="action-btn small" onclick="viewRequestDetails(${requestId})">View</button>`;
            }
        }
        
        function approveRequest(requestId) {
            // Fetch request details first
            fetch(`get_borrowing_requests.php?request_id=${requestId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        const request = data.data[0];
                        showApprovalModal(request);
                    } else {
                        alert('Error loading request details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading request details');
                });
        }
        
        function rejectRequest(requestId) {
            if (confirm('Are you sure you want to reject this borrow request?')) {
                updateRequestStatus(requestId, 'rejected');
            }
        }
        
        function displayRequestsPagination(pagination) {
            const paginationDiv = document.getElementById('borrowRequestsPagination');
            if (!pagination || pagination.total_pages <= 1) {
                paginationDiv.innerHTML = '';
                return;
            }
            
            let html = '<div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1rem;">';
            if (pagination.current_page > 1) {
                html += `<button class="pagination-btn" onclick="loadBorrowRequests(${pagination.current_page - 1})">Previous</button>`;
            }
            for (let i = Math.max(1, pagination.current_page - 2); i <= Math.min(pagination.total_pages, pagination.current_page + 2); i++) {
                html += `<button class="pagination-btn ${i === pagination.current_page ? 'active' : ''}" onclick="loadBorrowRequests(${i})">${i}</button>`;
            }
            if (pagination.current_page < pagination.total_pages) {
                html += `<button class="pagination-btn" onclick="loadBorrowRequests(${pagination.current_page + 1})">Next</button>`;
            }
            html += '</div>';
            paginationDiv.innerHTML = html;
        }
        
        // Return Requests Functions
        function loadReturnRequests(page = 1) {
            const loadingDiv = document.getElementById('returnsLoading');
            const contentDiv = document.getElementById('returnsContent');
            const tableBody = document.getElementById('returnRequestsTableBody');
            
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            const status = document.getElementById('statusReturnFilter').value;
            const search = document.getElementById('searchReturnFilter').value;
            
            fetch('get_return_requests.php?' + new URLSearchParams({ page: page, status: status, search: search }))
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    if (data.success) {
                        displayReturnRequests(data.requests);
                        displayReturnsPagination(data.pagination);
                    } else {
                        tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem;">Error: ${data.message}</td></tr>`;
                    }
                })
                .catch(error => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem;">Error loading returns</td></tr>`;
                });
        }
        
        function displayReturnRequests(requests) {
            const tableBody = document.getElementById('returnRequestsTableBody');
            if (!requests || requests.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem;">No return requests found</td></tr>`;
                return;
            }
            
            let html = '';
            requests.forEach(request => {
                const statusBadge = getReturnStatusBadge(request.status);
                const actions = getReturnRequestActions(request, request.status);
                
                html += `
                    <tr>
                        <td>${request.student_name}</td>
                        <td>${request.item_name}</td>
                        <td>${request.requested_at}</td>
                        <td>${request.condition_notes || '-'}</td>
                        <td>${statusBadge}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            });
            tableBody.innerHTML = html;
        }
        
        function getReturnStatusBadge(status) {
            const badges = {
                'pending': '<span style="background: #fef3c7; color: #f59e0b; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500;">Pending</span>',
                'completed': '<span style="background: #d1fae5; color: #10b981; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500;">Completed</span>',
                'confirmed': '<span style="background: #d1fae5; color: #10b981; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500;">Confirmed</span>'
            };
            return badges[status] || status;
        }
        
        let returnRequestsData = [];
        
        function getReturnRequestActions(request, status) {
            // Store request data for later retrieval
            const index = returnRequestsData.findIndex(r => r.id === request.id);
            if (index >= 0) {
                returnRequestsData[index] = request;
            } else {
                returnRequestsData.push(request);
            }
            
            if (status === 'pending') {
                return `
                    <button class="action-btn small" onclick="confirmReturn(${request.id})" style="background: #10b981; color: white; margin-right: 0.5rem;">Confirm Return</button>
                    <button class="action-btn small" onclick="viewReturnDetails(${request.id})">View</button>
                `;
            } else {
                return `<button class="action-btn small" onclick="viewReturnDetails(${request.id})">View</button>`;
            }
        }
        
        function confirmReturn(requestId) {
            if (confirm('Are you sure you want to confirm this return? This will mark the items as returned and available.')) {
                fetch('process_return_confirmation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        request_id: requestId,
                        action: 'confirm_return'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Return confirmed successfully!');
                        loadReturnRequests();
                        loadInventoryItems();
                    } else {
                        alert('Error confirming return: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error confirming return: ' + error.message);
                });
            }
        }
        
        function displayReturnsPagination(pagination) {
            const paginationDiv = document.getElementById('returnsPagination');
            if (!pagination || pagination.total_pages <= 1) {
                paginationDiv.innerHTML = '';
                return;
            }
            
            let html = '<div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1rem;">';
            if (pagination.current_page > 1) {
                html += `<button class="pagination-btn" onclick="loadReturnRequests(${pagination.current_page - 1})">Previous</button>`;
            }
            for (let i = Math.max(1, pagination.current_page - 2); i <= Math.min(pagination.total_pages, pagination.current_page + 2); i++) {
                html += `<button class="pagination-btn ${i === pagination.current_page ? 'active' : ''}" onclick="loadReturnRequests(${i})">${i}</button>`;
            }
            if (pagination.current_page < pagination.total_pages) {
                html += `<button class="pagination-btn" onclick="loadReturnRequests(${pagination.current_page + 1})">Next</button>`;
            }
            html += '</div>';
            paginationDiv.innerHTML = html;
        }
        
        function viewRequestDetails(id) { alert('View borrow request: ' + id); }
        function viewReturnDetails(id) {
            const request = returnRequestsData.find(r => r.id === id);
            if (!request) {
                alert('Return request not found');
                return;
            }
            
            const modal = document.getElementById('returnDetailsModal');
            const modalContent = document.getElementById('returnDetailsContent');
            
            modalContent.innerHTML = `
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    <h3 style="margin-bottom: 0.5rem; color: #333;">Student Information</h3>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Name:</strong> ${request.student_name}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>SR Code:</strong> ${request.sr_code || 'N/A'}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Email:</strong> ${request.email}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Campus:</strong> ${request.campus}</p>
                </div>
                
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    <h3 style="margin-bottom: 0.5rem; color: #333;">Item Information</h3>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Item Name:</strong> ${request.item_name}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Quantity Returned:</strong> ${request.quantity_returned || 1}</p>
                </div>
                
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    <h3 style="margin-bottom: 0.5rem; color: #333;">Return Information</h3>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Condition:</strong> ${request.condition_notes || 'N/A'}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Requested At:</strong> ${request.requested_at}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Status:</strong> ${request.status}</p>
                    ${request.completed_at ? `<p style="margin: 0.25rem 0; color: #666;"><strong>Completed At:</strong> ${request.completed_at}</p>` : ''}
                </div>
                
                ${request.borrow_date ? `
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px;">
                    <h3 style="margin-bottom: 0.5rem; color: #333;">Borrow Details</h3>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Borrow Date:</strong> ${request.borrow_date}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Due Date:</strong> ${request.due_date}</p>
                </div>
                ` : ''}
            `;
            
            modal.style.display = 'flex';
        }
        
        function closeReturnDetailsModal() {
            document.getElementById('returnDetailsModal').style.display = 'none';
        }

        // Approval Modal Functions
        function showApprovalModal(request) {
            const modal = document.getElementById('approvalModal');
            const requestInfo = document.getElementById('approvalRequestInfo');
            const requestedItemsList = document.getElementById('requestedItemsList');
            const availableItemsList = document.getElementById('availableItemsList');
            
            // Display request info
            requestInfo.innerHTML = `
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    <h3 style="margin-bottom: 0.5rem; color: #333;">${request.student_name || 'Unknown Student'}</h3>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Email:</strong> ${request.student_email || 'N/A'}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Campus:</strong> ${request.student_campus || 'N/A'}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Dates:</strong> ${request.dates_of_use || 'Not specified'}</p>
                    <p style="margin: 0.25rem 0; color: #666;"><strong>Purpose:</strong> ${request.purpose || 'Not specified'}</p>
                </div>
            `;
            
            // Parse and display requested items (read-only)
            const itemsText = request.item_name || request.items || '';
            let requestedHTML = '';
            
            if (itemsText.includes(',')) {
                const items = itemsText.split(',').map(i => i.trim());
                items.forEach((item, index) => {
                    const match = item.match(/(.+?)\s*\((\d+)\)/);
                    const itemName = match ? match[1].trim() : item;
                    const quantity = match ? match[2] : '1';
                    
                    requestedHTML += `
                        <div style="padding: 0.5rem; border-bottom: 1px solid #e0e0e0;">
                            <span style="color: #333; font-weight: 500;">${itemName}</span>
                        </div>
                    `;
                });
            } else {
                const match = itemsText.match(/(.+?)\s*\((\d+)\)/);
                const itemName = match ? match[1].trim() : itemsText;
                const quantity = match ? match[2] : '1';
                
                requestedHTML = `
                    <div style="padding: 0.5rem; border-bottom: 1px solid #e0e0e0;">
                        <span style="color: #333; font-weight: 500;">${itemName}</span>
                    </div>
                `;
            }
            
            requestedItemsList.innerHTML = requestedHTML;
            
            // Load available inventory items
            loadAvailableInventoryForApproval();
            
            // Store request ID for later use
            document.getElementById('confirmApprovalBtn').setAttribute('data-request-id', request.id);
            
            // Show modal
            modal.style.display = 'flex';
            // Prevent background scroll
            preventBackgroundScroll();
        }

        function loadAvailableInventoryForApproval() {
            const availableItemsList = document.getElementById('availableItemsList');
            availableItemsList.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">Loading available items...</div>';
            
            fetch('get_inventory_items.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const allItems = [...(data.costumes || []), ...(data.equipment || [])];
                        displayAvailableItems(allItems);
                    } else {
                        availableItemsList.innerHTML = '<div style="text-align: center; padding: 2rem; color: #dc2626;">Error loading items</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    availableItemsList.innerHTML = '<div style="text-align: center; padding: 2rem; color: #dc2626;">Error loading items</div>';
                });
        }

        function displayAvailableItems(items) {
            const availableItemsList = document.getElementById('availableItemsList');
            
            if (!items || items.length === 0) {
                availableItemsList.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">No items available</div>';
                return;
            }
            
            let html = '';
            items.forEach((item, index) => {
                const itemName = item.item_name || item.name || 'Unnamed Item';
                const quantity = item.quantity || 0;
                const category = item.category || 'unknown';
                const status = item.status || 'available';
                const isAvailable = status === 'available' && quantity > 0;
                
                html += `
                    <div class="available-item" style="display: flex; align-items: center; padding: 0.75rem; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 0.5rem; background: ${isAvailable ? 'white' : '#f5f5f5'}; opacity: ${isAvailable ? '1' : '0.6'};">
                        <input type="checkbox" id="avail_item_${item.id}" ${isAvailable ? '' : 'disabled'} 
                               style="margin-right: 1rem; width: 18px; height: 18px; cursor: ${isAvailable ? 'pointer' : 'not-allowed'};"
                               onchange="toggleQuantityInput(${item.id})">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #333;">
                                ${itemName}
                                <span style="background: ${category === 'costume' ? '#e0f2fe' : '#fef3c7'}; color: ${category === 'costume' ? '#0369a1' : '#a16207'}; padding: 0.15rem 0.5rem; border-radius: 12px; font-size: 0.75rem; margin-left: 0.5rem;">${category}</span>
                            </div>
                            <div style="font-size: 0.85rem; color: #666; margin-top: 0.25rem;">
                                Available: <strong>${quantity}</strong>
                                ${!isAvailable ? '<span style="color: #dc2626; margin-left: 0.5rem;">(Not available)</span>' : ''}
                            </div>
                        </div>
                        <div style="margin-left: 1rem;">
                            <input type="number" id="avail_qty_${item.id}" value="1" min="1" max="${quantity}" 
                                   disabled
                                   style="width: 70px; padding: 0.4rem; border: 1px solid #ddd; border-radius: 4px; text-align: center;">
                        </div>
                    </div>
                `;
            });
            
            availableItemsList.innerHTML = html;
        }

        function toggleQuantityInput(itemId) {
            const checkbox = document.getElementById(`avail_item_${itemId}`);
            const qtyInput = document.getElementById(`avail_qty_${itemId}`);
            
            if (checkbox && qtyInput) {
                qtyInput.disabled = !checkbox.checked;
                if (checkbox.checked) {
                    qtyInput.focus();
                }
            }
        }

        function searchAvailableItems() {
            const searchInput = document.getElementById('approvalSearchInput');
            const searchTerm = searchInput.value.toLowerCase();
            const items = document.querySelectorAll('.available-item');
            
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function closeApprovalModal() {
            document.getElementById('approvalModal').style.display = 'none';
            // Restore background scroll
            allowBackgroundScroll();
        }

        function viewBorrowerInfo(itemId, itemName) {
            const modal = document.getElementById('borrowerInfoModal');
            const loadingDiv = document.getElementById('borrowerInfoLoading');
            const contentDiv = document.getElementById('borrowerInfoContent');
            const titleElement = document.getElementById('borrowerInfoTitle');
            
            titleElement.textContent = `Borrower Information: ${itemName}`;
            modal.style.display = 'block';
            loadingDiv.style.display = 'block';
            // Prevent background scroll
            preventBackgroundScroll();
            contentDiv.style.display = 'none';
            
            // Fetch borrower information
            fetch(`get_item_borrowers.php?item_id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    
                    if (data.success) {
                        displayBorrowerInfo(data.borrowers, data.item);
                    } else {
                        contentDiv.innerHTML = `<p style="text-align: center; color: #ef4444; padding: 2rem;">${data.message || 'Error loading borrower information'}</p>`;
                    }
                })
                .catch(error => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    contentDiv.innerHTML = `<p style="text-align: center; color: #ef4444; padding: 2rem;">Error: ${error.message}</p>`;
                });
        }

        function closeBorrowerInfoModal() {
            document.getElementById('borrowerInfoModal').style.display = 'none';
            // Restore background scroll
            allowBackgroundScroll();
        }

        function openUnderRepair() {
            const modal = document.getElementById('underRepairModal');
            modal.style.display = 'flex';
            loadUnderRepairItems();
            // Prevent background scroll
            preventBackgroundScroll();
        }

        function closeUnderRepairModal() {
            const modal = document.getElementById('underRepairModal');
            modal.style.display = 'none';
            // Restore background scroll
            allowBackgroundScroll();
        }

        function loadUnderRepairItems(page = 1) {
            const loadingDiv = document.getElementById('underRepairLoading');
            const contentDiv = document.getElementById('underRepairContent');
            const tableBody = document.getElementById('underRepairTableBody');
            
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            const status = document.getElementById('repairStatusFilter').value;
            const search = document.getElementById('repairSearchInput').value;
            
            const params = new URLSearchParams({ page: page, limit: 10 });
            if (status) params.append('repair_status', status);
            if (search) params.append('search', search);
            
            // Simulate API call - you'll need to create get_repair_items.php
            fetch(`get_repair_items.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    
                    if (data.success) {
                        displayUnderRepairItems(data.items);
                    } else {
                        tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem; color: #dc3545;">Error: ${data.error || 'Failed to load repair items'}</td></tr>`;
                    }
                })
                .catch(error => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem; color: #dc3545;">Error loading repair items</td></tr>`;
                });
        }
        
        function displayUnderRepairItems(items) {
            const tableBody = document.getElementById('underRepairTableBody');
            if (!items || items.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem;">No repair items found</td></tr>`;
                return;
            }
            
            let html = '';
            items.forEach(item => {
                const statusBadge = getRepairStatusBadge(item.repair_status || item.status);
                const actions = getRepairItemActions(item.id, item.repair_status || item.status);
                
                html += `
                    <tr>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e0e0e0;">${item.item_name || item.name || 'Unknown Item'}</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e0e0e0;">${item.category || 'Unknown'}</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e0e0e0;">${item.quantity || 0}</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e0e0e0;">${statusBadge}</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e0e0e0;">${item.date_reported || 'Unknown'}</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e0e0e0;">${actions}</td>
                    </tr>
                `;
            });
            tableBody.innerHTML = html;
        }
        
        function getRepairStatusBadge(status) {
            const badges = {
                'damaged': '<span style="background: #dc2626; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;">Damaged</span>',
                'under_repair': '<span style="background: #f59e0b; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;">Under Repair</span>',
                'repaired': '<span style="background: #10b981; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;">Repaired</span>'
            };
            return badges[status] || `<span style="color: #666;">${status || 'Unknown'}</span>`;
        }
        
        function getRepairItemActions(itemId, status) {
            if (status === 'damaged') {
                return `<button class="icon-btn icon-btn-edit" onclick="markUnderRepair(${itemId})" title="Mark Under Repair" style="background: #f59e0b;"><i class="fas fa-tools"></i></button>`;
            } else if (status === 'under_repair') {
                return `<button class="icon-btn icon-btn-edit" onclick="markRepaired(${itemId})" title="Mark Repaired" style="background: #10b981;"><i class="fas fa-check"></i></button>`;
            } else {
                return `<button class="icon-btn icon-btn-info" onclick="viewRepairDetails(${itemId})" title="View Details"><i class="fas fa-info-circle"></i></button>`;
            }
        }
        
        function markUnderRepair(itemId) {
            if (confirm('Mark this item as under repair?')) {
                // You'll need to implement the backend for this
                updateRepairStatus(itemId, 'under_repair');
            }
        }
        
        function markRepaired(itemId) {
            if (confirm('Mark this item as repaired and return to inventory?')) {
                updateRepairStatus(itemId, 'repaired');
            }
        }
        
        function updateRepairStatus(itemId, status) {
            fetch('update_repair_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    item_id: itemId,
                    status: status
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Status updated successfully!');
                    loadUnderRepairItems();
                    loadInventoryItems(); // Refresh main inventory
                } else {
                    console.error('Full error details:', data);
                    let errorMsg = 'Error updating status: ' + (data.error || data.message || 'Unknown error');
                    if (data.details) {
                        errorMsg += '\nDetails: ' + JSON.stringify(data.details);
                    }
                    alert(errorMsg);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating status');
            });
        }
        
        function viewRepairDetails(itemId) {
            alert('View repair details for item: ' + itemId);
            // Implement repair details view
        }

        function displayBorrowerInfo(borrowers, item) {
            const contentDiv = document.getElementById('borrowerInfoContent');
            
            if (!borrowers || borrowers.length === 0) {
                contentDiv.innerHTML = `
                    <div style="text-align: center; padding: 2rem; color: #666;">
                        <i class="fas fa-info-circle" style="font-size: 3rem; color: #94a3b8; margin-bottom: 1rem;"></i>
                        <p style="margin: 0; font-size: 1.1rem;">No active borrowers for this item</p>
                        <small style="color: #94a3b8;">This item is currently available</small>
                    </div>
                `;
                return;
            }
            
            let html = `
                <div style="margin-bottom: 1.5rem; padding: 1rem; background: #f1f5f9; border-radius: 8px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                        <div>
                            <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">Total Quantity</div>
                            <div style="font-size: 1.25rem; font-weight: 600; color: #1e293b;">${item.total_quantity || 0}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">Available</div>
                            <div style="font-size: 1.25rem; font-weight: 600; color: #059669;">${item.available_quantity || 0}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">Borrowed</div>
                            <div style="font-size: 1.25rem; font-weight: 600; color: #dc2626;">${item.borrowed_quantity || 0}</div>
                        </div>
                    </div>
                </div>
                
                <h4 style="margin-bottom: 1rem; color: #334155; font-size: 1rem;">Active Borrowers</h4>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa; text-align: left;">
                            <th style="padding: 0.75rem; border-bottom: 2px solid #e2e8f0;">Borrower</th>
                            <th style="padding: 0.75rem; border-bottom: 2px solid #e2e8f0;">Quantity</th>
                            <th style="padding: 0.75rem; border-bottom: 2px solid #e2e8f0;">Borrow Date</th>
                            <th style="padding: 0.75rem; border-bottom: 2px solid #e2e8f0;">Due Date</th>
                            <th style="padding: 0.75rem; border-bottom: 2px solid #e2e8f0;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            borrowers.forEach((borrower, index) => {
                const borrowDate = new Date(borrower.borrow_date);
                const dueDate = new Date(borrower.due_date);
                const today = new Date();
                
                const formattedBorrowDate = borrowDate.toLocaleDateString('en-US', { 
                    month: 'short', day: 'numeric', year: 'numeric' 
                });
                const formattedDueDate = dueDate.toLocaleDateString('en-US', { 
                    month: 'short', day: 'numeric', year: 'numeric' 
                });
                
                // Check if overdue - only after 11:59 PM on the due date
                const endOfDueDate = new Date(dueDate);
                endOfDueDate.setHours(23, 59, 59, 999); // Set to 11:59:59 PM on due date
                const isOverdue = endOfDueDate < today && borrower.current_status === 'active';
                const daysOverdue = isOverdue ? Math.floor((today - endOfDueDate) / (1000 * 60 * 60 * 24)) : 0;
                
                let statusBadge = '';
                if (borrower.current_status === 'returned') {
                    statusBadge = '<span style="background: #10b981; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;">Returned</span>';
                } else if (borrower.current_status === 'pending_return') {
                    statusBadge = '<span style="background: #f59e0b; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;">Pending Return</span>';
                } else if (isOverdue) {
                    statusBadge = `<span style="background: #dc2626; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;">Overdue (${daysOverdue}d)</span>`;
                } else {
                    statusBadge = '<span style="background: #3b82f6; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;">Active</span>';
                }
                
                const rowBg = index % 2 === 0 ? '#ffffff' : '#f9fafb';
                
                html += `
                    <tr style="background: ${rowBg}; ${isOverdue ? 'border-left: 3px solid #dc2626;' : ''}">
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">
                            <div style="font-weight: 600; color: #1e293b; margin-bottom: 0.25rem;">${borrower.student_name}</div>
                            <div style="font-size: 0.85rem; color: #64748b;">${borrower.student_email || '-'}</div>
                        </td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; font-weight: 600;">${borrower.quantity || 1}</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">${formattedBorrowDate}</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">${formattedDueDate}</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">${statusBadge}</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            contentDiv.innerHTML = html;
        }

        function confirmApproval() {
            const requestId = document.getElementById('confirmApprovalBtn').getAttribute('data-request-id');
            const availableItemsList = document.getElementById('availableItemsList');
            const checkboxes = availableItemsList.querySelectorAll('input[type="checkbox"]:not([disabled])');
            
            // Collect approved items from available inventory
            let approvedItems = [];
            let hasSelectedItems = false;
            
            checkboxes.forEach((checkbox) => {
                if (checkbox.checked) {
                    hasSelectedItems = true;
                    const itemId = checkbox.id.replace('avail_item_', '');
                    const qtyInput = document.getElementById(`avail_qty_${itemId}`);
                    const itemDiv = checkbox.nextElementSibling;
                    const itemNameElement = itemDiv.querySelector('div:first-child');
                    const itemName = itemNameElement.childNodes[0].textContent.trim();
                    const quantity = qtyInput ? qtyInput.value : 1;
                    
                    approvedItems.push({
                        id: parseInt(itemId),
                        name: itemName,
                        quantity: parseInt(quantity)
                    });
                    
                    console.log('Added item:', { id: parseInt(itemId), name: itemName, quantity: parseInt(quantity) });
                }
            });
            
            if (!hasSelectedItems) {
                alert('Please select at least one item to approve');
                return;
            }
            
            console.log('Final approved items:', approvedItems);
            
            if (confirm(`Are you sure you want to approve ${approvedItems.length} item(s)?`)) {
                updateRequestStatus(requestId, 'approved', approvedItems);
                closeApprovalModal();
            }
        }

        function updateRequestStatus(requestId, status, approvedItems = null) {
            const payload = {
                request_id: requestId,
                status: status
            };
            
            if (approvedItems) {
                payload.approved_items = approvedItems;
                console.log('Sending payload with approved_items:', payload);
            }
            
            fetch('update_borrowing_request.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                console.log('Response from server:', data);
                if (data.success) {
                    alert(data.message || 'Request updated successfully!');
                    // Reload both the borrow requests and inventory
                    loadBorrowRequests();
                    loadInventoryItems(); // Refresh inventory to show updated quantities and status
                } else {
                    alert('Error: ' + (data.error || data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error updating request:', error);
                alert('Error updating request. Please try again.');
            });
        }
        document.addEventListener('DOMContentLoaded', function() {
            loadInventoryItems();
            
            // Initialize floating action buttons visibility
            initFloatingButtons();

            // Click outside modal to close functionality
            const modals = [
                'addItemModal',
                'borrowRequestsModal', 
                'returnsModal',
                'approvalModal',
                'borrowerInfoModal',
                'underRepairModal'
            ];

            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            // Call appropriate close function
                            switch(modalId) {
                                case 'addItemModal':
                                    closeAddItemModal();
                                    break;
                                case 'borrowRequestsModal':
                                    closeBorrowRequestsModal();
                                    break;
                                case 'returnsModal':
                                    closeReturnsModal();
                                    break;
                                case 'approvalModal':
                                    closeApprovalModal();
                                    break;
                                case 'borrowerInfoModal':
                                    closeBorrowerInfoModal();
                                    break;
                                case 'underRepairModal':
                                    closeUnderRepairModal();
                                    break;
                            }
                        }
                    });
                }
            });
        });

        // Show/hide floating action buttons based on scroll position
        function initFloatingButtons() {
            const pageHeader = document.getElementById('pageHeader');
            const floatingActions = document.querySelector('.floating-actions');
            
            if (!pageHeader || !floatingActions) return;
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        // Header is visible, hide floating buttons
                        floatingActions.classList.remove('visible');
                    } else {
                        // Header is not visible, show floating buttons
                        floatingActions.classList.add('visible');
                    }
                });
            }, {
                threshold: 0,
                rootMargin: '-70px 0px 0px 0px' // Account for sticky header
            });
            
            observer.observe(pageHeader);
        }
    </script>

    <!-- Borrow Requests Modal -->
    <div id="borrowRequestsModal" class="modal" style="display: none;">
        <div class="modal-content scrollable" style="max-width: 1000px; width: 95%;">
            <div class="modal-header">
                <h2>Student Borrow Requests</h2>
                <span class="close" onclick="closeBorrowRequestsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem;">Status:</label>
                        <select id="statusRequestFilter" onchange="loadBorrowRequests()" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px;">
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="">All Status</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem;">Search:</label>
                        <input type="text" id="requestSearchInput" placeholder="Search..." style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; width: 250px;">
                    </div>
                    <button onclick="loadBorrowRequests()" style="padding: 0.75rem 1rem; background: #dc2626; color: white; border: none; border-radius: 6px; cursor: pointer; align-self: end;">Refresh</button>
                </div>
                <div id="borrowRequestsLoading" style="text-align: center; padding: 2rem;"><p>Loading...</p></div>
                <div id="borrowRequestsContent" style="display: none;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left;">
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Student Info</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Items</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Dates</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Status</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="borrowRequestsTableBody"></tbody>
                    </table>
                </div>
                <div id="borrowRequestsPagination"></div>
            </div>
        </div>
    </div>

    <!-- Returns Modal -->
    <div id="returnsModal" class="modal" style="display: none;">
        <div class="modal-content scrollable" style="max-width: 1200px; width: 95%;">
            <div class="modal-header">
                <h2>Return Requests</h2>
                <span class="close" onclick="closeReturnsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem;">Status:</label>
                        <select id="statusReturnFilter" onchange="loadReturnRequests()" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px;">
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                            <option value="">All Status</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem;">Search:</label>
                        <input type="text" id="searchReturnFilter" placeholder="Search..." style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; width: 250px;">
                    </div>
                    <button onclick="loadReturnRequests()" style="padding: 0.75rem 1rem; background: #dc2626; color: white; border: none; border-radius: 6px; cursor: pointer; align-self: end;">Refresh</button>
                </div>
                <div id="returnsLoading" style="text-align: center; padding: 2rem;"><p>Loading...</p></div>
                <div id="returnsContent" style="display: none;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left;">
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Student</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Item</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Request Date</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Condition</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Status</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="returnRequestsTableBody"></tbody>
                    </table>
                </div>
                <div id="returnsPagination"></div>
            </div>
        </div>
    </div>

    <!-- Approval Modal -->
    <div id="approvalModal" class="modal" style="display: none;">
        <div class="modal-content scrollable" style="max-width: 900px; width: 95%;">
            <div class="modal-header">
                <h2>Approve Borrow Request</h2>
                <span class="close" onclick="closeApprovalModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="approvalRequestInfo"></div>
                
                <!-- Requested Items Section -->
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="margin-bottom: 0.75rem; color: #333; font-size: 1rem; display: flex; align-items: center;">
                        <i class="fas fa-clipboard-list" style="margin-right: 0.5rem; color: #dc2626;"></i>
                        Items Requested by Student:
                    </h3>
                    <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 0.75rem;">
                        <div id="requestedItemsList"></div>
                    </div>
                </div>
                
                <!-- Available Items Section -->
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="margin-bottom: 0.75rem; color: #333; font-size: 1rem; display: flex; align-items: center; justify-content: space-between;">
                        <span>
                            <i class="fas fa-box-open" style="margin-right: 0.5rem; color: #059669;"></i>
                            Select Available Items to Approve:
                        </span>
                    </h3>
                    
                    <!-- Search Bar -->
                    <div style="margin-bottom: 1rem;">
                        <input type="text" id="approvalSearchInput" placeholder="Search items by name or category..." 
                               oninput="searchAvailableItems()"
                               style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                    </div>
                    
                    <!-- Available Items List -->
                    <div id="availableItemsList" style="max-height: 300px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 6px; padding: 0.5rem; background: #f9fafb;">
                        <div style="text-align: center; padding: 2rem; color: #666;">Loading available items...</div>
                    </div>
                </div>
                
                <div style="background: #e0f2fe; border: 1px solid #0ea5e9; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    <p style="margin: 0; color: #075985; font-size: 0.9rem;">
                        <strong><i class="fas fa-info-circle"></i> Instructions:</strong> 
                        Search and select the items you want to approve from the available inventory. 
                        You can adjust quantities and only checked items will be approved.
                    </p>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeApprovalModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmApprovalBtn" onclick="confirmApproval()">
                        <i class="fas fa-check"></i> Confirm Approval
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Borrower Info Modal -->
    <div id="borrowerInfoModal" class="modal" style="display: none;">
        <div class="modal-content scrollable" style="max-width: 800px; width: 95%; margin: 5% auto;">
            <div class="modal-header">
                <h2 id="borrowerInfoTitle">Item Borrower Information</h2>
                <span class="close" onclick="closeBorrowerInfoModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="borrowerInfoLoading" style="text-align: center; padding: 2rem;">
                    <p>Loading borrower information...</p>
                </div>
                <div id="borrowerInfoContent" style="display: none;">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Return Details Modal -->
    <div id="returnDetailsModal" class="modal" style="display: none;">
        <div class="modal-content scrollable" style="max-width: 700px; width: 95%; margin: 5% auto;">
            <div class="modal-header">
                <h2>Return Request Details</h2>
                <span class="close" onclick="closeReturnDetailsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="returnDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Under Repair Modal -->
    <div id="underRepairModal" class="modal" style="display: none;">
        <div class="modal-content scrollable" style="max-width: 1000px; width: 95%;">
            <div class="modal-header">
                <h2>Under Repair Items</h2>
                <span class="close" onclick="closeUnderRepairModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem;">Filter by Status:</label>
                        <select id="repairStatusFilter" onchange="loadUnderRepairItems()" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px;">
                            <option value="damaged">Damaged</option>
                            <option value="under_repair">Under Repair</option>
                            <option value="">All Repair Items</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem;">Search:</label>
                        <input type="text" id="repairSearchInput" placeholder="Search items..." style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; width: 250px;">
                    </div>
                    <button onclick="loadUnderRepairItems()" style="padding: 0.75rem 1rem; background: #f59e0b; color: white; border: none; border-radius: 6px; cursor: pointer; align-self: end;">Refresh</button>
                </div>
                <div id="underRepairLoading" style="text-align: center; padding: 2rem;"><p>Loading...</p></div>
                <div id="underRepairContent" style="display: none;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left;">
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Item Name</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Category</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Quantity</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Status</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Date Reported</th>
                                <th style="padding: 1rem; border-bottom: 2px solid #ddd;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="underRepairTableBody"></tbody>
                    </table>
                </div>
                <div id="underRepairPagination"></div>
            </div>
        </div>
    </div>

</body>
</html>
