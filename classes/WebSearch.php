<?php
class WebSearch {
    private $db;
    private $cacheEnabled;
    
    public function __construct(Database $db) {
        $this->db = $db;
        $this->cacheEnabled = ENABLE_CACHING;
    }
    
    public function search($query, $options = []) {
        $defaults = [
            'limit' => SEARCH_RESULTS_LIMIT,
            'timeout' => SEARCH_TIMEOUT,
            'force_fresh' => false,
            'sources' => ['ddg', 'wiki', 'news']
        ];
        
        $options = array_merge($defaults, $options);
        
        // Check cache first
        if (!$options['force_fresh'] && $this->cacheEnabled) {
            $cached = $this->getCachedSearch($query, $options['limit']);
            if ($cached) {
                return array_merge($cached, ['cached' => true]);
            }
        }
        
        $results = [];
        $searchPromises = [];
        
        // Parallel search execution
        foreach ($options['sources'] as $source) {
            switch ($source) {
                case 'ddg':
                    if (ENABLE_DUCKDUCKGO) {
                        $searchPromises[] = $this->searchDuckDuckGoAsync($query);
                    }
                    break;
                    
                case 'wiki':
                    if (ENABLE_WIKIPEDIA) {
                        $searchPromises[] = $this->searchWikipediaAsync($query);
                    }
                    break;
                    
                case 'news':
                    if (ENABLE_NEWS && NEWSAPI_KEY) {
                        $searchPromises[] = $this->searchNewsAsync($query);
                    }
                    break;
                    
                case 'github':
                    $searchPromises[] = $this->searchGitHubAsync($query);
                    break;
                    
                case 'stackoverflow':
                    $searchPromises[] = $this->searchStackOverflowAsync($query);
                    break;
            }
        }
        
        // Execute all searches in parallel
        $multiHandle = curl_multi_init();
        $handles = [];
        
        foreach ($searchPromises as $promise) {
            $handles[] = $promise;
            curl_multi_add_handle($multiHandle, $promise);
        }
        
        // Execute queries
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);
        
        // Get results
        foreach ($handles as $handle) {
            $response = curl_multi_getcontent($handle);
            $source = curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
            
            if (strpos($source, 'duckduckgo') !== false) {
                $results = array_merge($results, $this->parseDuckDuckGo($response));
            } elseif (strpos($source, 'wikipedia') !== false) {
                $results = array_merge($results, $this->parseWikipedia($response));
            } elseif (strpos($source, 'newsapi') !== false) {
                $results = array_merge($results, $this->parseNews($response));
            } elseif (strpos($source, 'github') !== false) {
                $results = array_merge($results, $this->parseGitHub($response));
            } elseif (strpos($source, 'stackoverflow') !== false) {
                $results = array_merge($results, $this->parseStackOverflow($response));
            }
            
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }
        
        curl_multi_close($multiHandle);
        
        // Remove duplicates and limit results
        $results = $this->removeDuplicates($results);
        $results = array_slice($results, 0, $options['limit']);
        
        // Cache results
        if ($this->cacheEnabled && !empty($results)) {
            $this->cacheSearchResults($query, $results);
        }
        
        return $results;
    }
    
    private function searchDuckDuckGoAsync($query) {
        $url = "https://api.duckduckgo.com/?q=" . urlencode($query) . 
               "&format=json&no_html=1&skip_disambig=1&t=doston-davlatov";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => SEARCH_TIMEOUT,
            CURLOPT_USERAGENT => 'JARVIS-OSINT-AI/5.0 (+https://doston-davlatov.uz)',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);
        
        return $ch;
    }
    
    private function searchWikipediaAsync($query) {
        $url = "https://en.wikipedia.org/w/api.php?" . http_build_query([
            'action' => 'query',
            'format' => 'json',
            'prop' => 'extracts|info',
            'exintro' => true,
            'explaintext' => true,
            'inprop' => 'url',
            'redirects' => true,
            'titles' => $query
        ]);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => SEARCH_TIMEOUT
        ]);
        
        return $ch;
    }
    
    private function searchNewsAsync($query) {
        $url = "https://newsapi.org/v2/everything?" . http_build_query([
            'q' => $query,
            'apiKey' => NEWSAPI_KEY,
            'pageSize' => 5,
            'sortBy' => 'relevancy',
            'language' => 'en'
        ]);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => SEARCH_TIMEOUT
        ]);
        
        return $ch;
    }
    
    private function searchGitHubAsync($query) {
        $url = "https://api.github.com/search/repositories?q=" . urlencode($query) . 
               "&sort=stars&order=desc&per_page=3";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => SEARCH_TIMEOUT,
            CURLOPT_USERAGENT => 'JARVIS-AI',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json']
        ]);
        
        return $ch;
    }
    
    private function searchStackOverflowAsync($query) {
        $url = "https://api.stackexchange.com/2.3/search?" . http_build_query([
            'order' => 'desc',
            'sort' => 'relevance',
            'intitle' => $query,
            'site' => 'stackoverflow',
            'pagesize' => 3
        ]);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => SEARCH_TIMEOUT
        ]);
        
        return $ch;
    }
    
    private function parseDuckDuckGo($response) {
        $results = [];
        $data = json_decode($response, true);
        
        if (!empty($data['AbstractText'])) {
            $results[] = [
                'title' => $data['Heading'] ?: 'DuckDuckGo Result',
                'snippet' => $data['AbstractText'],
                'url' => $data['AbstractURL'] ?: 'https://duckduckgo.com/?q=' . urlencode($data['Heading'] ?? ''),
                'source' => 'DuckDuckGo',
                'type' => 'general',
                'confidence' => 0.9,
                'timestamp' => time()
            ];
        }
        
        if (!empty($data['RelatedTopics'])) {
            foreach ($data['RelatedTopics'] as $topic) {
                if (isset($topic['Text']) && isset($topic['FirstURL'])) {
                    $results[] = [
                        'title' => $topic['Text'],
                        'snippet' => $topic['Text'],
                        'url' => $topic['FirstURL'],
                        'source' => 'DuckDuckGo',
                        'type' => 'related',
                        'confidence' => 0.7,
                        'timestamp' => time()
                    ];
                }
            }
        }
        
        return $results;
    }
    
    private function parseWikipedia($response) {
        $results = [];
        $data = json_decode($response, true);
        $pages = $data['query']['pages'] ?? [];
        
        foreach ($pages as $page) {
            if (isset($page['extract']) && !empty($page['extract'])) {
                $results[] = [
                    'title' => $page['title'],
                    'snippet' => substr($page['extract'], 0, 300) . '...',
                    'url' => "https://en.wikipedia.org/wiki/" . urlencode($page['title']),
                    'source' => 'Wikipedia',
                    'type' => 'encyclopedia',
                    'confidence' => 0.95,
                    'timestamp' => time()
                ];
            }
        }
        
        return $results;
    }
    
    private function parseNews($response) {
        $results = [];
        $data = json_decode($response, true);
        
        if (!empty($data['articles'])) {
            foreach ($data['articles'] as $article) {
                $results[] = [
                    'title' => $article['title'] ?? '',
                    'snippet' => $article['description'] ?? '',
                    'url' => $article['url'] ?? '',
                    'source' => $article['source']['name'] ?? 'News',
                    'type' => 'news',
                    'confidence' => 0.8,
                    'timestamp' => strtotime($article['publishedAt'] ?? 'now')
                ];
            }
        }
        
        return $results;
    }
    
    private function parseGitHub($response) {
        $results = [];
        $data = json_decode($response, true);
        
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $results[] = [
                    'title' => $item['full_name'],
                    'snippet' => $item['description'] ?: 'GitHub repository',
                    'url' => $item['html_url'],
                    'source' => 'GitHub',
                    'type' => 'code',
                    'confidence' => 0.85,
                    'metadata' => [
                        'stars' => $item['stargazers_count'],
                        'language' => $item['language'],
                        'forks' => $item['forks_count']
                    ],
                    'timestamp' => time()
                ];
            }
        }
        
        return $results;
    }
    
    private function parseStackOverflow($response) {
        $results = [];
        $data = json_decode($response, true);
        
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $results[] = [
                    'title' => html_entity_decode($item['title']),
                    'snippet' => isset($item['excerpt']) ? strip_tags($item['excerpt']) : 'StackOverflow question',
                    'url' => $item['link'],
                    'source' => 'StackOverflow',
                    'type' => 'technical',
                    'confidence' => 0.8,
                    'metadata' => [
                        'score' => $item['score'],
                        'answers' => $item['answer_count'],
                        'views' => $item['view_count']
                    ],
                    'timestamp' => time()
                ];
            }
        }
        
        return $results;
    }
    
    private function getCachedSearch($query, $limit) {
        $queryHash = md5("search_" . $query . "_" . $limit);
        
        $result = $this->db->select('jarvis_cache', '*', [
            'query_hash' => $queryHash,
            'source' => 'web_search'
        ], 'created_at DESC', 1);
        
        if (!empty($result) && strtotime($result[0]['expires_at']) > time()) {
            return json_decode($result[0]['response'], true);
        }
        
        return null;
    }
    
    private function cacheSearchResults($query, $results) {
        $queryHash = md5("search_" . $query . "_" . SEARCH_RESULTS_LIMIT);
        $expiresAt = date('Y-m-d H:i:s', time() + CACHE_DURATION);
        
        $cacheData = [
            'query_hash' => $queryHash,
            'response' => json_encode($results, JSON_UNESCAPED_UNICODE),
            'source' => 'web_search',
            'model' => 'web_search',
            'tokens_used' => 0,
            'expires_at' => $expiresAt
        ];
        
        // Check if exists
        $existing = $this->db->select('jarvis_cache', 'id', ['query_hash' => $queryHash]);
        
        if (empty($existing)) {
            return $this->db->insert('jarvis_cache', $cacheData);
        } else {
            return $this->db->update('jarvis_cache', $cacheData, ['id' => $existing[0]['id']]);
        }
    }
    
    private function removeDuplicates($results) {
        $uniqueResults = [];
        $urls = [];
        
        foreach ($results as $result) {
            $url = $result['url'] ?? '';
            if (!in_array($url, $urls) && $url !== '') {
                $urls[] = $url;
                $uniqueResults[] = $result;
            }
        }
        
        return $uniqueResults;
    }
    
    public function clearExpiredCache() {
        $sql = "DELETE FROM jarvis_cache WHERE expires_at <= NOW()";
        $this->db->executeQuery($sql);
        return $this->db->getConnection()->affected_rows;
    }
}
?>
