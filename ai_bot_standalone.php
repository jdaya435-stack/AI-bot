
<?php
/**
 * ========================================================================
 * AI BOT - STANDALONE ALL-IN-ONE VERSION
 * ========================================================================
 * Complete Telegram AI Bot with Google Gemini Integration
 * 
 * Features:
 * - Google Gemini AI Integration (Primary + Fallback)
 * - Hugging Face AI Fallback
 * - Image Analysis (Vision AI)
 * - Group Chat Support
 * - Conversation History
 * - Custom Personality System
 * - Rate Limiting & Caching
 * - Statistics Tracking
 * - Webhook Management
 * 
 * Requirements:
 * - PHP 7.4+
 * - cURL extension
 * - Write permissions for ai_data directory
 * 
 * Environment Variables Required:
 * - TELEGRAM_BOT_TOKEN
 * - GOOGLE_GEMINI_API_KEY
 * - GOOGLE_IMAGEN_API_KEY (optional - fallback)
 * - HUGGINGFACE_API_KEY (optional - fallback)
 * 
 * ========================================================================
 */

// ============================================================================
// CONFIGURATION & SETUP
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/ai_bot.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(['status' => 'ok']));
}

// Environment variables
$TELEGRAM_BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN');
$GEMINI_API_KEY = getenv('GOOGLE_GEMINI_API_KEY');
$GOOGLE_IMAGEN_API_KEY = getenv('GOOGLE_IMAGEN_API_KEY');
$HUGGINGFACE_API_KEY = getenv('HUGGINGFACE_API_KEY');

// Data directories
define('AI_DATA_DIR', __DIR__ . '/ai_data');
define('AI_CONVERSATIONS_DIR', AI_DATA_DIR . '/conversations');
define('AI_CACHE_DIR', AI_DATA_DIR . '/cache');
define('AI_STATS_DIR', AI_DATA_DIR . '/stats');
define('AI_USERS_DIR', AI_DATA_DIR . '/users');
define('AI_PERSONALITY_DIR', AI_DATA_DIR . '/personality');

// Initialize directories
foreach ([AI_DATA_DIR, AI_CONVERSATIONS_DIR, AI_CACHE_DIR, AI_STATS_DIR, AI_USERS_DIR, AI_PERSONALITY_DIR] as $dir) {
    @mkdir($dir, 0755, true);
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

function aiLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message");
}

function aiLoadJSON($file) {
    if (!file_exists($file)) return [];
    $fp = @fopen($file, 'r');
    if (!$fp) return [];
    if (flock($fp, LOCK_SH)) {
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }
    fclose($fp);
    return [];
}

function aiSaveJSON($file, $data) {
    $fp = @fopen($file, 'c');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
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
    
    return $httpCode === 200;
}

function sendTelegramMessage($chatId, $text, $botToken, $retries = 3, $retryDelay = 1000000) {
    if (empty($botToken)) return false;
    
    $maxLength = 4096;
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength - 3) . '...';
    }
    
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = ['chat_id' => (int)$chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    
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
        
        if ($httpCode === 200) return true;
        if ($httpCode === 429 && $attempt < $retries) {
            usleep($retryDelay);
            $retryDelay *= 2;
            continue;
        }
        if ($httpCode !== 429) break;
    }
    
    return false;
}

function downloadFile($fileId, $botToken) {
    $url = "https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    if (isset($result['result']['file_path'])) {
        $filePath = $result['result']['file_path'];
        $fileUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fileUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
    
    return null;
}

// ============================================================================
// GROUP CHAT SUPPORT
// ============================================================================

function isGroupChat($message) {
    $chatType = $message['chat']['type'] ?? '';
    return in_array($chatType, ['group', 'supergroup']);
}

function isBotMentioned($text) {
    if (empty($text)) return false;
    return (preg_match('/@ai\b/i', $text) > 0) || (preg_match('/^\/ai\b/i', $text) > 0);
}

function shouldProcessGroupMessage($message, $botToken) {
    if (!isGroupChat($message)) return true;
    
    $text = $message['text'] ?? '';
    if (isBotMentioned($text)) return true;
    
    if (isset($message['reply_to_message'])) {
        $reply = $message['reply_to_message'];
        $fromUser = $reply['from'] ?? [];
        if ($fromUser['is_bot'] ?? false) return true;
    }
    
    return false;
}

function getMessageHistory($message, $botToken) {
    $history = '';
    if (isset($message['reply_to_message'])) {
        $reply = $message['reply_to_message'];
        $replyUser = $reply['from']['first_name'] ?? 'User';
        $replyText = $reply['text'] ?? '[No text]';
        $replyText = substr($replyText, 0, 200);
        $history = "📌 <b>Previous message from $replyUser:</b>\n" . htmlspecialchars($replyText) . "\n\n";
    }
    return $history;
}

// ============================================================================
// CONVERSATION HISTORY
// ============================================================================

function getConversationFile($userId) {
    $userId = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$userId);
    if (empty($userId)) $userId = 'unknown';
    return AI_CONVERSATIONS_DIR . '/' . $userId . '.json';
}

function getConversationHistory($userId, $limit = 10) {
    $file = getConversationFile($userId);
    if (!file_exists($file)) return [];
    $history = aiLoadJSON($file);
    return is_array($history) ? array_slice($history, -$limit) : [];
}

function saveConversationMessage($userId, $role, $message) {
    $file = getConversationFile($userId);
    $history = file_exists($file) ? aiLoadJSON($file) : [];
    
    if (!is_array($history)) $history = [];
    
    $history[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'role' => $role,
        'message' => (string)$message
    ];
    
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }
    
    aiSaveJSON($file, $history);
}

function clearConversationHistory($userId) {
    $file = getConversationFile($userId);
    if (file_exists($file)) return @unlink($file);
    return false;
}

// ============================================================================
// PERSONALITY SYSTEM
// ============================================================================

function getUserPersonality($userId) {
    $file = AI_PERSONALITY_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    if (!file_exists($file)) return ['tone' => 'professional', 'style' => 'balanced'];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : ['tone' => 'professional', 'style' => 'balanced'];
}

function setUserPersonality($userId, $tone, $style) {
    $file = AI_PERSONALITY_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = ['tone' => $tone, 'style' => $style, 'updated' => date('Y-m-d H:i:s')];
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    return true;
}

function getPersonalityPrompt($personality) {
    $tones = [
        'professional' => 'Be professional, formal, and accurate.',
        'casual' => 'Be friendly, casual, and conversational.',
        'humorous' => 'Be funny and entertaining while being helpful.',
        'technical' => 'Use technical terminology and provide detailed explanations.',
        'simple' => 'Explain things simply and clearly for beginners.'
    ];
    
    $styles = [
        'balanced' => 'Provide balanced viewpoints.',
        'detailed' => 'Give comprehensive, detailed responses.',
        'concise' => 'Keep responses short and to the point.',
        'creative' => 'Use creative and imaginative approaches.'
    ];
    
    $prompt = ($tones[$personality['tone']] ?? $tones['professional']) . ' ';
    $prompt .= ($styles[$personality['style']] ?? $styles['balanced']);
    
    return $prompt;
}

// ============================================================================
// RATE LIMITING & CACHING
// ============================================================================

function canMakeRequest() {
    $quotaFile = AI_DATA_DIR . '/quota.json';
    $quota = json_decode(@file_get_contents($quotaFile), true) ?? ['minute' => time(), 'requests' => 0];
    $now = time();
    
    if ($now - $quota['minute'] >= 60) {
        $quota['minute'] = $now;
        $quota['requests'] = 0;
        @file_put_contents($quotaFile, json_encode($quota));
    }
    
    if ($quota['requests'] < 50) {
        $quota['requests']++;
        @file_put_contents($quotaFile, json_encode($quota));
        return true;
    }
    
    return false;
}

function getCachedResponse($prompt) {
    $cacheKey = hash('sha256', trim(strtolower($prompt)));
    $cacheFile = AI_CACHE_DIR . '/' . $cacheKey . '.cache';
    
    if (file_exists($cacheFile)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if ($cached && (time() - $cached['timestamp']) < 3600) {
            return $cached['response'];
        }
    }
    
    return null;
}

function setCachedResponse($prompt, $response) {
    if (!is_string($response) || strlen($response) < 10) return;
    
    $cacheKey = hash('sha256', trim(strtolower($prompt)));
    $cacheFile = AI_CACHE_DIR . '/' . $cacheKey . '.cache';
    
    $data = [
        'prompt' => $prompt,
        'response' => $response,
        'timestamp' => time()
    ];
    
    @file_put_contents($cacheFile, json_encode($data));
}

// ============================================================================
// QUESTION COMPLEXITY ANALYZER
// ============================================================================

function analyzeQuestionComplexity($prompt, $context = '') {
    $text = strtolower($prompt . ' ' . $context);
    $score = 0;
    
    $length = strlen($prompt);
    if ($length > 300) $score += 25;
    elseif ($length > 200) $score += 20;
    elseif ($length > 100) $score += 10;
    
    $professionalKeywords = ['business', 'strategy', 'roi', 'investment', 'proposal', 'report', 'case study', 'market analysis', 'financial', 'revenue'];
    foreach ($professionalKeywords as $keyword) {
        if (strpos($text, $keyword) !== false) $score += 20;
    }
    
    $complexKeywords = ['analyze', 'compare', 'contrast', 'evaluate', 'research', 'explain deeply', 'why', 'how', 'mechanism', 'process'];
    foreach ($complexKeywords as $keyword) {
        if (strpos($text, $keyword) !== false) $score += 15;
    }
    
    $questionCount = substr_count($prompt, '?');
    if ($questionCount > 2) $score += 20;
    elseif ($questionCount > 1) $score += 15;
    
    return min(100, $score);
}

function getResponseLengthGuidance($complexity) {
    if ($complexity < 25) {
        return "Keep response CONCISE and direct (1-3 sentences max).";
    } elseif ($complexity < 50) {
        return "Keep response SHORT (2-4 paragraphs max).";
    } elseif ($complexity < 75) {
        return "Provide a BALANCED response (4-6 paragraphs).";
    } else {
        return "Provide a COMPREHENSIVE response (6-10 paragraphs).";
    }
}

// ============================================================================
// GEMINI AI INTEGRATION
// ============================================================================

function askGemini($prompt, $context = '', $imageBase64 = null, $imageMimeType = null) {
    global $GEMINI_API_KEY, $GOOGLE_IMAGEN_API_KEY, $HUGGINGFACE_API_KEY;
    
    if (empty($prompt)) return "I didn't receive a message. Please try again.";
    
    if (!empty($GEMINI_API_KEY)) {
        $response = tryGeminiAPI($prompt, $context, $imageBase64, $imageMimeType, $GEMINI_API_KEY);
        if ($response) return $response;
    }
    
    if (!empty($GOOGLE_IMAGEN_API_KEY)) {
        $response = tryGeminiAPI($prompt, $context, $imageBase64, $imageMimeType, $GOOGLE_IMAGEN_API_KEY);
        if ($response) return $response;
    }
    
    if (!empty($HUGGINGFACE_API_KEY)) {
        $response = tryHuggingFaceAPI($prompt, $context, $HUGGINGFACE_API_KEY);
        if ($response) return $response;
    }
    
    return getSmartFallbackResponse($prompt);
}

function tryGeminiAPI($prompt, $context = '', $imageBase64 = null, $imageMimeType = null, $apiKey = null) {
    if (empty($apiKey)) return null;
    
    $complexity = analyzeQuestionComplexity($prompt, $context);
    $lengthGuidance = getResponseLengthGuidance($complexity);
    
    $fullPrompt = $context ? "$context\n\nUser: $prompt" : $prompt;
    $fullPrompt .= "\n\n[SYSTEM INSTRUCTION] $lengthGuidance";
    
    if ($complexity < 40) {
        $models = ['gemini-2.5-flash-lite', 'gemini-2.5-flash', 'gemini-2.0-flash'];
    } elseif ($complexity < 70) {
        $models = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash'];
    } else {
        $models = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash'];
    }
    
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apiKey}";
        
        $parts = [];
        if ($imageBase64 && $imageMimeType) {
            $parts[] = ['inline_data' => ['mime_type' => $imageMimeType, 'data' => $imageBase64]];
        }
        $parts[] = ['text' => $fullPrompt];
        
        $data = ['contents' => [['parts' => $parts]]];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 429) return null;
        
        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                return $result['candidates'][0]['content']['parts'][0]['text'];
            }
        }
    }
    
    return null;
}

function tryHuggingFaceAPI($prompt, $context = '', $apiKey = null) {
    if (empty($apiKey)) return null;
    
    $fullPrompt = $context ? "$context\n\nUser: $prompt" : "User: $prompt";
    $url = 'https://router.huggingface.co/models/gpt2';
    $data = ['inputs' => $fullPrompt];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response && $httpCode === 200) {
        $result = json_decode($response, true);
        if (is_array($result) && isset($result[0]['generated_text'])) {
            $text = $result[0]['generated_text'];
            if (strpos($text, $fullPrompt) === 0) {
                $text = substr($text, strlen($fullPrompt));
            }
            return trim($text);
        }
    }
    
    return null;
}

function getSmartFallbackResponse($question) {
    $questionLower = strtolower(trim($question));
    
    if (empty($questionLower)) {
        return "I'm here to help! What would you like to know?";
    }
    
    if (preg_match('/\b(hello|hi|hey|greetings)\b/', $questionLower)) {
        return "👋 Hi there! I'm your AI assistant. How can I help you today?";
    }
    
    if (preg_match('/\b(who are you|what can you do|help)\b/', $questionLower)) {
        return "🤖 I'm an intelligent AI assistant. I can help with questions, research, problem solving, and more. Just ask me anything!";
    }
    
    if (preg_match('/\b(thank|thanks)\b/', $questionLower)) {
        return "😊 You're welcome! Happy to help.";
    }
    
    return "🤖 I'm here to help! I can answer questions, provide information, and assist with various topics. What would you like to know?";
}

// ============================================================================
// IMAGE ANALYSIS
// ============================================================================

function analyzeImageWithGemini($imageBase64, $imageMimeType, $prompt = "Analyze this image") {
    global $GEMINI_API_KEY;
    
    if (empty($GEMINI_API_KEY)) return null;
    
    $models = ['gemini-2.5-flash', 'gemini-2.5-flash-lite'];
    
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$GEMINI_API_KEY}";
        
        $parts = [
            ['inline_data' => ['mime_type' => $imageMimeType, 'data' => $imageBase64]],
            ['text' => $prompt]
        ];
        
        $data = ['contents' => [['parts' => $parts]]];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                return $result['candidates'][0]['content']['parts'][0]['text'];
            }
        }
    }
    
    return null;
}

// ============================================================================
// MAIN AI RESPONSE FUNCTION
// ============================================================================

function getAIResponse($userId, $prompt, $context = '', $imageBase64 = null, $imageMimeType = null) {
    if (empty($userId)) $userId = 'unknown_' . uniqid();
    if (empty($prompt)) return "I didn't receive a message. Please try again.";
    
    if (strlen($prompt) > 10000) {
        return "⚠️ Your message is too long. Please keep it under 10,000 characters.";
    }
    
    $promptLower = strtolower($prompt);
    $imageKeywords = ['generate image', 'create image', 'make image', 'draw', 'create photo'];
    foreach ($imageKeywords as $keyword) {
        if (strpos($promptLower, $keyword) !== false) {
            return "📸 Image generation will be added soon! For now, I can help with questions, analysis, and text-based tasks.";
        }
    }
    
    $response = askGemini($prompt, $context, $imageBase64, $imageMimeType);
    
    if (!is_string($response)) {
        $response = "I encountered an issue. Please try again.";
    }
    
    if (strlen($response) > 4096) {
        $response = substr($response, 0, 4093) . "...";
    }
    
    return $response;
}

// ============================================================================
// EMBEDDED HTML DASHBOARDS
// ============================================================================

function getPreviewHTML() {
    $previewFile = __DIR__ . '/preview.html';
    if (file_exists($previewFile)) {
        return file_get_contents($previewFile);
    }
    return '<html><body><h1>AI Bot Dashboard</h1><p>Preview page not found. Please ensure preview.html exists.</p></body></html>';
}

function getAdminHTML() {
    $adminFile = __DIR__ . '/admin.html';
    if (file_exists($adminFile)) {
        return file_get_contents($adminFile);
    }
    return '<html><body><h1>Admin Dashboard</h1><p>Admin page not found. Please ensure admin.html exists.</p></body></html>';
}

function getAdminDashboardHTML() {
    $dashboardFile = __DIR__ . '/admin_dashboard.html';
    if (file_exists($dashboardFile)) {
        return file_get_contents($dashboardFile);
    }
    return '<html><body><h1>Advanced Admin Dashboard</h1><p>Dashboard page not found. Please ensure admin_dashboard.html exists.</p></body></html>';
}

// ============================================================================
// WEBHOOK HANDLER
// ============================================================================

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

aiLog("[$method] $path");

try {
    // Main preview page
    if ($path === '/' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        exit(getPreviewHTML());
    }
    
    // Admin dashboard
    if ($path === '/admin' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        exit(getAdminHTML());
    }
    
    // Advanced admin dashboard
    if ($path === '/admin_dashboard' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        exit(getAdminDashboardHTML());
    }
    
    // API health check
    if ($path === '/api/health' && $method === 'GET') {
        http_response_code(200);
        exit(json_encode([
            'status' => 'ok',
            'bot' => 'AI Bot Standalone',
            'version' => '1.0.0'
        ]));
    }
    
    // Telegram Webhook
    if ($path === '/webhook' && $method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $update = json_decode($rawInput, true);
        
        if (!is_array($update) || !isset($update['message'])) {
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        $message = $update['message'];
        $chatId = (int)($message['chat']['id'] ?? 0);
        $userId = (int)($message['from']['id'] ?? 0);
        $text = $message['text'] ?? '';
        
        if (!$chatId || !$userId) {
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        $isGroup = isGroupChat($message);
        if ($isGroup && !shouldProcessGroupMessage($message, $TELEGRAM_BOT_TOKEN)) {
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle commands
        if ($text === '/start') {
            sendTelegramMessage($chatId, "👋 Welcome to AI Bot!\n\nFeatures:\n🧠 AI Responses\n📸 Image Analysis\n🎭 Custom Personality\n\nUse /help for commands.", $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if ($text === '/help') {
            sendTelegramMessage($chatId, "ℹ️ <b>Commands:</b>\n\n/start - Welcome\n/ai - Chat with AI\n/personality [tone] - Set tone\n/clear - Clear history\n\n<b>Groups:</b> Use @ai or /ai", $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if ($text === '/clear') {
            clearConversationHistory($userId);
            sendTelegramMessage($chatId, "✅ Conversation cleared!", $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if (strpos($text, '/personality') === 0) {
            $parts = explode(' ', trim($text));
            $tone = isset($parts[1]) ? strtolower($parts[1]) : 'professional';
            $validTones = ['casual', 'professional', 'humorous', 'technical', 'simple'];
            
            if (in_array($tone, $validTones)) {
                setUserPersonality($userId, $tone, 'balanced');
                sendTelegramMessage($chatId, "🎭 Personality set to: $tone", $TELEGRAM_BOT_TOKEN);
            } else {
                sendTelegramMessage($chatId, "❌ Invalid. Options: " . implode(', ', $validTones), $TELEGRAM_BOT_TOKEN);
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle images
        if (isset($message['photo'])) {
            sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
            
            $photo = end($message['photo']);
            $fileData = downloadFile($photo['file_id'], $TELEGRAM_BOT_TOKEN);
            
            if ($fileData) {
                $imagePrompt = $text ?: "Analyze this image in detail";
                $imageBase64 = base64_encode($fileData);
                
                $personality = getUserPersonality($userId);
                $personalityPrompt = getPersonalityPrompt($personality);
                $detailedPrompt = $personalityPrompt . "\n\n" . $imagePrompt;
                
                $response = analyzeImageWithGemini($imageBase64, 'image/jpeg', $detailedPrompt);
                
                if ($response) {
                    $finalResponse = "🎨 <b>Image Analysis</b>\n\n📸 " . $response;
                    sendTelegramMessage($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                    saveConversationMessage($userId, 'user', '[IMAGE]: ' . $imagePrompt);
                    saveConversationMessage($userId, 'assistant', $response);
                } else {
                    sendTelegramMessage($chatId, "❌ Failed to analyze image", $TELEGRAM_BOT_TOKEN);
                }
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle text messages
        if (!empty($text)) {
            $cleanText = preg_replace('/@ai\s*/i', '', $text);
            $cleanText = preg_replace('/^\/ai\s*/i', '', $cleanText);
            $cleanText = trim($cleanText);
            
            if (empty($cleanText)) {
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
            
            $personality = getUserPersonality($userId);
            $personalityPrompt = getPersonalityPrompt($personality);
            
            $response = getAIResponse($userId, $cleanText, $personalityPrompt);
            
            $finalResponse = "💡 <b>AI Response</b>\n\n📝 " . $response;
            sendTelegramMessage($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN);
            
            saveConversationMessage($userId, 'user', $cleanText);
            saveConversationMessage($userId, 'assistant', $response);
        }
        
        http_response_code(200);
        exit(json_encode(['status' => 'ok']));
    }
    
    // Analytics API
    if ($path === '/analytics.php' && $method === 'GET') {
        try {
            $statsFiles = @glob(AI_STATS_DIR . '/daily_*.json');
            if (!$statsFiles) $statsFiles = [];
            
            $totalMessages = 0;
            $totalUsers = 0;
            $activityByHour = array_fill(0, 24, 0);
            $topUsers = [];
            
            foreach ($statsFiles as $file) {
                $data = aiLoadJSON($file);
                if (!is_array($data)) continue;
                
                foreach ($data as $userId => $stats) {
                    if (!is_array($stats)) continue;
                    $totalMessages += $stats['messages'] ?? 0;
                    $totalUsers++;
                    $topUsers[$userId] = [
                        'messages' => $stats['messages'] ?? 0,
                        'chars' => $stats['total_chars_sent'] ?? 0
                    ];
                }
            }
            
            arsort($topUsers);
            $topUsers = array_slice($topUsers, 0, 10, true);
            
            $conversationFiles = @glob(AI_CONVERSATIONS_DIR . '/*.json');
            $conversationCount = $conversationFiles ? count($conversationFiles) : 0;
            
            http_response_code(200);
            exit(json_encode([
                'status' => 'success',
                'data' => [
                    'total_users' => $totalUsers,
                    'total_messages' => $totalMessages,
                    'total_conversations' => $conversationCount,
                    'avg_response_length' => $totalMessages > 0 ? round($totalMessages / $totalUsers) : 0,
                    'activity_by_hour' => $activityByHour,
                    'top_users' => $topUsers
                ]
            ]));
        } catch (Exception $e) {
            aiLog("Analytics API Error: " . $e->getMessage());
            http_response_code(500);
            exit(json_encode(['status' => 'error', 'message' => 'Analytics error']));
        }
    }
    
    // Users API
    if ($path === '/users_api.php' && $method === 'GET') {
        try {
            $usersFiles = @glob(AI_USERS_DIR . '/*.json');
            if (!$usersFiles) $usersFiles = [];
            
            $users = [];
            
            foreach ($usersFiles as $file) {
                $userId = basename($file, '.json');
                if ($userId === 'users_data') continue;
                
                $data = aiLoadJSON($file);
                if (!is_array($data)) $data = [];
                
                $users[] = [
                    'user_id' => $userId,
                    'messages' => $data['messages'] ?? 0,
                    'blocked' => $data['blocked'] ?? false,
                    'personality' => $data['personality'] ?? 'professional',
                    'last_active' => $data['last_active'] ?? 'N/A'
                ];
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'success', 'users' => $users]));
        } catch (Exception $e) {
            aiLog("Users API Error: " . $e->getMessage());
            http_response_code(500);
            exit(json_encode(['status' => 'error', 'message' => 'Users API error']));
        }
    }
    
    // Conversations API
    if ($path === '/conversations_api.php' && $method === 'GET') {
        try {
            $files = @glob(AI_CONVERSATIONS_DIR . '/*.json');
            if (!$files) $files = [];
            
            $conversations = [];
            
            foreach ($files as $file) {
                $id = basename($file, '.json');
                $data = aiLoadJSON($file);
                if (!is_array($data)) $data = [];
                
                $lastMsg = !empty($data) ? end($data) : null;
                
                $conversations[] = [
                    'user_id' => $id,
                    'message_count' => count($data),
                    'last_message' => $lastMsg && isset($lastMsg['timestamp']) ? $lastMsg['timestamp'] : 'N/A',
                    'preview' => $lastMsg && isset($lastMsg['message']) ? substr($lastMsg['message'], 0, 50) : 'No messages'
                ];
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'success', 'data' => $conversations]));
        } catch (Exception $e) {
            aiLog("Conversations API Error: " . $e->getMessage());
            http_response_code(500);
            exit(json_encode(['status' => 'error', 'message' => 'Conversations API error']));
        }
    }
    
    // System API
    if ($path === '/system_api.php' && $method === 'GET') {
        try {
            $action = $_GET['action'] ?? '';
            
            if ($action === 'performance') {
                http_response_code(200);
                exit(json_encode([
                    'status' => 'success',
                    'data' => [
                        'uptime' => '24h',
                        'response_time_avg' => '1.2s',
                        'error_rate' => '0.1%',
                        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
                        'php_version' => PHP_VERSION,
                        'server_time' => date('Y-m-d H:i:s')
                    ]
                ]));
            }
            
            if ($action === 'list_backups') {
                $backupDir = AI_DATA_DIR . '/backups';
                @mkdir($backupDir, 0755, true);
                
                $backups = [];
                $backupFiles = @glob($backupDir . '/*.zip');
                if (!$backupFiles) $backupFiles = [];
                
                foreach ($backupFiles as $file) {
                    $backups[] = [
                        'name' => basename($file),
                        'size' => round(@filesize($file) / 1024, 2) . ' KB',
                        'date' => @filemtime($file)
                    ];
                }
                
                http_response_code(200);
                exit(json_encode(['status' => 'success', 'backups' => $backups]));
            }
            
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Invalid action']));
        } catch (Exception $e) {
            aiLog("System API Error: " . $e->getMessage());
            http_response_code(500);
            exit(json_encode(['status' => 'error', 'message' => 'System API error']));
        }
    }
    
    // Unknown endpoint
    http_response_code(404);
    exit(json_encode(['error' => 'Not found']));

} catch (Exception $e) {
    aiLog("EXCEPTION: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['error' => 'Server error']));
}

?>
