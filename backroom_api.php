<?php
session_start();

// Include the configuration file
require 'config.php'; // Assuming config.php is in the same directory


// Disable display of errors to the frontend
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/backroom_debug.log');
error_reporting(E_ALL);

header('Content-Type: application/json');

// Start output buffering to prevent unexpected output
ob_start();

// Handle chat clearing action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (isset($input['action']) && $input['action'] === 'clear_backroom') {
        $_SESSION['backroom_conversation'] = [];
        
        // Clean the output buffer before sending the response
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit();
    }
}


// Database connection using config.php credentials
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    echo json_encode(['error' => 'Database connection failed.']);
    ob_clean();
    exit();
}

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'User not authenticated.']);
    ob_clean();
    exit();
}

// Decode the JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Determine if it's a manual submission (Removed manual input, but keeping for possible future use)
$manual = isset($input['manual']) && $input['manual'] === true;

// Extract necessary parameters
$model_selected = trim($input['model'] ?? '');
$prompt = trim($input['prompt'] ?? '');
$turns = isset($input['turns']) ? intval($input['turns']) : 1;
$system_message = trim($input['system_message'] ?? 'You are an AI talking to another AI with an initial human prompt.'); // Updated default

// Extract model settings
$max_tokens = isset($input['max_tokens']) ? intval($input['max_tokens']) : 200;
$temperature = isset($input['temperature']) ? floatval($input['temperature']) : 0.7;

if ($manual) {
    // Manual Submission Handling (Removing manual input functionality)
    // Since manual input is removed, you can choose to disable this section or keep it for future use
    echo json_encode(['error' => 'Manual submission is disabled.']);
    ob_clean();
    exit();
}

// Automated Backroom Interaction

if ($manual === false) {
    if (empty($prompt) || $turns < 1) {
        echo json_encode(['error' => 'Invalid input. Prompt and positive number of turns are required.']);
        ob_clean();
        exit();
    }

    // Define active OpenAI models
    $models = ['gpt-4o', 'gpt-4o-mini'];
    $api_keys = [];

    // Fetch API keys for the active models
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT model, api_key FROM api_keys WHERE user_id = ? AND model IN ('gpt-4o', 'gpt-4o-mini')");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($model_fetched, $api_key);

    while ($stmt->fetch()) {
        $api_keys[$model_fetched] = $api_key;
    }
    $stmt->close();

    // Ensure all active API keys are present
    $missing_keys = array_diff($models, array_keys($api_keys));
    if (!empty($missing_keys)) {
        echo json_encode(['error' => 'Missing API keys for models: ' . implode(", ", $missing_keys)]);
        ob_clean();
        exit();
    }

    // Initialize backroom conversation history if not set
    if (!isset($_SESSION['backroom_conversation']) || empty($_SESSION['backroom_conversation'])) {
        // Initialize with the system message only once
        $_SESSION['backroom_conversation'] = [
            [
                'role' => 'system',
                'content' => $system_message
            ]
        ];
    }

    // Append the user's initial prompt to the conversation history
    $_SESSION['backroom_conversation'][] = [
        'role' => 'user',
        'content' => $prompt
    ];

    // Determine which model should respond next based on the number of assistant messages
    $assistant_count = 0;
    foreach ($_SESSION['backroom_conversation'] as $msg) {
        if (isset($msg['role']) && $msg['role'] === 'assistant') {
            $assistant_count++;
        }
    }
    $current_model = $models[$assistant_count % count($models)];

    // Fetch the API key for the current model
    if (!isset($api_keys[$current_model])) {
        echo json_encode(['error' => "API key for model {$current_model} is missing."]);
        ob_clean();
        exit();
    }
    $api_key = $api_keys[$current_model];

    // Prepare the messages array by including all prior conversation
    $messages = array_map(function($msg) {
        return [
            'role' => $msg['role'],
            'content' => $msg['content']
        ];
    }, $_SESSION['backroom_conversation']);

    // Call the current model
    $api_response = call_ai_model($current_model, $messages, $api_key, $max_tokens, $temperature);

    if (isset($api_response['error'])) {
        // Log and return the error
        error_log("Error from model $current_model: " . $api_response['error']);
        echo json_encode(['error' => "Error from model $current_model: " . $api_response['error']]);
        ob_clean();
        exit();
    }

    $response_text = $api_response['response'];

    // Append AI's response as an 'assistant' message to maintain role alternation
    $_SESSION['backroom_conversation'][] = [
        'role' => 'assistant',
        'content' => $response_text,
        'model' => $current_model
    ];

    // Prepare the response to be sent back to the frontend
    $conversation = [
        [
            'model' => $current_model,
            'content' => $response_text,
            'role' => 'assistant'
        ]
    ];

    // Validate JSON encoding
    $json_response = json_encode(['conversation' => $conversation]);

    if ($json_response === false) {
        $json_error = json_last_error_msg();
        error_log("JSON Encoding Error: " . $json_error);
        echo json_encode(['error' => 'Failed to encode JSON response.']);
        ob_clean();
        exit();
    }

    // Clean the output buffer and send the response
    ob_clean();
    echo $json_response;
    exit();
}

/**
 * Function to call AI models based on the model type.
 */
function call_ai_model($model, $messages, $api_key, $max_tokens, $temperature) {
    // Define API endpoints and payload structures for each model
    $api_endpoints = [
        'gpt-4o' => [
            'url' => 'https://api.openai.com/v1/chat/completions',
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ],
            'payload' => [
                'model' => 'gpt-4o',
                'messages' => $messages,
                'max_tokens' => $max_tokens,
                'temperature' => $temperature
            ]
        ],
        'gpt-4o-mini' => [
            'url' => 'https://api.openai.com/v1/chat/completions',
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ],
            'payload' => [
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'max_tokens' => $max_tokens,
                'temperature' => $temperature
            ]
        ]
    ];

    // Check if the selected model is supported
    if (!array_key_exists($model, $api_endpoints)) {
        error_log("Unsupported model selected: $model");
        return ['error' => 'Unsupported model selected.'];
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
        curl_close($ch);
        return ['error' => curl_error($ch)];
    }

    // Get HTTP status code
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Handle non-200 responses
    if ($http_status !== 200) {
        $decoded_response = json_decode($response, true);
        $error_message = isset($decoded_response['error']['message']) ? $decoded_response['error']['message'] : 'API request failed.';
        error_log("API request failed with status code $http_status: " . json_encode($decoded_response));
        return ['error' => 'API request failed with status code ' . $http_status . '. ' . $error_message];
    }

    // Decode the response
    $decoded_response = json_decode($response, true);

    // Initialize AI response variable
    $ai_response = '';

    // Extract the AI response
    if (isset($decoded_response['choices'][0]['message']['content'])) {
        $ai_response = $decoded_response['choices'][0]['message']['content'];
    } else {
        error_log("{$model} response missing content: " . json_encode($decoded_response));
        return ['error' => "No content in {$model} response."];
    }

    // Check if the response was cut off
    if (isset($decoded_response['choices'][0]['finish_reason']) && $decoded_response['choices'][0]['finish_reason'] === 'length') {
        // Optionally, append a continuation prompt or notify the user
        $ai_response .= "\n\n*Note: The response was cut off. Please continue the conversation.*";
    }

    return ['response' => $ai_response];
}
?>