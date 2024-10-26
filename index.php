<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the configuration file
require 'config.php'; // Assuming config.php is in the same directory

// Initialize conversation history if not set
if (!isset($_SESSION['conversation'])) {
    $_SESSION['conversation'] = [];
}

// Initialize system prompt settings
if (!isset($_SESSION['system_prompt_enabled'])) {
    $_SESSION['system_prompt_enabled'] = false;
}
if (!isset($_SESSION['system_prompt'])) {
    $_SESSION['system_prompt'] = '';
}

// Database connection using config.php credentials
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id, $hashedPassword);
        $stmt->fetch();

        if (password_verify($password, $hashedPassword)) {
            $_SESSION['user_id'] = $id; // Store user ID in session
            // Reset conversation history upon new login
            $_SESSION['conversation'] = [];
        } else {
            $_SESSION['login_error'] = "Invalid password.";
        }
    } else {
        $_SESSION['login_error'] = "User not found.";
    }
    $stmt->close();

    // Redirect to prevent form resubmission
    header("Location: index.php");
    exit();
}

// Fetch API keys for the logged-in user, mapped by model
$api_keys = [];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT model, api_key FROM api_keys WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($model, $api_key);

    while ($stmt->fetch()) {
        $api_keys[$model] = $api_key; // Store API keys mapped to models
    }
    $stmt->close();
}

// Handle API key updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_keys'])) {
    $model = $_POST['model'];
    $api_key = $_POST['api_key'];
    $user_id = $_SESSION['user_id'];

    // Update or insert the API key for the specific model
    $stmt = $conn->prepare("INSERT INTO api_keys (user_id, model, api_key) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE api_key = ?");
    $stmt->bind_param("isss", $user_id, $model, $api_key, $api_key);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "API key for " . htmlspecialchars($model) . " updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();

    // Redirect to prevent form resubmission
    header("Location: index.php");
    exit();
}

// Handle system prompt updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_system_prompt'])) {
    $system_prompt_enabled = isset($_POST['system_prompt_enabled']) ? true : false;
    $system_prompt = trim($_POST['system_prompt'] ?? '');

    $_SESSION['system_prompt_enabled'] = $system_prompt_enabled;
    $_SESSION['system_prompt'] = $system_prompt;

    $_SESSION['system_prompt_message'] = $system_prompt_enabled ? "System prompt enabled." : "System prompt disabled.";

    // Redirect to prevent form resubmission
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Interaction</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        #responseContainer { border: 1px solid #ccc; padding: 10px; height: 300px; overflow-y: auto; margin-top: 10px; }
        button { padding: 10px; margin: 5px; }
        textarea { width: 100%; height: 60px; }
        .login-container, .api-key-container, .system-prompt-container { margin-bottom: 20px; }
        label { display: block; margin-top: 10px; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 8px; margin-top: 5px; }
        .message { margin: 10px 0; padding: 10px; border-bottom: 1px solid #eee; }
        .user-message { background-color: #f5f5f5; }
        .ai-message { background-color: #fff; }
        .error-message { color: red; background-color: #fff3f3; padding: 10px; margin: 10px 0; }
        .loading { opacity: 0.6; }
        .system-prompt-container { border: 1px solid #ccc; padding: 10px; }
    </style>
</head>
<body>

<h1>AI Interaction</h1>

<!-- Login Form -->
<div class="login-container">
    <?php if (!isset($_SESSION['user_id'])): ?>
        <form method="POST" action="">
            <label for="username">Username:</label>
            <input type="text" name="username" required placeholder="Username" id="username">
            
            <label for="password">Password:</label>
            <input type="password" name="password" required placeholder="Password" id="password">
            
            <button type="submit" name="login">Login</button>
        </form>
        <?php if (isset($_SESSION['login_error'])): ?>
            <p style="color: red;"><?php echo htmlspecialchars($_SESSION['login_error']); ?></p>
            <?php unset($_SESSION['login_error']); ?>
        <?php endif; ?>
    <?php else: ?>
        <p>Welcome! <a href="?logout=true">Logout</a></p>
    <?php endif; ?>
</div>

<?php if (isset($_SESSION['user_id'])): ?>

    <!-- Display Success and Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <p style="color: green;"><?php echo $_SESSION['success_message']; ?></p>
        <?php unset($_SESSION['success_message']); ?>
    <?php elseif (isset($_SESSION['error_message'])): ?>
        <p style="color: red;"><?php echo $_SESSION['error_message']; ?></p>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['system_prompt_message'])): ?>
        <p style="color: green;"><?php echo $_SESSION['system_prompt_message']; ?></p>
        <?php unset($_SESSION['system_prompt_message']); ?>
    <?php endif; ?>

    <!-- API Key Management -->
    <div class="api-key-container">
        <h2>Manage API Keys</h2>
        <form method="POST" action="">
            <label for="modelSelect">Select API Model:</label>
            <select name="model" id="modelSelect" required>
                <option value="gpt-4" <?php echo isset($api_keys['gpt-4']) ? 'selected' : ''; ?>>GPT-4</option>
                <option value="gpt-4o" <?php echo isset($api_keys['gpt-4o']) ? 'selected' : ''; ?>>GPT-4o</option> <!-- New Model -->
                <option value="gpt-4o-mini" <?php echo isset($api_keys['gpt-4o-mini']) ? 'selected' : ''; ?>>GPT-4o Mini</option> <!-- New Model -->
                <option value="claude-3-opus-20240229" <?php echo isset($api_keys['claude-3-opus-20240229']) ? 'selected' : ''; ?>>Claude-3 Opus</option>
                <option value="claude-3-5-sonnet-latest" <?php echo isset($api_keys['claude-3-5-sonnet-latest']) ? 'selected' : ''; ?>>Claude-3.5 Sonnet</option>
                <option value="claude-3-haiku-20240229" <?php echo isset($api_keys['claude-3-haiku-20240229']) ? 'selected' : ''; ?>>Claude-3 Haiku</option>
            </select>
            
            <label for="api_key">API Key:</label>
            <input type="text" name="api_key" id="api_key" placeholder="Enter your API key" value="<?php echo isset($api_keys[$_POST['model'] ?? '']) ? htmlspecialchars($api_keys[$_POST['model'] ?? '']) : ''; ?>" required>
            <button type="submit" name="update_keys">Update API Key</button>
        </form>
    </div>

    <!-- System Prompt Management -->
    <div class="system-prompt-container">
        <h2>System Prompt Settings</h2>
        <form method="POST" action="">
            <label>
                <input type="checkbox" name="system_prompt_enabled" <?php echo $_SESSION['system_prompt_enabled'] ? 'checked' : ''; ?>>
                Enable System Prompt
            </label>
            <label for="system_prompt">System Prompt:</label>
            <textarea name="system_prompt" id="system_prompt" placeholder="Enter system prompt here..."><?php echo htmlspecialchars($_SESSION['system_prompt']); ?></textarea>
            <button type="submit" name="update_system_prompt">Update System Prompt</button>
        </form>
    </div>

    <!-- AI Interaction -->
    <h2>Interact with AI</h2>
    
    <div id="responseContainer">
        <?php
        // Display existing conversation history
        foreach ($_SESSION['conversation'] as $message) {
            if ($message['role'] === 'user') {
                echo '<div class="message user-message"><strong>User:</strong> ' . htmlspecialchars($message['content']) . '</div>';
            } elseif ($message['role'] === 'assistant') {
                echo '<div class="message ai-message"><strong>AI (' . htmlspecialchars($message['model']) . '):</strong> ' . nl2br(htmlspecialchars($message['content'])) . '</div>';
            }
        }
        ?>
    </div>
    
    <label for="aiModelSelect">Select AI Model:</label>
    <select name="ai_model" id="aiModelSelect" required>
        <option value="gpt-4">GPT-4</option>
        <option value="gpt-4o">GPT-4o</option> <!-- New Model -->
        <option value="gpt-4o-mini">GPT-4o Mini</option> <!-- New Model -->
        <option value="claude-3-5-sonnet-latest">Claude-3.5 Sonnet</option> <!-- Ensure this matches backend -->
        <option value="claude-3-opus-20240229">Claude-3 Opus</option>
        <option value="claude-3-haiku-20240229">Claude-3 Haiku</option>
    </select>
    <br><br>
    <label for="promptInput">Enter your prompt:</label>
    <textarea id="promptInput" placeholder="Enter your prompt"></textarea>
    <br>
    <button id="sendButton">Send</button>
    <button id="clearChatButton">Clear Chat</button>

    <script>
        document.getElementById('sendButton').addEventListener('click', async () => {
            const prompt = document.getElementById('promptInput').value.trim();
            const model = document.getElementById('aiModelSelect').value;
            const responseContainer = document.getElementById('responseContainer');
            const promptInput = document.getElementById('promptInput');

            if (!prompt) {
                alert('Please enter a prompt.');
                return;
            }

            // Disable the send button and show loading state
            const sendButton = document.getElementById('sendButton');
            sendButton.disabled = true;
            sendButton.textContent = 'Sending...';
            
            // Add user message immediately
            const userMessageDiv = document.createElement('div');
            userMessageDiv.className = 'message user-message';
            userMessageDiv.innerHTML = `<strong>User:</strong> ${escapeHtml(prompt)}`;
            responseContainer.appendChild(userMessageDiv);
            
            // Create AI message container with loading state
            const aiMessageDiv = document.createElement('div');
            aiMessageDiv.className = 'message ai-message loading';
            aiMessageDiv.innerHTML = `<strong>AI (${escapeHtml(model)}):</strong> Processing...`;
            responseContainer.appendChild(aiMessageDiv);

            // Scroll to bottom
            responseContainer.scrollTop = responseContainer.scrollHeight;

            try {
                const apiResponse = await fetch('api_call.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ prompt, model })
                });

                const apiData = await apiResponse.json();
                console.log('API Response:', apiData); // Debug log

                if (apiData.error) {
                    aiMessageDiv.innerHTML = `<strong>Error:</strong> ${escapeHtml(apiData.error)}`;
                    aiMessageDiv.className = 'message error-message';
                } else if (apiData.response) {
                    // Display the AI's response
                    aiMessageDiv.innerHTML = `<strong>AI (${escapeHtml(model)}):</strong> ${escapeHtml(apiData.response).replace(/\n/g, '<br>')}`;
                    aiMessageDiv.className = 'message ai-message'; // Remove loading state
                } else {
                    aiMessageDiv.innerHTML = `<strong>Error:</strong> Unexpected response format`;
                    aiMessageDiv.className = 'message error-message';
                }
            } catch (error) {
                console.error('API Error:', error);
                aiMessageDiv.innerHTML = `<strong>Error:</strong> ${escapeHtml(error.message)}`;
                aiMessageDiv.className = 'message error-message';
            } finally {
                // Reset UI state
                sendButton.disabled = false;
                sendButton.textContent = 'Send';
                promptInput.value = ''; // Clear input after sending
                responseContainer.scrollTop = responseContainer.scrollHeight;
            }
        });

        // Clear chat functionality
        document.getElementById('clearChatButton').addEventListener('click', () => {
            if (confirm('Are you sure you want to clear the chat?')) {
                fetch('api_call.php', { 
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear_chat' })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            document.getElementById('responseContainer').innerHTML = '';
                        } else {
                            alert('Failed to clear chat.');
                        }
                    })
                    .catch(() => {
                        alert('Error clearing chat.');
                    });
            }
        });

        // Handle Enter key in textarea (Shift+Enter for new line)
        document.getElementById('promptInput').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('sendButton').click();
            }
        });

        // Function to escape HTML to prevent XSS
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    </script>

<?php endif; ?>

<!-- Add this in index.php where appropriate -->
<p><a href="backroom.php">Go to The Finite Backroom</a></p>

</body>
</html>