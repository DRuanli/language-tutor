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

// Get user progress data for charts
$stmt = $conn->prepare("
    SELECT date, language, grammar_score, vocabulary_score, conversation_fluency 
    FROM user_progress 
    WHERE user_id = ? 
    ORDER BY date DESC 
    LIMIT 10
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$progress_result = $stmt->get_result();
$progress_data = [];
while ($row = $progress_result->fetch_assoc()) {
    $progress_data[] = $row;
}
$progress_data = array_reverse($progress_data);

// Get recent conversations
$stmt = $conn->prepare("
    SELECT c.conversation_id, c.language, c.conversation_mode, c.started_at, 
           COUNT(m.message_id) as message_count
    FROM conversations c
    LEFT JOIN messages m ON c.conversation_id = m.conversation_id
    WHERE c.user_id = ?
    GROUP BY c.conversation_id
    ORDER BY c.started_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$conversations_result = $stmt->get_result();

// Get common mistakes
$stmt = $conn->prepare("
    SELECT correction_type, COUNT(*) as count 
    FROM corrections c
    JOIN messages m ON c.message_id = m.message_id
    JOIN conversations conv ON m.conversation_id = conv.conversation_id
    WHERE conv.user_id = ?
    GROUP BY correction_type
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$mistakes_result = $stmt->get_result();
$mistakes_data = [];
while ($row = $mistakes_result->fetch_assoc()) {
    $mistakes_data[$row['correction_type']] = $row['count'];
}

// Get total conversations
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM conversations WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_result = $stmt->get_result();
$total_conversations = $total_result->fetch_assoc()['total'];

// Close the database connection
$stmt->close();
$conn->close();

// Convert data to JSON for JavaScript charts
$progress_json = json_encode($progress_data);
$mistakes_json = json_encode($mistakes_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Language Tutor - Dashboard</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            transform: translateY(-5px);
        }
        
        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--card-border);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
        }
        
        .card-header-pills {
            margin: -.25rem -.5rem;
        }
        
        .card-header-pills .nav-link {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            margin: 0.25rem 0.5rem;
            font-weight: 500;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Stat Cards */
        .stat-card {
            background-color: var(--primary);
            color: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            height: 100%;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card-icon {
            position: absolute;
            right: 1.5rem;
            bottom: 1.5rem;
            font-size: 4rem;
            opacity: 0.2;
            transition: var(--transition);
        }
        
        .stat-card:hover .stat-card-icon {
            transform: scale(1.1);
        }
        
        .stat-card.bg-english {
            background-color: var(--primary);
        }
        
        .stat-card.bg-german {
            background-color: var(--success);
        }
        
        .stat-card h2 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        
        .stat-card p {
            text-transform: uppercase;
            font-weight: 500;
            letter-spacing: 0.5px;
            margin-bottom: 0;
            font-size: 0.875rem;
        }
        
        /* Progress Bars */
        .progress {
            height: 0.75rem;
            background-color: rgba(67, 97, 238, 0.1);
            border-radius: 1rem;
            margin-bottom: 1rem;
            overflow: visible;
        }
        
        .progress-bar {
            position: relative;
            border-radius: 1rem;
            transition: width 1s ease;
            background-image: linear-gradient(to right, var(--primary-light), var(--primary));
        }
        
        .progress-bar::after {
            content: attr(data-progress) '%';
            position: absolute;
            right: 0;
            top: -25px;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--primary);
        }
        
        /* Buttons */
        .btn {
            padding: 0.5rem 1.5rem;
            font-weight: 500;
            border-radius: 0.5rem;
            transition: var(--transition);
        }
        
        .btn:focus {
            box-shadow: none;
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background-color: var(--secondary);
            border-color: var(--secondary);
            transform: translateY(-2px);
        }
        
        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary);
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        .btn-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            padding: 0;
            border-radius: 50%;
        }
        
        .btn-icon i {
            font-size: 1rem;
        }
        
        /* Language cards */
        .language-card {
            padding: 1.5rem;
            border-radius: var(--border-radius);
            background-color: var(--card-bg);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
            cursor: pointer;
        }
        
        .language-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }
        
        .language-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background-color: var(--primary);
        }
        
        .language-card.german::before {
            background-color: var(--success);
        }
        
        .language-card h4 {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        
        .language-card h4 i {
            margin-right: 0.5rem;
            color: var(--primary);
        }
        
        .language-card.german h4 i {
            color: var(--success);
        }
        
        .language-card .progress {
            margin-bottom: 0.5rem;
        }
        
        .language-card .level {
            font-size: 0.875rem;
            color: #6c757d;
            display: flex;
            justify-content: space-between;
        }
        
        /* Quick start buttons */
        .quick-start-btn {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            margin-bottom: 1rem;
            color: var(--dark);
            text-decoration: none;
            border: 1px solid var(--card-border);
            position: relative;
            overflow: hidden;
        }
        
        .quick-start-btn:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            color: var(--primary);
        }
        
        .quick-start-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background-color: var(--primary);
            transition: var(--transition);
        }
        
        .quick-start-btn:hover::before {
            width: 100%;
            opacity: 0.1;
        }
        
        .quick-start-btn i {
            margin-right: 1rem;
            font-size: 1.5rem;
            color: var(--primary);
            transition: var(--transition);
        }
        
        .quick-start-btn:hover i {
            transform: translateX(5px);
        }
        
        .quick-start-btn.business i {
            color: var(--secondary);
        }
        
        .quick-start-btn.travel i {
            color: var(--info);
        }
        
        /* Tables */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            padding: 1rem 1.5rem;
            background-color: rgba(67, 97, 238, 0.05);
            white-space: nowrap;
        }
        
        .table td {
            padding: 1rem 1.5rem;
            vertical-align: middle;
        }
        
        .table-hover tbody tr {
            transition: var(--transition);
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .badge {
            padding: 0.5rem 0.75rem;
            font-weight: 500;
            border-radius: 0.5rem;
        }
        
        /* Responsive */
        @media (max-width: 991.98px) {
            .navbar {
                position: fixed;
                top: 0;
                right: 0;
                left: 0;
                z-index: 1030;
            }
        }
        
        @media (max-width: 767.98px) {
            .content {
                padding: 1rem;
                padding-top: 5rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .language-card {
                padding: 1rem;
            }
            
            .quick-start-btn {
                padding: 0.75rem 1rem;
            }
            
            .quick-start-btn i {
                font-size: 1.25rem;
            }
            
            .table th, .table td {
                padding: 0.75rem 1rem;
            }
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
        
        /* Floating Action Button */
        .fab {
            position: fixed;
            right: 2rem;
            bottom: 2rem;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            z-index: 1020;
            text-decoration: none;
        }
        
        .fab:hover {
            transform: translateY(-5px) rotate(90deg);
            box-shadow: var(--shadow-lg);
            color: white;
        }
        
        .fab i {
            font-size: 1.5rem;
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
                    <a class="nav-link active" href="index.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="conversation.php">
                        <i class="fas fa-comments"></i> Conversations
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="history.php">
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
                    <h1 class="mb-1">Dashboard</h1>
                    <p class="text-muted mb-0">Welcome back, <?php echo htmlspecialchars($user['username']); ?>!</p>
                </div>
                <div class="d-none d-md-block">
                    <a href="conversation.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i> New Conversation
                    </a>
                </div>
            </div>
            
            <!-- Stats Overview -->
            <div class="row">
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <h2><?php echo $total_conversations; ?></h2>
                        <p>Total Conversations</p>
                        <i class="fas fa-comments stat-card-icon"></i>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card bg-english">
                        <h2>
                            <?php 
                                // Calculate English proficiency
                                $english_score = 0;
                                $count = 0;
                                foreach ($progress_data as $data) {
                                    if (isset($data['language']) && $data['language'] == 'English') {
                                        $english_score += ($data['grammar_score'] + $data['vocabulary_score'] + $data['conversation_fluency']) / 3;
                                        $count++;
                                    }
                                }
                                $english_proficiency = $count > 0 ? round($english_score / $count) : 0;
                                echo $english_proficiency . '%';
                            ?>
                        </h2>
                        <p>English Proficiency</p>
                        <i class="fas fa-flag-usa stat-card-icon"></i>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card bg-german">
                        <h2>
                            <?php 
                                // Calculate German proficiency
                                $german_score = 0;
                                $count = 0;
                                foreach ($progress_data as $data) {
                                    if (isset($data['language']) && $data['language'] == 'German') {
                                        $german_score += ($data['grammar_score'] + $data['vocabulary_score'] + $data['conversation_fluency']) / 3;
                                        $count++;
                                    }
                                }
                                $german_proficiency = $count > 0 ? round($german_score / $count) : 0;
                                echo $german_proficiency . '%';
                            ?>
                        </h2>
                        <p>German Proficiency</p>
                        <i class="fas fa-globe-europe stat-card-icon"></i>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--info);">
                        <h2>
                            <?php 
                                // Calculate streak
                                $streak = rand(3, 15); // Example - would normally be calculated from actual data
                                echo $streak;
                            ?>
                        </h2>
                        <p>Day Streak</p>
                        <i class="fas fa-fire-alt stat-card-icon"></i>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="row mt-4">
                <div class="col-lg-8">
                    <!-- Language Progress -->
                    <div class="card fade-in">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h5 class="mb-0">Language Progress</h5>
                            <div>
                                <ul class="nav nav-pills card-header-pills" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="overall-tab" data-bs-toggle="pill" data-bs-target="#overall" type="button" role="tab" aria-selected="true">Overall</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="english-tab" data-bs-toggle="pill" data-bs-target="#english" type="button" role="tab" aria-selected="false">English</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="german-tab" data-bs-toggle="pill" data-bs-target="#german" type="button" role="tab" aria-selected="false">German</button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="overall" role="tabpanel">
                                    <canvas id="progressChart" height="300"></canvas>
                                </div>
                                <div class="tab-pane fade" id="english" role="tabpanel">
                                    <div class="language-card">
                                        <h4><i class="fas fa-flag-usa"></i> English Skills</h4>
                                        <div class="mb-4">
                                            <label class="d-flex justify-content-between mb-1">
                                                <span>Grammar</span>
                                                <span>
                                                    <?php
                                                        $english_grammar = 0;
                                                        $count = 0;
                                                        foreach ($progress_data as $data) {
                                                            if (isset($data['language']) && $data['language'] == 'English') {
                                                                $english_grammar += $data['grammar_score'];
                                                                $count++;
                                                            }
                                                        }
                                                        $english_grammar_avg = $count > 0 ? round($english_grammar / $count) : 0;
                                                        echo $english_grammar_avg . '%';
                                                    ?>
                                                </span>
                                            </label>
                                            <div class="progress">
                                                <div class="progress-bar" role="progressbar" style="width: <?php echo $english_grammar_avg; ?>%" aria-valuenow="<?php echo $english_grammar_avg; ?>" aria-valuemin="0" aria-valuemax="100" data-progress="<?php echo $english_grammar_avg; ?>"></div>
                                            </div>
                                        </div>
                                        <div class="mb-4">
                                            <label class="d-flex justify-content-between mb-1">
                                                <span>Vocabulary</span>
                                                <span>
                                                    <?php
                                                        $english_vocab = 0;
                                                        $count = 0;
                                                        foreach ($progress_data as $data) {
                                                            if (isset($data['language']) && $data['language'] == 'English') {
                                                                $english_vocab += $data['vocabulary_score'];
                                                                $count++;
                                                            }
                                                        }
                                                        $english_vocab_avg = $count > 0 ? round($english_vocab / $count) : 0;
                                                        echo $english_vocab_avg . '%';
                                                    ?>
                                                </span>
                                            </label>
                                            <div class="progress">
                                                <div class="progress-bar" role="progressbar" style="width: <?php echo $english_vocab_avg; ?>%" aria-valuenow="<?php echo $english_vocab_avg; ?>" aria-valuemin="0" aria-valuemax="100" data-progress="<?php echo $english_vocab_avg; ?>"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="d-flex justify-content-between mb-1">
                                                <span>Fluency</span>
                                                <span>
                                                    <?php
                                                        $english_fluency = 0;
                                                        $count = 0;
                                                        foreach ($progress_data as $data) {
                                                            if (isset($data['language']) && $data['language'] == 'English') {
                                                                $english_fluency += $data['conversation_fluency'];
                                                                $count++;
                                                            }
                                                        }
                                                        $english_fluency_avg = $count > 0 ? round($english_fluency / $count) : 0;
                                                        echo $english_fluency_avg . '%';
                                                    ?>
                                                </span>
                                            </label>
                                            <div class="progress">
                                                <div class="progress-bar" role="progressbar" style="width: <?php echo $english_fluency_avg; ?>%" aria-valuenow="<?php echo $english_fluency_avg; ?>" aria-valuemin="0" aria-valuemax="100" data-progress="<?php echo $english_fluency_avg; ?>"></div>
                                            </div>
                                        </div>
                                        <div class="level mt-4">
                                            <span>Beginner</span>
                                            <span>Intermediate</span>
                                            <span>Advanced</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="german" role="tabpanel">
                                    <div class="language-card german">
                                        <h4><i class="fas fa-globe-europe"></i> German Skills</h4>
                                        <div class="mb-4">
                                            <label class="d-flex justify-content-between mb-1">
                                                <span>Grammar</span>
                                                <span>
                                                    <?php
                                                        $german_grammar = 0;
                                                        $count = 0;
                                                        foreach ($progress_data as $data) {
                                                            if (isset($data['language']) && $data['language'] == 'German') {
                                                                $german_grammar += $data['grammar_score'];
                                                                $count++;
                                                            }
                                                        }
                                                        $german_grammar_avg = $count > 0 ? round($german_grammar / $count) : 0;
                                                        echo $german_grammar_avg . '%';
                                                    ?>
                                                </span>
                                            </label>
                                            <div class="progress">
                                                <div class="progress-bar" style="background-image: linear-gradient(to right, #4cc9f0, #4895ef); width: <?php echo $german_grammar_avg; ?>%" aria-valuenow="<?php echo $german_grammar_avg; ?>" aria-valuemin="0" aria-valuemax="100" data-progress="<?php echo $german_grammar_avg; ?>"></div>
                                            </div>
                                        </div>
                                        <div class="mb-4">
                                            <label class="d-flex justify-content-between mb-1">
                                                <span>Vocabulary</span>
                                                <span>
                                                    <?php
                                                        $german_vocab = 0;
                                                        $count = 0;
                                                        foreach ($progress_data as $data) {
                                                            if (isset($data['language']) && $data['language'] == 'German') {
                                                                $german_vocab += $data['vocabulary_score'];
                                                                $count++;
                                                            }
                                                        }
                                                        $german_vocab_avg = $count > 0 ? round($german_vocab / $count) : 0;
                                                        echo $german_vocab_avg . '%';
                                                    ?>
                                                </span>
                                            </label>
                                            <div class="progress">
                                                <div class="progress-bar" style="background-image: linear-gradient(to right, #4cc9f0, #4895ef); width: <?php echo $german_vocab_avg; ?>%" aria-valuenow="<?php echo $german_vocab_avg; ?>" aria-valuemin="0" aria-valuemax="100" data-progress="<?php echo $german_vocab_avg; ?>"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="d-flex justify-content-between mb-1">
                                                <span>Fluency</span>
                                                <span>
                                                    <?php
                                                        $german_fluency = 0;
                                                        $count = 0;
                                                        foreach ($progress_data as $data) {
                                                            if (isset($data['language']) && $data['language'] == 'German') {
                                                                $german_fluency += $data['conversation_fluency'];
                                                                $count++;
                                                            }
                                                        }
                                                        $german_fluency_avg = $count > 0 ? round($german_fluency / $count) : 0;
                                                        echo $german_fluency_avg . '%';
                                                    ?>
                                                </span>
                                            </label>
                                            <div class="progress">
                                                <div class="progress-bar" style="background-image: linear-gradient(to right, #4cc9f0, #4895ef); width: <?php echo $german_fluency_avg; ?>%" aria-valuenow="<?php echo $german_fluency_avg; ?>" aria-valuemin="0" aria-valuemax="100" data-progress="<?php echo $german_fluency_avg; ?>"></div>
                                            </div>
                                        </div>
                                        <div class="level mt-4">
                                            <span>Beginner</span>
                                            <span>Intermediate</span>
                                            <span>Advanced</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Conversations -->
                    <div class="card fade-in">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h5 class="mb-0">Recent Conversations</h5>
                            <a href="history.php" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Language</th>
                                            <th>Mode</th>
                                            <th>Messages</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($conversations_result->num_rows > 0): ?>
                                            <?php while ($conversation = $conversations_result->fetch_assoc()): ?>
                                                <tr>
                                                    <td>
                                                        <i class="far fa-calendar-alt me-2 text-muted"></i>
                                                        <?php echo date('M d, Y H:i', strtotime($conversation['started_at'])); ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $conversation['language'] == 'English' ? 'primary' : 'success'; ?>">
                                                            <?php echo htmlspecialchars($conversation['language']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars(ucfirst($conversation['conversation_mode'])); ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark">
                                                            <?php echo $conversation['message_count']; ?> messages
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="view_conversation.php?id=<?php echo $conversation['conversation_id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="conversation.php?continue=<?php echo $conversation['conversation_id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4">
                                                    <p class="mb-2">No conversations yet.</p>
                                                    <a href="conversation.php" class="btn btn-primary">Start your first conversation</a>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Quick Start -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h5 class="mb-0">Quick Start</h5>
                        </div>
                        <div class="card-body">
                            <a href="conversation.php?mode=casual&language=<?php echo $user['preferred_language']; ?>" class="quick-start-btn">
                                <i class="fas fa-comment-dots"></i>
                                <div>
                                    <strong>Casual Conversation</strong>
                                    <div class="small text-muted">Everyday topics and phrases</div>
                                </div>
                            </a>
                            <a href="conversation.php?mode=business&language=<?php echo $user['preferred_language']; ?>" class="quick-start-btn business">
                                <i class="fas fa-briefcase"></i>
                                <div>
                                    <strong>Business Conversation</strong>
                                    <div class="small text-muted">Professional terms and etiquette</div>
                                </div>
                            </a>
                            <a href="conversation.php?mode=travel&language=<?php echo $user['preferred_language']; ?>" class="quick-start-btn travel">
                                <i class="fas fa-plane"></i>
                                <div>
                                    <strong>Travel Conversation</strong>
                                    <div class="small text-muted">Travel phrases and scenarios</div>
                                </div>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Common Mistakes -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h5 class="mb-0">Common Mistakes</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="mistakesChart" height="230"></canvas>
                        </div>
                    </div>
                    
                    <!-- Tips -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h5 class="mb-0">Daily Tip</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex">
                                <div class="me-3 text-primary">
                                    <i class="fas fa-lightbulb fa-2x"></i>
                                </div>
                                <div>
                                    <h6>Consistency is Key</h6>
                                    <p class="mb-0">Practice for just 15 minutes every day to see significant improvements in your language skills over time.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Floating Action Button -->
    <a href="conversation.php" class="fab">
        <i class="fas fa-plus"></i>
    </a>
    
    <!-- Bootstrap and jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Chart.js Initialization -->
    <script>
        // Mobile sidebar toggle
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                });
            }
            
            // Add toggle button to navbar for mobile
            const navbar = document.querySelector('.navbar');
            if (navbar && window.innerWidth < 992) {
                const navbarToggle = document.createElement('button');
                navbarToggle.className = 'navbar-toggler';
                navbarToggle.innerHTML = '<i class="fas fa-bars"></i>';
                navbarToggle.addEventListener('click', function() {
                    sidebar.classList.add('show');
                });
                navbar.prepend(navbarToggle);
            }
            
            // Animation for progress bars
            const progressBars = document.querySelectorAll('.progress-bar');
            setTimeout(() => {
                progressBars.forEach(bar => {
                    const width = bar.getAttribute('aria-valuenow') + '%';
                    bar.style.width = width;
                });
            }, 300);
        });
        
        // Progress chart
        const progressData = <?php echo $progress_json; ?>;
        const dates = progressData.map(item => item.date);
        const grammarScores = progressData.map(item => item.grammar_score);
        const vocabularyScores = progressData.map(item => item.vocabulary_score);
        const fluencyScores = progressData.map(item => item.conversation_fluency);
        
        const progressCtx = document.getElementById('progressChart').getContext('2d');
        const progressChart = new Chart(progressCtx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [
                    {
                        label: 'Grammar',
                        data: grammarScores,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        tension: 0.4,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointBackgroundColor: '#4361ee',
                        fill: true
                    },
                    {
                        label: 'Vocabulary',
                        data: vocabularyScores,
                        borderColor: '#3f37c9',
                        backgroundColor: 'rgba(63, 55, 201, 0.1)',
                        tension: 0.4,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointBackgroundColor: '#3f37c9',
                        fill: true
                    },
                    {
                        label: 'Fluency',
                        data: fluencyScores,
                        borderColor: '#4cc9f0',
                        backgroundColor: 'rgba(76, 201, 240, 0.1)',
                        tension: 0.4,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointBackgroundColor: '#4cc9f0',
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            }
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#212529',
                        bodyColor: '#212529',
                        borderColor: 'rgba(0, 0, 0, 0.1)',
                        borderWidth: 1,
                        titleFont: {
                            size: 14,
                            weight: 'bold',
                            family: "'Inter', sans-serif"
                        },
                        bodyFont: {
                            size: 13,
                            family: "'Inter', sans-serif"
                        },
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            },
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45,
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            }
                        }
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                animation: {
                    duration: 1000
                }
            }
        });
        
        // Mistakes chart
        const mistakesData = <?php echo $mistakes_json; ?>;
        const mistakesLabels = Object.keys(mistakesData).map(key => {
            return key.charAt(0).toUpperCase() + key.slice(1);
        });
        
        const mistakesValues = Object.values(mistakesData);
        
        const mistakesCtx = document.getElementById('mistakesChart').getContext('2d');
        const mistakesChart = new Chart(mistakesCtx, {
            type: 'doughnut',
            data: {
                labels: mistakesLabels,
                datasets: [{
                    data: mistakesValues,
                    backgroundColor: [
                        '#4361ee',
                        '#3f37c9',
                        '#4cc9f0'
                    ],
                    borderWidth: 0,
                    hoverOffset: 15
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
                            padding: 20,
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#212529',
                        bodyColor: '#212529',
                        borderColor: 'rgba(0, 0, 0, 0.1)',
                        borderWidth: 1,
                        padding: 12,
                        titleFont: {
                            size: 14,
                            weight: 'bold',
                            family: "'Inter', sans-serif"
                        },
                        bodyFont: {
                            size: 13,
                            family: "'Inter', sans-serif"
                        }
                    }
                },
                cutout: '70%',
                animation: {
                    animateScale: true,
                    animateRotate: true
                }
            }
        });
        
        // Tab control
        const triggerTabList = document.querySelectorAll('#overall-tab, #english-tab, #german-tab');
        triggerTabList.forEach(triggerEl => {
            const tabTrigger = new bootstrap.Tab(triggerEl);
            triggerEl.addEventListener('click', event => {
                event.preventDefault();
                tabTrigger.show();
            });
        });
    </script>
</body>
</html>