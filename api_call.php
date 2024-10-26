<?php
session_start();
header('Content-Type: application/json');

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log'); // Logs will be stored in the same directory as api_call.php
error_reporting(E_ALL);

// Include the configuration file
require 'config.php'; // Assuming config.php is in the same directory


// Handle chat clearing action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Decode JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (isset($input['action']) && $input['action'] === 'clear_chat') {
        if (isset($_SESSION['conversation'])) {
            $_SESSION['conversation'] = [];
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'failure', 'error' => 'No conversation to clear.']);
        }
        exit();
    }
}


// Database connection using config.php credentials
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'User not authenticated.']);
    exit();
}

// Decode the JSON input
$input = json_decode(file_get_contents('php://input'), true);
$prompt = trim($input['prompt'] ?? '');
$model = trim($input['model'] ?? '');

if (empty($prompt) || empty($model)) {
    echo json_encode(['error' => 'Prompt and model are required.']);
    exit();
}

// Fetch the API key for the user and selected model
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT api_key FROM api_keys WHERE user_id = ? AND model = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    echo json_encode(['error' => 'Internal server error.']);
    exit();
}
$stmt->bind_param("is", $user_id, $model);
$stmt->execute();
$stmt->bind_result($api_key);
if (!$stmt->fetch()) {
    echo json_encode(['error' => 'API key not found for the selected model.']);
    exit();
}
$stmt->close();

// Initialize conversation history if not set
if (!isset($_SESSION['conversation'])) {
    $_SESSION['conversation'] = [];
}

// Handle system prompt
$system_prompt_enabled = $_SESSION['system_prompt_enabled'] ?? false;
$system_prompt = $_SESSION['system_prompt'] ?? 'You are a helpful AI assistant.';

// Append user message to conversation history
$_SESSION['conversation'][] = [
    'role' => 'user',
    'content' => $prompt
];

// Prepare prompt for AI
$full_prompt = '';

// Add system prompt if enabled
if ($system_prompt_enabled && !empty($system_prompt)) {
    $full_prompt .= $system_prompt . "\n\n";
}

// Build the conversation history for the prompt
foreach ($_SESSION['conversation'] as $message) {
    if ($message['role'] === 'user') {
        $full_prompt .= "Human: " . $message['content'] . "\n";
    } elseif ($message['role'] === 'assistant') {
        $full_prompt .= "Assistant: " . $message['content'] . "\n";
    }
}

// Append the AI's turn
$full_prompt .= "Assistant: ";

// Define API endpoints and payload structures for each model
$api_endpoints = [
    'gpt-4' => [
        'url' => 'https://api.openai.com/v1/chat/completions',
        'headers' => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        'payload' => [
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt_enabled ? $system_prompt : "You are a helpful AI assistant."],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 150,
            'temperature' => 0.7
        ]
    ],
    'gpt-4o' => [ // New Model
        'url' => 'https://api.openai.com/v1/chat/completions',
        'headers' => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        'payload' => [
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt_enabled ? $system_prompt : "You are a helpful AI assistant."],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 150,
            'temperature' => 0.7
        ]
    ],
    'gpt-4o-mini' => [ // New Model
        'url' => 'https://api.openai.com/v1/chat/completions',
        'headers' => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        'payload' => [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt_enabled ? $system_prompt : "You are a helpful AI assistant."],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 150,
            'temperature' => 0.7
        ]
    ],
    'claude-3-5-sonnet-latest' => [
        'url' => 'https://api.anthropic.com/v1/messages',
        'headers' => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01'
        ],
        'payload' => [
            'model' => 'claude-3-sonnet-20240229',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 150,
            'temperature' => 0.7
        ]
    ],
    'claude-3-opus-20240229' => [
        'url' => 'https://api.anthropic.com/v1/messages',
        'headers' => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01'
        ],
        'payload' => [
            'model' => 'claude-3-opus-20240229',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 150,
            'temperature' => 0.7
        ]
    ],
    'claude-3-haiku-20240229' => [
        'url' => 'https://api.anthropic.com/v1/messages',
        'headers' => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01'
        ],
        'payload' => [
            'model' => 'claude-3-haiku-20240229',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 150,
            'temperature' => 0.7
        ]
    ]
];

// Check if the selected model is supported
if (!array_key_exists($model, $api_endpoints)) {
    error_log("Unsupported model selected: $model");
    echo json_encode(['error' => 'Unsupported model selected.']);
    exit();
}

// Prepare the API request
$endpoint = $api_endpoints[$model]['url'];
$headers = $api_endpoints[$model]['headers'];
$payload = $api_endpoints[$model]['payload'];

// Initialize cURL
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

// Execute the API request
$response = curl_exec($ch);

// Log the request and response for debugging
error_log("Request to $model API: " . json_encode($payload));
error_log("Response from $model API: " . $response);

// Handle cURL errors
if (curl_errno($ch)) {
    error_log("cURL error: " . curl_error($ch));
    echo json_encode(['error' => curl_error($ch)]);
    curl_close($ch);
    exit();
}

// Get HTTP status code
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Handle non-200 responses
if ($http_status !== 200) {
    $decoded_response = json_decode($response, true);
    $error_message = $decoded_response['error']['message'] ?? 'API request failed.';
    error_log("API request failed with status code $http_status: " . json_encode($decoded_response));
    echo json_encode(['error' => 'API request failed with status code ' . $http_status . '.', 'details' => $error_message]);
    exit();
}

// Decode the response
$decoded_response = json_decode($response, true);

// Initialize AI response variable
$ai_response = '';

// Format the response based on the model
if ($model === 'gpt-4' || $model === 'gpt-4o' || $model === 'gpt-4o-mini') {
    if (isset($decoded_response['choices'][0]['message']['content'])) {
        $ai_response = $decoded_response['choices'][0]['message']['content'];
    } else {
        error_log("GPT-4/$model response missing content: " . json_encode($decoded_response));
        echo json_encode([
            'error' => 'No content in response',
            'model' => $model,
            'status' => 'error'
        ]);
        exit();
    }
} elseif (strpos($model, 'claude') !== false) {
    // For Claude models using Messages API
    if (isset($decoded_response['content'][0]['text'])) {
        $ai_response = $decoded_response['content'][0]['text'];
    } else {
        error_log("Claude response missing content: " . json_encode($decoded_response));
        echo json_encode([
            'error' => 'No content in response',
            'model' => $model,
            'status' => 'error'
        ]);
        exit();
    }

} else {
    // Unsupported model type
    error_log("Unsupported model type: $model");
    echo json_encode([
        'error' => 'Unsupported model type.',
        'model' => $model,
        'status' => 'error'
    ]);
    exit();
}

// Append AI response to conversation history
$_SESSION['conversation'][] = [
    'role' => 'assistant',
    'content' => $ai_response,
    'model' => $model
];

// Return the AI response
echo json_encode([
    'response' => $ai_response,
    'model' => $model,
    'status' => 'success'
]);
?>