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
// CONFIGURATION
// ============================================================================

define('AI_DATA_DIR', __DIR__ . '/ai_data');
define('AI_CONVERSATIONS_DIR', AI_DATA_DIR . '/conversations');

// Load environment variables
$GEMINI_API_KEY = getenv('GOOGLE_GEMINI_API_KEY');
$GOOGLE_IMAGEN_API_KEY = getenv('GOOGLE_IMAGEN_API_KEY');
$HUGGINGFACE_API_KEY = getenv('HUGGINGFACE_API_KEY');

// Ensure directories exist
if (!file_exists(AI_DATA_DIR)) {
    mkdir(AI_DATA_DIR, 0777, true);
}
if (!file_exists(AI_CONVERSATIONS_DIR)) {
    mkdir(AI_CONVERSATIONS_DIR, 0777, true);
}

// ============================================================================
// UTILITY FUNCTIONS
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
        return json_decode($content, true) ?: [];
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
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    
    fclose($fp);
    return false;
}

// ============================================================================
// CONVERSATION HISTORY MANAGEMENT
// ============================================================================

function getConversationFile($userId) {
    return AI_CONVERSATIONS_DIR . '/' . $userId . '.json';
}

function getConversationHistory($userId, $limit = 10) {
    $file = getConversationFile($userId);
    if (!file_exists($file)) {
        return [];
    }
    $history = aiLoadJSON($file);
    return array_slice($history, -$limit);
}

function saveConversationMessage($userId, $role, $message) {
    $file = getConversationFile($userId);
    $history = file_exists($file) ? aiLoadJSON($file) : [];
    
    $history[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'role' => $role,
        'message' => $message
    ];
    
    // Keep only last 50 messages per user
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }
    
    aiSaveJSON($file, $history);
}

function formatConversationHistory($history) {
    $formatted = "📝 Conversation History:\n";
    foreach ($history as $msg) {
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
        unlink($file);
        return true;
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
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
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
        
        if ($httpCode === 200) {
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $text = $result['candidates'][0]['content']['parts'][0]['text'];
                aiLog("✅ Gemini: $model SUCCESS - Got " . strlen($text) . " chars");
                return $text;
            } else {
                aiLog("❌ Gemini: $model - No text in response");
            }
        } else {
            aiLog("❌ Gemini: $model HTTP $httpCode - " . ($result['error']['message'] ?? 'Unknown error'));
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
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
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
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
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
    
    // Analysis/advice
    if (aiMatchesIntent($questionLower, ['analyze', 'analyze', 'opinion', 'advice', 'suggest', 'recommend', 'thoughts'])) {
        return "🧠 I'm happy to provide analysis, suggestions, and perspectives. Share what you'd like me to analyze or advise on, and I'll give you thoughtful insights!";
    }
    
    // Default helpful response
    return "🤖 I'm here to help! I can answer questions, provide information, write content, offer advice, or help with research on virtually any topic.\n\nWhat would you like to know?";
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
// EXPORT FUNCTIONS (Main API)
// ============================================================================

// Get AI response with full system
function getAIResponse($userId, $prompt, $context = '', $imageBase64 = null, $imageMimeType = null) {
    // ===== IMAGE GENERATION RESTRICTION =====
    // Block image generation requests
    $promptLower = strtolower($prompt);
    $imageKeywords = ['generate image', 'create image', 'make image', 'draw', 'create photo', 'generate photo', 'make picture', 'generate art', 'create art', 'design image', 'generate picture', 'edit image'];
    
    foreach ($imageKeywords as $keyword) {
        if (strpos($promptLower, $keyword) !== false) {
            $response = "📸 <b>Image Generation Not Available</b>\n\nSorry, image generation is not available at this time. However, I can help you with:\n\n• Questions and answers\n• Information and research\n• Creative writing and ideas\n• General conversations\n• Problem solving\n\nWhat else can I help you with?";
            
            // Save to history
            saveConversationMessage($userId, 'user', $prompt);
            saveConversationMessage($userId, 'assistant', $response);
            
            aiLog("🚫 Image generation blocked: $prompt");
            return $response;
        }
    }
    
    // Get conversation history
    $history = getConversationHistory($userId, 5);
    
    // Enhance context with history
    if (!empty($history)) {
        $historyText = formatConversationHistory($history);
        $context = $context ? "$context\n\n$historyText" : $historyText;
    }
    
    // Get AI response
    $response = askGemini($prompt, $context, $imageBase64, $imageMimeType);
    
    // Save to history
    saveConversationMessage($userId, 'user', $prompt);
    saveConversationMessage($userId, 'assistant', $response);
    
    return $response;
}

// Execute system command
function runSystemCommand($command) {
    return executeSystemCommand($command);
}

// Manage conversation
function getUserConversation($userId) {
    return getConversationHistory($userId, 50);
}

function clearUserConversation($userId) {
    return clearConversationHistory($userId);
}

?>
