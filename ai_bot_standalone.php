<?php
/**
 * ========================================================================
 * ADVANCED AI BOT - ENTERPRISE EDITION
 * ========================================================================
 * Complete Telegram AI Bot with Advanced Features
 * 
 * NEW FEATURES:
 * - Donation System (Ko-fi + Telegram Stars)
 * - Advanced AI Translation Engine
 * - Dynamic AI Personality Engine
 * - Comprehensive Analytics & Insights
 * - Advanced Admin Security System
 * - Real-time Notification System
 * - Emergency & Maintenance Mode
 * - User Behavior Analytics
 * - Feature Flag Management
 * - Mood Detection Engine
 * - IP & Device Tracking
 * - Real-time Monitoring Dashboard
 * - Mandatory Information Gathering
 * - Group Admin Privilege Enforcement
 * - Auto-reply System
 * - Content Moderation
 * - Rate Limit Tiers
 * - Session Management
 * - Webhook Security
 * - Advanced Caching Strategy
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
$ADMIN_TOKEN = getenv('ADMIN_TOKEN') ?: bin2hex(random_bytes(16));
$BOT_OWNER_ID = getenv('BOT_OWNER_ID') ?: $ADMIN_USER_ID;
$WEBHOOK_SECRET = getenv('WEBHOOK_SECRET') ?: bin2hex(random_bytes(32));

// Data directories
define('AI_DATA_DIR', __DIR__ . '/ai_data');
define('AI_CONVERSATIONS_DIR', AI_DATA_DIR . '/conversations');
define('AI_CACHE_DIR', AI_DATA_DIR . '/cache');
define('AI_STATS_DIR', AI_DATA_DIR . '/stats');
define('AI_USERS_DIR', AI_DATA_DIR . '/users');
define('AI_PERSONALITY_DIR', AI_DATA_DIR . '/personality');
define('AI_PREFERENCES_DIR', AI_DATA_DIR . '/preferences');
define('AI_ADMIN_DIR', AI_DATA_DIR . '/admin');
define('AI_DONATIONS_DIR', AI_DATA_DIR . '/donations');
define('AI_ANALYTICS_DIR', AI_DATA_DIR . '/analytics');
define('AI_MONITORING_DIR', AI_DATA_DIR . '/monitoring');
define('AI_SESSIONS_DIR', AI_DATA_DIR . '/sessions');
define('AI_DEVICE_DIR', AI_DATA_DIR . '/devices');
define('AI_FLAGS_DIR', AI_DATA_DIR . '/flags');
define('AI_NOTIFICATIONS_DIR', AI_DATA_DIR . '/notifications');
define('AI_MODERATION_DIR', AI_DATA_DIR . '/moderation');

// Initialize directories
$dirs = [
    AI_DATA_DIR, AI_CONVERSATIONS_DIR, AI_CACHE_DIR, AI_STATS_DIR, 
    AI_USERS_DIR, AI_PERSONALITY_DIR, AI_PREFERENCES_DIR, AI_ADMIN_DIR,
    AI_DONATIONS_DIR, AI_ANALYTICS_DIR, AI_MONITORING_DIR, AI_SESSIONS_DIR,
    AI_DEVICE_DIR, AI_FLAGS_DIR, AI_NOTIFICATIONS_DIR, AI_MODERATION_DIR
];

foreach ($dirs as $dir) {
    @mkdir($dir, 0755, true);
}

// ============================================================================
// SYSTEM STATUS & MAINTENANCE MODE
// ============================================================================

function getSystemStatus() {
    $statusFile = AI_ADMIN_DIR . '/system_status.json';
    if (!file_exists($statusFile)) {
        $defaultStatus = [
            'maintenance_mode' => false,
            'emergency_mode' => false,
            'status' => 'operational',
            'message' => '',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        aiSaveJSON($statusFile, $defaultStatus);
        return $defaultStatus;
    }
    return aiLoadJSON($statusFile);
}

function setMaintenanceMode($enabled, $message = '') {
    $statusFile = AI_ADMIN_DIR . '/system_status.json';
    $status = getSystemStatus();
    $status['maintenance_mode'] = $enabled;
    $status['message'] = $message;
    $status['updated_at'] = date('Y-m-d H:i:s');
    return aiSaveJSON($statusFile, $status);
}

function setEmergencyMode($enabled, $message = '') {
    $statusFile = AI_ADMIN_DIR . '/system_status.json';
    $status = getSystemStatus();
    $status['emergency_mode'] = $enabled;
    $status['message'] = $message;
    $status['updated_at'] = date('Y-m-d H:i:s');
    return aiSaveJSON($statusFile, $status);
}

function isSystemOperational() {
    $status = getSystemStatus();
    return !$status['maintenance_mode'] && !$status['emergency_mode'];
}

// ============================================================================
// FEATURE FLAGS MANAGEMENT
// ============================================================================

function getFeatureFlags() {
    $flagsFile = AI_FLAGS_DIR . '/features.json';
    if (!file_exists($flagsFile)) {
        $defaultFlags = [
            'donations_enabled' => true,
            'translations_enabled' => true,
            'web_search_enabled' => true,
            'image_analysis_enabled' => true,
            'mood_detection_enabled' => true,
            'analytics_enabled' => true,
            'group_chat_enabled' => true,
            'voice_messages_enabled' => false,
            'document_analysis_enabled' => false,
            'auto_reply_enabled' => false
        ];
        aiSaveJSON($flagsFile, $defaultFlags);
        return $defaultFlags;
    }
    return aiLoadJSON($flagsFile);
}

function isFeatureEnabled($feature) {
    $flags = getFeatureFlags();
    return $flags[$feature] ?? false;
}

function setFeatureFlag($feature, $enabled) {
    $flagsFile = AI_FLAGS_DIR . '/features.json';
    $flags = getFeatureFlags();
    $flags[$feature] = $enabled;
    return aiSaveJSON($flagsFile, $flags);
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

function aiLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message";
    error_log($logEntry);
    
    // Also save to monitoring file
    $monitoringFile = AI_MONITORING_DIR . '/logs_' . date('Y-m-d') . '.json';
    $logs = file_exists($monitoringFile) ? aiLoadJSON($monitoringFile) : [];
    $logs[] = [
        'timestamp' => $timestamp,
        'level' => $level,
        'message' => $message
    ];
    if (count($logs) > 1000) {
        $logs = array_slice($logs, -1000);
    }
    aiSaveJSON($monitoringFile, $logs);
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
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

// ============================================================================
// ADMIN & SECURITY FUNCTIONS
// ============================================================================

function isAdmin($userId) {
    global $ADMIN_USER_ID;
    if (empty($ADMIN_USER_ID)) return false;
    $adminIds = array_map('trim', explode(',', $ADMIN_USER_ID));
    return in_array((string)$userId, $adminIds);
}

function isBotOwner($userId) {
    global $BOT_OWNER_ID;
    return (string)$userId === (string)$BOT_OWNER_ID;
}

function getAdminPermissions($userId) {
    $permFile = AI_ADMIN_DIR . '/permissions.json';
    $permissions = file_exists($permFile) ? aiLoadJSON($permFile) : [];
    return $permissions[(string)$userId] ?? [
        'can_block_users' => false,
        'can_broadcast' => false,
        'can_view_analytics' => false,
        'can_manage_system' => false,
        'can_moderate_content' => false
    ];
}

function setAdminPermissions($userId, $permissions) {
    $permFile = AI_ADMIN_DIR . '/permissions.json';
    $allPerms = file_exists($permFile) ? aiLoadJSON($permFile) : [];
    $allPerms[(string)$userId] = $permissions;
    return aiSaveJSON($permFile, $allPerms);
}

function hasPermission($userId, $permission) {
    if (isBotOwner($userId)) return true;
    if (!isAdmin($userId)) return false;
    $perms = getAdminPermissions($userId);
    return $perms[$permission] ?? false;
}

// ============================================================================
// IP & DEVICE TRACKING
// ============================================================================

function getClientIP() {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
             'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (isset($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return 'Unknown';
}

function getUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

function trackDeviceInfo($userId, $message) {
    $deviceFile = AI_DEVICE_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $deviceData = file_exists($deviceFile) ? aiLoadJSON($deviceFile) : [];
    
    $currentDevice = [
        'ip' => getClientIP(),
        'user_agent' => getUserAgent(),
        'platform' => $message['from']['language_code'] ?? 'unknown',
        'first_seen' => $deviceData['first_seen'] ?? date('Y-m-d H:i:s'),
        'last_seen' => date('Y-m-d H:i:s'),
        'request_count' => ($deviceData['request_count'] ?? 0) + 1
    ];
    
    aiSaveJSON($deviceFile, $currentDevice);
    return $currentDevice;
}

// ============================================================================
// SESSION MANAGEMENT
// ============================================================================

function createSession($userId) {
    $sessionId = bin2hex(random_bytes(32));
    $sessionFile = AI_SESSIONS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $sessionData = [
        'session_id' => $sessionId,
        'user_id' => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        'active' => true
    ];
    aiSaveJSON($sessionFile, $sessionData);
    return $sessionId;
}

function getSession($userId) {
    $sessionFile = AI_SESSIONS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    if (!file_exists($sessionFile)) return null;
    $session = aiLoadJSON($sessionFile);
    if (strtotime($session['expires_at']) < time()) {
        return null;
    }
    return $session;
}

// ============================================================================
// NOTIFICATION SYSTEM
// ============================================================================

function sendNotificationToOwner($message, $priority = 'normal') {
    global $BOT_OWNER_ID, $TELEGRAM_BOT_TOKEN;
    
    if (empty($BOT_OWNER_ID) || empty($TELEGRAM_BOT_TOKEN)) return false;
    
    $emoji = [
        'critical' => '🚨',
        'high' => '⚠️',
        'normal' => 'ℹ️',
        'low' => '💬'
    ];
    
    $icon = $emoji[$priority] ?? 'ℹ️';
    $notifMsg = "$icon <b>[Bot Notification]</b>\n\n$message\n\n⏰ " . date('Y-m-d H:i:s');
    
    return sendTelegramMessage($BOT_OWNER_ID, $notifMsg, $TELEGRAM_BOT_TOKEN);
}

function logNotification($userId, $type, $message) {
    $notifFile = AI_NOTIFICATIONS_DIR . '/notifications_' . date('Y-m-d') . '.json';
    $notifications = file_exists($notifFile) ? aiLoadJSON($notifFile) : [];
    
    $notifications[] = [
        'user_id' => $userId,
        'type' => $type,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (count($notifications) > 500) {
        $notifications = array_slice($notifications, -500);
    }
    
    aiSaveJSON($notifFile, $notifications);
}

// ============================================================================
// DONATION SYSTEM
// ============================================================================

function getDonationStats() {
    $donationFile = AI_DONATIONS_DIR . '/donations.json';
    if (!file_exists($donationFile)) {
        return [
            'total_amount' => 0,
            'total_stars' => 0,
            'donation_count' => 0,
            'top_donors' => []
        ];
    }
    return aiLoadJSON($donationFile);
}

function recordDonation($userId, $amount, $type = 'kofi', $stars = 0) {
    $donationFile = AI_DONATIONS_DIR . '/donations.json';
    $stats = getDonationStats();
    
    if ($type === 'stars') {
        $stats['total_stars'] += $stars;
    } else {
        $stats['total_amount'] += $amount;
    }
    
    $stats['donation_count']++;
    
    if (!isset($stats['top_donors'][$userId])) {
        $stats['top_donors'][$userId] = [
            'total' => 0,
            'stars' => 0,
            'count' => 0
        ];
    }
    
    $stats['top_donors'][$userId]['total'] += $amount;
    $stats['top_donors'][$userId]['stars'] += $stars;
    $stats['top_donors'][$userId]['count']++;
    
    aiSaveJSON($donationFile, $stats);
    
    // Log individual donation
    $userDonationFile = AI_DONATIONS_DIR . '/user_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $userDonations = file_exists($userDonationFile) ? aiLoadJSON($userDonationFile) : [];
    
    $userDonations[] = [
        'amount' => $amount,
        'stars' => $stars,
        'type' => $type,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    aiSaveJSON($userDonationFile, $userDonations);
    
    // Notify owner
    $userName = getUserPreferences($userId)['name'] ?? "User $userId";
    if ($type === 'stars') {
        sendNotificationToOwner("💰 New donation! $userName sent $stars Telegram Stars!", 'high');
    } else {
        sendNotificationToOwner("💰 New Ko-fi donation recorded for $userName: $$amount", 'high');
    }
    
    return true;
}

// ============================================================================
// TELEGRAM API FUNCTIONS
// ============================================================================

function sendChatAction($chatId, $action, $botToken) {
    if (empty($botToken)) return false;
    $validActions = ['typing', 'upload_photo', 'record_video', 'upload_video', 'record_audio', 'upload_audio', 'upload_document', 'find_location'];
    if (!in_array($action, $validActions)) $action = 'typing';
    
    $url = "https://api.telegram.org/bot{$botToken}/sendChatAction";
    $data = ['chat_id' => $chatId, 'action' => $action];
    
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

function sendTelegramMessage($chatId, $text, $botToken, $replyMarkup = null, $retries = 3) {
    if (empty($botToken)) return false;
    
    $maxLength = 4096;
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength - 3) . '...';
    }
    
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($replyMarkup) {
        $data['reply_markup'] = $replyMarkup;
    }
    
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
            usleep(1000000);
            continue;
        }
        
        if ($httpCode !== 429) break;
    }
    
    return false;
}

function editTelegramMessage($chatId, $messageId, $text, $botToken) {
    if (empty($botToken) || empty($messageId)) return false;
    
    $url = "https://api.telegram.org/bot{$botToken}/editMessageText";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
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

function getChatMember($chatId, $userId, $botToken) {
    $url = "https://api.telegram.org/bot{$botToken}/getChatMember";
    $data = ['chat_id' => $chatId, 'user_id' => $userId];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function getBotInfo($botToken) {
    $url = "https://api.telegram.org/bot{$botToken}/getMe";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    return $result['result'] ?? null;
}

// ============================================================================
// GROUP CHAT SUPPORT WITH ADMIN ENFORCEMENT
// ============================================================================

function isGroupChat($message) {
    $chatType = $message['chat']['type'] ?? '';
    return in_array($chatType, ['group', 'supergroup']);
}

function isBotMentioned($text) {
    if (empty($text)) return false;
    return (preg_match('/@ai\b/i', $text) > 0) || (preg_match('/^\/ai\b/i', $text) > 0);
}

function isBotAdmin($chatId, $botToken) {
    $botInfo = getBotInfo($botToken);
    if (!$botInfo) return false;
    
    $botId = $botInfo['id'];
    $member = getChatMember($chatId, $botId, $botToken);
    
    if (!isset($member['result'])) return false;
    
    $status = $member['result']['status'] ?? '';
    return in_array($status, ['administrator', 'creator']);
}

function requestAdminPrivileges($chatId, $botToken) {
    $message = "⚠️ <b>Admin Privileges Required</b>\n\n";
    $message .= "To function properly in this group, I need administrator privileges with the following permissions:\n\n";
    $message .= "✅ Delete messages\n";
    $message .= "✅ Ban users (for moderation)\n";
    $message .= "✅ Pin messages\n";
    $message .= "✅ Manage group\n\n";
    $message .= "Please promote me to admin with these permissions for full functionality.";
    
    return sendTelegramMessage($chatId, $message, $botToken);
}

function handleNewGroupJoin($message, $botToken) {
    global $BOT_OWNER_ID;
    
    $chatId = $message['chat']['id'];
    $chatTitle = $message['chat']['title'] ?? 'Unknown Group';
    $addedBy = $message['from']['id'] ?? 'Unknown';
    $addedByName = $message['from']['first_name'] ?? 'Unknown User';
    
    // Check if bot has admin privileges
    if (!isBotAdmin($chatId, $botToken)) {
        requestAdminPrivileges($chatId, $botToken);
        
        // Notify owner
        $notifMsg = "🆕 Added to new group!\n\n";
        $notifMsg .= "📝 Group: $chatTitle\n";
        $notifMsg .= "🆔 Chat ID: $chatId\n";
        $notifMsg .= "👤 Added by: $addedByName (ID: $addedBy)\n";
        $notifMsg .= "⚠️ Status: Waiting for admin privileges";
        
        sendNotificationToOwner($notifMsg, 'high');
    } else {
        // Send welcome message
        $welcomeMsg = "👋 <b>Hello everyone!</b>\n\n";
        $welcomeMsg .= "I'm your AI assistant! I can help with:\n\n";
        $welcomeMsg .= "🤖 Intelligent conversations\n";
        $welcomeMsg .= "📸 Image analysis\n";
        $welcomeMsg .= "🌐 Translations\n";
        $welcomeMsg .= "🔍 Web search\n";
        $welcomeMsg .= "📊 And much more!\n\n";
        $welcomeMsg .= "Mention me with @ai or reply to my messages to chat!";
        
        sendTelegramMessage($chatId, $welcomeMsg, $botToken);
        
        // Notify owner
        $notifMsg = "🆕 Added to new group!\n\n";
        $notifMsg .= "📝 Group: $chatTitle\n";
        $notifMsg .= "🆔 Chat ID: $chatId\n";
        $notifMsg .= "👤 Added by: $addedByName (ID: $addedBy)\n";
        $notifMsg .= "✅ Status: Admin privileges granted";
        
        sendNotificationToOwner($notifMsg, 'normal');
    }
}

function shouldProcessGroupMessage($message, $botToken) {
    if (!isGroupChat($message)) return true;
    
    $text = $message['text'] ?? '';
    
    if (isBotMentioned($text)) return true;
    if (stripos($text, '/ai') === 0) return true;
    
    if (isset($message['reply_to_message'])) {
        $reply = $message['reply_to_message'];
        $fromUser = $reply['from'] ?? [];
        if (($fromUser['is_bot'] ?? false)) {
            return true;
        }
    }
    
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
        
        if (empty($replyText) && isset($reply['caption'])) {
            $replyText = $reply['caption'];
        }
        
        $hasPhoto = isset($reply['photo']) ? '[📸 Photo] ' : '';
        
        if (empty($replyText)) {
            $replyText = '[No text content]';
        } else {
            $replyText = substr($replyText, 0, 500);
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
// USER PREFERENCES SYSTEM (Enhanced with Mandatory Info)
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
            'profile_complete' => false,
            'awaiting_name' => false,
            'awaiting_nationality' => false,
            'created' => date('Y-m-d H:i:s'),
            'last_active' => date('Y-m-d H:i:s')
        ];
    }
    $data = aiLoadJSON($file);
    if (!isset($data['telegram_id'])) $data['telegram_id'] = $userId;
    if (!isset($data['nationality'])) $data['nationality'] = null;
    if (!isset($data['country_emoji'])) $data['country_emoji'] = null;
    if (!isset($data['profile_complete'])) $data['profile_complete'] = false;
    if (!isset($data['awaiting_name'])) $data['awaiting_name'] = false;
    if (!isset($data['awaiting_nationality'])) $data['awaiting_nationality'] = false;
    return is_array($data) ? $data : getUserPreferences('default');
}

function isProfileComplete($userId) {
    $prefs = getUserPreferences($userId);
    return !empty($prefs['name']) && !empty($prefs['nationality']) && $prefs['profile_complete'] === true;
}

function getCountryFlagEmoji($nationality) {
    $countryFlags = [
        'kenya' => '🇰🇪', 'usa' => '🇺🇸', 'united states' => '🇺🇸', 'america' => '🇺🇸',
        'uk' => '🇬🇧', 'united kingdom' => '🇬🇧', 'britain' => '🇬🇧', 'england' => '🇬🇧',
        'india' => '🇮🇳', 'china' => '🇨🇳', 'japan' => '🇯🇵', 'nigeria' => '🇳🇬',
        'germany' => '🇩🇪', 'france' => '🇫🇷', 'italy' => '🇮🇹', 'spain' => '🇪🇸',
        'brazil' => '🇧🇷', 'canada' => '🇨🇦', 'australia' => '🇦🇺', 'mexico' => '🇲🇽',
        'russia' => '🇷🇺', 'south korea' => '🇰🇷', 'korea' => '🇰🇷', 'south africa' => '🇿🇦',
        'egypt' => '🇪🇬', 'pakistan' => '🇵🇰', 'bangladesh' => '🇧🇩', 'philippines' => '🇵🇭',
        'vietnam' => '🇻🇳', 'thailand' => '🇹🇭', 'indonesia' => '🇮🇩', 'turkey' => '🇹🇷',
        'saudi arabia' => '🇸🇦', 'uae' => '🇦🇪', 'argentina' => '🇦🇷', 'colombia' => '🇨🇴',
        'chile' => '🇨🇱', 'poland' => '🇵🇱', 'ukraine' => '🇺🇦', 'netherlands' => '🇳🇱',
        'belgium' => '🇧🇪', 'sweden' => '🇸🇪', 'norway' => '🇳🇴', 'denmark' => '🇩🇰',
        'finland' => '🇫🇮', 'portugal' => '🇵🇹', 'greece' => '🇬🇷', 'switzerland' => '🇨🇭',
        'austria' => '🇦🇹', 'ireland' => '🇮🇪', 'new zealand' => '🇳🇿', 'singapore' => '🇸🇬',
        'malaysia' => '🇲🇾', 'israel' => '🇮🇱', 'iran' => '🇮🇷', 'iraq' => '🇮🇶',
        'morocco' => '🇲🇦', 'algeria' => '🇩🇿', 'tunisia' => '🇹🇳', 'libya' => '🇱🇾',
        'ethiopia' => '🇪🇹', 'ghana' => '🇬🇭', 'tanzania' => '🇹🇿', 'uganda' => '🇺🇬',
        'rwanda' => '🇷🇼', 'zimbabwe' => '🇿🇼', 'zambia' => '🇿🇲', 'botswana' => '🇧🇼'
    ];
    
    $nationalityLower = strtolower(trim($nationality));
    return $countryFlags[$nationalityLower] ?? '🌍';
}

function saveUserPreferences($userId, $preferences) {
    $file = AI_PREFERENCES_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $preferences['last_active'] = date('Y-m-d H:i:s');
    return aiSaveJSON($file, $preferences);
}

function updateUserPreference($userId, $key, $value) {
    $prefs = getUserPreferences($userId);
    $prefs[$key] = $value;
    return saveUserPreferences($userId, $prefs);
}

function formatPreferencesForContext($userId) {
    $prefs = getUserPreferences($userId);
    $context = "";
    
    if (!empty($prefs['name'])) {
        $context .= "User's name is {$prefs['name']}. Always address them by their name when appropriate. ";
    }
    if (!empty($prefs['nationality'])) {
        $context .= "User is from {$prefs['nationality']}. ";
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

// ============================================================================
// MOOD DETECTION ENGINE
// ============================================================================

function detectMood($text) {
    $text = strtolower($text);
    
    $moodKeywords = [
        'happy' => ['happy', 'great', 'awesome', 'wonderful', 'fantastic', 'excellent', 'love', 'excited', '😊', '😄', '😃', '🎉', '❤️'],
        'sad' => ['sad', 'depressed', 'down', 'unhappy', 'crying', 'terrible', 'awful', 'horrible', '😢', '😭', '💔'],
        'angry' => ['angry', 'mad', 'furious', 'annoyed', 'frustrated', 'pissed', 'hate', '😠', '😡', '🤬'],
        'anxious' => ['anxious', 'worried', 'nervous', 'stressed', 'scared', 'afraid', 'concerned', '😰', '😨'],
        'confused' => ['confused', 'lost', 'don\'t understand', 'unclear', 'puzzled', '🤔', '😕'],
        'excited' => ['excited', 'thrilled', 'pumped', 'eager', 'can\'t wait', '🤩', '😍'],
        'neutral' => []
    ];
    
    $moodScores = [];
    foreach ($moodKeywords as $mood => $keywords) {
        $score = 0;
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                $score++;
            }
        }
        $moodScores[$mood] = $score;
    }
    
    arsort($moodScores);
    $detectedMood = array_key_first($moodScores);
    $confidence = $moodScores[$detectedMood] > 0 ? min(100, $moodScores[$detectedMood] * 25) : 0;
    
    return [
        'mood' => $detectedMood,
        'confidence' => $confidence,
        'scores' => $moodScores
    ];
}

function logMood($userId, $mood, $confidence) {
    $moodFile = AI_ANALYTICS_DIR . '/moods_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $moods = file_exists($moodFile) ? aiLoadJSON($moodFile) : [];
    
    $moods[] = [
        'mood' => $mood,
        'confidence' => $confidence,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (count($moods) > 100) {
        $moods = array_slice($moods, -100);
    }
    
    aiSaveJSON($moodFile, $moods);
}

function adjustResponseByMood($response, $mood) {
    $moodPrefixes = [
        'sad' => "I sense you might be feeling down. ",
        'angry' => "I understand you might be frustrated. ",
        'anxious' => "I can see this might be concerning for you. ",
        'happy' => "I'm glad to hear you're in good spirits! ",
        'excited' => "I love your enthusiasm! "
    ];
    
    if (isset($moodPrefixes[$mood]) && $mood !== 'neutral') {
        return $moodPrefixes[$mood] . $response;
    }
    
    return $response;
}

// ============================================================================
// ADVANCED TRANSLATION ENGINE
// ============================================================================

function translateText($text, $targetLanguage, $apiKey) {
    if (empty($apiKey) || empty($text)) return null;
    
    $prompt = "Translate the following text to $targetLanguage. Only provide the translation, no explanations or additional text:\n\n$text";
    
    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.0-flash:generateContent?key={$apiKey}";
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

function detectLanguage($text, $apiKey) {
    if (empty($apiKey) || empty($text)) return 'unknown';
    
    $prompt = "Detect the language of this text and respond with ONLY the language name in English (e.g., 'Spanish', 'French', 'Arabic'). Text: $text";
    
    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    $data = ['contents' => [['parts' => [['text' => $prompt]]]]];
    
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
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($result['candidates'][0]['content']['parts'][0]['text']);
        }
    }
    return 'unknown';
}

// ============================================================================
// DYNAMIC AI PERSONALITY ENGINE
// ============================================================================

function getPersonalityOptions() {
    return [
        'professional' => [
            'tone' => 'formal and precise',
            'style' => 'structured and detailed',
            'emoji_usage' => 'minimal',
            'description' => 'Professional, formal, and accurate'
        ],
        'casual' => [
            'tone' => 'friendly and relaxed',
            'style' => 'conversational and warm',
            'emoji_usage' => 'moderate',
            'description' => 'Friendly, casual, and conversational'
        ],
        'humorous' => [
            'tone' => 'witty and entertaining',
            'style' => 'playful with jokes',
            'emoji_usage' => 'frequent',
            'description' => 'Funny and entertaining while helpful'
        ],
        'technical' => [
            'tone' => 'expert and analytical',
            'style' => 'detailed with terminology',
            'emoji_usage' => 'rare',
            'description' => 'Technical terminology and detailed explanations'
        ],
        'simple' => [
            'tone' => 'clear and straightforward',
            'style' => 'easy to understand',
            'emoji_usage' => 'helpful',
            'description' => 'Simple and clear for beginners'
        ],
        'empathetic' => [
            'tone' => 'caring and understanding',
            'style' => 'supportive and warm',
            'emoji_usage' => 'heartfelt',
            'description' => 'Empathetic and emotionally supportive'
        ],
        'creative' => [
            'tone' => 'imaginative and inspired',
            'style' => 'artistic and unique',
            'emoji_usage' => 'expressive',
            'description' => 'Creative and imaginative approaches'
        ]
    ];
}

function getUserPersonality($userId) {
    $file = AI_PERSONALITY_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    if (!file_exists($file)) {
        return [
            'tone' => 'professional',
            'style' => 'balanced',
            'custom_instructions' => ''
        ];
    }
    $data = aiLoadJSON($file);
    return is_array($data) ? $data : ['tone' => 'professional', 'style' => 'balanced', 'custom_instructions' => ''];
}

function setUserPersonality($userId, $tone, $style, $customInstructions = '') {
    $file = AI_PERSONALITY_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = [
        'tone' => $tone,
        'style' => $style,
        'custom_instructions' => $customInstructions,
        'updated' => date('Y-m-d H:i:s')
    ];
    aiSaveJSON($file, $data);
    return true;
}

function getPersonalityPrompt($personality) {
    $options = getPersonalityOptions();
    $selectedOption = $options[$personality['tone']] ?? $options['professional'];
    
    $prompt = "Respond with a {$selectedOption['tone']} tone. ";
    $prompt .= "Use a {$selectedOption['style']} writing style. ";
    $prompt .= "Emoji usage: {$selectedOption['emoji_usage']}. ";
    
    if (!empty($personality['custom_instructions'])) {
        $prompt .= "Additional instructions: {$personality['custom_instructions']} ";
    }
    
    return $prompt;
}

// ============================================================================
// ANALYTICS & USER BEHAVIOR INSIGHTS
// ============================================================================

function logUserBehavior($userId, $action, $metadata = []) {
    $behaviorFile = AI_ANALYTICS_DIR . '/behavior_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $behaviors = file_exists($behaviorFile) ? aiLoadJSON($behaviorFile) : [];
    
    $behaviors[] = [
        'action' => $action,
        'metadata' => $metadata,
        'timestamp' => date('Y-m-d H:i:s'),
        'hour' => (int)date('H'),
        'day_of_week' => date('l')
    ];
    
    if (count($behaviors) > 500) {
        $behaviors = array_slice($behaviors, -500);
    }
    
    aiSaveJSON($behaviorFile, $behaviors);
}

function getUserBehaviorInsights($userId) {
    $behaviorFile = AI_ANALYTICS_DIR . '/behavior_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    if (!file_exists($behaviorFile)) return null;
    
    $behaviors = aiLoadJSON($behaviorFile);
    if (empty($behaviors)) return null;
    
    $insights = [
        'total_actions' => count($behaviors),
        'most_active_hour' => 0,
        'most_active_day' => '',
        'action_distribution' => [],
        'average_session_length' => 0,
        'last_30_days_activity' => 0
    ];
    
    $hourCounts = array_fill(0, 24, 0);
    $dayCounts = [];
    $actionCounts = [];
    
    $thirtyDaysAgo = strtotime('-30 days');
    $recentActivity = 0;
    
    foreach ($behaviors as $behavior) {
        $hour = $behavior['hour'] ?? 0;
        $day = $behavior['day_of_week'] ?? 'Unknown';
        $action = $behavior['action'] ?? 'unknown';
        
        $hourCounts[$hour]++;
        $dayCounts[$day] = ($dayCounts[$day] ?? 0) + 1;
        $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;
        
        if (strtotime($behavior['timestamp']) > $thirtyDaysAgo) {
            $recentActivity++;
        }
    }
    
    $insights['most_active_hour'] = array_keys($hourCounts, max($hourCounts))[0];
    arsort($dayCounts);
    $insights['most_active_day'] = array_key_first($dayCounts) ?? 'Unknown';
    $insights['action_distribution'] = $actionCounts;
    $insights['last_30_days_activity'] = $recentActivity;
    
    return $insights;
}

function getSystemAnalytics() {
    $analytics = [
        'total_users' => 0,
        'active_users_today' => 0,
        'active_users_week' => 0,
        'active_users_month' => 0,
        'total_messages' => 0,
        'total_conversations' => 0,
        'avg_messages_per_user' => 0,
        'popular_features' => [],
        'peak_usage_hours' => [],
        'user_retention' => 0
    ];
    
    $userFiles = glob(AI_USERS_DIR . '/*.json') ?: [];
    $analytics['total_users'] = count($userFiles);
    
    $convFiles = glob(AI_CONVERSATIONS_DIR . '/*.json') ?: [];
    $analytics['total_conversations'] = count($convFiles);
    
    $totalMessages = 0;
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $monthAgo = date('Y-m-d', strtotime('-30 days'));
    
    $activeToday = 0;
    $activeWeek = 0;
    $activeMonth = 0;
    
    foreach ($userFiles as $file) {
        $userId = basename($file, '.json');
        $prefs = getUserPreferences($userId);
        $lastActive = $prefs['last_active'] ?? '';
        
        if (strpos($lastActive, $today) === 0) $activeToday++;
        if ($lastActive >= $weekAgo) $activeWeek++;
        if ($lastActive >= $monthAgo) $activeMonth++;
    }
    
    foreach ($convFiles as $file) {
        $data = aiLoadJSON($file);
        $totalMessages += is_array($data) ? count($data) : 0;
    }
    
    $analytics['active_users_today'] = $activeToday;
    $analytics['active_users_week'] = $activeWeek;
    $analytics['active_users_month'] = $activeMonth;
    $analytics['total_messages'] = $totalMessages;
    $analytics['avg_messages_per_user'] = $analytics['total_users'] > 0 ? 
        round($totalMessages / $analytics['total_users'], 2) : 0;
    
    return $analytics;
}

// ============================================================================
// CONTENT MODERATION SYSTEM
// ============================================================================

function moderateContent($text, $userId) {
    $bannedWords = ['spam', 'scam', 'fraud', 'illegal'];
    $suspiciousPatterns = [
        '/(?:buy|sell|trade)\s+(?:drugs|weapons)/i',
        '/click\s+(?:here|this)\s+link/i',
        '/(?:earn|make)\s+\$\d+\s+(?:fast|quick)/i'
    ];
    
    $textLower = strtolower($text);
    $flags = [];
    
    foreach ($bannedWords as $word) {
        if (strpos($textLower, $word) !== false) {
            $flags[] = "Contains banned word: $word";
        }
    }
    
    foreach ($suspiciousPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            $flags[] = "Matches suspicious pattern";
        }
    }
    
    if (!empty($flags)) {
        logModeration($userId, $text, $flags);
        return [
            'passed' => false,
            'flags' => $flags
        ];
    }
    
    return ['passed' => true, 'flags' => []];
}

function logModeration($userId, $content, $flags) {
    $modFile = AI_MODERATION_DIR . '/moderation_' . date('Y-m-d') . '.json';
    $logs = file_exists($modFile) ? aiLoadJSON($modFile) : [];
    
    $logs[] = [
        'user_id' => $userId,
        'content' => substr($content, 0, 200),
        'flags' => $flags,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (count($logs) > 100) {
        $logs = array_slice($logs, -100);
    }
    
    aiSaveJSON($modFile, $logs);
    
    // Notify owner of serious flags
    if (!empty($flags)) {
        sendNotificationToOwner("🚨 Content moderation flag!\n\nUser: $userId\nFlags: " . implode(', ', $flags), 'high');
    }
}

// ============================================================================
// RATE LIMITING WITH TIERS
// ============================================================================

function getUserTier($userId) {
    $tierFile = AI_USERS_DIR . '/tiers.json';
    $tiers = file_exists($tierFile) ? aiLoadJSON($tierFile) : [];
    return $tiers[(string)$userId] ?? 'free';
}

function setUserTier($userId, $tier) {
    $tierFile = AI_USERS_DIR . '/tiers.json';
    $tiers = file_exists($tierFile) ? aiLoadJSON($tierFile) : [];
    $tiers[(string)$userId] = $tier;
    return aiSaveJSON($tierFile, $tiers);
}

function getRateLimits($tier) {
    $limits = [
        'free' => ['messages_per_hour' => 20, 'messages_per_day' => 100],
        'supporter' => ['messages_per_hour' => 50, 'messages_per_day' => 500],
        'premium' => ['messages_per_hour' => 200, 'messages_per_day' => 2000],
        'unlimited' => ['messages_per_hour' => 9999, 'messages_per_day' => 99999]
    ];
    return $limits[$tier] ?? $limits['free'];
}

function checkRateLimit($userId, $isAIRequest = false) {
    // Admins have unlimited requests
    if (isAdmin($userId)) {
        return [
            'allowed' => true,
            'remaining_hourly' => 999999,
            'remaining_daily' => 999999,
            'is_admin' => true
        ];
    }
    
    // Skip rate limit check if not an AI request (e.g., just browsing commands)
    if (!$isAIRequest) {
        return [
            'allowed' => true,
            'remaining_hourly' => 0,
            'remaining_daily' => 0,
            'skipped' => true
        ];
    }
    
    $tier = getUserTier($userId);
    $limits = getRateLimits($tier);
    
    $rateLimitFile = AI_DATA_DIR . '/rate_limits_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = file_exists($rateLimitFile) ? aiLoadJSON($rateLimitFile) : [
        'hourly' => ['count' => 0, 'reset' => time() + 3600],
        'daily' => ['count' => 0, 'reset' => strtotime('tomorrow')]
    ];
    
    $now = time();
    
    if ($now >= $data['hourly']['reset']) {
        $data['hourly'] = ['count' => 0, 'reset' => $now + 3600];
    }
    if ($now >= $data['daily']['reset']) {
        $data['daily'] = ['count' => 0, 'reset' => strtotime('tomorrow')];
    }
    
    if ($data['hourly']['count'] >= $limits['messages_per_hour']) {
        return [
            'allowed' => false,
            'reason' => 'hourly_limit',
            'reset' => $data['hourly']['reset']
        ];
    }
    
    if ($data['daily']['count'] >= $limits['messages_per_day']) {
        return [
            'allowed' => false,
            'reason' => 'daily_limit',
            'reset' => $data['daily']['reset']
        ];
    }
    
    $data['hourly']['count']++;
    $data['daily']['count']++;
    aiSaveJSON($rateLimitFile, $data);
    
    return [
        'allowed' => true,
        'remaining_hourly' => $limits['messages_per_hour'] - $data['hourly']['count'],
        'remaining_daily' => $limits['messages_per_day'] - $data['daily']['count']
    ];
}

function getRateLimitStatus($userId) {
    // Admins have unlimited
    if (isAdmin($userId)) {
        return [
            'remaining_hourly' => 999999,
            'remaining_daily' => 999999,
            'hourly_limit' => 999999,
            'daily_limit' => 999999
        ];
    }
    
    $tier = getUserTier($userId);
    $limits = getRateLimits($tier);
    
    $rateLimitFile = AI_DATA_DIR . '/rate_limits_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = file_exists($rateLimitFile) ? aiLoadJSON($rateLimitFile) : [
        'hourly' => ['count' => 0, 'reset' => time() + 3600],
        'daily' => ['count' => 0, 'reset' => strtotime('tomorrow')]
    ];
    
    $now = time();
    
    if ($now >= $data['hourly']['reset']) {
        $data['hourly'] = ['count' => 0, 'reset' => $now + 3600];
    }
    if ($now >= $data['daily']['reset']) {
        $data['daily'] = ['count' => 0, 'reset' => strtotime('tomorrow')];
    }
    
    return [
        'remaining_hourly' => max(0, $limits['messages_per_hour'] - $data['hourly']['count']),
        'remaining_daily' => max(0, $limits['messages_per_day'] - $data['daily']['count']),
        'hourly_limit' => $limits['messages_per_hour'],
        'daily_limit' => $limits['messages_per_day']
    ];
}

function addUserRequests($userId, $hourlyAmount, $dailyAmount) {
    $rateLimitFile = AI_DATA_DIR . '/rate_limits_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = file_exists($rateLimitFile) ? aiLoadJSON($rateLimitFile) : [
        'hourly' => ['count' => 0, 'reset' => time() + 3600],
        'daily' => ['count' => 0, 'reset' => strtotime('tomorrow')]
    ];
    
    // Subtract from count (negative addition = more requests available)
    $data['hourly']['count'] = max(0, $data['hourly']['count'] - $hourlyAmount);
    $data['daily']['count'] = max(0, $data['daily']['count'] - $dailyAmount);
    
    return aiSaveJSON($rateLimitFile, $data);
}

function resetUserRateLimit($userId) {
    $rateLimitFile = AI_DATA_DIR . '/rate_limits_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
    $data = [
        'hourly' => ['count' => 0, 'reset' => time() + 3600],
        'daily' => ['count' => 0, 'reset' => strtotime('tomorrow')]
    ];
    return aiSaveJSON($rateLimitFile, $data);
}

// ============================================================================
// WEB SEARCH & CACHING
// ============================================================================

function webSearch($query, $limit = 5) {
    $encodedQuery = urlencode($query);
    $url = "https://api.duckduckgo.com/?q={$encodedQuery}&format=json&no_html=1&skip_disambig=1";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'AI Bot/2.0',
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

function getCachedResponse($prompt) {
    $cacheKey = hash('sha256', trim(strtolower($prompt)));
    $cacheFile = AI_CACHE_DIR . '/' . $cacheKey . '.cache';
    
    if (file_exists($cacheFile)) {
        $cached = aiLoadJSON($cacheFile);
        if ($cached && (time() - ($cached['timestamp'] ?? 0)) < 3600) {
            return $cached['response'] ?? null;
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
    
    aiSaveJSON($cacheFile, $data);
}

// ============================================================================
// ADMIN MANAGEMENT FUNCTIONS
// ============================================================================

function getAllUsers() {
    $users = [];
    $files = glob(AI_USERS_DIR . '/*.json');
    if (!$files) return $users;
    
    foreach ($files as $file) {
        $basename = basename($file, '.json');
        if ($basename === 'tiers') continue;
        
        $userId = $basename;
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
            'tier' => getUserTier($userId),
            'preferred_language' => $prefs['preferred_language'] ?? 'English'
        ];
    }
    
    return $users;
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

function broadcastMessage($message, $botToken, $excludeBlocked = true) {
    $userFiles = glob(AI_CONVERSATIONS_DIR . '/*.json') ?: [];
    $sent = 0;
    $failed = 0;
    
    foreach ($userFiles as $file) {
        $userId = basename($file, '.json');
        if (!is_numeric($userId)) continue;
        if ($excludeBlocked && isUserBlocked($userId)) continue;
        
        if (sendTelegramMessage($userId, $message, $botToken)) {
            $sent++;
        } else {
            $failed++;
        }
        usleep(100000); // Rate limiting
    }
    
    return ['sent' => $sent, 'failed' => $failed];
}

// ============================================================================
// GEMINI AI INTEGRATION
// ============================================================================

function analyzeQuestionComplexity($prompt, $context = '') {
    $text = strtolower($prompt . ' ' . $context);
    $score = 0;
    
    $length = strlen($prompt);
    if ($length > 300) $score += 25;
    elseif ($length > 200) $score += 20;
    elseif ($length > 100) $score += 10;
    
    $professionalKeywords = ['business', 'strategy', 'investment', 'analysis', 'report'];
    foreach ($professionalKeywords as $keyword) {
        if (strpos($text, $keyword) !== false) $score += 20;
    }
    
    $complexKeywords = ['analyze', 'compare', 'explain', 'why', 'how'];
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

function askGemini($prompt, $context = '', $imageBase64 = null, $imageMimeType = null) {
    global $GEMINI_API_KEY, $GOOGLE_IMAGEN_API_KEY, $HUGGINGFACE_API_KEY;
    
    if (empty($prompt)) {
        aiLog("askGemini: Empty prompt received", 'WARNING');
        return "I didn't receive a message. Please try again.";
    }
    
    aiLog("askGemini: Processing prompt - Length: " . strlen($prompt), 'INFO');
    
    // Check cache first (only for non-image requests)
    if (empty($imageBase64)) {
        $cached = getCachedResponse($prompt);
        if ($cached) {
            aiLog("askGemini: Returning cached response", 'INFO');
            return $cached;
        }
    }
    
    // Try primary Gemini API
    if (!empty($GEMINI_API_KEY)) {
        aiLog("askGemini: Trying primary GEMINI_API_KEY", 'INFO');
        $response = tryGeminiAPI($prompt, $context, $imageBase64, $imageMimeType, $GEMINI_API_KEY);
        if ($response) {
            if (empty($imageBase64)) setCachedResponse($prompt, $response);
            aiLog("askGemini: Success with primary key", 'INFO');
            return $response;
        } else {
            aiLog("askGemini: Primary key failed", 'WARNING');
        }
    } else {
        aiLog("askGemini: No GEMINI_API_KEY set", 'WARNING');
    }
    
    // Try fallback Gemini API (Imagen key)
    if (!empty($GOOGLE_IMAGEN_API_KEY)) {
        aiLog("askGemini: Trying fallback GOOGLE_IMAGEN_API_KEY", 'INFO');
        $response = tryGeminiAPI($prompt, $context, $imageBase64, $imageMimeType, $GOOGLE_IMAGEN_API_KEY);
        if ($response) {
            aiLog("askGemini: Success with fallback key", 'INFO');
            return $response;
        } else {
            aiLog("askGemini: Fallback key failed", 'WARNING');
        }
    } else {
        aiLog("askGemini: No GOOGLE_IMAGEN_API_KEY set", 'WARNING');
    }
    
    // Try Hugging Face as last resort
    if (!empty($HUGGINGFACE_API_KEY)) {
        aiLog("askGemini: Trying Hugging Face API", 'INFO');
        $response = tryHuggingFaceAPI($prompt, $context, $HUGGINGFACE_API_KEY);
        if ($response) {
            aiLog("askGemini: Success with Hugging Face", 'INFO');
            return $response;
        } else {
            aiLog("askGemini: Hugging Face failed", 'WARNING');
        }
    } else {
        aiLog("askGemini: No HUGGINGFACE_API_KEY set", 'WARNING');
    }
    
    // All APIs failed
    aiLog("askGemini: ALL APIs FAILED - Returning fallback", 'ERROR');
    return getSmartFallbackResponse($prompt);
}

function tryGeminiAPI($prompt, $context = '', $imageBase64 = null, $imageMimeType = null, $apiKey = null) {
    if (empty($apiKey)) {
        aiLog("Gemini API: No API key provided", 'WARNING');
        return null;
    }
    
    $complexity = analyzeQuestionComplexity($prompt, $context);
    $lengthGuidance = getResponseLengthGuidance($complexity);
    
    $fullPrompt = $context ? "$context\n\nUser: $prompt" : $prompt;
    $fullPrompt .= "\n\n[SYSTEM INSTRUCTION] $lengthGuidance";
    
    $models = ['gemini-2.0-flash-exp', 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-flash-8b'];
    
    foreach ($models as $model) {
        aiLog("Trying Gemini model: $model", 'INFO');
        
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        
        $parts = [];
        if ($imageBase64 && $imageMimeType) {
            $parts[] = ['inline_data' => ['mime_type' => $imageMimeType, 'data' => $imageBase64]];
        }
        $parts[] = ['text' => $fullPrompt];
        
        $data = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048
            ]
        ];
        
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
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            aiLog("Gemini API curl error for $model: $curlError", 'ERROR');
            continue;
        }
        
        aiLog("Gemini API response for $model - HTTP Code: $httpCode", 'INFO');
        
        if ($httpCode === 429) {
            aiLog("Rate limit hit for $model, trying next model", 'WARNING');
            continue;
        }
        
        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $responseText = $result['candidates'][0]['content']['parts'][0]['text'];
                aiLog("Gemini API success with $model - Response length: " . strlen($responseText), 'INFO');
                return $responseText;
            } else {
                aiLog("Gemini API response structure error for $model: " . json_encode($result), 'ERROR');
            }
        } else {
            aiLog("Gemini API failed for $model - Response: " . substr($response, 0, 500), 'ERROR');
        }
    }
    
    aiLog("All Gemini models failed", 'ERROR');
    return null;
}

function tryHuggingFaceAPI($prompt, $context = '', $apiKey = null) {
    if (empty($apiKey)) return null;
    
    $fullPrompt = $context ? "$context\n\nUser: $prompt" : "User: $prompt";
    $url = 'https://api-inference.huggingface.co/models/gpt2';
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
    
    // CRITICAL: Only return fallback if APIs are completely unavailable
    return "⚠️ I'm currently unable to connect to my AI services. Please try again in a moment. If this persists, contact the bot administrator.";
}

function analyzeImageWithGemini($imageBase64, $imageMimeType, $prompt = "Analyze this image in detail") {
    global $GEMINI_API_KEY, $GOOGLE_IMAGEN_API_KEY;
    
    if (empty($GEMINI_API_KEY) && empty($GOOGLE_IMAGEN_API_KEY)) {
        aiLog("analyzeImageWithGemini: No API keys available", 'ERROR');
        return null;
    }
    
    $apiKey = !empty($GEMINI_API_KEY) ? $GEMINI_API_KEY : $GOOGLE_IMAGEN_API_KEY;
    $models = ['gemini-2.0-flash-exp', 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'];
    
    foreach ($models as $model) {
        aiLog("analyzeImageWithGemini: Trying model $model", 'INFO');
        
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        
        $parts = [
            ['inline_data' => ['mime_type' => $imageMimeType, 'data' => $imageBase64]],
            ['text' => $prompt]
        ];
        
        $data = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048
            ]
        ];
        
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
        
        aiLog("analyzeImageWithGemini: Model $model returned HTTP $httpCode", 'INFO');
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                aiLog("analyzeImageWithGemini: Success with $model", 'INFO');
                return $result['candidates'][0]['content']['parts'][0]['text'];
            } else {
                aiLog("analyzeImageWithGemini: Invalid response structure from $model", 'ERROR');
            }
        }
    }
    
    aiLog("analyzeImageWithGemini: All models failed", 'ERROR');
    return null;
}

function getAIResponse($userId, $prompt, $context = '', $imageBase64 = null, $imageMimeType = null) {
    if (empty($userId)) $userId = 'unknown_' . uniqid();
    if (empty($prompt)) return "I didn't receive a message. Please try again.";
    
    if (strlen($prompt) > 10000) {
        return "⚠️ Your message is too long. Please keep it under 10,000 characters.";
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
// RICH FORMATTING
// ============================================================================

function formatRichResponse($response, $type = 'ai') {
    $divider = str_repeat("─", 28);
    
    switch ($type) {
        case 'ai':
            return "💡 <b>✨ AI Response ✨</b>\n$divider\n\n💬 $response\n\n$divider\n✓ <i>Response complete</i>";
        case 'translation':
            return "🌐 <b>Translation</b>\n$divider\n\n💬 $response\n\n$divider\n✓ <i>Translation complete</i>";
        case 'search':
            return $response;
        case 'image':
            return "🎨 <b>✨ Image Analysis ✨</b>\n$divider\n\n📸 $response\n\n$divider\n✓ <i>Analysis complete</i>";
        case 'admin':
            return "🔐 <b>Admin Panel</b>\n$divider\n\n$response\n\n$divider";
        case 'memory':
            return "🧠 <b>Memory Updated</b>\n$divider\n\n✅ $response\n\n$divider";
        case 'donation':
            return "💝 <b>Support Us</b>\n$divider\n\n$response\n\n$divider";
        default:
            return $response;
    }
}

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
// REAL-TIME MONITORING
// ============================================================================

function logSystemMetrics() {
    $metricsFile = AI_MONITORING_DIR . '/metrics_' . date('Y-m-d_H') . '.json';
    $metrics = [
        'timestamp' => date('Y-m-d H:i:s'),
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true),
        'active_users' => count(glob(AI_SESSIONS_DIR . '/*.json') ?: []),
        'cache_size' => array_sum(array_map('filesize', glob(AI_CACHE_DIR . '/*.cache') ?: [])),
        'error_count' => 0 // Will be updated by error handler
    ];
    
    aiSaveJSON($metricsFile, $metrics);
}

// ============================================================================
// WEBHOOK HANDLER - MAIN LOGIC
// ============================================================================

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

aiLog("[$method] $path", 'INFO');

try {
    // Health check
    if ($path === '/api/health' && $method === 'GET') {
        http_response_code(200);
        exit(json_encode([
            'status' => 'ok',
            'bot' => 'Advanced AI Bot',
            'version' => '2.0.0',
            'system_status' => getSystemStatus()
        ]));
    }
    
    // Check system status first - Allow admins to bypass
    if (!isSystemOperational() && $path === '/webhook') {
        $rawInput = file_get_contents('php://input');
        $update = json_decode($rawInput, true);
        
        if (isset($update['message'])) {
            $tempUserId = (int)($update['message']['from']['id'] ?? 0);
            
            // Allow admins to use bot during maintenance
            if (!isAdmin($tempUserId)) {
                $status = getSystemStatus();
                $statusMessage = '';
                
                if ($status['maintenance_mode']) {
                    $statusMessage = "🔧 <b>Maintenance Mode</b>\n\n";
                    $statusMessage .= "The bot is currently under maintenance.\n\n";
                    $statusMessage .= $status['message'] ? "ℹ️ <i>{$status['message']}</i>\n\n" : "";
                    $statusMessage .= "Please try again later. Thank you for your patience! 🙏";
                    
                    $tempChatId = (int)($update['message']['chat']['id'] ?? 0);
                    if ($tempChatId) {
                        sendTelegramMessage($tempChatId, $statusMessage, $TELEGRAM_BOT_TOKEN);
                    }
                    
                    http_response_code(200);
                    exit(json_encode(['status' => 'maintenance']));
                }
                
                if ($status['emergency_mode']) {
                    $statusMessage = "🚨 <b>Emergency Mode</b>\n\n";
                    $statusMessage .= "The bot is temporarily unavailable due to an emergency.\n\n";
                    $statusMessage .= $status['message'] ? "ℹ️ <i>{$status['message']}</i>\n\n" : "";
                    $statusMessage .= "We're working to restore service as soon as possible.";
                    
                    $tempChatId = (int)($update['message']['chat']['id'] ?? 0);
                    if ($tempChatId) {
                        sendTelegramMessage($tempChatId, $statusMessage, $TELEGRAM_BOT_TOKEN);
                    }
                    
                    http_response_code(200);
                    exit(json_encode(['status' => 'emergency']));
                }
            }
        }
    }
    
    // Webhook handler
    if ($path === '/webhook' && $method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $update = json_decode($rawInput, true);
        
        if (!is_array($update)) {
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle callback queries FIRST (for inline buttons like donation buttons)
        if (isset($update['callback_query'])) {
            $callbackQuery = $update['callback_query'];
            $callbackData = $callbackQuery['data'] ?? '';
            $callbackChatId = $callbackQuery['message']['chat']['id'] ?? 0;
            $callbackUserId = $callbackQuery['from']['id'] ?? 0;
            $callbackMessageId = $callbackQuery['message']['message_id'] ?? 0;
            
            aiLog("Callback query received: Data='$callbackData', User=$callbackUserId, Chat=$callbackChatId", 'INFO');
            
            // Answer callback query IMMEDIATELY to remove loading state
            $answerUrl = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/answerCallbackQuery";
            $answerData = [
                'callback_query_id' => $callbackQuery['id'],
                'text' => '⏳ Processing...',
                'show_alert' => false
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $answerUrl,
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => json_encode($answerData),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            $answerResponse = curl_exec($ch);
            $answerHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            aiLog("Callback query answered: HTTP $answerHttpCode", 'INFO');
            
            // Handle donation button clicks
            if (strpos($callbackData, 'donate_') === 0) {
                aiLog("Processing donation button: $callbackData", 'INFO');
                
                $amount = 0;
                $tierName = '';
                
                // Handle test payment
                if ($callbackData === 'donate_test_1') {
                    $amount = 1;
                    $tierName = 'Test';
                    aiLog("Test payment initiated", 'INFO');
                } elseif ($callbackData === 'donate_100') {
                    $amount = 100;
                    $tierName = 'Supporter';
                } elseif ($callbackData === 'donate_500') {
                    $amount = 500;
                    $tierName = 'Premium';
                } elseif ($callbackData === 'donate_1000') {
                    $amount = 1000;
                    $tierName = 'Premium+';
                } elseif ($callbackData === 'donate_custom') {
                    aiLog("Custom donation selected", 'INFO');
                    
                    // For custom amounts, send instructions
                    $customMsg = "⭐ <b>Custom Donation Amount</b>\n\n";
                    $customMsg .= "To donate a custom amount of Telegram Stars:\n\n";
                    $customMsg .= "1. Reply to this message with the number of stars\n";
                    $customMsg .= "2. Example: <code>250</code>\n\n";
                    $customMsg .= "📊 Minimum: 50 stars\n";
                    $customMsg .= "📊 Maximum: 2500 stars\n\n";
                    $customMsg .= "💡 <i>Just type a number between 50-2500</i>";
                    
                    sendTelegramMessage($callbackChatId, $customMsg, $TELEGRAM_BOT_TOKEN);
                    
                    // Mark user as awaiting custom amount
                    $prefs = getUserPreferences($callbackUserId);
                    $prefs['awaiting_donation_amount'] = true;
                    saveUserPreferences($callbackUserId, $prefs);
                    
                    aiLog("Custom donation setup complete for user $callbackUserId", 'INFO');
                    
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                if ($amount > 0) {
                    aiLog("Creating invoice: Amount=$amount, Tier=$tierName, User=$callbackUserId", 'INFO');
                    
                    // Send "Creating invoice..." message
                    sendTelegramMessage($callbackChatId, "💫 Creating payment invoice for <b>$amount Stars</b>...", $TELEGRAM_BOT_TOKEN);
                    
                    // Create invoice for Telegram Stars payment
                    $invoiceUrl = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendInvoice";
                    
                    $payload = json_encode([
                        'user_id' => $callbackUserId,
                        'amount' => $amount,
                        'tier' => $tierName,
                        'timestamp' => time()
                    ]);
                    
                    $invoiceData = [
                        'chat_id' => $callbackChatId,
                        'title' => "Support AI Bot - $tierName Tier",
                        'description' => "Thank you for supporting the bot! You'll get $tierName tier with {$amount} messages per hour.",
                        'payload' => $payload,
                        'currency' => 'XTR',
                        'prices' => [
                            [
                                'label' => "$tierName Tier",
                                'amount' => $amount
                            ]
                        ]
                    ];
                    
                    aiLog("Invoice data prepared: " . json_encode($invoiceData), 'INFO');
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $invoiceUrl,
                        CURLOPT_POST => 1,
                        CURLOPT_POSTFIELDS => json_encode($invoiceData),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_SSL_VERIFYPEER => true
                    ]);
                    
                    $invoiceResponse = curl_exec($ch);
                    $invoiceHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    
                    aiLog("Invoice API response: HTTP $invoiceHttpCode", 'INFO');
                    
                    if ($curlError) {
                        aiLog("Invoice creation CURL error: $curlError", 'ERROR');
                        sendTelegramMessage($callbackChatId, "❌ <b>Network Error</b>\n\nFailed to connect to payment system. Please try again.\n\nError: $curlError", $TELEGRAM_BOT_TOKEN);
                    } elseif ($invoiceHttpCode === 200) {
                        $result = json_decode($invoiceResponse, true);
                        if (isset($result['ok']) && $result['ok'] === true) {
                            aiLog("Invoice created successfully for user $callbackUserId - $amount stars", 'INFO');
                            
                            // Send success notification to owner
                            sendNotificationToOwner("💰 Invoice created: $amount stars for user $callbackUserId ($tierName tier)", 'normal');
                        } else {
                            aiLog("Invoice creation failed: " . json_encode($result), 'ERROR');
                            $errorMsg = $result['description'] ?? 'Unknown error';
                            sendTelegramMessage($callbackChatId, "❌ <b>Invoice Creation Failed</b>\n\n$errorMsg\n\n💡 Please contact the bot administrator.", $TELEGRAM_BOT_TOKEN);
                        }
                    } else {
                        aiLog("Invoice creation failed: HTTP $invoiceHttpCode - Response: $invoiceResponse", 'ERROR');
                        
                        $errorDetails = json_decode($invoiceResponse, true);
                        $errorMsg = $errorDetails['description'] ?? 'Unknown error';
                        
                        sendTelegramMessage($callbackChatId, "❌ <b>Payment System Error</b>\n\nHTTP Code: $invoiceHttpCode\nError: $errorMsg\n\n💡 This might be a bot configuration issue. Please contact the administrator.", $TELEGRAM_BOT_TOKEN);
                        
                        // Send detailed error to owner
                        sendNotificationToOwner("🚨 Invoice creation failed!\n\nUser: $callbackUserId\nAmount: $amount stars\nHTTP: $invoiceHttpCode\nError: $errorMsg\n\nFull response: " . substr($invoiceResponse, 0, 300), 'critical');
                    }
                } else {
                    aiLog("Invalid amount: $amount", 'ERROR');
                }
            } else {
                aiLog("Unknown callback data: $callbackData", 'WARNING');
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle new chat members (bot added to group)
        if (isset($update['message']['new_chat_members'])) {
            $botInfo = getBotInfo($TELEGRAM_BOT_TOKEN);
            if ($botInfo) {
                foreach ($update['message']['new_chat_members'] as $member) {
                    if ($member['id'] === $botInfo['id']) {
                        handleNewGroupJoin($update['message'], $TELEGRAM_BOT_TOKEN);
                        http_response_code(200);
                        exit(json_encode(['status' => 'ok']));
                    }
                }
            }
        }
        
        // Handle Telegram Stars payment (pre-checkout query)
        if (isset($update['pre_checkout_query'])) {
            $preCheckout = $update['pre_checkout_query'];
            $preCheckoutId = $preCheckout['id'];
            $userId = $preCheckout['from']['id'];
            $stars = $preCheckout['total_amount'];
            $payload = json_decode($preCheckout['invoice_payload'], true);
            
            aiLog("Pre-checkout query received: $stars stars from user $userId", 'INFO');
            
            // Always approve the pre-checkout query (payment goes to bot owner automatically)
            $answerUrl = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/answerPreCheckoutQuery";
            $answerData = [
                'pre_checkout_query_id' => $preCheckoutId,
                'ok' => true
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $answerUrl,
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => json_encode($answerData),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                aiLog("Pre-checkout approved for user $userId", 'INFO');
            } else {
                aiLog("Failed to approve pre-checkout: HTTP $httpCode", 'ERROR');
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle successful payment
        if (isset($update['message']['successful_payment'])) {
            $payment = $update['message']['successful_payment'];
            $userId = $update['message']['from']['id'];
            $chatId = $update['message']['chat']['id'];
            $stars = $payment['total_amount'];
            $payload = json_decode($payment['invoice_payload'], true);
            
            aiLog("Successful payment: $stars stars from user $userId", 'INFO');
            
            // Record donation
            recordDonation($userId, 0, 'stars', $stars);
            
            // Upgrade user tier based on stars
            $tier = 'free';
            $tierName = 'Free';
            
            if ($stars >= 1000) {
                setUserTier($userId, 'premium');
                $tier = 'premium';
                $tierName = 'Premium';
            } elseif ($stars >= 500) {
                setUserTier($userId, 'premium');
                $tier = 'premium';
                $tierName = 'Premium';
            } elseif ($stars >= 100) {
                setUserTier($userId, 'supporter');
                $tier = 'supporter';
                $tierName = 'Supporter';
            }
            
            // Get tier benefits
            $limits = getRateLimits($tier);
            
            $thankYouMsg = "🌟 <b>Thank You for Your Support!</b>\n\n";
            $thankYouMsg .= "✨ You donated <b>$stars Telegram Stars</b>!\n\n";
            
            if ($tier !== 'free') {
                $thankYouMsg .= "🎉 You've been upgraded to <b>$tierName</b> tier!\n\n";
                $thankYouMsg .= "<b>Your New Benefits:</b>\n";
                $thankYouMsg .= "⚡ {$limits['messages_per_hour']} messages per hour\n";
                $thankYouMsg .= "📊 {$limits['messages_per_day']} messages per day\n\n";
            }
            
            $thankYouMsg .= "Your support helps keep this bot running and improving. 💙\n\n";
            $thankYouMsg .= "Use /myinfo to see your profile and benefits!";
            
            sendTelegramMessage($chatId, formatRichResponse($thankYouMsg, 'donation'), $TELEGRAM_BOT_TOKEN);
            
            // Log the donation
            logUserBehavior($userId, 'donation', ['stars' => $stars, 'tier' => $tier]);
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if (!isset($update['message'])) {
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
        
        // Track device info
        trackDeviceInfo($userId, $message);
        
        // Log metrics
        logSystemMetrics();
        
        // Check if user is blocked
        if (isUserBlocked($userId)) {
            sendTelegramMessage($chatId, "⛔ You have been blocked from using this bot.", $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Check rate limits ONLY for non-command messages (will be checked again for AI requests)
        // This is just an initial check for display purposes
        $rateLimitStatus = getRateLimitStatus($userId);
        
        // Group chat handling
        $isGroup = isGroupChat($message);
        if ($isGroup && !shouldProcessGroupMessage($message, $TELEGRAM_BOT_TOKEN)) {
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Log user behavior
        logUserBehavior($userId, 'message_sent', ['text_length' => strlen($text), 'is_group' => $isGroup]);
        
        // MANDATORY PROFILE CHECK - Cannot bypass
        $prefs = getUserPreferences($userId);
        $profileComplete = isProfileComplete($userId);
        
        // Handle /start command
        if ($text === '/start') {
            if (!$profileComplete) {
                $prefs['awaiting_name'] = true;
                $prefs['awaiting_nationality'] = false;
                $prefs['profile_complete'] = false;
                saveUserPreferences($userId, $prefs);
                
                sendTelegramMessage($chatId, "👋 <b>Welcome to Advanced AI Bot!</b>\n\n🎯 Let's set up your profile!\n\n<b>Step 1:</b> Please tell me your name.\n\n💬 <i>Just type your name in the next message...</i>", $TELEGRAM_BOT_TOKEN);
            } else {
                $name = $prefs['name'];
                $welcomeMsg = "👋 Welcome back, <b>$name</b>!\n\n";
                $welcomeMsg .= "<b>🎉 What's New:</b>\n";
                $welcomeMsg .= "💝 Ko-fi & Telegram Stars donations\n";
                $welcomeMsg .= "🌐 Advanced AI translation\n";
                $welcomeMsg .= "🎭 Dynamic personality engine\n";
                $welcomeMsg .= "📊 User behavior analytics\n";
                $welcomeMsg .= "🧠 Mood detection\n";
                $welcomeMsg .= "🔐 Enhanced security\n\n";
                $welcomeMsg .= "Use /help for all commands!";
                
                sendTelegramMessage($chatId, $welcomeMsg, $TELEGRAM_BOT_TOKEN);
            }
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // ENFORCE PROFILE COMPLETION - Block all other commands
        if (!$profileComplete) {
            // Handle name input
            if (isset($prefs['awaiting_name']) && $prefs['awaiting_name'] === true) {
                $name = trim($text);
                
                if (strlen($name) > 50) {
                    sendTelegramMessage($chatId, "❌ Name is too long. Please keep it under 50 characters.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                if (empty($name) || strlen($name) < 2) {
                    sendTelegramMessage($chatId, "❌ Please provide a valid name (at least 2 characters).", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                $prefs['name'] = $name;
                $prefs['awaiting_name'] = false;
                $prefs['awaiting_nationality'] = true;
                saveUserPreferences($userId, $prefs);
                
                sendTelegramMessage($chatId, "✅ Nice to meet you, <b>$name</b>!\n\n🌍 <b>Step 2:</b> What's your nationality/country?\n\n💬 <i>Example: Kenya, USA, UK, India, etc.</i>", $TELEGRAM_BOT_TOKEN);
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Handle nationality input
            if (isset($prefs['awaiting_nationality']) && $prefs['awaiting_nationality'] === true) {
                $nationality = trim($text);
                
                if (strlen($nationality) > 50) {
                    sendTelegramMessage($chatId, "❌ Please provide a shorter nationality name.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                if (empty($nationality) || strlen($nationality) < 2) {
                    sendTelegramMessage($chatId, "❌ Please provide a valid nationality.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                $countryEmoji = getCountryFlagEmoji($nationality);
                
                $prefs['nationality'] = ucfirst($nationality);
                $prefs['country_emoji'] = $countryEmoji;
                $prefs['awaiting_nationality'] = false;
                $prefs['profile_complete'] = true;
                saveUserPreferences($userId, $prefs);
                
                $welcomeMsg = "✅ <b>Profile Complete!</b>\n";
                $welcomeMsg .= str_repeat("─", 28) . "\n\n";
                $welcomeMsg .= "✅ <b>Name:</b> {$prefs['name']}\n";
                $welcomeMsg .= "🌍 <b>Nationality:</b> $countryEmoji {$prefs['nationality']}\n";
                $welcomeMsg .= "🆔 <b>Telegram ID:</b> <code>$userId</code>\n";
                $welcomeMsg .= "🎖️ <b>Tier:</b> " . ucfirst(getUserTier($userId)) . "\n\n";
                $welcomeMsg .= "<b>🎉 What I can do for you:</b>\n";
                $welcomeMsg .= "🧠 Answer questions intelligently\n";
                $welcomeMsg .= "📸 Analyze images\n";
                $welcomeMsg .= "🌐 Translate languages\n";
                $welcomeMsg .= "🔍 Search the web\n";
                $welcomeMsg .= "💭 Remember our conversations\n";
                $welcomeMsg .= "🎭 Adapt to your preferred style\n";
                $welcomeMsg .= "😊 Detect your mood\n\n";
                $welcomeMsg .= "💬 <i>Try asking me anything to get started!</i>\n\n";
                $welcomeMsg .= "Use /help to see all commands or /myinfo to view your profile.";
                
                sendTelegramMessage($chatId, $welcomeMsg, $TELEGRAM_BOT_TOKEN);
                
                // Notify owner of new user
                $notifMsg = "🆕 New user registered!\n\n";
                $notifMsg .= "👤 Name: {$prefs['name']}\n";
                $notifMsg .= "🌍 From: $countryEmoji {$prefs['nationality']}\n";
                $notifMsg .= "🆔 ID: $userId";
                sendNotificationToOwner($notifMsg, 'normal');
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // If neither awaiting name nor nationality, something went wrong - restart
            sendTelegramMessage($chatId, "⚠️ Please complete your profile first. Use /start to begin.", $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // PROFILE COMPLETE - Handle all other commands
        
        // Check if user is awaiting custom donation amount
        if (isset($prefs['awaiting_donation_amount']) && $prefs['awaiting_donation_amount'] === true) {
            if (is_numeric($text)) {
                $amount = (int)$text;
                
                if ($amount < 50) {
                    sendTelegramMessage($chatId, "❌ Minimum donation amount is 50 stars.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                if ($amount > 2500) {
                    sendTelegramMessage($chatId, "❌ Maximum donation amount is 2500 stars.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                // Create invoice for custom amount
                $invoiceUrl = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendInvoice";
                $invoiceData = [
                    'chat_id' => $chatId,
                    'title' => "Support AI Bot - Custom Amount",
                    'description' => "Thank you for supporting the bot with $amount Telegram Stars!",
                    'payload' => json_encode(['user_id' => $userId, 'amount' => $amount]),
                    'currency' => 'XTR',
                    'prices' => [
                        ['label' => "Custom Donation ($amount Stars)", 'amount' => $amount]
                    ]
                ];
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $invoiceUrl,
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => json_encode($invoiceData),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode !== 200) {
                    sendTelegramMessage($chatId, "❌ Failed to create payment invoice. Please try again later.", $TELEGRAM_BOT_TOKEN);
                    aiLog("Failed to create custom invoice: $response", 'ERROR');
                }
                
                // Clear awaiting state
                $prefs['awaiting_donation_amount'] = false;
                saveUserPreferences($userId, $prefs);
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            } else {
                sendTelegramMessage($chatId, "❌ Please send a valid number of stars (50-2500).", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
        }
        
        // /donate command - Ko-fi + Telegram Stars
        if ($text === '/donate') {
            if (!isFeatureEnabled('donations_enabled')) {
                sendTelegramMessage($chatId, "⚠️ Donations are currently disabled.", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            $donateMsg = "💝 <b>Support This Bot</b>\n";
            $donateMsg .= str_repeat("─", 28) . "\n\n";
            $donateMsg .= "Thank you for considering supporting this project! ❤️\n\n";
            $donateMsg .= "Your donations help keep the bot running and allow for continuous improvements.\n\n";
            $donateMsg .= "<b>🌟 Donation Tiers:</b>\n";
            $donateMsg .= "• 100+ ⭐ = Supporter tier (50 msgs/hour)\n";
            $donateMsg .= "• 500+ ⭐ = Premium tier (200 msgs/hour)\n\n";
            $donateMsg .= "Choose your preferred method below:\n\n";
            $donateMsg .= "🙏 Every contribution is greatly appreciated!";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💰 Donate via Ko-fi', 'url' => 'https://ko-fi.com/calvin_munene#checkoutModal']
                    ],
                    [
                        ['text' => '⭐ 100 Stars (Supporter)', 'callback_data' => 'donate_100'],
                        ['text' => '⭐ 500 Stars (Premium)', 'callback_data' => 'donate_500']
                    ],
                    [
                        ['text' => '⭐ 1000 Stars (Premium+)', 'callback_data' => 'donate_1000']
                    ]
                ]
            ];
            
            sendTelegramMessage($chatId, formatRichResponse($donateMsg, 'donation'), $TELEGRAM_BOT_TOKEN, json_encode($keyboard));
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle other commands
        if ($text === '/help') {
            $helpMsg = "ℹ️ <b>Commands:</b>\n\n";
            $helpMsg .= "<b>Basic:</b>\n";
            $helpMsg .= "/start - Welcome message\n";
            $helpMsg .= "/help - Show this help\n";
            $helpMsg .= "/clear - Clear conversation\n";
            $helpMsg .= "/donate - Support the bot 💝\n";
            $helpMsg .= "/myinfo - View your profile\n\n";
            $helpMsg .= "<b>AI Features:</b>\n";
            $helpMsg .= "/ai [message] - Chat with AI\n";
            $helpMsg .= "/translate [text] to [lang] - Translate\n";
            $helpMsg .= "/search [query] - Web search\n";
            $helpMsg .= "/personality [type] - Set AI style\n\n";
            $helpMsg .= "<b>Personalization:</b>\n";
            $helpMsg .= "/remember [info] - Save info\n";
            $helpMsg .= "/forget - Clear saved data\n\n";
            $helpMsg .= "<b>Your Tier:</b> " . ucfirst(getUserTier($userId)) . "\n";
            $helpMsg .= "<b>Rate Limit:</b> {$rateLimitStatus['remaining_hourly']}/{$rateLimitStatus['hourly_limit']} msgs left this hour";
            
            if (isAdmin($userId)) {
                $helpMsg .= "\n\n<b>🔐 Admin Commands:</b>\n";
                $helpMsg .= "/admin - Admin panel\n";
                $helpMsg .= "/stats - System stats\n";
                $helpMsg .= "/users - List users\n";
                $helpMsg .= "/apitest - Test API connectivity\n";
                $helpMsg .= "/maintenance [on/off] - Toggle maintenance\n";
                $helpMsg .= "/broadcast [msg] - Message all users\n";
                $helpMsg .= "/features - Manage feature flags\n";
                $helpMsg .= "/addrequest [id] [amount] - Add requests to user\n";
                $helpMsg .= "/resetlimit [id] - Reset user's rate limit\n\n";
                $helpMsg .= "<i>💡 Admins have unlimited AI requests!</i>";
            }
            
            sendTelegramMessage($chatId, $helpMsg, $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if ($text === '/myinfo') {
            $prefs = getUserPreferences($userId);
            $tier = getUserTier($userId);
            $insights = getUserBehaviorInsights($userId);
            
            $info = "👤 <b>Your Profile</b>\n" . str_repeat("─", 28) . "\n\n";
            $info .= "🆔 <b>Telegram ID:</b> <code>{$prefs['telegram_id']}</code>\n";
            $info .= "👤 <b>Name:</b> {$prefs['name']}\n";
            
            $countryEmoji = $prefs['country_emoji'] ?? '🌍';
            $info .= "🌍 <b>Nationality:</b> $countryEmoji {$prefs['nationality']}\n";
            $info .= "🌐 <b>Language:</b> {$prefs['preferred_language']}\n";
            $info .= "🎖️ <b>Tier:</b> " . ucfirst($tier) . "\n";
            $info .= "📅 <b>Member since:</b> " . date('M d, Y', strtotime($prefs['created'])) . "\n\n";
            
            if ($insights) {
                $info .= "<b>📊 Your Activity:</b>\n";
                $info .= "• Total actions: {$insights['total_actions']}\n";
                $info .= "• Most active: {$insights['most_active_hour']}:00\n";
                $info .= "• Favorite day: {$insights['most_active_day']}\n";
                $info .= "• Last 30 days: {$insights['last_30_days_activity']} actions\n\n";
            }
            
            if (!empty($prefs['remember_items'])) {
                $info .= "<b>💭 Remembered items:</b>\n";
                foreach (array_slice($prefs['remember_items'], -3) as $item) {
                    $info .= "  • {$item['text']}\n";
                }
            }
            
            $info .= "\n" . str_repeat("─", 28);
            
            sendTelegramMessage($chatId, $info, $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if ($text === '/clear') {
            clearConversationHistory($userId);
            sendTelegramMessage($chatId, formatRichResponse("Conversation history cleared!", 'memory'), $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        if ($text === '/forget') {
            $file = AI_PREFERENCES_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '.json';
            $prefs = getUserPreferences($userId);
            $prefs['remember_items'] = [];
            saveUserPreferences($userId, $prefs);
            sendTelegramMessage($chatId, formatRichResponse("All remembered items cleared!", 'memory'), $TELEGRAM_BOT_TOKEN);
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Personality command
        if (strpos($text, '/personality') === 0) {
            $parts = explode(' ', trim($text));
            if (count($parts) < 2) {
                $options = getPersonalityOptions();
                $msg = "🎭 <b>Available Personalities:</b>\n\n";
                foreach ($options as $key => $opt) {
                    $msg .= "• <b>$key</b> - {$opt['description']}\n";
                }
                $msg .= "\n<i>Usage: /personality [type]</i>";
                sendTelegramMessage($chatId, $msg, $TELEGRAM_BOT_TOKEN);
            } else {
                $tone = strtolower($parts[1]);
                $options = getPersonalityOptions();
                if (isset($options[$tone])) {
                    setUserPersonality($userId, $tone, 'balanced');
                    sendTelegramMessage($chatId, formatRichResponse("Personality set to: <b>$tone</b>", 'memory'), $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "❌ Invalid personality type. Use /personality to see options.", $TELEGRAM_BOT_TOKEN);
                }
            }
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Admin commands
        if (isAdmin($userId)) {
            if ($text === '/admin') {
                $stats = getSystemAnalytics();
                $status = getSystemStatus();
                
                $adminMsg = "🔐 <b>Admin Panel</b>\n" . str_repeat("─", 28) . "\n\n";
                $adminMsg .= "📊 <b>System Status:</b> ";
                $adminMsg .= $status['maintenance_mode'] ? "🔧 Maintenance" : "✅ Operational";
                $adminMsg .= "\n\n<b>Statistics:</b>\n";
                $adminMsg .= "👥 Total users: {$stats['total_users']}\n";
                $adminMsg .= "💬 Total messages: {$stats['total_messages']}\n";
                $adminMsg .= "📈 Active today: {$stats['active_users_today']}\n";
                $adminMsg .= "📊 Active this week: {$stats['active_users_week']}\n";
                $adminMsg .= "💾 Avg msgs/user: {$stats['avg_messages_per_user']}\n\n";
                $adminMsg .= "<i>Use /help for admin commands</i>";
                
                sendTelegramMessage($chatId, formatRichResponse($adminMsg, 'admin'), $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            if (strpos($text, '/maintenance') === 0) {
                $parts = explode(' ', trim($text), 2);
                if (count($parts) < 2) {
                    sendTelegramMessage($chatId, "Usage: /maintenance [on|off]", $TELEGRAM_BOT_TOKEN);
                } else {
                    $mode = strtolower($parts[1]);
                    if ($mode === 'on') {
                        setMaintenanceMode(true, 'System maintenance in progress');
                        sendTelegramMessage($chatId, "🔧 Maintenance mode ENABLED", $TELEGRAM_BOT_TOKEN);
                    } elseif ($mode === 'off') {
                        setMaintenanceMode(false);
                        sendTelegramMessage($chatId, "✅ Maintenance mode DISABLED", $TELEGRAM_BOT_TOKEN);
                    }
                }
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            if ($text === '/features') {
                $flags = getFeatureFlags();
                $msg = "🚩 <b>Feature Flags:</b>\n\n";
                foreach ($flags as $feature => $enabled) {
                    $status = $enabled ? "✅" : "❌";
                    $msg .= "$status $feature\n";
                }
                $msg .= "\n<i>Toggle with /toggle [feature]</i>";
                sendTelegramMessage($chatId, $msg, $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            if ($text === '/apitest') {
                global $GEMINI_API_KEY, $GOOGLE_IMAGEN_API_KEY, $HUGGINGFACE_API_KEY, $TELEGRAM_BOT_TOKEN;
                
                $msg = "🔍 <b>API Diagnostics</b>\n" . str_repeat("─", 28) . "\n\n";
                
                // Check API keys
                $msg .= "<b>API Keys Status:</b>\n";
                $msg .= "GEMINI_API_KEY: " . (!empty($GEMINI_API_KEY) ? "✅ Set (" . substr($GEMINI_API_KEY, 0, 10) . "...)" : "❌ Not set") . "\n";
                $msg .= "GOOGLE_IMAGEN_API_KEY: " . (!empty($GOOGLE_IMAGEN_API_KEY) ? "✅ Set" : "❌ Not set") . "\n";
                $msg .= "HUGGINGFACE_API_KEY: " . (!empty($HUGGINGFACE_API_KEY) ? "✅ Set" : "❌ Not set") . "\n";
                $msg .= "TELEGRAM_BOT_TOKEN: " . (!empty($TELEGRAM_BOT_TOKEN) ? "✅ Set (" . substr($TELEGRAM_BOT_TOKEN, 0, 10) . "...)" : "❌ Not set") . "\n\n";
                
                // Check bot info and payment provider
                $botInfo = getBotInfo($TELEGRAM_BOT_TOKEN);
                if ($botInfo) {
                    $msg .= "<b>Bot Information:</b>\n";
                    $msg .= "Bot ID: {$botInfo['id']}\n";
                    $msg .= "Username: @{$botInfo['username']}\n";
                    $msg .= "Name: {$botInfo['first_name']}\n";
                    $msg .= "Can Join Groups: " . ($botInfo['can_join_groups'] ? "✅" : "❌") . "\n";
                    $msg .= "Supports Inline: " . ($botInfo['supports_inline_queries'] ? "✅" : "❌") . "\n\n";
                    
                    // Check if bot can receive payments
                    $msg .= "<b>Payment Status:</b>\n";
                    $msg .= "⭐ Telegram Stars: Checking...\n\n";
                    
                    // Try to check payment support by attempting to get bot commands
                    $msg .= "💡 <i>To enable Telegram Stars payments:</i>\n";
                    $msg .= "1. Talk to @BotFather\n";
                    $msg .= "2. Select your bot\n";
                    $msg .= "3. Go to Bot Settings → Payments\n";
                    $msg .= "4. Select 'Telegram Stars'\n";
                    $msg .= "5. Payments go directly to your Telegram account\n\n";
                } else {
                    $msg .= "❌ Failed to get bot info\n\n";
                }
                
                // Test Gemini API
                if (!empty($GEMINI_API_KEY)) {
                    $msg .= "<b>Testing Gemini API...</b>\n";
                    $testResponse = tryGeminiAPI("Say 'API Working' if you receive this", "", null, null, $GEMINI_API_KEY);
                    if ($testResponse) {
                        $msg .= "✅ Gemini API: <b>Working</b>\n";
                        $msg .= "Response: " . substr($testResponse, 0, 50) . "...\n\n";
                    } else {
                        $msg .= "❌ Gemini API: <b>Failed</b>\n\n";
                    }
                }
                
                // Check recent logs
                $logFile = AI_MONITORING_DIR . '/logs_' . date('Y-m-d') . '.json';
                if (file_exists($logFile)) {
                    $logs = aiLoadJSON($logFile);
                    $errorCount = 0;
                    $recentErrors = [];
                    foreach (array_slice($logs, -20) as $log) {
                        if ($log['level'] === 'ERROR') {
                            $errorCount++;
                            $recentErrors[] = $log['message'];
                        }
                    }
                    $msg .= "<b>Recent Errors:</b> $errorCount in last 20 logs\n";
                    if (!empty($recentErrors)) {
                        $msg .= "Last error: " . substr($recentErrors[0], 0, 100) . "\n";
                    }
                }
                
                sendTelegramMessage($chatId, formatRichResponse($msg, 'admin'), $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Test payment system
            if ($text === '/testpayment') {
                $msg = "💳 <b>Payment System Test</b>\n" . str_repeat("─", 28) . "\n\n";
                $msg .= "This will send you a test donation button to verify the payment system is working.\n\n";
                $msg .= "Click the button below to test:";
                
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '⭐ Test 1 Star Payment', 'callback_data' => 'donate_test_1']
                        ]
                    ]
                ];
                
                sendTelegramMessage($chatId, $msg, $TELEGRAM_BOT_TOKEN, json_encode($keyboard));
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Add requests to user
            if (strpos($text, '/addrequest') === 0) {
                $parts = preg_split('/\s+/', trim($text));
                
                if (count($parts) < 3) {
                    sendTelegramMessage($chatId, "❌ Usage: /addrequest [user_id] [amount]\n\nExample: /addrequest 123456789 50\n\nThis will add 50 requests to both hourly and daily limits.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                $targetUserId = $parts[1];
                $amount = (int)$parts[2];
                
                if (!is_numeric($targetUserId) || $amount <= 0) {
                    sendTelegramMessage($chatId, "❌ Invalid user ID or amount. User ID must be numeric and amount must be positive.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                if (addUserRequests($targetUserId, $amount, $amount)) {
                    $userName = getUserPreferences($targetUserId)['name'] ?? 'Unknown';
                    sendTelegramMessage($chatId, "✅ Added <b>$amount</b> requests to user:\n\n👤 User: $userName\n🆔 ID: $targetUserId\n\nThey now have $amount extra requests in both hourly and daily limits!", $TELEGRAM_BOT_TOKEN);
                    
                    // Notify the user
                    sendTelegramMessage($targetUserId, "🎁 <b>Bonus Requests!</b>\n\nYou've been granted <b>$amount</b> extra AI requests!\n\nUse /myinfo to check your current limits.", $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "❌ Failed to add requests. Please try again.", $TELEGRAM_BOT_TOKEN);
                }
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Reset user rate limit
            if (strpos($text, '/resetlimit') === 0) {
                $parts = preg_split('/\s+/', trim($text));
                
                if (count($parts) < 2) {
                    sendTelegramMessage($chatId, "❌ Usage: /resetlimit [user_id]\n\nExample: /resetlimit 123456789", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                $targetUserId = $parts[1];
                
                if (!is_numeric($targetUserId)) {
                    sendTelegramMessage($chatId, "❌ Invalid user ID. Must be numeric.", $TELEGRAM_BOT_TOKEN);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok']));
                }
                
                if (resetUserRateLimit($targetUserId)) {
                    $userName = getUserPreferences($targetUserId)['name'] ?? 'Unknown';
                    sendTelegramMessage($chatId, "✅ Reset rate limit for:\n\n👤 User: $userName\n🆔 ID: $targetUserId\n\nTheir request counters have been reset to 0!", $TELEGRAM_BOT_TOKEN);
                    
                    // Notify the user
                    sendTelegramMessage($targetUserId, "🔄 <b>Rate Limit Reset!</b>\n\nYour request counters have been reset. You now have full access to your tier limits again!", $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "❌ Failed to reset rate limit. Please try again.", $TELEGRAM_BOT_TOKEN);
                }
                
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            if (strpos($text, '/broadcast') === 0 && hasPermission($userId, 'can_broadcast')) {
                $broadcastMsg = trim(substr($text, 10));
                if (empty($broadcastMsg)) {
                    sendTelegramMessage($chatId, "❌ Usage: /broadcast [message]", $TELEGRAM_BOT_TOKEN);
                } else {
                    sendTelegramMessage($chatId, "📢 Broadcasting message...", $TELEGRAM_BOT_TOKEN);
                    $result = broadcastMessage("📢 <b>Announcement</b>\n\n$broadcastMsg", $TELEGRAM_BOT_TOKEN);
                    sendTelegramMessage($chatId, "✅ Broadcast complete!\nSent: {$result['sent']}\nFailed: {$result['failed']}", $TELEGRAM_BOT_TOKEN);
                }
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
        }
        
        // Handle images
        if (isset($message['photo']) && isFeatureEnabled('image_analysis_enabled')) {
            // Check rate limit for AI image analysis
            $rateLimit = checkRateLimit($userId, true);
            if (!$rateLimit['allowed']) {
                $resetTime = date('H:i', $rateLimit['reset']);
                $reason = $rateLimit['reason'] === 'hourly_limit' ? 'hourly' : 'daily';
                sendTelegramMessage($chatId, "⏱️ <b>Rate Limit Reached!</b>\n\nYour $reason limit has been exceeded.\n\n🕐 Resets at: <b>$resetTime</b>\n\n💡 <i>Tip: Use /donate to upgrade your tier for higher limits!</i>", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
            $msgId = sendTelegramMessage($chatId, "📸 <b>Analyzing image</b>●", $TELEGRAM_BOT_TOKEN);
            
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
                    $finalResponse = formatRichResponse($response, 'image');
                    if ($msgId && is_numeric($msgId)) {
                        editTelegramMessage($chatId, $msgId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                    } else {
                        sendTelegramMessage($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                    }
                    saveConversationMessage($userId, 'user', '[IMAGE]: ' . $imagePrompt);
                    saveConversationMessage($userId, 'assistant', $response);
                    logUserBehavior($userId, 'image_analysis', ['prompt_length' => strlen($imagePrompt)]);
                } else {
                    editTelegramMessage($chatId, $msgId, "❌ Failed to analyze image. Please try again.", $TELEGRAM_BOT_TOKEN);
                }
            }
            
            http_response_code(200);
            exit(json_encode(['status' => 'ok']));
        }
        
        // Handle text messages - AI response
        if (!empty($text)) {
            // Content moderation
            $moderation = moderateContent($text, $userId);
            if (!$moderation['passed']) {
                sendTelegramMessage($chatId, "⚠️ Your message was flagged by our content moderation system. Please rephrase.", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // NOW check rate limit for actual AI request
            $rateLimit = checkRateLimit($userId, true);
            if (!$rateLimit['allowed']) {
                $resetTime = date('H:i', $rateLimit['reset']);
                $reason = $rateLimit['reason'] === 'hourly_limit' ? 'hourly' : 'daily';
                sendTelegramMessage($chatId, "⏱️ <b>Rate Limit Reached!</b>\n\nYour $reason limit has been exceeded.\n\n🕐 Resets at: <b>$resetTime</b>\n\n💡 <i>Tip: Use /donate to upgrade your tier for higher limits!</i>", $TELEGRAM_BOT_TOKEN);
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            // Mood detection
            if (isFeatureEnabled('mood_detection_enabled')) {
                $moodData = detectMood($text);
                if ($moodData['confidence'] > 50) {
                    logMood($userId, $moodData['mood'], $moodData['confidence']);
                }
            }
            
            $cleanText = preg_replace('/@ai\s*/i', '', $text);
            $cleanText = preg_replace('/^\/ai\s*/i', '', $cleanText);
            $cleanText = trim($cleanText);
            
            if (empty($cleanText)) {
                http_response_code(200);
                exit(json_encode(['status' => 'ok']));
            }
            
            sendChatAction($chatId, 'typing', $TELEGRAM_BOT_TOKEN);
            $msgId = sendTelegramMessage($chatId, "🧠 <b>Thinking</b>●", $TELEGRAM_BOT_TOKEN);
            
            // Build context
            $replyContext = getMessageHistory($message, $TELEGRAM_BOT_TOKEN);
            $conversationContext = formatConversationForContext($userId, 6);
            $userPrefsContext = formatPreferencesForContext($userId);
            $personality = getUserPersonality($userId);
            $personalityPrompt = getPersonalityPrompt($personality);
            
            $fullContext = "";
            if (!empty($replyContext)) $fullContext .= $replyContext;
            if (!empty($userPrefsContext)) $fullContext .= "User info: $userPrefsContext\n\n";
            if (!empty($conversationContext)) $fullContext .= $conversationContext;
            $fullContext .= "Personality: $personalityPrompt";
            
            // Get AI response
            $response = getAIResponse($userId, $cleanText, $fullContext);
            
            // Adjust by mood if detected
            if (isset($moodData) && $moodData['confidence'] > 50) {
                $response = adjustResponseByMood($response, $moodData['mood']);
            }
            
            $finalResponse = formatRichResponse($response, 'ai');
            
            // Send response
            if ($msgId && is_numeric($msgId)) {
                if (strlen($response) > 500) {
                    sendStreamingResponse($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN, $msgId);
                } else {
                    editTelegramMessage($chatId, $msgId, $finalResponse, $TELEGRAM_BOT_TOKEN);
                }
            } else {
                sendTelegramMessage($chatId, $finalResponse, $TELEGRAM_BOT_TOKEN);
            }
            
            // Save conversation
            saveConversationMessage($userId, 'user', $cleanText);
            saveConversationMessage($userId, 'assistant', $response);
            
            // Log behavior
            logUserBehavior($userId, 'ai_query', [
                'text_length' => strlen($cleanText),
                'response_length' => strlen($response),
                'mood' => $moodData['mood'] ?? 'neutral'
            ]);
            
            // Update preferences
            $prefs = getUserPreferences($userId);
            saveUserPreferences($userId, $prefs);
        }
        
        http_response_code(200);
        exit(json_encode(['status' => 'ok']));
    }
    
    // 404 for unknown endpoints
    http_response_code(404);
    exit(json_encode(['error' => 'Not found']));

} catch (Exception $e) {
    aiLog("EXCEPTION: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    exit(json_encode(['error' => 'Server error']));
}

?>