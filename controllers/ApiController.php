<!--controllers/ApiController.php-->
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
    
    public function handlePostRequest($input) {
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
                
            case 'learn':
                return $this->handleLearning($input);
                
            case 'search_images':
                return $this->handleImageSearch($input);
                
            case 'get_models':
                return $this->getAvailableModels();
                
            case 'test_api':
                return $this->handleApiTest($input);
                
            default:
                throw new Exception('Unknown action: ' . $input['action'], 400);
        }
    }
    
    public function handleGetRequest($params) {
        switch ($params['action']) {
            case 'status':
                return $this->getSystemStatus();
                
            case 'models':
                return $this->getAvailableModels();
                
            case 'stats':
                return $this->getPublicStats($params);
                
            case 'session':
                return $this->getSessionInfo();
                
            case 'export':
                return $this->exportData($params);
                
            case 'docs':
                return $this->getApiDocumentation();
                
            case 'ping':
                return $this->handlePing();
                
            case 'audio':
                return $this->serveAudioFile($params);
                
            default:
                throw new Exception('Unknown action', 400);
        }
    }
    
    private function handleChat($input) {
        $startTime = microtime(true);
        
        // Validate input
        if (empty($input['message'])) {
            throw new Exception('Message is required', 400);
        }
        
        $userMessage = sanitizeInput($input['message']);
        $sessionId = $_SESSION['jarvis_session_id'] ?? 'unknown';
        
        // Get conversation context
        $context = $this->getConversationContext($sessionId, $input['context'] ?? []);
        
        // Analyze the query
        $analysis = $this->aiAnalyzer->analyzeQuery($userMessage, $context);
        
        // Gather information from various sources
        $information = $this->aiAnalyzer->gatherInformation($userMessage, $analysis);
        
        // Build system prompt
        $systemPrompt = $this->buildSystemPrompt($analysis, $information, $context);
        
        // Prepare AI options
        $aiOptions = [
            'temperature' => min(1.0, max(0.1, $input['temperature'] ?? AI_TEMPERATURE)),
            'max_tokens' => min(4000, max(100, $input['max_tokens'] ?? MAX_TOKENS)),
            'model' => $input['model'] ?? DEFAULT_GROQ_MODEL,
            'use_cache' => !($input['force_fresh'] ?? false),
            'timeout' => $input['timeout'] ?? 30
        ];
        
        // Generate response using Groq AI
        $aiResponse = $this->groqAI->generateResponse($systemPrompt, $userMessage, $aiOptions);
        
        // Format the response
        $formattedResponse = $this->responseFormatter->formatAIResponse($aiResponse, [
            'analysis' => $analysis,
            'information' => $information
        ], [
            'format' => $input['format'] ?? 'json',
            'include_sources' => $input['include_sources'] ?? true,
            'include_metadata' => $input['include_metadata'] ?? true,
            'style' => $input['style'] ?? 'jarvis',
            'language' => $input['language'] ?? 'uz'
        ]);
        
        // Calculate response time
        $totalResponseTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Track analytics
        $metadata = [
            'tokens_used' => $aiResponse['tokens_used'],
            'model' => $aiOptions['model'],
            'source' => 'groq',
            'cache_hit' => $aiResponse['cache_hit'] ?? false,
            'response_time' => $totalResponseTime,
            'ai_response_time' => $aiResponse['response_time'] ?? 0
        ];
        
        $analyticsId = $this->analyticsTracker->trackQuery($userMessage, $aiResponse, $metadata);
        
        // Learn from this interaction
        $this->aiAnalyzer->learnFromInteraction($userMessage, $aiResponse['response'], 1.0);
        
        // Store in conversation history
        $this->storeConversationHistory($sessionId, $userMessage, $aiResponse['response']);
        
        // Prepare final response
        $response = [
            'success' => true,
            'action' => 'chat',
            'response' => $formattedResponse,
            'analysis' => $analysis,
            'metadata' => array_merge($metadata, [
                'analytics_id' => $analyticsId,
                'session_id' => $sessionId
            ]),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Add voice synthesis if requested
        if (($input['generate_voice'] ?? false) && ENABLE_VOICE_SYNTHESIS) {
            $voiceOptions = [
                'language' => $input['voice_language'] ?? DEFAULT_VOICE_LANG,
                'speed' => $input['voice_speed'] ?? VOICE_SPEED,
                'pitch' => $input['voice_pitch'] ?? VOICE_PITCH
            ];
            
            $voiceResponse = $this->voiceSynthesizer->textToSpeech($aiResponse['response'], $voiceOptions);
            if ($voiceResponse) {
                $response['voice'] = $voiceResponse;
            }
        }
        
        // Add web search results if requested
        if (($input['include_raw_results'] ?? false) && !empty($information['web'])) {
            $response['raw_results'] = $information['web'];
/workspace/jarvis$ /bin/bash -lc sed -n '200,400p' controllers/ApiController.php
$response['raw_results'] = $information['web'];
        }
        
        return $response;
    }
    
    private function handleWebSearch($input) {
        if (empty($input['query'])) {
            throw new Exception('Search query is required', 400);
        }
        
        $query = sanitizeInput($input['query']);
        $options = [
            'limit' => min(20, max(1, $input['limit'] ?? SEARCH_RESULTS_LIMIT)),
            'force_fresh' => $input['force_fresh'] ?? false,
            'sources' => $input['sources'] ?? ['ddg', 'wiki'],
            'timeout' => $input['timeout'] ?? SEARCH_TIMEOUT
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
        
        // Get detailed information for top results
        $detailedResults = [];
        if ($input['get_details'] ?? false) {
            $detailedResults = $this->getDetailedResults($searchResults);
        }
        
        return [
            'success' => true,
            'action' => 'web_search',
            'query' => $query,
            'results' => $searchResults,
            'analysis' => $analysis,
            'summary' => $summary,
            'detailed_results' => $detailedResults,
            'result_count' => count($searchResults),
            'cached' => $searchResults['cached'] ?? false,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleAnalyze($input) {
        if (empty($input['text'])) {
            throw new Exception('Text to analyze is required', 400);
        }
        
        $text = sanitizeInput($input['text']);
        $analysisType = $input['type'] ?? 'all';
        $detailed = $input['detailed'] ?? false;
        
        // Perform analysis based on type
        $analysis = [];
        
        // Sentiment analysis
        if ($analysisType === 'sentiment' || $analysisType === 'all') {
            $analysis['sentiment'] = $this->groqAI->analyzeSentiment($text, $detailed);
        }
        
        // Keyword extraction
        if ($analysisType === 'keywords' || $analysisType === 'all') {
            $analysis['keywords'] = $this->groqAI->extractKeywords($text, $input['max_keywords'] ?? 10);
        }
        
        // Entity recognition
        if ($analysisType === 'entities' || $analysisType === 'all') {
            $analysis['entities'] = $this->aiAnalyzer->analyzeQuery($text);
        }
        
        // Complexity analysis
        if ($analysisType === 'complexity' || $analysisType === 'all') {
            $complexity = $this->aiAnalyzer->analyzeQuery($text);
            $analysis['complexity'] = $complexity['complexity'] ?? [];
        }
        
        // Language detection
        if ($analysisType === 'language' || $analysisType === 'all') {
            $analysis['language'] = $this->detectLanguage($text);
        }
        
        // Summarization (if text is long)
        if (($analysisType === 'summary' || $analysisType === 'all') && strlen($text) > 500) {
            $analysis['summary'] = $this->groqAI->summarizeText($text, 0.3);
        }
        
        return [
            'success' => true,
            'action' => 'analyze',
            'text' => $text,
            'analysis_type' => $analysisType,
            'analysis' => $analysis,
            'text_length' => strlen($text),
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
        $preserve_formatting = $input['preserve_formatting'] ?? true;
        
        // Check if text is too long
        if (strlen($text) > 4000) {
            throw new Exception('Text too long for translation. Maximum 4000 characters.', 400);
        }
        
        $translation = $this->groqAI->translateText($text, $targetLang, $sourceLang);
        
        // Format response
        $formattedTranslation = $this->responseFormatter->formatAIResponse($translation, [], [
            'format' => $input['format'] ?? 'plain',
            'style' => 'simple'
        ]);
        
        return [
            'success' => true,
            'action' => 'translate',
            'original_text' => $text,
            'translated_text' => $translation['response'],
            'formatted_translation' => $formattedTranslation,
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
        $style = $input['style'] ?? 'professional';
        $include_bullets = $input['include_bullets'] ?? true;
        
        // Check text length
        if (strlen($text) < 100) {
            throw new Exception('Text too short for summarization. Minimum 100 characters.', 400);
        }
        
        $summary = $this->groqAI->summarizeText($text, $ratio);
        
        // Format the summary
        $formattedSummary = $this->responseFormatter->formatAIResponse($summary, [], [
            'format' => $input['format'] ?? 'html',
            'style' => $style
        ]);
        
        // Extract key points if requested
        $keyPoints = [];
        if ($include_bullets && strlen($summary['response']) > 200) {
            $keyPoints = $this->extractKeyPoints($summary['response']);
        }
        
        return [
            'success' => true,
            'action' => 'summarize',
            'original_length' => strlen($text),
            'summary_length' => strlen($summary['response']),
            'compression_ratio' => round(strlen($summary['response']) / strlen($text) * 100, 2) . '%',
            'summary' => $summary['response'],
            'formatted_summary' => $formattedSummary,
            'key_points' => $keyPoints,
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
/workspace/jarvis$ /bin/bash -lc sed -n '400,800p' controllers/ApiController.php
$language = $input['language'] ?? 'php';
        $context = $input['context'] ?? '';
        $generate_tests = $input['generate_tests'] ?? false;
        $explain_code = $input['explain_code'] ?? true;
        
        $codeAssist = $this->groqAI->codeAssistant($query, $language, $context);
        
        // Format code response
        $formattedCode = $this->responseFormatter->formatCode(
            $codeAssist['response'],
            $language,
            $input['format'] ?? 'html'
        );
        
        // Generate tests if requested
        $tests = null;
        if ($generate_tests) {
            $tests = $this->generateTests($codeAssist['response'], $language);
        }
        
        // Explain code if requested
        $explanation = null;
        if ($explain_code) {
            $explanation = $this->explainCode($codeAssist['response'], $language);
        }
        
        return [
            'success' => true,
            'action' => 'code_assist',
            'query' => $query,
            'language' => $language,
            'code' => $codeAssist['response'],
            'formatted_code' => $formattedCode,
            'tests' => $tests,
            'explanation' => $explanation,
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
            'speed' => min(2.0, max(0.5, $input['speed'] ?? VOICE_SPEED)),
            'pitch' => min(2.0, max(0.5, $input['pitch'] ?? VOICE_PITCH)),
            'cache' => $input['cache'] ?? true,
            'format' => $input['format'] ?? 'mp3'
        ];
        
        // Check text length
        if (strlen($text) > 2000) {
            throw new Exception('Text too long for voice synthesis. Maximum 2000 characters.', 400);
        }
        
        $voiceResponse = $this->voiceSynthesizer->textToSpeech($text, $options);
        
        if (!$voiceResponse) {
            throw new Exception('Voice synthesis failed', 500);
        }
        
        return [
            'success' => true,
            'action' => 'voice_synthesize',
            'text' => $text,
            'audio' => $voiceResponse,
            'options' => $options,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleGetStats($input) {
        $period = $input['period'] ?? 'today';
        $statsType = $input['type'] ?? 'system';
        $detailed = $input['detailed'] ?? false;
        
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
                
            case 'models':
                $stats = $this->groqAI->getUsageStats($period);
                break;
                
            case 'top_queries':
                $limit = min(50, max(1, $input['limit'] ?? 10));
                $stats = $this->analyticsTracker->getTopQueries($limit, $period);
                break;
                
            case 'engagement':
                $stats = $this->analyticsTracker->getUserEngagementMetrics();
                break;
                
            default:
                throw new Exception('Unknown stats type', 400);
        }
        
        $response = [
            'success' => true,
            'action' => 'get_stats',
            'type' => $statsType,
            'period' => $period,
            'stats' => $stats,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Add detailed information if requested
        if ($detailed && $statsType === 'system') {
            $response['detailed'] = [
                'hourly_metrics' => $this->analyticsTracker->getPerformanceMetrics($period),
                'top_queries' => $this->analyticsTracker->getTopQueries(10, $period),
                'error_analysis' => $this->analyticsTracker->getErrorAnalysis($period)
            ];
        }
        
        return $response;
    }
    
    private function handleClearCache($input) {
        $type = $input['type'] ?? 'all';
        $olderThan = $input['older_than'] ?? null;
        $confirm = $input['confirm'] ?? false;
        
        if (!$confirm) {
            throw new Exception('Please confirm cache clearance by setting confirm=true', 400);
        }
        
        $result = $this->cacheManager->clear($type, $olderThan);
        
        // Also clear expired cache from web search
        $webSearchCleared = $this->webSearch->clearExpiredCache();
        
        // Optimize database
        $this->cacheManager->optimize();
        
        return [
            'success' => true,
            'action' => 'clear_cache',
            'type' => $type,
            'cleared' => $result,
            'web_search_cache_cleared' => $webSearchCleared,
            'message' => 'Cache cleared successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleHealthCheck() {
        $health = [
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'components' => [],
            'metrics' => []
        ];
        
        // Check database
        try {
            $this->db->executeQuery('SELECT 1');
            $health['components']['database'] = 'healthy';
        } catch (Exception $e) {
            $health['components']['database'] = 'unhealthy';
            $health['status'] = 'degraded';
            $health['database_error'] = $e->getMessage();
        }
        
        // Check Groq API
        try {
            $apiStatus = $this->groqAI->validateAPIKey();
            $health['components']['groq_api'] = $apiStatus['valid'] ? 'healthy' : 'unhealthy';
            $health['groq_response_time'] = $apiStatus['response_time'] ?? 0;
        } catch (Exception $e) {
            $health['components']['groq_api'] = 'unhealthy';
            $health['status'] = 'degraded';
            $health['groq_error'] = $e->getMessage();
        }
        
        // Check cache
        $cacheStats = $this->cacheManager->getStats();
        $health['components']['cache'] = $cacheStats['enabled'] ? 'healthy' : 'disabled';
        $health['cache_hit_rate'] = $cacheStats['hit_rate'] ?? 0;
        
        // Check web search
        $health['components']['web_search'] = ENABLE_WEB_SEARCH ? 'enabled' : 'disabled';
        
        // Check voice synthesis
        $health['components']['voice_synthesis'] = ENABLE_VOICE_SYNTHESIS ? 'enabled' : 'disabled';
        
        // System metrics
        $health['metrics'] = [
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            'uptime' => $this->getSystemUptime(),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : 'unknown'
        ];
        
        // Disk space
        if (function_exists('disk_free_space')) {
            $freeSpace = disk_free_space(ROOT_PATH);
            $totalSpace = disk_total_space(ROOT_PATH);
            if ($freeSpace !== false && $totalSpace !== false) {
                $health['metrics']['disk_usage'] = [
                    'free' => round($freeSpace / 1024 / 1024 / 1024, 2) . ' GB',
                    'total' => round($totalSpace / 1024 / 1024 / 1024, 2) . ' GB',
                    'used_percentage' => round((1 - ($freeSpace / $totalSpace)) * 100, 2) . '%'
                ];
            }
        }
        
        return [
            'success' => true,
            'action' => 'health_check',
            'health' => $health,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleLearning($input) {
        if (empty($input['query']) || empty($input['response'])) {
            throw new Exception('Query and response are required for learning', 400);
        }
        
        $query = sanitizeInput($input['query']);
        $response = sanitizeInput($input['response']);
        $quality = min(1.0, max(0.0, $input['quality'] ?? 1.0));
        $category = $input['category'] ?? 'general';
        
        $result = $this->aiAnalyzer->learnFromInteraction($query, $response, $quality);
        
        return [
            'success' => true,
            'action' => 'learn',
            'learned' => $result,
            'query' => $query,
            'category' => $category,
            'quality_score' => $quality,
            'message' => 'Knowledge added to learning database',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleImageSearch($input) {
        if (empty($input['query'])) {
            throw new Exception('Search query is required', 400);
        }
        
        $query = sanitizeInput($input['query']);
        $limit = min(20, max(1, $input['limit'] ?? 10));
        
        // Note: Image search requires additional APIs
        // This is a placeholder implementation
        $images = $this->searchImagesFromWeb($query, $limit);
        
        return [
            'success' => true,
            'action' => 'search_images',
            'query' => $query,
            'images' => $images,
            'result_count' => count($images),
            'note' => 'Image search requires additional API integration',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function handleApiTest($input) {
        $testType = $input['test_type'] ?? 'basic';
        $iterations = min(10, max(1, $input['iterations'] ?? 3));
        
        $results = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            
            try {
                switch ($testType) {
                    case 'basic':
                        $testResponse = $this->groqAI->generateResponse(
                            'You are a test assistant. Respond with "Test successful."',
                            'Test message ' . ($i + 1),
                            ['max_tokens' => 10, 'use_cache' => false]
                        );
                        break;
                        
                    case 'web_search':
                        $testResponse = $this->webSearch->search('test', ['limit' => 1]);
                        break;
                        
                    case 'voice':
                        $testResponse = $this->voiceSynthesizer->textToSpeech('Test', [
                            'language' => 'en-US',
                            'cache' => false
                        ]);
                        break;
                        
                    default:
                        throw new Exception('Unknown test type', 400);
                }
                
                $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                
                $results[] = [
                    'iteration' => $i + 1,
                    'success' => true,
                    'response_time' => $responseTime,
                    'data' => is_array($testResponse) ? 'Array response' : 'Success'
                ];
                
            } catch (Exception $e) {
                $results[] = [
                    'iteration' => $i + 1,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'response_time' => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
            
            // Small delay between iterations
            if ($i < $iterations - 1) {
                usleep(100000); // 100ms
            }
        }
        
        // Calculate statistics
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $responseTimes = array_column(array_filter($results, fn($r) => isset($r['response_time'])), 'response_time');
        $avgResponseTime = $responseTimes ? array_sum($responseTimes) / count($responseTimes) : 0;
        
        return [
            'success' => true,
            'action' => 'test_api',
            'test_type' => $testType,
            'iterations' => $iterations,
            'results' => $results,
            'statistics' => [
                'success_rate' => round(($successCount / $iterations) * 100, 2) . '%',
                'average_response_time' => round($avgResponseTime, 2) . 'ms',
                'min_response_time' => $responseTimes ? min($responseTimes) : 0,
                'max_response_time' => $responseTimes ? max($responseTimes) : 0
            ],
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
                'groq_ai' => defined('GROQ_API_KEY') && !empty(GROQ_API_KEY),
                'web_search' => ENABLE_WEB_SEARCH,
                'voice_synthesis' => ENABLE_VOICE_SYNTHESIS,
                'caching' => ENABLE_CACHING,
                'analytics' => true,
                'multiple_languages' => true,
                'code_assistance' => true,
                'sentiment_analysis' => true
            ],
            'limits' => [
                'rate_limit' => RATE_LIMIT_REQUESTS ?? 20,
                'rate_window' => RATE_LIMIT_WINDOW ?? 60,
                'max_tokens' => MAX_TOKENS,
                'max_context' => MAX_CONTEXT_LENGTH
            ],
/workspace/jarvis$ /bin/bash -lc sed -n '800,1200p' controllers/ApiController.php
],
            'endpoints' => [
                'POST /api' => 'Main API endpoint',
                'GET /status' => 'System status',
                'GET /health' => 'Health check',
                'GET /docs' => 'API documentation'
            ]
        ];
        
        // Add model information
        $models = $this->groqAI->getAvailableModels();
        $status['available_models'] = array_keys($models);
        $status['default_model'] = DEFAULT_GROQ_MODEL;
        
        return [
            'success' => true,
            'status' => $status
        ];
    }
    
    private function getAvailableModels() {
        $models = $this->groqAI->getAvailableModels();
        
        return [
            'success' => true,
            'models' => $models,
            'default_model' => DEFAULT_GROQ_MODEL,
            'backup_model' => BACKUP_MODEL,
            'recommendation' => 'Use llama-3.3-70b-versatile for best results',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function getPublicStats($params) {
        $period = $params['period'] ?? 'today';
        $stats = $this->analyticsTracker->getSystemStats($period);
        
        // Remove sensitive information for public access
        $publicStats = [
            'total_queries' => $stats['total']['queries'] ?? 0,
            'total_sessions' => $stats['total']['sessions'] ?? 0,
            'avg_response_time' => $stats['total']['avg_response_time'] ?? 0,
            'cache_hit_rate' => round(($stats['total']['cache_hits'] / max(1, $stats['total']['queries'])) * 100, 2),
            'error_rate' => round(($stats['total']['errors'] / max(1, $stats['total']['queries'])) * 100, 2),
            'period' => $period,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Add model usage if requested
        if ($params['include_models'] ?? false) {
            $publicStats['model_usage'] = [];
            foreach ($stats['models'] ?? [] as $model => $modelStats) {
                $publicStats['model_usage'][$model] = [
                    'queries' => $modelStats['queries'] ?? 0,
                    'percentage' => round(($modelStats['queries'] / max(1, $stats['total']['queries'])) * 100, 2) . '%'
                ];
            }
        }
        
        return [
            'success' => true,
            'stats' => $publicStats
        ];
    }
    
    private function getSessionInfo() {
        $sessionId = $_SESSION['jarvis_session_id'] ?? 'unknown';
        
        $sessionInfo = [
            'session_id' => $sessionId,
            'start_time' => date('Y-m-d H:i:s', $_SESSION['jarvis_start_time'] ?? time()),
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
        $password = $params['password'] ?? '';
        
        // Simple password protection for exports
        $exportPassword = defined('EXPORT_PASSWORD') ? EXPORT_PASSWORD : 'jarvis_export_2024';
        if ($password !== $exportPassword) {
            throw new Exception('Export password required', 401);
        }
        
        switch ($type) {
            case 'stats':
                $data = $this->analyticsTracker->exportData($format, $period);
                break;
                
            case 'queries':
                $limit = min(1000, max(1, $params['limit'] ?? 100));
                $data = $this->getQueriesForExport($limit, $period);
                break;
                
            case 'errors':
                $data = $this->analyticsTracker->getErrorAnalysis($period);
                break;
                
            case 'sessions':
                $data = $this->getSessionsForExport($period);
                break;
                
            default:
                throw new Exception('Invalid export type', 400);
        }
        
        // Set appropriate headers
        switch ($format) {
            case 'csv':
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="jarvis_export_' . $type . '_' . date('Y-m-d') . '.csv"');
                break;
                
            case 'json':
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="jarvis_export_' . $type . '_' . date('Y-m-d') . '.json"');
                break;
                
            case 'html':
                header('Content-Type: text/html');
                break;
        }
        
        return $data;
    }
    
    private function getApiDocumentation() {
        $docs = [
            'api' => 'JARVIS OSINT AI API v5.0',
            'version' => '5.0.0',
            'base_url' => ($_SERVER['HTTPS'] ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'authentication' => 'No authentication required for public endpoints',
            'rate_limits' => [
                'requests' => RATE_LIMIT_REQUESTS ?? 20,
                'window' => RATE_LIMIT_WINDOW ?? 60,
                'unit' => 'requests per minute'
            ],
            'endpoints' => [
                'POST /api' => [
                    'description' => 'Main API endpoint for all AI operations',
                    'parameters' => [
                        'action' => [
                            'required' => false,
                            'default' => 'chat',
                            'options' => [
                                'chat', 'web_search', 'analyze', 'translate', 'summarize',
                                'code_assist', 'voice_synthesize', 'get_stats', 'clear_cache',
                                'health_check', 'learn', 'search_images', 'get_models', 'test_api'
                            ]
                        ],
                        'message' => 'The user message (required for chat action)',
                        'model' => 'Groq model to use (default: ' . DEFAULT_GROQ_MODEL . ')',
                        'temperature' => 'Creativity level 0.1-1.0 (default: ' . AI_TEMPERATURE . ')',
                        'format' => ['html', 'markdown', 'json', 'plain'],
                        'include_sources' => 'boolean (default: true)',
                        'generate_voice' => 'boolean (default: false)'
                    ]
                ],
                'GET /status' => [
                    'description' => 'Get system status information'
                ],
                'GET /health' => [
                    'description' => 'Health check endpoint'
                ],
                'GET /docs' => [
                    'description' => 'API documentation (this endpoint)'
                ]
            ],
            'supported_models' => $this->groqAI->getAvailableModels(),
            'features' => [
                'real_time_web_search' => ENABLE_WEB_SEARCH,
                'voice_synthesis' => ENABLE_VOICE_SYNTHESIS,
                'caching' => ENABLE_CACHING,
                'analytics' => true,
                'multiple_languages' => true,
                'code_assistance' => true,
                'sentiment_analysis' => true,
                'translation' => true,
                'summarization' => true
            ],
            'example_requests' => [
                'chat' => [
                    'curl' => "curl -X POST https://doston-davlatov.uz/api \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"action\":\"chat\",\"message\":\"Doston Davlatov kim?\",\"model\":\"llama-3.3-70b-versatile\"}'",
                    'response' => 'JSON with AI response, sources, and metadata'
                ],
                'web_search' => [
                    'curl' => "curl -X POST https://doston-davlatov.uz/api \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"action\":\"web_search\",\"query\":\"latest AI developments\",\"limit\":5}'",
                    'response' => 'JSON with search results and analysis'
                ]
            ],
            'contact' => [
                'developer' => 'Doston Davlatov',
                'website' => 'https://doston-davlatov.uz',
                'email' => 'contact@doston-davlatov.uz'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return [
            'success' => true,
            'docs' => $docs
        ];
    }
    
    private function handlePing() {
        return [
            'success' => true,
            'message' => 'pong',
            'timestamp' => date('Y-m-d H:i:s'),
            'server_time' => time(),
            'uptime' => $this->getSystemUptime()
        ];
    }
    
    private function serveAudioFile($params) {
        $hash = $params['hash'] ?? '';
        $format = $params['format'] ?? 'mp3';
        
        if (empty($hash)) {
            throw new Exception('Audio hash required', 400);
        }
        
        $audioDir = 'audio_cache/';
        $audioFile = $audioDir . $hash . '.' . $format;
        
        if (!file_exists($audioFile)) {
            throw new Exception('Audio file not found', 404);
        }
        
        // Set appropriate headers
        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg'
        ];
        
        if (isset($mimeTypes[$format])) {
            header('Content-Type: ' . $mimeTypes[$format]);
            header('Content-Length: ' . filesize($audioFile));
            header('Cache-Control: public, max-age=86400');
            readfile($audioFile);
        } else {
            throw new Exception('Unsupported audio format', 415);
        }
        
        exit();
    }
    
    // Helper methods
    
    private function getConversationContext($sessionId, $providedContext = []) {
        // Get last 5 messages from this session
        $sql = "SELECT query, response 
                FROM analytics 
                WHERE session_id = ? 
                AND error IS NULL
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
        // [Previous implementation from earlier code]
        // Return the system prompt based on analysis and information
        $prompt = "You are JARVIS, an advanced AI assistant...";
        // ... rest of the prompt building logic
        
        return $prompt;
    }
    
    private function storeConversationHistory($sessionId, $query, $response) {
        // This is handled by analytics tracker
        return true;
    }
    
    private function analyzeSearchResults($results) {
        $sources = [];
        $types = [];
        $confidences = [];
        
        foreach ($results as $result) {
            $sources[] = $result['source'] ?? 'unknown';
            $types[] = $result['type'] ?? 'general';
            if (isset($result['confidence'])) {
                $confidences[] = $result['confidence'];
            }
        }
        
        return [
            'source_distribution' => array_count_values($sources),
            'type_distribution' => array_count_values($types),
            'total_results' => count($results),
            'unique_sources' => count(array_unique($sources)),
            'average_confidence' => $confidences ? array_sum($confidences) / count($confidences) : null
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
        
        // Add top 3 results
        $summary .= "\nEng yaxshi natijalar:\n";
        $topResults = array_slice($results, 0, 3);
        foreach ($topResults as $index => $result) {
            $summary .= ($index + 1) . ". " . ($result['title'] ?? 'Noma\'lum') . "\n";
            $summary .= "   " . substr($result['snippet'] ?? '', 0, 100) . "...\n";
        }
        
        return $summary;
    }
    
    private function getDetailedResults($results) {
        $detailed = [];
        
        foreach ($results as $result) {
            $detailed[] = [
                'title' => $result['title'] ?? '',
                'snippet' => $result['snippet'] ?? '',
                'url' => $result['url'] ?? '',
                'source' => $result['source'] ?? 'unknown',
                'type' => $result['type'] ?? 'general',
                'confidence' => $result['confidence'] ?? 0,
                'timestamp' => $result['timestamp'] ?? time(),
                'metadata' => $result['metadata'] ?? []
            ];
        }
        
        return $detailed;
    }
    
    private function detectLanguage($text) {
        // Simple language detection
        $uzbekPattern = '/[chsho\'g\'qxh]/i';
        $russianPattern = '/[щшчцж]/iu';
        
        $uzbekCount = preg_match_all($uzbekPattern, $text);
        $russianCount = preg_match_all($russianPattern, $text);
        
        if ($uzbekCount > $russianCount && $uzbekCount > 2) {
            return 'uz';
        } elseif ($russianCount > $uzbekCount && $russianCount > 2) {
            return 'ru';
        }
        
        return 'en';
    }
    
    private function extractKeyPoints($text) {
        // Extract bullet points or numbered lists
        $lines = explode("\n", $text);
        $keyPoints = [];
        
        foreach ($lines as $line) {
/workspace/jarvis$ /bin/bash -lc sed -n '1200,1600p' controllers/ApiController.php
foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[•\-\*]\s+(.+)$/', $line, $matches) || 
                preg_match('/^\d+[\.\)]\s+(.+)$/', $line, $matches)) {
                $keyPoints[] = $matches[1];
            }
        }
        
        return $keyPoints;
    }
    
    private function generateTests($code, $language) {
        // Generate unit tests for the code
        $prompt = "Generate unit tests for the following {$language} code:\n\n{$code}\n\nProvide tests in {$language}.";
        
        $testResponse = $this->groqAI->generateResponse($prompt, 'Generate tests', [
            'temperature' => 0.3,
            'max_tokens' => 1000
        ]);
        
        return $testResponse['response'];
    }
    
    private function explainCode($code, $language) {
        // Explain the code
        $prompt = "Explain the following {$language} code in simple terms:\n\n{$code}\n\nFocus on what the code does and how it works.";
        
        $explanationResponse = $this->groqAI->generateResponse($prompt, 'Explain code', [
            'temperature' => 0.5,
            'max_tokens' => 800
        ]);
        
        return $explanationResponse['response'];
    }
    
    private function searchImagesFromWeb($query, $limit) {
        // Placeholder for image search
        // In production, integrate with Unsplash, Pixabay, or similar API
        return [
            [
                'url' => 'https://via.placeholder.com/300x200?text=' . urlencode($query),
                'title' => 'Placeholder for: ' . $query,
                'source' => 'placeholder',
                'width' => 300,
                'height' => 200
            ]
        ];
    }
    
    private function getQueriesForExport($limit, $period) {
        $queries = $this->analyticsTracker->getTopQueries($limit, $period);
        
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
    
    private function getSessionsForExport($period) {
        $startDate = date('Y-m-d 00:00:00');
        
        if ($period === 'week') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        } elseif ($period === 'month') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
        
        $sql = "SELECT 
                    session_id,
                    user_ip,
                    user_agent,
                    total_queries,
                    start_time,
                    last_activity
                FROM user_sessions 
                WHERE start_time >= ?
                ORDER BY last_activity DESC";
        
        $stmt = $this->db->executeQuery($sql, [$startDate], 's');
        $result = $stmt->get_result();
        
        $csv = "Session ID,User IP,User Agent,Total Queries,Start Time,Last Activity\n";
        
        while ($row = $result->fetch_assoc()) {
            $csv .= sprintf('"%s","%s","%s",%d,%s,%s' . "\n",
                $row['session_id'],
                $row['user_ip'],
                str_replace('"', '""', $row['user_agent']),
                $row['total_queries'],
                $row['start_time'],
                $row['last_activity']
            );
        }
        
        return $csv;
    }
    
    private function getSystemUptime() {
        if (!isset($_SESSION['system_start_time'])) {
            $_SESSION['system_start_time'] = time();
        }
        
        $uptime = time() - $_SESSION['system_start_time'];
        
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = $uptime % 60;
        
        if ($days > 0) {
            return sprintf('%d kun, %02d:%02d:%02d', $days, $hours, $minutes, $seconds);
        }
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
}
?>