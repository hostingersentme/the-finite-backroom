<?php
session_start();

// Include the configuration file
require 'config.php';

// Enable error logging and optionally display errors for debugging
ini_set('display_errors', 0); // Set to 1 temporarily for debugging, then revert to 0
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/claudebackroom_debug.log');
error_reporting(E_ALL);

// Set Content-Type header
header('Content-Type: application/json');

// Start output buffering
ob_start();

// Function to send JSON response and exit
function send_json_response($data) {
    ob_clean(); // Clear the output buffer
    echo json_encode($data);
    exit();
}

// Wrap the entire script in a try-catch block for robust error handling
try {
    // Handle chat clearing action
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON input: " . json_last_error_msg());
        }

        if (isset($input['action']) && $input['action'] === 'clear_backroom') {
            $_SESSION['claudebackroom_conversation'] = [];

            send_json_response(['status' => 'success']);
        }
    }

    // Database connection using config.php credentials
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $user_id = $_SESSION['user_id'] ?? null;

    // Check if user is authenticated
    if (!$user_id) {
        throw new Exception("User not authenticated.");
    }

    // Decode the JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON input: " . json_last_error_msg());
    }

    // Extract necessary parameters
    $model_selected = trim($input['model'] ?? '');
    $prompt = trim($input['prompt'] ?? '');
    $turns = isset($input['turns']) ? intval($input['turns']) : 1;
    $system_message = trim($input['system_message'] ?? "You're one of three different AI models talking to each other, engaging in conversation with the other AIs. With the occasional human prompt every ten responses or so.");

    // Extract model settings
    $max_tokens = isset($input['max_tokens']) ? intval($input['max_tokens']) : 200;
    $temperature = isset($input['temperature']) ? floatval($input['temperature']) : 0.7;

    // Validate input
    if (empty($prompt) || $turns < 1) {
        throw new Exception("Invalid input. Prompt and positive number of turns are required.");
    }

    // Define active models
    $models = ['claude-3-5-sonnet-latest', 'gpt-4o', 'gpt-4o-mini'];
    $api_keys = [];

    // Fetch API keys for the active models
    $stmt = $conn->prepare("SELECT model, api_key FROM api_keys WHERE user_id = ? AND model IN ('claude-3-5-sonnet-latest', 'gpt-4o', 'gpt-4o-mini')");
    if (!$stmt) {
        throw new Exception("Prepare statement failed: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute statement failed: " . $stmt->error);
    }
    $stmt->bind_result($model_fetched, $api_key);

    while ($stmt->fetch()) {
        $api_keys[$model_fetched] = $api_key;
    }
    $stmt->close();

    // Ensure all active API keys are present
    $missing_keys = array_diff($models, array_keys($api_keys));
    if (!empty($missing_keys)) {
        throw new Exception("Missing API keys for models: " . implode(", ", $missing_keys));
    }

    // Initialize backroom conversation history if not set
    if (!isset($_SESSION['claudebackroom_conversation']) || empty($_SESSION['claudebackroom_conversation'])) {
        $_SESSION['claudebackroom_conversation'] = [
            [
                'role' => 'system',
                'content' => $system_message
            ]
        ];
    }

    // Append the user's initial prompt to the conversation history
    $_SESSION['claudebackroom_conversation'][] = [
        'role' => 'user',
        'content' => $prompt
    ];

    // Determine which model should respond next based on the number of assistant messages
    $assistant_count = 0;
    foreach ($_SESSION['claudebackroom_conversation'] as $msg) {
        if (isset($msg['role']) && $msg['role'] === 'assistant') {
            $assistant_count++;
        }
    }
    $current_model = $models[$assistant_count % count($models)];

    // Fetch the API key for the current model
    if (!isset($api_keys[$current_model])) {
        throw new Exception("API key for model {$current_model} is missing.");
    }
    $api_key = $api_keys[$current_model];

    // Prepare the messages array by including all prior conversation
    $messages = array_map(function($msg) {
        return [
            'role' => $msg['role'],
            'content' => $msg['content']
        ];
    }, $_SESSION['claudebackroom_conversation']);

    // For Anthropic models, extract system message
    $system = null;
    if ($current_model === 'claude-3-5-sonnet-latest') {
        if (count($messages) > 0 && $messages[0]['role'] === 'system') {
            $system = $messages[0]['content'];
            array_shift($messages); // Remove system message from messages
        }
    }

    // Call the current model
    $api_response = call_ai_model($current_model, $messages, $api_key, $max_tokens, $temperature, $system);

    if (isset($api_response['error'])) {
        throw new Exception("Error from model {$current_model}: " . $api_response['error']);
    }

    $response_text = $api_response['response'];

    // Append AI's response as an 'assistant' message
    $_SESSION['claudebackroom_conversation'][] = [
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
        throw new Exception("JSON Encoding Error: " . json_last_error_msg());
    }

    // Send the JSON response
    echo $json_response;
    exit();

} catch (Exception $e) {
    // Log the exception message
    error_log("Exception: " . $e->getMessage());

    // Send a JSON error response
    send_json_response(['error' => $e->getMessage()]);
}

/**
 * Function to call AI models based on the model type.
 */
function call_ai_model($model, $messages, $api_key, $max_tokens, $temperature, $system = null) {
    // Define API endpoints and payload structures for each model
    $api_endpoints = [
        'claude-3-5-sonnet-latest' => [
            'url' => 'https://api.anthropic.com/v1/messages',
            'headers' => [
                'Content-Type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01'
            ],
            'payload' => [
                'model' => 'claude-3-5-sonnet-latest',
                'max_tokens' => $max_tokens,
                'temperature' => $temperature,
                'messages' => $messages
            ]
        ],
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

    if (!array_key_exists($model, $api_endpoints)) {
        error_log("Unsupported model selected: $model");
        return ['error' => 'Unsupported model selected.'];
    }

    $endpoint = $api_endpoints[$model]['url'];
    $headers = $api_endpoints[$model]['headers'];
    $payload = $api_endpoints[$model]['payload'];

    // Adjust payload for Anthropic models
    if ($model === 'claude-3-5-sonnet-latest') {
        if ($system !== null) {
            $payload['system'] = $system;
        }
    }

    // Encode payload as JSON
    $json_payload = json_encode($payload);

    if ($json_payload === false) {
        $json_error = json_last_error_msg();
        error_log("JSON Encoding Error for payload: " . $json_error);
        return ['error' => 'Failed to encode JSON payload.'];
    }

    // Initialize cURL
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);

    // Execute cURL request
    $response = curl_exec($ch);

    // Log the request and response
    error_log("Request to $model API: " . $json_payload);
    error_log("Response from $model API: " . $response);

    if (curl_errno($ch)) {
        error_log("cURL error: " . curl_error($ch));
        curl_close($ch);
        return ['error' => curl_error($ch)];
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status !== 200) {
        $decoded_response = json_decode($response, true);
        $error_message = isset($decoded_response['error']['message']) ? $decoded_response['error']['message'] : 'API request failed.';
        error_log("API request failed with status code $http_status: " . json_encode($decoded_response));
        return ['error' => 'API request failed with status code ' . $http_status . '. ' . $error_message];
    }

    $decoded_response = json_decode($response, true);
    $ai_response = '';

    // Handle different response formats for Claude vs OpenAI
    if ($model === 'claude-3-5-sonnet-latest') {
        if (isset($decoded_response['content']) && is_array($decoded_response['content'])) {
            // Concatenate all text parts
            foreach ($decoded_response['content'] as $part) {
                if (isset($part['text'])) {
                    $ai_response .= $part['text'];
                }
            }
            $ai_response = trim($ai_response);
        } else {
            error_log("Claude response missing content: " . json_encode($decoded_response));
            return ['error' => "No content in Claude response."];
        }
    } else {
        if (isset($decoded_response['choices'][0]['message']['content'])) {
            $ai_response = $decoded_response['choices'][0]['message']['content'];
        } else {
            error_log("OpenAI response missing content: " . json_encode($decoded_response));
            return ['error' => "No content in OpenAI response."];
        }
    }

    return ['response' => $ai_response];
}
?>