<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the configuration file
require 'config.php'; // Assuming config.php is in the same directory

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Database connection using config.php credentials
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user_id'];

// Define active OpenAI models
$models = ['gpt-4o', 'gpt-4o-mini'];
$api_keys = [];

// Fetch API keys for the active models
$stmt = $conn->prepare("SELECT model, api_key FROM api_keys WHERE user_id = ? AND model IN ('gpt-4o', 'gpt-4o-mini')");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($model, $api_key);

while ($stmt->fetch()) {
    $api_keys[$model] = $api_key;
}
$stmt->close();

// Ensure all active API keys are present
$missing_keys = array_diff($models, array_keys($api_keys));
if (!empty($missing_keys)) {
    die("Missing API keys for models: " . implode(", ", $missing_keys));
}

// Initialize conversation history if not set
if (!isset($_SESSION['backroom_conversation'])) {
    $_SESSION['backroom_conversation'] = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>The Finite Backroom</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            display: flex;
            flex-direction: column;
            height: 100vh;
            background-color: #fefbdc; /* Subdued background color */
            
        }
        .container { 
            display: flex; 
            flex-direction: column; 
            flex: 1; 
        }
        #backroomContainer { 
            border: 1px solid #ccc; 
            padding: 10px; 
            height: 50vh; /* Adjusted height for better visibility */
            overflow-y: auto; 
            margin-top: 10px; 
            background-color: #ffffff;
            word-wrap: break-word; /* Ensure text wraps */
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        button { 
            padding: 10px; 
            margin: 5px 0; 
            cursor: pointer;
            background-color: #007BFF; /* Modern button color */
            border: none;
            border-radius: 5px;
            color: #ffffff;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #0056b3;
        }
        textarea { 
            width: 100%; 
            height: 80px; 
            padding: 10px;
            box-sizing: border-box;
            resize: vertical;
            border: 2px solid #ccc; /* Modern border */
            border-radius: 5px;
            background-color: #f9f9f9;
            font-family: Arial, sans-serif;
            color: #333333;
        }
        label { 
            display: block; 
            margin-top: 10px; 
            font-weight: bold;
            color: #333333;
        }
        select { 
            width: 100%; 
            padding: 8px; 
            margin-top: 5px; 
            box-sizing: border-box;
            border: 2px solid #ccc; /* Modern border */
            border-radius: 5px;
            background-color: #ffffff;
            color: #333333;
            font-family: Arial, sans-serif;
        }
        .message { 
            margin: 10px 0; 
            padding: 10px; 
            border-bottom: 1px solid #eee; 
            border-radius: 5px;
            background-color: #f1f1f1;
            font-family: Arial, sans-serif;
            color: #333333;
        }
        .ai-message { 
            background-color: #e7f3ff; 
        }
        .error-message { 
            color: red; 
            background-color: #ffe6e6; 
            padding: 10px; 
            margin: 10px 0; 
            border: 1px solid red;
            border-radius: 5px;
        }
        .loading { 
            opacity: 0.6; 
            pointer-events: none;
        }
        /* Responsive Design */
        @media (max-width: 600px) {
            #backroomContainer { 
                height: 40vh; 
            }
            textarea { 
                height: 60px; 
            }
        }
        /* Additional Styles for Logout and Back Link */
        .top-menu {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #ffffff;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .top-menu a {
            color: #007BFF;
            text-decoration: none;
            font-weight: bold;
            margin-right: 10px;
        }
        .top-menu a:hover {
            text-decoration: underline;
        }
        /* New Styles for Compact Model Settings */
        .model-settings {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 15px;
        }
        .model-settings .setting-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .model-settings label {
            font-weight: normal;
            white-space: nowrap;
        }
        .model-settings select {
            width: 80px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="top-menu">
        <div>
            <a href="index.php">Home</a>
            <a href="backroom.php">Backroom</a>
        </div>
        <div>
            <button id="logoutButton">Logout</button>
        </div>
    </div>

    <h1 style="color: #333333;">The Finite Backroom</h1>

    <!-- System Message Input -->
    <div>
        <label for="systemMessage">System Message:</label>
        <textarea id="systemMessage" placeholder="Enter system message here...">You are an AI talking to another AI with an initial human prompt.</textarea>
    </div>

    <!-- Automated Backroom Interaction -->
    <div>
        <h2 style="color: #333333;">Automated Backroom Interaction</h2>
        <label for="initialPrompt">Enter Initial Prompt:</label>
        <textarea id="initialPrompt" placeholder="Enter your initial prompt"></textarea>
        
        <label for="turnsSelect">Select Number of Turns:</label>
        <select id="turnsSelect">
            <?php for ($i = 1; $i <= 10; $i++): ?>
                <option value="<?php echo $i; ?>"><?php echo $i; ?> Turn<?php echo $i > 1 ? 's' : ''; ?></option>
            <?php endfor; ?>
        </select>
        
        <button id="startBackroomButton">Start Backroom</button>
        <button id="clearBackroomButton">Clear Backroom</button>
    </div>

    <!-- Model Settings: Number of Tokens and Temperature -->
    <div>
        <h2 style="color: #333333;">Model Settings</h2>
        <?php foreach ($models as $model): ?>
            <div style="margin-bottom: 10px;">
                <h3 style="color: #333333;"><?php echo strtoupper($model); ?> Settings</h3>
                
                <div class="model-settings">
                    <div class="setting-group">
                        <label for="max_tokens_<?php echo $model; ?>">Max Tokens:</label>
                        <select id="max_tokens_<?php echo $model; ?>">
                            <?php for ($i = 100; $i <= 600; $i += 100): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($i === 200) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="setting-group">
                        <label for="temperature_<?php echo $model; ?>">Temperature:</label>
                        <select id="temperature_<?php echo $model; ?>">
                            <option value="0.3">0.3</option>
                            <option value="0.5">0.5</option>
                            <option value="0.7" selected>0.7</option>
                            <option value="1.0">1.0</option>
                        </select>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div id="backroomContainer">
        <?php
        // Display existing backroom conversation history
        foreach ($_SESSION['backroom_conversation'] as $msg) {
            if ($msg['role'] === 'system') {
                // Optionally, display the system message or keep it hidden
                // echo '<div class="message"><em>System:</em> ' . nl2br(htmlspecialchars($msg['content'])) . '</div>';
                continue; // Skip displaying system messages
            } elseif ($msg['role'] === 'user') {
                echo '<div class="message"><strong>User:</strong> ' . nl2br(htmlspecialchars($msg['content'])) . '</div>';
            } elseif ($msg['role'] === 'assistant') {
                // Display AI message with model identification
                $model = htmlspecialchars($msg['model'] ?? 'Unknown');
                echo '<div class="message ai-message"><strong>AI (' . $model . '):</strong> ' . nl2br(htmlspecialchars($msg['content'])) . '</div>';
            }
        }
        ?>
    </div>
</div>

<script>
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

// Logout Functionality
document.getElementById('logoutButton').addEventListener('click', async () => {
    try {
        const response = await fetch('logout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'logout' })
        });

        const data = await response.json();

        if (data.status === 'success') {
            window.location.href = 'index.php';
        } else {
            alert('Failed to logout.');
        }
    } catch {
        alert('Error logging out.');
    }
});

// Automated Backroom Interaction
document.getElementById('startBackroomButton').addEventListener('click', async () => {
    let initialPrompt = document.getElementById('initialPrompt').value.trim();
    const turns = parseInt(document.getElementById('turnsSelect').value);
    const systemMessage = document.getElementById('systemMessage').value.trim();
    const backroomContainer = document.getElementById('backroomContainer');

    if (!initialPrompt) {
        alert('Please enter an initial prompt.');
        return;
    }

    // Disable buttons to prevent multiple submissions
    document.getElementById('startBackroomButton').disabled = true;
    document.getElementById('clearBackroomButton').disabled = true;
    document.getElementById('startBackroomButton').textContent = 'Processing...';
    document.getElementById('startBackroomButton').classList.add('loading');

    // Append system message if it's the first interaction
    if (backroomContainer.innerHTML.trim() === '') {
        const systemMessageDiv = document.createElement('div');
        systemMessageDiv.className = 'message';
        systemMessageDiv.innerHTML = `<em>System:</em> ${escapeHtml(systemMessage)}`;
        backroomContainer.appendChild(systemMessageDiv);
    }

    // Append user's initial prompt to the conversation container
    const initialMessageDiv = document.createElement('div');
    initialMessageDiv.className = 'message';
    initialMessageDiv.innerHTML = `<strong>User:</strong> ${escapeHtml(initialPrompt)}`;
    backroomContainer.appendChild(initialMessageDiv);

    // Scroll to bottom
    backroomContainer.scrollTop = backroomContainer.scrollHeight;

    try {
        for (let i = 0; i < turns; i++) {
            // Determine the current model based on the number of assistant messages
            let assistantCount = 0;
            const messages = Array.from(backroomContainer.getElementsByClassName('message'));
            messages.forEach(msg => {
                if (msg.innerHTML.includes('<strong>AI')) {
                    assistantCount++;
                }
            });
            const current_model = '<?php echo $models[0]; ?>'; // Default model if PHP cannot determine

            // Fetch model settings
            const model = i % <?php echo count($models); ?> === 0 ? '<?php echo $models[0]; ?>' : '<?php echo $models[1]; ?>';
            const maxTokens = document.getElementById(`max_tokens_${model}`).value;
            const temperature = document.getElementById(`temperature_${model}`).value;

            const response = await fetch('backroom_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    prompt: initialPrompt, 
                    turns: 1, // Process one turn at a time
                    system_message: systemMessage,
                    max_tokens: maxTokens,
                    temperature: temperature,
                    model: model
                })
            });

            const text = await response.text();
            console.log('Raw Response:', text);

            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('Invalid JSON response from server.');
            }

            if (data.error) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'message error-message';
                errorDiv.innerHTML = `<strong>Error:</strong> ${escapeHtml(data.error)}`;
                backroomContainer.appendChild(errorDiv);
                break; // Stop further processing on error
            } else if (data.conversation) {
                data.conversation.forEach(msg => {
                    const msgDiv = document.createElement('div');
                    msgDiv.className = 'message ai-message';
                    // Include model identification
                    msgDiv.innerHTML = `<strong>AI (${escapeHtml(msg.model)}):</strong> ${escapeHtml(msg.content).replace(/\n/g, '<br>')}`;
                    backroomContainer.appendChild(msgDiv);
                });
            }

            // Update the prompt for the next turn
            if (data.conversation && data.conversation.length > 0) {
                initialPrompt = data.conversation[data.conversation.length - 1].content;
            }

            // Scroll to bottom
            backroomContainer.scrollTop = backroomContainer.scrollHeight;
        }

    } catch (error) {
        console.error('Backroom API Error:', error);
        const errorDiv = document.createElement('div');
        errorDiv.className = 'message error-message';
        errorDiv.innerHTML = `<strong>Error:</strong> ${escapeHtml(error.message)}`;
        backroomContainer.appendChild(errorDiv);
    } finally {
        // Re-enable buttons
        document.getElementById('startBackroomButton').disabled = false;
        document.getElementById('clearBackroomButton').disabled = false;
        document.getElementById('startBackroomButton').textContent = 'Start Backroom';
        document.getElementById('startBackroomButton').classList.remove('loading');
        document.getElementById('initialPrompt').value = ''; // Clear input after sending
        backroomContainer.scrollTop = backroomContainer.scrollHeight;
    }
});

// Clear backroom conversation
document.getElementById('clearBackroomButton').addEventListener('click', async () => {
    if (confirm('Are you sure you want to clear the backroom conversation?')) {
        try {
            const response = await fetch('backroom_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clear_backroom' })
            });

            const data = await response.json();

            if (data.status === 'success') {
                document.getElementById('backroomContainer').innerHTML = '';
            } else {
                alert('Failed to clear backroom conversation.');
            }
        } catch {
            alert('Error clearing backroom conversation.');
        }
    }
});
</script>

</body>
</html>