
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
$ADMIN_USER_ID = getenv('ADMIN_USER_ID') ?: '';

// Data directories
define('AI_DATA_DIR', __DIR__ . '/ai_data');
define('AI_CONVERSATIONS_DIR', AI_DATA_DIR . '/conversations');
define('AI_CACHE_DIR', AI_DATA_DIR . '/cache');
define('AI_STATS_DIR', AI_DATA_DIR . '/stats');
define('AI_USERS_DIR', AI_DATA_DIR . '/users');
define('AI_PERSONALITY_DIR', AI_DATA_DIR . '/personality');
define('AI_PREFERENCES_DIR', AI_DATA_DIR . '/preferences');
define('AI_ADMIN_DIR', AI_DATA_DIR . '/admin');

// Initialize directories
foreach ([AI_DATA_DIR, AI_CONVERSATIONS_DIR, AI_CACHE_DIR, AI_STATS_DIR, AI_USERS_DIR, AI_PERSONALITY_DIR, AI_PREFERENCES_DIR, AI_ADMIN_DIR] as $dir) {
    @mkdir($dir, 0755, true);
}

// Admin check function
function isAdmin($userId) {
    global $ADMIN_USER_ID;
    if (empty($ADMIN_USER_ID)) return false;
    $adminIds = array_map('trim', explode(',', $ADMIN_USER_ID));
    return in_array((string)$userId, $adminIds);
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
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return $result['result']['message_id'] ?? true;
        }
        if ($httpCode === 429 && $attempt < $retries) {
            usleep($retryDelay);
            $retryDelay *= 2;
            continue;
        }
        if ($httpCode !== 429) break;
    }
    
    return false;
}

function editTelegramMessage($chatId, $messageId, $text, $botToken) {
    if (empty($botToken) || empty($messageId)) return false;
    
    $url = "https://api.telegram.org/bot{$botToken}/editMessageText";
    $data = ['chat_id' => (int)$chatId, 'message_id' => (int)$messageId, 'text' => $text, 'parse_mode' => 'HTML'];
    
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
    
    // Check if bot is mentioned
    if (isBotMentioned($text)) return true;
    
    // Check if message starts with /ai command
    if (stripos($text, '/ai') === 0) return true;
    
    // Check if replying to bot's message
    if (isset($message['reply_to_message'])) {
        $reply = $message['reply_to_message'];
        $fromUser = $reply['from'] ?? [];
        
        // Get bot info to check if replied message is from this bot
        if (($fromUser['is_bot'] ?? false)) {
            return true;
        }
        
        // Also check if current message mentions AI
        if (isBotMentioned($text)) {
            return true;
        }
    }
    
    // Check for entities (mentions)
    if (isset($message['entities'])) {
        foreach ($message['entities'] as $entity) {
            if ($entity['type'] === 'mention' || $entity['type'] === 'text_mention') {
                return true;
            }
        }
    }
    
    return false;
}

function getMessageHistory($message, $botToken) {
    $history = '';
    if (isset($message['reply_to_message'])) {
        $reply = $message['reply_to_message'];
        $replyUser = $reply['from']['first_name'] ?? 'User';
        $replyUserId = $reply['from']['id'] ?? 0;
        $replyText = $reply['text'] ?? '';
        
        // Handle photo captions in replied message
        if (empty($replyText) && isset($reply['caption'])) {
            $replyText = $reply['caption'];
        }
        
        // Check if replied message has a photo
        $hasPhoto = isset($reply['photo']) ? '[📸 Photo] ' : '';
        
        if (empty($replyText)) {
            $replyText = '[No text content]';
        } else {
            $replyText = substr($replyText, 0, 500); // Extended limit
        }
        
        $history = "📌 <b>Context - Replying to message from $replyUser (ID: $replyUserId):</b>\n";
        $history .= "$hasPhoto" . htmlspecialchars($replyText) . "\n\n";
        $history .= "<i>The user is asking you about the above message. Answer their question based on this context.</i>\n\n";
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

function formatConversationForContext($userId, $limit = 6) {
    $history = getConversationHistory($userId, $limit);
    if (empty($history)) return '';
    
    $formatted = "Previous conversation:\n";
    foreach ($history as $msg) {
        $role = ($msg['role'] === 'user') ? 'User' : 'Assistant';
        $text = substr($msg['message'], 0, 300);
        $formatted .= "$role: $text\n";
    }
    return $formatted . "\n";
}

// ============================================================================
// USER PREFERENCES SYSTEM (Multi-Turn Context Memory)
// ============================================================================

function getUserPreferences($userId) {
    $file = AI_PREFERENCES_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    if (!file_exists($file)) {
        return [
            'name' => null,
            'nationality' => null,
            'country_emoji' => null,
            'telegram_id' => $userId,
            'preferred_language' => 'English',
            'topics_of_interest' => [],
            'response_style' => 'balanced',
            'remember_items' => [],
            'created' => date('Y-m-d H:i:s'),
            'last_active' => date('Y-m-d H:i:s')
        ];
    }
    $data = json_decode(file_get_contents($file), true);
    // Ensure new fields exist for existing users
    if (!isset($data['telegram_id'])) $data['telegram_id'] = $userId;
    if (!isset($data['nationality'])) $data['nationality'] = null;
    if (!isset($data['country_emoji'])) $data['country_emoji'] = null;
    return is_array($data) ? $data : getUserPreferences('default');
}

function getCountryFlagEmoji($nationality) {
    // Map country names to flag emojis
    $countryFlags = [
        'kenya' => '🇰🇪',
        'usa' => '🇺🇸',
        'united states' => '🇺🇸',
        'america' => '🇺🇸',
        'uk' => '🇬🇧',
        'united kingdom' => '🇬🇧',
        'britain' => '🇬🇧',
        'england' => '🇬🇧',
        'india' => '🇮🇳',
        'china' => '🇨🇳',
        'japan' => '🇯🇵',
        'nigeria' => '🇳🇬',
        'germany' => '🇩🇪',
        'france' => '🇫🇷',
        'italy' => '🇮🇹',
        'spain' => '🇪🇸',
        'brazil' => '🇧🇷',
        'canada' => '🇨🇦',
        'australia' => '🇦🇺',
        'mexico' => '🇲🇽',
        'russia' => '🇷🇺',
        'south korea' => '🇰🇷',
        'korea' => '🇰🇷',
        'south africa' => '🇿🇦',
        'egypt' => '🇪🇬',
        'pakistan' => '🇵🇰',
        'bangladesh' => '🇧🇩',
        'philippines' => '🇵🇭',
        'vietnam' => '🇻🇳',
        'thailand' => '🇹🇭',
        'indonesia' => '🇮🇩',
        'turkey' => '🇹🇷',
        'saudi arabia' => '🇸🇦',
        'uae' => '🇦🇪',
        'argentina' => '🇦🇷',
        'colombia' => '🇨🇴',
        'chile' => '🇨🇱',
        'poland' => '🇵🇱',
        'ukraine' => '🇺🇦',
        'netherlands' => '🇳🇱',
        'belgium' => '🇧🇪',
        'sweden' => '🇸🇪',
        'norway' => '🇳🇴',
        'denmark' => '🇩🇰',
        'finland' => '🇫🇮',
        'portugal' => '🇵🇹',
        'greece' => '🇬🇷',
        'switzerland' => '🇨🇭',
        'austria' => '🇦🇹',
        'ireland' => '🇮🇪',
        'new zealand' => '🇳🇿',
        'singapore' => '🇸🇬',
        'malaysia' => '🇲🇾',
        'israel' => '🇮🇱',
        'iran' => '🇮🇷',
        'iraq' => '🇮🇶'
    ];
    
    $nationalityLower = strtolower(trim($nationality));
    return $countryFlags[$nationalityLower] ?? '🌍';
}

function saveUserPreferences($userId, $preferences) {
    $file = AI_PREFERENCES_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $preferences['last_active'] = date('Y-m-d H:i:s');
    return file_put_contents($file, json_encode($preferences, JSON_PRETTY_PRINT)) !== false;
}

function updateUserPreference($userId, $key, $value) {
    $prefs = getUserPreferences($userId);
    $prefs[$key] = $value;
    return saveUserPreferences($userId, $prefs);
}

function addRememberItem($userId, $item) {
    $prefs = getUserPreferences($userId);
    if (!isset($prefs['remember_items'])) $prefs['remember_items'] = [];
    $prefs['remember_items'][] = ['text' => $item, 'added' => date('Y-m-d H:i:s')];
    if (count($prefs['remember_items']) > 20) {
        $prefs['remember_items'] = array_slice($prefs['remember_items'], -20);
    }
    return saveUserPreferences($userId, $prefs);
}

function formatPreferencesForContext($userId) {
    $prefs = getUserPreferences($userId);
    $context = "";
    
    if (!empty($prefs['name'])) {
        $context .= "User's name is {$prefs['name']}. Always address them by their name when appropriate. ";
    }
    if (!empty($prefs['preferred_language']) && $prefs['preferred_language'] !== 'English') {
        $context .= "User prefers responses in {$prefs['preferred_language']}. ";
    }
    if (!empty($prefs['topics_of_interest'])) {
        $topics = implode(', ', array_slice($prefs['topics_of_interest'], 0, 5));
        $context .= "User is interested in: $topics. ";
    }
    if (!empty($prefs['remember_items'])) {
        $items = array_slice($prefs['remember_items'], -5);
        foreach ($items as $item) {
            $context .= "Remember: {$item['text']}. ";
        }
    }
    
    return $context;
}

function parseRememberCommand($text) {
    $patterns = [
        '/remember\s+(?:that\s+)?my\s+name\s+is\s+([a-zA-Z\s]+)/i' => 'name',
        '/(?:i\s+prefer|respond\s+in|use)\s+([a-zA-Z]+)\s+(?:language)?/i' => 'language',
        '/remember\s+(?:that\s+)?(.+)/i' => 'general'
    ];
    
    foreach ($patterns as $pattern => $type) {
        if (preg_match($pattern, $text, $matches)) {
            return ['type' => $type, 'value' => trim($matches[1])];
        }
    }
    return null;
}

// ============================================================================
// LANGUAGE TRANSLATION
// ============================================================================

function translateText($text, $targetLanguage, $apiKey) {
    if (empty($apiKey) || empty($text)) return null;
    
    $prompt = "Translate the following text to $targetLanguage. Only provide the translation, no explanations:\n\n$text";
    
    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key={$apiKey}";
    $data = ['contents' => [['parts' => [['text' => $prompt]]]]];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
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
    return null;
}

function parseTranslateCommand($text) {
    if (preg_match('/^\/translate\s+(.+)\s+to\s+([a-zA-Z]+)$/i', $text, $matches)) {
        return ['text' => trim($matches[1]), 'language' => trim($matches[2])];
    }
    if (preg_match('/^\/translate\s+([a-zA-Z]+)\s+(.+)$/i', $text, $matches)) {
        return ['text' => trim($matches[2]), 'language' => trim($matches[1])];
    }
    return null;
}

// ============================================================================
// WEB SEARCH INTEGRATION
// ============================================================================

function webSearch($query, $limit = 5) {
    $encodedQuery = urlencode($query);
    $url = "https://api.duckduckgo.com/?q={$encodedQuery}&format=json&no_html=1&skip_disambig=1";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'AI Bot/1.0',
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) return null;
    
    $data = json_decode($response, true);
    if (!$data) return null;
    
    $results = [];
    
    if (!empty($data['Abstract'])) {
        $results[] = [
            'title' => $data['Heading'] ?? 'Summary',
            'snippet' => $data['Abstract'],
            'url' => $data['AbstractURL'] ?? ''
        ];
    }
    
    if (!empty($data['RelatedTopics'])) {
        foreach (array_slice($data['RelatedTopics'], 0, $limit) as $topic) {
            if (isset($topic['Text'])) {
                $results[] = [
                    'title' => $topic['FirstURL'] ?? 'Related',
                    'snippet' => $topic['Text'],
                    'url' => $topic['FirstURL'] ?? ''
                ];
            }
        }
    }
    
    return $results;
}

function formatSearchResults($results, $query) {
    if (empty($results)) {
        return "No results found for: $query";
    }
    
    $formatted = "🔍 <b>Search Results for:</b> <i>$query</i>\n";
    $formatted .= str_repeat("━", 30) . "\n\n";
    
    foreach (array_slice($results, 0, 4) as $i => $result) {
        $num = $i + 1;
        $snippet = substr($result['snippet'], 0, 200);
        $formatted .= "📌 <b>Result $num:</b>\n";
        $formatted .= "$snippet\n\n";
    }
    
    $formatted .= str_repeat("━", 30) . "\n";
    $formatted .= "🌐 <i>Powered by Web Search</i>";
    
    return $formatted;
}

function parseSearchCommand($text) {
    $patterns = [
        '/^\/search\s+(.+)$/i',
        '/^search\s+(?:for|the\s+web\s+for)?\s*(.+)$/i',
        '/^(?:google|look\s+up|find)\s+(.+)$/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            return trim($matches[1]);
        }
    }
    return null;
}

// ============================================================================
// ADMIN MANAGEMENT SYSTEM
// ============================================================================

function getAllUsers() {
    $users = [];
    $files = glob(AI_USERS_DIR . '/*.json');
    if (!$files) return $users;
    
    foreach ($files as $file) {
        $userId = basename($file, '.json');
        $data = aiLoadJSON($file);
        $prefs = getUserPreferences($userId);
        $convFile = getConversationFile($userId);
        $msgCount = file_exists($convFile) ? count(aiLoadJSON($convFile)) : 0;
        
        $users[] = [
            'user_id' => $userId,
            'name' => $prefs['name'] ?? 'Unknown',
            'messages' => $msgCount,
            'last_active' => $prefs['last_active'] ?? 'Never',
            'blocked' => $data['blocked'] ?? false,
            'preferred_language' => $prefs['preferred_language'] ?? 'English'
        ];
    }
    
    return $users;
}

function getSystemStats() {
    $convFiles = glob(AI_CONVERSATIONS_DIR . '/*.json') ?: [];
    $userFiles = glob(AI_USERS_DIR . '/*.json') ?: [];
    $prefFiles = glob(AI_PREFERENCES_DIR . '/*.json') ?: [];
    
    $totalMessages = 0;
    foreach ($convFiles as $file) {
        $data = aiLoadJSON($file);
        $totalMessages += is_array($data) ? count($data) : 0;
    }
    
    $cacheFiles = glob(AI_CACHE_DIR . '/*.cache') ?: [];
    $cacheSize = 0;
    foreach ($cacheFiles as $file) {
        $cacheSize += filesize($file);
    }
    
    return [
        'total_users' => count($userFiles),
        'total_conversations' => count($convFiles),
        'total_messages' => $totalMessages,
        'users_with_preferences' => count($prefFiles),
        'cache_entries' => count($cacheFiles),
        'cache_size_kb' => round($cacheSize / 1024, 2),
        'server_time' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'memory_usage_mb' => round(memory_get_usage() / 1024 / 1024, 2)
    ];
}

function blockUser($userId) {
    $file = AI_USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = file_exists($file) ? aiLoadJSON($file) : [];
    $data['blocked'] = true;
    $data['blocked_at'] = date('Y-m-d H:i:s');
    return aiSaveJSON($file, $data);
}

function unblockUser($userId) {
    $file = AI_USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = file_exists($file) ? aiLoadJSON($file) : [];
    $data['blocked'] = false;
    $data['unblocked_at'] = date('Y-m-d H:i:s');
    return aiSaveJSON($file, $data);
}

function isUserBlocked($userId) {
    $file = AI_USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    if (!file_exists($file)) return false;
    $data = aiLoadJSON($file);
    return $data['blocked'] ?? false;
}

function logUserActivity($userId, $action, $details = '') {
    $file = AI_USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = file_exists($file) ? aiLoadJSON($file) : [];
    
    if (!isset($data['activity_log'])) $data['activity_log'] = [];
    $data['activity_log'][] = [
        'action' => $action,
        'details' => $details,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (count($data['activity_log']) > 100) {
        $data['activity_log'] = array_slice($data['activity_log'], -100);
    }
    
    $data['last_activity'] = date('Y-m-d H:i:s');
    $data['total_actions'] = ($data['total_actions'] ?? 0) + 1;
    
    return aiSaveJSON($file, $data);
}

function broadcastMessage($message, $botToken) {
    $userFiles = glob(AI_CONVERSATIONS_DIR . '/*.json') ?: [];
    $sent = 0;
    $failed = 0;
    
    foreach ($userFiles as $file) {
        $userId = basename($file, '.json');
        if (!is_numeric($userId)) continue;
        if (isUserBlocked($userId)) continue;
        
        if (sendTelegramMessage($userId, $message, $botToken)) {
            $sent++;
        } else {
            $failed++;
        }
        usleep(100000);
    }
    
    return ['sent' => $sent, 'failed' => $failed];
}

function clearUserData($userId) {
    $files = [
        getConversationFile($userId),
        AI_PERSONALITY_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json',
        AI_PREFERENCES_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json',
        AI_USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json'
    ];
    
    $deleted = 0;
    foreach ($files as $file) {
        if (file_exists($file) && @unlink($file)) {
            $deleted++;
        }
    }
    return $deleted;
}

function getAdminDashboardData() {
    return [
        'stats' => getSystemStats(),
        'users' => getAllUsers(),
        'recent_activity' => getRecentActivity(20)
    ];
}

function getRecentActivity($limit = 20) {
    $activity = [];
    $userFiles = glob(AI_USERS_DIR . '/*.json') ?: [];
    
    foreach ($userFiles as $file) {
        $data = aiLoadJSON($file);
        if (isset($data['activity_log'])) {
            $userId = basename($file, '.json');
            foreach (array_slice($data['activity_log'], -5) as $log) {
                $log['user_id'] = $userId;
                $activity[] = $log;
            }
        }
    }
    
    usort($activity, function($a, $b) {
        return strtotime($b['timestamp'] ?? 0) - strtotime($a['timestamp'] ?? 0);
    });
    
    return array_slice($activity, 0, $limit);
}

// ============================================================================
// RICH FORMATTING
// ============================================================================

function formatRichResponse($response, $type = 'ai') {
    $divider = str_repeat("━", 28);
    
    switch ($type) {
        case 'ai':
            return "💡 <b>✨ AI Response ✨</b>\n$divider\n\n📝 $response\n\n$divider\n✓ <i>Response complete</i>";
        case 'translation':
            return "🌐 <b>Translation</b>\n$divider\n\n📝 $response\n\n$divider\n✓ <i>Translation complete</i>";
        case 'search':
            return $response;
        case 'image':
            return "🎨 <b>✨ Image Analysis ✨</b>\n$divider\n\n📸 $response\n\n$divider\n✓ <i>Analysis complete</i>";
        case 'admin':
            return "🔐 <b>Admin Panel</b>\n$divider\n\n$response\n\n$divider";
        case 'memory':
            return "🧠 <b>Memory Updated</b>\n$divider\n\n✅ $response\n\n$divider";
        default:
            return $response;
    }
}

// ============================================================================
// STREAMING RESPONSE HANDLER
// ============================================================================

function sendStreamingResponse($chatId, $response, $botToken, $msgId = null) {
    $chunks = splitResponseIntoChunks($response, 100);
    $fullResponse = "";
    
    foreach ($chunks as $i => $chunk) {
        $fullResponse .= $chunk;
        $displayText = $fullResponse;
        
        if ($i < count($chunks) - 1) {
            $displayText .= " ▌";
        }
        
        if ($msgId) {
            editTelegramMessage($chatId, $msgId, $displayText, $botToken);
        }
        usleep(50000);
    }
    
    return $fullResponse;
}

function splitResponseIntoChunks($text, $chunkSize = 100) {
    $words = explode(' ', $text);
    $chunks = [];
    $current = '';
    
    foreach ($words as $word) {
        if (strlen($current) + strlen($word) + 1 > $chunkSize) {
            if (!empty($current)) {
                $chunks[] = $current;
            }
            $current = $word;
        } else {
            $current .= (empty($current) ? '' : ' ') . $word;
        }
    }
    
    if (!empty($current)) {
        $chunks[] = $current;
    }
    
    return $chunks;
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
    
    // Admin dashboard - requires ADMIN_TOKEN
    if ($path === '/admin' && $method === 'GET') {
        $adminToken = getenv('ADMIN_TOKEN') ?: '';
        $providedToken = $_GET['token'] ?? '';
        if (empty($adminToken) || $providedToken !== $adminToken) {
            http_response_code(403);
            exit(json_encode(['error' => 'Unauthorized. Admin token required.']));
        }
        header('Content-Type: text/html; charset=utf-8');
        exit(getAdminHTML());
    }
    
    // Advanced admin dashboard - requires ADMIN_TOKEN
    if ($path === '/admin_dashboard' && $method === 'GET') {
        $adminToken = getenv('ADMIN_TOKEN') ?: '';
        $providedToken = $_GET['token'] ?? '';
        if (empty($adminToken) || $providedToken !== $adminToken) {
            http_response_code(403);
            exit(json_encode(['error' => 'Unauthorized. Admin token required.']));
        }
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
        
        // Check if user is blocked
        if (isUserBlocked($userId)) {
            sendTelegramMessage($chatId, "⛔ You have been blocked from using this bot.", $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Log user activity
        logUserActivity($userId, 'message', substr($text, 0, 50));
        
        // Handle commands
        if ($text === '/start') {
            $prefs = getUserPreferences($userId);
            
            // Check if this is a first-time user (no name set)
            if (empty($prefs['name'])) {
                // Mark user as awaiting name input
                $prefs['awaiting_name'] = true;
                saveUserPreferences($userId, $prefs);
                
                sendTelegramMessage($chatId, "👋 <b>Welcome to AI Bot!</b>\n\n🎯 Let's set up your profile!\n\n<b>Step 1:</b> Please tell me your name.\n\n💬 <i>Just type your name in the next message...</i>", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Existing user with name
            $name = $prefs['name'];
            sendTelegramMessage($chatId, "👋 Welcome back, <b>$name</b>!\n\n<b>Features:</b>\n🧠 Smart AI Responses\n📸 Image Analysis\n🌐 Language Translation\n🔍 Web Search\n🧠 Context Memory\n🎭 Custom Personality\n\nUse /help for all commands.", $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if ($text === '/donate') {
            $donateMsg = "💝 <b>Support This Bot</b>\n";
            $donateMsg .= str_repeat("━", 28) . "\n\n";
            $donateMsg .= "Thank you for considering supporting this project! ❤️\n\n";
            $donateMsg .= "Your donations help keep the bot running and allow for continuous improvements.\n\n";
            $donateMsg .= "Click the button below to donate via PayPal:\n\n";
            $donateMsg .= "🙏 Every contribution is greatly appreciated!";
            
            // Send message with inline PayPal button
            $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";
            $data = [
                'chat_id' => (int)$chatId,
                'text' => $donateMsg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        [
                            'text' => '💰 Donate via PayPal',
                            'url' => 'https://www.paypal.com/donate?hosted_button_id=XH7BK4ZX7LRY2'
                        ]
                    ]]
                ])
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10
            ]);
            curl_exec($ch);
            curl_close($ch);
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if ($text === '/help') {
            $helpMsg = "ℹ️ <b>Commands:</b>\n\n";
            $helpMsg .= "<b>Basic:</b>\n";
            $helpMsg .= "/start - Welcome message\n";
            $helpMsg .= "/help - Show this help\n";
            $helpMsg .= "/clear - Clear conversation\n";
            $helpMsg .= "/donate - Support the bot\n\n";
            $helpMsg .= "<b>AI Features:</b>\n";
            $helpMsg .= "/ai [message] - Chat with AI\n";
            $helpMsg .= "/translate [text] to [lang] - Translate text\n";
            $helpMsg .= "/search [query] - Search the web\n\n";
            $helpMsg .= "<b>Personalization:</b>\n";
            $helpMsg .= "/personality [tone] - Set AI tone\n";
            $helpMsg .= "/remember [info] - Save info about you\n";
            $helpMsg .= "/myinfo - View your saved info\n";
            $helpMsg .= "/forget - Clear your saved info\n\n";
            $helpMsg .= "<b>Groups:</b> Use @ai or /ai to mention bot";
            
            if (isAdmin($userId)) {
                $helpMsg .= "\n\n<b>🔐 Admin Commands:</b>\n";
                $helpMsg .= "/admin - Admin panel\n";
                $helpMsg .= "/stats - System statistics\n";
                $helpMsg .= "/users - List all users\n";
                $helpMsg .= "/block [id] - Block user\n";
                $helpMsg .= "/unblock [id] - Unblock user\n";
                $helpMsg .= "/broadcast [msg] - Message all users\n";
                $helpMsg .= "/clearuser [id] - Clear user data";
            }
            
            sendTelegramMessage($chatId, $helpMsg, $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if ($text === '/clear') {
            clearConversationHistory($userId);
            sendTelegramMessage($chatId, formatRichResponse("Conversation history cleared!", 'memory'), $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle /myinfo command
        if ($text === '/myinfo') {
            $prefs = getUserPreferences($userId);
            
            $info = "👤 <b>Your Profile Information:</b>\n";
            $info .= str_repeat("━", 28) . "\n\n";
            $info .= "🆔 <b>Telegram ID:</b> <code>{$prefs['telegram_id']}</code>\n";
            $info .= "👤 <b>Name:</b> " . ($prefs['name'] ?? '<i>Not set</i>') . "\n";
            
            if (!empty($prefs['nationality'])) {
                $countryEmoji = $prefs['country_emoji'] ?? '🌍';
                $info .= "🌍 <b>Nationality:</b> $countryEmoji {$prefs['nationality']}\n";
            } else {
                $info .= "🌍 <b>Nationality:</b> <i>Not set</i>\n";
            }
            
            $info .= "🌐 <b>Language:</b> " . ($prefs['preferred_language'] ?? 'English') . "\n";
            $info .= "📅 <b>Member since:</b> " . ($prefs['created'] ?? 'Unknown') . "\n\n";
            
            if (!empty($prefs['remember_items'])) {
                $info .= "<b>💭 Remembered items:</b>\n";
                foreach (array_slice($prefs['remember_items'], -5) as $item) {
                    $info .= "  • {$item['text']}\n";
                }
            } else {
                $info .= "<i>💭 No remembered items yet.</i>\n";
            }
            
            $info .= "\n" . str_repeat("━", 28);
            
            sendTelegramMessage($chatId, $info, $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle /forget command
        if ($text === '/forget') {
            $file = AI_PREFERENCES_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
            if (file_exists($file)) @unlink($file);
            sendTelegramMessage($chatId, formatRichResponse("All your saved preferences have been cleared!", 'memory'), $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle /remember command
        if (strpos($text, '/remember') === 0) {
            $content = trim(substr($text, 9));
            if (empty($content)) {
                sendTelegramMessage($chatId, "❌ Please provide something to remember.\nExample: /remember I prefer short answers", $TELEGRAM_BOT_TOKEN);
            } else {
                $parsed = parseRememberCommand($content);
                if ($parsed) {
                    if ($parsed['type'] === 'name') {
                        updateUserPreference($userId, 'name', $parsed['value']);
                        sendTelegramMessage($chatId, formatRichResponse("I'll remember your name is {$parsed['value']}!", 'memory'), $TELEGRAM_BOT_TOKEN);
                    } elseif ($parsed['type'] === 'language') {
                        updateUserPreference($userId, 'preferred_language', ucfirst($parsed['value']));
                        sendTelegramMessage($chatId, formatRichResponse("I'll respond in {$parsed['value']} from now on!", 'memory'), $TELEGRAM_BOT_TOKEN);
                    } else {
                        addRememberItem($userId, $parsed['value']);
                        sendTelegramMessage($chatId, formatRichResponse("I'll remember: {$parsed['value']}", 'memory'), $TELEGRAM_BOT_TOKEN);
                    }
                } else {
                    addRememberItem($userId, $content);
                    sendTelegramMessage($chatId, formatRichResponse("I'll remember: $content", 'memory'), $TELEGRAM_BOT_TOKEN);
                }
            }
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle /translate command
        if (strpos($text, '/translate') === 0) {
            $parsed = parseTranslateCommand($text);
            if (!$parsed) {
                sendTelegramMessage($chatId, "❌ Usage: /translate [text] to [language]\nExample: /translate Hello to Spanish", $TELEGRAM_BOT_TOKEN);
            } else {
                sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
                $msgId = sendTelegramMessage($chatId, "🌐 <b>Translating</b>●●●", $TELEGRAM_BOT_TOKEN);
                
                $translation = translateText($parsed['text'], $parsed['language'], $GEMINI_API_KEY);
                
                if ($translation) {
                    $result = "<b>Original:</b>\n{$parsed['text']}\n\n<b>➡️ {$parsed['language']}:</b>\n$translation";
                    $response = formatRichResponse($result, 'translation');
                } else {
                    $response = "❌ Translation failed. Please try again.";
                }
                
                if ($msgId && is_numeric($msgId)) {
                    editTelegramMessage($chatId, $msgId, $response, $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, $response, $TELEGRAM_BOT_TOKEN);
                }
            }
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle /search command
        if (strpos($text, '/search') === 0) {
            $query = trim(substr($text, 7));
            if (empty($query)) {
                sendTelegramMessage($chatId, "❌ Usage: /search [query]\nExample: /search latest news about Kenya", $TELEGRAM_BOT_TOKEN);
            } else {
                sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
                $msgId = sendTelegramMessage($chatId, "🔍 <b>Searching</b>●●●", $TELEGRAM_BOT_TOKEN);
                
                $results = webSearch($query);
                $response = formatSearchResults($results, $query);
                
                if ($msgId && is_numeric($msgId)) {
                    editTelegramMessage($chatId, $msgId, $response, $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, $response, $TELEGRAM_BOT_TOKEN);
                }
            }
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if (strpos($text, '/personality') === 0) {
            $parts = explode(' ', trim($text));
            $tone = isset($parts[1]) ? strtolower($parts[1]) : 'professional';
            $validTones = ['casual', 'professional', 'humorous', 'technical', 'simple'];
            
            if (in_array($tone, $validTones)) {
                setUserPersonality($userId, $tone, 'balanced');
                sendTelegramMessage($chatId, formatRichResponse("Personality set to: $tone", 'memory'), $TELEGRAM_BOT_TOKEN);
            } else {
                sendTelegramMessage($chatId, "❌ Invalid. Options: " . implode(', ', $validTones), $TELEGRAM_BOT_TOKEN);
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // ============ ADMIN COMMANDS ============
        if (isAdmin($userId)) {
            // /admin command
            if ($text === '/admin') {
                $stats = getSystemStats();
                $adminMsg = "🔐 <b>Admin Panel</b>\n" . str_repeat("━", 28) . "\n\n";
                $adminMsg .= "📊 <b>System Stats:</b>\n";
                $adminMsg .= "👥 Users: {$stats['total_users']}\n";
                $adminMsg .= "💬 Messages: {$stats['total_messages']}\n";
                $adminMsg .= "🗣️ Conversations: {$stats['total_conversations']}\n";
                $adminMsg .= "💾 Cache: {$stats['cache_size_kb']} KB\n";
                $adminMsg .= "🖥️ Memory: {$stats['memory_usage_mb']} MB\n";
                $adminMsg .= "⏰ Server: {$stats['server_time']}\n\n";
                $adminMsg .= "<b>Commands:</b>\n";
                $adminMsg .= "/users - List users\n";
                $adminMsg .= "/block [id] - Block user\n";
                $adminMsg .= "/unblock [id] - Unblock user\n";
                $adminMsg .= "/broadcast [msg] - Message all\n";
                $adminMsg .= "/clearuser [id] - Clear user data\n";
                $adminMsg .= "/clearcache - Clear cache";
                sendTelegramMessage($chatId, $adminMsg, $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // /stats command
            if ($text === '/stats') {
                $stats = getSystemStats();
                $msg = "📊 <b>System Statistics</b>\n" . str_repeat("━", 28) . "\n\n";
                $msg .= "👥 Total Users: {$stats['total_users']}\n";
                $msg .= "💬 Total Messages: {$stats['total_messages']}\n";
                $msg .= "🗣️ Conversations: {$stats['total_conversations']}\n";
                $msg .= "⚙️ Users with Prefs: {$stats['users_with_preferences']}\n";
                $msg .= "💾 Cache Entries: {$stats['cache_entries']}\n";
                $msg .= "📦 Cache Size: {$stats['cache_size_kb']} KB\n";
                $msg .= "🖥️ Memory Usage: {$stats['memory_usage_mb']} MB\n";
                $msg .= "🐘 PHP Version: {$stats['php_version']}\n";
                $msg .= "⏰ Server Time: {$stats['server_time']}";
                sendTelegramMessage($chatId, $msg, $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // /users command
            if ($text === '/users') {
                $users = getAllUsers();
                if (empty($users)) {
                    sendTelegramMessage($chatId, "No users found.", $TELEGRAM_BOT_TOKEN);
                } else {
                    $msg = "👥 <b>Users List</b> (" . count($users) . ")\n" . str_repeat("━", 28) . "\n\n";
                    foreach (array_slice($users, 0, 20) as $user) {
                        $status = $user['blocked'] ? "🔴" : "🟢";
                        $name = $user['name'] ?: 'Unknown';
                        $msg .= "$status <code>{$user['user_id']}</code>\n";
                        $msg .= "   👤 $name | 💬 {$user['messages']} msgs\n";
                    }
                    if (count($users) > 20) {
                        $msg .= "\n... and " . (count($users) - 20) . " more users";
                    }
                    sendTelegramMessage($chatId, $msg, $TELEGRAM_BOT_TOKEN);
                }
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // /block command
            if (strpos($text, '/block') === 0 && $text !== '/block') {
                $targetId = trim(substr($text, 6));
                if (is_numeric($targetId)) {
                    blockUser($targetId);
                    sendTelegramMessage($chatId, "✅ User $targetId has been blocked.", $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "❌ Usage: /block [user_id]", $TELEGRAM_BOT_TOKEN);
                }
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // /unblock command
            if (strpos($text, '/unblock') === 0) {
                $targetId = trim(substr($text, 8));
                if (is_numeric($targetId)) {
                    unblockUser($targetId);
                    sendTelegramMessage($chatId, "✅ User $targetId has been unblocked.", $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "❌ Usage: /unblock [user_id]", $TELEGRAM_BOT_TOKEN);
                }
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // /clearuser command
            if (strpos($text, '/clearuser') === 0) {
                $targetId = trim(substr($text, 10));
                if (is_numeric($targetId)) {
                    $deleted = clearUserData($targetId);
                    sendTelegramMessage($chatId, "✅ Cleared $deleted files for user $targetId.", $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "❌ Usage: /clearuser [user_id]", $TELEGRAM_BOT_TOKEN);
                }
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // /clearcache command
            if ($text === '/clearcache') {
                $cacheFiles = glob(AI_CACHE_DIR . '/*.cache') ?: [];
                $count = 0;
                foreach ($cacheFiles as $file) {
                    if (@unlink($file)) $count++;
                }
                sendTelegramMessage($chatId, "✅ Cleared $count cache entries.", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // /broadcast command
            if (strpos($text, '/broadcast') === 0) {
                $message = trim(substr($text, 10));
                if (empty($message)) {
                    sendTelegramMessage($chatId, "❌ Usage: /broadcast [message]", $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "📣 Broadcasting message...", $TELEGRAM_BOT_TOKEN);
                    $result = broadcastMessage("📢 <b>Announcement</b>\n\n$message", $TELEGRAM_BOT_TOKEN);
                    sendTelegramMessage($chatId, "✅ Broadcast complete!\nSent: {$result['sent']}\nFailed: {$result['failed']}", $TELEGRAM_BOT_TOKEN);
                }
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
        }
        
        // Handle images
        if (isset($message['photo'])) {
            sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
            
            // Send analyzing animation
            $msgId = sendTelegramMessage($chatId, "📸 <b>Analyzing image</b>●", $TELEGRAM_BOT_TOKEN);
            
            $photo = end($message['photo']);
            $fileData = downloadFile($photo['file_id'], $TELEGRAM_BOT_TOKEN);
            
            if ($fileData) {
                // Animate the dots
                if ($msgId && is_numeric($msgId)) {
                    $dots = ['●', '●●', '●●●'];
                    foreach ($dots as $dot) {
                        usleep(300000);
                        editTelegramMessage($chatId, $msgId, "📸 <b>Analyzing image</b>" . $dot, $TELEGRAM_BOT_TOKEN);
                    }
                }
                
                $imagePrompt = $text ?: "Analyze this image in detail";
                $imageBase64 = base64_encode($fileData);
                
                $personality = getUserPersonality($userId);
                $personalityPrompt = getPersonalityPrompt($personality);
                $detailedPrompt = $personalityPrompt . "\n\n" . $imagePrompt;
                
                $response = analyzeImageWithGemini($imageBase64, 'image/jpeg', $detailedPrompt);
                
                if ($response) {
                    $finalResponse = formatRichResponse($response, 'image');
                    if ($msgId && is_numeric($msgId)) {
                        editTelegramMessage($chatId, $msgId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                    } else {
                        sendTelegramMessage($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                    }
                    saveConversationMessage($userId, 'user', '[IMAGE]: ' . $imagePrompt);
                    saveConversationMessage($userId, 'assistant', $response);
                } else {
                    $errorMsg = "❌ Failed to analyze image. Please try again.";
                    if ($msgId && is_numeric($msgId)) {
                        editTelegramMessage($chatId, $msgId, $errorMsg, $TELEGRAM_BOT_TOKEN);
                    } else {
                        sendTelegramMessage($chatId, $errorMsg, $TELEGRAM_BOT_TOKEN);
                    }
                }
            } else {
                $errorMsg = "❌ Failed to download image. Please try again.";
                if ($msgId && is_numeric($msgId)) {
                    editTelegramMessage($chatId, $msgId, $errorMsg, $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, $errorMsg, $TELEGRAM_BOT_TOKEN);
                }
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle text messages
        if (!empty($text)) {
            // Check if user is awaiting name input
            $prefs = getUserPreferences($userId);
            if (isset($prefs['awaiting_name']) && $prefs['awaiting_name'] === true) {
                // Validate and save the name
                $name = trim($text);
                
                // Basic validation
                if (strlen($name) > 50) {
                    sendTelegramMessage($chatId, "❌ Name is too long. Please keep it under 50 characters.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                if (empty($name)) {
                    sendTelegramMessage($chatId, "❌ Please provide a valid name.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                // Save the name and ask for nationality
                $prefs['name'] = $name;
                $prefs['awaiting_name'] = false;
                $prefs['awaiting_nationality'] = true;
                saveUserPreferences($userId, $prefs);
                
                sendTelegramMessage($chatId, "✅ Nice to meet you, <b>$name</b>!\n\n🌍 <b>Step 2:</b> What's your nationality/country?\n\n💬 <i>Example: Kenya, USA, UK, India, etc.</i>", $TELEGRAM_BOT_TOKEN);
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Check if user is awaiting nationality input
            if (isset($prefs['awaiting_nationality']) && $prefs['awaiting_nationality'] === true) {
                $nationality = trim($text);
                
                if (strlen($nationality) > 50) {
                    sendTelegramMessage($chatId, "❌ Please provide a shorter nationality name.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                if (empty($nationality)) {
                    sendTelegramMessage($chatId, "❌ Please provide a valid nationality.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                // Auto-detect country and get flag emoji
                $countryEmoji = getCountryFlagEmoji($nationality);
                
                // Save nationality and country emoji
                $prefs['nationality'] = ucfirst($nationality);
                $prefs['country_emoji'] = $countryEmoji;
                $prefs['awaiting_nationality'] = false;
                saveUserPreferences($userId, $prefs);
                
                // Send complete welcome message
                $welcomeMsg = "✅ <b>Profile Complete!</b>\n";
                $welcomeMsg .= str_repeat("━", 28) . "\n\n";
                $welcomeMsg .= "✅ <b>Name:</b> {$prefs['name']}\n";
                $welcomeMsg .= "🌍 <b>Nationality:</b> $countryEmoji {$prefs['nationality']}\n";
                $welcomeMsg .= "🆔 <b>Telegram ID:</b> <code>$userId</code>\n\n";
                $welcomeMsg .= "<b>🎉 What I can do for you:</b>\n";
                $welcomeMsg .= "🧠 Answer questions intelligently\n";
                $welcomeMsg .= "📸 Analyze images\n";
                $welcomeMsg .= "🌐 Translate languages\n";
                $welcomeMsg .= "🔍 Search the web\n";
                $welcomeMsg .= "💭 Remember our conversations\n";
                $welcomeMsg .= "🎭 Adapt to your preferred style\n\n";
                $welcomeMsg .= "💬 <i>Try asking me anything to get started!</i>\n\n";
                $welcomeMsg .= "Use /help to see all commands or /myinfo to view your profile.";
                
                sendTelegramMessage($chatId, $welcomeMsg, $TELEGRAM_BOT_TOKEN);
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            $cleanText = preg_replace('/@ai\s*/i', '', $text);
            $cleanText = preg_replace('/^\/ai\s*/i', '', $cleanText);
            $cleanText = trim($cleanText);
            
            if (empty($cleanText)) {
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Check for natural language search requests
            $searchQuery = parseSearchCommand($cleanText);
            if ($searchQuery) {
                sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
                $msgId = sendTelegramMessage($chatId, "🔍 <b>Searching</b>●●●", $TELEGRAM_BOT_TOKEN);
                
                $results = webSearch($searchQuery);
                $response = formatSearchResults($results, $searchQuery);
                
                if ($msgId && is_numeric($msgId)) {
                    editTelegramMessage($chatId, $msgId, $response, $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, $response, $TELEGRAM_BOT_TOKEN);
                }
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Check for remember/memory commands in natural language
            $rememberParsed = parseRememberCommand($cleanText);
            if ($rememberParsed) {
                if ($rememberParsed['type'] === 'name') {
                    updateUserPreference($userId, 'name', $rememberParsed['value']);
                    sendTelegramMessage($chatId, formatRichResponse("I'll remember your name is {$rememberParsed['value']}!", 'memory'), $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                } elseif ($rememberParsed['type'] === 'language') {
                    updateUserPreference($userId, 'preferred_language', ucfirst($rememberParsed['value']));
                    sendTelegramMessage($chatId, formatRichResponse("I'll respond in {$rememberParsed['value']} from now on!", 'memory'), $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
            }
            
            sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
            
            // Send animated analyzing message
            $msgId = sendTelegramMessage($chatId, "🧠 <b>Analyzing</b>●", $TELEGRAM_BOT_TOKEN);
            
            if ($msgId && is_numeric($msgId)) {
                $dots = ['●', '●●', '●●●'];
                foreach ($dots as $dot) {
                    usleep(300000);
                    editTelegramMessage($chatId, $msgId, "🧠 <b>Analyzing</b>" . $dot, $TELEGRAM_BOT_TOKEN);
                }
            }
            
            // Get reply context for groups
            $replyContext = getMessageHistory($message, $TELEGRAM_BOT_TOKEN);
            
            // BUILD FULL CONTEXT: Reply context + Conversation history + User preferences + Personality
            $conversationContext = formatConversationForContext($userId, 6);
            $userPrefsContext = formatPreferencesForContext($userId);
            $personality = getUserPersonality($userId);
            $personalityPrompt = getPersonalityPrompt($personality);
            
            // Combine all context
            $fullContext = "";
            if (!empty($replyContext)) {
                $fullContext .= $replyContext;
            }
            if (!empty($userPrefsContext)) {
                $fullContext .= "User information: $userPrefsContext\n\n";
            }
            if (!empty($conversationContext)) {
                $fullContext .= $conversationContext;
            }
            $fullContext .= "Personality: $personalityPrompt";
            
            $response = getAIResponse($userId, $cleanText, $fullContext);
            
            // Format response with rich styling
            $finalResponse = formatRichResponse($response, 'ai');
            
            // Stream the response progressively for long responses
            if ($msgId && is_numeric($msgId)) {
                if (strlen($response) > 500) {
                    sendStreamingResponse($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN, $msgId);
                } else {
                    editTelegramMessage($chatId, $msgId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                }
            } else {
                sendTelegramMessage($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN);
            }
            
            // Save to conversation history
            saveConversationMessage($userId, 'user', $cleanText);
            saveConversationMessage($userId, 'assistant', $response);
            
            // Update last active
            $prefs = getUserPreferences($userId);
            saveUserPreferences($userId, $prefs);
        }
        
        http_response_code(200);
        exit(json_encode(['status' => 'ok']));
    }
    
    // Analytics API - requires ADMIN_TOKEN
    if ($path === '/analytics.php' && $method === 'GET') {
        $adminToken = getenv('ADMIN_TOKEN') ?: '';
        $providedToken = $_GET['token'] ?? '';
        if (empty($adminToken) || $providedToken !== $adminToken) {
            http_response_code(403);
            exit(json_encode(['error' => 'Unauthorized. Admin token required.']));
        }
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
    
    // Users API - requires ADMIN_TOKEN
    if ($path === '/users_api.php' && $method === 'GET') {
        $adminToken = getenv('ADMIN_TOKEN') ?: '';
        $providedToken = $_GET['token'] ?? '';
        if (empty($adminToken) || $providedToken !== $adminToken) {
            http_response_code(403);
            exit(json_encode(['error' => 'Unauthorized. Admin token required.']));
        }
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
    
    // Conversations API - requires ADMIN_TOKEN
    if ($path === '/conversations_api.php' && $method === 'GET') {
        $adminToken = getenv('ADMIN_TOKEN') ?: '';
        $providedToken = $_GET['token'] ?? '';
        if (empty($adminToken) || $providedToken !== $adminToken) {
            http_response_code(403);
            exit(json_encode(['error' => 'Unauthorized. Admin token required.']));
        }
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
    
    // System API - requires ADMIN_TOKEN
    if ($path === '/system_api.php' && $method === 'GET') {
        $adminToken = getenv('ADMIN_TOKEN') ?: '';
        $providedToken = $_GET['token'] ?? '';
        if (empty($adminToken) || $providedToken !== $adminToken) {
            http_response_code(403);
            exit(json_encode(['error' => 'Unauthorized. Admin token required.']));
        }
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
