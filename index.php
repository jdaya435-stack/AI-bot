<?php
/**
 * AI Bot Engine - API Entry Point
 * Handles webhook requests for AI responses
 * 
 * Usage:
 * POST /api/chat
 * {
 *   "user_id": "123456",
 *   "message": "Hello, how are you?"
 * }
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/app/ai_data/error.log');

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(['status' => 'ok']));
}

// Load AI engine
if (!file_exists(__DIR__ . '/ai_bot_engine.php')) {
    http_response_code(500);
    exit(json_encode(['error' => 'AI engine not found']));
}

require_once __DIR__ . '/ai_bot_engine.php';

// Create data directory if not exists
@mkdir('/app/ai_data/conversations', 0755, true);

// Determine request path
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

try {
    // Health check endpoint
    if ($path === '/' && $method === 'GET') {
        http_response_code(200);
        exit(json_encode([
            'status' => 'ok',
            'service' => 'AI Bot Engine',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s')
        ]));
    }
    
    // AI Chat endpoint
    if ($path === '/api/chat' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['user_id']) || !isset($input['message'])) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing user_id or message']));
        }
        
        $userId = $input['user_id'];
        $message = $input['message'];
        $context = $input['context'] ?? '';
        
        // Get response
        $response = getAIResponse($userId, $message, $context);
        
        http_response_code(200);
        exit(json_encode([
            'status' => 'success',
            'user_id' => $userId,
            'message' => $message,
            'response' => $response
        ]));
    }
    
    // Get conversation history endpoint
    if ($path === '/api/history' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['user_id'])) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing user_id']));
        }
        
        $userId = $input['user_id'];
        $history = getUserConversation($userId);
        
        http_response_code(200);
        exit(json_encode([
            'status' => 'success',
            'user_id' => $userId,
            'history' => $history
        ]));
    }
    
    // Clear conversation endpoint
    if ($path === '/api/clear' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['user_id'])) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing user_id']));
        }
        
        $userId = $input['user_id'];
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
        'available_endpoints' => [
            'GET /' => 'Health check',
            'POST /api/chat' => 'Send message to AI',
            'POST /api/history' => 'Get conversation history',
            'POST /api/clear' => 'Clear conversation history'
        ]
    ]));

} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]));
}

?>
