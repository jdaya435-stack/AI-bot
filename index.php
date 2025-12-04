<?php
/**
 * AI Bot Engine - Telegram Webhook + API Entry Point
 * Full-featured AI assistant with advanced capabilities
 * Production-ready with comprehensive error handling
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/bot_error.log');

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

// Load AI engines and features
if (!file_exists(__DIR__ . '/ai_bot_engine.php') || !file_exists(__DIR__ . '/ai_features.php')) {
    http_response_code(500);
    exit(json_encode(['error' => 'Engine files not found', 'status' => 'error']));
}

require_once __DIR__ . '/ai_bot_engine.php';
require_once __DIR__ . '/ai_features.php';

// Get environment variables
$TELEGRAM_BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN');
$GEMINI_API_KEY = getenv('GOOGLE_GEMINI_API_KEY');
$GOOGLE_IMAGEN_API_KEY = getenv('GOOGLE_IMAGEN_API_KEY');

// Initialize data directories
$dataDir = __DIR__ . '/ai_data';
foreach (['conversations', 'stats', 'users', 'personality'] as $dir) {
    @mkdir("$dataDir/$dir", 0755, true);
}

// ============================================================================
// TELEGRAM API FUNCTIONS
// ============================================================================

function sendChatAction($chatId, $action, $botToken) {
    if (empty($botToken)) return false;
    
    $validActions = ['typing', 'upload_photo', 'record_video', 'upload_video', 'record_audio', 'upload_audio', 'upload_document', 'find_location'];
    if (!in_array($action, $validActions)) $action = 'typing';
    
    $url = "https://api.telegram.org/bot{$botToken}/sendChatAction";
    $data = ['chat_id' => (int)$chatId, 'action' => $action];
    
    // Retry with backoff for rate limiting
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return true;
        } elseif ($httpCode === 429 && $attempt < 2) {
            usleep(500000); // Wait 0.5s before retry
            continue;
        }
    }
    
    return false;
}

function sendTelegramMessage($chatId, $text, $botToken, $retries = 3, $retryDelay = 1000000) {
    if (empty($botToken)) {
        error_log("❌ Telegram Bot Token not set");
        return false;
    }
    
    $maxLength = 4096;
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength - 3) . '...';
        error_log("[TELEGRAM] Message truncated");
    }
    
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => (int)$chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    // Retry logic with exponential backoff for rate limiting
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            error_log("[TELEGRAM] Message sent - HTTP 200 ✅");
            return true;
        } elseif ($httpCode === 429) {
            // Rate limited - wait and retry
            if ($attempt < $retries) {
                error_log("[TELEGRAM] Rate limited (429) - Retry $attempt/$retries in " . ($retryDelay / 1000000) . "s");
                usleep($retryDelay);
                $retryDelay *= 2; // Exponential backoff
                continue;
            }
        }
        
        error_log("[TELEGRAM] Message failed - HTTP $httpCode (Attempt $attempt/$retries)");
        
        // Don't retry on other errors
        if ($httpCode !== 429) {
            break;
        }
    }
    
    return false;
}

function setTelegramWebhook($botToken, $webhookUrl) {
    if (empty($botToken)) {
        error_log("❌ Telegram Bot Token not set");
        return false;
    }
    
    $url = "https://api.telegram.org/bot{$botToken}/setWebhook";
    $data = [
        'url' => $webhookUrl,
        'allowed_updates' => ['message', 'callback_query']
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("[WEBHOOK] Setup response: HTTP $httpCode");
    return $httpCode === 200;
}

function getTelegramWebhookStatus($botToken) {
    if (empty($botToken)) return null;
    
    $url = "https://api.telegram.org/bot{$botToken}/getWebhookInfo";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 200) ? json_decode($response, true) : null;
}

// ============================================================================
// GROUP CHAT SUPPORT FUNCTIONS
// ============================================================================

function isGroupChat($message) {
    $chatType = $message['chat']['type'] ?? '';
    return in_array($chatType, ['group', 'supergroup']);
}

function isBotMentioned($text) {
    if (empty($text)) return false;
    // Check for @ai or /ai mention
    return (preg_match('/@ai\b/i', $text) > 0) || (preg_match('/^\/ai\b/i', $text) > 0);
}

function getMessageHistory($message, $botToken) {
    $history = '';
    
    // Check for reply_to_message
    if (isset($message['reply_to_message'])) {
        $reply = $message['reply_to_message'];
        $replyUser = $reply['from']['first_name'] ?? 'User';
        $replyText = $reply['text'] ?? '[No text]';
        $replyText = substr($replyText, 0, 200); // Limit length
        $history = "📌 <b>Previous message from $replyUser:</b>\n" . htmlspecialchars($replyText) . "\n\n";
    }
    
    return $history;
}

function shouldProcessGroupMessage($message, $botToken) {
    if (!isGroupChat($message)) {
        return true; // Process all private messages
    }
    
    $text = $message['text'] ?? '';
    
    // Check if bot is mentioned or it's a reply to a previous bot message
    if (isBotMentioned($text)) {
        return true;
    }
    
    if (isset($message['reply_to_message'])) {
        $reply = $message['reply_to_message'];
        $fromUser = $reply['from'] ?? [];
        // If replying to a bot message, process it
        if ($fromUser['is_bot'] ?? false) {
            return true;
        }
    }
    
    return false;
}

// ============================================================================
// PERFORMANCE CACHE (In-memory for this request)
// ============================================================================

class RequestCache {
    private static $cache = [];
    
    public static function get($key) {
        return self::$cache[$key] ?? null;
    }
    
    public static function set($key, $value) {
        self::$cache[$key] = $value;
    }
    
    public static function has($key) {
        return isset(self::$cache[$key]);
    }
}

// ============================================================================
// REQUEST ROUTING
// ============================================================================

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if (!is_string($path)) $path = '/';

error_log("[" . date('Y-m-d H:i:s') . "] $method $path");

try {
    // Thinking animation endpoint
    if ($path === '/thinking' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        exit(file_get_contents(__DIR__ . '/thinking_animation.html'));
    }
    
    // Health check endpoint - Serve stylish preview page
    if ($path === '/' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        exit(file_get_contents(__DIR__ . '/preview.html'));
    }
    
    // Admin dashboard endpoint
    if ($path === '/admin' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        exit(file_get_contents(__DIR__ . '/admin.html'));
    }
    
    // Advanced admin dashboard endpoint
    if ($path === '/admin_dashboard' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        exit(file_get_contents(__DIR__ . '/admin_dashboard.html'));
    }
    
    // Telegram Webhook Endpoint
    if ($path === '/webhook' && $method === 'POST') {
        $rawInput = file_get_contents('php://input');
        error_log("[WEBHOOK] Received: " . substr($rawInput, 0, 300));
        
        $update = json_decode($rawInput, true);
        
        if (!is_array($update)) {
            error_log("❌ Invalid JSON from Telegram");
            http_response_code(200);
            exit(json_encode(['status' => 'error']));
        }
        
        // Handle message
        if (isset($update['message'])) {
            $message = $update['message'];
            $chatId = (int)($message['chat']['id'] ?? 0);
            $userId = (int)($message['from']['id'] ?? 0);
            $text = $message['text'] ?? '';
            
            if (!$chatId || !$userId) {
                error_log("❌ Invalid chat or user ID");
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            error_log("[WEBHOOK] Message from user $userId in chat $chatId");
            
            // GROUP CHAT CHECK: Skip if group message without mention
            $isGroup = isGroupChat($message);
            if ($isGroup && !shouldProcessGroupMessage($message, $TELEGRAM_BOT_TOKEN)) {
                error_log("[GROUP] Ignoring group message (no mention)");
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            if ($isGroup) {
                error_log("[GROUP] Processing group message with mention");
            }
            
            // RATE LIMITING (lightweight check, logged asynchronously)
            // Only warn if severely exceeded (allows burst usage)
            $userRateLimits = @json_decode(@file_get_contents(__DIR__ . '/ai_data/users/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json'), true) ?? [];
            $recentRequests = array_filter($userRateLimits['requests'] ?? [], function($t) { return (time() - $t) < 60; });
            
            if (count($recentRequests) > 100) { // Very lenient - allow bursts
                sendTelegramMessage($chatId, "⏱️ Too many requests. Please wait a moment.", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Handle /start command
            if ($text === '/start') {
                $startMessage = "👋 Welcome to AI Bot!\n\nFeatures:\n🧠 Image Analysis\n🎙️ Voice Messages\n💬 Smart Context\n🎭 Custom Personality\n\nUse /help for more commands.";
                sendTelegramMessage($chatId, $startMessage, $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Handle /help command
            if ($text === '/help') {
                $helpMessage = "ℹ️ <b>Commands:</b>\n\n/start - Welcome\n/ai - Chat with AI\n/personality casual - Set casual tone\n/personality professional - Set formal tone\n/personality humorous - Be funny\n/personality technical - Use tech terms\n/personality simple - Explain simply\n/clear - Clear history\n\n<b>Group Chats:</b> Use @ai or /ai to mention the bot.";
                sendTelegramMessage($chatId, $helpMessage, $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Handle /ai command
            if ($text === '/ai' || preg_match('/^\/ai\s+/i', $text)) {
                $aiText = preg_replace('/^\/ai\s+/i', '', $text);
                $aiText = trim($aiText);
                if (!empty($aiText)) {
                    // Process as if it were a regular message
                    $text = $aiText;
                } else {
                    sendTelegramMessage($chatId, "💬 Please provide a message after /ai command.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
            }
            
            // Handle /clear command
            if ($text === '/clear') {
                clearUserConversation($userId);
                sendTelegramMessage($chatId, "✅ Conversation cleared!", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Handle /personality command
            if (strpos($text, '/personality') === 0) {
                $parts = explode(' ', trim($text));
                $tone = isset($parts[1]) ? strtolower($parts[1]) : 'professional';
                $validTones = ['casual', 'professional', 'humorous', 'technical', 'simple'];
                
                if (in_array($tone, $validTones)) {
                    setUserPersonality($userId, $tone, 'balanced');
                    sendTelegramMessage($chatId, "🎭 Personality set to: $tone", $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "❌ Invalid personality. Options: " . implode(', ', $validTones), $TELEGRAM_BOT_TOKEN);
                }
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Handle empty messages
            if (empty($text) && !isset($message['photo'])) {
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // PROCESS IMAGE if present
            if (isset($message['photo'])) {
                sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
                
                // Lock file to prevent duplicate processing
                $lockFile = sys_get_temp_dir() . '/img_lock_' . $chatId . '.lock';
                $resultFile = sys_get_temp_dir() . '/img_result_' . $chatId . '.txt';
                
                // Check if already processing
                if (file_exists($lockFile)) {
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                // Create lock file
                @file_put_contents($lockFile, time());
                
                // Send image analysis message with animation
                $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";
                $data = json_encode([
                    'chat_id' => (int)$chatId,
                    'text' => "📸 Analyzing image●",
                    'parse_mode' => 'HTML'
                ]);
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => $data,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $msgData = json_decode($response, true);
                $imageAnimMsgId = $msgData['result']['message_id'] ?? null;
                
                if (!$imageAnimMsgId) {
                    @unlink($lockFile);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                // Download image file
                $photo = end($message['photo']);
                $fileData = downloadFile($photo['file_id'], $TELEGRAM_BOT_TOKEN);
                
                if (!$fileData) {
                    sendTelegramMessage($chatId, "❌ Failed to download image", $TELEGRAM_BOT_TOKEN);
                    @unlink($lockFile);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                // Prepare image data for background processing
                $imagePrompt = $text ?: "Analyze this image in detail";
                $imageBase64 = base64_encode($fileData);
                $imageDataJson = base64_encode(json_encode(['prompt' => $imagePrompt]));
                
                // Clear any previous result
                @unlink($resultFile);
                
                // Start image processing in background
                @proc_open('php ' . __DIR__ . '/process_image_async.php ' . 
                    escapeshellarg($imageDataJson) . ' ' . 
                    escapeshellarg($imageBase64) . ' ' . 
                    escapeshellarg($chatId) . ' ' . 
                    escapeshellarg($userId) . ' ' .
                    escapeshellarg($lockFile) . ' > /dev/null 2>&1', [], $pipes);
                
                // Animate continuously until result is ready
                $dots = ['●', '●●', '●●●'];
                $startTime = time();
                $dotIndex = 0;
                $response = null;
                $animCheckCount = 0;
                
                while ((time() - $startTime) < 45) {
                    // Check result file every iteration
                    if (file_exists($resultFile)) {
                        $resultData = @json_decode(file_get_contents($resultFile), true);
                        if ($resultData && isset($resultData['response'])) {
                            $response = $resultData['response'];
                            error_log("[IMAGE] Analysis complete after " . (time() - $startTime) . "s");
                            break;
                        }
                    }
                    
                    // Update animation every 0.5s
                    $animText = "📸 Analyzing image" . $dots[$dotIndex];
                    $editUrl = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/editMessageText";
                    $editData = json_encode([
                        'chat_id' => (int)$chatId,
                        'message_id' => (int)$imageAnimMsgId,
                        'text' => $animText,
                        'parse_mode' => 'HTML'
                    ]);
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $editUrl,
                        CURLOPT_POST => 1,
                        CURLOPT_POSTFIELDS => $editData,
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 2,
                        CURLOPT_SSL_VERIFYPEER => true
                    ]);
                    
                    curl_exec($ch);
                    curl_close($ch);
                    
                    $dotIndex = ($dotIndex + 1) % 3;
                    usleep(500000); // 0.5s
                }
                
                // Clean up result file
                @unlink($resultFile);
                
                // Send final response
                $finalResponse = "🎨 <b>✨ Image Analysis ✨</b>\n" . str_repeat("━", 28) . "\n\n" . 
                                "📸 " . ($response ?: "Sorry, couldn't analyze.") . "\n\n" . 
                                str_repeat("━", 28) . "\n✓ <i>Analysis complete</i>";
                sendStreamedMessage($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                
                // Log asynchronously
                @file_put_contents(__DIR__ . '/ai_data/bg_queue.log', json_encode(['user_id' => $userId, 'timestamp' => time(), 'user_message' => "[IMAGE]", 'ai_response' => $finalResponse, 'input_len' => 50, 'output_len' => strlen($finalResponse)]) . "\n", FILE_APPEND | LOCK_EX);
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // PROCESS TEXT MESSAGE
            if (!empty($text)) {
                error_log("[WEBHOOK] Processing text message");
                
                // Extract message history if this is a reply
                $messageHistory = getMessageHistory($message, $TELEGRAM_BOT_TOKEN);
                
                // Remove bot mention from text for processing
                $cleanText = preg_replace('/@ai\s*/i', '', $text);
                $cleanText = preg_replace('/^\/ai\s*/i', '', $cleanText);
                $cleanText = trim($cleanText);
                
                // If cleanText is empty after removing mention, use original
                if (empty($cleanText)) {
                    $cleanText = $text;
                }
                
                sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
                usleep(200000); // 0.2s delay after chat action to avoid rate limiting
                
                // Send animated analyzing message in background (non-blocking)
                $animFile = sys_get_temp_dir() . '/anim_' . $chatId . '.txt';
                @proc_open('php ' . __DIR__ . '/send_animation.php ' . escapeshellarg($chatId) . ' ' . escapeshellarg($TELEGRAM_BOT_TOKEN) . ' ' . escapeshellarg($animFile) . ' > /dev/null 2>&1', [], $pipes);
                
                // Get personality only (no context - saves 500ms)
                $personality = getUserPersonality($userId);
                $personalityPrompt = getPersonalityPrompt($personality);
                
                // ===== GET AI RESPONSE (ONLY BLOCKING OPERATION) =====
                // No context loading - just personality for speed
                $response = getAIResponse($userId, $text, $personalityPrompt);
                
                if (!is_string($response)) {
                    $response = "Sorry, I encountered an issue. Please try again.";
                }
                
                // Send response immediately with enhanced styling
                $finalResponse = "💡 <b>✨ AI Response ✨</b>\n" . str_repeat("━", 28) . "\n\n" . 
                                "📝 " . $response . "\n\n" . 
                                str_repeat("━", 28) . "\n✓ <i>Response complete</i>";
                sendStreamedMessage($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                
                // Log asynchronously (after response sent)
                $logEntry = json_encode([
                    'user_id' => $userId,
                    'timestamp' => time(),
                    'user_message' => $text,
                    'ai_response' => $response,
                    'input_len' => strlen($text),
                    'output_len' => strlen($response)
                ]) . "\n";
                
                @file_put_contents(__DIR__ . '/ai_data/bg_queue.log', $logEntry, FILE_APPEND | LOCK_EX);
                if (function_exists('proc_open')) {
                    @proc_open('php ' . __DIR__ . '/bg_processor.php > /dev/null 2>&1', [], $pipes);
                }
            }
        }
        
        http_response_code(200);
        exit(json_encode(['status' => 'ok']));
    }
    
    // Setup webhook endpoint
    if ($path === '/webhook/setup' && $method === 'GET') {
        $domain = getenv('REPLIT_DOMAINS');
        if (empty($domain)) {
            http_response_code(400);
            exit(json_encode(['error' => 'Domain not available', 'status' => 'error']));
        }
        
        $webhookUrl = "https://{$domain}/webhook";
        error_log("[SETUP] Setting webhook to: $webhookUrl");
        
        $success = setTelegramWebhook($TELEGRAM_BOT_TOKEN, $webhookUrl);
        
        http_response_code($success ? 200 : 500);
        exit(json_encode([
            'status' => $success ? 'success' : 'error',
            'webhook_url' => $webhookUrl
        ]));
    }
    
    // Webhook status endpoint
    if ($path === '/webhook/status' && $method === 'GET') {
        $webhookInfo = getTelegramWebhookStatus($TELEGRAM_BOT_TOKEN);
        
        if (!$webhookInfo) {
            http_response_code(500);
            exit(json_encode(['error' => 'Failed to get status']));
        }
        
        http_response_code(200);
        exit(json_encode(['status' => 'success', 'webhook_info' => $webhookInfo['result'] ?? []]));
    }
    
    // AI Chat endpoint (testing)
    if ($path === '/api/chat' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($input) || !isset($input['user_id'], $input['message'])) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing user_id or message']));
        }
        
        $userId = (string)$input['user_id'];
        $message = (string)$input['message'];
        
        if (empty($userId) || empty($message)) {
            http_response_code(400);
            exit(json_encode(['error' => 'Invalid input']));
        }
        
        $personality = getUserPersonality($userId);
        $personalityPrompt = getPersonalityPrompt($personality);
        $response = getAIResponse($userId, $message, $personalityPrompt);
        
        recordUserStats($userId, strlen($message), strlen($response), strlen($response) / 4);
        
        http_response_code(200);
        exit(json_encode(['status' => 'success', 'response' => $response]));
    }
    
    // Get statistics
    if ($path === '/api/stats' && $method === 'GET') {
        $stats = getGlobalStats();
        
        http_response_code(200);
        exit(json_encode([
            'status' => 'success',
            'timestamp' => date('Y-m-d'),
            'stats' => $stats ?? []
        ]));
    }
    
    // Get user stats
    if ($path === '/api/user-stats' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['user_id'] ?? null;
        
        if (!$userId) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing user_id']));
        }
        
        $userStats = getUserStats($userId);
        http_response_code(200);
        exit(json_encode(['status' => 'success', 'stats' => $userStats ?? []]));
    }
    
    // Unknown endpoint
    http_response_code(404);
    exit(json_encode([
        'error' => 'Not found',
        'available_endpoints' => [
            'GET /' => 'Health check',
            'POST /webhook' => 'Telegram webhook',
            'GET /webhook/setup' => 'Register webhook',
            'GET /webhook/status' => 'Check webhook',
            'POST /api/chat' => 'Chat API',
            'GET /api/stats' => 'Global stats',
            'POST /api/user-stats' => 'User stats'
        ]
    ]));

} catch (Exception $e) {
    error_log("EXCEPTION: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['error' => 'Server error']));
}

?>
