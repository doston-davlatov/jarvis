<?php
class AIAnalyzer {
    private $db;
    private $webSearch;
    
    public function __construct(Database $db, WebSearch $webSearch) {
        $this->db = $db;
        $this->webSearch = $webSearch;
    }
    
    public function analyzeQuery($query, $context = []) {
        $analysis = [
            'query' => $query,
            'type' => $this->detectQueryType($query),
            'category' => $this->detectCategory($query),
            'complexity' => $this->assessComplexity($query),
            'needs_web_search' => $this->needsWebSearch($query),
            'needs_local_data' => $this->needsLocalData($query),
            'sentiment' => $this->analyzeSentiment($query),
            'topics' => $this->extractTopics($query),
            'entities' => $this->extractEntities($query),
            'language' => $this->detectLanguage($query),
            'timestamp' => time()
        ];
        
        // Add context from previous interactions
        if (!empty($context)) {
            $analysis['context'] = $context;
        }
        
        // Check learning database for similar queries
        $similar = $this->findSimilarQueries($query);
        if (!empty($similar)) {
            $analysis['similar_queries'] = $similar;
            $analysis['learning_score'] = $this->calculateLearningScore($similar);
        }
        
        return $analysis;
    }
    
    private function detectQueryType($query) {
        $query = strtolower($query);
        
        $typePatterns = [
            'project' => ['loyiha', 'project', 'portfolio', 'ishim', 'qilgan', 'tuzgan'],
            'blog' => ['blog', 'maqola', 'yozgan', 'post', 'article'],
            'tech' => ['texnologiya', 'technology', 'framework', 'dasturlash', 'programming', 'code'],
            'personal' => ['kim', 'who', 'haqida', 'about', 'doston', 'davlatov', 'yosh', 'age'],
            'current' => ['hozir', 'current', 'bugun', 'today', 'yangilik', 'news', 'trend'],
            'fact' => ['nima', 'what', 'qanday', 'how', 'necha', 'how many', 'qayerda', 'where'],
            'opinion' => ['fikring', 'opinion', 'tavsiya', 'recommend', 'qaysi', 'which', 'maslahat'],
            'technical' => ['error', 'xato', 'muammo', 'problem', 'yechim', 'solution', 'code', 'kod'],
            'creative' => ['yarat', 'create', 'idea', 'g\'oya', 'taklif', 'suggestion'],
            'analytical' => ['tahlil', 'analysis', 'statistika', 'statistics', 'raqam', 'number']
        ];
        
        foreach ($typePatterns as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($query, $keyword) !== false) {
                    return $type;
                }
            }
        }
        
        return 'general';
    }
    
    private function detectCategory($query) {
        $categories = [
            'technology' => ['python', 'javascript', 'php', 'react', 'vue', 'node', 'database'],
            'ai_ml' => ['ai', 'machine learning', 'deep learning', 'neural', 'chatgpt', 'gpt'],
            'web_dev' => ['web', 'website', 'frontend', 'backend', 'fullstack', 'api'],
            'cybersecurity' => ['security', 'xavfsizlik', 'hack', 'protection', 'encryption'],
            'business' => ['startup', 'business', 'money', 'investment', 'marketing'],
            'education' => ['learn', 'o\'rganish', 'course', 'tutorial', 'education']
        ];
        
        $query = strtolower($query);
        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($query, $keyword) !== false) {
                    return $category;
                }
            }
        }
        
        return 'general';
    }
    
    private function assessComplexity($query) {
        $wordCount = str_word_count($query);
        $sentenceCount = preg_match_all('/[.!?]+/', $query) + 1;
        
        $complexityScore = ($wordCount / 10) + ($sentenceCount * 2);
        
        if ($complexityScore < 1.5) return ['level' => 'simple', 'score' => $complexityScore];
        if ($complexityScore < 3) return ['level' => 'medium', 'score' => $complexityScore];
        return ['level' => 'complex', 'score' => $complexityScore];
    }
    
    private function needsWebSearch($query) {
        $type = $this->detectQueryType($query);
        $currentTimeTerms = ['hozir', 'bugun', 'today', 'current', 'yangilik', 'news'];
        $factTerms = ['kim', 'nima', 'qachon', 'qayerda', 'necha', 'who', 'what', 'when', 'where', 'how many'];
        
        $queryLower = strtolower($query);
        
        // Always search for current events
        foreach ($currentTimeTerms as $term) {
            if (strpos($queryLower, $term) !== false) {
                return true;
            }
        }
        
        // Search for factual questions
        foreach ($factTerms as $term) {
            if (strpos($queryLower, $term) !== false) {
                return true;
            }
        }
        
        // Types that need web search
        $searchTypes = ['current', 'fact', 'technical', 'analytical', 'general'];
        return in_array($type, $searchTypes);
    }
    
    private function needsLocalData($query) {
        $localTerms = ['doston', 'davlatov', 'loyiha', 'project', 'portfolio', 'blog', 'ish'];
        $queryLower = strtolower($query);
        
        foreach ($localTerms as $term) {
            if (strpos($queryLower, $term) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function analyzeSentiment($query) {
        $positiveWords = ['yaxshi', 'alohida', 'ajoyib', 'mukammal', 'good', 'great', 'excellent', 'thanks'];
        $negativeWords = ['yomon', 'xato', 'muammo', 'problem', 'bad', 'wrong', 'error', 'help'];
        
        $positiveCount = 0;
        $negativeCount = 0;
        $queryLower = strtolower($query);
        
        foreach ($positiveWords as $word) {
            if (strpos($queryLower, $word) !== false) $positiveCount++;
        }
        
        foreach ($negativeWords as $word) {
            if (strpos($queryLower, $word) !== false) $negativeCount++;
        }
        
        if ($positiveCount > $negativeCount) return 'positive';
        if ($negativeCount > $positiveCount) return 'negative';
        return 'neutral';
    }
    
    private function extractTopics($query) {
        $stopwords = ['va', 'haqida', 'qanday', 'nima', 'kim', 'qayerda', 'necha', 
                     'about', 'the', 'and', 'how', 'what', 'who', 'where', 'why'];
        
        $words = preg_split('/\s+/', strtolower($query));
        $topics = array_diff($words, $stopwords);
        $topics = array_filter($topics, function($word) {
            return strlen($word) > 2 && !is_numeric($word);
        });
        
        return array_values(array_unique($topics));
    }
    
    private function extractEntities($query) {
        // Simple entity extraction
        $entities = [
            'people' => [],
            'places' => [],
            'technologies' => [],
            'companies' => []
        ];
        
        // Common technology names
        $techTerms = ['python', 'javascript', 'php', 'react', 'vue', 'node', 'laravel', 
                     'django', 'tensorflow', 'pytorch', 'docker', 'kubernetes'];
        
        // Company names
        $companies = ['google', 'microsoft', 'apple', 'amazon', 'meta', 'openai', 'groq'];
        
        $queryLower = strtolower($query);
        
        foreach ($techTerms as $tech) {
            if (strpos($queryLower, $tech) !== false) {
                $entities['technologies'][] = $tech;
            }
        }
        
        foreach ($companies as $company) {
            if (strpos($queryLower, $company) !== false) {
                $entities['companies'][] = $company;
            }
        }
        
        return $entities;
    }
    
    private function detectLanguage($query) {
        $uzbekChars = ['ch', 'sh', 'yo', 'yo\'', 'g\'', 'o\'', 'q', 'x', 'h'];
        $englishChars = ['the', 'and', 'for', 'with', 'what', 'how', 'why'];
        
        $uzbekCount = 0;
        $englishCount = 0;
        
        $queryLower = strtolower($query);
        
        foreach ($uzbekChars as $char) {
            if (strpos($queryLower, $char) !== false) $uzbekCount++;
        }
        
        foreach ($englishChars as $char) {
            if (strpos($queryLower, $char) !== false) $englishCount++;
        }
        
        if ($uzbekCount > $englishCount) return 'uz';
        if ($englishCount > $uzbekCount) return 'en';
        return 'mixed';
    }
    
    private function findSimilarQueries($query) {
        $words = $this->extractTopics($query);
        if (empty($words)) return [];
        
        $similar = [];
        foreach ($words as $word) {
            $likeQuery = '%' . $word . '%';
            try {
                $stmt = $this->db->executeQuery(
                    "SELECT * FROM learning_data WHERE query LIKE ? ORDER BY quality_score DESC, usage_count DESC LIMIT 3",
                    [$likeQuery],
                    's'
                );
                $result = $stmt->get_result();
                $rows = $result->fetch_all(MYSQLI_ASSOC);
                $similar = array_merge($similar, $rows);
            } catch (Exception $e) {
                error_log("Similar query lookup failed: " . $e->getMessage());
            }
        }
        
        // Remove duplicates
        $unique = [];
        $seen = [];
        foreach ($similar as $item) {
            if (!in_array($item['query'], $seen)) {
                $seen[] = $item['query'];
                $unique[] = $item;
            }
        }
        
        return array_slice($unique, 0, 5);
    }
    
    private function calculateLearningScore($similarQueries) {
        if (empty($similarQueries)) return 0;
        
        $totalScore = 0;
        $count = 0;
        
        foreach ($similarQueries as $query) {
            $totalScore += $query['quality_score'] * $query['usage_count'];
            $count++;
        }
        
        return $count > 0 ? $totalScore / $count : 0;
    }
    
    public function gatherInformation($query, $analysis) {
        $information = [
            'local' => [],
            'web' => [],
            'contextual' => []
        ];
        
        // Get local information if needed
        if ($analysis['needs_local_data']) {
            $information['local'] = $this->getLocalInformation($query);
        }
        
        // Get web information if needed
        if ($analysis['needs_web_search'] && ENABLE_WEB_SEARCH) {
            $searchOptions = [
                'limit' => SEARCH_RESULTS_LIMIT,
                'sources' => $this->getSearchSourcesForQuery($query)
            ];
            
            $information['web'] = $this->webSearch->search($query, $searchOptions);
        }
        
        // Add contextual information
        $information['contextual'] = [
            'current_time' => date('Y-m-d H:i:s'),
            'user_session' => $_SESSION['jarvis_session_id'] ?? 'unknown',
            'query_analysis' => $analysis
        ];
        
        return $information;
    }
    
    private function getLocalInformation($query) {
        $information = [
            'projects' => [],
            'blogs' => [],
            'personal' => [],
            'skills' => []
        ];
        
        try {
            $likeQuery = '%' . $query . '%';

            // Search projects
            $projectsStmt = $this->db->executeQuery(
                "SELECT id, title, description, technologies, link, status, created_at
                 FROM projects
                 WHERE title LIKE ? OR description LIKE ? OR technologies LIKE ?
                 ORDER BY created_at DESC
                 LIMIT 5",
                [$likeQuery, $likeQuery, $likeQuery],
                'sss'
            );
            $information['projects'] = $projectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Search blogs
            $blogsStmt = $this->db->executeQuery(
                "SELECT id, title, description, content, publish_date, read_time, tags
                 FROM blogs
                 WHERE title LIKE ? OR description LIKE ? OR tags LIKE ?
                 ORDER BY publish_date DESC
                 LIMIT 5",
                [$likeQuery, $likeQuery, $likeQuery],
                'sss'
            );
            $information['blogs'] = $blogsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Personal information
            $information['personal'] = [
                'name' => 'Doston Davlatov',
                'title' => 'Full Stack Developer & AI Engineer',
                'location' => 'Tashkent, Uzbekistan',
                'email' => 'contact@doston-davlatov.uz',
                'website' => 'https://doston-davlatov.uz',
                'experience' => '5+ years',
                'education' => 'BSc in Computer Science'
            ];
            
            // Skills and technologies
            $information['skills'] = [
                'programming' => ['PHP', 'Python', 'JavaScript', 'TypeScript'],
                'frameworks' => ['Laravel', 'React', 'Vue.js', 'Node.js', 'TensorFlow'],
                'databases' => ['MySQL', 'PostgreSQL', 'MongoDB', 'Redis'],
                'devops' => ['Docker', 'Kubernetes', 'AWS', 'Git', 'CI/CD'],
                'ai_ml' => ['Machine Learning', 'Deep Learning', 'NLP', 'Computer Vision'],
                'languages' => ['Uzbek (Native)', 'English (Fluent)', 'Russian (Intermediate)']
            ];
            
        } catch (Exception $e) {
            error_log("Local info error: " . $e->getMessage());
        }
        
        return $information;
    }
    
    private function getSearchSourcesForQuery($query) {
        $analysis = $this->analyzeQuery($query);
        
        switch ($analysis['type']) {
            case 'current':
            case 'news':
                return ['ddg', 'news'];
                
            case 'technical':
            case 'fact':
                return ['ddg', 'wiki', 'github', 'stackoverflow'];
                
            case 'general':
                return ['ddg', 'wiki'];
                
            default:
                return ['ddg'];
        }
    }
    
    public function learnFromInteraction($query, $response, $quality = 1.0) {
        $analysis = $this->analyzeQuery($query);
        
        $learningData = [
            'query' => $query,
            'response' => substr($response, 0, 1000), // Limit response length
            'category' => $analysis['category'],
            'quality_score' => $quality,
            'usage_count' => 1
        ];
        
        // Check if similar query exists
        $existing = $this->findSimilarQueries($query);
        if (!empty($existing)) {
            // Update existing entry
            $existingId = $existing[0]['id'];
            $currentCount = $existing[0]['usage_count'];
            $currentScore = $existing[0]['quality_score'];
            
            $newScore = ($currentScore * $currentCount + $quality) / ($currentCount + 1);
            
            return $this->db->update('learning_data', [
                'usage_count' => $currentCount + 1,
                'quality_score' => $newScore,
                'response' => $learningData['response']
            ], ['id' => $existingId]);
        } else {
            // Insert new entry
            return $this->db->insert('learning_data', $learningData);
        }
    }
}
?>
