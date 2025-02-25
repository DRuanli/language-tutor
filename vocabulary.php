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
$filter_category = $_GET['category'] ?? 'all';
$filter_level = $_GET['level'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';
$page = max(1, $_GET['page'] ?? 1);
$per_page = 20;

// Handle adding new vocabulary
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_word') {
        // Validate input
        $word = trim($_POST['word']);
        $language = $_POST['language'];
        $translation = trim($_POST['translation']);
        $definition = trim($_POST['definition']);
        $part_of_speech = $_POST['part_of_speech'];
        $category_id = $_POST['category'];
        
        if (!empty($word) && !empty($language)) {
            // First check if word already exists for this user
            $check_stmt = $conn->prepare("
                SELECT vocabulary_id FROM vocabulary_items 
                WHERE user_id = ? AND word = ? AND language = ?
            ");
            $check_stmt->bind_param("iss", $user_id, $word, $language);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                // Insert new vocabulary item
                $insert_stmt = $conn->prepare("
                    INSERT INTO vocabulary_items 
                    (user_id, word, language, translation, definition, part_of_speech, date_added) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert_stmt->bind_param("isssss", $user_id, $word, $language, $translation, $definition, $part_of_speech);
                $insert_stmt->execute();
                $vocabulary_id = $insert_stmt->insert_id;
                
                // Add category if selected
                if ($category_id !== 'none') {
                    $cat_stmt = $conn->prepare("
                        INSERT INTO vocabulary_category_items 
                        (vocabulary_id, category_id) 
                        VALUES (?, ?)
                    ");
                    $cat_stmt->bind_param("ii", $vocabulary_id, $category_id);
                    $cat_stmt->execute();
                }
                
                // Initialize progress entry
                $prog_stmt = $conn->prepare("
                    INSERT INTO vocabulary_progress 
                    (vocabulary_id, user_id, mastery_level, times_reviewed, last_reviewed, next_review) 
                    VALUES (?, ?, 1, 0, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))
                ");
                $prog_stmt->bind_param("ii", $vocabulary_id, $user_id);
                $prog_stmt->execute();
                
                // Set success message
                $success_message = "Word \"$word\" added successfully!";
            } else {
                // Word already exists
                $error_message = "This word already exists in your vocabulary list.";
            }
        } else {
            $error_message = "Word and language are required.";
        }
    } elseif ($_POST['action'] === 'update_mastery') {
        $vocabulary_id = $_POST['vocabulary_id'];
        $new_level = $_POST['mastery_level'];
        
        // Update mastery level
        $update_stmt = $conn->prepare("
            UPDATE vocabulary_progress 
            SET mastery_level = ?, 
                times_reviewed = times_reviewed + 1,
                last_reviewed = NOW(),
                next_review = DATE_ADD(NOW(), INTERVAL ? DAY)
            WHERE vocabulary_id = ? AND user_id = ?
        ");
        
        // Calculate next review based on mastery level (spaced repetition)
        $review_interval = 1; // Default 1 day
        switch ($new_level) {
            case 1: $review_interval = 1; break;  // New - review tomorrow
            case 2: $review_interval = 3; break;  // Learning - review in 3 days
            case 3: $review_interval = 7; break;  // Familiar - review in a week
            case 4: $review_interval = 14; break; // Known - review in 2 weeks
            case 5: $review_interval = 30; break; // Mastered - review in a month
        }
        
        $update_stmt->bind_param("iiii", $new_level, $review_interval, $vocabulary_id, $user_id);
        $update_stmt->execute();
        
        // Set success message
        $success_message = "Mastery level updated successfully!";
    } elseif ($_POST['action'] === 'add_category') {
        $category_name = trim($_POST['category_name']);
        
        if (!empty($category_name)) {
            // Check if category already exists
            $check_stmt = $conn->prepare("
                SELECT category_id FROM vocabulary_categories 
                WHERE user_id = ? AND category_name = ?
            ");
            $check_stmt->bind_param("is", $user_id, $category_name);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                // Insert new category
                $insert_stmt = $conn->prepare("
                    INSERT INTO vocabulary_categories 
                    (category_name, user_id) 
                    VALUES (?, ?)
                ");
                $insert_stmt->bind_param("si", $category_name, $user_id);
                $insert_stmt->execute();
                
                // Set success message
                $success_message = "Category \"$category_name\" added successfully!";
            } else {
                // Category already exists
                $error_message = "This category already exists.";
            }
        } else {
            $error_message = "Category name is required.";
        }
    } elseif ($_POST['action'] === 'delete_word') {
        $vocabulary_id = $_POST['vocabulary_id'];
        
        // Delete word (cascading to related entries)
        $delete_stmt = $conn->prepare("
            DELETE FROM vocabulary_items 
            WHERE vocabulary_id = ? AND user_id = ?
        ");
        $delete_stmt->bind_param("ii", $vocabulary_id, $user_id);
        $delete_stmt->execute();
        
        // Set success message
        $success_message = "Word deleted successfully!";
    }
    
    // Redirect to avoid form resubmission
    header("Location: vocabulary.php");
    exit;
}

// Build query with filters
$query = "
    SELECT v.vocabulary_id, v.word, v.language, v.translation, v.definition, 
           v.part_of_speech, v.date_added, vp.mastery_level, vp.last_reviewed,
           vp.next_review, vp.times_reviewed,
           GROUP_CONCAT(DISTINCT vc.category_name SEPARATOR ', ') as categories
    FROM vocabulary_items v
    LEFT JOIN vocabulary_progress vp ON v.vocabulary_id = vp.vocabulary_id
    LEFT JOIN vocabulary_category_items vci ON v.vocabulary_id = vci.vocabulary_id
    LEFT JOIN vocabulary_categories vc ON vci.category_id = vc.category_id
    WHERE v.user_id = ?
";

$params = [$user_id];
$types = "i";

// Apply filters
if ($filter_language !== 'all') {
    $query .= " AND v.language = ?";
    $params[] = $filter_language;
    $types .= "s";
}

if ($filter_category !== 'all') {
    $query .= " AND vci.category_id = ?";
    $params[] = $filter_category;
    $types .= "i";
}

if ($filter_level !== 'all') {
    $query .= " AND vp.mastery_level = ?";
    $params[] = $filter_level;
    $types .= "i";
}

if ($search_query !== '') {
    $query .= " AND (v.word LIKE ? OR v.translation LIKE ? OR v.definition LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

// Add group by
$query .= " GROUP BY v.vocabulary_id";

// Apply sorting
switch ($sort_by) {
    case 'alphabetical':
        $query .= " ORDER BY v.word ASC";
        break;
    case 'review_due':
        $query .= " ORDER BY vp.next_review ASC";
        break;
    case 'mastery_asc':
        $query .= " ORDER BY vp.mastery_level ASC";
        break;
    case 'mastery_desc':
        $query .= " ORDER BY vp.mastery_level DESC";
        break;
    case 'newest':
    default:
        $query .= " ORDER BY v.date_added DESC";
        break;
}

// Get total count for pagination
$count_query = str_replace("SELECT v.vocabulary_id, v.word, v.language, v.translation, v.definition, 
           v.part_of_speech, v.date_added, vp.mastery_level, vp.last_reviewed,
           vp.next_review, vp.times_reviewed,
           GROUP_CONCAT(DISTINCT vc.category_name SEPARATOR ', ') as categories", "SELECT COUNT(DISTINCT v.vocabulary_id) as total", $query);
$count_query = preg_replace("/GROUP BY v.vocabulary_id.*/", "", $count_query);

$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_vocabulary = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_vocabulary / $per_page);

// Apply pagination
$offset = ($page - 1) * $per_page;
$query .= " LIMIT $per_page OFFSET $offset";

// Execute query
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$vocabulary_result = $stmt->get_result();

// Get vocabulary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_words,
        SUM(CASE WHEN language = 'English' THEN 1 ELSE 0 END) as english_words,
        SUM(CASE WHEN language = 'German' THEN 1 ELSE 0 END) as german_words,
        SUM(CASE WHEN vp.mastery_level = 1 THEN 1 ELSE 0 END) as level1,
        SUM(CASE WHEN vp.mastery_level = 2 THEN 1 ELSE 0 END) as level2,
        SUM(CASE WHEN vp.mastery_level = 3 THEN 1 ELSE 0 END) as level3,
        SUM(CASE WHEN vp.mastery_level = 4 THEN 1 ELSE 0 END) as level4,
        SUM(CASE WHEN vp.mastery_level = 5 THEN 1 ELSE 0 END) as level5,
        SUM(CASE WHEN vp.next_review <= NOW() THEN 1 ELSE 0 END) as due_for_review
    FROM 
        vocabulary_items v
    JOIN 
        vocabulary_progress vp ON v.vocabulary_id = vp.vocabulary_id
    WHERE 
        v.user_id = ?
";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get all categories for dropdown
$cat_stmt = $conn->prepare("
    SELECT category_id, category_name 
    FROM vocabulary_categories 
    WHERE user_id = ? 
    ORDER BY category_name
");
$cat_stmt->bind_param("i", $user_id);
$cat_stmt->execute();
$categories_result = $cat_stmt->get_result();
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row;
}

// Get recently learned words (last 7 days)
$recent_stmt = $conn->prepare("
    SELECT v.word, v.language 
    FROM vocabulary_items v 
    WHERE v.user_id = ? 
    AND v.date_added >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
    ORDER BY v.date_added DESC 
    LIMIT 5
");
$recent_stmt->bind_param("i", $user_id);
$recent_stmt->execute();
$recent_result = $recent_stmt->get_result();

// Get words due for review
$due_stmt = $conn->prepare("
    SELECT v.vocabulary_id, v.word, v.language 
    FROM vocabulary_items v 
    JOIN vocabulary_progress vp ON v.vocabulary_id = vp.vocabulary_id 
    WHERE v.user_id = ? 
    AND vp.next_review <= NOW() 
    ORDER BY vp.next_review ASC 
    LIMIT 5
");
$due_stmt->bind_param("i", $user_id);
$due_stmt->execute();
$due_result = $due_stmt->get_result();

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vocabulary - AI Language Tutor</title>
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
        
        /* Vocabulary Card */
        .vocab-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 1px solid var(--card-border);
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .vocab-card:hover {
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }
        
        .vocab-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background-color: var(--primary);
        }
        
        .vocab-card.german::before {
            background-color: var(--success);
        }
        
        .vocab-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .vocab-card-word {
            font-weight: 700;
            font-size: 1.25rem;
            margin-bottom: 0;
        }
        
        .vocab-card-body {
            padding: 1.25rem 1.5rem;
        }
        
        .vocab-card-footer {
            padding: 0.75rem 1.5rem;
            background-color: rgba(0, 0, 0, 0.01);
            border-top: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .vocab-card-date {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .vocab-mastery {
            display: flex;
            gap: 0.25rem;
        }
        
        .vocab-mastery-dot {
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background-color: rgba(67, 97, 238, 0.2);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .vocab-mastery-dot.active {
            background-color: var(--primary);
        }
        
        .vocab-mastery-dot:hover {
            transform: scale(1.2);
        }
        
        .vocab-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .vocab-badge-english {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        .vocab-badge-german {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }
        
        .vocab-categories {
            margin-top: 0.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .vocab-category {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            background-color: rgba(0, 0, 0, 0.05);
            border-radius: 0.5rem;
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
        
        /* Mastery levels */
        .mastery-legend {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .mastery-level {
            display: flex;
            align-items: center;
            margin-right: 1rem;
        }
        
        .mastery-dot {
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .mastery-label {
            font-size: 0.875rem;
        }
        
        .level-1 { background-color: #e63946; }
        .level-2 { background-color: #f72585; }
        .level-3 { background-color: #4895ef; }
        .level-4 { background-color: #4cc9f0; }
        .level-5 { background-color: #06d6a0; }
        
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
        
        /* List Items */
        .list-group-item {
            border-left: none;
            border-right: none;
            padding: 1rem 1.5rem;
            transition: var(--transition);
        }
        
        .list-group-item:first-child {
            border-top: none;
        }
        
        .list-group-item:last-child {
            border-bottom: none;
        }
        
        .list-group-item:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        /* Button Styles */
        .btn-icon {
            width: 2rem;
            height: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            transition: var(--transition);
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
        }
        
        .btn-add-word {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
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
        }
        
        .btn-add-word:hover {
            transform: translateY(-5px) rotate(90deg);
            box-shadow: var(--shadow-lg);
            color: white;
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--shadow-lg);
        }
        
        .modal-header {
            border-bottom: 1px solid var(--card-border);
            padding: 1.25rem 1.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            border-top: 1px solid var(--card-border);
            padding: 1.25rem 1.5rem;
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
                    <a class="nav-link active" href="vocabulary.php">
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
                    <h1 class="mb-1">Vocabulary</h1>
                    <p class="text-muted mb-0">Manage and learn your vocabulary collection</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                        <i class="fas fa-folder-plus me-2"></i> New Category
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWordModal">
                        <i class="fas fa-plus me-2"></i> Add Word
                    </button>
                </div>
            </div>
            
            <!-- Stats Overview -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stats-card h-100">
                        <div class="stats-card-header">
                            <i class="fas fa-book me-2"></i> Total Vocabulary
                        </div>
                        <div class="stats-card-body text-center">
                            <h2 class="display-4 mb-0"><?php echo $stats['total_words'] ?? 0; ?></h2>
                            <p class="text-muted mt-2">Words in your collection</p>
                            <div class="d-flex justify-content-around mt-3">
                                <div>
                                    <h5 class="mb-0"><?php echo $stats['english_words'] ?? 0; ?></h5>
                                    <small class="text-muted">English</small>
                                </div>
                                <div>
                                    <h5 class="mb-0"><?php echo $stats['german_words'] ?? 0; ?></h5>
                                    <small class="text-muted">German</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 col-md-6 mb-4">
                    <div class="stats-card h-100">
                        <div class="stats-card-header">
                            <i class="fas fa-graduation-cap me-2"></i> Learning Progress
                        </div>
                        <div class="stats-card-body">
                            <div class="mastery-legend">
                                <div class="mastery-level">
                                    <div class="mastery-dot level-1"></div>
                                    <div class="mastery-label">New</div>
                                </div>
                                <div class="mastery-level">
                                    <div class="mastery-dot level-2"></div>
                                    <div class="mastery-label">Learning</div>
                                </div>
                                <div class="mastery-level">
                                    <div class="mastery-dot level-3"></div>
                                    <div class="mastery-label">Familiar</div>
                                </div>
                                <div class="mastery-level">
                                    <div class="mastery-dot level-4"></div>
                                    <div class="mastery-label">Known</div>
                                </div>
                                <div class="mastery-level">
                                    <div class="mastery-dot level-5"></div>
                                    <div class="mastery-label">Mastered</div>
                                </div>
                            </div>
                            <div class="progress" style="height: 2rem;">
                                <div class="progress-bar level-1" role="progressbar" style="width: <?php echo $stats['total_words'] > 0 ? ($stats['level1'] / $stats['total_words'] * 100) : 0; ?>%" aria-valuenow="<?php echo $stats['level1'] ?? 0; ?>" aria-valuemin="0" aria-valuemax="<?php echo $stats['total_words'] ?? 0; ?>"></div>
                                <div class="progress-bar level-2" role="progressbar" style="width: <?php echo $stats['total_words'] > 0 ? ($stats['level2'] / $stats['total_words'] * 100) : 0; ?>%" aria-valuenow="<?php echo $stats['level2'] ?? 0; ?>" aria-valuemin="0" aria-valuemax="<?php echo $stats['total_words'] ?? 0; ?>"></div>
                                <div class="progress-bar level-3" role="progressbar" style="width: <?php echo $stats['total_words'] > 0 ? ($stats['level3'] / $stats['total_words'] * 100) : 0; ?>%" aria-valuenow="<?php echo $stats['level3'] ?? 0; ?>" aria-valuemin="0" aria-valuemax="<?php echo $stats['total_words'] ?? 0; ?>"></div>
                                <div class="progress-bar level-4" role="progressbar" style="width: <?php echo $stats['total_words'] > 0 ? ($stats['level4'] / $stats['total_words'] * 100) : 0; ?>%" aria-valuenow="<?php echo $stats['level4'] ?? 0; ?>" aria-valuemin="0" aria-valuemax="<?php echo $stats['total_words'] ?? 0; ?>"></div>
                                <div class="progress-bar level-5" role="progressbar" style="width: <?php echo $stats['total_words'] > 0 ? ($stats['level5'] / $stats['total_words'] * 100) : 0; ?>%" aria-valuenow="<?php echo $stats['level5'] ?? 0; ?>" aria-valuemin="0" aria-valuemax="<?php echo $stats['total_words'] ?? 0; ?>"></div>
                            </div>
                            <div class="d-flex justify-content-between mt-2 text-center">
                                <div><?php echo $stats['level1'] ?? 0; ?></div>
                                <div><?php echo $stats['level2'] ?? 0; ?></div>
                                <div><?php echo $stats['level3'] ?? 0; ?></div>
                                <div><?php echo $stats['level4'] ?? 0; ?></div>
                                <div><?php echo $stats['level5'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-12 mb-4">
                    <div class="stats-card h-100">
                        <div class="stats-card-header">
                            <i class="fas fa-clock me-2"></i> Review Due
                        </div>
                        <div class="stats-card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h3 class="mb-0"><?php echo $stats['due_for_review'] ?? 0; ?> words</h3>
                                <a href="flashcards.php" class="btn btn-sm btn-primary">Start Review</a>
                            </div>
                            <?php if ($due_result->num_rows > 0): ?>
                                <ul class="list-group">
                                    <?php while ($word = $due_result->fetch_assoc()): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <?php echo htmlspecialchars($word['word']); ?>
                                            <span class="vocab-badge vocab-badge-<?php echo strtolower($word['language']); ?>">
                                                <?php echo htmlspecialchars($word['language']); ?>
                                            </span>
                                        </li>
                                    <?php endwhile; ?>
                                </ul>
                            <?php else: ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i> All caught up! No words due for review.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-card mb-4">
                <div class="filter-header">
                    <div>
                        <i class="fas fa-filter me-2"></i> Filter Vocabulary
                    </div>
                    <button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="true" aria-controls="filterCollapse">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div class="collapse show" id="filterCollapse">
                    <div class="filter-body">
                        <form action="vocabulary.php" method="get" id="filterForm">
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
                                    <label for="category" class="form-label">Category</label>
                                    <select class="form-select" id="category" name="category">
                                        <option value="all" <?php echo $filter_category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['category_id']; ?>" <?php echo $filter_category == $category['category_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="level" class="form-label">Mastery Level</label>
                                    <select class="form-select" id="level" name="level">
                                        <option value="all" <?php echo $filter_level === 'all' ? 'selected' : ''; ?>>All Levels</option>
                                        <option value="1" <?php echo $filter_level === '1' ? 'selected' : ''; ?>>Level 1 - New</option>
                                        <option value="2" <?php echo $filter_level === '2' ? 'selected' : ''; ?>>Level 2 - Learning</option>
                                        <option value="3" <?php echo $filter_level === '3' ? 'selected' : ''; ?>>Level 3 - Familiar</option>
                                        <option value="4" <?php echo $filter_level === '4' ? 'selected' : ''; ?>>Level 4 - Known</option>
                                        <option value="5" <?php echo $filter_level === '5' ? 'selected' : ''; ?>>Level 5 - Mastered</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="sort" class="form-label">Sort By</label>
                                    <select class="form-select" id="sort" name="sort">
                                        <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="alphabetical" <?php echo $sort_by === 'alphabetical' ? 'selected' : ''; ?>>Alphabetical</option>
                                        <option value="review_due" <?php echo $sort_by === 'review_due' ? 'selected' : ''; ?>>Review Due First</option>
                                        <option value="mastery_asc" <?php echo $sort_by === 'mastery_asc' ? 'selected' : ''; ?>>Mastery (Low to High)</option>
                                        <option value="mastery_desc" <?php echo $sort_by === 'mastery_desc' ? 'selected' : ''; ?>>Mastery (High to Low)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="search" class="form-label">Search</label>
                                    <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search in vocabulary...">
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="filter-actions w-100">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="fas fa-search me-1"></i> Apply
                                        </button>
                                        <a href="vocabulary.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i> Reset
                                        </a>
                                        
                                        <a href="flashcards.php<?php echo $filter_language !== 'all' || $filter_category !== 'all' || $filter_level !== 'all' || $search_query !== '' ? '?' . http_build_query([
                                            'language' => $filter_language,
                                            'category' => $filter_category,
                                            'level' => $filter_level,
                                            'search' => $search_query
                                        ]) : ''; ?>" class="btn btn-success ms-auto">
                                            <i class="fas fa-play me-1"></i> Practice Selected
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Vocabulary List -->
            <div class="row">
                <?php if ($vocabulary_result->num_rows > 0): ?>
                    <?php while ($word = $vocabulary_result->fetch_assoc()): ?>
                        <div class="col-lg-6 mb-4">
                            <div class="vocab-card <?php echo strtolower($word['language']); ?> fade-in">
                                <div class="vocab-card-header">
                                    <h5 class="vocab-card-word"><?php echo htmlspecialchars($word['word']); ?></h5>
                                    <span class="vocab-badge vocab-badge-<?php echo strtolower($word['language']); ?>">
                                        <?php echo htmlspecialchars($word['language']); ?>
                                    </span>
                                </div>
                                <div class="vocab-card-body">
                                    <?php if ($word['translation']): ?>
                                        <div class="mb-2">
                                            <strong>Translation:</strong> <?php echo htmlspecialchars($word['translation']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($word['definition']): ?>
                                        <div class="mb-2">
                                            <strong>Definition:</strong> <?php echo htmlspecialchars($word['definition']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($word['part_of_speech']): ?>
                                        <div class="mb-2">
                                            <strong>Part of Speech:</strong> <?php echo htmlspecialchars($word['part_of_speech']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($word['categories']): ?>
                                        <div class="vocab-categories">
                                            <?php foreach (explode(', ', $word['categories']) as $category): ?>
                                                <span class="vocab-category"><?php echo htmlspecialchars($category); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="vocab-card-footer">
                                    <div class="vocab-card-date">
                                        <i class="far fa-calendar-alt me-1"></i>
                                        Added <?php echo date('M d, Y', strtotime($word['date_added'])); ?>
                                    </div>
                                    
                                    <div class="d-flex align-items-center">
                                        <div class="vocab-mastery me-3" data-vocab-id="<?php echo $word['vocabulary_id']; ?>">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <div class="vocab-mastery-dot <?php echo $i <= $word['mastery_level'] ? 'active' : ''; ?>" data-level="<?php echo $i; ?>"></div>
                                            <?php endfor; ?>
                                        </div>
                                        
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#editWordModal" data-vocab-id="<?php echo $word['vocabulary_id']; ?>">
                                                        <i class="fas fa-edit me-2"></i> Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="flashcards.php?word=<?php echo $word['vocabulary_id']; ?>">
                                                        <i class="fas fa-play me-2"></i> Practice
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#deleteWordModal" data-vocab-id="<?php echo $word['vocabulary_id']; ?>" data-vocab-word="<?php echo htmlspecialchars($word['word']); ?>">
                                                        <i class="fas fa-trash-alt me-2"></i> Delete
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-info" role="alert">
                            <?php if ($filter_language !== 'all' || $filter_category !== 'all' || $filter_level !== 'all' || $search_query !== ''): ?>
                                <i class="fas fa-info-circle me-2"></i> No vocabulary items found matching your filters.
                                <a href="vocabulary.php" class="alert-link">Clear all filters</a>
                            <?php else: ?>
                                <i class="fas fa-info-circle me-2"></i> Your vocabulary list is empty. Add your first word to get started!
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&language=<?php echo $filter_language; ?>&category=<?php echo $filter_category; ?>&level=<?php echo $filter_level; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>" aria-label="Previous">
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
                                    <a class="page-link" href="?page=<?php echo $i; ?>&language=<?php echo $filter_language; ?>&category=<?php echo $filter_category; ?>&level=<?php echo $filter_level; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&language=<?php echo $filter_language; ?>&category=<?php echo $filter_category; ?>&level=<?php echo $filter_level; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>" aria-label="Next">
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
    
    <!-- Add Word Button (Mobile) -->
    <a href="#" class="btn-add-word d-md-none" data-bs-toggle="modal" data-bs-target="#addWordModal">
        <i class="fas fa-plus"></i>
    </a>
    
    <!-- Mobile Nav Toggle -->
    <div class="position-fixed top-0 start-0 p-3 d-lg-none d-block" style="z-index: 1031;">
        <button class="btn btn-primary btn-sm rounded-circle shadow" id="mobileSidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <!-- Add Word Modal -->
    <div class="modal fade" id="addWordModal" tabindex="-1" aria-labelledby="addWordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addWordModalLabel">Add New Word</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="vocabulary.php" method="post">
                    <input type="hidden" name="action" value="add_word">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="word" class="form-label">Word/Phrase*</label>
                                <input type="text" class="form-control" id="word" name="word" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="language" class="form-label">Language*</label>
                                <select class="form-select" id="language" name="language" required>
                                    <option value="English">English</option>
                                    <option value="German">German</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="translation" class="form-label">Translation</label>
                                <input type="text" class="form-control" id="translation" name="translation">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="part_of_speech" class="form-label">Part of Speech</label>
                                <select class="form-select" id="part_of_speech" name="part_of_speech">
                                    <option value="">-- Select --</option>
                                    <option value="noun">Noun</option>
                                    <option value="verb">Verb</option>
                                    <option value="adjective">Adjective</option>
                                    <option value="adverb">Adverb</option>
                                    <option value="preposition">Preposition</option>
                                    <option value="conjunction">Conjunction</option>
                                    <option value="pronoun">Pronoun</option>
                                    <option value="interjection">Interjection</option>
                                    <option value="phrase">Phrase</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="definition" class="form-label">Definition</label>
                            <textarea class="form-control" id="definition" name="definition" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category">
                                <option value="none">-- None --</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['category_id']; ?>">
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Word</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Category Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalLabel">Add New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="vocabulary.php" method="post">
                    <input type="hidden" name="action" value="add_category">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="category_name" class="form-label">Category Name</label>
                            <input type="text" class="form-control" id="category_name" name="category_name" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Word Modal -->
    <div class="modal fade" id="deleteWordModal" tabindex="-1" aria-labelledby="deleteWordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteWordModalLabel">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete "<span id="deleteWordName"></span>"? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <form action="vocabulary.php" method="post">
                        <input type="hidden" name="action" value="delete_word">
                        <input type="hidden" name="vocabulary_id" id="deleteWordId">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
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
            
            // Mastery level dots
            document.querySelectorAll('.vocab-mastery').forEach(masteryContainer => {
                const vocabId = masteryContainer.dataset.vocabId;
                
                masteryContainer.querySelectorAll('.vocab-mastery-dot').forEach(dot => {
                    dot.addEventListener('click', function(e) {
                        e.preventDefault();
                        const level = this.dataset.level;
                        
                        // Update UI immediately
                        masteryContainer.querySelectorAll('.vocab-mastery-dot').forEach(d => {
                            if (d.dataset.level <= level) {
                                d.classList.add('active');
                            } else {
                                d.classList.remove('active');
                            }
                        });
                        
                        // Send AJAX request to update mastery level
                        const formData = new FormData();
                        formData.append('action', 'update_mastery');
                        formData.append('vocabulary_id', vocabId);
                        formData.append('mastery_level', level);
                        
                        fetch('vocabulary.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.text();
                        })
                        .catch(error => {
                            console.error('Error updating mastery level:', error);
                            // Revert UI if there was an error
                            location.reload();
                        });
                    });
                });
            });
            
            // Delete word modal
            const deleteWordModal = document.getElementById('deleteWordModal');
            if (deleteWordModal) {
                deleteWordModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const vocabId = button.getAttribute('data-vocab-id');
                    const vocabWord = button.getAttribute('data-vocab-word');
                    
                    document.getElementById('deleteWordId').value = vocabId;
                    document.getElementById('deleteWordName').textContent = vocabWord;
                });
            }
            
            // Edit word modal (would need to be implemented)
            // This would involve fetching the word details via AJAX
            const editWordModal = document.getElementById('editWordModal');
            if (editWordModal) {
                editWordModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const vocabId = button.getAttribute('data-vocab-id');
                    
                    // Fetch word details via AJAX
                    fetch(`get_word.php?id=${vocabId}`)
                        .then(response => response.json())
                        .then(data => {
                            // Populate form fields
                            document.getElementById('edit_word').value = data.word;
                            document.getElementById('edit_language').value = data.language;
                            document.getElementById('edit_translation').value = data.translation;
                            document.getElementById('edit_definition').value = data.definition;
                            document.getElementById('edit_part_of_speech').value = data.part_of_speech;
                            document.getElementById('edit_category').value = data.category_id;
                            document.getElementById('edit_vocabulary_id').value = data.vocabulary_id;
                        })
                        .catch(error => {
                            console.error('Error fetching word details:', error);
                        });
                });
            }
        });
    </script>
</body>
</html>