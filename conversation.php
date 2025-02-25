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

// Set default values
$language = $_GET['language'] ?? $user['preferred_language'];
$mode = $_GET['mode'] ?? 'casual';
$conversation_id = null;
$messages = [];

// Check if continuing a conversation
if (isset($_GET['continue']) && is_numeric($_GET['continue'])) {
    $conversation_id = $_GET['continue'];
    
    // Verify this conversation belongs to the user
    $stmt = $conn->prepare("SELECT * FROM conversations WHERE conversation_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $conversation_id, $user_id);
    $stmt->execute();
    $conversation_result = $stmt->get_result();
    
    if ($conversation_result->num_rows === 0) {
        // Not found or not owned by user
        header('Location: index.php');
        exit;
    }
    
    $conversation = $conversation_result->fetch_assoc();
    $language = $conversation['language'];
    $mode = $conversation['conversation_mode'];
    
    // Get existing messages
    $stmt = $conn->prepare("
        SELECT m.*, c.original_text, c.corrected_text, c.explanation, c.correction_type 
        FROM messages m
        LEFT JOIN corrections c ON m.message_id = c.message_id
        WHERE m.conversation_id = ?
        ORDER BY m.timestamp ASC
    ");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $messages_result = $stmt->get_result();
    
    while ($row = $messages_result->fetch_assoc()) {
        $messages[] = $row;
    }
}

// Handle new message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['message']) && trim($_POST['message']) !== '') {
        $user_message = trim($_POST['message']);
        
        // If no conversation exists yet, create one
        if (!$conversation_id) {
            $stmt = $conn->prepare("
                INSERT INTO conversations (user_id, language, conversation_mode, started_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param("iss", $user_id, $language, $mode);
            $stmt->execute();
            $conversation_id = $conn->insert_id;
        }
        
        // Store user message
        $stmt = $conn->prepare("
            INSERT INTO messages (conversation_id, is_user, content, timestamp) 
            VALUES (?, 1, ?, NOW())
        ");
        $stmt->bind_param("is", $conversation_id, $user_message);
        $stmt->execute();
        $message_id = $conn->insert_id;
        
        // Simulated AI response (in a real app, you'd call an AI API here)
        // For demo purposes, we'll just create a simple response with corrections
        
        // Check for grammar mistakes (simplified example)
        $corrections = [];
        $has_correction = false;
        
        // Simple grammar check simulation
        if (strpos(strtolower($user_message), 'i is') !== false) {
            $original_text = 'I is';
            $corrected_text = 'I am';
            $explanation = 'Use "am" with the first-person singular pronoun "I".';
            $correction_type = 'grammar';
            $has_correction = true;
            
            // Store correction
            $stmt = $conn->prepare("
                INSERT INTO corrections (message_id, original_text, corrected_text, explanation, correction_type) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issss", $message_id, $original_text, $corrected_text, $explanation, $correction_type);
            $stmt->execute();
            
            $corrections[] = [
                'original_text' => $original_text,
                'corrected_text' => $corrected_text,
                'explanation' => $explanation,
                'correction_type' => $correction_type
            ];
        }
        
        // Generate AI response
        $ai_response = "Thank you for your message.";
        if ($has_correction) {
            $ai_response .= " I noticed some grammar issues that I've corrected above.";
        } else {
            $ai_response .= " Your grammar looks good!";
        }
        
        if ($language === 'German') {
            $ai_response .= " How is your German practice going today?";
        } else {
            $ai_response .= " Is there anything specific you'd like to practice today?";
        }
        
        // Store AI response
        $stmt = $conn->prepare("
            INSERT INTO messages (conversation_id, is_user, content, timestamp) 
            VALUES (?, 0, ?, NOW())
        ");
        $stmt->bind_param("is", $conversation_id, $ai_response);
        $stmt->execute();
        
        // Redirect to avoid form resubmission
        header("Location: conversation.php?continue=$conversation_id");
        exit;
    }
}

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Language Tutor - Conversation</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            --user-message-bg: #e3f2fd;
            --ai-message-bg: #ffffff;
            --correction-bg: #fff8e6;
            --correction-border: #ffca80;
        }
        
        /* Base Styles */
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--body-bg);
            color: var(--dark);
            line-height: 1.6;
            overflow-x: hidden;
            min-height: 100vh;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-weight: 700;
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
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        
        @media (max-width: 991.98px) {
            .content {
                margin-left: 0;
                padding-top: 4.5rem;
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
        
        /* Chat Container */
        .chat-wrapper {
            display: flex;
            flex-direction: column;
            flex: 1;
            overflow: hidden;
            position: relative;
        }
        
        .chat-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--card-border);
            background-color: var(--card-bg);
            box-shadow: var(--shadow-sm);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            z-index: 10;
        }
        
        .chat-title {
            font-weight: 600;
            margin-bottom: 0;
            display: flex;
            align-items: center;
        }
        
        .chat-container {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background-color: var(--body-bg);
            display: flex;
            flex-direction: column;
        }
        
        .message-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 1.5rem;
            max-width: 85%;
        }
        
        .message-group.user {
            align-self: flex-end;
            align-items: flex-end;
        }
        
        .message-group.ai {
            align-self: flex-start;
            align-items: flex-start;
        }
        
        .message-header {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .message-group.user .message-header {
            justify-content: flex-end;
        }
        
        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
        }
        
        .message-group.user .message-avatar {
            margin-right: 0;
            margin-left: 0.75rem;
            background-color: var(--success);
        }
        
        .message-username {
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .message-time {
            font-size: 0.75rem;
            color: #6c757d;
            margin-left: 0.5rem;
        }
        
        .message {
            padding: 1rem;
            border-radius: 1rem;
            background-color: var(--ai-message-bg);
            box-shadow: var(--shadow-sm);
            position: relative;
            margin-bottom: 0.5rem;
        }
        
        .message-group.user .message {
            background-color: var(--user-message-bg);
            border-bottom-right-radius: 0.25rem;
        }
        
        .message-group.ai .message {
            border-bottom-left-radius: 0.25rem;
        }
        
        .message-content {
            margin-bottom: 0;
            white-space: pre-line;
        }
        
        .correction {
            background-color: var(--correction-bg);
            border-left: 3px solid var(--correction-border);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-top: 0.5rem;
            position: relative;
        }
        
        .correction-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            color: #9a6700;
        }
        
        .correction-title i {
            margin-right: 0.5rem;
        }
        
        .original-text {
            text-decoration: line-through;
            color: var(--danger);
        }
        
        .corrected-text {
            color: var(--success);
            font-weight: 600;
        }
        
        .correction-explanation {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        /* Input Area */
        .chat-input-wrapper {
            padding: 1.25rem;
            background-color: var(--card-bg);
            border-top: 1px solid var(--card-border);
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            box-shadow: var(--shadow-sm);
            z-index: 10;
        }
        
        .chat-input-container {
            display: flex;
            align-items: center;
        }
        
        .chat-input {
            flex: 1;
            border: 1px solid var(--card-border);
            border-radius: 1.5rem;
            padding: 0.75rem 1.25rem;
            background-color: var(--body-bg);
            transition: var(--transition);
            resize: none;
            overflow: hidden;
            height: 50px;
            max-height: 120px;
        }
        
        .chat-input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            border-color: var(--primary-light);
        }
        
        .send-button {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 0.75rem;
            cursor: pointer;
            transition: var(--transition);
            border: none;
        }
        
        .send-button:hover {
            background-color: var(--secondary);
            transform: translateY(-2px);
        }
        
        .send-button:active {
            transform: translateY(0);
        }
        
        .chat-footer {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        /* Welcome Message */
        .welcome-message {
            background-color: var(--body-bg);
            padding: 2rem;
            border-radius: var(--border-radius);
            text-align: center;
            max-width: 600px;
            margin: 2rem auto;
        }
        
        .welcome-message h2 {
            margin-bottom: 1rem;
            color: var(--primary);
        }
        
        .welcome-message p {
            margin-bottom: 1.5rem;
        }
        
        /* Language Badge */
        .language-badge {
            padding: 0.4rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            margin-left: 0.75rem;
        }
        
        .language-badge.english {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        .language-badge.german {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
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
            
            .chat-container {
                padding: 1rem;
            }
            
            .message-group {
                max-width: 95%;
            }
        }
        
        @media (max-width: 767.98px) {
            .chat-header {
                padding: 0.75rem 1rem;
            }
            
            .chat-input-wrapper {
                padding: 0.75rem 1rem;
            }
        }
        
        /* Typing indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            margin-top: 0.5rem;
        }
        
        .typing-indicator span {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--primary);
            margin: 0 1px;
            animation: typing 1.5s infinite ease-in-out;
        }
        
        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typing {
            0%, 60%, 100% {
                transform: translateY(0);
            }
            30% {
                transform: translateY(-6px);
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
                    <a class="nav-link active" href="conversation.php">
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
            <!-- Chat Interface -->
            <div class="chat-wrapper">
                <!-- Chat Header -->
                <div class="chat-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <h4 class="chat-title mb-0">
                                <i class="fas fa-comment-dots me-2"></i>
                                <?php echo htmlspecialchars(ucfirst($mode)); ?> Conversation
                            </h4>
                            <span class="language-badge <?php echo strtolower($language); ?>">
                                <?php echo htmlspecialchars($language); ?>
                            </span>
                        </div>
                        <div>
                            <?php if ($conversation_id): ?>
                                <a href="conversation.php" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-plus me-2"></i>New Conversation
                                </a>
                            <?php else: ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" id="modeDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-cog me-1"></i> Options
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="modeDropdown">
                                        <li><h6 class="dropdown-header">Language</h6></li>
                                        <li>
                                            <a class="dropdown-item <?php echo $language === 'English' ? 'active' : ''; ?>" href="?language=English&mode=<?php echo $mode; ?>">
                                                <i class="fas fa-flag-usa me-2"></i> English
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item <?php echo $language === 'German' ? 'active' : ''; ?>" href="?language=German&mode=<?php echo $mode; ?>">
                                                <i class="fas fa-globe-europe me-2"></i> German
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><h6 class="dropdown-header">Conversation Mode</h6></li>
                                        <li>
                                            <a class="dropdown-item <?php echo $mode === 'casual' ? 'active' : ''; ?>" href="?language=<?php echo $language; ?>&mode=casual">
                                                <i class="fas fa-coffee me-2"></i> Casual Chat
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item <?php echo $mode === 'business' ? 'active' : ''; ?>" href="?language=<?php echo $language; ?>&mode=business">
                                                <i class="fas fa-briefcase me-2"></i> Business Talk
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item <?php echo $mode === 'travel' ? 'active' : ''; ?>" href="?language=<?php echo $language; ?>&mode=travel">
                                                <i class="fas fa-plane me-2"></i> Travel Scenarios
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Chat Messages -->
                <div class="chat-container" id="chatContainer">
                    <?php if (empty($messages)): ?>
                        <!-- Welcome message for new conversations -->
                        <div class="welcome-message fade-in">
                            <div class="mb-4">
                                <i class="fas fa-robot fa-3x text-primary"></i>
                            </div>
                            <h2>Welcome to your <?php echo htmlspecialchars(ucfirst($mode)); ?> Conversation</h2>
                            <?php if ($language === 'English'): ?>
                                <p>I'm your AI language tutor, designed to help you practice your English. Feel free to start chatting, and I'll provide corrections and suggestions to help improve your language skills.</p>
                            <?php else: ?>
                                <p>Ich bin dein KI-Sprachtutor, und ich bin hier, um dir zu helfen, dein Deutsch zu üben. Du kannst das Gespräch gerne beginnen, und ich werde dir Korrekturen und Vorschläge geben, um deine Sprachkenntnisse zu verbessern.</p>
                            <?php endif; ?>
                            <p class="text-muted small">Type your first message below to begin</p>
                        </div>
                    <?php else: ?>
                        <!-- Display existing conversation -->
                        <?php 
                        $current_sender = null;
                        foreach ($messages as $index => $message): 
                            $is_user = $message['is_user'] == 1;
                            $sender_changed = $current_sender !== $is_user;
                            $current_sender = $is_user;
                            
                            if ($sender_changed || $index === 0):
                        ?>
                            <div class="message-group <?php echo $is_user ? 'user' : 'ai'; ?> fade-in">
                                <div class="message-header">
                                    <?php if (!$is_user): ?>
                                        <div class="message-avatar">
                                            <i class="fas fa-robot"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="message-username">
                                        <?php echo $is_user ? htmlspecialchars($user['username']) : 'AI Tutor'; ?>
                                    </div>
                                    
                                    <div class="message-time">
                                        <?php echo date('h:i A', strtotime($message['timestamp'])); ?>
                                    </div>
                                    
                                    <?php if ($is_user): ?>
                                        <div class="message-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                        <?php endif; ?>
                                
                                <div class="message">
                                    <p class="message-content"><?php echo htmlspecialchars($message['content']); ?></p>
                                </div>
                                
                                <?php if ($is_user && isset($message['original_text']) && !empty($message['original_text'])): ?>
                                    <div class="correction">
                                        <div class="correction-title">
                                            <i class="fas fa-check-circle"></i> Correction
                                        </div>
                                        <p class="mb-1">
                                            "<span class="original-text"><?php echo htmlspecialchars($message['original_text']); ?></span>" 
                                            should be 
                                            "<span class="corrected-text"><?php echo htmlspecialchars($message['corrected_text']); ?></span>"
                                        </p>
                                        <p class="correction-explanation">
                                            <i class="fas fa-info-circle me-1"></i> <?php echo htmlspecialchars($message['explanation']); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            
                        <?php 
                            // Check if next message is from a different sender or if this is the last message
                            $next_is_different_sender = isset($messages[$index + 1]) && $messages[$index + 1]['is_user'] != $message['is_user'];
                            $is_last_message = $index === count($messages) - 1;
                            
                            if ($next_is_different_sender || $is_last_message):
                        ?>
                            </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    <?php endif; ?>
                </div>
                
                <!-- Chat Input -->
                <div class="chat-input-wrapper">
                    <form method="post" action="conversation.php<?php echo $conversation_id ? '?continue='.$conversation_id : ''; ?>" id="messageForm">
                        <div class="chat-input-container">
                            <textarea 
                                name="message" 
                                id="messageInput" 
                                class="chat-input" 
                                placeholder="Type your message here..." 
                                required
                                rows="1"
                            ></textarea>
                            <button type="submit" class="send-button" id="sendButton">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        <div class="chat-footer">
                            <div>
                                <i class="fas fa-microphone me-1"></i> Voice input coming soon
                            </div>
                            <div>
                                <i class="fas fa-language me-1"></i> Practicing: <?php echo htmlspecialchars($language); ?>
                            </div>
                        </div>
                    </form>
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
            // Auto-scroll to bottom of chat
            const chatContainer = document.getElementById('chatContainer');
            chatContainer.scrollTop = chatContainer.scrollHeight;
            
            // Focus message input
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.focus();
            }
            
            // Auto-resize textarea as user types
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                const height = Math.min(this.scrollHeight, 120);
                this.style.height = `${height}px`;
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
            
            // Form submission with typing indicator
            const messageForm = document.getElementById('messageForm');
            const sendButton = document.getElementById('sendButton');
            
            messageForm.addEventListener('submit', function(e) {
                if (messageInput.value.trim() === '') {
                    e.preventDefault();
                    return;
                }
                
                // Disable button and input while submitting
                messageInput.disabled = true;
                sendButton.disabled = true;
                
                // Show typing indicator in chat
                const typingIndicator = document.createElement('div');
                typingIndicator.className = 'message-group ai fade-in';
                typingIndicator.innerHTML = `
                    <div class="message-header">
                        <div class="message-avatar">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="message-username">
                            AI Tutor
                        </div>
                    </div>
                    <div class="typing-indicator">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                `;
                
                chatContainer.appendChild(typingIndicator);
                chatContainer.scrollTop = chatContainer.scrollHeight;
            });
        });
    </script>
</body>
</html>