<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the configuration file
require 'config.php'; // Assuming config.php is in the same directory

// Database connection using config.php credentials
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve and sanitize inputs
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Please provide both username and password.";
    } else {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert user into the database
        $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        if (!$stmt) {
            $error = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("ss", $username, $hashed_password);
            if ($stmt->execute()) {
                // Registration successful, redirect to index with success message
                $_SESSION['success_message'] = "Registration successful! Please log in.";
                header("Location: index.php");
                exit();
            } else {
                if ($conn->errno === 1062) { // Duplicate entry
                    $error = "Username already exists. Please choose a different one.";
                } else {
                    $error = "Error: " . $stmt->error;
                }
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI Interaction and the Finite Backroom</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            background-color: #ff00ff; /* Garish 90s background color */
            background-image: url('https://i.imgur.com/your-garish-background-image.gif'); /* Replace with a 90s-style background image */
            background-repeat: repeat;
            color: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .register-container {
            background-color: rgba(0, 0, 0, 0.7);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px #0000ff;
            width: 300px;
        }
        h1 {
            font-family: 'Comic Sans MS', cursive, sans-serif;
            text-align: center;
            margin-bottom: 20px;
        }
        form { 
            display: flex; 
            flex-direction: column; 
        }
        input { 
            padding: 10px; 
            margin-bottom: 15px; 
            border: 2px solid #0000ff; /* 90s-style border */
            border-radius: 5px;
            background-color: #ffffff;
            color: #000000;
            font-family: 'Courier New', Courier, monospace;
        }
        button { 
            padding: 10px; 
            background-color: #00ffff; /* 90s-style button color */
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            color: #000000;
        }
        button:hover {
            background-color: #00bbbb;
        }
        .error { 
            color: red; 
            margin-bottom: 15px;
            text-align: center;
        }
        .disclaimer {
            margin-top: 20px;
            font-size: 12px;
            color: #ffff00; /* Bright yellow for visibility */
        }
        a {
            color: #ffffff;
            text-decoration: underline;
        }
        a:hover {
            color: #00ffff;
        }
    </style>
</head>
<body>

<div class="register-container">
    <h1>Register</h1>
    
    <?php if (isset($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST" action="">
        <input type="text" name="username" required placeholder="Username">
        <input type="password" name="password" required placeholder="Password">
        <button type="submit">Register</button>
    </form>
    
    <div class="info">
        <p>You need an API key to use this site. Obtain one from <a href="https://openai.com/index/openai-api/" target="_blank">OpenAI API</a>.</p>
        <p>Once registered, you can <a href="index.php">return to the homepage</a>.</p>
    </div>
    
    <div class="disclaimer">
        <p><strong>Disclaimer:</strong> I am not responsible for anything you do on this site. Your API keys are stored in a database and site is intended for personal testing use only with small amounts of API credit.</p>
    </div>
</div>

</body>
</html>