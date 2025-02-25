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
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Initialize feedback messages
$success_message = "";
$error_message = "";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine which form was submitted
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // Profile Update
        if ($action === 'update_profile') {
            $username = trim($_POST['username']);
            $email = trim($_POST['email']);
            $preferred_language = $_POST['preferred_language'];
            
            // Validate inputs
            if (empty($username)) {
                $error_message = "Username cannot be empty.";
            } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = "Please enter a valid email address.";
            } else {
                // Check if username or email already exists for another user
                $check_stmt = $conn->prepare("
                    SELECT user_id FROM users 
                    WHERE (username = ? OR email = ?) AND user_id != ?
                ");
                $check_stmt->bind_param("ssi", $username, $email, $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "Username or email already in use by another account.";
                } else {
                    // Update user profile
                    $update_stmt = $conn->prepare("
                        UPDATE users 
                        SET username = ?, email = ?, preferred_language = ? 
                        WHERE user_id = ?
                    ");
                    $update_stmt->bind_param("sssi", $username, $email, $preferred_language, $user_id);
                    
                    if ($update_stmt->execute()) {
                        $success_message = "Profile updated successfully!";
                        
                        // Update session data
                        $_SESSION['username'] = $username;
                        
                        // Refresh user data
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $user = $result->fetch_assoc();
                    } else {
                        $error_message = "Error updating profile: " . $conn->error;
                    }
                    $update_stmt->close();
                }
                $check_stmt->close();
            }
        }
        
        // Password Update
        elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // Validate inputs
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error_message = "All password fields are required.";
            } elseif ($new_password !== $confirm_password) {
                $error_message = "New passwords do not match.";
            } elseif (strlen($new_password) < 8) {
                $error_message = "New password must be at least 8 characters long.";
            } else {
                // Verify current password
                if (password_verify($current_password, $user['password'])) {
                    // Hash the new password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    // Update password
                    $update_stmt = $conn->prepare("
                        UPDATE users 
                        SET password = ? 
                        WHERE user_id = ?
                    ");
                    $update_stmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($update_stmt->execute()) {
                        $success_message = "Password changed successfully!";
                    } else {
                        $error_message = "Error changing password: " . $conn->error;
                    }
                    $update_stmt->close();
                } else {
                    $error_message = "Current password is incorrect.";
                }
            }
        }
        
        // Learning Goals Update
        elseif ($action === 'update_learning_goals') {
            $daily_goal = intval($_POST['daily_goal']);
            $weekly_goal = intval($_POST['weekly_goal']);
            $practice_reminder = isset($_POST['practice_reminder']) ? 1 : 0;
            $reminder_time = $_POST['reminder_time'];
            
            // Validate inputs
            if ($daily_goal < 0 || $weekly_goal < 0) {
                $error_message = "Goals cannot be negative.";
            } else {
                // Check if learning_settings table has a record for this user
                $check_stmt = $conn->prepare("
                    SELECT user_id FROM learning_settings 
                    WHERE user_id = ?
                ");
                $check_stmt->bind_param("i", $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    // Update existing settings
                    $update_stmt = $conn->prepare("
                        UPDATE learning_settings 
                        SET daily_word_goal = ?, weekly_word_goal = ?, 
                            practice_reminder = ?, reminder_time = ? 
                        WHERE user_id = ?
                    ");
                    $update_stmt->bind_param("iiisi", $daily_goal, $weekly_goal, $practice_reminder, $reminder_time, $user_id);
                    
                    if ($update_stmt->execute()) {
                        $success_message = "Learning goals updated successfully!";
                    } else {
                        $error_message = "Error updating learning goals: " . $conn->error;
                    }
                    $update_stmt->close();
                } else {
                    // Insert new settings
                    $insert_stmt = $conn->prepare("
                        INSERT INTO learning_settings 
                        (user_id, daily_word_goal, weekly_word_goal, practice_reminder, reminder_time)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insert_stmt->bind_param("iiiss", $user_id, $daily_goal, $weekly_goal, $practice_reminder, $reminder_time);
                    
                    if ($insert_stmt->execute()) {
                        $success_message = "Learning goals set successfully!";
                    } else {
                        $error_message = "Error setting learning goals: " . $conn->error;
                    }
                    $insert_stmt->close();
                }
                $check_stmt->close();
            }
        }
        
        // Appearance Settings Update
        elseif ($action === 'update_appearance') {
            $theme = $_POST['theme'];
            $font_size = $_POST['font_size'];
            $high_contrast = isset($_POST['high_contrast']) ? 1 : 0;
            
            // Check if appearance_settings table has a record for this user
            $check_stmt = $conn->prepare("
                SELECT user_id FROM appearance_settings 
                WHERE user_id = ?
            ");
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // Update existing settings
                $update_stmt = $conn->prepare("
                    UPDATE appearance_settings 
                    SET theme = ?, font_size = ?, high_contrast = ? 
                    WHERE user_id = ?
                ");
                $update_stmt->bind_param("ssii", $theme, $font_size, $high_contrast, $user_id);
                
                if ($update_stmt->execute()) {
                    $success_message = "Appearance settings updated successfully!";
                } else {
                    $error_message = "Error updating appearance settings: " . $conn->error;
                }
                $update_stmt->close();
            } else {
                // Insert new settings
                $insert_stmt = $conn->prepare("
                    INSERT INTO appearance_settings 
                    (user_id, theme, font_size, high_contrast)
                    VALUES (?, ?, ?, ?)
                ");
                $insert_stmt->bind_param("issi", $user_id, $theme, $font_size, $high_contrast);
                
                if ($insert_stmt->execute()) {
                    $success_message = "Appearance settings saved successfully!";
                } else {
                    $error_message = "Error saving appearance settings: " . $conn->error;
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
        }
        
        // Notification Settings Update
        elseif ($action === 'update_notifications') {
            $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
            $streak_reminders = isset($_POST['streak_reminders']) ? 1 : 0;
            $achievement_alerts = isset($_POST['achievement_alerts']) ? 1 : 0;
            $learning_tips = isset($_POST['learning_tips']) ? 1 : 0;
            
            // Check if notification_settings table has a record for this user
            $check_stmt = $conn->prepare("
                SELECT user_id FROM notification_settings 
                WHERE user_id = ?
            ");
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // Update existing settings
                $update_stmt = $conn->prepare("
                    UPDATE notification_settings 
                    SET email_notifications = ?, streak_reminders = ?, 
                        achievement_alerts = ?, learning_tips = ? 
                    WHERE user_id = ?
                ");
                $update_stmt->bind_param("iiiii", $email_notifications, $streak_reminders, $achievement_alerts, $learning_tips, $user_id);
                
                if ($update_stmt->execute()) {
                    $success_message = "Notification settings updated successfully!";
                } else {
                    $error_message = "Error updating notification settings: " . $conn->error;
                }
                $update_stmt->close();
            } else {
                // Insert new settings
                $insert_stmt = $conn->prepare("
                    INSERT INTO notification_settings 
                    (user_id, email_notifications, streak_reminders, achievement_alerts, learning_tips)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insert_stmt->bind_param("iiiii", $user_id, $email_notifications, $streak_reminders, $achievement_alerts, $learning_tips);
                
                if ($insert_stmt->execute()) {
                    $success_message = "Notification settings saved successfully!";
                } else {
                    $error_message = "Error saving notification settings: " . $conn->error;
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
        }
        
        // Export Data
        elseif ($action === 'export_data') {
            // This would typically generate a file for download
            // For demo purposes, we'll just set a success message
            $success_message = "Data export functionality will be implemented soon.";
        }
        
        // Delete Account (requires confirmation)
        elseif ($action === 'delete_account') {
            $confirmation = $_POST['delete_confirmation'];
            
            if ($confirmation === $user['username']) {
                // Begin transaction for data deletion
                $conn->begin_transaction();
                
                try {
                    // Delete user's vocabulary
                    $delete_vocab = $conn->prepare("DELETE FROM vocabulary WHERE user_id = ?");
                    $delete_vocab->bind_param("i", $user_id);
                    $delete_vocab->execute();
                    
                    // Delete user's conversation data (messages, corrections, etc.)
                    // First get all conversation IDs
                    $conv_stmt = $conn->prepare("SELECT conversation_id FROM conversations WHERE user_id = ?");
                    $conv_stmt->bind_param("i", $user_id);
                    $conv_stmt->execute();
                    $conv_result = $conv_stmt->get_result();
                    
                    while ($conv = $conv_result->fetch_assoc()) {
                        $conv_id = $conv['conversation_id'];
                        
                        // Delete corrections for messages in this conversation
                        $delete_corrections = $conn->prepare("
                            DELETE c FROM corrections c
                            JOIN messages m ON c.message_id = m.message_id
                            WHERE m.conversation_id = ?
                        ");
                        $delete_corrections->bind_param("i", $conv_id);
                        $delete_corrections->execute();
                        
                        // Delete messages
                        $delete_messages = $conn->prepare("DELETE FROM messages WHERE conversation_id = ?");
                        $delete_messages->bind_param("i", $conv_id);
                        $delete_messages->execute();
                    }
                    
                    // Delete conversations
                    $delete_convs = $conn->prepare("DELETE FROM conversations WHERE user_id = ?");
                    $delete_convs->bind_param("i", $user_id);
                    $delete_convs->execute();
                    
                    // Delete user progress
                    $delete_progress = $conn->prepare("DELETE FROM user_progress WHERE user_id = ?");
                    $delete_progress->bind_param("i", $user_id);
                    $delete_progress->execute();
                    
                    // Delete settings
                    $delete_learning = $conn->prepare("DELETE FROM learning_settings WHERE user_id = ?");
                    $delete_learning->bind_param("i", $user_id);
                    $delete_learning->execute();
                    
                    $delete_appearance = $conn->prepare("DELETE FROM appearance_settings WHERE user_id = ?");
                    $delete_appearance->bind_param("i", $user_id);
                    $delete_appearance->execute();
                    
                    $delete_notifications = $conn->prepare("DELETE FROM notification_settings WHERE user_id = ?");
                    $delete_notifications->bind_param("i", $user_id);
                    $delete_notifications->execute();
                    
                    // Finally, delete the user
                    $delete_user = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                    $delete_user->bind_param("i", $user_id);
                    $delete_user->execute();
                    
                    // Commit transaction
                    $conn->commit();
                    
                    // End session
                    session_destroy();
                    
                    // Redirect to login page with message
                    header("Location: login.php?message=account_deleted");
                    exit;
                } catch (Exception $e) {
                    // Roll back transaction on error
                    $conn->rollback();
                    $error_message = "Error deleting account: " . $e->getMessage();
                }
            } else {
                $error_message = "Username confirmation does not match. Account was not deleted.";
            }
        }
    }
}

// Get user's learning settings
$learning_settings = null;
$learning_stmt = $conn->prepare("SELECT * FROM learning_settings WHERE user_id = ?");
$learning_stmt->bind_param("i", $user_id);
$learning_stmt->execute();
$learning_result = $learning_stmt->get_result();

if ($learning_result->num_rows > 0) {
    $learning_settings = $learning_result->fetch_assoc();
} else {
    // Default settings
    $learning_settings = [
        'daily_word_goal' => 10,
        'weekly_word_goal' => 50,
        'practice_reminder' => 1,
        'reminder_time' => '18:00'
    ];
}

// Get user's appearance settings
$appearance_settings = null;
$appearance_stmt = $conn->prepare("SELECT * FROM appearance_settings WHERE user_id = ?");
$appearance_stmt->bind_param("i", $user_id);
$appearance_stmt->execute();
$appearance_result = $appearance_stmt->get_result();

if ($appearance_result->num_rows > 0) {
    $appearance_settings = $appearance_result->fetch_assoc();
} else {
    // Default settings
    $appearance_settings = [
        'theme' => 'light',
        'font_size' => 'medium',
        'high_contrast' => 0
    ];
}

// Get user's notification settings
$notification_settings = null;
$notification_stmt = $conn->prepare("SELECT * FROM notification_settings WHERE user_id = ?");
$notification_stmt->bind_param("i", $user_id);
$notification_stmt->execute();
$notification_result = $notification_stmt->get_result();

if ($notification_result->num_rows > 0) {
    $notification_settings = $notification_result->fetch_assoc();
} else {
    // Default settings
    $notification_settings = [
        'email_notifications' => 1,
        'streak_reminders' => 1,
        'achievement_alerts' => 1,
        'learning_tips' => 1
    ];
}

// Get user statistics
$stats = [];

// Total vocabulary words
$vocab_stmt = $conn->prepare("SELECT COUNT(*) as total FROM vocabulary WHERE user_id = ?");
$vocab_stmt->bind_param("i", $user_id);
$vocab_stmt->execute();
$vocab_result = $vocab_stmt->get_result();
$stats['total_words'] = $vocab_result->fetch_assoc()['total'];

// Total conversations
$conv_stmt = $conn->prepare("SELECT COUNT(*) as total FROM conversations WHERE user_id = ?");
$conv_stmt->bind_param("i", $user_id);
$conv_stmt->execute();
$conv_result = $conv_stmt->get_result();
$stats['total_conversations'] = $conv_result->fetch_assoc()['total'];

// Total practice time (placeholder - would need a dedicated time tracking table)
$stats['total_practice_time'] = '25h 45m';

// Account age
$age_stmt = $conn->prepare("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE user_id = ?");
$age_stmt->bind_param("i", $user_id);
$age_stmt->execute();
$age_result = $age_stmt->get_result();
$stats['account_age'] = $age_result->fetch_assoc()['days'];

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - AI Language Tutor</title>
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
        
        /* Settings Dashboard Cards */
        .settings-card {
            height: 100%;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .settings-card:hover {
            transform: translateY(-5px);
        }
        
        .settings-card .card-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 2rem 1.5rem;
        }
        
        .settings-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1.5rem;
            transition: var(--transition);
        }
        
        .settings-card:hover .settings-icon {
            transform: scale(1.1);
        }
        
        /* Settings Tabs */
        .settings-tabs {
            display: flex;
            border-bottom: 1px solid var(--card-border);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .settings-tab {
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            color: #6c757d;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
            border-bottom: 3px solid transparent;
        }
        
        .settings-tab:hover {
            color: var(--primary);
        }
        
        .settings-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 600;
        }
        
        .settings-content {
            overflow: hidden;
        }
        
        .settings-pane {
            display: none;
        }
        
        .settings-pane.active {
            display: block;
            animation: fadeIn 0.3s;
        }
        
        /* Form Controls */
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            border: 1px solid var(--card-border);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.1);
        }
        
        /* Custom Form Elements */
        .custom-switch {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 0.75rem 0;
            border-radius: 0.5rem;
            transition: var(--transition);
        }
        
        .custom-switch:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .switch-label {
            flex: 1;
            margin-bottom: 0;
            padding-right: 1rem;
        }
        
        .switch-description {
            color: #6c757d;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .switch-toggle {
            position: relative;
            width: 50px;
            height: 24px;
            background-color: #e2e8f0;
            border-radius: 12px;
            transition: var(--transition);
        }
        
        .switch-toggle::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background-color: white;
            border-radius: 50%;
            transition: var(--transition);
        }
        
        .switch-input:checked + .switch-toggle {
            background-color: var(--primary);
        }
        
        .switch-input:checked + .switch-toggle::after {
            transform: translateX(26px);
        }
        
        .switch-input {
            display: none;
        }
        
        /* Theme Selector */
        .theme-option {
            display: flex;
            align-items: center;
            background-color: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .theme-option:hover {
            border-color: var(--primary-light);
        }
        
        .theme-radio {
            display: none;
        }
        
        .theme-radio:checked + .theme-option {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .theme-color {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin-right: 1rem;
        }
        
        .theme-light .theme-color {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
        }
        
        .theme-dark .theme-color {
            background-color: #1a202c;
        }
        
        .theme-blue .theme-color {
            background-color: #4361ee;
        }
        
        .theme-info {
            flex: 1;
        }
        
        .theme-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .theme-description {
            color: #6c757d;
            font-size: 0.875rem;
        }
        
        /* Font Size Selector */
        .font-size-options {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .font-option {
            flex: 1;
            text-align: center;
            background-color: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 0.5rem;
            padding: 1rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .font-option:hover {
            border-color: var(--primary-light);
        }
        
        .font-radio {
            display: none;
        }
        
        .font-radio:checked + .font-option {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .font-sample {
            margin-bottom: 0.5rem;
        }
        
        .font-small .font-sample {
            font-size: 0.875rem;
        }
        
        .font-medium .font-sample {
            font-size: 1rem;
        }
        
        .font-large .font-sample {
            font-size: 1.25rem;
        }
        
        .font-name {
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        /* User Statistics */
        .stat-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            height: 100%;
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }
        
        .stat-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
        }
        
        /* Danger Zone */
        .danger-zone {
            background-color: rgba(230, 57, 70, 0.05);
            border: 1px solid rgba(230, 57, 70, 0.2);
            border-radius: var(--border-radius);
            padding: 1.5rem;
        }
        
        .danger-zone-header {
            color: var(--danger);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        
        .danger-zone-header i {
            margin-right: 0.5rem;
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
            
            .font-size-options {
                flex-direction: column;
                gap: 0.5rem;
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
                    <a class="nav-link" href="progress.php">
                        <i class="fas fa-chart-line"></i> Progress
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="settings.php">
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
            <div class="mb-4">
                <h1 class="mb-1">Settings</h1>
                <p class="text-muted mb-0">Customize your language learning experience</p>
            </div>
            
            <!-- Success and Error Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Settings Dashboard -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="card settings-card" data-tab="profile">
                        <div class="card-body">
                            <div class="settings-icon">
                                <i class="fas fa-user-circle"></i>
                            </div>
                            <h5>Profile Settings</h5>
                            <p class="text-muted small">Update your personal information</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="card settings-card" data-tab="learning">
                        <div class="card-body">
                            <div class="settings-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <h5>Learning Preferences</h5>
                            <p class="text-muted small">Set goals and practice schedules</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="card settings-card" data-tab="appearance">
                        <div class="card-body">
                            <div class="settings-icon">
                                <i class="fas fa-palette"></i>
                            </div>
                            <h5>Appearance</h5>
                            <p class="text-muted small">Customize themes and display options</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="card settings-card" data-tab="notifications">
                        <div class="card-body">
                            <div class="settings-icon">
                                <i class="fas fa-bell"></i>
                            </div>
                            <h5>Notifications</h5>
                            <p class="text-muted small">Manage alerts and reminders</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- User Statistics -->
            <div class="row mb-4">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-chart-bar me-2"></i> Account Statistics
                        </div>
                        <div class="card-body p-0">
                            <div class="row g-0">
                                <div class="col-lg-3 col-md-6 border-end border-bottom">
                                    <div class="stat-card">
                                        <div class="stat-icon">
                                            <i class="fas fa-book"></i>
                                        </div>
                                        <div class="stat-value"><?php echo $stats['total_words']; ?></div>
                                        <div class="stat-label">Vocabulary Words</div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 border-end border-bottom">
                                    <div class="stat-card">
                                        <div class="stat-icon">
                                            <i class="fas fa-comments"></i>
                                        </div>
                                        <div class="stat-value"><?php echo $stats['total_conversations']; ?></div>
                                        <div class="stat-label">Conversations</div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 border-end border-bottom">
                                    <div class="stat-card">
                                        <div class="stat-icon">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                        <div class="stat-value"><?php echo $stats['total_practice_time']; ?></div>
                                        <div class="stat-label">Total Practice Time</div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 border-bottom">
                                    <div class="stat-card">
                                        <div class="stat-icon">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                        <div class="stat-value"><?php echo $stats['account_age']; ?></div>
                                        <div class="stat-label">Days Since Registration</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Settings Tabs -->
            <div class="settings-tabs">
                <div class="settings-tab active" data-tab="profile">
                    <i class="fas fa-user-circle me-2"></i> Profile
                </div>
                <div class="settings-tab" data-tab="security">
                    <i class="fas fa-shield-alt me-2"></i> Security
                </div>
                <div class="settings-tab" data-tab="learning">
                    <i class="fas fa-graduation-cap me-2"></i> Learning
                </div>
                <div class="settings-tab" data-tab="appearance">
                    <i class="fas fa-palette me-2"></i> Appearance
                </div>
                <div class="settings-tab" data-tab="notifications">
                    <i class="fas fa-bell me-2"></i> Notifications
                </div>
                <div class="settings-tab" data-tab="data">
                    <i class="fas fa-database me-2"></i> Data & Privacy
                </div>
            </div>
            
            <!-- Settings Content -->
            <div class="settings-content">
                <!-- Profile Settings -->
                <div class="settings-pane active" id="profilePane">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-user-circle me-2"></i> Profile Information
                        </div>
                        <div class="card-body">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="action" value="update_profile">
                                
                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3">
                                        <div class="text-center mb-4">
                                            <div class="position-relative d-inline-block">
                                                <img src="https://via.placeholder.com/150" class="rounded-circle" alt="Profile Picture" width="150" height="150">
                                                <div class="position-absolute bottom-0 end-0">
                                                    <button type="button" class="btn btn-sm btn-primary rounded-circle" style="width: 32px; height: 32px;">
                                                        <i class="fas fa-camera"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="mb-3">
                                            <label for="username" class="form-label">Username</label>
                                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                            <div class="form-text">This is how you'll appear to other users.</div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                            <div class="form-text">We'll never share your email with anyone else.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="preferred_language" class="form-label">Preferred Learning Language</label>
                                    <select class="form-select" id="preferred_language" name="preferred_language">
                                        <option value="English" <?php echo $user['preferred_language'] === 'English' ? 'selected' : ''; ?>>English</option>
                                        <option value="German" <?php echo $user['preferred_language'] === 'German' ? 'selected' : ''; ?>>German</option>
                                    </select>
                                    <div class="form-text">This is the primary language you're learning.</div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Security Settings -->
                <div class="settings-pane" id="securityPane">
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-lock me-2"></i> Change Password
                        </div>
                        <div class="card-body">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="action" value="change_password">
                                
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <div class="form-text">Password must be at least 8 characters long.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-key me-1"></i> Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-shield-alt me-2"></i> Account Security
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="me-3 text-success">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-1">Last Login</h5>
                                        <div class="text-muted">
                                            <?php echo date('F j, Y \a\t g:i a', strtotime($user['last_login'])); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <h5 class="mb-3">Login History</h5>
                                    <p class="text-muted">Login history will be available in a future update.</p>
                                </div>
                                
                                <div>
                                    <h5 class="mb-3">Two-Factor Authentication</h5>
                                    <p class="text-muted mb-2">Add an extra layer of security to your account.</p>
                                    <button type="button" class="btn btn-outline-primary" disabled>
                                        <i class="fas fa-plus me-1"></i> Enable 2FA
                                        <span class="badge bg-info ms-2">Coming Soon</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Learning Settings -->
                <div class="settings-pane" id="learningPane">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-graduation-cap me-2"></i> Learning Preferences
                        </div>
                        <div class="card-body">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="action" value="update_learning_goals">
                                
                                <h5 class="mb-3">Learning Goals</h5>
                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3">
                                        <label for="daily_goal" class="form-label">Daily Word Goal</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="daily_goal" name="daily_goal" value="<?php echo $learning_settings['daily_word_goal']; ?>" min="1" max="100">
                                            <span class="input-group-text">words</span>
                                        </div>
                                        <div class="form-text">How many new words you aim to learn each day.</div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="weekly_goal" class="form-label">Weekly Word Goal</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="weekly_goal" name="weekly_goal" value="<?php echo $learning_settings['weekly_word_goal']; ?>" min="5" max="500">
                                            <span class="input-group-text">words</span>
                                        </div>
                                        <div class="form-text">Your weekly vocabulary target.</div>
                                    </div>
                                </div>
                                
                                <h5 class="mb-3">Practice Reminders</h5>
                                <div class="mb-4">
                                    <label class="custom-switch">
                                        <div>
                                            <div class="switch-label">Enable Daily Practice Reminder</div>
                                            <div class="switch-description">Receive a notification to practice at your scheduled time</div>
                                        </div>
                                        <input type="checkbox" class="switch-input" name="practice_reminder" id="practice_reminder" <?php echo $learning_settings['practice_reminder'] ? 'checked' : ''; ?>>
                                        <span class="switch-toggle"></span>
                                    </label>
                                    
                                    <div class="mt-3" id="reminderTimeContainer" <?php echo $learning_settings['practice_reminder'] ? '' : 'style="display: none;"'; ?>>
                                        <label for="reminder_time" class="form-label">Reminder Time</label>
                                        <input type="time" class="form-control" id="reminder_time" name="reminder_time" value="<?php echo $learning_settings['reminder_time']; ?>">
                                        <div class="form-text">When would you like to receive your daily practice reminder?</div>
                                    </div>
                                </div>
                                
                                <h5 class="mb-3">Learning Methods</h5>
                                <div class="mb-4">
                                    <div class="form-text mb-3">Choose your preferred learning methods (multiple selection allowed):</div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="custom-switch">
                                                <div>
                                                    <div class="switch-label">Flashcards</div>
                                                    <div class="switch-description">Quick review of vocabulary words</div>
                                                </div>
                                                <input type="checkbox" class="switch-input" name="method_flashcards" checked>
                                                <span class="switch-toggle"></span>
                                            </label>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="custom-switch">
                                                <div>
                                                    <div class="switch-label">Conversation Practice</div>
                                                    <div class="switch-description">Learn through dialogue with AI tutor</div>
                                                </div>
                                                <input type="checkbox" class="switch-input" name="method_conversation" checked>
                                                <span class="switch-toggle"></span>
                                            </label>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="custom-switch">
                                                <div>
                                                    <div class="switch-label">Listening Exercises</div>
                                                    <div class="switch-description">Practice understanding spoken language</div>
                                                </div>
                                                <input type="checkbox" class="switch-input" name="method_listening" checked>
                                                <span class="switch-toggle"></span>
                                            </label>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="custom-switch">
                                                <div>
                                                    <div class="switch-label">Grammar Drills</div>
                                                    <div class="switch-description">Focused practice on language rules</div>
                                                </div>
                                                <input type="checkbox" class="switch-input" name="method_grammar" checked>
                                                <span class="switch-toggle"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Learning Preferences
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Appearance Settings -->
                <div class="settings-pane" id="appearancePane">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-palette me-2"></i> Display Settings
                        </div>
                        <div class="card-body">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="action" value="update_appearance">
                                
                                <h5 class="mb-3">Theme</h5>
                                <div class="row mb-4">
                                    <div class="col-md-4 mb-3">
                                        <input type="radio" name="theme" id="theme-light" value="light" class="theme-radio" <?php echo $appearance_settings['theme'] === 'light' ? 'checked' : ''; ?>>
                                        <label for="theme-light" class="theme-option theme-light">
                                            <div class="theme-color"></div>
                                            <div class="theme-info">
                                                <div class="theme-name">Light</div>
                                                <div class="theme-description">Clean, bright interface</div>
                                            </div>
                                        </label>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <input type="radio" name="theme" id="theme-dark" value="dark" class="theme-radio" <?php echo $appearance_settings['theme'] === 'dark' ? 'checked' : ''; ?>>
                                        <label for="theme-dark" class="theme-option theme-dark">
                                            <div class="theme-color"></div>
                                            <div class="theme-info">
                                                <div class="theme-name">Dark</div>
                                                <div class="theme-description">Easy on the eyes at night</div>
                                            </div>
                                        </label>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <input type="radio" name="theme" id="theme-blue" value="blue" class="theme-radio" <?php echo $appearance_settings['theme'] === 'blue' ? 'checked' : ''; ?>>
                                        <label for="theme-blue" class="theme-option theme-blue">
                                            <div class="theme-color"></div>
                                            <div class="theme-info">
                                                <div class="theme-name">Blue</div>
                                                <div class="theme-description">Calming blue tones</div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                
                                <h5 class="mb-3">Font Size</h5>
                                <div class="font-size-options mb-4">
                                    <input type="radio" name="font_size" id="font-small" value="small" class="font-radio" <?php echo $appearance_settings['font_size'] === 'small' ? 'checked' : ''; ?>>
                                    <label for="font-small" class="font-option font-small">
                                        <div class="font-sample">Sample Text</div>
                                        <div class="font-name">Small</div>
                                    </label>
                                    
                                    <input type="radio" name="font_size" id="font-medium" value="medium" class="font-radio" <?php echo $appearance_settings['font_size'] === 'medium' ? 'checked' : ''; ?>>
                                    <label for="font-medium" class="font-option font-medium">
                                        <div class="font-sample">Sample Text</div>
                                        <div class="font-name">Medium</div>
                                    </label>
                                    
                                    <input type="radio" name="font_size" id="font-large" value="large" class="font-radio" <?php echo $appearance_settings['font_size'] === 'large' ? 'checked' : ''; ?>>
                                    <label for="font-large" class="font-option font-large">
                                        <div class="font-sample">Sample Text</div>
                                        <div class="font-name">Large</div>
                                    </label>
                                </div>
                                
                                <h5 class="mb-3">Accessibility</h5>
                                <div class="mb-4">
                                    <label class="custom-switch">
                                        <div>
                                            <div class="switch-label">High Contrast Mode</div>
                                            <div class="switch-description">Increase contrast for better readability</div>
                                        </div>
                                        <input type="checkbox" class="switch-input" name="high_contrast" <?php echo $appearance_settings['high_contrast'] ? 'checked' : ''; ?>>
                                        <span class="switch-toggle"></span>
                                    </label>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Appearance Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Notification Settings -->
                <div class="settings-pane" id="notificationsPane">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-bell me-2"></i> Notification Preferences
                        </div>
                        <div class="card-body">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="action" value="update_notifications">
                                
                                <div class="mb-4">
                                    <label class="custom-switch">
                                        <div>
                                            <div class="switch-label">Email Notifications</div>
                                            <div class="switch-description">Receive important updates via email</div>
                                        </div>
                                        <input type="checkbox" class="switch-input" name="email_notifications" <?php echo $notification_settings['email_notifications'] ? 'checked' : ''; ?>>
                                        <span class="switch-toggle"></span>
                                    </label>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="custom-switch">
                                        <div>
                                            <div class="switch-label">Streak Reminders</div>
                                            <div class="switch-description">Get reminded to maintain your learning streak</div>
                                        </div>
                                        <input type="checkbox" class="switch-input" name="streak_reminders" <?php echo $notification_settings['streak_reminders'] ? 'checked' : ''; ?>>
                                        <span class="switch-toggle"></span>
                                    </label>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="custom-switch">
                                        <div>
                                            <div class="switch-label">Achievement Alerts</div>
                                            <div class="switch-description">Notifications when you reach milestones</div>
                                        </div>
                                        <input type="checkbox" class="switch-input" name="achievement_alerts" <?php echo $notification_settings['achievement_alerts'] ? 'checked' : ''; ?>>
                                        <span class="switch-toggle"></span>
                                    </label>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="custom-switch">
                                        <div>
                                            <div class="switch-label">Learning Tips</div>
                                            <div class="switch-description">Receive periodic language learning advice</div>
                                        </div>
                                        <input type="checkbox" class="switch-input" name="learning_tips" <?php echo $notification_settings['learning_tips'] ? 'checked' : ''; ?>>
                                        <span class="switch-toggle"></span>
                                    </label>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Notification Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Data & Privacy Settings -->
                <div class="settings-pane" id="dataPane">
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-download me-2"></i> Export Your Data
                        </div>
                        <div class="card-body">
                            <p class="mb-4">Download a copy of your data, including your vocabulary, conversation history, and learning progress.</p>
                            
                            <form action="settings.php" method="post">
                                <input type="hidden" name="action" value="export_data">
                                
                                <div class="mb-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" value="vocabulary" id="export-vocabulary" checked>
                                        <label class="form-check-label" for="export-vocabulary">
                                            Vocabulary Words
                                        </label>
                                    </div>
                                    
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" value="conversations" id="export-conversations" checked>
                                        <label class="form-check-label" for="export-conversations">
                                            Conversation History
                                        </label>
                                    </div>
                                    
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" value="progress" id="export-progress" checked>
                                        <label class="form-check-label" for="export-progress">
                                            Learning Progress
                                        </label>
                                    </div>
                                    
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="settings" id="export-settings" checked>
                                        <label class="form-check-label" for="export-settings">
                                            User Settings
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="export-format" class="form-label">Format</label>
                                    <select class="form-select" id="export-format" name="export_format">
                                        <option value="json">JSON</option>
                                        <option value="csv">CSV</option>
                                    </select>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-download me-1"></i> Export Data
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header text-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i> Danger Zone
                        </div>
                        <div class="card-body">
                            <div class="danger-zone">
                                <div class="danger-zone-header">
                                    <i class="fas fa-trash-alt"></i> Delete Account
                                </div>
                                <p class="text-muted mb-3">Permanently delete your account and all associated data. This action cannot be undone.</p>
                                
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                                    <i class="fas fa-trash-alt me-1"></i> Delete My Account
                                </button>
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
    
    <!-- Delete Account Modal -->
    <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteAccountModalLabel">Delete Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-exclamation-triangle text-danger fa-3x"></i>
                    </div>
                    
                    <p class="fw-bold">Warning: This action cannot be undone!</p>
                    
                    <p>You are about to permanently delete your account and all associated data, including:</p>
                    
                    <ul>
                        <li>All vocabulary words and practice history</li>
                        <li>All conversation records and progress</li>
                        <li>All settings and preferences</li>
                        <li>Your user profile information</li>
                    </ul>
                    
                    <p>To confirm, please type your username below:</p>
                    
                    <form action="settings.php" method="post" id="deleteAccountForm">
                        <input type="hidden" name="action" value="delete_account">
                        
                        <div class="mb-3">
                            <input type="text" class="form-control" id="delete_confirmation" name="delete_confirmation" placeholder="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="deleteAccountForm" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-1"></i> Permanently Delete Account
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap and jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabButtons = document.querySelectorAll('.settings-tab');
            const tabPanes = document.querySelectorAll('.settings-pane');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabName = this.dataset.tab;
                    
                    // Update active tab
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show selected tab, hide others
                    tabPanes.forEach(pane => {
                        if (pane.id === tabName + 'Pane') {
                            pane.classList.add('active');
                        } else {
                            pane.classList.remove('active');
                        }
                    });
                });
            });
            
            // Dashboard card navigation
            const dashboardCards = document.querySelectorAll('.settings-card');
            
            dashboardCards.forEach(card => {
                card.addEventListener('click', function() {
                    const tabName = this.dataset.tab;
                    
                    // Find and click the corresponding tab
                    document.querySelector(`.settings-tab[data-tab="${tabName}"]`).click();
                    
                    // Scroll to tabs
                    document.querySelector('.settings-tabs').scrollIntoView({ behavior: 'smooth' });
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
            
            // Toggle reminder time field
            const practiceReminderToggle = document.getElementById('practice_reminder');
            const reminderTimeContainer = document.getElementById('reminderTimeContainer');
            
            if (practiceReminderToggle && reminderTimeContainer) {
                practiceReminderToggle.addEventListener('change', function() {
                    if (this.checked) {
                        reminderTimeContainer.style.display = 'block';
                    } else {
                        reminderTimeContainer.style.display = 'none';
                    }
                });
            }
            
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>