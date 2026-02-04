<?php
class ResponseFormatter {
    private $db;
    private $sessionId;
    
    public function __construct(Database $db) {
        $this->db = $db;
        $this->sessionId = $_SESSION['jarvis_session_id'] ?? 'unknown';
    }
    
    public function formatAIResponse($response, $context = [], $options = []) {
        $defaults = [
            'format' => 'html', // html, markdown, plain, json
            'include_sources' => true,
            'include_metadata' => true,
            'include_analysis' => false,
            'add_timestamp' => true,
            'style' => 'jarvis', // jarvis, simple, technical, creative
            'max_length' => 2000,
            'language' => 'uz'
        ];
        
        $options = array_merge($defaults, $options);
        
        // Extract response text
        $responseText = is_array($response) ? ($response['response'] ?? '') : $response;
        
        // Apply formatting based on style
        $formattedText = $this->applyStyle($responseText, $options['style'], $options['language']);
        
        // Truncate if too long
        if (strlen($formattedText) > $options['max_length']) {
            $formattedText = substr($formattedText, 0, $options['max_length']) . '...';
        }
        
        $formattedResponse = [
            'content' => $formattedText,
            'format' => $options['format'],
            'style' => $options['style']
        ];
        
        // Add sources if available
        if ($options['include_sources'] && isset($response['sources'])) {
            $formattedResponse['sources'] = $this->formatSources($response['sources'], $options['format']);
        }
        
        // Add metadata if available
        if ($options['include_metadata'] && is_array($response)) {
            $formattedResponse['metadata'] = $this->formatMetadata($response, $options['format']);
        }
        
        // Add analysis if requested
        if ($options['include_analysis'] && isset($context['analysis'])) {
            $formattedResponse['analysis'] = $this->formatAnalysis($context['analysis'], $options['format']);
        }
        
        // Add timestamp
        if ($options['add_timestamp']) {
            $formattedResponse['timestamp'] = date('Y-m-d H:i:s');
            $formattedResponse['timestamp_human'] = $this->formatTimestamp($formattedResponse['timestamp']);
        }
        
        // Add session info
        $formattedResponse['session_id'] = $this->sessionId;
        
        // Convert to requested format
        return $this->convertToFormat($formattedResponse, $options['format']);
    }
    
    private function applyStyle($text, $style, $language) {
        switch ($style) {
            case 'jarvis':
                return $this->applyJarvisStyle($text, $language);
                
            case 'technical':
                return $this->applyTechnicalStyle($text, $language);
                
            case 'creative':
                return $this->applyCreativeStyle($text, $language);
                
            case 'simple':
                return $this->applySimpleStyle($text, $language);
                
            default:
                return $text;
        }
    }
    
    private function applyJarvisStyle($text, $language) {
        $greetings = [
            'uz' => ['Sir, ', 'Javob: ', 'Analiz: '],
            'en' => ['Sir, ', 'Response: ', 'Analysis: '],
            'ru' => ['Сэр, ', 'Ответ: ', 'Анализ: ']
        ];
        
        $lang = in_array($language, ['uz', 'en', 'ru']) ? $language : 'uz';
        $greeting = $greetings[$lang][0];
        
        // Add greeting if not already present
        if (strpos($text, $greeting) !== 0 && 
            strpos($text, 'Sir') !== 0 && 
            strpos($text, 'Сэр') !== 0) {
            $text = $greeting . $text;
        }
        
        // Format paragraphs
        $paragraphs = preg_split('/\n+/', $text);
        $formatted = '';
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (!empty($paragraph)) {
                // Add bullet points for lists
                if (preg_match('/^\d+[\.\)]|^[-•*]/', $paragraph)) {
                    $formatted .= "• " . $paragraph . "\n\n";
                } else {
                    $formatted .= $paragraph . "\n\n";
                }
            }
        }
        
        return trim($formatted);
    }
    
    private function applyTechnicalStyle($text, $language) {
        // Add code formatting markers
        $text = preg_replace_callback('/`([^`]+)`/', function($matches) {
            return '<code>' . $matches[1] . '</code>';
        }, $text);
        
        // Format numbers and statistics
        $text = preg_replace('/(\d+[\.,]\d+%)/', '<strong>$1</strong>', $text);
        
        // Add section headers
        $lines = explode("\n", $text);
        $formatted = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^#+\s/', $line)) {
                $formatted .= "\n## " . substr($line, strpos($line, ' ') + 1) . "\n";
            } elseif (preg_match('/^(Summary|Conclusion|Recommendation):/i', $line)) {
                $formatted .= "\n**" . $line . "**\n";
            } else {
                $formatted .= $line . "\n";
            }
        }
        
        return trim($formatted);
    }
    
    private function applyCreativeStyle($text, $language) {
        // Add creative formatting
        $text = preg_replace('/\.\s/', ".\n\n", $text);
        
        // Emphasize certain words
        $emphasisWords = ['amazing', 'incredible', 'wonderful', 'fantastic', 
                         'ajoyib', 'hayratlanarli', 'a\'lo', 'mukammal'];
        
        foreach ($emphasisWords as $word) {
            $text = preg_replace("/\b$word\b/i", "*$word*", $text);
        }
        
        return $text;
    }
    
    private function applySimpleStyle($text, $language) {
        // Remove any formatting, keep it simple
        $text = strip_tags($text);
        $text = preg_replace('/\n\s*\n+/', "\n", $text);
        
        return trim($text);
    }
    
    private function formatSources($sources, $format) {
        if (empty($sources)) {
            return [];
        }
        
        $formattedSources = [];
        
        foreach ($sources as $source) {
            if (is_string($source)) {
                $formattedSources[] = [
                    'name' => $source,
                    'type' => $this->detectSourceType($source)
                ];
            } elseif (is_array($source)) {
                $formattedSources[] = [
                    'name' => $source['name'] ?? $source['source'] ?? 'Unknown',
                    'type' => $source['type'] ?? $this->detectSourceType($source['name'] ?? ''),
                    'url' => $source['url'] ?? null,
                    'confidence' => $source['confidence'] ?? null
                ];
            }
        }
        
        // Remove duplicates
        $uniqueSources = [];
        $seen = [];
        
        foreach ($formattedSources as $source) {
            $key = $source['name'] . '|' . ($source['type'] ?? '');
            if (!in_array($key, $seen)) {
                $seen[] = $key;
                $uniqueSources[] = $source;
            }
        }
        
        // Format for output
        switch ($format) {
            case 'html':
                return $this->formatSourcesHTML($uniqueSources);
                
            case 'markdown':
                return $this->formatSourcesMarkdown($uniqueSources);
                
            case 'json':
                return $uniqueSources;
                
            default:
                return array_column($uniqueSources, 'name');
        }
    }
    
    private function detectSourceType($sourceName) {
        $sourceName = strtolower($sourceName);
        
        if (strpos($sourceName, 'wikipedia') !== false) return 'encyclopedia';
        if (strpos($sourceName, 'github') !== false) return 'code_repository';
        if (strpos($sourceName, 'stackoverflow') !== false) return 'technical_forum';
        if (strpos($sourceName, 'news') !== false) return 'news';
        if (strpos($sourceName, 'duckduckgo') !== false) return 'search_engine';
        if (strpos($sourceName, 'database') !== false) return 'local_database';
        if (strpos($sourceName, 'blog') !== false) return 'blog';
        if (strpos($sourceName, 'portfolio') !== false) return 'portfolio';
        
        return 'general';
    }
    
    private function formatSourcesHTML($sources) {
        if (empty($sources)) {
            return '';
        }
        
        $html = '<div class="sources-container">';
        $html .= '<h4>Manbalar:</h4>';
        $html .= '<ul class="sources-list">';
        
        foreach ($sources as $source) {
            $html .= '<li class="source-item">';
            $html .= '<span class="source-name">' . htmlspecialchars($source['name']) . '</span>';
            
            if (isset($source['type'])) {
                $html .= ' <span class="source-type">(' . $source['type'] . ')</span>';
            }
            
            if (isset($source['url'])) {
                $html .= ' <a href="' . htmlspecialchars($source['url']) . '" target="_blank" class="source-link">↗</a>';
            }
            
            $html .= '</li>';
        }
        
        $html .= '</ul>';
        $html .= '</div>';
        
        return $html;
    }
    
    private function formatSourcesMarkdown($sources) {
        if (empty($sources)) {
            return '';
        }
        
        $markdown = "**Manbalar:**\n\n";
        
        foreach ($sources as $source) {
            $markdown .= "- " . $source['name'];
            
            if (isset($source['type'])) {
                $markdown .= " (" . $source['type'] . ")";
            }
            
            if (isset($source['url'])) {
                $markdown .= " [" . $source['url'] . "]";
            }
            
            $markdown .= "\n";
        }
        
        return $markdown;
    }
    
    private function formatMetadata($response, $format) {
        $metadata = [
            'model' => $response['model'] ?? 'unknown',
            'tokens_used' => $response['tokens_used'] ?? 0,
            'response_time' => $response['response_time'] ?? 0,
            'provider' => $response['provider'] ?? 'unknown',
            'cached' => $response['cached'] ?? false,
            'cache_hit' => $response['cache_hit'] ?? false
        ];
        
        if (isset($response['speed_rating'])) {
            $metadata['speed_rating'] = $response['speed_rating'];
        }
        
        if (isset($response['finish_reason'])) {
            $metadata['finish_reason'] = $response['finish_reason'];
        }
        
        switch ($format) {
            case 'html':
                return $this->formatMetadataHTML($metadata);
                
            case 'markdown':
                return $this->formatMetadataMarkdown($metadata);
                
            case 'json':
                return $metadata;
                
            default:
                return implode(', ', array_filter($metadata));
        }
    }
    
    private function formatMetadataHTML($metadata) {
        $html = '<div class="metadata-container">';
        $html .= '<div class="metadata-grid">';
        
        foreach ($metadata as $key => $value) {
            $formattedKey = ucfirst(str_replace('_', ' ', $key));
            $formattedValue = $this->formatValue($value, $key);
            
            $html .= '<div class="metadata-item">';
            $html .= '<span class="metadata-key">' . $formattedKey . ':</span>';
            $html .= '<span class="metadata-value">' . $formattedValue . '</span>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    private function formatMetadataMarkdown($metadata) {
        $markdown = "**Metadata:**\n\n";
        
        foreach ($metadata as $key => $value) {
            $formattedKey = ucfirst(str_replace('_', ' ', $key));
            $formattedValue = $this->formatValue($value, $key);
            
            $markdown .= "- **$formattedKey:** $formattedValue\n";
        }
        
        return $markdown;
    }
    
    private function formatValue($value, $key) {
        switch ($key) {
            case 'response_time':
                return $value . 'ms';
                
            case 'tokens_used':
                return number_format($value);
                
            case 'cached':
            case 'cache_hit':
                return $value ? 'Yes' : 'No';
                
            default:
                return is_string($value) ? $value : json_encode($value);
        }
    }
    
    private function formatAnalysis($analysis, $format) {
        if (empty($analysis)) {
            return null;
        }
        
        $formattedAnalysis = [
            'query_type' => $analysis['type'] ?? 'unknown',
            'category' => $analysis['category'] ?? 'general',
            'complexity' => $analysis['complexity']['level'] ?? 'medium',
            'complexity_score' => $analysis['complexity']['score'] ?? 0,
            'sentiment' => $analysis['sentiment'] ?? 'neutral',
            'language' => $analysis['language'] ?? 'unknown',
            'topics' => $analysis['topics'] ?? [],
            'entities' => $analysis['entities'] ?? []
        ];
        
        switch ($format) {
            case 'html':
                return $this->formatAnalysisHTML($formattedAnalysis);
                
            case 'markdown':
                return $this->formatAnalysisMarkdown($formattedAnalysis);
                
            case 'json':
                return $formattedAnalysis;
                
            default:
                return null;
        }
    }
    
    private function formatAnalysisHTML($analysis) {
        $html = '<div class="analysis-container">';
        $html .= '<h4>Query Analysis:</h4>';
        $html .= '<div class="analysis-grid">';
        
        foreach ($analysis as $key => $value) {
            $formattedKey = ucfirst(str_replace('_', ' ', $key));
            
            if (is_array($value)) {
                $formattedValue = implode(', ', $value);
            } else {
                $formattedValue = $value;
            }
            
            $html .= '<div class="analysis-item">';
            $html .= '<span class="analysis-key">' . $formattedKey . ':</span>';
            $html .= '<span class="analysis-value">' . $formattedValue . '</span>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    private function formatAnalysisMarkdown($analysis) {
        $markdown = "**Query Analysis:**\n\n";
        
        foreach ($analysis as $key => $value) {
            $formattedKey = ucfirst(str_replace('_', ' ', $key));
            
            if (is_array($value)) {
                $formattedValue = implode(', ', $value);
            } else {
                $formattedValue = $value;
            }
            
            $markdown .= "- **$formattedKey:** $formattedValue\n";
        }
        
        return $markdown;
    }
    
    private function formatTimestamp($timestamp) {
        $now = new DateTime();
        $time = new DateTime($timestamp);
        $interval = $now->diff($time);
        
        if ($interval->y > 0) {
            return $interval->y . ' yil oldin';
        } elseif ($interval->m > 0) {
            return $interval->m . ' oy oldin';
        } elseif ($interval->d > 0) {
            return $interval->d . ' kun oldin';
        } elseif ($interval->h > 0) {
            return $interval->h . ' soat oldin';
        } elseif ($interval->i > 0) {
            return $interval->i . ' daqiqa oldin';
        } else {
            return 'hozirgina';
        }
    }
    
    private function convertToFormat($data, $format) {
        switch ($format) {
            case 'html':
                return $this->convertToHTML($data);
                
            case 'markdown':
                return $this->convertToMarkdown($data);
                
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
            case 'plain':
                return $data['content'];
                
            default:
                return $data;
        }
    }
    
    private function convertToHTML($data) {
        $html = '<div class="jarvis-response">';
        
        // Add content
        $html .= '<div class="response-content">';
        $html .= nl2br(htmlspecialchars($data['content']));
        $html .= '</div>';
        
        // Add sources if present
        if (isset($data['sources']) && !empty($data['sources'])) {
            $html .= $data['sources'];
        }
        
        // Add metadata if present
        if (isset($data['metadata']) && !empty($data['metadata'])) {
            $html .= $data['metadata'];
        }
        
        // Add analysis if present
        if (isset($data['analysis']) && !empty($data['analysis'])) {
            $html .= $data['analysis'];
        }
        
        // Add footer
        $html .= '<div class="response-footer">';
        
        if (isset($data['timestamp_human'])) {
            $html .= '<span class="timestamp">' . $data['timestamp_human'] . '</span>';
        }
        
        if (isset($data['metadata']['model'])) {
            $html .= ' • <span class="model">Model: ' . $data['metadata']['model'] . '</span>';
        }
        
        $html .= '</div>';
        
        $html .= '</div>';
        
        return $html;
    }
    
    private function convertToMarkdown($data) {
        $markdown = $data['content'] . "\n\n";
        
        if (isset($data['sources']) && !empty($data['sources'])) {
            $markdown .= $data['sources'] . "\n\n";
        }
        
        if (isset($data['metadata']) && !empty($data['metadata'])) {
            $markdown .= $data['metadata'] . "\n\n";
        }
        
        if (isset($data['analysis']) && !empty($data['analysis'])) {
            $markdown .= $data['analysis'] . "\n\n";
        }
        
        if (isset($data['timestamp_human'])) {
            $markdown .= "*" . $data['timestamp_human'] . "*";
        }
        
        return trim($markdown);
    }
    
    public function formatError($error, $format = 'html') {
        $errorData = [
            'type' => 'error',
            'message' => is_string($error) ? $error : ($error['message'] ?? 'Unknown error'),
            'code' => $error['code'] ?? 500,
            'timestamp' => date('Y-m-d H:i:s'),
            'session_id' => $this->sessionId
        ];
        
        // Add debug info in development
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $errorData['debug'] = [
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
                'trace' => $error['trace'] ?? null
            ];
        }
        
        switch ($format) {
            case 'html':
                return $this->formatErrorHTML($errorData);
                
            case 'json':
                return json_encode($errorData, JSON_PRETTY_PRINT);
                
            case 'plain':
                return 'Xato: ' . $errorData['message'];
                
            default:
                return $errorData;
        }
    }
    
    private function formatErrorHTML($error) {
        $html = '<div class="error-container">';
        $html .= '<div class="error-header">';
        $html .= '<h4>❌ Xato yuz berdi</h4>';
        $html .= '</div>';
        $html .= '<div class="error-content">';
        $html .= '<p>' . htmlspecialchars($error['message']) . '</p>';
        
        if (isset($error['debug']) && DEBUG_MODE) {
            $html .= '<div class="error-debug">';
            $html .= '<p><strong>Fayl:</strong> ' . ($error['debug']['file'] ?? 'N/A') . '</p>';
            $html .= '<p><strong>Qator:</strong> ' . ($error['debug']['line'] ?? 'N/A') . '</p>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '<div class="error-footer">';
        $html .= '<small>Session: ' . substr($error['session_id'], 0, 8) . '... • ';
        $html .= $error['timestamp'] . '</small>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    public function formatSuccess($message, $format = 'html', $data = []) {
        $successData = [
            'type' => 'success',
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'session_id' => $this->sessionId,
            'data' => $data
        ];
        
        switch ($format) {
            case 'html':
                return $this->formatSuccessHTML($successData);
                
            case 'json':
                return json_encode($successData, JSON_PRETTY_PRINT);
                
            case 'plain':
                return '✅ ' . $message;
                
            default:
                return $successData;
        }
    }
    
    private function formatSuccessHTML($success) {
        $html = '<div class="success-container">';
        $html .= '<div class="success-header">';
        $html .= '<h4>✅ Muvaffaqiyatli</h4>';
        $html .= '</div>';
        $html .= '<div class="success-content">';
        $html .= '<p>' . htmlspecialchars($success['message']) . '</p>';
        
        if (!empty($success['data'])) {
            $html .= '<pre class="success-data">';
            $html .= htmlspecialchars(json_encode($success['data'], JSON_PRETTY_PRINT));
            $html .= '</pre>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    public function formatSystemMessage($message, $type = 'info', $format = 'html') {
        $types = [
            'info' => ['icon' => 'ℹ️', 'title' => 'Ma\'lumot'],
            'warning' => ['icon' => '⚠️', 'title' => 'Ogohlantirish'],
            'tip' => ['icon' => '💡', 'title' => 'Maslahat'],
            'update' => ['icon' => '🔄', 'title' => 'Yangilanish']
        ];
        
        $typeConfig = $types[$type] ?? $types['info'];
        
        $systemData = [
            'type' => 'system',
            'subtype' => $type,
            'icon' => $typeConfig['icon'],
            'title' => $typeConfig['title'],
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        switch ($format) {
            case 'html':
                return $this->formatSystemMessageHTML($systemData);
                
            case 'json':
                return json_encode($systemData, JSON_PRETTY_PRINT);
                
            case 'plain':
                return $typeConfig['icon'] . ' ' . $message;
                
            default:
                return $systemData;
        }
    }
    
    private function formatSystemMessageHTML($system) {
        $html = '<div class="system-message system-' . $system['subtype'] . '">';
        $html .= '<div class="system-header">';
        $html .= '<span class="system-icon">' . $system['icon'] . '</span>';
        $html .= '<span class="system-title">' . $system['title'] . '</span>';
        $html .= '</div>';
        $html .= '<div class="system-content">';
        $html .= '<p>' . nl2br(htmlspecialchars($system['message'])) . '</p>';
        $html .= '</div>';
        $html .= '<div class="system-footer">';
        $html .= '<small>' . $system['timestamp'] . '</small>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    public function formatCode($code, $language = 'php', $format = 'html') {
        $formattedCode = [
            'type' => 'code',
            'language' => $language,
            'code' => $code,
            'lines' => substr_count($code, "\n") + 1,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        switch ($format) {
            case 'html':
                return $this->formatCodeHTML($formattedCode);
                
            case 'json':
                return json_encode($formattedCode, JSON_PRETTY_PRINT);
                
            case 'plain':
                return "```$language\n$code\n```";
                
            default:
                return $formattedCode;
        }
    }
    
    private function formatCodeHTML($codeData) {
        $html = '<div class="code-container">';
        $html .= '<div class="code-header">';
        $html .= '<span class="code-language">' . strtoupper($codeData['language']) . '</span>';
        $html .= '<span class="code-lines">' . $codeData['lines'] . ' qator</span>';
        $html .= '</div>';
        $html .= '<pre class="code-content"><code class="language-' . $codeData['language'] . '">';
        $html .= htmlspecialchars($codeData['code']);
        $html .= '</code></pre>';
        $html .= '</div>';
        
        return $html;
    }
    
    public function formatTable($data, $headers = [], $format = 'html') {
        if (empty($data)) {
            return '';
        }
        
        $tableData = [
            'type' => 'table',
            'headers' => $headers ?: array_keys($data[0]),
            'rows' => $data,
            'row_count' => count($data),
            'column_count' => count($headers ?: array_keys($data[0]))
        ];
        
        switch ($format) {
            case 'html':
                return $this->formatTableHTML($tableData);
                
            case 'markdown':
                return $this->formatTableMarkdown($tableData);
                
            case 'json':
                return json_encode($tableData, JSON_PRETTY_PRINT);
                
            default:
                return $tableData;
        }
    }
    
    private function formatTableHTML($tableData) {
        $html = '<div class="table-container">';
        $html .= '<table class="data-table">';
        
        // Headers
        $html .= '<thead><tr>';
        foreach ($tableData['headers'] as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead>';
        
        // Rows
        $html .= '<tbody>';
        foreach ($tableData['rows'] as $row) {
            $html .= '<tr>';
            foreach ($tableData['headers'] as $header) {
                $value = $row[$header] ?? '';
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
        
        $html .= '</table>';
        $html .= '</div>';
        
        return $html;
    }
}
?>
