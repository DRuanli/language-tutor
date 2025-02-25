<?php
// Initialize the session
session_start();

// Check if user is already logged in
if (isset($_SESSION["user_id"])) {
    header("location: index.php");
    exit;
}

// Include database connection
require_once "includes/db.php";

// Define variables and initialize with empty values
$username = $password = "";
$username_err = $password_err = $login_err = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Check if username is empty
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter username.";
    } else {
        $username = trim($_POST["username"]);
    }
    
    // Check if password is empty
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }
    
    // Validate credentials
    if (empty($username_err) && empty($password_err)) {
        // Prepare a select statement
        $sql = "SELECT user_id, username, password FROM users WHERE username = ?";
        
        if ($stmt = $conn->prepare($sql)) {
            // Bind variables to the prepared statement as parameters
            $stmt->bind_param("s", $param_username);
            
            // Set parameters
            $param_username = $username;
            
            // Attempt to execute the prepared statement
            if ($stmt->execute()) {
                // Store result
                $stmt->store_result();
                
                // Check if username exists, if yes then verify password
                if ($stmt->num_rows == 1) {
                    // Bind result variables
                    $stmt->bind_result($id, $username, $hashed_password);
                    if ($stmt->fetch()) {
                        if (password_verify($password, $hashed_password)) {
                            // Password is correct, start a new session
                            session_start();
                            
                            // Store data in session variables
                            $_SESSION["user_id"] = $id;
                            $_SESSION["username"] = $username;
                            
                            // Update last login time
                            $update_stmt = $conn->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?");
                            $update_stmt->bind_param("i", $id);
                            $update_stmt->execute();
                            $update_stmt->close();
                            
                            // Redirect user to welcome page
                            header("location: index.php");
                        } else {
                            // Password is not valid
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else {
                    // Username doesn't exist
                    $login_err = "Invalid username or password.";
                }
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
    <title>Login - AI Language Tutor</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4941E1;
            --primary-dark: #3730B3;
            --accent: #9F3ECF;
            --background: #f9f7ff;
            --card-bg: rgba(255, 255, 255, 0.95);
            --text-dark: #333342;
            --text-light: #f9f7ff;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            height: 100vh;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, var(--background), #e0dfff);
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('assets/img/magic-particles.png');
            background-size: cover;
            opacity: 0.1;
            animation: float 60s infinite linear;
        }
        
        @keyframes float {
            0% { background-position: 0 0; }
            100% { background-position: 100% 100%; }
        }
        
        .login-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            z-index: 1;
        }
        
        .login-container {
            max-width: 450px;
            width: 100%;
            padding: 20px;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(73, 65, 225, 0.15), 
                        0 5px 15px rgba(0, 0, 0, 0.07);
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(73, 65, 225, 0.2), 
                        0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            background: linear-gradient(to right, var(--primary), var(--accent));
            color: var(--text-light);
            border: none;
            text-align: center;
            padding: 30px 20px;
            position: relative;
            overflow: hidden;
        }
        
        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            animation: pulse 8s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.05); opacity: 0.7; }
            100% { transform: scale(1); opacity: 0.5; }
        }
        
        .card-title {
            font-family: 'Cinzel', serif;
            font-weight: 700;
            font-size: 2rem;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
            letter-spacing: 1px;
        }
        
        .card-subtitle {
            font-weight: 300;
            opacity: 0.9;
            font-size: 1rem;
        }
        
        .card-body {
            padding: 2.5rem;
        }
        
        .card-footer {
            background: transparent;
            border-top: 1px solid rgba(73, 65, 225, 0.1);
            text-align: center;
            padding: 1.5rem;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .input-group {
            margin-bottom: 0.5rem;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: box-shadow 0.3s ease;
        }
        
        .input-group:focus-within {
            box-shadow: 0 3px 10px rgba(73, 65, 225, 0.2);
        }
        
        .input-group-text {
            background: linear-gradient(to bottom, var(--primary), var(--primary-dark));
            color: var(--text-light);
            border: none;
            width: 45px;
            display: flex;
            justify-content: center;
        }
        
        .form-control {
            border: 1px solid rgba(73, 65, 225, 0.1);
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        .form-control:focus {
            box-shadow: none;
            border-color: var(--primary);
        }
        
        .invalid-feedback {
            color: #e74c3c;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        
        .btn-primary {
            background: linear-gradient(to right, var(--primary), var(--accent));
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, rgba(255,255,255,0.2), rgba(255,255,255,0));
            transition: all 0.5s ease;
            z-index: -1;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(73, 65, 225, 0.4);
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:active {
            transform: translateY(1px);
        }
        
        .alert-danger {
            background-color: rgba(231, 76, 60, 0.1);
            border-left: 4px solid #e74c3c;
            color: #e74c3c;
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        
        a {
            color: var(--primary);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        a:hover {
            color: var(--accent);
            text-decoration: none;
        }
        
        .sign-up-link {
            position: relative;
            display: inline-block;
        }
        
        .sign-up-link::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 2px;
            bottom: -2px;
            left: 0;
            background: linear-gradient(to right, var(--primary), var(--accent));
            transform: scaleX(0);
            transform-origin: bottom right;
            transition: transform 0.3s ease;
        }
        
        .sign-up-link:hover::after {
            transform: scaleX(1);
            transform-origin: bottom left;
        }
        
        /* Floating magical elements */
        .magic-element {
            position: absolute;
            background: radial-gradient(circle, rgba(159, 62, 207, 0.3) 0%, rgba(159, 62, 207, 0) 70%);
            border-radius: 50%;
            z-index: -1;
            opacity: 0.7;
        }
        
        .magic-element-1 {
            width: 300px;
            height: 300px;
            top: -150px;
            right: -100px;
            animation: float-slow 15s infinite alternate ease-in-out;
        }
        
        .magic-element-2 {
            width: 200px;
            height: 200px;
            bottom: -100px;
            left: -50px;
            animation: float-slow 12s infinite alternate-reverse ease-in-out;
        }
        
        @keyframes float-slow {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(30px, 30px) rotate(10deg); }
        }
        
        /* Custom icon animation */
        .icon-glow {
            animation: glow 2s infinite alternate ease-in-out;
        }
        
        @keyframes glow {
            0% { text-shadow: 0 0 5px rgba(255, 255, 255, 0.5); }
            100% { text-shadow: 0 0 15px rgba(255, 255, 255, 0.9), 0 0 30px rgba(255, 255, 255, 0.5); }
        }
        
        @media (max-width: 768px) {
            .card-body {
                padding: 1.5rem;
            }
            
            .login-container {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Magical floating elements -->
    <div class="magic-element magic-element-1"></div>
    <div class="magic-element magic-element-2"></div>
    
    <div class="login-wrapper">
        <div class="login-container">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-language me-2 icon-glow"></i>AI Language Tutor</h3>
                    <p class="card-subtitle mb-0">Unlock the Magic of Language</p>
                </div>
                <div class="card-body">
                    
                    <?php if (!empty($login_err)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $login_err; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <div class="mb-4">
                            <label for="username" class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" name="username" id="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $username; ?>" placeholder="Enter your username">
                            </div>
                            <?php if (!empty($username_err)): ?>
                                <div class="invalid-feedback"><?php echo $username_err; ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-4">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" placeholder="Enter your password">
                            </div>
                            <?php if (!empty($password_err)): ?>
                                <div class="invalid-feedback"><?php echo $password_err; ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt me-2"></i>Begin Your Journey
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer">
                    <p class="mb-0">Don't have an account? <a href="register.php" class="sign-up-link">Sign up now</a></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>