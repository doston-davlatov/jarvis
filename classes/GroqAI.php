<?php
class GroqAI {
    private $db;
    private $apiKey;
    private $defaultModel;
    private $cacheManager;
    
    public function __construct(Database $db, $cacheManager = null) {
        $this->db = $db;
        $this->apiKey = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
        $this->defaultModel = defined('DEFAULT_GROQ_MODEL') ? DEFAULT_GROQ_MODEL : 'llama-3.3-70b-versatile';
        $this->cacheManager = $cacheManager;
        
        if (empty($this->apiKey)) {
            throw new Exception('Groq API kaliti topilmadi. config.php da GROQ_API_KEY ni aniqlang.');
        }
    }
    
    public function generateResponse($systemPrompt, $userPrompt, $options = []) {
        $startTime = microtime(true);
        
        // Default options
        $defaults = [
            'model' => $this->defaultModel,
            'temperature' => AI_TEMPERATURE,
            'max_tokens' => MAX_TOKENS,
            'top_p' => 0.9,
            'frequency_penalty' => 0.1,
            'presence_penalty' => 0.1,
            'stream' => false,
            'use_cache' => true,
            'retry_count' => 2,
            'timeout' => 30
        ];
        
        $options = array_merge($defaults, $options);
        
        // Generate cache key
        $cacheKey = $this->generateCacheKey($systemPrompt, $userPrompt, $options);
        
        // Check cache first
        if ($options['use_cache'] && $this->cacheManager) {
            $cached = $this->cacheManager->get($cacheKey);
            if ($cached) {
                return array_merge($cached, [
                    'cached' => true,
                    'response_time' => 0,
                    'cache_hit' => true
                ]);
            }
        }
        
        $response = null;
        $lastError = null;
        
        // Retry logic
        for ($attempt = 0; $attempt <= $options['retry_count']; $attempt++) {
            try {
                $response = $this->callGroqAPI($systemPrompt, $userPrompt, $options);
                
                // If successful, break out of retry loop
                if ($response && !empty($response['choices'][0]['message']['content'])) {
                    break;
                }
                
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                error_log("Groq API attempt $attempt failed: " . $lastError);
                
                // Wait before retry (exponential backoff)
                if ($attempt < $options['retry_count']) {
                    $waitTime = pow(2, $attempt) * 1000000; // microseconds
                    usleep($waitTime);
                    
                    // Try backup model on second attempt
                    if ($attempt == 1 && $options['model'] != BACKUP_MODEL) {
                        $options['model'] = BACKUP_MODEL;
                        error_log("Switching to backup model: " . BACKUP_MODEL);
                    }
                }
            }
        }
        
        // If all attempts failed
        if (!$response || empty($response['choices'][0]['message']['content'])) {
            throw new Exception("Groq API failed after {$options['retry_count']} attempts. Last error: " . $lastError);
        }
        
        $responseTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Extract response data
        $aiResponse = $response['choices'][0]['message']['content'];
        $tokensUsed = $response['usage']['total_tokens'] ?? 0;
        $finishReason = $response['choices'][0]['finish_reason'] ?? 'stop';
        
        // Sanitize response
        $aiResponse = $this->sanitizeResponse($aiResponse);
        
        $result = [
            'response' => $aiResponse,
            'tokens_used' => $tokensUsed,
            'model' => $options['model'],
            'finish_reason' => $finishReason,
            'response_time' => $responseTime,
            'cached' => false,
            'cache_hit' => false,
            'provider' => 'Groq Cloud',
            'speed_rating' => $this->calculateSpeedRating($responseTime, $tokensUsed)
        ];
        
        // Cache the response
        if ($options['use_cache'] && $this->cacheManager) {
            $this->cacheManager->set($cacheKey, $result, CACHE_DURATION);
        }
        
        return $result;
    }
    
    private function callGroqAPI($systemPrompt, $userPrompt, $options) {
        $endpoint = 'https://api.groq.com/openai/v1/chat/completions';
        
        // Prepare messages
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];
        
        // Prepare request data
        $data = [
            'model' => $options['model'],
            'messages' => $messages,
            'temperature' => $options['temperature'],
            'max_tokens' => $options['max_tokens'],
            'top_p' => $options['top_p'],
            'frequency_penalty' => $options['frequency_penalty'],
            'presence_penalty' => $options['presence_penalty'],
            'stream' => $options['stream']
        ];
        
        // Add tools if provided
        if (isset($options['tools']) && !empty($options['tools'])) {
            $data['tools'] = $options['tools'];
            $data['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }
        
        $ch = curl_init($endpoint);
        
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: JARVIS-OSINT-AI/5.0'
            ],
            CURLOPT_TIMEOUT => $options['timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0
        ];
        
        curl_setopt_array($ch, $curlOptions);
        
        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        
        curl_close($ch);
        
        // Log request details
        $this->logAPICall([
            'model' => $options['model'],
            'tokens' => strlen($systemPrompt . $userPrompt) / 4, // rough estimate
            'http_code' => $httpCode,
            'response_time' => $totalTime,
            'endpoint' => $endpoint
        ]);
        
        if ($curlError) {
            throw new Exception("cURL error: " . $curlError);
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['error']['message'] ?? 'Unknown error';
            $errorType = $errorData['error']['type'] ?? 'unknown';
            
            throw new Exception("Groq API error ($httpCode): $errorMsg (Type: $errorType)");
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Groq API");
        }
        
        return $result;
    }
    
    public function generateWithTools($systemPrompt, $userPrompt, $tools, $options = []) {
        $defaultToolOptions = [
            'tools' => $tools,
            'tool_choice' => 'auto',
            'max_tokens' => 2000
        ];
        
        $options = array_merge($defaultToolOptions, $options);
        
        return $this->generateResponse($systemPrompt, $userPrompt, $options);
    }
    
    public function webSearchAssistant($query, $context = '') {
        $systemPrompt = <<<PROMPT
        You are a web search assistant with access to real-time internet search capabilities.
        Your task is to help users find accurate, up-to-date information from the web.
        
        CAPABILITIES:
        1. Search the web for current information
        2. Summarize search results
        3. Provide citations and sources
        4. Answer factual questions with evidence
        
        GUIDELINES:
        - Always cite your sources with [Source: Name]
        - Be objective and factual
        - If information is conflicting, mention it
        - For technical topics, provide code examples when relevant
        - For news, mention publication dates
        
        USER QUERY: {query}
        
        ADDITIONAL CONTEXT: {context}
        PROMPT;
        
        $systemPrompt = str_replace(
            ['{query}', '{context}'],
            [$query, $context],
            $systemPrompt
        );
        
        // Define tools for web search
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' => 'Search the web for current information',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'The search query'
                            ],
                            'num_results' => [
                                'type' => 'integer',
                                'description' => 'Number of results to return (1-10)',
                                'default' => 5
                            ]
                        ],
                        'required' => ['query']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'summarize_article',
                    'description' => 'Summarize a web article or page',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => [
                                'type' => 'string',
                                'description' => 'URL of the article to summarize'
                            ],
                            'max_length' => [
                                'type' => 'integer',
                                'description' => 'Maximum summary length in words',
                                'default' => 200
                            ]
                        ],
                        'required' => ['url']
                    ]
                ]
            ],
        ];
        
        return $this->generateWithTools($systemPrompt, $query, $tools);
    }
    
    public function codeAssistant($query, $language = '', $context = '') {
        $systemPrompt = <<<PROMPT
        You are an expert coding assistant specializing in {language}.
        You help developers write better code, debug issues, and learn programming.
        
        SPECIALIZATIONS:
        - Code generation and explanation
        - Debugging and error fixing
        - Code optimization
        - Best practices and design patterns
        - API integration
        
        GUIDELINES:
        1. Provide complete, runnable code examples
        2. Explain complex concepts clearly
        3. Include error handling
        4. Suggest alternatives when appropriate
        5. Follow {language} best practices
        
        USER QUERY: {query}
        
        ADDITIONAL CONTEXT: {context}
        PROMPT;
        
        $systemPrompt = str_replace(
            ['{language}', '{query}', '{context}'],
            [$language ?: 'multiple programming languages', $query, $context],
            $systemPrompt
        );
        
        return $this->generateResponse($systemPrompt, $query, [
            'temperature' => 0.3, // Lower temperature for code generation
            'max_tokens' => 1500
        ]);
    }
    
    public function creativeWriter($prompt, $style = 'professional', $length = 'medium') {
        $stylePrompts = [
            'professional' => 'Write in a professional, business-like tone.',
            'casual' => 'Write in a casual, conversational tone.',
            'technical' => 'Write in a detailed, technical style.',
            'creative' => 'Write creatively with vivid descriptions.',
            'persuasive' => 'Write persuasively to convince the reader.'
        ];
        
        $lengthPrompts = [
            'short' => 'Keep response under 100 words.',
            'medium' => 'Write 200-300 words.',
            'long' => 'Write 400-500 words.',
            'detailed' => 'Write 600+ words with comprehensive details.'
        ];
        
        $systemPrompt = <<<PROMPT
        You are a professional writer with expertise in {style} writing.
        
        WRITING STYLE: {style_instruction}
        
        LENGTH REQUIREMENT: {length_instruction}
        
        GUIDELINES:
        - Maintain consistent tone throughout
        - Use appropriate vocabulary for the style
        - Structure content logically
        - Engage the reader
        - Proofread for clarity and flow
        
        USER PROMPT: {prompt}
        PROMPT;
        
        $systemPrompt = str_replace([
            '{style}',
            '{style_instruction}',
            '{length_instruction}',
            '{prompt}'
        ],
        [
            $style,
            $stylePrompts[$style] ?? $stylePrompts['professional'],
            $lengthPrompts[$length] ?? $lengthPrompts['medium'],
            $prompt
        ], $systemPrompt);
        
        return $this->generateResponse($systemPrompt, $prompt, [
            'temperature' => 0.8, // Higher temperature for creative writing
            'max_tokens' => $this->getTokenLimitForLength($length)
        ]);
    }
    
    public function analyzeSentiment($text, $detailed = false) {
        $systemPrompt = <<<PROMPT
        You are a sentiment analysis expert. Analyze the given text and determine:
        1. Overall sentiment (positive, negative, neutral)
        2. Emotion detection (joy, anger, sadness, fear, surprise, disgust)
        3. Confidence score (0-100%)
        4. Key phrases that influenced the sentiment
        
        Provide analysis in JSON format.
        
        TEXT TO ANALYZE: {text}
        PROMPT;
        
        $systemPrompt = str_replace('{text}', $text, $systemPrompt);
        
        $response = $this->generateResponse($systemPrompt, 'Analyze this text', [
            'temperature' => 0.1,
            'max_tokens' => 500
        ]);
        
        // Try to parse JSON from response
        $jsonStart = strpos($response['response'], '{');
        $jsonEnd = strrpos($response['response'], '}');
        
        if ($jsonStart !== false && $jsonEnd !== false) {
            $jsonStr = substr($response['response'], $jsonStart, $jsonEnd - $jsonStart + 1);
            $analysis = json_decode($jsonStr, true);
            
            if ($analysis) {
                $response['analysis'] = $analysis;
            }
        }
        
        return $response;
    }
    
    public function translateText($text, $targetLang, $sourceLang = 'auto') {
        $systemPrompt = <<<PROMPT
        You are a professional translator. Translate the given text from {source_lang} to {target_lang}.
        
        TRANSLATION GUIDELINES:
        1. Maintain original meaning and intent
        2. Preserve cultural context
        3. Use natural phrasing in target language
        4. Handle idioms and expressions appropriately
        5. Maintain technical accuracy for specialized terms
        
        TEXT TO TRANSLATE: {text}
        
        Provide only the translation without additional commentary.
        PROMPT;
        
        $systemPrompt = str_replace([
            '{source_lang}',
            '{target_lang}',
            '{text}'
        ], [
            $sourceLang,
            $targetLang,
            $text
        ], $systemPrompt);
        
        return $this->generateResponse($systemPrompt, 'Translate this text', [
            'temperature' => 0.3,
            'max_tokens' => 1000
        ]);
    }
    
    public function summarizeText($text, $ratio = 0.3) {
        $systemPrompt = <<<PROMPT
        You are a text summarization expert. Summarize the given text while preserving:
        1. Main ideas and key points
        2. Important facts and figures
        3. Conclusions and recommendations
        4. Critical context
        
        SUMMARY RATIO: Create a summary that is approximately {ratio}% of the original length.
        
        GUIDELINES:
        - Use bullet points for key takeaways
        - Highlight important statistics
        - Preserve technical accuracy
        - Maintain original tone where possible
        
        TEXT TO SUMMARIZE: {text}
        PROMPT;
        
        $targetLength = max(100, strlen($text) * $ratio);
        
        $systemPrompt = str_replace([
            '{ratio}',
            '{text}'
        ], [
            round($ratio * 100),
            substr($text, 0, 5000) // Limit input length
        ], $systemPrompt);
        
        return $this->generateResponse($systemPrompt, 'Summarize this text', [
            'temperature' => 0.2,
            'max_tokens' => $targetLength
        ]);
    }
    
    public function extractKeywords($text, $maxKeywords = 10) {
        $systemPrompt = <<<PROMPT
        Extract the most important keywords and key phrases from the given text.
        
        REQUIREMENTS:
        1. Extract {maxKeywords} most important keywords
        2. Include both single words and key phrases
        3. Rank by importance/relevance
        4. Provide confidence scores
        5. Categorize by type (person, place, organization, concept, etc.)
        
        Return in JSON format with the following structure:
        {
            "keywords": [
                {
                    "keyword": "example",
                    "type": "concept",
                    "relevance": 0.95,
                    "frequency": 5,
                    "context": "appears in discussion about..."
                }
            ],
            "summary": "Brief summary of main topics"
        }
        
        TEXT: {text}
        PROMPT;
        
        $systemPrompt = str_replace([
            '{maxKeywords}',
            '{text}'
        ], [
            $maxKeywords,
            substr($text, 0, 4000)
        ], $systemPrompt);
        
        return $this->generateResponse($systemPrompt, 'Extract keywords', [
            'temperature' => 0.1,
            'max_tokens' => 800
        ]);
    }
    
    private function generateCacheKey($systemPrompt, $userPrompt, $options) {
        $keyData = [
            'system' => substr($systemPrompt, 0, 500),
            'user' => $userPrompt,
            'model' => $options['model'],
            'temperature' => $options['temperature']
        ];
        
        return 'groq_' . md5(serialize($keyData));
    }
    
    private function sanitizeResponse($response) {
        // Remove any potential harmful content
        $response = strip_tags($response);
        $response = htmlspecialchars($response, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Trim and clean up
        $response = trim($response);
        $response = preg_replace('/\s+/', ' ', $response);
        
        return $response;
    }
    
    private function calculateSpeedRating($responseTime, $tokens) {
        if ($tokens <= 0) return 'unknown';
        
        $tokensPerSecond = $tokens / ($responseTime / 1000);
        
        if ($tokensPerSecond > 200) return 'ultra_fast';
        if ($tokensPerSecond > 100) return 'very_fast';
        if ($tokensPerSecond > 50) return 'fast';
        if ($tokensPerSecond > 20) return 'moderate';
        return 'slow';
    }
    
    private function getTokenLimitForLength($length) {
        $limits = [
            'short' => 200,
            'medium' => 500,
            'long' => 1000,
            'detailed' => 2000
        ];
        
        return $limits[$length] ?? 500;
    }
    
    private function logAPICall($details) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'model' => $details['model'],
            'estimated_tokens' => $details['tokens'],
            'response_time' => round($details['response_time'] * 1000, 2),
            'http_code' => $details['http_code'],
            'endpoint' => $details['endpoint']
        ];
        
        $logFile = 'logs/groq_api_' . date('Y-m-d') . '.log';
        
        if (!file_exists('logs')) {
            mkdir('logs', 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND);
    }
    
    public function getAvailableModels() {
        return [
            'llama-3.3-70b-versatile' => [
                'name' => 'Llama 3.3 70B',
                'description' => 'Most capable model, excellent for complex tasks',
                'context' => 131072,
                'speed' => 'fast'
            ],
            'mixtral-8x7b-32768' => [
                'name' => 'Mixtral 8x7B',
                'description' => 'Good balance of capability and speed',
                'context' => 32768,
                'speed' => 'very_fast'
            ],
            'gemma2-9b-it' => [
                'name' => 'Gemma2 9B',
                'description' => 'Lightweight and very fast',
                'context' => 8192,
                'speed' => 'ultra_fast'
            ],
            'llama-3.2-1b-preview' => [
                'name' => 'Llama 3.2 1B',
                'description' => 'Smallest model, fastest responses',
                'context' => 8192,
                'speed' => 'ultra_fast'
            ]
        ];
    }
    
    public function getModelInfo($modelName) {
        $models = $this->getAvailableModels();
        return $models[$modelName] ?? null;
    }
    
    public function getUsageStats($period = 'today') {
        $startDate = date('Y-m-d 00:00:00');
        $endDate = date('Y-m-d 23:59:59');
        
        if ($period === 'week') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        } elseif ($period === 'month') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_calls,
                    SUM(tokens_used) as total_tokens,
                    AVG(response_time) as avg_response_time,
                    model,
                    DATE(created_at) as call_date
                FROM analytics 
                WHERE source = 'groq' 
                AND created_at BETWEEN ? AND ?
                GROUP BY model, DATE(created_at)
                ORDER BY call_date DESC";
        
        $stmt = $this->db->executeQuery($sql, [$startDate, $endDate], 'ss');
        $result = $stmt->get_result();
        
        $stats = [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'models' => [],
            'total' => [
                'calls' => 0,
                'tokens' => 0,
                'avg_time' => 0
            ]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $model = $row['model'];
            if (!isset($stats['models'][$model])) {
                $stats['models'][$model] = [
                    'calls' => 0,
                    'tokens' => 0,
                    'avg_time' => 0,
                    'days' => []
                ];
            }
            
            $stats['models'][$model]['calls'] += $row['total_calls'];
            $stats['models'][$model]['tokens'] += $row['total_tokens'];
            $stats['models'][$model]['days'][] = [
                'date' => $row['call_date'],
                'calls' => $row['total_calls'],
                'tokens' => $row['total_tokens']
            ];
            
            $stats['total']['calls'] += $row['total_calls'];
            $stats['total']['tokens'] += $row['total_tokens'];
        }
        
        // Calculate averages
        foreach ($stats['models'] as $model => &$modelData) {
            if ($modelData['calls'] > 0) {
                $modelData['avg_time'] = $modelData['tokens'] / $modelData['calls'];
            }
        }
        
        if ($stats['total']['calls'] > 0) {
            $stats['total']['avg_time'] = $stats['total']['tokens'] / $stats['total']['calls'];
        }
        
        return $stats;
    }
    
    public function validateAPIKey() {
        try {
            $testResponse = $this->generateResponse(
                'You are a test assistant. Respond with "API key is valid."',
                'Test message',
                ['max_tokens' => 10, 'use_cache' => false]
            );
            
            return [
                'valid' => true,
                'model' => $testResponse['model'],
                'response_time' => $testResponse['response_time']
            ];
            
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>
