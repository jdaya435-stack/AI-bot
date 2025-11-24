<?php
/**
 * AI Bot Engine - API Entry Point
 * Handles webhook requests for AI responses
 * Production-ready with comprehensive error handling
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/app/ai_data/error.log');

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(['status' => 'ok']));
}

// Load AI engine
if (!file_exists(__DIR__ . '/ai_bot_engine.php')) {
    http_response_code(500);
    exit(json_encode(['error' => 'AI engine not found', 'status' => 'error']));
}

require_once __DIR__ . '/ai_bot_engine.php';

// Create data directory if not exists - with safe permissions
@mkdir('/app/ai_data', 0755, true);
@mkdir('/app/ai_data/conversations', 0755, true);

// Determine request path
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if (!is_string($path)) {
    $path = '/';
}

// Log all requests
error_log("[" . date('Y-m-d H:i:s') . "] $method $path");

try {
    // Health check endpoint
    if ($path === '/' && $method === 'GET') {
        $geminiKey = getenv('GOOGLE_GEMINI_API_KEY');
        $imageKey = getenv('GOOGLE_IMAGEN_API_KEY');
        http_response_code(200);
        exit(json_encode([
            'status' => 'ok',
            'service' => 'AI Bot Engine',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'api_keys' => [
                'primary_gemini' => !empty($geminiKey) ? 'SET' : 'NOT SET',
                'fallback_gemini' => !empty($imageKey) ? 'SET' : 'NOT SET'
            ]
        ], JSON_UNESCAPED_SLASHES));
    }
    
    // AI Chat endpoint
    if ($path === '/api/chat' && $method === 'POST') {
        $rawInput = file_get_contents('php://input');
        
        if (empty($rawInput)) {
            http_response_code(400);
            exit(json_encode(['error' => 'Empty request body', 'status' => 'error']));
        }
        
        $input = json_decode($rawInput, true);
        
        error_log("[CHAT] Request received: " . substr($rawInput, 0, 200));
        
        if (!is_array($input)) {
            http_response_code(400);
            exit(json_encode(['error' => 'Invalid JSON payload', 'status' => 'error']));
        }
        
        if (!isset($input['user_id']) || !isset($input['message'])) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing user_id or message', 'status' => 'error']));
        }
        
        $userId = (string)$input['user_id'];
        $message = (string)$input['message'];
        $context = isset($input['context']) ? (string)$input['context'] : '';
        
        // Validate inputs
        if (empty($userId) || strlen($userId) > 100) {
            http_response_code(400);
            exit(json_encode(['error' => 'Invalid user_id', 'status' => 'error']));
        }
        
        if (empty($message) || strlen($message) > 10000) {
            http_response_code(400);
            exit(json_encode(['error' => 'Message too short or too long', 'status' => 'error']));
        }
        
        error_log("[CHAT] Processing for user $userId");
        
        // Get response
        $response = getAIResponse($userId, $message, $context);
        
        if (!is_string($response)) {
            throw new Exception('AI Response generation failed');
        }
        
        error_log("[CHAT] Response generated: " . substr($response, 0, 100));
        
        http_response_code(200);
        exit(json_encode([
            'status' => 'success',
            'user_id' => $userId,
            'message' => $message,
            'response' => $response
        ], JSON_UNESCAPED_SLASHES));
    }
    
    // Get conversation history endpoint
    if ($path === '/api/history' && $method === 'POST') {
        $rawInput = file_get_contents('php://input');
        
        if (empty($rawInput)) {
            http_response_code(400);
            exit(json_encode(['error' => 'Empty request body', 'status' => 'error']));
        }
        
        $input = json_decode($rawInput, true);
        
        if (!is_array($input) || !isset($input['user_id'])) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing user_id', 'status' => 'error']));
        }
        
        $userId = (string)$input['user_id'];
        $history = getUserConversation($userId);
        
        if (!is_array($history)) {
            $history = [];
        }
        
        http_response_code(200);
        exit(json_encode([
            'status' => 'success',
            'user_id' => $userId,
            'history' => $history
        ], JSON_UNESCAPED_SLASHES));
    }
    
    // Clear conversation endpoint
    if ($path === '/api/clear' && $method === 'POST') {
        $rawInput = file_get_contents('php://input');
        
        if (empty($rawInput)) {
            http_response_code(400);
            exit(json_encode(['error' => 'Empty request body', 'status' => 'error']));
        }
        
        $input = json_decode($rawInput, true);
        
        if (!is_array($input) || !isset($input['user_id'])) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing user_id', 'status' => 'error']));
        }
        
        $userId = (string)$input['user_id'];
        clearUserConversation($userId);
        
        http_response_code(200);
        exit(json_encode([
            'status' => 'success',
            'message' => 'Conversation cleared'
        ]));
    }
    
    // Unknown endpoint
    http_response_code(404);
    exit(json_encode([
        'error' => 'Not found',
        'status' => 'error',
        'available_endpoints' => [
            'GET /' => 'Health check',
            'POST /api/chat' => 'Send message to AI',
            'POST /api/history' => 'Get conversation history',
            'POST /api/clear' => 'Clear conversation history'
        ]
    ]));

} catch (Exception $e) {
    error_log("EXCEPTION: " . $e->getMessage() . " at line " . $e->getLine());
    
    http_response_code(500);
    exit(json_encode([
        'error' => 'Server error',
        'status' => 'error',
        'message' => 'An internal error occurred. Please try again later.'
    ]));
} catch (Throwable $e) {
    error_log("FATAL: " . $e->getMessage());
    
    http_response_code(500);
    exit(json_encode([
        'error' => 'Server error',
        'status' => 'error',
        'message' => 'A fatal error occurred.'
    ]));
}

?>
