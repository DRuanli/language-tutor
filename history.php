<?php
// Start session
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Include database connection
require_once "includes/db.php";

// Get user information
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, preferred_language FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Initialize filter variables
$filter_language = $_GET['language'] ?? 'all';
$filter_mode = $_GET['mode'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';
$page = max(1, $_GET['page'] ?? 1);
$per_page = 10;

// Build query with filters
$query = "
    SELECT c.conversation_id, c.language, c.conversation_mode, c.started_at, 
           COUNT(m.message_id) as message_count,
           MAX(m.timestamp) as last_message_time,
           TIMESTAMPDIFF(MINUTE, c.started_at, MAX(m.timestamp)) as duration
    FROM conversations c
    LEFT JOIN messages m ON c.conversation_id = m.conversation_id
    WHERE c.user_id = ?
";

$params = [$user_id];
$types = "i";

// Apply filters
if ($filter_language !== 'all') {
    $query .= " AND c.language = ?";
    $params[] = $filter_language;
    $types .= "s";
}

if ($filter_mode !== 'all') {
    $query .= " AND c.conversation_mode = ?";
    $params[] = $filter_mode;
    $types .= "s";
}

if ($filter_date_from !== '') {
    $query .= " AND DATE(c.started_at) >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}

if ($filter_date_to !== '') {
    $query .= " AND DATE(c.started_at) <= ?";
    $params[] = $filter_date_to;
    $types .= "s";
}

if ($search_query !== '') {
    $query .= " AND EXISTS (
        SELECT 1 FROM messages 
        WHERE messages.conversation_id = c.conversation_id 
        AND content LIKE ?
    )";
    $params[] = "%$search_query%";
    $types .= "s";
}

$query .= " GROUP BY c.conversation_id";

// Apply sorting
switch ($sort_by) {
    case 'oldest':
        $query .= " ORDER BY c.started_at ASC";
        break;
    case 'longest':
        $query .= " ORDER BY message_count DESC";
        break;
    case 'shortest':
        $query .= " ORDER BY message_count ASC";
        break;
    case 'newest':
    default:
        $query .= " ORDER BY c.started_at DESC";
        break;
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM conversations WHERE user_id = ?";
$count_params = [$user_id];
$count_types = "i";

// Apply the same WHERE filters to the count query
if ($filter_language !== 'all') {
    $count_query .= " AND language = ?";
    $count_params[] = $filter_language;
    $count_types .= "s";
}

if ($filter_mode !== 'all') {
    $count_query .= " AND conversation_mode = ?";
    $count_params[] = $filter_mode;
    $count_types .= "s";
}

if ($filter_date_from !== '') {
    $count_query .= " AND DATE(started_at) >= ?";
    $count_params[] = $filter_date_from;
    $count_types .= "s";
}

if ($filter_date_to !== '') {
    $count_query .= " AND DATE(started_at) <= ?";
    $count_params[] = $filter_date_to;
    $count_types .= "s";
}

if ($search_query !== '') {
    $count_query .= " AND conversation_id IN (
        SELECT DISTINCT conversation_id FROM messages 
        WHERE content LIKE ?
    )";
    $count_params[] = "%$search_query%";
    $count_types .= "s";
}

$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_conversations = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_conversations / $per_page);

// Apply pagination
$offset = ($page - 1) * $per_page;
$query .= " LIMIT $per_page OFFSET $offset";

// Execute query
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$conversations_result = $stmt->get_result();

// Get language distribution for statistics
$lang_stmt = $conn->prepare("
    SELECT language, COUNT(*) as count 
    FROM conversations 
    WHERE user_id = ? 
    GROUP BY language
");
$lang_stmt->bind_param("i", $user_id);
$lang_stmt->execute();
$language_result = $lang_stmt->get_result();
$language_stats = [];
while ($row = $language_result->fetch_assoc()) {
    $language_stats[$row['language']] = $row['count'];
}

// Get mode distribution for statistics
$mode_stmt = $conn->prepare("
    SELECT conversation_mode, COUNT(*) as count 
    FROM conversations 
    WHERE user_id = ? 
    GROUP BY conversation_mode
");
$mode_stmt->bind_param("i", $user_id);
$mode_stmt->execute();
$mode_result = $mode_stmt->get_result();
$mode_stats = [];
while ($row = $mode_result->fetch_assoc()) {
    $mode_stats[$row['conversation_mode']] = $row['count'];
}

// Get monthly conversation count for chart
$month_stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(started_at, '%Y-%m') as month,
        COUNT(*) as count 
    FROM conversations 
    WHERE user_id = ? 
    GROUP BY DATE_FORMAT(started_at, '%Y-%m')
    ORDER BY month
    LIMIT 12
");
$month_stmt->bind_param("i", $user_id);
$month_stmt->execute();
$month_result = $month_stmt->get_result();
$month_stats = [];
$month_labels = [];

while ($row = $month_result->fetch_assoc()) {
    $date = new DateTime($row['month'] . '-01');
    $month_labels[] = $date->format('M Y');
    $month_stats[] = $row['count'];
}

// Get top corrections for statistics
$corrections_stmt = $conn->prepare("
    SELECT 
        correction_type, 
        COUNT(*) as count 
    FROM corrections c
    JOIN messages m ON c.message_id = m.message_id
    JOIN conversations conv ON m.conversation_id = conv.conversation_id
    WHERE conv.user_id = ?
    GROUP BY correction_type
    ORDER BY count DESC
    LIMIT 5
");
$corrections_stmt->bind_param("i", $user_id);
$corrections_stmt->execute();
$corrections_result = $corrections_stmt->get_result();
$correction_stats = [];
while ($row = $corrections_result->fetch_assoc()) {
    $correction_stats[$row['correction_type']] = $row['count'];
}

// Get conversation dates for calendar
$dates_stmt = $conn->prepare("
    SELECT DATE(started_at) as date, COUNT(*) as count 
    FROM conversations 
    WHERE user_id = ? 
    GROUP BY DATE(started_at)
");
$dates_stmt->bind_param("i", $user_id);
$dates_stmt->execute();
$dates_result = $dates_stmt->get_result();
$date_counts = [];
while ($row = $dates_result->fetch_assoc()) {
    $date_counts[$row['date']] = (int)$row['count'];
}

// Close the database connection
$stmt->close();
$conn->close();

// Convert stats to JSON for JS charts
$language_json = json_encode($language_stats);
$mode_json = json_encode($mode_stats);
$month_json = json_encode(['labels' => $month_labels, 'data' => $month_stats]);
$correction_json = json_encode($correction_stats);
$date_json = json_encode($date_counts);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversation History - AI Language Tutor</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Flatpickr for date picking -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f72585;
            --danger: #e63946;
            --dark: #212529;
            --light: #f8f9fa;
            --body-bg: #f5f7fa;
            --card-bg: #ffffff;
            --card-border: rgba(0, 0, 0, 0.03);
            --border-radius: 1rem;
            --transition: all 0.3s ease;
            --shadow-sm: 0 .125rem .25rem rgba(0, 0, 0, .035);
            --shadow: 0 .5rem 1rem rgba(0, 0, 0, .05);
            --shadow-lg: 0 1rem 3rem rgba(0, 0, 0, .075);
        }
        
        /* Base Styles */
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--body-bg);
            color: var(--dark);
            line-height: 1.6;
            overflow-x: hidden;
            padding-bottom: 3rem;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        /* Layout */
        .layout-wrapper {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }
        
        .content {
            flex: 1;
            min-width: 0;
            padding: 1.5rem;
            margin-left: 280px;
            transition: var(--transition);
        }
        
        @media (max-width: 991.98px) {
            .content {
                margin-left: 0;
                padding-top: 5rem;
            }
        }
        
        /* Navbar */
        .navbar {
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow);
            background-color: var(--card-bg);
            border: none;
            z-index: 1030;
            transition: var(--transition);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--primary);
        }
        
        .navbar-toggler {
            border: none;
            padding: 0;
        }
        
        .navbar-toggler:focus {
            box-shadow: none;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 280px;
            background-color: var(--card-bg);
            box-shadow: var(--shadow);
            z-index: 1040;
            transition: var(--transition);
            overflow-y: auto;
            padding-top: 1.5rem;
        }
        
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
        }
        
        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .sidebar-logo {
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--primary);
            text-decoration: none;
        }
        
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--dark);
            padding: 0;
            cursor: pointer;
        }
        
        @media (max-width: 991.98px) {
            .sidebar-toggle {
                display: block;
            }
        }
        
        .nav-pills .nav-link {
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            color: var(--dark);
            border-radius: 0;
            position: relative;
            transition: var(--transition);
        }
        
        .nav-pills .nav-link:hover {
            background-color: rgba(67, 97, 238, 0.05);
            color: var(--primary);
        }
        
        .nav-pills .nav-link.active {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            font-weight: 600;
        }
        
        .nav-pills .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background-color: var(--primary);
        }
        
        .nav-pills .nav-link i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
            transition: var(--transition);
        }
        
        .nav-pills .nav-link:hover i {
            transform: translateX(3px);
        }
        
        /* Cards */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: var(--shadow);
        }
        
        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--card-border);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Conversation Cards */
        .conversation-card {
            padding: 1.5rem;
            border-radius: var(--border-radius);
            background-color: var(--card-bg);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
            cursor: pointer;
            display: flex;
            flex-direction: column;
        }
        
        .conversation-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }
        
        .conversation-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background-color: var(--primary);
            transition: var(--transition);
        }
        
        .conversation-card.german::before {
            background-color: var(--success);
        }
        
        .conversation-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .conversation-card-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
        }
        
        .conversation-card-title i {
            margin-right: 0.5rem;
            color: var(--primary);
        }
        
        .conversation-card.german .conversation-card-title i {
            color: var(--success);
        }
        
        .conversation-date {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .conversation-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-english {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        .badge-german {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }
        
        .conversation-stats {
            display: flex;
            margin-top: 1rem;
            border-top: 1px solid var(--card-border);
            padding-top: 1rem;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-right: 1.5rem;
        }
        
        .stat-value {
            font-weight: 600;
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .conversation-actions {
            display: flex;
            margin-top: 1rem;
        }
        
        .conversation-actions .btn {
            margin-right: 0.5rem;
        }
        
        /* Filter Card */
        .filter-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .filter-header {
            padding: 1rem 1.5rem;
            background-color: rgba(67, 97, 238, 0.05);
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filter-body {
            padding: 1.5rem;
        }
        
        .filter-actions {
            margin-top: 1rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        
        /* Pagination */
        .pagination-container {
            margin-top: 2rem;
            display: flex;
            justify-content: center;
        }
        
        .pagination .page-item .page-link {
            border: none;
            color: var(--dark);
            padding: 0.5rem 0.75rem;
            transition: var(--transition);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary);
            color: white;
            border-radius: 0.5rem;
        }
        
        .pagination .page-item .page-link:hover {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        /* Stats Cards */
        .stats-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
            height: 100%;
        }
        
        .stats-card-header {
            padding: 1rem 1.5rem;
            font-weight: 600;
            border-bottom: 1px solid var(--card-border);
        }
        
        .stats-card-body {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        /* View Switcher */
        .view-switcher {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .view-switcher .btn {
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            font-weight: 500;
        }
        
        /* Timeline View */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0.75rem;
            width: 2px;
            background-color: var(--card-border);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 2rem;
        }
        
        .timeline-dot {
            position: absolute;
            left: -2rem;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 1;
        }
        
        .timeline-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            transition: var(--transition);
        }
        
        .timeline-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }
        
        /* Calendar View */
        .calendar-container {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .calendar-month {
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
        }
        
        .calendar-weekday {
            text-align: center;
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .calendar-day:hover {
            background-color: rgba(67, 97, 238, 0.1);
        }
        
        .calendar-day.has-conversation::after {
            content: '';
            position: absolute;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--primary);
            bottom: 4px;
        }
        
        .calendar-day.today {
            background-color: rgba(67, 97, 238, 0.1);
            font-weight: 700;
            color: var(--primary);
        }
        
        /* Animation utility classes */
        .fade-in {
            animation: fadeIn 0.5s;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive styles */
        @media (max-width: 991.98px) {
            .navbar {
                position: fixed;
                top: 0;
                right: 0;
                left: 0;
                z-index: 1030;
            }
            
            .filter-body {
                padding: 1rem;
            }
            
            .stats-card-body {
                padding: 1rem;
            }
        }
        
        @media (max-width: 767.98px) {
            .content {
                padding: 1rem;
                padding-top: 5rem;
            }
            
            .filter-header {
                padding: 0.75rem 1rem;
            }
            
            .conversation-card {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="layout-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="index.php" class="sidebar-logo">
                    <i class="fas fa-language me-2"></i>
                    Language Tutor
                </a>
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <ul class="nav nav-pills flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="conversation.php">
                        <i class="fas fa-comments"></i> Conversations
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="history.php">
                        <i class="fas fa-history"></i> History
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="vocabulary.php">
                        <i class="fas fa-book"></i> Vocabulary
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="progress.php">
                        <i class="fas fa-chart-line"></i> Progress
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings.php">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </li>
                <li class="nav-item mt-3">
                    <a class="nav-link text-danger" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>
        
        <!-- Main Content -->
        <main class="content">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                <div>
                    <h1 class="mb-1">Conversation History</h1>
                    <p class="text-muted mb-0">Review and analyze your past language practice sessions</p>
                </div>
                <div class="d-none d-md-block">
                    <a href="conversation.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i> New Conversation
                    </a>
                </div>
            </div>
            
            <!-- Stats Overview -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stats-card h-100">
                        <div class="stats-card-header">
                            <i class="fas fa-comments me-2"></i> Total Conversations
                        </div>
                        <div class="stats-card-body text-center">
                            <h2 class="display-4 mb-0"><?php echo $total_conversations; ?></h2>
                            <p class="text-muted mt-2">Practice sessions completed</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stats-card h-100">
                        <div class="stats-card-header">
                            <i class="fas fa-language me-2"></i> Languages
                        </div>
                        <div class="stats-card-body">
                            <canvas id="languageChart" height="150"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stats-card h-100">
                        <div class="stats-card-header">
                            <i class="fas fa-check-circle me-2"></i> Top Corrections
                        </div>
                        <div class="stats-card-body">
                            <?php if (count($correction_stats) > 0): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($correction_stats as $type => $count): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <?php echo ucfirst($type); ?>
                                            <span class="badge bg-primary rounded-pill"><?php echo $count; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="text-center text-muted">
                                    <p>No correction data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stats-card h-100">
                        <div class="stats-card-header">
                            <i class="fas fa-tag me-2"></i> Conversation Modes
                        </div>
                        <div class="stats-card-body">
                            <canvas id="modeChart" height="150"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-card mb-4">
                <div class="filter-header">
                    <div>
                        <i class="fas fa-filter me-2"></i> Filter Conversations
                    </div>
                    <button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="true" aria-controls="filterCollapse">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div class="collapse show" id="filterCollapse">
                    <div class="filter-body">
                        <form action="history.php" method="get" id="filterForm">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="language" class="form-label">Language</label>
                                    <select class="form-select" id="language" name="language">
                                        <option value="all" <?php echo $filter_language === 'all' ? 'selected' : ''; ?>>All Languages</option>
                                        <option value="English" <?php echo $filter_language === 'English' ? 'selected' : ''; ?>>English</option>
                                        <option value="German" <?php echo $filter_language === 'German' ? 'selected' : ''; ?>>German</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="mode" class="form-label">Conversation Mode</label>
                                    <select class="form-select" id="mode" name="mode">
                                        <option value="all" <?php echo $filter_mode === 'all' ? 'selected' : ''; ?>>All Modes</option>
                                        <option value="casual" <?php echo $filter_mode === 'casual' ? 'selected' : ''; ?>>Casual</option>
                                        <option value="business" <?php echo $filter_mode === 'business' ? 'selected' : ''; ?>>Business</option>
                                        <option value="travel" <?php echo $filter_mode === 'travel' ? 'selected' : ''; ?>>Travel</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="date_from" class="form-label">Date From</label>
                                    <input type="text" class="form-control datepicker" id="date_from" name="date_from" value="<?php echo $filter_date_from; ?>" placeholder="YYYY-MM-DD">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="date_to" class="form-label">Date To</label>
                                    <input type="text" class="form-control datepicker" id="date_to" name="date_to" value="<?php echo $filter_date_to; ?>" placeholder="YYYY-MM-DD">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="search" class="form-label">Search Content</label>
                                    <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search in conversations...">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="sort" class="form-label">Sort By</label>
                                    <select class="form-select" id="sort" name="sort">
                                        <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="longest" <?php echo $sort_by === 'longest' ? 'selected' : ''; ?>>Most Messages</option>
                                        <option value="shortest" <?php echo $sort_by === 'shortest' ? 'selected' : ''; ?>>Fewest Messages</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="filter-actions w-100">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="fas fa-search me-1"></i> Apply
                                        </button>
                                        <a href="history.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i> Reset
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- View Switcher -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="view-switcher">
                    <button class="btn btn-primary active" data-view="list" id="listViewBtn">
                        <i class="fas fa-list me-1"></i> List
                    </button>
                    <button class="btn btn-outline-primary" data-view="timeline" id="timelineViewBtn">
                        <i class="fas fa-stream me-1"></i> Timeline
                    </button>
                    <button class="btn btn-outline-primary" data-view="calendar" id="calendarViewBtn">
                        <i class="fas fa-calendar-alt me-1"></i> Calendar
                    </button>
                </div>
                
                <div class="text-muted">
                    Showing <?php echo min($total_conversations, $per_page); ?> of <?php echo $total_conversations; ?> conversations
                </div>
            </div>
            
            <!-- List View -->
            <div class="view-container" id="listView">
                <div class="row">
                    <?php if ($conversations_result->num_rows > 0): ?>
                        <?php while ($conversation = $conversations_result->fetch_assoc()): ?>
                            <div class="col-lg-6 mb-4">
                                <div class="conversation-card <?php echo strtolower($conversation['language']); ?> fade-in">
                                    <div class="conversation-card-header">
                                        <div>
                                            <h5 class="conversation-card-title">
                                                <i class="<?php echo $conversation['language'] === 'English' ? 'fas fa-flag-usa' : 'fas fa-globe-europe'; ?>"></i>
                                                <?php echo htmlspecialchars(ucfirst($conversation['conversation_mode'])); ?> Conversation
                                            </h5>
                                            <div class="conversation-date">
                                                <i class="far fa-calendar-alt me-1"></i>
                                                <?php echo date('M d, Y - h:i A', strtotime($conversation['started_at'])); ?>
                                            </div>
                                        </div>
                                        <span class="conversation-badge badge-<?php echo strtolower($conversation['language']); ?>">
                                            <?php echo htmlspecialchars($conversation['language']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="conversation-stats">
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $conversation['message_count']; ?></div>
                                            <div class="stat-label">Messages</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $conversation['duration'] ? $conversation['duration'] . 'm' : '-'; ?></div>
                                            <div class="stat-label">Duration</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value">
                                                <?php 
                                                    $days_ago = floor((time() - strtotime($conversation['started_at'])) / 86400);
                                                    echo $days_ago === 0 ? 'Today' : ($days_ago === 1 ? 'Yesterday' : $days_ago . ' days ago');
                                                ?>
                                            </div>
                                            <div class="stat-label">When</div>
                                        </div>
                                    </div>
                                    
                                    <div class="conversation-actions">
                                        <a href="view_conversation.php?id=<?php echo $conversation['conversation_id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye me-1"></i> View
                                        </a>
                                        <a href="conversation.php?continue=<?php echo $conversation['conversation_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-play me-1"></i> Continue
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="tooltip" title="Download Conversation">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info" role="alert">
                                <i class="fas fa-info-circle me-2"></i> No conversations found matching your filters.
                                <?php if ($filter_language !== 'all' || $filter_mode !== 'all' || $filter_date_from !== '' || $filter_date_to !== '' || $search_query !== ''): ?>
                                    <a href="history.php" class="alert-link">Clear all filters</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Timeline View -->
            <div class="view-container" id="timelineView" style="display: none;">
                <div class="timeline">
                    <?php 
                    if ($conversations_result->num_rows > 0):
                        // Reset data pointer
                        $conversations_result->data_seek(0);
                        $current_date = null;
                        while ($conversation = $conversations_result->fetch_assoc()):
                            $conversation_date = date('Y-m-d', strtotime($conversation['started_at']));
                            if ($current_date !== $conversation_date):
                                $current_date = $conversation_date;
                    ?>
                            <div class="timeline-date"><?php echo date('F j, Y', strtotime($conversation_date)); ?></div>
                    <?php endif; ?>
                            <div class="timeline-item fade-in">
                                <div class="timeline-dot">
                                    <i class="<?php echo $conversation['language'] === 'English' ? 'fas fa-flag-usa' : 'fas fa-globe-europe'; ?> fa-xs"></i>
                                </div>
                                <div class="timeline-card">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="mb-1">
                                                <?php echo htmlspecialchars(ucfirst($conversation['conversation_mode'])); ?> Conversation
                                            </h5>
                                            <div class="text-muted small">
                                                <i class="far fa-clock me-1"></i>
                                                <?php echo date('h:i A', strtotime($conversation['started_at'])); ?>
                                            </div>
                                        </div>
                                        <span class="conversation-badge badge-<?php echo strtolower($conversation['language']); ?>">
                                            <?php echo htmlspecialchars($conversation['language']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="d-flex flex-wrap gap-3 mb-3">
                                        <div class="small">
                                            <i class="fas fa-comment me-1"></i>
                                            <?php echo $conversation['message_count']; ?> messages
                                        </div>
                                        <div class="small">
                                            <i class="fas fa-clock me-1"></i>
                                            <?php echo $conversation['duration'] ? $conversation['duration'] . ' minutes' : 'Unknown duration'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex gap-2">
                                        <a href="view_conversation.php?id=<?php echo $conversation['conversation_id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye me-1"></i> View
                                        </a>
                                        <a href="conversation.php?continue=<?php echo $conversation['conversation_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-play me-1"></i> Continue
                                        </a>
                                    </div>
                                </div>
                            </div>
                    <?php 
                        endwhile;
                    else: 
                    ?>
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i> No conversations found matching your filters.
                            <?php if ($filter_language !== 'all' || $filter_mode !== 'all' || $filter_date_from !== '' || $filter_date_to !== '' || $search_query !== ''): ?>
                                <a href="history.php" class="alert-link">Clear all filters</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Calendar View -->
            <div class="view-container" id="calendarView" style="display: none;">
                <div class="calendar-container">
                    <div class="calendar-header">
                        <button class="btn btn-sm btn-outline-primary" id="prevMonth">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <div class="calendar-month" id="currentMonth"></div>
                        <button class="btn btn-sm btn-outline-primary" id="nextMonth">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    
                    <div class="calendar-grid" id="calendarGrid">
                        <!-- Weekday headers -->
                        <div class="calendar-weekday">Sun</div>
                        <div class="calendar-weekday">Mon</div>
                        <div class="calendar-weekday">Tue</div>
                        <div class="calendar-weekday">Wed</div>
                        <div class="calendar-weekday">Thu</div>
                        <div class="calendar-weekday">Fri</div>
                        <div class="calendar-weekday">Sat</div>
                        
                        <!-- Calendar days will be populated by JavaScript -->
                    </div>
                </div>
                
                <div class="mt-4" id="calendarDayDetails" style="display: none;">
                    <h5 class="mb-3" id="selectedDate"></h5>
                    <div class="row" id="dayConversations"></div>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&language=<?php echo $filter_language; ?>&mode=<?php echo $filter_mode; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($start_page + 4, $total_pages);
                            if ($end_page - $start_page < 4 && $start_page > 1) {
                                $start_page = max(1, $end_page - 4);
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&language=<?php echo $filter_language; ?>&mode=<?php echo $filter_mode; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&language=<?php echo $filter_language; ?>&mode=<?php echo $filter_mode; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Mobile Nav Toggle -->
    <div class="position-fixed top-0 start-0 p-3 d-lg-none d-block" style="z-index: 1031;">
        <button class="btn btn-primary btn-sm rounded-circle shadow" id="mobileSidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <!-- Bootstrap and jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize date pickers
            flatpickr('.datepicker', {
                dateFormat: 'Y-m-d',
                allowInput: true
            });
            
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // View switcher
            const viewSwitcherBtns = document.querySelectorAll('.view-switcher .btn');
            const viewContainers = document.querySelectorAll('.view-container');
            
            viewSwitcherBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // Update active button
                    viewSwitcherBtns.forEach(b => b.classList.remove('active', 'btn-primary'));
                    viewSwitcherBtns.forEach(b => b.classList.add('btn-outline-primary'));
                    this.classList.remove('btn-outline-primary');
                    this.classList.add('active', 'btn-primary');
                    
                    // Show selected view, hide others
                    const selectedView = this.getAttribute('data-view');
                    viewContainers.forEach(container => {
                        if (container.id === selectedView + 'View') {
                            container.style.display = 'block';
                        } else {
                            container.style.display = 'none';
                        }
                    });
                    
                    // Initialize calendar if selected
                    if (selectedView === 'calendar') {
                        initCalendar();
                    }
                });
            });
            
            // Mobile sidebar toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                });
            }
            
            if (mobileSidebarToggle) {
                mobileSidebarToggle.addEventListener('click', function() {
                    sidebar.classList.add('show');
                });
            }
            
            // Initialize charts
            initCharts();
            
            // Initialize calendar
            function initCalendar() {
                const dateCounts = <?php echo $date_json; ?>;
                const calendarGrid = document.getElementById('calendarGrid');
                const currentMonthElem = document.getElementById('currentMonth');
                const prevMonthBtn = document.getElementById('prevMonth');
                const nextMonthBtn = document.getElementById('nextMonth');
                const selectedDateElem = document.getElementById('selectedDate');
                const dayConversationsElem = document.getElementById('dayConversations');
                const calendarDayDetails = document.getElementById('calendarDayDetails');
                
                // Get current date
                let currentDate = new Date();
                let currentMonth = currentDate.getMonth();
                let currentYear = currentDate.getFullYear();
                
                // Render calendar
                renderCalendar(currentMonth, currentYear);
                
                // Event listeners for month navigation
                prevMonthBtn.addEventListener('click', function() {
                    currentMonth--;
                    if (currentMonth < 0) {
                        currentMonth = 11;
                        currentYear--;
                    }
                    renderCalendar(currentMonth, currentYear);
                });
                
                nextMonthBtn.addEventListener('click', function() {
                    currentMonth++;
                    if (currentMonth > 11) {
                        currentMonth = 0;
                        currentYear++;
                    }
                    renderCalendar(currentMonth, currentYear);
                });
                
                function renderCalendar(month, year) {
                    // Update month display
                    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                    currentMonthElem.textContent = `${monthNames[month]} ${year}`;
                    
                    // Clear grid except weekday headers
                    const dayElements = calendarGrid.querySelectorAll('.calendar-day');
                    dayElements.forEach(day => day.remove());
                    
                    // Get first day of month and total days
                    const firstDay = new Date(year, month, 1).getDay();
                    const daysInMonth = new Date(year, month + 1, 0).getDate();
                    
                    // Add empty cells for days before first of month
                    for (let i = 0; i < firstDay; i++) {
                        const emptyDay = document.createElement('div');
                        calendarGrid.appendChild(emptyDay);
                    }
                    
                    // Add days of month
                    for (let day = 1; day <= daysInMonth; day++) {
                        const dayElement = document.createElement('div');
                        dayElement.className = 'calendar-day';
                        dayElement.textContent = day;
                        
                        // Check if day has conversations
                        const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                        if (dateCounts[dateString]) {
                            dayElement.classList.add('has-conversation');
                            dayElement.dataset.count = dateCounts[dateString];
                        }
                        
                        // Check if today
                        const today = new Date();
                        if (day === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
                            dayElement.classList.add('today');
                        }
                        
                        // Add click event
                        dayElement.addEventListener('click', function() {
                            const selectedDate = new Date(year, month, day);
                            const formattedDate = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                            
                            selectedDateElem.textContent = selectedDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                            
                            // Fetch conversations for this day
                            if (dateCounts[formattedDate]) {
                                window.location.href = `history.php?date_from=${formattedDate}&date_to=${formattedDate}`;
                            } else {
                                dayConversationsElem.innerHTML = '<div class="col-12"><div class="alert alert-info">No conversations on this day.</div></div>';
                                calendarDayDetails.style.display = 'block';
                            }
                        });
                        
                        calendarGrid.appendChild(dayElement);
                    }
                }
            }
            
            // Initialize charts
            function initCharts() {
                // Language distribution chart
                const languageData = <?php echo $language_json; ?>;
                if (Object.keys(languageData).length > 0) {
                    const languageCtx = document.getElementById('languageChart').getContext('2d');
                    new Chart(languageCtx, {
                        type: 'doughnut',
                        data: {
                            labels: Object.keys(languageData),
                            datasets: [{
                                data: Object.values(languageData),
                                backgroundColor: ['#4361ee', '#4cc9f0'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        usePointStyle: true,
                                        padding: 15,
                                        font: {
                                            size: 11
                                        }
                                    }
                                }
                            },
                            cutout: '70%'
                        }
                    });
                }
                
                // Conversation modes chart
                const modeData = <?php echo $mode_json; ?>;
                if (Object.keys(modeData).length > 0) {
                    const modeCtx = document.getElementById('modeChart').getContext('2d');
                    new Chart(modeCtx, {
                        type: 'doughnut',
                        data: {
                            labels: Object.keys(modeData).map(mode => mode.charAt(0).toUpperCase() + mode.slice(1)),
                            datasets: [{
                                data: Object.values(modeData),
                                backgroundColor: ['#4361ee', '#3f37c9', '#4cc9f0'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        usePointStyle: true,
                                        padding: 15,
                                        font: {
                                            size: 11
                                        }
                                    }
                                }
                            },
                            cutout: '70%'
                        }
                    });
                }
            }
        });
    </script>
</body>
</html>