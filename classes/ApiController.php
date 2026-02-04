<?php
class ApiController {
    private $db;
    private $groqAI;
    private $webSearch;
    private $aiAnalyzer;
    private $voiceSynthesizer;
    private $analyticsTracker;
    private $cacheManager;
    private $responseFormatter;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->cacheManager = new CacheManager($this->db);
        $this->groqAI = new GroqAI($this->db, $this->cacheManager);
        $this->webSearch = new WebSearch($this->db);
        $this->aiAnalyzer = new AIAnalyzer($this->db, $this->webSearch);
        $this->voiceSynthesizer = new VoiceSynthesizer($this->db);
        $this->analyticsTracker = new AnalyticsTracker($this->db);
        $this->responseFormatter = new ResponseFormatter($this->db);
    }
    
    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            
            switch ($method) {
                case 'POST':
                    return $this->handlePostRequest();
                    
                case 'GET':
                    return $this->handleGetRequest();
                    
                case 'OPTIONS':
                    return $this->handleOptionsRequest();
                    
                default:
                    throw new Exception('Method not allowed', 405);
            }
            
        } catch (Exception $e) {
            return $this->handleError($e);
        }
    }
    
    private function handlePostRequest() {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        
        if (empty($input['action'])) {
            $input['action'] = 'chat';
        }
        
        switch ($input['action']) {
            case 'chat':
                return $this->handleChat($input);
                
            case 'web_search':
                return $this->handleWebSearch($input);
                
            case 'analyze':
                return $this->handleAnalyze($input);
                
            case 'translate':
                return $this->handleTranslation($input);
                
            case 'summarize':
                return $this->handleSummarization($input);
                
            case 'code_assist':
                return $this->handleCodeAssist($input);
                
            case 'voice_synthesize':
                return $this->handleVoiceSynthesis($input);
                
            case 'get_stats':
                return $this->handleGetStats($input);
                
            case 'clear_cache':
                return $this->handleClearCache($input);
                
            case 'health_check':
                return $this->handleHealthCheck();
                
            default:
                throw new Exception('Unknown action: ' . $input['action'], 400);
        }
    }
    
    private function handleGetRequest() {
        $action = $_GET['action'] ?? 'status';
        
        switch ($action) {
            case 'status':
                return $this->getSystemStatus();
                
            case 'models':
                return $this->getAvailableModels();
                
            case 'stats':
                return $this->getPublicStats();
                
            case 'session':
                return $this->getSessionInfo();
                
            case 'export':
                return $this->exportData($_GET);
                
            default:
                throw new Exception('Unknown action', 400);
        }
    }
    
    private function handleOptionsRequest() {
        header('HTTP/1.1 200 OK');
        exit();
    }
    
    private function handleChat($input) {
        $startTime = microtime(true);
        
        // Validate input
        if (empty($input['message'])) {
            throw new Exception('Message is required', 400);
        }
        
        $userMessage = sanitizeInput($input['message']);
        $sessionId = $_SESSION['jarvis_session_id'] ?? 'unknown';
        
        // Get context from previous messages if available
        $context = $this->getConversationContext($sessionId, $input['context'] ?? []);
        
        // Analyze the query
        $analysis = $this->aiAnalyzer->analyzeQuery($userMessage, $context);
        
        // Gather information from various sources
        $information = $this->aiAnalyzer->gatherInformation($userMessage, $analysis);
        
        // Build system prompt
        $systemPrompt = $this->buildSystemPrompt($analysis, $information, $context);
        
        // Generate response using Groq AI
        $aiOptions = [
            'temperature' => $input['temperature'] ?? AI_TEMPERATURE,
            'max_tokens' => $input['max_tokens'] ?? MAX_TOKENS,
            'model' => $input['model'] ?? DEFAULT_GROQ_MODEL,
            'use_cache' => !($input['force_fresh'] ?? false)
        ];
        
        $aiResponse = $this->groqAI->generateResponse($systemPrompt, $userMessage, $aiOptions);
        
        // Format the response
        $formattedResponse = $this->responseFormatter->formatAIResponse($aiResponse, [
            'analysis' => $analysis,
            'information' => $information
        ], [
            'format' => $input['format'] ?? 'json',
            'include_sources' => $input['include_sources'] ?? true,
            'style' => $input['style'] ?? 'jarvis'
        ]);
        
        // Track analytics
        $metadata = [
            'tokens_used' => $aiResponse['tokens_used'],
            'model' => $aiOptions['model'],
            'source' => 'groq',
            'cache_hit' => $aiResponse['cache_hit'] ?? false,
            'response_time' => round((microtime(true) - $startTime) * 1000, 2)
        ];
        
        $this->analyticsTracker->trackQuery($userMessage, $aiResponse, $metadata);
        
        // Learn from this interaction
        $this->aiAnalyzer->learnFromInteraction($userMessage, $aiResponse['response']);
        
        // Store in conversation history
        $this->storeConversationHistory($sessionId, $userMessage, $aiResponse['response']);
        
        // Prepare final response
        $response = [
            'success' => true,
            'action' => 'chat',
            'response' => $formattedResponse,
            'analysis' => $analysis,
            'metadata' => $metadata,
            'session_id' => $sessionId,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Add voice synthesis if requested
        if (($input['generate_voice'] ?? false) && ENABLE_VOICE_SYNTHESIS) {
            $voiceResponse = $this->voiceSynthesizer->speakResponse($aiResponse, $sessionId);
            if ($voiceResponse) {
                $response['voice'] = $voiceResponse;
            }
        }
        
        return $response;
    }
    
    private function handleWebSearch($input) {
        if (empty($input['query'])) {
            throw new Exception('Search query is required', 400);
        }
        
        $query = sanitizeInput($input['query']);
        $options = [
            'limit' => $input['limit'] ?? SEARCH_RESULTS_LIMIT,
            'force_fresh' => $input['force_fresh'] ?? false,
            'sources' => $input['sources'] ?? ['ddg', 'wiki', 'github']
        ];
        
        $searchResults = $this->webSearch->search($query, $options);
        
        // Analyze search results
        $analysis = [];
        if (!empty($searchResults)) {
            $analysis = $this->analyzeSearchResults($searchResults);
        }
        
        // Generate summary if requested
        $summary = null;
        if ($input['generate_summary'] ?? false) {
            $summary = $this->generateSearchSummary($searchResults, $query);
        }
        
        return [
            'success' => true,
            'action' => 'web_search',
            'query' => $query,
            'results' => $searchResults,
            'analysis' => $analysis,
            'summary' => $summary,
            'result_count' => count($searchResults),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleAnalyze($input) {
        if (empty($input['text'])) {
            throw new Exception('Text to analyze is required', 400);
        }
        
        $text = sanitizeInput($input['text']);
        
        // Perform different types of analysis
        $analysis = [];
        
        if ($input['type'] === 'sentiment' || $input['type'] === 'all') {
            $analysis['sentiment'] = $this->groqAI->analyzeSentiment($text, true);
        }
        
        if ($input['type'] === 'keywords' || $input['type'] === 'all') {
            $analysis['keywords'] = $this->groqAI->extractKeywords($text, $input['max_keywords'] ?? 10);
        }
        
        if ($input['type'] === 'entities' || $input['type'] === 'all') {
            $analysis['entities'] = $this->aiAnalyzer->analyzeQuery($text);
        }
        
        return [
            'success' => true,
            'action' => 'analyze',
            'text' => $text,
            'analysis' => $analysis,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleTranslation($input) {
        if (empty($input['text']) || empty($input['target_lang'])) {
            throw new Exception('Text and target language are required', 400);
        }
        
        $text = sanitizeInput($input['text']);
        $targetLang = sanitizeInput($input['target_lang']);
        $sourceLang = $input['source_lang'] ?? 'auto';
        
        $translation = $this->groqAI->translateText($text, $targetLang, $sourceLang);
        
        return [
            'success' => true,
            'action' => 'translate',
            'original_text' => $text,
            'translated_text' => $translation['response'],
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
            'metadata' => [
                'model' => $translation['model'],
                'tokens_used' => $translation['tokens_used'],
                'response_time' => $translation['response_time']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleSummarization($input) {
        if (empty($input['text'])) {
            throw new Exception('Text to summarize is required', 400);
        }
        
        $text = sanitizeInput($input['text']);
        $ratio = min(1.0, max(0.1, $input['ratio'] ?? 0.3));
        
        $summary = $this->groqAI->summarizeText($text, $ratio);
        
        return [
            'success' => true,
            'action' => 'summarize',
            'original_length' => strlen($text),
            'summary_length' => strlen($summary['response']),
            'compression_ratio' => round(strlen($summary['response']) / strlen($text) * 100, 2) . '%',
            'summary' => $summary['response'],
            'metadata' => [
                'model' => $summary['model'],
                'tokens_used' => $summary['tokens_used'],
                'response_time' => $summary['response_time']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleCodeAssist($input) {
        if (empty($input['query'])) {
            throw new Exception('Code assistance query is required', 400);
        }
        
        $query = sanitizeInput($input['query']);
        $language = $input['language'] ?? 'php';
        $context = $input['context'] ?? '';
        
        $codeAssist = $this->groqAI->codeAssistant($query, $language, $context);
        
        // Format code response
        $formattedCode = $this->responseFormatter->formatCode(
            $codeAssist['response'],
            $language,
            $input['format'] ?? 'html'
        );
        
        return [
            'success' => true,
            'action' => 'code_assist',
            'query' => $query,
            'language' => $language,
            'response' => $formattedCode,
            'metadata' => [
                'model' => $codeAssist['model'],
                'tokens_used' => $codeAssist['tokens_used'],
                'response_time' => $codeAssist['response_time']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleVoiceSynthesis($input) {
        if (empty($input['text'])) {
            throw new Exception('Text for voice synthesis is required', 400);
        }
        
        $text = sanitizeInput($input['text']);
        $options = [
            'language' => $input['language'] ?? DEFAULT_VOICE_LANG,
            'speed' => $input['speed'] ?? VOICE_SPEED,
            'pitch' => $input['pitch'] ?? VOICE_PITCH,
            'cache' => $input['cache'] ?? true
        ];
        
        $voiceResponse = $this->voiceSynthesizer->textToSpeech($text, $options);
        
        if (!$voiceResponse) {
            throw new Exception('Voice synthesis failed', 500);
        }
        
        return [
            'success' => true,
            'action' => 'voice_synthesize',
            'text' => $text,
            'audio' => $voiceResponse,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleGetStats($input) {
        $period = $input['period'] ?? 'today';
        $statsType = $input['type'] ?? 'system';
        
        switch ($statsType) {
            case 'system':
                $stats = $this->analyticsTracker->getSystemStats($period);
                break;
                
            case 'session':
                $sessionId = $input['session_id'] ?? $_SESSION['jarvis_session_id'] ?? 'unknown';
                $stats = $this->analyticsTracker->getSessionStats($sessionId);
                break;
                
            case 'realtime':
                $stats = $this->analyticsTracker->getRealTimeStats();
                break;
                
            case 'performance':
                $stats = $this->analyticsTracker->getPerformanceMetrics($period);
                break;
                
            case 'errors':
                $stats = $this->analyticsTracker->getErrorAnalysis($period);
                break;
                
            case 'cache':
                $stats = $this->cacheManager->getStats();
                break;
                
            case 'voice':
                $stats = $this->voiceSynthesizer->getVoiceStats($period);
                break;
                
            default:
                throw new Exception('Unknown stats type', 400);
        }
        
        return [
            'success' => true,
            'action' => 'get_stats',
            'type' => $statsType,
            'period' => $period,
            'stats' => $stats,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleClearCache($input) {
        $type = $input['type'] ?? 'all';
        $olderThan = $input['older_than'] ?? null;
        
        $result = $this->cacheManager->clear($type, $olderThan);
        
        // Also clear expired cache from database
        $this->webSearch->clearExpiredCache();
        
        return [
            'success' => true,
            'action' => 'clear_cache',
            'type' => $type,
            'cleared' => $result,
            'message' => 'Cache cleared successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleHealthCheck() {
        $health = [
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'components' => []
        ];
        
        // Check database
        try {
            $this->db->executeQuery('SELECT 1');
            $health['components']['database'] = 'healthy';
        } catch (Exception $e) {
            $health['components']['database'] = 'unhealthy';
            $health['status'] = 'degraded';
        }
        
        // Check Groq API
        try {
            $apiStatus = $this->groqAI->validateAPIKey();
            $health['components']['groq_api'] = $apiStatus['valid'] ? 'healthy' : 'unhealthy';
            $health['groq_response_time'] = $apiStatus['response_time'] ?? 0;
        } catch (Exception $e) {
            $health['components']['groq_api'] = 'unhealthy';
            $health['status'] = 'degraded';
        }
        
        // Check cache
        $cacheStats = $this->cacheManager->getStats();
        $health['components']['cache'] = $cacheStats['enabled'] ? 'healthy' : 'disabled';
        $health['cache_hit_rate'] = $cacheStats['hit_rate'] ?? 0;
        
        // Check web search
        $health['components']['web_search'] = ENABLE_WEB_SEARCH ? 'enabled' : 'disabled';
        
        // Check voice synthesis
        $health['components']['voice_synthesis'] = ENABLE_VOICE_SYNTHESIS ? 'enabled' : 'disabled';
        
        // System load
        $health['system_load'] = [
            'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'peak_memory' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
            'uptime' => $this->getSystemUptime()
        ];
        
        return [
            'success' => true,
            'action' => 'health_check',
            'health' => $health,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function getSystemStatus() {
        $status = [
            'system' => 'JARVIS OSINT AI v5.0',
            'status' => 'online',
            'version' => '5.0.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'uptime' => $this->getSystemUptime(),
            'environment' => DEBUG_MODE ? 'development' : 'production',
            'features' => [
                'groq_ai' => true,
                'web_search' => ENABLE_WEB_SEARCH,
                'voice_synthesis' => ENABLE_VOICE_SYNTHESIS,
                'caching' => ENABLE_CACHING,
                'analytics' => true
            ]
        ];
        
        return $status;
    }
    
    private function getAvailableModels() {
        $models = $this->groqAI->getAvailableModels();
        
        return [
            'success' => true,
            'models' => $models,
            'default_model' => DEFAULT_GROQ_MODEL,
            'backup_model' => BACKUP_MODEL,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function getPublicStats() {
        $stats = $this->analyticsTracker->getSystemStats('today');
        
        // Remove sensitive information
        unset($stats['daily']);
        unset($stats['trends']);
        
        return [
            'success' => true,
            'stats' => [
                'total_queries' => $stats['total']['queries'],
                'total_sessions' => $stats['total']['sessions'],
                'avg_response_time' => $stats['total']['avg_response_time'],
                'cache_hit_rate' => round(($stats['total']['cache_hits'] / max(1, $stats['total']['queries'])) * 100, 2)
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function getSessionInfo() {
        $sessionId = $_SESSION['jarvis_session_id'] ?? 'unknown';
        
        $sessionInfo = [
            'session_id' => $sessionId,
            'start_time' => $_SESSION['jarvis_start_time'] ?? time(),
            'duration' => time() - ($_SESSION['jarvis_start_time'] ?? time()),
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        // Get session stats
        $sessionStats = $this->analyticsTracker->getSessionStats($sessionId);
        
        return [
            'success' => true,
            'session' => $sessionInfo,
            'stats' => $sessionStats,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function exportData($params) {
        $format = $params['format'] ?? 'json';
        $period = $params['period'] ?? 'today';
        $type = $params['type'] ?? 'stats';
        
        switch ($type) {
            case 'stats':
                $data = $this->analyticsTracker->exportData($format, $period);
                break;
                
            case 'queries':
                $data = $this->getTopQueriesForExport($params['limit'] ?? 100);
                break;
                
            case 'errors':
                $data = $this->analyticsTracker->getErrorAnalysis($period);
                break;
                
            default:
                throw new Exception('Invalid export type', 400);
        }
        
        // Set appropriate headers
        switch ($format) {
            case 'csv':
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="jarvis_export_' . date('Y-m-d') . '.csv"');
                break;
                
            case 'json':
                header('Content-Type: application/json');
                break;
                
            case 'html':
                header('Content-Type: text/html');
                break;
        }
        
        return $data;
    }
    
    private function handleError(Exception $e) {
        $errorCode = $e->getCode() ?: 500;
        http_response_code($errorCode);
        
        $errorResponse = [
            'success' => false,
            'error' => [
                'message' => $e->getMessage(),
                'code' => $errorCode,
                'type' => get_class($e)
            ],
            'timestamp' => date('Y-m-d H:i:s'),
            'session_id' => $_SESSION['jarvis_session_id'] ?? 'unknown'
        ];
        
        // Add debug info in development
        if (DEBUG_MODE) {
            $errorResponse['debug'] = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }
        
        // Log the error
        error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        
        return $errorResponse;
    }
    
    private function getConversationContext($sessionId, $providedContext = []) {
        // Get last 5 messages from this session
        $sql = "SELECT query, response 
                FROM analytics 
                WHERE session_id = ? 
                ORDER BY created_at DESC 
                LIMIT 5";
        
        $stmt = $this->db->executeQuery($sql, [$sessionId], 's');
        $result = $stmt->get_result();
        
        $context = [];
        while ($row = $result->fetch_assoc()) {
            $context[] = [
                'query' => $row['query'],
                'response' => $row['response']
            ];
        }
        
        // Add provided context
        if (!empty($providedContext)) {
            $context = array_merge($context, $providedContext);
        }
        
        return array_reverse($context); // Oldest first
    }
    
    private function buildSystemPrompt($analysis, $information, $context) {
        $prompt = <<<PROMPT
        Siz doston-davlatov.uz saytining JARVIS ismli OSINT AI yordamchisiz.
        Siz Groq API orqali ishlaysiz va internetdagi barcha ochiq manbalardan foydalanasiz.
        
        SHAXSIY MA'LUMOTLAR:
        Ism: Doston Davlatov
        Kasb: Full Stack Developer & AI Engineer
        Manzil: Toshkent, O'zbekiston
        Tajriba: 5+ yil
        Veb-sayt: https://doston-davlatov.uz
        
        QOBILIYATLAR:
        1. Portfel ma'lumotlarini bilib olish
        2. Real-time web qidiruv
        3. Kod yozish va tahlil qilish
        4. Matnni tarjima qilish
        5. Sentiment tahlili
        6. Ma'lumotlarni umumlashtirish
        
        USLUB:
        - Tony Starkning JARVIS'i kabi muloqot qiling
        - Har bir javobni "Sir" deb boshlang
        - Aniq va qisqa javob bering
        - Manbalarni ko'rsating
        - Professional va yordamchi bo'ling
        
        SAVOL TAHLILI:
        Turi: {$analysis['type']}
        Kategoriya: {$analysis['category']}
        Murakkablik: {$analysis['complexity']['level']}
        Kayfiyat: {$analysis['sentiment']}
        Til: {$analysis['language']}
        
        MAVZULAR: {$this->formatTopics($analysis['topics'])}
        PROMPT;
        
        // Add local information if available
        if (!empty($information['local'])) {
            $prompt .= "\n\nLOKAL MA'LUMOTLAR:\n";
            
            if (!empty($information['local']['projects'])) {
                $prompt .= "LOYIHALAR:\n";
                foreach ($information['local']['projects'] as $project) {
                    $prompt .= "- {$project['title']}: {$project['description']}\n";
                }
                $prompt .= "\n";
            }
            
            if (!empty($information['local']['blogs'])) {
                $prompt .= "BLOGLAR:\n";
                foreach ($information['local']['blogs'] as $blog) {
                    $prompt .= "- {$blog['title']}: {$blog['description']}\n";
                }
                $prompt .= "\n";
            }
        }
        
        // Add web information if available
        if (!empty($information['web'])) {
            $prompt .= "WEB QIDIRUV NATIJALARI:\n";
            foreach ($information['web'] as $result) {
                $prompt .= "- [{$result['source']}] {$result['title']}: {$result['snippet']}\n";
            }
            $prompt .= "\n";
        }
        
        // Add conversation context
        if (!empty($context)) {
            $prompt .= "OLDINGI SUHBAT:\n";
            foreach ($context as $message) {
                if (isset($message['query'])) {
                    $prompt .= "Foydalanuvchi: {$message['query']}\n";
                }
                if (isset($message['response'])) {
                    $prompt .= "JARVIS: {$message['response']}\n";
                }
            }
            $prompt .= "\n";
        }
        
        $prompt .= <<<PROMPT
        
        VAQT: {current_time}
        TIZIM HOLATI: ONLINE
        
        ENDI FOYDALANUVCHINING SAVOLIGA JAVOB BERING:
        PROMPT;
        
        $prompt = str_replace('{current_time}', date('Y-m-d H:i:s T'), $prompt);
        
        return substr($prompt, 0, MAX_CONTEXT_LENGTH);
    }
    
    private function formatTopics($topics) {
        return !empty($topics) ? implode(', ', $topics) : 'mavzu aniq emas';
    }
    
    private function storeConversationHistory($sessionId, $query, $response) {
        // This is already done by analytics tracker
        // Additional storage can be added here if needed
        return true;
    }
    
    private function analyzeSearchResults($results) {
        $sources = [];
        $types = [];
        
        foreach ($results as $result) {
            $sources[] = $result['source'] ?? 'unknown';
            $types[] = $result['type'] ?? 'general';
        }
        
        return [
            'source_distribution' => array_count_values($sources),
            'type_distribution' => array_count_values($types),
            'total_results' => count($results),
            'unique_sources' => count(array_unique($sources))
        ];
    }
    
    private function generateSearchSummary($results, $query) {
        if (empty($results)) {
            return "Hech qanday natija topilmadi.";
        }
        
        $summary = "Qidiruv natijalari: \"{$query}\"\n\n";
        $summary .= "Jami natijalar: " . count($results) . "\n\n";
        
        $sourceCounts = [];
        foreach ($results as $result) {
            $source = $result['source'] ?? 'unknown';
            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
        }
        
        $summary .= "Manbalar bo'yicha taqsimot:\n";
        foreach ($sourceCounts as $source => $count) {
            $summary .= "- {$source}: {$count} ta natija\n";
        }
        
        return $summary;
    }
    
    private function getTopQueriesForExport($limit) {
        $queries = $this->analyticsTracker->getTopQueries($limit, 'month');
        
        $csv = "Query,Frequency,Avg Response Time,Avg Tokens,First Asked,Last Asked\n";
        
        foreach ($queries as $query) {
            $csv .= sprintf('"%s",%d,%d,%d,%s,%s' . "\n",
                str_replace('"', '""', $query['query']),
                $query['frequency'],
                $query['avg_response_time'],
                $query['avg_tokens'],
                $query['first_asked'],
                $query['last_asked']
            );
        }
        
        return $csv;
    }
    
    private function getSystemUptime() {
        // This is a simplified version
        // In production, you might want to track actual start time
        $startTime = $_SESSION['jarvis_start_time'] ?? time();
        $uptime = time() - $startTime;
        
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = $uptime % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
}
?>
