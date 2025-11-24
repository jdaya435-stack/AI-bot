<?php
/**
 * AI BOT ENGINE - Professional Gemini & AI Integration
 * Reusable AI system for any Telegram/Chat bot application
 * 
 * Features:
 * - Google Gemini API (Primary + Fallback)
 * - Hugging Face AI Fallback
 * - Smart Intelligent Responses
 * - Conversation History Management
 * - System Command Execution
 * 
 * Usage:
 * 1. Set environment variables: GOOGLE_GEMINI_API_KEY, GOOGLE_IMAGEN_API_KEY, HUGGINGFACE_API_KEY
 * 2. Call askGemini($prompt, $context) for AI responses
 * 3. Use conversation history functions for memory management
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/ai_engine.log');

// ============================================================================
// UTILITY FUNCTIONS (DEFINED FIRST - NO DEPENDENCIES)
// ============================================================================

function aiLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message");
}

function aiLoadJSON($file) {
    if (!file_exists($file)) {
        return [];
    }
    
    $fp = fopen($file, 'r');
    if (!$fp) {
        return [];
    }
    
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
    $fp = fopen($file, 'c');
    if (!$fp) {
        aiLog("ERROR: Cannot open file for writing: $file");
        return false;
    }
    
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            aiLog("ERROR: JSON encoding failed for $file");
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    
    fclose($fp);
    return false;
}

function aiMatchesIntent($text, $keywords) {
    foreach ($keywords as $keyword) {
        if (strpos($text, strtolower($keyword)) !== false) {
            return true;
        }
    }
    return false;
}

// ============================================================================
// CONFIGURATION (NOW SAFE TO USE aiLog)
// ============================================================================

define('AI_DATA_DIR', __DIR__ . '/ai_data');
define('AI_CONVERSATIONS_DIR', AI_DATA_DIR . '/conversations');

// Load environment variables
$GEMINI_API_KEY = getenv('GOOGLE_GEMINI_API_KEY');
$GOOGLE_IMAGEN_API_KEY = getenv('GOOGLE_IMAGEN_API_KEY');
$HUGGINGFACE_API_KEY = getenv('HUGGINGFACE_API_KEY');

aiLog("🚀 AI Engine Initialized");
aiLog("✅ Primary Gemini Key: " . (!empty($GEMINI_API_KEY) ? "SET" : "NOT SET"));
aiLog("✅ Fallback Gemini Key: " . (!empty($GOOGLE_IMAGEN_API_KEY) ? "SET" : "NOT SET"));
aiLog("✅ HuggingFace Key: " . (!empty($HUGGINGFACE_API_KEY) ? "SET" : "NOT SET"));

// Ensure directories exist with proper permissions
if (!file_exists(AI_DATA_DIR)) {
    @mkdir(AI_DATA_DIR, 0755, true);
}
if (!file_exists(AI_CONVERSATIONS_DIR)) {
    @mkdir(AI_CONVERSATIONS_DIR, 0755, true);
}

// ============================================================================
// CONVERSATION HISTORY MANAGEMENT
// ============================================================================

function getConversationFile($userId) {
    // Sanitize userId to prevent directory traversal
    $userId = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$userId);
    if (empty($userId)) {
        $userId = 'unknown';
    }
    return AI_CONVERSATIONS_DIR . '/' . $userId . '.json';
}

function getConversationHistory($userId, $limit = 10) {
    $file = getConversationFile($userId);
    if (!file_exists($file)) {
        return [];
    }
    $history = aiLoadJSON($file);
    return is_array($history) ? array_slice($history, -$limit) : [];
}

function saveConversationMessage($userId, $role, $message) {
    $file = getConversationFile($userId);
    $history = file_exists($file) ? aiLoadJSON($file) : [];
    
    if (!is_array($history)) {
        $history = [];
    }
    
    $history[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'role' => $role,
        'message' => (string)$message
    ];
    
    // Keep only last 50 messages per user
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }
    
    aiSaveJSON($file, $history);
}

function formatConversationHistory($history) {
    if (!is_array($history) || empty($history)) {
        return "";
    }
    
    $formatted = "📝 Conversation History:\n";
    foreach ($history as $msg) {
        if (!is_array($msg) || !isset($msg['role']) || !isset($msg['message'])) {
            continue;
        }
        $role = ($msg['role'] === 'user') ? '👤 User' : '🤖 Assistant';
        $text = substr($msg['message'], 0, 100);
        if (strlen($msg['message']) > 100) {
            $text .= "...";
        }
        $formatted .= "$role: $text\n";
    }
    return $formatted;
}

function clearConversationHistory($userId) {
    $file = getConversationFile($userId);
    if (file_exists($file)) {
        return @unlink($file);
    }
    return false;
}

// ============================================================================
// SYSTEM COMMAND EXECUTOR
// ============================================================================

function executeSystemCommand($command) {
    aiLog("🔧 [SYSTEM] Executing: $command");
    
    $output = shell_exec("$command 2>&1");
    $success = ($output !== null);
    
    $result = [
        'success' => $success,
        'output' => $output ?: 'Command executed',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    aiLog("✅ [SYSTEM] Result: " . ($success ? "Success" : "Failed"));
    
    return $result;
}

// ============================================================================
// GEMINI AI INTEGRATION
// ============================================================================

function askGemini($prompt, $context = '', $imageBase64 = null, $imageMimeType = null) {
    global $GEMINI_API_KEY, $GOOGLE_IMAGEN_API_KEY;
    
    if (empty($prompt)) {
        aiLog("⚠️ Empty prompt received");
        return "I didn't receive a message. Please try again.";
    }
    
    // Try Gemini API first (PRIMARY)
    if (!empty($GEMINI_API_KEY)) {
        $response = tryGeminiAPI($prompt, $context, $imageBase64, $imageMimeType);
        if ($response) {
            return $response;
        }
    }
    
    // Try Secondary Gemini API Key (FALLBACK)
    if (!empty($GOOGLE_IMAGEN_API_KEY)) {
        aiLog("🔄 Trying fallback Gemini API");
        $response = trySecondaryGeminiAPI($prompt, $context, $imageBase64, $imageMimeType, $GOOGLE_IMAGEN_API_KEY);
        if ($response) {
            aiLog("✅ Fallback Gemini API succeeded!");
            return $response;
        }
    }
    
    // Try Hugging Face (TERTIARY)
    $hfResponse = tryHuggingFaceAPI($prompt, $context);
    if ($hfResponse) {
        return $hfResponse;
    }
    
    // Fallback to smart assistant
    aiLog("🤖 Using smart fallback");
    return getSmartFallbackResponse($prompt);
}

function tryGeminiAPI($prompt, $context = '', $imageBase64 = null, $imageMimeType = null) {
    global $GEMINI_API_KEY;
    
    if (empty($GEMINI_API_KEY)) {
        aiLog("❌ Gemini: API KEY EMPTY");
        return null;
    }
    
    $fullPrompt = $context ? "$context\n\nUser: $prompt" : $prompt;
    $models = ['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-pro'];
    
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$GEMINI_API_KEY}";
        
        $parts = [];
        if ($imageBase64 && $imageMimeType) {
            $parts[] = ['inline_data' => ['mime_type' => $imageMimeType, 'data' => $imageBase64]];
        }
        $parts[] = ['text' => $fullPrompt];
        
        $data = ['contents' => [['parts' => $parts]]];
        
        $isImage = $imageBase64 ? " (with image)" : "";
        aiLog("🔄 Gemini: Trying $model$isImage...");
        
        $ch = curl_init();
        if (!$ch) {
            aiLog("❌ Gemini: $model curl init failed");
            continue;
        }
        
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            aiLog("❌ Gemini: $model JSON encoding failed");
            curl_close($ch);
            continue;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        aiLog("📡 Gemini: $model returned HTTP $httpCode");
        
        if ($curlError) {
            aiLog("❌ Gemini: $model curl error: $curlError");
            continue;
        }
        
        if (!$response) {
            aiLog("❌ Gemini: $model no response");
            continue;
        }
        
        $result = json_decode($response, true);
        if (!is_array($result)) {
            aiLog("❌ Gemini: $model invalid JSON response");
            continue;
        }
        
        if ($httpCode === 200) {
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $text = $result['candidates'][0]['content']['parts'][0]['text'];
                aiLog("✅ Gemini: $model SUCCESS - Got " . strlen($text) . " chars");
                return $text;
            } else {
                aiLog("❌ Gemini: $model - No text in response");
            }
        } else {
            $errorMsg = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error';
            aiLog("❌ Gemini: $model HTTP $httpCode - $errorMsg");
        }
    }
    
    aiLog("❌ Gemini: All models failed");
    return null;
}

function trySecondaryGeminiAPI($prompt, $context = '', $imageBase64 = null, $imageMimeType = null, $apiKey = null) {
    if (empty($apiKey)) {
        aiLog("❌ Secondary Gemini: API KEY EMPTY");
        return null;
    }
    
    $fullPrompt = $context ? "$context\n\nUser: $prompt" : $prompt;
    $models = ['gemini-2.0-flash', 'gemini-2.5-flash'];
    
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apiKey}";
        
        $parts = [];
        if ($imageBase64 && $imageMimeType) {
            $parts[] = ['inline_data' => ['mime_type' => $imageMimeType, 'data' => $imageBase64]];
        }
        $parts[] = ['text' => $fullPrompt];
        
        $data = ['contents' => [['parts' => $parts]]];
        
        $isImage = $imageBase64 ? " (with image)" : "";
        aiLog("🔄 Secondary Gemini: Trying $model$isImage...");
        
        $ch = curl_init();
        if (!$ch) {
            aiLog("❌ Secondary Gemini: $model curl init failed");
            continue;
        }
        
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            aiLog("❌ Secondary Gemini: $model JSON encoding failed");
            curl_close($ch);
            continue;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        aiLog("📡 Secondary Gemini: $model returned HTTP $httpCode");
        
        if ($curlError) {
            aiLog("❌ Secondary Gemini: $model curl error: $curlError");
            continue;
        }
        
        if (!$response) {
            aiLog("❌ Secondary Gemini: $model no response");
            continue;
        }
        
        $result = json_decode($response, true);
        if (!is_array($result)) {
            aiLog("❌ Secondary Gemini: $model invalid JSON response");
            continue;
        }
        
        if ($httpCode === 200) {
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $text = $result['candidates'][0]['content']['parts'][0]['text'];
                aiLog("✅ Secondary Gemini: $model SUCCESS - Got " . strlen($text) . " chars");
                return $text;
            }
        } else {
            aiLog("❌ Secondary Gemini: $model HTTP $httpCode");
        }
    }
    
    aiLog("❌ Secondary Gemini: All models failed");
    return null;
}

// ============================================================================
// HUGGING FACE AI INTEGRATION
// ============================================================================

function tryHuggingFaceAPI($prompt, $context = '') {
    global $HUGGINGFACE_API_KEY;
    
    if (empty($HUGGINGFACE_API_KEY)) {
        return null;
    }
    
    $fullPrompt = $context ? "$context\n\nUser: $prompt" : "User: $prompt";
    
    $models = [
        'gpt2' => 'https://router.huggingface.co/models/gpt2',
        'flan-t5' => 'https://router.huggingface.co/models/google/flan-t5-base'
    ];
    
    foreach ($models as $name => $url) {
        $data = ['inputs' => $fullPrompt];
        
        aiLog("🔄 HF: Trying $name...");
        
        $ch = curl_init();
        if (!$ch) {
            continue;
        }
        
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            curl_close($ch);
            continue;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $HUGGINGFACE_API_KEY
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
            if (is_array($result)) {
                $text = null;
                if (isset($result[0]['generated_text'])) {
                    $text = $result[0]['generated_text'];
                } elseif (isset($result[0]['summary_text'])) {
                    $text = $result[0]['summary_text'];
                }
                
                if ($text) {
                    if (strpos($text, $fullPrompt) === 0) {
                        $text = substr($text, strlen($fullPrompt));
                    }
                    aiLog("✅ HF: $name SUCCESS");
                    return trim($text);
                }
            }
        }
    }
    
    return null;
}

// ============================================================================
// SMART FALLBACK RESPONSE
// ============================================================================

function getSmartFallbackResponse($question) {
    $questionLower = strtolower(trim($question));
    
    if (empty($questionLower)) {
        return "I'm here to help! What would you like to know?";
    }
    
    // Greeting responses
    if (aiMatchesIntent($questionLower, ['hello', 'hi', 'hey', 'greetings', 'what is your name'])) {
        return "👋 Hi there! I'm your AI assistant. I'm here to help you with information, research, and answering any questions you might have. What can I help you with today?";
    }
    
    // Who are you questions
    if (aiMatchesIntent($questionLower, ['who are you', 'what can you do', 'help', 'how do i'])) {
        return "🤖 I'm an intelligent AI assistant powered by Google Gemini. I can help you with:\n• General knowledge and information\n• Research and explanations\n• Creative writing and ideas\n• Problem solving\n• Answering questions on virtually any topic\n\nJust ask me anything!";
    }
    
    // Thank you
    if (aiMatchesIntent($questionLower, ['thank', 'thanks', 'appreciate', 'thank you'])) {
        return "😊 You're welcome! Happy to help. Feel free to ask me anything else!";
    }
    
    // General question starters
    if (aiMatchesIntent($questionLower, ['how', 'what', 'why', 'when', 'where', 'which', 'can you', 'do you', 'is it'])) {
        return "💭 Great question! I'm here to help with virtually any topic. I can provide information, explanations, research help, and much more.\n\nWhat specifically would you like to know?";
    }
    
    // Learning/research
    if (aiMatchesIntent($questionLower, ['learn', 'teach', 'explain', 'understand', 'how does', 'tell me about'])) {
        return "📚 I'd love to help you learn! Whether it's a concept, skill, history, science, or anything else - just let me know what you'd like to understand better, and I'll explain it clearly.";
    }
    
    // Creative requests
    if (aiMatchesIntent($questionLower, ['write', 'create', 'compose', 'generate', 'imagine', 'story', 'poem'])) {
        return "✨ I can help with creative writing! I can write stories, poems, scripts, ideas, and much more. What would you like me to create for you?";
    }
    
    // Analysis/advice (FIXED: removed duplicate 'analyze')
    if (aiMatchesIntent($questionLower, ['analyze', 'opinion', 'advice', 'suggest', 'recommend', 'thoughts'])) {
        return "🧠 I'm happy to provide analysis, suggestions, and perspectives. Share what you'd like me to analyze or advise on, and I'll give you thoughtful insights!";
    }
    
    // Default helpful response
    return "🤖 I'm here to help! I can answer questions, provide information, write content, offer advice, or help with research on virtually any topic.\n\nWhat would you like to know?";
}

// ============================================================================
// EXPORT FUNCTIONS (Main API)
// ============================================================================

function getAIResponse($userId, $prompt, $context = '', $imageBase64 = null, $imageMimeType = null) {
    if (empty($userId)) {
        aiLog("⚠️ Empty user ID received");
        $userId = 'unknown_' . uniqid();
    }
    
    if (empty($prompt)) {
        aiLog("⚠️ Empty prompt received");
        return "I didn't receive a message. Please try again.";
    }
    
    // ===== IMAGE GENERATION RESTRICTION =====
    $promptLower = strtolower($prompt);
    $imageKeywords = ['generate image', 'create image', 'make image', 'draw', 'create photo', 'generate photo', 'make picture', 'generate art', 'create art', 'design image', 'generate picture', 'edit image'];
    
    foreach ($imageKeywords as $keyword) {
        if (strpos($promptLower, $keyword) !== false) {
            $response = "📸 Image Generation Not Available\n\nSorry, image generation is not available at this time. However, I can help you with:\n\n• Questions and answers\n• Information and research\n• Creative writing and ideas\n• General conversations\n• Problem solving\n\nWhat else can I help you with?";
            
            saveConversationMessage($userId, 'user', $prompt);
            saveConversationMessage($userId, 'assistant', $response);
            
            aiLog("🚫 Image generation blocked: $prompt");
            return $response;
        }
    }
    
    // Get conversation history
    $history = getConversationHistory($userId, 5);
    
    // Enhance context with history
    if (!empty($history) && is_array($history)) {
        $historyText = formatConversationHistory($history);
        if ($historyText) {
            $context = $context ? "$context\n\n$historyText" : $historyText;
        }
    }
    
    // Get AI response
    $response = askGemini($prompt, $context, $imageBase64, $imageMimeType);
    
    // Ensure response is a string
    if (!is_string($response)) {
        $response = "I encountered an issue processing your request. Please try again.";
        aiLog("⚠️ Response was not a string: " . gettype($response));
    }
    
    // Save to history
    saveConversationMessage($userId, 'user', $prompt);
    saveConversationMessage($userId, 'assistant', $response);
    
    return $response;
}

function runSystemCommand($command) {
    return executeSystemCommand($command);
}

function getUserConversation($userId) {
    return getConversationHistory($userId, 50);
}

function clearUserConversation($userId) {
    return clearConversationHistory($userId);
}

?>
