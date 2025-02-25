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
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
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
                // Redirect to login page with success message
                $_SESSION['register_success'] = true;
                header("location: login.php");
                exit;
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
    <title>Register - AI Language Tutor</title>
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
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--body-bg);
            color: var(--dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }
        
        .register-container {
            max-width: 550px;
            width: 100%;
            padding: 15px;
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            background-color: var(--card-bg);
            transition: var(--transition);
        }
        
        .card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-5px);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-bottom: none;
            padding: 2rem 1.5rem;
            text-align: center;
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
            margin-bottom: 0.5rem;
            font-weight: 700;
            font-size: 1.75rem;
        }
        
        .card-subtitle {
            opacity: 0.8;
            font-weight: 300;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .card-footer {
            background-color: transparent;
            border-top: 1px solid var(--card-border);
            text-align: center;
            padding: 1.5rem;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            border: 1px solid var(--card-border);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.1);
        }
        
        .form-select {
            border: 1px solid var(--card-border);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            transition: var(--transition);
        }
        
        .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.1);
        }
        
        .input-group-text {
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 0.5rem 0 0 0.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
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
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .invalid-feedback {
            color: var(--danger);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .header-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: white;
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }
        
        a:hover {
            color: var(--secondary);
            text-decoration: underline;
        }
        
        .password-toggle {
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 10;
        }
        
        .password-field-wrapper {
            position: relative;
        }
        
        @media (max-width: 576px) {
            .card-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="card">
            <div class="card-header">
                <div class="header-icon">
                    <i class="fas fa-language"></i>
                </div>
                <h3 class="card-title">Create an Account</h3>
                <p class="card-subtitle mb-0">Join AI Language Tutor to start learning</p>
            </div>
            <div class="card-body">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="row mb-3">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <label for="username" class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" name="username" id="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $username; ?>" placeholder="Choose a username">
                            </div>
                            <?php if (!empty($username_err)): ?>
                                <div class="invalid-feedback d-block"><?php echo $username_err; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" id="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $email; ?>" placeholder="Enter your email">
                            </div>
                            <?php if (!empty($email_err)): ?>
                                <div class="invalid-feedback d-block"><?php echo $email_err; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <label for="password" class="form-label">Password</label>
                            <div class="password-field-wrapper">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" placeholder="Create a password">
                                </div>
                                <span class="password-toggle" id="passwordToggle">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <?php if (!empty($password_err)): ?>
                                <div class="invalid-feedback d-block"><?php echo $password_err; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <div class="password-field-wrapper">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" placeholder="Confirm your password">
                                </div>
                                <span class="password-toggle" id="confirmPasswordToggle">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <?php if (!empty($confirm_password_err)): ?>
                                <div class="invalid-feedback d-block"><?php echo $confirm_password_err; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="preferred_language" class="form-label">Preferred Language</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-globe"></i></span>
                            <select name="preferred_language" id="preferred_language" class="form-select">
                                <option value="English" <?php echo $preferred_language === 'English' ? 'selected' : ''; ?>>English</option>
                                <option value="German" <?php echo $preferred_language === 'German' ? 'selected' : ''; ?>>German</option>
                            </select>
                        </div>
                        <div class="form-text text-muted mt-2">
                            <i class="fas fa-info-circle me-1"></i> This will be your default language for conversations
                        </div>
                    </div>
                    
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i> Create Account
                        </button>
                    </div>
                    
                    <div class="text-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="terms" name="terms" required>
                            <label class="form-check-label small" for="terms">
                                I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms of Service</a> and <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="card-footer">
                <p class="mb-0">Already have an account? <a href="login.php">Log in here</a></p>
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
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password visibility toggle
            const passwordToggle = document.getElementById('passwordToggle');
            const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
            const passwordField = document.getElementById('password');
            const confirmPasswordField = document.getElementById('confirm_password');
            
            passwordToggle.addEventListener('click', function() {
                togglePasswordVisibility(passwordField, this);
            });
            
            confirmPasswordToggle.addEventListener('click', function() {
                togglePasswordVisibility(confirmPasswordField, this);
            });
            
            function togglePasswordVisibility(field, button) {
                if (field.type === 'password') {
                    field.type = 'text';
                    button.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    field.type = 'password';
                    button.innerHTML = '<i class="fas fa-eye"></i>';
                }
            }
        });
    </script>
</body>
</html>