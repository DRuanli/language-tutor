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
$filter_language = $_GET['language'] ?? $user['preferred_language'];
$filter_period = $_GET['period'] ?? 'month';
$time_periods = [
    'week' => '7 DAY',
    'month' => '30 DAY',
    'quarter' => '90 DAY',
    'year' => '365 DAY',
    'all' => '10 YEAR' // effectively all data
];
$selected_period = $time_periods[$filter_period];

// Get learning goals from learning_settings
$goals_stmt = $conn->prepare("
    SELECT daily_word_goal, weekly_word_goal 
    FROM learning_settings 
    WHERE user_id = ?
");
$goals_stmt->bind_param("i", $user_id);
$goals_stmt->execute();
$goals_result = $goals_stmt->get_result();

if ($goals_result->num_rows > 0) {
    $goals = $goals_result->fetch_assoc();
} else {
    // Default goals if not set
    $goals = [
        'daily_word_goal' => 10,
        'weekly_word_goal' => 50
    ];
}

// Get current streak data
$streak_stmt = $conn->prepare("
    WITH dates AS (
        SELECT DISTINCT DATE(started_at) as practice_date
        FROM conversations
        WHERE user_id = ?
        ORDER BY practice_date DESC
    ),
    numbered_dates AS (
        SELECT 
            practice_date,
            ROW_NUMBER() OVER (ORDER BY practice_date DESC) as row_num
        FROM dates
    ),
    streaks AS (
        SELECT 
            practice_date,
            row_num,
            DATEDIFF(CURRENT_DATE(), practice_date) as days_ago
        FROM numbered_dates
        WHERE DATEDIFF(CURRENT_DATE(), practice_date) = row_num - 1
    )
    SELECT COUNT(*) as current_streak
    FROM streaks
");
$streak_stmt->bind_param("i", $user_id);
$streak_stmt->execute();
$streak_result = $streak_stmt->get_result();
$current_streak = $streak_result->fetch_assoc()['current_streak'];

// Get the longest streak
$longest_streak_stmt = $conn->prepare("
    WITH practice_dates AS (
        SELECT DISTINCT DATE(started_at) as practice_date
        FROM conversations
        WHERE user_id = ?
        ORDER BY practice_date
    ),
    date_groups AS (
        SELECT 
            practice_date,
            DATEDIFF(practice_date, 
                     (SELECT MIN(practice_date) FROM practice_dates)) - 
            ROW_NUMBER() OVER (ORDER BY practice_date) as grp
        FROM practice_dates
    ),
    streaks AS (
        SELECT 
            COUNT(*) as streak_length,
            MIN(practice_date) as streak_start,
            MAX(practice_date) as streak_end
        FROM date_groups
        GROUP BY grp
        ORDER BY streak_length DESC
        LIMIT 1
    )
    SELECT COALESCE(streak_length, 0) as longest_streak
    FROM streaks
");
$longest_streak_stmt->bind_param("i", $user_id);
$longest_streak_stmt->execute();
$longest_streak_result = $longest_streak_stmt->get_result();
$longest_streak = $longest_streak_result->fetch_assoc()['longest_streak'];

// Get proficiency score calculations
$proficiency_stmt = $conn->prepare("
    SELECT
        language,
        COUNT(*) as total_words,
        SUM(mastery_level) as total_mastery,
        ROUND(AVG(mastery_level), 2) as avg_mastery,
        COUNT(CASE WHEN mastery_level >= 4 THEN 1 END) as mastered_words
    FROM vocabulary
    WHERE user_id = ? AND language = ?
    GROUP BY language
");
$proficiency_stmt->bind_param("is", $user_id, $filter_language);
$proficiency_stmt->execute();
$proficiency_result = $proficiency_stmt->get_result();

if ($proficiency_result->num_rows > 0) {
    $proficiency = $proficiency_result->fetch_assoc();
    
    // Calculate overall proficiency score (0-100)
    if ($proficiency['total_words'] > 0) {
        $mastery_score = ($proficiency['total_mastery'] / ($proficiency['total_words'] * 5)) * 100;
        $vocabulary_size_factor = min(1, $proficiency['total_words'] / 1000); // Scale factor based on vocabulary size
        $proficiency_score = round($mastery_score * $vocabulary_size_factor);
    } else {
        $proficiency_score = 0;
        $mastery_score = 0;
        $vocabulary_size_factor = 0;
    }
} else {
    $proficiency = [
        'total_words' => 0,
        'avg_mastery' => 0,
        'mastered_words' => 0
    ];
    $proficiency_score = 0;
    $mastery_score = 0;
    $vocabulary_size_factor = 0;
}

// Get activity data for the selected time period
$activity_stmt = $conn->prepare("
    SELECT 
        DATE(started_at) as activity_date,
        COUNT(DISTINCT conversation_id) as conversation_count
    FROM conversations
    WHERE user_id = ? AND language = ? AND started_at >= DATE_SUB(CURRENT_DATE(), INTERVAL $selected_period)
    GROUP BY activity_date
    ORDER BY activity_date
");
$activity_stmt->bind_param("is", $user_id, $filter_language);
$activity_stmt->execute();
$activity_result = $activity_stmt->get_result();

$activity_data = [];
$activity_dates = [];
$activity_counts = [];

while ($row = $activity_result->fetch_assoc()) {
    $activity_data[] = $row;
    $activity_dates[] = $row['activity_date'];
    $activity_counts[] = $row['conversation_count'];
}

// Get vocabulary growth over time
$vocab_growth_stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(added_date, '%Y-%m-%d') as date,
        COUNT(*) as words_added,
        SUM(COUNT(*)) OVER (ORDER BY DATE_FORMAT(added_date, '%Y-%m-%d')) as cumulative_words
    FROM vocabulary
    WHERE user_id = ? AND language = ? AND added_date >= DATE_SUB(CURRENT_DATE(), INTERVAL $selected_period)
    GROUP BY DATE_FORMAT(added_date, '%Y-%m-%d')
    ORDER BY date
");
$vocab_growth_stmt->bind_param("is", $user_id, $filter_language);
$vocab_growth_stmt->execute();
$vocab_growth_result = $vocab_growth_stmt->get_result();

$vocab_growth_dates = [];
$vocab_growth_counts = [];
$vocab_cumulative = [];

while ($row = $vocab_growth_result->fetch_assoc()) {
    $vocab_growth_dates[] = $row['date'];
    $vocab_growth_counts[] = $row['words_added'];
    $vocab_cumulative[] = $row['cumulative_words'];
}

// Get mastery level distribution
$mastery_dist_stmt = $conn->prepare("
    SELECT 
        mastery_level,
        COUNT(*) as count
    FROM vocabulary
    WHERE user_id = ? AND language = ?
    GROUP BY mastery_level
    ORDER BY mastery_level
");
$mastery_dist_stmt->bind_param("is", $user_id, $filter_language);
$mastery_dist_stmt->execute();
$mastery_dist_result = $mastery_dist_stmt->get_result();

$mastery_levels = [0, 0, 0, 0, 0]; // Initialize with zeros
while ($row = $mastery_dist_result->fetch_assoc()) {
    $level = $row['mastery_level'] - 1; // Zero-based array index
    $mastery_levels[$level] = $row['count'];
}

// Get recent achievements data (mock data for now)
$achievements = [
    [
        'title' => '7-Day Streak',
        'description' => 'Practice for 7 consecutive days',
        'icon' => 'fire',
        'date' => date('Y-m-d', strtotime('-3 days')),
        'completed' => $current_streak >= 7
    ],
    [
        'title' => 'Vocabulary Builder',
        'description' => 'Add 50 words to your vocabulary',
        'icon' => 'book',
        'date' => date('Y-m-d', strtotime('-5 days')),
        'completed' => $proficiency['total_words'] >= 50
    ],
    [
        'title' => 'Conversation Master',
        'description' => 'Complete 10 conversations',
        'icon' => 'comments',
        'date' => date('Y-m-d', strtotime('-10 days')),
        'completed' => true
    ],
    [
        'title' => 'Word Mastery',
        'description' => 'Reach mastery level 5 with 20 words',
        'icon' => 'star',
        'date' => date('Y-m-d', strtotime('-12 days')),
        'completed' => false
    ]
];

// Get recent practice sessions
$recent_practice_stmt = $conn->prepare("
    SELECT 
        c.conversation_id,
        c.language,
        c.conversation_mode,
        c.started_at,
        COUNT(m.message_id) as message_count,
        TIMESTAMPDIFF(MINUTE, c.started_at, MAX(m.timestamp)) as duration
    FROM conversations c
    LEFT JOIN messages m ON c.conversation_id = m.conversation_id
    WHERE c.user_id = ? AND c.language = ?
    GROUP BY c.conversation_id
    ORDER BY c.started_at DESC
    LIMIT 5
");
$recent_practice_stmt->bind_param("is", $user_id, $filter_language);
$recent_practice_stmt->execute();
$recent_practice_result = $recent_practice_stmt->get_result();

// Convert activity data to JSON for charts
$activity_json = json_encode([
    'dates' => $activity_dates,
    'counts' => $activity_counts
]);

// Convert vocabulary growth data to JSON for charts
$vocab_growth_json = json_encode([
    'dates' => $vocab_growth_dates,
    'counts' => $vocab_growth_counts,
    'cumulative' => $vocab_cumulative
]);

// Convert mastery distribution data to JSON for charts
$mastery_dist_json = json_encode($mastery_levels);

// Get skill breakdown (grammar, vocabulary, listening, speaking)
// This would typically come from a dedicated assessment table
// Using placeholder calculations for demo purposes
$grammar_score = min(100, round($mastery_score * 1.1));
$vocabulary_score = min(100, round($proficiency_score * 1.2));
$listening_score = min(100, rand(40, 90)); // Placeholder
$speaking_score = min(100, rand(30, 85)); // Placeholder

$skills_json = json_encode([
    'labels' => ['Grammar', 'Vocabulary', 'Listening', 'Speaking'],
    'scores' => [$grammar_score, $vocabulary_score, $listening_score, $speaking_score]
]);

// Close database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Tracking - AI Language Tutor</title>
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
        
        /* Proficiency Meter */
        .proficiency-meter {
            position: relative;
            width: 100%;
            height: 10px;
            background-color: rgba(67, 97, 238, 0.1);
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 1rem;
        }
        
        .proficiency-bar {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background: linear-gradient(to right, #4361ee, #4cc9f0);
            border-radius: 5px;
            transition: width 2s ease;
        }
        
        .proficiency-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        /* Progress Cards */
        .progress-stat {
            padding: 1.5rem;
            border-radius: var(--border-radius);
            background-color: var(--card-bg);
            box-shadow: var(--shadow-sm);
            height: 100%;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .progress-stat:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }
        
        .progress-stat::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background-color: var(--primary);
        }
        
        .progress-stat.streak::before {
            background-color: var(--warning);
        }
        
        .progress-stat.vocabulary::before {
            background-color: var(--success);
        }
        
        .progress-stat.time::before {
            background-color: var(--info);
        }
        
        .progress-stat-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
            transition: var(--transition);
        }
        
        .progress-stat:hover .progress-stat-icon {
            transform: scale(1.1);
        }
        
        .progress-stat.streak .progress-stat-icon {
            color: var(--warning);
        }
        
        .progress-stat.vocabulary .progress-stat-icon {
            color: var(--success);
        }
        
        .progress-stat.time .progress-stat-icon {
            color: var(--info);
        }
        
        .progress-stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .progress-stat-label {
            color: #6c757d;
            font-size: 0.875rem;
        }
        
        /* Timeline */
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
        
        .timeline-item:last-child {
            margin-bottom: 0;
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
        
        .timeline-dot.completed {
            background-color: var(--success);
        }
        
        .timeline-dot.incomplete {
            background-color: var(--card-border);
            color: var(--dark);
        }
        
        .timeline-content {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            transition: var(--transition);
        }
        
        .timeline-content:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }
        
        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .timeline-title i {
            margin-right: 0.5rem;
        }
        
        .timeline-date {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        
        /* Progress Filters */
        .progress-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-dropdown {
            min-width: 200px;
        }
        
        /* Skill Chart */
        .skill-chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Activity Calendar */
        .activity-calendar {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.25rem;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            border-radius: 0.25rem;
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            transition: var(--transition);
            position: relative;
        }
        
        .calendar-day.has-activity {
            background-color: rgba(67, 97, 238, 0.1);
            border-color: var(--primary-light);
        }
        
        .calendar-day.has-activity[data-count="2"] {
            background-color: rgba(67, 97, 238, 0.3);
        }
        
        .calendar-day.has-activity[data-count="3"] {
            background-color: rgba(67, 97, 238, 0.5);
        }
        
        .calendar-day.has-activity[data-count="4"], 
        .calendar-day.has-activity[data-count="5"] {
            background-color: rgba(67, 97, 238, 0.7);
        }
        
        .calendar-day:hover {
            transform: scale(1.1);
            z-index: 1;
        }
        
        /* Recent Practice */
        .practice-item {
            padding: 1rem;
            border-bottom: 1px solid var(--card-border);
            transition: var(--transition);
        }
        
        .practice-item:last-child {
            border-bottom: none;
        }
        
        .practice-item:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .practice-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .practice-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.875rem;
            color: #6c757d;
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
        }
        
        @media (max-width: 767.98px) {
            .content {
                padding: 1rem;
                padding-top: 5rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .progress-filters {
                flex-direction: column;
            }
            
            .filter-dropdown {
                width: 100%;
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
                    <a class="nav-link active" href="progress.php">
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
                    <h1 class="mb-1">Progress Tracking</h1>
                    <p class="text-muted mb-0">Monitor your language learning journey</p>
                </div>
                
                <!-- Filters -->
                <div class="progress-filters">
                    <div class="filter-dropdown">
                        <select class="form-select" id="languageFilter" onchange="window.location = '?language=' + this.value + '&period=<?php echo $filter_period; ?>'">
                            <option value="English" <?php echo $filter_language === 'English' ? 'selected' : ''; ?>>English</option>
                            <option value="German" <?php echo $filter_language === 'German' ? 'selected' : ''; ?>>German</option>
                        </select>
                    </div>
                    
                    <div class="filter-dropdown">
                        <select class="form-select" id="periodFilter" onchange="window.location = '?language=<?php echo $filter_language; ?>&period=' + this.value">
                            <option value="week" <?php echo $filter_period === 'week' ? 'selected' : ''; ?>>Last Week</option>
                            <option value="month" <?php echo $filter_period === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="quarter" <?php echo $filter_period === 'quarter' ? 'selected' : ''; ?>>Last 90 Days</option>
                            <option value="year" <?php echo $filter_period === 'year' ? 'selected' : ''; ?>>Last Year</option>
                            <option value="all" <?php echo $filter_period === 'all' ? 'selected' : ''; ?>>All Time</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Proficiency Overview -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-chart-line me-2"></i> <?php echo $filter_language; ?> Proficiency
                </div>
                <div class="card-body">
                    <div class="row align-items-center mb-4">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <h3 class="mb-1">Level: <?php echo getProficiencyLevel($proficiency_score); ?></h3>
                            <div class="proficiency-meter">
                                <div class="proficiency-bar" style="width: <?php echo $proficiency_score; ?>%;"></div>
                            </div>
                            <div class="proficiency-labels">
                                <span>Beginner</span>
                                <span>Intermediate</span>
                                <span>Advanced</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex flex-wrap justify-content-around text-center">
                                <div class="px-3 py-2">
                                    <div class="h4 mb-0"><?php echo $proficiency['total_words']; ?></div>
                                    <div class="small text-muted">Total Words</div>
                                </div>
                                <div class="px-3 py-2">
                                    <div class="h4 mb-0"><?php echo $proficiency['mastered_words']; ?></div>
                                    <div class="small text-muted">Mastered Words</div>
                                </div>
                                <div class="px-3 py-2">
                                    <div class="h4 mb-0"><?php echo $proficiency['avg_mastery']; ?></div>
                                    <div class="small text-muted">Average Mastery</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-3">Skill Breakdown</h5>
                    <div class="skill-chart-container">
                        <canvas id="skillsChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Progress Stats -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="progress-stat streak">
                        <div class="progress-stat-icon">
                            <i class="fas fa-fire"></i>
                        </div>
                        <div class="progress-stat-value"><?php echo $current_streak; ?></div>
                        <div class="progress-stat-label">Current Streak (days)</div>
                        <div class="mt-2 small text-muted">Longest: <?php echo $longest_streak; ?> days</div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="progress-stat vocabulary">
                        <div class="progress-stat-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="progress-stat-value"><?php echo round($proficiency['avg_mastery'], 1); ?></div>
                        <div class="progress-stat-label">Average Mastery</div>
                        <div class="mt-2 small text-muted">
                            <?php echo round(($proficiency['mastered_words'] / max(1, $proficiency['total_words'])) * 100); ?>% words mastered
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="progress-stat time">
                        <div class="progress-stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="progress-stat-value">24.5h</div>
                        <div class="progress-stat-label">Total Practice Time</div>
                        <div class="mt-2 small text-muted">
                            <?php echo count($activity_data); ?> active days
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="progress-stat">
                        <div class="progress-stat-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="progress-stat-value">12</div>
                        <div class="progress-stat-label">Achievements</div>
                        <div class="mt-2 small text-muted">
                            3 unlocked this month
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Activity & Vocabulary Growth -->
            <div class="row mb-4">
                <div class="col-lg-8 mb-4">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between">
                            <div>
                                <i class="fas fa-chart-area me-2"></i> Learning Activity
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary active" id="showConversationsBtn">Conversations</button>
                                <button type="button" class="btn btn-outline-primary" id="showVocabularyBtn">Vocabulary</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="activityChartContainer">
                                <canvas id="activityChart" height="300"></canvas>
                            </div>
                            <div id="vocabularyGrowthContainer" style="display: none;">
                                <canvas id="vocabularyGrowthChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <i class="fas fa-star me-2"></i> Mastery Distribution
                        </div>
                        <div class="card-body">
                            <canvas id="masteryChart" height="250"></canvas>
                            
                            <div class="mt-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="small">Mastery Level 1</div>
                                    <div class="small text-muted"><?php echo $mastery_levels[0]; ?> words</div>
                                </div>
                                <div class="progress mb-3" style="height: 8px;">
                                    <div class="progress-bar bg-danger" style="width: <?php echo ($mastery_levels[0] / max(1, array_sum($mastery_levels))) * 100; ?>%"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="small">Mastery Level 3+</div>
                                    <div class="small text-muted"><?php echo $mastery_levels[2] + $mastery_levels[3] + $mastery_levels[4]; ?> words</div>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo (($mastery_levels[2] + $mastery_levels[3] + $mastery_levels[4]) / max(1, array_sum($mastery_levels))) * 100; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Achievements & Recent Practice -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <i class="fas fa-trophy me-2"></i> Achievements
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php foreach ($achievements as $achievement): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot <?php echo $achievement['completed'] ? 'completed' : 'incomplete'; ?>">
                                            <i class="fas fa-<?php echo $achievement['icon']; ?> fa-xs"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-title">
                                                <?php if ($achievement['completed']): ?>
                                                    <i class="fas fa-check-circle text-success"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-lock text-muted"></i>
                                                <?php endif; ?>
                                                <?php echo $achievement['title']; ?>
                                            </div>
                                            <div class="timeline-date">
                                                <?php echo $achievement['completed'] ? 'Completed on ' . date('F j, Y', strtotime($achievement['date'])) : 'In progress'; ?>
                                            </div>
                                            <div class="small">
                                                <?php echo $achievement['description']; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <i class="fas fa-history me-2"></i> Recent Practice Sessions
                        </div>
                        <div class="card-body p-0">
                            <?php if ($recent_practice_result->num_rows > 0): ?>
                                <?php while ($practice = $recent_practice_result->fetch_assoc()): ?>
                                    <div class="practice-item">
                                        <div class="practice-title">
                                            <?php echo ucfirst($practice['conversation_mode']); ?> Conversation
                                        </div>
                                        <div class="practice-meta">
                                            <div>
                                                <i class="far fa-calendar-alt me-1"></i>
                                                <?php echo date('M j, Y', strtotime($practice['started_at'])); ?>
                                            </div>
                                            <div>
                                                <i class="far fa-clock me-1"></i>
                                                <?php echo $practice['duration'] ? $practice['duration'] . ' min' : 'N/A'; ?>
                                            </div>
                                            <div>
                                                <i class="far fa-comment me-1"></i>
                                                <?php echo $practice['message_count']; ?> messages
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <a href="view_conversation.php?id=<?php echo $practice['conversation_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye me-1"></i> View Details
                                            </a>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center p-4">
                                    <div class="text-muted">No recent practice sessions found</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
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
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Skills radar chart
            const skillsData = <?php echo $skills_json; ?>;
            const skillsCtx = document.getElementById('skillsChart').getContext('2d');
            
            const skillsChart = new Chart(skillsCtx, {
                type: 'radar',
                data: {
                    labels: skillsData.labels,
                    datasets: [{
                        label: 'Skill Level',
                        data: skillsData.scores,
                        backgroundColor: 'rgba(67, 97, 238, 0.2)',
                        borderColor: '#4361ee',
                        pointBackgroundColor: '#4361ee',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#4361ee',
                        borderWidth: 2
                    }]
                },
                options: {
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                stepSize: 20
                            },
                            pointLabels: {
                                font: {
                                    family: "'Inter', sans-serif",
                                    size: 12
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
            
            // Activity chart
            const activityData = <?php echo $activity_json; ?>;
            const activityCtx = document.getElementById('activityChart').getContext('2d');
            
            const activityChart = new Chart(activityCtx, {
                type: 'bar',
                data: {
                    labels: activityData.dates,
                    datasets: [{
                        label: 'Conversations',
                        data: activityData.counts,
                        backgroundColor: 'rgba(67, 97, 238, 0.7)',
                        borderColor: '#4361ee',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
            
            // Vocabulary growth chart
            const vocabData = <?php echo $vocab_growth_json; ?>;
            const vocabCtx = document.getElementById('vocabularyGrowthChart').getContext('2d');
            
            const vocabChart = new Chart(vocabCtx, {
                type: 'line',
                data: {
                    labels: vocabData.dates,
                    datasets: [
                        {
                            label: 'Words Added',
                            data: vocabData.counts,
                            backgroundColor: 'rgba(76, 201, 240, 0.3)',
                            borderColor: '#4cc9f0',
                            borderWidth: 2,
                            borderRadius: 4,
                            type: 'bar'
                        },
                        {
                            label: 'Cumulative Words',
                            data: vocabData.cumulative,
                            borderColor: '#3f37c9',
                            backgroundColor: 'rgba(63, 55, 201, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true,
                            type: 'line'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
            
            // Toggle between activity charts
            const showConversationsBtn = document.getElementById('showConversationsBtn');
            const showVocabularyBtn = document.getElementById('showVocabularyBtn');
            const activityChartContainer = document.getElementById('activityChartContainer');
            const vocabularyGrowthContainer = document.getElementById('vocabularyGrowthContainer');
            
            showConversationsBtn.addEventListener('click', function() {
                activityChartContainer.style.display = 'block';
                vocabularyGrowthContainer.style.display = 'none';
                showConversationsBtn.classList.add('active');
                showVocabularyBtn.classList.remove('active');
            });
            
            showVocabularyBtn.addEventListener('click', function() {
                activityChartContainer.style.display = 'none';
                vocabularyGrowthContainer.style.display = 'block';
                showConversationsBtn.classList.remove('active');
                showVocabularyBtn.classList.add('active');
            });
            
            // Mastery distribution chart
            const masteryData = <?php echo $mastery_dist_json; ?>;
            const masteryCtx = document.getElementById('masteryChart').getContext('2d');
            
            const masteryChart = new Chart(masteryCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Level 1', 'Level 2', 'Level 3', 'Level 4', 'Level 5'],
                    datasets: [{
                        data: masteryData,
                        backgroundColor: [
                            '#e63946', // Level 1
                            '#f72585', // Level 2
                            '#4895ef', // Level 3
                            '#4cc9f0', // Level 4
                            '#4361ee'  // Level 5
                        ],
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
                                    size: 11,
                                    family: "'Inter', sans-serif"
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label;
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((sum, val) => sum + val, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} words (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
            
            // Initialize proficiency bar animation
            setTimeout(function() {
                document.querySelector('.proficiency-bar').style.width = '<?php echo $proficiency_score; ?>%';
            }, 300);
        });
        
        <?php
        // Function to determine proficiency level based on score
        function getProficiencyLevel($score) {
            if ($score < 20) return 'Beginner';
            if ($score < 40) return 'Elementary';
            if ($score < 60) return 'Intermediate';
            if ($score < 80) return 'Advanced';
            return 'Proficient';
        }
        ?>
    </script>
</body>
</html>