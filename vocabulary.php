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

// Initialize variables
$active_language = $_GET['language'] ?? $user['preferred_language'];
$active_category = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'recent';
$difficulty = $_GET['difficulty'] ?? 'all';
$mastery = $_GET['mastery'] ?? 'all';
$page = max(1, $_GET['page'] ?? 1);
$per_page = 20;

// Handle add new word if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_word') {
        $word = trim($_POST['word']);
        $translation = trim($_POST['translation']);
        $word_language = $_POST['word_language'];
        $category = $_POST['category'];
        $notes = trim($_POST['notes']);
        
        if (!empty($word) && !empty($translation)) {
            // Check if word already exists
            $check_stmt = $conn->prepare("
                SELECT vocabulary_id FROM vocabulary 
                WHERE user_id = ? AND word = ? AND language = ?
            ");
            $check_stmt->bind_param("iss", $user_id, $word, $word_language);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                // Insert new word
                $insert_stmt = $conn->prepare("
                    INSERT INTO vocabulary 
                    (user_id, word, translation, language, category, notes, added_date, last_practiced, difficulty_level, mastery_level)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, 3, 1)
                ");
                $insert_stmt->bind_param("isssss", $user_id, $word, $translation, $word_language, $category, $notes);
                
                if ($insert_stmt->execute()) {
                    $success_message = "Word added successfully!";
                } else {
                    $error_message = "Error adding word: " . $conn->error;
                }
                $insert_stmt->close();
            } else {
                $error_message = "This word already exists in your vocabulary.";
            }
            $check_stmt->close();
        } else {
            $error_message = "Word and translation are required.";
        }
    } elseif ($_POST['action'] === 'update_mastery') {
        $vocab_id = $_POST['vocabulary_id'];
        $new_mastery = $_POST['mastery_level'];
        
        $update_stmt = $conn->prepare("
            UPDATE vocabulary 
            SET mastery_level = ?, last_practiced = NOW()
            WHERE vocabulary_id = ? AND user_id = ?
        ");
        $update_stmt->bind_param("iii", $new_mastery, $vocab_id, $user_id);
        
        if ($update_stmt->execute()) {
            // Return JSON response for AJAX requests
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit;
        }
    } elseif ($_POST['action'] === 'delete_word') {
        $vocab_id = $_POST['vocabulary_id'];
        
        $delete_stmt = $conn->prepare("
            DELETE FROM vocabulary 
            WHERE vocabulary_id = ? AND user_id = ?
        ");
        $delete_stmt->bind_param("ii", $vocab_id, $user_id);
        
        if ($delete_stmt->execute()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit;
        }
    }
}

// Get categories for the active language
$categories_stmt = $conn->prepare("
    SELECT DISTINCT category FROM vocabulary 
    WHERE user_id = ? AND language = ? AND category IS NOT NULL AND category != ''
    ORDER BY category
");
$categories_stmt->bind_param("is", $user_id, $active_language);
$categories_stmt->execute();
$categories_result = $categories_stmt->get_result();

$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row['category'];
}

$query = "
    SELECT v.*, 
           CASE 
               WHEN m.message_id IS NOT NULL THEN 1 
               ELSE 0 
           END as has_usage_examples
    FROM vocabulary v
    LEFT JOIN (
        SELECT DISTINCT m.message_id, m.conversation_id
        FROM messages m
        JOIN conversations c ON m.conversation_id = c.conversation_id
        WHERE c.user_id = ? AND m.content LIKE CONCAT('%', v.word, '%')
        LIMIT 1
    ) m ON 1=1
    WHERE v.user_id = ? AND v.language = ?
";

// With this corrected query:
$query = "
    SELECT v.*, 
           (SELECT COUNT(*) > 0 
            FROM messages m
            JOIN conversations c ON m.conversation_id = c.conversation_id
            WHERE c.user_id = ? AND m.content LIKE CONCAT('%', v.word, '%')
            LIMIT 1) as has_usage_examples
    FROM vocabulary v
    WHERE v.user_id = ? AND v.language = ?
";

// Update the parameter binding to match the new query structure
$params = [$user_id, $user_id, $active_language];
$types = "iis";

// Apply filters
if ($active_category !== 'all') {
    $query .= " AND v.category = ?";
    $params[] = $active_category;
    $types .= "s";
}

if ($search_query !== '') {
    $query .= " AND (v.word LIKE ? OR v.translation LIKE ? OR v.notes LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($difficulty !== 'all') {
    $query .= " AND v.difficulty_level = ?";
    $params[] = $difficulty;
    $types .= "i";
}

if ($mastery !== 'all') {
    $query .= " AND v.mastery_level = ?";
    $params[] = $mastery;
    $types .= "i";
}

// Apply sorting
switch ($sort_by) {
    case 'az':
        $query .= " ORDER BY v.word ASC";
        break;
    case 'za':
        $query .= " ORDER BY v.word DESC";
        break;
    case 'difficulty_asc':
        $query .= " ORDER BY v.difficulty_level ASC, v.word ASC";
        break;
    case 'difficulty_desc':
        $query .= " ORDER BY v.difficulty_level DESC, v.word ASC";
        break;
    case 'mastery_asc':
        $query .= " ORDER BY v.mastery_level ASC, v.word ASC";
        break;
    case 'mastery_desc':
        $query .= " ORDER BY v.mastery_level DESC, v.word ASC";
        break;
    case 'recent':
    default:
        $query .= " ORDER BY v.added_date DESC, v.word ASC";
        break;
}

// Count total words for pagination
$count_query = "SELECT COUNT(*) as total FROM vocabulary WHERE user_id = ? AND language = ?";
$count_params = [$user_id, $active_language];
$count_types = "is";

// Apply the same WHERE filters to the count query
if ($active_category !== 'all') {
    $count_query .= " AND category = ?";
    $count_params[] = $active_category;
    $count_types .= "s";
}

if ($search_query !== '') {
    $count_query .= " AND (word LIKE ? OR translation LIKE ? OR notes LIKE ?)";
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_types .= "sss";
}

if ($difficulty !== 'all') {
    $count_query .= " AND difficulty_level = ?";
    $count_params[] = $difficulty;
    $count_types .= "i";
}

if ($mastery !== 'all') {
    $count_query .= " AND mastery_level = ?";
    $count_params[] = $mastery;
    $count_types .= "i";
}

$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_words = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_words / $per_page);

// Apply pagination
$offset = ($page - 1) * $per_page;
$query .= " LIMIT $per_page OFFSET $offset";

// Execute query
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$vocab_result = $stmt->get_result();

// Get vocabulary statistics
$stats_stmt = $conn->prepare("
    SELECT 
        language,
        COUNT(*) as total_words,
        AVG(mastery_level) as avg_mastery,
        SUM(CASE WHEN mastery_level >= 4 THEN 1 ELSE 0 END) as mastered_words,
        COUNT(DISTINCT category) as category_count
    FROM vocabulary
    WHERE user_id = ?
    GROUP BY language
");
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();

$vocabulary_stats = [];
while ($row = $stats_result->fetch_assoc()) {
    $vocabulary_stats[$row['language']] = $row;
}

// Get learning progress over time
$progress_stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(added_date, '%Y-%m') as month,
        COUNT(*) as words_added
    FROM vocabulary
    WHERE user_id = ? AND language = ?
    GROUP BY DATE_FORMAT(added_date, '%Y-%m')
    ORDER BY month
    LIMIT 12
");
$progress_stmt->bind_param("is", $user_id, $active_language);
$progress_stmt->execute();
$progress_result = $progress_stmt->get_result();

$progress_data = [];
$progress_labels = [];

while ($row = $progress_result->fetch_assoc()) {
    $date = new DateTime($row['month'] . '-01');
    $progress_labels[] = $date->format('M Y');
    $progress_data[] = $row['words_added'];
}

// Get difficulty distribution
$difficulty_stmt = $conn->prepare("
    SELECT 
        difficulty_level,
        COUNT(*) as count
    FROM vocabulary
    WHERE user_id = ? AND language = ?
    GROUP BY difficulty_level
    ORDER BY difficulty_level
");
$difficulty_stmt->bind_param("is", $user_id, $active_language);
$difficulty_stmt->execute();
$difficulty_result = $difficulty_stmt->get_result();

$difficulty_data = [0, 0, 0, 0, 0];
while ($row = $difficulty_result->fetch_assoc()) {
    $difficulty_data[$row['difficulty_level'] - 1] = $row['count'];
}

// Get mastery distribution
$mastery_stmt = $conn->prepare("
    SELECT 
        mastery_level,
        COUNT(*) as count
    FROM vocabulary
    WHERE user_id = ? AND language = ?
    GROUP BY mastery_level
    ORDER BY mastery_level
");
$mastery_stmt->bind_param("is", $user_id, $active_language);
$mastery_stmt->execute();
$mastery_result = $mastery_stmt->get_result();

$mastery_data = [0, 0, 0, 0, 0];
while ($row = $mastery_result->fetch_assoc()) {
    $mastery_data[$row['mastery_level'] - 1] = $row['count'];
}

// Get words due for practice (spaced repetition)
$practice_stmt = $conn->prepare("
    SELECT vocabulary_id, word, translation, mastery_level, 
           DATEDIFF(NOW(), COALESCE(last_practiced, added_date)) as days_since_practice
    FROM vocabulary
    WHERE user_id = ? AND language = ?
    HAVING days_since_practice >= CASE 
        WHEN mastery_level = 1 THEN 1
        WHEN mastery_level = 2 THEN 3
        WHEN mastery_level = 3 THEN 7
        WHEN mastery_level = 4 THEN 14
        ELSE 30
    END
    ORDER BY days_since_practice DESC, mastery_level ASC
    LIMIT 10
");
$practice_stmt->bind_param("is", $user_id, $active_language);
$practice_stmt->execute();
$practice_result = $practice_stmt->get_result();

// Get sample of recently added words for word cloud
$word_cloud_stmt = $conn->prepare("
    SELECT word, mastery_level
    FROM vocabulary
    WHERE user_id = ? AND language = ?
    ORDER BY added_date DESC
    LIMIT 50
");
$word_cloud_stmt->bind_param("is", $user_id, $active_language);
$word_cloud_stmt->execute();
$word_cloud_result = $word_cloud_stmt->get_result();

$word_cloud_data = [];
while ($row = $word_cloud_result->fetch_assoc()) {
    $word_cloud_data[] = [
        'text' => $row['word'],
        'weight' => 5 + (5 - $row['mastery_level']) * 2, // Higher weight for less mastered words
    ];
}

// Close database connection
$conn->close();

// Convert stats to JSON for JS charts
$progress_json = json_encode(['labels' => $progress_labels, 'data' => $progress_data]);
$difficulty_json = json_encode($difficulty_data);
$mastery_json = json_encode($mastery_data);
$word_cloud_json = json_encode($word_cloud_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vocabulary Manager - AI Language Tutor</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- jQCloud for Word Cloud -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jqcloud2@2.0.3/dist/jqcloud.min.css">
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
        
        /* Vocabulary Cards */
        .vocabulary-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-bottom: 1rem;
            height: 100%;
        }
        
        .vocabulary-card:hover {
            box-shadow: var(--shadow);
            transform: translateY(-5px);
        }
        
        .vocabulary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            transition: var(--transition);
        }
        
        .vocabulary-card.difficulty-1::before {
            background-color: var(--success);
        }
        
        .vocabulary-card.difficulty-2::before {
            background-color: #6bd5fa;
        }
        
        .vocabulary-card.difficulty-3::before {
            background-color: var(--info);
        }
        
        .vocabulary-card.difficulty-4::before {
            background-color: #a35cff;
        }
        
        .vocabulary-card.difficulty-5::before {
            background-color: var(--warning);
        }
        
        .vocabulary-card-body {
            padding: 1.25rem;
        }
        
        .vocabulary-card-title {
            font-weight: 600;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .vocabulary-card-subtitle {
            color: #6c757d;
            margin-bottom: 1rem;
        }
        
        .vocabulary-card-details {
            border-top: 1px solid var(--card-border);
            padding-top: 1rem;
            margin-top: 1rem;
        }
        
        .vocabulary-card-footer {
            border-top: 1px solid var(--card-border);
            padding-top: 1rem;
            margin-top: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .vocabulary-card-actions .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        /* Mastery badges */
        .mastery-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .mastery-level-1 {
            background-color: rgba(231, 57, 70, 0.1);
            color: #e63946;
        }
        
        .mastery-level-2 {
            background-color: rgba(247, 37, 133, 0.1);
            color: #f72585;
        }
        
        .mastery-level-3 {
            background-color: rgba(72, 149, 239, 0.1);
            color: #4895ef;
        }
        
        .mastery-level-4 {
            background-color: rgba(76, 201, 240, 0.1);
            color: #4cc9f0;
        }
        
        .mastery-level-5 {
            background-color: rgba(67, 97, 238, 0.1);
            color: #4361ee;
        }
        
        /* Difficulty badges */
        .difficulty-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .difficulty-level-1 {
            background-color: rgba(76, 201, 240, 0.1);
            color: #4cc9f0;
        }
        
        .difficulty-level-2 {
            background-color: rgba(72, 149, 239, 0.1);
            color: #4895ef;
        }
        
        .difficulty-level-3 {
            background-color: rgba(67, 97, 238, 0.1);
            color: #4361ee;
        }
        
        .difficulty-level-4 {
            background-color: rgba(63, 55, 201, 0.1);
            color: #3f37c9;
        }
        
        .difficulty-level-5 {
            background-color: rgba(247, 37, 133, 0.1);
            color: #f72585;
        }
        
        /* Mastery Selector */
        .mastery-selector {
            display: flex;
            margin-top: 0.5rem;
        }
        
        .mastery-selector-item {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid rgba(0, 0, 0, 0.1);
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .mastery-selector-item.active {
            border-color: var(--primary);
            background-color: var(--primary);
            color: white;
        }
        
        .mastery-selector-item:hover {
            transform: scale(1.1);
        }
        
        /* Flashcard */
        .flashcard {
            perspective: 1000px;
            width: 100%;
            height: 250px;
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .flashcard-inner {
            position: relative;
            width: 100%;
            height: 100%;
            transition: transform 0.6s;
            transform-style: preserve-3d;
        }
        
        .flashcard.flipped .flashcard-inner {
            transform: rotateY(180deg);
        }
        
        .flashcard-front, .flashcard-back {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        
        .flashcard-back {
            transform: rotateY(180deg);
        }
        
        .flashcard-word {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .flashcard-pronunciation {
            color: #6c757d;
            margin-bottom: 1rem;
            cursor: pointer;
        }
        
        .flashcard-hint {
            color: #6c757d;
            font-size: 0.875rem;
            margin-top: auto;
        }
        
        .flashcard-navigation {
            display: flex;
            justify-content: space-between;
            width: 100%;
            margin-top: 1rem;
        }
        
        /* Word Cloud */
        .word-cloud-container {
            height: 300px;
            position: relative;
        }
        
        /* Custom Pagination */
        .custom-pagination {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }
        
        .custom-pagination .page-item .page-link {
            border: none;
            color: var(--dark);
            padding: 0.5rem 0.75rem;
            border-radius: 0.25rem;
            margin: 0 0.25rem;
            transition: var(--transition);
        }
        
        .custom-pagination .page-item.active .page-link {
            background-color: var(--primary);
            color: white;
        }
        
        .custom-pagination .page-item .page-link:hover:not(.active) {
            background-color: rgba(67, 97, 238, 0.1);
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
            
            .vocabulary-card-body {
                padding: 1rem;
            }
        }
        
        /* Tab System */
        .custom-tabs {
            display: flex;
            border-bottom: 1px solid var(--card-border);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .custom-tab {
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            color: #6c757d;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
            border-bottom: 3px solid transparent;
        }
        
        .custom-tab:hover {
            color: var(--primary);
        }
        
        .custom-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 600;
        }
        
        .tab-content {
            overflow: hidden;
        }
        
        .tab-pane {
            display: none;
        }
        
        .tab-pane.active {
            display: block;
            animation: fadeIn 0.3s;
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
                    <h1 class="mb-1">Vocabulary Manager</h1>
                    <p class="text-muted mb-0">Track, practice, and master your language vocabulary</p>
                </div>
                <div class="d-flex gap-2">
                    <div class="dropdown">
                        <button class="btn btn-outline-primary dropdown-toggle" type="button" id="languageDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-language me-1"></i> <?php echo $active_language; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown">
                            <li><a class="dropdown-item <?php echo $active_language === 'English' ? 'active' : ''; ?>" href="?language=English">English</a></li>
                            <li><a class="dropdown-item <?php echo $active_language === 'German' ? 'active' : ''; ?>" href="?language=German">German</a></li>
                        </ul>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWordModal">
                        <i class="fas fa-plus me-1"></i> Add Word
                    </button>
                </div>
            </div>
            
            <!-- Tabs -->
            <div class="custom-tabs mb-4">
                <div class="custom-tab active" data-tab="vocabulary">
                    <i class="fas fa-book me-1"></i> My Vocabulary
                </div>
                <div class="custom-tab" data-tab="practice">
                    <i class="fas fa-sync me-1"></i> Practice
                </div>
                <div class="custom-tab" data-tab="analytics">
                    <i class="fas fa-chart-pie me-1"></i> Analytics
                </div>
            </div>
            
            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Vocabulary Tab -->
                <div class="tab-pane active" id="vocabularyTab">
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
                                    <input type="hidden" name="language" value="<?php echo $active_language; ?>">
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="category" class="form-label">Category</label>
                                            <select class="form-select" id="category" name="category">
                                                <option value="all" <?php echo $active_category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                                <?php foreach ($categories as $category): ?>
                                                    <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $active_category === $category ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($category); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="difficulty" class="form-label">Difficulty</label>
                                            <select class="form-select" id="difficulty" name="difficulty">
                                                <option value="all" <?php echo $difficulty === 'all' ? 'selected' : ''; ?>>All Levels</option>
                                                <option value="1" <?php echo $difficulty === '1' ? 'selected' : ''; ?>>Level 1 - Very Easy</option>
                                                <option value="2" <?php echo $difficulty === '2' ? 'selected' : ''; ?>>Level 2 - Easy</option>
                                                <option value="3" <?php echo $difficulty === '3' ? 'selected' : ''; ?>>Level 3 - Medium</option>
                                                <option value="4" <?php echo $difficulty === '4' ? 'selected' : ''; ?>>Level 4 - Hard</option>
                                                <option value="5" <?php echo $difficulty === '5' ? 'selected' : ''; ?>>Level 5 - Very Hard</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="mastery" class="form-label">Mastery Level</label>
                                            <select class="form-select" id="mastery" name="mastery">
                                                <option value="all" <?php echo $mastery === 'all' ? 'selected' : ''; ?>>All Levels</option>
                                                <option value="1" <?php echo $mastery === '1' ? 'selected' : ''; ?>>Level 1 - Just Learning</option>
                                                <option value="2" <?php echo $mastery === '2' ? 'selected' : ''; ?>>Level 2 - Familiar</option>
                                                <option value="3" <?php echo $mastery === '3' ? 'selected' : ''; ?>>Level 3 - Competent</option>
                                                <option value="4" <?php echo $mastery === '4' ? 'selected' : ''; ?>>Level 4 - Proficient</option>
                                                <option value="5" <?php echo $mastery === '5' ? 'selected' : ''; ?>>Level 5 - Mastered</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-8 mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search word, translation or notes...">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="sort" class="form-label">Sort By</label>
                                            <select class="form-select" id="sort" name="sort">
                                                <option value="recent" <?php echo $sort_by === 'recent' ? 'selected' : ''; ?>>Recently Added</option>
                                                <option value="az" <?php echo $sort_by === 'az' ? 'selected' : ''; ?>>A to Z</option>
                                                <option value="za" <?php echo $sort_by === 'za' ? 'selected' : ''; ?>>Z to A</option>
                                                <option value="difficulty_asc" <?php echo $sort_by === 'difficulty_asc' ? 'selected' : ''; ?>>Easiest First</option>
                                                <option value="difficulty_desc" <?php echo $sort_by === 'difficulty_desc' ? 'selected' : ''; ?>>Hardest First</option>
                                                <option value="mastery_asc" <?php echo $sort_by === 'mastery_asc' ? 'selected' : ''; ?>>Least Mastered First</option>
                                                <option value="mastery_desc" <?php echo $sort_by === 'mastery_desc' ? 'selected' : ''; ?>>Most Mastered First</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search me-1"></i> Apply Filters
                                        </button>
                                        <a href="vocabulary.php?language=<?php echo $active_language; ?>" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i> Reset
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Word Count and Export -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <span class="text-muted">Showing <?php echo min($total_words, $per_page); ?> of <?php echo $total_words; ?> words</span>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" id="exportVocabulary">
                                <i class="fas fa-download me-1"></i> Export
                            </button>
                        </div>
                    </div>
                    
                    <!-- Vocabulary List -->
                    <?php if ($vocab_result->num_rows > 0): ?>
                        <div class="row">
                            <?php while ($word = $vocab_result->fetch_assoc()): ?>
                                <div class="col-lg-4 col-md-6 mb-4">
                                    <div class="vocabulary-card difficulty-<?php echo $word['difficulty_level']; ?> fade-in">
                                        <div class="vocabulary-card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <h5 class="vocabulary-card-title"><?php echo htmlspecialchars($word['word']); ?></h5>
                                                <div class="d-flex gap-1">
                                                    <span class="difficulty-badge difficulty-level-<?php echo $word['difficulty_level']; ?>">
                                                        <i class="fas fa-signal-alt me-1"></i> L<?php echo $word['difficulty_level']; ?>
                                                    </span>
                                                    <span class="mastery-badge mastery-level-<?php echo $word['mastery_level']; ?>">
                                                        <i class="fas fa-star me-1"></i> L<?php echo $word['mastery_level']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="vocabulary-card-subtitle">
                                                <?php echo htmlspecialchars($word['translation']); ?>
                                            </div>
                                            
                                            <?php if (!empty($word['category'])): ?>
                                                <div class="badge bg-light text-dark mb-2">
                                                    <i class="fas fa-tag me-1"></i>
                                                    <?php echo htmlspecialchars($word['category']); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($word['notes'])): ?>
                                                <div class="vocabulary-card-details">
                                                    <div class="small text-muted">
                                                        <i class="fas fa-sticky-note me-1"></i> Notes:
                                                    </div>
                                                    <div class="small">
                                                        <?php echo htmlspecialchars($word['notes']); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($word['has_usage_examples']): ?>
                                                <div class="vocabulary-card-details">
                                                    <div class="small text-primary">
                                                        <i class="fas fa-comment me-1"></i> Has usage examples in conversations
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="vocabulary-card-footer">
                                                <div>
                                                    <div class="small text-muted mb-1">Mastery Level:</div>
                                                    <div class="mastery-selector" data-id="<?php echo $word['vocabulary_id']; ?>">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <div class="mastery-selector-item <?php echo $word['mastery_level'] == $i ? 'active' : ''; ?>" data-level="<?php echo $i; ?>">
                                                                <?php echo $i; ?>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="vocabulary-card-actions">
                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="tooltip" title="Edit Word">
                                                        <i class="fas fa-pencil-alt"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger delete-word" data-id="<?php echo $word['vocabulary_id']; ?>" data-bs-toggle="tooltip" title="Delete Word">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="custom-pagination">
                                <ul class="pagination">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&language=<?php echo $active_language; ?>&category=<?php echo $active_category; ?>&difficulty=<?php echo $difficulty; ?>&mastery=<?php echo $mastery; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>" aria-label="Previous">
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
                                            <a class="page-link" href="?page=<?php echo $i; ?>&language=<?php echo $active_language; ?>&category=<?php echo $active_category; ?>&difficulty=<?php echo $difficulty; ?>&mastery=<?php echo $mastery; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&language=<?php echo $active_language; ?>&category=<?php echo $active_category; ?>&difficulty=<?php echo $difficulty; ?>&mastery=<?php echo $mastery; ?>&search=<?php echo urlencode($search_query); ?>&sort=<?php echo $sort_by; ?>" aria-label="Next">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info text-center" role="alert">
                            <div class="mb-3">
                                <i class="fas fa-book fa-3x"></i>
                            </div>
                            <h5>No words found</h5>
                            <p class="mb-0">
                                <?php if ($filter_language !== 'all' || $active_category !== 'all' || $difficulty !== 'all' || $mastery !== 'all' || $search_query !== ''): ?>
                                    No words match your current filters.
                                    <a href="vocabulary.php?language=<?php echo $active_language; ?>" class="alert-link">Clear all filters</a>
                                <?php else: ?>
                                    You haven't added any words yet. Start by adding your first word!
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Practice Tab -->
                <div class="tab-pane" id="practiceTab">
                    <div class="row mb-4">
                        <div class="col-lg-8">
                            <!-- Flashcard Practice -->
                            <div class="card mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-sync me-2"></i> Flashcard Practice
                                    </div>
                                    <div>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-primary active" id="orderRandom">Random</button>
                                            <button type="button" class="btn btn-outline-primary" id="orderMastery">Least Mastered</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if ($practice_result->num_rows > 0): ?>
                                        <?php $practice_result->data_seek(0); ?>
                                        
                                        <div class="flashcards-container">
                                            <?php
                                            $index = 0;
                                            while ($word = $practice_result->fetch_assoc()):
                                            ?>
                                                <div class="flashcard <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                                                    <div class="flashcard-inner">
                                                        <div class="flashcard-front">
                                                            <div class="flashcard-word"><?php echo htmlspecialchars($word['word']); ?></div>
                                                            <div class="flashcard-pronunciation">
                                                                <i class="fas fa-volume-up me-1"></i> Pronunciation
                                                            </div>
                                                            <div class="flashcard-hint">
                                                                <i class="fas fa-lightbulb me-1"></i> Click to flip card
                                                            </div>
                                                        </div>
                                                        <div class="flashcard-back">
                                                            <div class="flashcard-word"><?php echo htmlspecialchars($word['translation']); ?></div>
                                                            <div class="text-center mb-3">
                                                                <div class="badge bg-secondary mb-2">Mastery Level: <?php echo $word['mastery_level']; ?></div>
                                                                <div class="small text-muted">
                                                                    Last practiced: <?php echo $word['days_since_practice'] > 0 ? $word['days_since_practice'] . ' days ago' : 'Today'; ?>
                                                                </div>
                                                            </div>
                                                            <div class="text-center mb-3">
                                                                <div class="small">How well did you know this word?</div>
                                                                <div class="mastery-selector-practice mt-2" data-id="<?php echo $word['vocabulary_id']; ?>">
                                                                    <div class="btn-group btn-group-sm" role="group">
                                                                        <button type="button" class="btn btn-outline-danger mastery-practice-btn" data-level="1">
                                                                            <i class="fas fa-frown"></i>
                                                                        </button>
                                                                        <button type="button" class="btn btn-outline-warning mastery-practice-btn" data-level="2">
                                                                            <i class="fas fa-meh"></i>
                                                                        </button>
                                                                        <button type="button" class="btn btn-outline-success mastery-practice-btn" data-level="4">
                                                                            <i class="fas fa-smile"></i>
                                                                        </button>
                                                                        <button type="button" class="btn btn-outline-primary mastery-practice-btn" data-level="5">
                                                                            <i class="fas fa-grin-stars"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php
                                                $index++;
                                                endwhile;
                                            ?>
                                        </div>
                                        
                                        <div class="flashcard-navigation mt-3">
                                            <button class="btn btn-sm btn-outline-secondary" id="prevCard" <?php echo $practice_result->num_rows <= 1 ? 'disabled' : ''; ?>>
                                                <i class="fas fa-chevron-left me-1"></i> Previous
                                            </button>
                                            <div class="text-center text-muted">
                                                <span id="currentCardNum">1</span> / <span id="totalCards"><?php echo $practice_result->num_rows; ?></span>
                                            </div>
                                            <button class="btn btn-sm btn-outline-primary" id="nextCard" <?php echo $practice_result->num_rows <= 1 ? 'disabled' : ''; ?>>
                                                Next <i class="fas fa-chevron-right ms-1"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-5">
                                            <div class="mb-3">
                                                <i class="fas fa-check-circle fa-3x text-success"></i>
                                            </div>
                                            <h5>You're all caught up!</h5>
                                            <p class="text-muted mb-3">You don't have any words due for practice right now.</p>
                                            <button class="btn btn-primary" id="practiceAllWords">
                                                Practice All Words
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Vocabulary Quiz -->
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-graduation-cap me-2"></i> Vocabulary Quiz
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100">
                                                <div class="card-body text-center">
                                                    <div class="mb-3">
                                                        <i class="fas fa-language fa-3x text-primary"></i>
                                                    </div>
                                                    <h5>Translation Quiz</h5>
                                                    <p class="text-muted small mb-3">
                                                        Test your word translation knowledge with multiple-choice questions
                                                    </p>
                                                    <button class="btn btn-primary" data-quiz-type="translation">
                                                        Start Quiz
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100">
                                                <div class="card-body text-center">
                                                    <div class="mb-3">
                                                        <i class="fas fa-spell-check fa-3x text-primary"></i>
                                                    </div>
                                                    <h5>Spelling Quiz</h5>
                                                    <p class="text-muted small mb-3">
                                                        Practice your spelling skills by typing the correct word
                                                    </p>
                                                    <button class="btn btn-primary" data-quiz-type="spelling">
                                                        Start Quiz
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <!-- Practice Stats -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <i class="fas fa-chart-bar me-2"></i> Practice Stats
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <h6 class="text-muted mb-2">Words by Mastery Level</h6>
                                        <div class="progress mb-2" style="height: 20px;">
                                            <?php
                                            $mastery_colors = ['#e63946', '#f72585', '#4895ef', '#4cc9f0', '#4361ee'];
                                            $total_words_count = array_sum($mastery_data);
                                            
                                            for ($i = 0; $i < 5; $i++) {
                                                if ($total_words_count > 0) {
                                                    $percentage = ($mastery_data[$i] / $total_words_count) * 100;
                                                } else {
                                                    $percentage = 0;
                                                }
                                                
                                                echo '<div class="progress-bar" role="progressbar" style="width: ' . $percentage . '%; background-color: ' . $mastery_colors[$i] . ';" aria-valuenow="' . $percentage . '" aria-valuemin="0" aria-valuemax="100" data-bs-toggle="tooltip" title="Level ' . ($i + 1) . ': ' . $mastery_data[$i] . ' words"></div>';
                                            }
                                            ?>
                                        </div>
                                        <div class="small">
                                            <?php
                                            for ($i = 0; $i < 5; $i++) {
                                                echo '<span class="badge" style="background-color: ' . $mastery_colors[$i] . ';">L' . ($i + 1) . ': ' . $mastery_data[$i] . '</span> ';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6 class="text-muted mb-2">Practice Queue</h6>
                                        <div class="small mb-2">
                                            <i class="fas fa-clock me-1"></i> Words due for practice:
                                            <span class="fw-bold"><?php echo $practice_result->num_rows; ?></span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $practice_result->num_rows > 0 ? 100 : 0; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <h6 class="text-muted mb-2">Practice Streak</h6>
                                        <div class="d-flex align-items-center">
                                            <div class="me-3 text-warning">
                                                <i class="fas fa-fire fa-2x"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold fs-4">3 days</div>
                                                <div class="small text-muted">Keep it going!</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Due for Practice -->
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-hourglass-half me-2"></i> Words Due for Practice
                                </div>
                                <div class="card-body">
                                    <?php if ($practice_result->num_rows > 0): ?>
                                        <?php
                                        $practice_result->data_seek(0);
                                        $count = 0;
                                        while ($word = $practice_result->fetch_assoc() and $count < 5):
                                        ?>
                                            <div class="d-flex justify-content-between align-items-center mb-3 pb-3 <?php echo $count < 4 ? 'border-bottom' : ''; ?>">
                                                <div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($word['word']); ?></div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($word['translation']); ?></div>
                                                </div>
                                                <div class="text-end">
                                                    <div class="badge <?php echo $word['mastery_level'] < 3 ? 'bg-warning' : 'bg-primary'; ?> mb-1">
                                                        L<?php echo $word['mastery_level']; ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        <?php echo $word['days_since_practice']; ?> days ago
                                                    </div>
                                                </div>
                                            </div>
                                        <?php
                                            $count++;
                                            endwhile;
                                        ?>
                                        
                                        <?php if ($practice_result->num_rows > 5): ?>
                                            <div class="text-center mt-2">
                                                <button class="btn btn-sm btn-outline-primary" id="showAllDue">
                                                    Show all <?php echo $practice_result->num_rows; ?> words
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-3">
                                            <i class="fas fa-check-circle mb-2"></i>
                                            <p class="mb-0">No words due for practice</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Analytics Tab -->
                <div class="tab-pane" id="analyticsTab">
                    <div class="row mb-4">
                        <!-- Vocabulary Growth -->
                        <div class="col-lg-8 mb-4">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-chart-line me-2"></i> Vocabulary Growth
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary active">Monthly</button>
                                        <button type="button" class="btn btn-outline-primary">Weekly</button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <canvas id="growthChart" height="250"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Word Cloud -->
                        <div class="col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-header">
                                    <i class="fas fa-cloud me-2"></i> Word Cloud
                                </div>
                                <div class="card-body">
                                    <div class="word-cloud-container" id="wordCloud"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Difficulty Distribution -->
                        <div class="col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-header">
                                    <i class="fas fa-signal-alt me-2"></i> Difficulty Distribution
                                </div>
                                <div class="card-body">
                                    <canvas id="difficultyChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Mastery Level Distribution -->
                        <div class="col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-header">
                                    <i class="fas fa-star me-2"></i> Mastery Distribution
                                </div>
                                <div class="card-body">
                                    <canvas id="masteryChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Category Distribution -->
                        <div class="col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-header">
                                    <i class="fas fa-folder me-2"></i> Category Distribution
                                </div>
                                <div class="card-body">
                                    <?php if (count($categories) > 0): ?>
                                        <canvas id="categoryChart" height="200"></canvas>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-5">
                                            <i class="fas fa-folder-open mb-2"></i>
                                            <p class="mb-0">No categories defined yet</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Activity Calendar -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-calendar-alt me-2"></i> Activity Calendar
                                </div>
                                <div class="card-body">
                                    <div class="text-center text-muted py-5">
                                        <i class="fas fa-calendar mb-2"></i>
                                        <p class="mb-0">Activity calendar coming soon</p>
                                    </div>
                                </div>
                            </div>
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
    
    <!-- Add Word Modal -->
    <div class="modal fade" id="addWordModal" tabindex="-1" aria-labelledby="addWordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addWordModalLabel">Add New Word</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addWordForm" method="post" action="vocabulary.php">
                        <input type="hidden" name="action" value="add_word">
                        <div class="mb-3">
                            <label for="word" class="form-label">Word</label>
                            <input type="text" class="form-control" id="word" name="word" required>
                        </div>
                        <div class="mb-3">
                            <label for="translation" class="form-label">Translation</label>
                            <input type="text" class="form-control" id="translation" name="translation" required>
                        </div>
                        <div class="mb-3">
                            <label for="word_language" class="form-label">Language</label>
                            <select class="form-select" id="word_language" name="word_language" required>
                                <option value="English" <?php echo $active_language === 'English' ? 'selected' : ''; ?>>English</option>
                                <option value="German" <?php echo $active_language === 'German' ? 'selected' : ''; ?>>German</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="word_category" name="category">
                                <option value="">No Category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>">
                                        <?php echo htmlspecialchars($category); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="new">+ Add New Category</option>
                            </select>
                        </div>
                        <div class="mb-3" id="newCategoryGroup" style="display: none;">
                            <label for="new_category" class="form-label">New Category Name</label>
                            <input type="text" class="form-control" id="new_category" name="new_category">
                        </div>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addWordForm" class="btn btn-primary">Add Word</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap and jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jqcloud2@2.0.3/dist/jqcloud.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Tab switching
            const tabButtons = document.querySelectorAll('.custom-tab');
            const tabPanes = document.querySelectorAll('.tab-pane');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabName = this.dataset.tab;
                    
                    // Update active tab
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show selected tab, hide others
                    tabPanes.forEach(pane => {
                        if (pane.id === tabName + 'Tab') {
                            pane.classList.add('active');
                        } else {
                            pane.classList.remove('active');
                        }
                    });
                    
                    // Initialize wordcloud if analytics tab selected
                    if (tabName === 'analytics') {
                        initWordCloud();
                        
                        // Initialize charts
                        initCharts();
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
            
            // New category handling in add word form
            const categorySelect = document.getElementById('word_category');
            const newCategoryGroup = document.getElementById('newCategoryGroup');
            
            if (categorySelect) {
                categorySelect.addEventListener('change', function() {
                    if (this.value === 'new') {
                        newCategoryGroup.style.display = 'block';
                        document.getElementById('new_category').focus();
                    } else {
                        newCategoryGroup.style.display = 'none';
                    }
                });
            }
            
            // Mastery level selector
            const masterySelectors = document.querySelectorAll('.mastery-selector');
            
            masterySelectors.forEach(selector => {
                const items = selector.querySelectorAll('.mastery-selector-item');
                const vocabId = selector.dataset.id;
                
                items.forEach(item => {
                    item.addEventListener('click', function() {
                        const level = parseInt(this.dataset.level);
                        
                        // Update UI
                        items.forEach(i => i.classList.remove('active'));
                        this.classList.add('active');
                        
                        // Send AJAX request to update
                        fetch('vocabulary.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=update_mastery&vocabulary_id=${vocabId}&mastery_level=${level}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                console.error('Error updating mastery level:', data.error);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                        });
                    });
                });
            });
            
            // Handle delete word
            const deleteButtons = document.querySelectorAll('.delete-word');
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const vocabId = this.dataset.id;
                    
                    if (confirm('Are you sure you want to delete this word?')) {
                        fetch('vocabulary.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=delete_word&vocabulary_id=${vocabId}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Remove the vocabulary card
                                this.closest('.col-lg-4').remove();
                            } else {
                                console.error('Error deleting word:', data.error);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                        });
                    }
                });
            });
            
            // Flashcard handling
            const flashcards = document.querySelectorAll('.flashcard');
            let currentCardIndex = 0;
            
            if (flashcards.length > 0) {
                const prevButton = document.getElementById('prevCard');
                const nextButton = document.getElementById('nextCard');
                const currentCardNum = document.getElementById('currentCardNum');
                
                // Flip card on click
                flashcards.forEach(card => {
                    card.addEventListener('click', function() {
                        this.classList.toggle('flipped');
                    });
                });
                
                // Navigation buttons
                if (prevButton && nextButton) {
                    prevButton.addEventListener('click', function() {
                        if (currentCardIndex > 0) {
                            flashcards[currentCardIndex].classList.remove('active');
                            flashcards[currentCardIndex].classList.remove('flipped');
                            currentCardIndex--;
                            flashcards[currentCardIndex].classList.add('active');
                            currentCardNum.textContent = currentCardIndex + 1;
                        }
                    });
                    
                    nextButton.addEventListener('click', function() {
                        if (currentCardIndex < flashcards.length - 1) {
                            flashcards[currentCardIndex].classList.remove('active');
                            flashcards[currentCardIndex].classList.remove('flipped');
                            currentCardIndex++;
                            flashcards[currentCardIndex].classList.add('active');
                            currentCardNum.textContent = currentCardIndex + 1;
                        }
                    });
                }
                
                // Practice mastery buttons
                const masteryPracticeButtons = document.querySelectorAll('.mastery-practice-btn');
                
                masteryPracticeButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const level = parseInt(this.dataset.level);
                        const container = this.closest('.mastery-selector-practice');
                        const vocabId = container.dataset.id;
                        
                        // Send AJAX request to update
                        fetch('vocabulary.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=update_mastery&vocabulary_id=${vocabId}&mastery_level=${level}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Move to next card automatically
                                if (currentCardIndex < flashcards.length - 1) {
                                    nextButton.click();
                                }
                            } else {
                                console.error('Error updating mastery level:', data.error);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                        });
                    });
                });
            }
            
            // Export vocabulary functionality
            const exportButton = document.getElementById('exportVocabulary');
            if (exportButton) {
                exportButton.addEventListener('click', function() {
                    // In a real app, you'd set up an export endpoint
                    alert('Export functionality will be available soon!');
                });
            }
            
            // Word cloud initialization
            function initWordCloud() {
                const wordCloudContainer = document.getElementById('wordCloud');
                if (wordCloudContainer) {
                    try {
                        const wordCloudData = <?php echo $word_cloud_json; ?>;
                        
                        if (wordCloudData.length > 0) {
                            $(wordCloudContainer).jQCloud(wordCloudData, {
                                colors: ["#4895ef", "#4cc9f0", "#4361ee", "#3f37c9", "#7209b7"],
                                autoResize: true,
                                delay: 50
                            });
                        } else {
                            wordCloudContainer.innerHTML = '<div class="text-center text-muted py-5">No vocabulary data available</div>';
                        }
                    } catch (error) {
                        console.error('Error initializing word cloud:', error);
                        wordCloudContainer.innerHTML = '<div class="text-center text-muted py-5">Failed to load word cloud</div>';
                    }
                }
            }
            
            // Initialize charts
            function initCharts() {
                // Growth chart
                const growthData = <?php echo $progress_json; ?>;
                if (document.getElementById('growthChart')) {
                    const growthCtx = document.getElementById('growthChart').getContext('2d');
                    new Chart(growthCtx, {
                        type: 'line',
                        data: {
                            labels: growthData.labels,
                            datasets: [{
                                label: 'Words Added',
                                data: growthData.data,
                                fill: true,
                                backgroundColor: 'rgba(67, 97, 238, 0.1)',
                                borderColor: '#4361ee',
                                tension: 0.4,
                                borderWidth: 2
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
                            },
                            plugins: {
                                legend: {
                                    display: false
                                }
                            }
                        }
                    });
                }
                
                // Difficulty distribution chart
                const difficultyData = <?php echo $difficulty_json; ?>;
                if (document.getElementById('difficultyChart')) {
                    const difficultyCtx = document.getElementById('difficultyChart').getContext('2d');
                    new Chart(difficultyCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Level 1', 'Level 2', 'Level 3', 'Level 4', 'Level 5'],
                            datasets: [{
                                data: difficultyData,
                                backgroundColor: ['#4cc9f0', '#4895ef', '#4361ee', '#3f37c9', '#f72585'],
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
                
                // Mastery distribution chart
                const masteryData = <?php echo $mastery_json; ?>;
                if (document.getElementById('masteryChart')) {
                    const masteryCtx = document.getElementById('masteryChart').getContext('2d');
                    new Chart(masteryCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Level 1', 'Level 2', 'Level 3', 'Level 4', 'Level 5'],
                            datasets: [{
                                data: masteryData,
                                backgroundColor: ['#e63946', '#f72585', '#4895ef', '#4cc9f0', '#4361ee'],
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
                
                // Category distribution chart
                if (document.getElementById('categoryChart')) {
                    const categoryCtx = document.getElementById('categoryChart').getContext('2d');
                    
                    // This would need dynamic data from PHP in a real implementation
                    const categoryData = {
                        labels: <?php echo json_encode($categories); ?>,
                        datasets: [{
                            data: Array(<?php echo count($categories); ?>).fill(1), // Placeholder data
                            backgroundColor: [
                                '#4361ee', '#3f37c9', '#4895ef', '#4cc9f0', '#7209b7',
                                '#f72585', '#b5179e', '#560bad', '#480ca8', '#3a0ca3'
                            ].slice(0, <?php echo count($categories); ?>),
                            borderWidth: 0
                        }]
                    };
                    
                    new Chart(categoryCtx, {
                        type: 'doughnut',
                        data: categoryData,
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
            
            // Success and Error messages
            <?php if (isset($success_message)): ?>
                alert('<?php echo $success_message; ?>');
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                alert('<?php echo $error_message; ?>');
            <?php endif; ?>
        });
    </script>
</body>
</html>