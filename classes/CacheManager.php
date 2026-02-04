<?php
class CacheManager {
    private $db;
    private $enabled;
    private $cacheDir;
    private $memoryCache = [];
    
    public function __construct(Database $db) {
        $this->db = $db;
        $this->enabled = defined('ENABLE_CACHING') ? ENABLE_CACHING : true;
        $this->cacheDir = 'cache/';
        $this->initCacheDirectory();
    }
    
    private function initCacheDirectory() {
        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        // Create index file
        $indexFile = $this->cacheDir . 'index.html';
        if (!file_exists($indexFile)) {
            file_put_contents($indexFile, '<!-- Cache directory -->');
        }
    }
    
    public function get($key, $type = 'database') {
        if (!$this->enabled) {
            return null;
        }
        
        // Check memory cache first
        if (isset($this->memoryCache[$key])) {
            $cached = $this->memoryCache[$key];
            if ($cached['expires'] > time()) {
                return $cached['data'];
            } else {
                unset($this->memoryCache[$key]);
            }
        }
        
        switch ($type) {
            case 'database':
                return $this->getFromDatabase($key);
                
            case 'file':
                return $this->getFromFile($key);
                
            case 'memory':
                return null; // Already checked
                
            default:
                return null;
        }
    }
    
    private function getFromDatabase($key) {
        $sql = "SELECT response, expires_at 
                FROM jarvis_cache 
                WHERE query_hash = ? AND expires_at > NOW()
                LIMIT 1";
        
        $stmt = $this->db->executeQuery($sql, [$key], 's');
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $data = json_decode($row['response'], true);
            
            // Store in memory cache for faster access
            $this->memoryCache[$key] = [
                'data' => $data,
                'expires' => strtotime($row['expires_at'])
            ];
            
            return $data;
        }
        
        return null;
    }
    
    private function getFromFile($key) {
        $filePath = $this->cacheDir . $key . '.json';
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        
        if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
            unlink($filePath);
            return null;
        }
        
        // Store in memory cache
        $this->memoryCache[$key] = [
            'data' => $data['data'],
            'expires' => $data['expires']
        ];
        
        return $data['data'];
    }
    
    public function set($key, $data, $ttl = 3600, $type = 'database', $metadata = []) {
        if (!$this->enabled) {
            return false;
        }
        
        $expires = time() + $ttl;
        
        // Store in memory cache
        $this->memoryCache[$key] = [
            'data' => $data,
            'expires' => $expires,
            'metadata' => $metadata
        ];
        
        switch ($type) {
            case 'database':
                return $this->setInDatabase($key, $data, $expires, $metadata);
                
            case 'file':
                return $this->setInFile($key, $data, $expires, $metadata);
                
            case 'memory':
                return true; // Already stored
                
            default:
                return false;
        }
    }
    
    private function setInDatabase($key, $data, $expires, $metadata) {
        $expiresAt = date('Y-m-d H:i:s', $expires);
        
        $cacheData = [
            'query_hash' => $key,
            'response' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'source' => $metadata['source'] ?? 'cache',
            'model' => $metadata['model'] ?? 'unknown',
            'tokens_used' => $metadata['tokens_used'] ?? 0,
            'expires_at' => $expiresAt
        ];
        
        // Check if exists
        $existing = $this->db->select('jarvis_cache', 'id', ['query_hash' => $key]);
        
        if (empty($existing)) {
            return $this->db->insert('jarvis_cache', $cacheData);
        } else {
            return $this->db->update('jarvis_cache', $cacheData, ['id' => $existing[0]['id']]);
        }
    }
    
    private function setInFile($key, $data, $expires, $metadata) {
        $filePath = $this->cacheDir . $key . '.json';
        
        $cacheData = [
            'data' => $data,
            'expires' => $expires,
            'metadata' => $metadata,
            'created' => time()
        ];
        
        return file_put_contents($filePath, json_encode($cacheData, JSON_UNESCAPED_UNICODE));
    }
    
    public function delete($key, $type = 'all') {
        $deleted = 0;
        
        // Remove from memory cache
        if (isset($this->memoryCache[$key])) {
            unset($this->memoryCache[$key]);
            $deleted++;
        }
        
        if ($type === 'all' || $type === 'database') {
            $sql = "DELETE FROM jarvis_cache WHERE query_hash = ?";
            $stmt = $this->db->executeQuery($sql, [$key], 's');
            $deleted += $this->db->getConnection()->affected_rows;
        }
        
        if ($type === 'all' || $type === 'file') {
            $filePath = $this->cacheDir . $key . '.json';
            if (file_exists($filePath)) {
                unlink($filePath);
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    public function clear($type = 'all', $olderThan = null) {
        $cleared = [
            'memory' => 0,
            'database' => 0,
            'files' => 0
        ];
        
        // Clear memory cache
        if ($type === 'all' || $type === 'memory') {
            $cleared['memory'] = count($this->memoryCache);
            $this->memoryCache = [];
        }
        
        // Clear database cache
        if ($type === 'all' || $type === 'database') {
            if ($olderThan) {
                $sql = "DELETE FROM jarvis_cache WHERE expires_at < ?";
                $cutoff = date('Y-m-d H:i:s', strtotime($olderThan));
                $stmt = $this->db->executeQuery($sql, [$cutoff], 's');
            } else {
                $sql = "DELETE FROM jarvis_cache";
                $stmt = $this->db->executeQuery($sql);
            }
            $cleared['database'] = $this->db->getConnection()->affected_rows;
        }
        
        // Clear file cache
        if ($type === 'all' || $type === 'file') {
            $files = glob($this->cacheDir . '*.json');
            $cleared['files'] = 0;
            
            foreach ($files as $file) {
                if ($olderThan) {
                    if (filemtime($file) < strtotime($olderThan)) {
                        unlink($file);
                        $cleared['files']++;
                    }
                } else {
                    unlink($file);
                    $cleared['files']++;
                }
            }
        }
        
        return $cleared;
    }
    
    public function getStats() {
        $stats = [
            'enabled' => $this->enabled,
            'memory' => [
                'items' => count($this->memoryCache),
                'size' => strlen(serialize($this->memoryCache))
            ],
            'database' => [],
            'files' => []
        ];
        
        // Database cache stats
        $sql = "SELECT 
                    COUNT(*) as total_items,
                    SUM(LENGTH(response)) as total_size,
                    AVG(LENGTH(response)) as avg_size,
                    COUNT(CASE WHEN expires_at > NOW() THEN 1 END) as active_items,
                    COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired_items
                FROM jarvis_cache";
        
        $stmt = $this->db->executeQuery($sql);
        $result = $stmt->get_result();
        $stats['database'] = $result->fetch_assoc();
        
        // File cache stats
        $files = glob($this->cacheDir . '*.json');
        $stats['files']['total_items'] = count($files);
        $stats['files']['total_size'] = 0;
        
        foreach ($files as $file) {
            $stats['files']['total_size'] += filesize($file);
        }
        
        $stats['files']['avg_size'] = $stats['files']['total_items'] > 0 
            ? $stats['files']['total_size'] / $stats['files']['total_items'] 
            : 0;
        
        // Hit rate estimation (based on recent activity)
        $sql = "SELECT 
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits
                FROM analytics 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        
        $stmt = $this->db->executeQuery($sql);
        $result = $stmt->get_result();
        $hitStats = $result->fetch_assoc();
        
        $stats['hit_rate'] = $hitStats['total_requests'] > 0 
            ? round(($hitStats['cache_hits'] / $hitStats['total_requests']) * 100, 2)
            : 0;
        
        return $stats;
    }
    
    public function optimize() {
        $optimizations = [];
        
        // Clean expired cache from database
        $sql = "DELETE FROM jarvis_cache WHERE expires_at <= NOW()";
        $stmt = $this->db->executeQuery($sql);
        $optimizations['expired_db_entries'] = $this->db->getConnection()->affected_rows;
        
        // Clean expired cache from files
        $files = glob($this->cacheDir . '*.json');
        $optimizations['expired_files'] = 0;
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            if ($data && isset($data['expires']) && $data['expires'] < time()) {
                unlink($file);
                $optimizations['expired_files']++;
            }
        }
        
        // Optimize database table
        $this->db->executeQuery("OPTIMIZE TABLE jarvis_cache");
        
        // Clear memory cache of expired items
        $currentTime = time();
        $initialCount = count($this->memoryCache);
        
        foreach ($this->memoryCache as $key => $item) {
            if ($item['expires'] < $currentTime) {
                unset($this->memoryCache[$key]);
            }
        }
        
        $optimizations['expired_memory_items'] = $initialCount - count($this->memoryCache);
        
        return $optimizations;
    }
    
    public function prefetch($keys, $dataGenerator) {
        $results = [];
        $toFetch = [];
        
        foreach ($keys as $key) {
            $cached = $this->get($key);
            
            if ($cached !== null) {
                $results[$key] = $cached;
            } else {
                $toFetch[] = $key;
            }
        }
        
        if (!empty($toFetch)) {
            $fetchedData = $dataGenerator($toFetch);
            
            foreach ($fetchedData as $key => $data) {
                $this->set($key, $data);
                $results[$key] = $data;
            }
        }
        
        return $results;
    }
    
    public function cacheWithTags($key, $data, $tags, $ttl = 3600) {
        $result = $this->set($key, $data, $ttl, 'database', ['tags' => $tags]);
        
        if ($result) {
            // Store tag relationships
            foreach ($tags as $tag) {
                $tagKey = 'tag:' . $tag;
                $tagData = $this->get($tagKey) ?: [];
                $tagData[] = $key;
                $this->set($tagKey, $tagData, $ttl * 2, 'database');
            }
        }
        
        return $result;
    }
    
    public function getByTag($tag) {
        $tagKey = 'tag:' . $tag;
        $cachedKeys = $this->get($tagKey);
        
        if (!$cachedKeys) {
            return [];
        }
        
        $results = [];
        foreach ($cachedKeys as $key) {
            $data = $this->get($key);
            if ($data !== null) {
                $results[$key] = $data;
            }
        }
        
        return $results;
    }
    
    public function invalidateTag($tag) {
        $tagKey = 'tag:' . $tag;
        $cachedKeys = $this->get($tagKey);
        
        if ($cachedKeys) {
            foreach ($cachedKeys as $key) {
                $this->delete($key);
            }
            $this->delete($tagKey);
            
            return count($cachedKeys);
        }
        
        return 0;
    }
    
    public function enable() {
        $this->enabled = true;
        return true;
    }
    
    public function disable() {
        $this->enabled = false;
        $this->clear('memory');
        return true;
    }
    
    public function isEnabled() {
        return $this->enabled;
    }
    
    public function getMemoryUsage() {
        $usage = [
            'memory_cache_size' => count($this->memoryCache),
            'estimated_memory' => strlen(serialize($this->memoryCache))
        ];
        
        return $usage;
    }
}
?>
