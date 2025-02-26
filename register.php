<?php
// Start session
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Include database connection
require_once "includes/db.php";

// Define variables and initialize with empty values
$username = $email = $password = $confirm_password = $preferred_language = "";
$username_err = $email_err = $password_err = $confirm_password_err = "";
$registration_success = false;

// Process form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate username
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter a username.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', trim($_POST["username"]))) {
        $username_err = "Username can only contain letters, numbers, and underscores.";
    } else {
        // Prepare a select statement
        $sql = "SELECT user_id FROM users WHERE username = ?";

        if ($stmt = $conn->prepare($sql)) {
            // Bind variables to the prepared statement as parameters
            $stmt->bind_param("s", $param_username);

            // Set parameters
            $param_username = trim($_POST["username"]);

            // Attempt to execute the prepared statement
            if ($stmt->execute()) {
                // Store result
                $stmt->store_result();

                if ($stmt->num_rows == 1) {
                    $username_err = "This username is already taken.";
                } else {
                    $username = trim($_POST["username"]);
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            $stmt->close();
        }
    }

    // Validate email
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter an email.";
    } elseif (!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
        $email_err = "Please enter a valid email address.";
    } else {
        // Prepare a select statement
        $sql = "SELECT user_id FROM users WHERE email = ?";

        if ($stmt = $conn->prepare($sql)) {
            // Bind variables to the prepared statement as parameters
            $stmt->bind_param("s", $param_email);

            // Set parameters
            $param_email = trim($_POST["email"]);

            // Attempt to execute the prepared statement
            if ($stmt->execute()) {
                // Store result
                $stmt->store_result();

                if ($stmt->num_rows == 1) {
                    $email_err = "This email is already registered.";
                } else {
                    $email = trim($_POST["email"]);
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            $stmt->close();
        }
    }

    // Validate password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";
    } elseif (strlen(trim($_POST["password"])) < 8) {
        $password_err = "Password must have at least 8 characters.";
    } else {
        $password = trim($_POST["password"]);
    }

    // Validate confirm password
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Please confirm password.";
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Passwords did not match.";
        }
    }

    // Validate preferred language
    $preferred_language = trim($_POST["preferred_language"]);
    if (!in_array($preferred_language, ['English', 'German'])) {
        $preferred_language = 'English'; // Default to English if invalid
    }

    // Check input errors before inserting in database
    if (empty($username_err) && empty($email_err) && empty($password_err) && empty($confirm_password_err)) {

        // Prepare an insert statement
        $sql = "INSERT INTO users (username, email, password, preferred_language, created_at) VALUES (?, ?, ?, ?, NOW())";

        if ($stmt = $conn->prepare($sql)) {
            // Bind variables to the prepared statement as parameters
            $stmt->bind_param("ssss", $param_username, $param_email, $param_password, $param_preferred_language);

            // Set parameters
            $param_username = $username;
            $param_email = $email;
            $param_password = password_hash($password, PASSWORD_DEFAULT); // Creates a password hash
            $param_preferred_language = $preferred_language;

            // Attempt to execute the prepared statement
            if ($stmt->execute()) {
                // Set registration success flag
                $registration_success = true;
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            $stmt->close();
        }
    }

    // Close connection
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join AI Language Tutor - Create Your Account</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #4895ef;
            --secondary: #6c63ff;
            --accent: #9f3ecf;
            --success: #06d6a0;
            --warning: #f72585;
            --danger: #e63946;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --card-bg: rgba(255, 255, 255, 0.95);
            --border-radius: 16px;
            --box-shadow: 0 8px 32px rgba(67, 97, 238, 0.15);
        }

        /* Base Styles */
        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7ff 0%, #e3e9ff 100%);
            position: relative;
            overflow-x: hidden;
        }

        /* Background animation elements */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(67, 97, 238, 0.1);
            animation: float 15s ease-in-out infinite;
        }

        .shape-1 {
            width: 500px;
            height: 500px;
            top: -250px;
            right: -100px;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 300px;
            height: 300px;
            bottom: -150px;
            left: -150px;
            background: rgba(159, 62, 207, 0.1);
            animation-delay: 2s;
        }

        .shape-3 {
            width: 200px;
            height: 200px;
            bottom: 20%;
            right: 10%;
            background: rgba(6, 214, 160, 0.1);
            animation-delay: 4s;
        }

        .shape-4 {
            width: 100px;
            height: 100px;
            top: 30%;
            left: 10%;
            background: rgba(247, 37, 133, 0.1);
            animation-delay: 6s;
        }

        @keyframes float {
            0% {
                transform: translate(0, 0) rotate(0deg);
            }
            50% {
                transform: translate(30px, 30px) rotate(10deg);
            }
            100% {
                transform: translate(0, 0) rotate(0deg);
            }
        }

        /* Page Container */
        .page-container {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        /* Left Section - Feature highlights */
        .features-section {
            display: none;
            flex: 1;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 3rem;
            position: relative;
            overflow: hidden;
        }

        @media (min-width: 1200px) {
            .features-section {
                display: flex;
                flex-direction: column;
                justify-content: center;
            }
        }

        .features-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('data:image/svg+xml,<svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><path fill="white" opacity="0.1" d="M96,95h4v1h-4v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9zm-1,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm9-10v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm9-10v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm9-10v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-10,0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9zm10,0h9v-9h-9v9z"/></svg>');
            opacity: 0.2;
        }

        .app-logo {
            margin-bottom: 3rem;
            text-align: center;
        }

        .app-logo i {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            display: inline-block;
            background: linear-gradient(45deg, #fff, rgba(255,255,255,0.5));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .app-logo h2 {
            font-weight: 700;
            margin-bottom: 0;
            font-size: 2rem;
        }

        .app-logo p {
            opacity: 0.8;
            margin-bottom: 0;
        }

        .feature-list {
            margin-top: 2rem;
        }

        .feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1rem;
            border-radius: var(--border-radius);
            background-color: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .feature-item:hover {
            transform: translateY(-5px);
            background-color: rgba(255, 255, 255, 0.15);
        }

        .feature-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            margin-right: 1rem;
            font-size: 1.5rem;
        }

        .feature-content h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .feature-content p {
            margin-bottom: 0;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        /* Right Section - Registration Form */
        .form-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .form-container {
            width: 100%;
            max-width: 550px;
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }

        .form-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .form-header h2 {
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .form-header p {
            color: var(--gray);
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control {
            height: 50px;
            border-radius: 12px;
            padding-left: 45px;
            font-size: 0.95rem;
            border: 1px solid #e0e0e0;
            background-color: white;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
            border-color: var(--primary-light);
        }

        .form-control.is-invalid {
            border-color: var(--danger);
            background-image: none;
        }

        .form-control.is-invalid:focus {
            box-shadow: 0 0 0 3px rgba(230, 57, 70, 0.15);
        }

        .form-icon {
            position: absolute;
            left: 15px;
            top: 43px;
            color: var(--gray);
            transition: all 0.3s ease;
        }

        .form-control:focus + .form-icon {
            color: var(--primary);
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .invalid-feedback {
            font-size: 0.85rem;
            color: var(--danger);
            margin-top: 0.5rem;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 43px;
            color: var(--gray);
            cursor: pointer;
            z-index: 10;
        }

        .form-buttons {
            margin-top: 2rem;
        }

        .btn {
            border-radius: 12px;
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
            text-transform: capitalize;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border: none;
            position: relative;
            overflow: hidden;
        }

        .btn-primary::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, rgba(255,255,255,0.2), rgba(255,255,255,0));
            transition: all 0.5s ease;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(67, 97, 238, 0.3);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
        }

        .btn-primary:hover::after {
            left: 100%;
        }

        .btn-outline-secondary {
            border-color: #e0e0e0;
            color: var(--gray);
        }

        .btn-outline-secondary:hover {
            background-color: #f8f9fa;
            border-color: #d0d0d0;
            color: var(--dark);
        }

        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            color: var(--gray);
        }

        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            position: relative;
            display: inline-block;
        }

        .login-link a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--primary);
            transition: all 0.3s ease;
        }

        .login-link a:hover::after {
            width: 100%;
        }

        /* Password strength meter */
        .password-strength {
            margin-top: 0.5rem;
            height: 5px;
            border-radius: 5px;
            background-color: #e0e0e0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .password-strength-meter {
            height: 100%;
            width: 0;
            transition: all 0.3s ease;
            border-radius: 5px;
            background-color: #e63946;
        }

        .strength-text {
            font-size: 0.75rem;
            margin-top: 0.3rem;
            color: var(--gray);
            display: flex;
            justify-content: space-between;
        }

        /* Weak password */
        .password-strength-meter.weak {
            background-color: #e63946;
            width: 25%;
        }

        /* Medium password */
        .password-strength-meter.medium {
            background-color: #f77f00;
            width: 50%;
        }

        /* Strong password */
        .password-strength-meter.strong {
            background-color: #2a9d8f;
            width: 75%;
        }

        /* Very strong password */
        .password-strength-meter.very-strong {
            background-color: #06d6a0;
            width: 100%;
        }

        /* Language selection with icons */
        .language-option {
            text-align: center;
            cursor: pointer;
            padding: 1rem;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }

        .language-option:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .language-option.active {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }

        .language-option.active .language-icon {
            color: var(--primary);
        }

        .language-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--gray);
            transition: all 0.3s ease;
        }

        .language-name {
            font-weight: 500;
            margin-bottom: 0;
        }

        /* Form steps */
        .form-step {
            display: none;
        }

        .form-step.active {
            display: block;
            animation: fadeInRight 0.5s ease forwards;
        }

        @keyframes fadeInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .steps-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
            text-align: center;
        }

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 20px;
            right: -50%;
            width: 100%;
            height: 2px;
            background-color: #e0e0e0;
            z-index: 1;
        }

        .step-item.completed:not(:last-child)::after {
            background-color: var(--primary);
        }

        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #f8f9fa;
            border: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--gray);
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }

        .step-item.active .step-icon {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .step-item.completed .step-icon {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .step-title {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }

        .step-item.active .step-title {
            color: var(--primary);
            font-weight: 600;
        }

        .step-item.completed .step-title {
            color: var(--primary);
        }

        /* Success message */
        .success-message {
            display: none;
            text-align: center;
            animation: fadeIn 0.5s ease forwards;
        }

        .success-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--success);
            color: white;
            font-size: 2rem;
            margin: 0 auto 1.5rem;
        }

        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            background-color: var(--primary);
            opacity: 0.8;
            animation: confetti 5s ease infinite;
            z-index: 1;
        }

        .confetti:nth-child(2) {
            width: 15px;
            height: 15px;
            background-color: var(--success);
            animation-delay: 0.5s;
            animation-duration: 4s;
        }

        .confetti:nth-child(3) {
            width: 8px;
            height: 8px;
            background-color: var(--warning);
            animation-delay: 1s;
            animation-duration: 6s;
        }

        .confetti:nth-child(4) {
            width: 12px;
            height: 12px;
            background-color: var(--accent);
            animation-delay: 1.5s;
            animation-duration: 5s;
        }

        @keyframes confetti {
            0% {
                transform: translateY(0) rotate(0);
                opacity: 0.8;
            }
            100% {
                transform: translateY(100vh) rotate(360deg);
                opacity: 0;
            }
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

        /* Responsive */
        @media (max-width: 1199.98px) {
            .form-container {
                max-width: 500px;
            }
        }

        @media (max-width: 767.98px) {
            .form-container {
                padding: 1.5rem;
            }

            .form-header h2 {
                font-size: 1.5rem;
            }

            .steps-indicator {
                margin-bottom: 1.5rem;
            }

            .step-icon {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }

            .step-title {
                font-size: 0.7rem;
            }

            .language-option {
                padding: 0.75rem;
            }

            .form-buttons .btn {
                padding: 0.6rem 1.2rem;
            }
        }
    </style>
</head>
<body>
<!-- Background Animation Elements -->
<div class="bg-animation">
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
    <div class="shape shape-3"></div>
    <div class="shape shape-4"></div>
</div>

<div class="page-container">
    <!-- Left Section - Feature Highlights -->
    <div class="features-section" data-aos="fade-right">
        <div class="app-logo">
            <i class="fas fa-language"></i>
            <h2>AI Language Tutor</h2>
            <p>Your personal guide to language mastery</p>
        </div>

        <div class="feature-list">
            <div class="feature-item" data-aos="fade-up" data-aos-delay="100">
                <div class="feature-icon">
                    <i class="fas fa-comments"></i>
                </div>
                <div class="feature-content">
                    <h3>Conversation Practice</h3>
                    <p>Chat naturally with an AI tutor in English or German</p>
                </div>
            </div>

            <div class="feature-item" data-aos="fade-up" data-aos-delay="200">
                <div class="feature-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="feature-content">
                    <h3>Real-Time Corrections</h3>
                    <p>Get instant feedback on grammar and vocabulary</p>
                </div>
            </div>

            <div class="feature-item" data-aos="fade-up" data-aos-delay="300">
                <div class="feature-icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="feature-content">
                    <h3>Vocabulary Building</h3>
                    <p>Track your learning with a personalized vocabulary system</p>
                </div>
            </div>

            <div class="feature-item" data-aos="fade-up" data-aos-delay="400">
                <div class="feature-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="feature-content">
                    <h3>Progress Tracking</h3>
                    <p>Visualize your improvement with detailed analytics</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Section - Registration Form -->
    <div class="form-section" data-aos="fade-left">
        <div class="form-container" id="registerForm">
            <?php if ($registration_success): ?>
                <!-- Success Message -->
                <div class="success-message" style="display: block;">
                    <div class="success-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <h2>Registration Successful!</h2>
                    <p class="mb-4">Your account has been created successfully. You can now log in and start your language learning journey.</p>
                    <a href="login.php" class="btn btn-primary">Log In Now</a>

                    <!-- Confetti Animation -->
                    <div class="confetti" style="left: 20%; top: -10%;"></div>
                    <div class="confetti" style="left: 60%; top: -20%;"></div>
                    <div class="confetti" style="left: 30%; top: -15%;"></div>
                    <div class="confetti" style="left: 70%; top: -5%;"></div>
                </div>
            <?php else: ?>
                <!-- Registration Form -->
                <div class="form-header">
                    <h2>Create Your Account</h2>
                    <p>Join our community and start your language learning journey</p>
                </div>

                <!-- Form Steps Indicator -->
                <div class="steps-indicator">
                    <div class="step-item active" data-step="1">
                        <div class="step-icon">1</div>
                        <div class="step-title">Account</div>
                    </div>
                    <div class="step-item" data-step="2">
                        <div class="step-icon">2</div>
                        <div class="step-title">Details</div>
                    </div>
                    <div class="step-item" data-step="3">
                        <div class="step-icon">3</div>
                        <div class="step-title">Preferences</div>
                    </div>
                </div>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="stepForm">
                    <!-- Step 1: Account Information -->
                    <div class="form-step active" id="step1">
                        <div class="form-group">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" name="username" id="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $username; ?>" placeholder="Choose a username" autofocus>
                            <i class="fas fa-user form-icon"></i>
                            <?php if (!empty($username_err)): ?>
                                <div class="invalid-feedback"><?php echo $username_err; ?></div>
                            <?php endif; ?>
                            <small class="form-text text-muted">Username can contain letters, numbers, and underscores.</small>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $email; ?>" placeholder="Enter your email">
                            <i class="fas fa-envelope form-icon"></i>
                            <?php if (!empty($email_err)): ?>
                                <div class="invalid-feedback"><?php echo $email_err; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-buttons">
                            <button type="button" class="btn btn-primary w-100" id="step1Next">Continue <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>

                    <!-- Step 2: Password -->
                    <div class="form-step" id="step2">
                        <div class="form-group">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" placeholder="Create a strong password">
                            <i class="fas fa-lock form-icon"></i>
                            <span class="password-toggle" id="passwordToggle"><i class="fas fa-eye"></i></span>
                            <?php if (!empty($password_err)): ?>
                                <div class="invalid-feedback"><?php echo $password_err; ?></div>
                            <?php endif; ?>

                            <!-- Password Strength Meter -->
                            <div class="password-strength">
                                <div class="password-strength-meter" id="passwordStrengthMeter"></div>
                            </div>
                            <div class="strength-text">
                                <span id="passwordStrengthText">Password strength</span>
                                <span id="passwordCriteria">8+ characters</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" placeholder="Confirm your password">
                            <i class="fas fa-lock form-icon"></i>
                            <span class="password-toggle" id="confirmPasswordToggle"><i class="fas fa-eye"></i></span>
                            <?php if (!empty($confirm_password_err)): ?>
                                <div class="invalid-feedback"><?php echo $confirm_password_err; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-buttons d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary" id="step2Back"><i class="fas fa-arrow-left me-1"></i> Back</button>
                            <button type="button" class="btn btn-primary flex-grow-1" id="step2Next">Continue <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>

                    <!-- Step 3: Language Preference -->
                    <div class="form-step" id="step3">
                        <h5 class="mb-3">Choose Your Learning Language</h5>
                        <p class="text-muted mb-4">Select the language you want to learn:</p>

                        <div class="row">
                            <div class="col-6">
                                <div class="language-option active" data-language="English">
                                    <div class="language-icon">
                                        <i class="fas fa-flag-usa"></i>
                                    </div>
                                    <div class="language-name">English</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="language-option" data-language="German">
                                    <div class="language-icon">
                                        <i class="fas fa-globe-europe"></i>
                                    </div>
                                    <div class="language-name">German</div>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="preferred_language" id="preferred_language" value="English">

                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" id="terms" name="terms" required>
                            <label class="form-check-label" for="terms">
                                I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms of Service</a> and <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>
                            </label>
                        </div>

                        <div class="form-buttons d-flex gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary" id="step3Back"><i class="fas fa-arrow-left me-1"></i> Back</button>
                            <button type="submit" class="btn btn-primary flex-grow-1">Create Account <i class="fas fa-user-plus ms-1"></i></button>
                        </div>
                    </div>
                </form>

                <div class="login-link">
                    Already have an account? <a href="login.php">Log in here</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Terms Modal -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel">Terms of Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>These Terms of Service govern your use of the AI Language Tutor platform.</p>

                <h6>1. User Accounts</h6>
                <p>When you create an account with us, you must provide accurate information. You are responsible for maintaining the security of your account.</p>

                <h6>2. Platform Usage</h6>
                <p>You agree to use the platform for language learning purposes and not to engage in any activity that may interfere with its proper functioning.</p>

                <h6>3. Privacy</h6>
                <p>Your use of the service is also governed by our Privacy Policy, which is incorporated by reference into these Terms.</p>

                <h6>4. Changes to Terms</h6>
                <p>We reserve the right to modify these terms at any time. Your continued use of the platform after such modifications constitutes your acceptance of the new terms.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
            </div>
        </div>
    </div>
</div>

<!-- Privacy Modal -->
<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="privacyModalLabel">Privacy Policy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>At AI Language Tutor, we value your privacy and are committed to protecting your personal data.</p>

                <h6>1. Data Collection</h6>
                <p>We collect information necessary to provide our services, including registration details, language data, and usage statistics.</p>

                <h6>2. Data Usage</h6>
                <p>We use your data to provide and improve our service, offer personalized learning experiences, and maintain platform security.</p>

                <h6>3. Data Sharing</h6>
                <p>We do not sell your personal information to third parties. Data may be shared with service providers who assist in platform operations.</p>

                <h6>4. Data Security</h6>
                <p>We implement appropriate security measures to protect your personal information from unauthorized access or disclosure.</p>

                <h6>5. Your Rights</h6>
                <p>You have the right to access, correct, or delete your personal information. You may also withdraw consent where applicable.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- AOS Animation Library -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize AOS animations
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true
        });

        // Form step navigation
        const stepForm = document.getElementById('stepForm');
        const steps = document.querySelectorAll('.form-step');
        const stepItems = document.querySelectorAll('.step-item');

        // Step 1 validation and navigation
        document.getElementById('step1Next').addEventListener('click', function() {
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            let isValid = true;

            // Simple validation
            if (username === '') {
                document.getElementById('username').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('username').classList.remove('is-invalid');
            }

            if (email === '' || !isValidEmail(email)) {
                document.getElementById('email').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('email').classList.remove('is-invalid');
            }

            if (isValid) {
                // Move to step 2
                steps[0].classList.remove('active');
                steps[1].classList.add('active');

                // Update step indicator
                stepItems[0].classList.add('completed');
                stepItems[1].classList.add('active');
            }
        });

        // Step 2 navigation
        document.getElementById('step2Next').addEventListener('click', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            let isValid = true;

            // Simple validation
            if (password.length < 8) {
                document.getElementById('password').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('password').classList.remove('is-invalid');
            }

            if (confirmPassword === '' || confirmPassword !== password) {
                document.getElementById('confirm_password').classList.add('is-invalid');
                isValid = false;
            } else {
                document.getElementById('confirm_password').classList.remove('is-invalid');
            }

            if (isValid) {
                // Move to step 3
                steps[1].classList.remove('active');
                steps[2].classList.add('active');

                // Update step indicator
                stepItems[1].classList.add('completed');
                stepItems[2].classList.add('active');
            }
        });

        // Back buttons
        document.getElementById('step2Back').addEventListener('click', function() {
            steps[1].classList.remove('active');
            steps[0].classList.add('active');

            stepItems[1].classList.remove('active');
        });

        document.getElementById('step3Back').addEventListener('click', function() {
            steps[2].classList.remove('active');
            steps[1].classList.add('active');

            stepItems[2].classList.remove('active');
        });

        // Password visibility toggle
        document.getElementById('passwordToggle').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                this.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                passwordField.type = 'password';
                this.innerHTML = '<i class="fas fa-eye"></i>';
            }
        });

        document.getElementById('confirmPasswordToggle').addEventListener('click', function() {
            const confirmPasswordField = document.getElementById('confirm_password');
            if (confirmPasswordField.type === 'password') {
                confirmPasswordField.type = 'text';
                this.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                confirmPasswordField.type = 'password';
                this.innerHTML = '<i class="fas fa-eye"></i>';
            }
        });

        // Password strength meter
        const passwordField = document.getElementById('password');
        const strengthMeter = document.getElementById('passwordStrengthMeter');
        const strengthText = document.getElementById('passwordStrengthText');

        passwordField.addEventListener('input', function() {
            const strength = calculatePasswordStrength(this.value);

            // Update strength meter
            strengthMeter.className = 'password-strength-meter';
            if (strength === 0) {
                strengthMeter.style.width = '0';
                strengthText.textContent = 'Password strength';
            } else if (strength < 30) {
                strengthMeter.classList.add('weak');
                strengthText.textContent = 'Weak';
            } else if (strength < 60) {
                strengthMeter.classList.add('medium');
                strengthText.textContent = 'Medium';
            } else if (strength < 80) {
                strengthMeter.classList.add('strong');
                strengthText.textContent = 'Strong';
            } else {
                strengthMeter.classList.add('very-strong');
                strengthText.textContent = 'Very Strong';
            }
        });

        // Language selection
        const languageOptions = document.querySelectorAll('.language-option');
        const preferredLanguageInput = document.getElementById('preferred_language');

        languageOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove active class from all options
                languageOptions.forEach(opt => opt.classList.remove('active'));

                // Add active class to clicked option
                this.classList.add('active');

                // Update hidden input
                preferredLanguageInput.value = this.getAttribute('data-language');
            });
        });

        // Helper functions
        function isValidEmail(email) {
            const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return regex.test(email);
        }

        function calculatePasswordStrength(password) {
            // Empty password
            if (!password) return 0;

            let strength = 0;

            // Length contribution (up to 25 points)
            strength += Math.min(25, password.length * 2);

            // Character variety contribution
            if (/[a-z]/.test(password)) strength += 10; // lowercase
            if (/[A-Z]/.test(password)) strength += 10; // uppercase
            if (/[0-9]/.test(password)) strength += 10; // numbers
            if (/[^a-zA-Z0-9]/.test(password)) strength += 15; // special chars

            // Length contribution beyond 8 characters
            if (password.length > 8) strength += 10;

            // Repeating characters penalty
            if (/(.)\1{2,}/.test(password)) strength -= 10;

            return Math.min(100, Math.max(0, strength));
        }
    });
</script>
</body>
</html>