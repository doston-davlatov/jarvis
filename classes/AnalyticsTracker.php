<?php
class AnalyticsTracker {
    private $db;
    private $sessionId;
    private $userIp;
    private $startTime;
    
    public function __construct(Database $db) {
        $this->db = $db;
        $this->sessionId = $_SESSION['jarvis_session_id'] ?? 'unknown';
        $this->userIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->startTime = microtime(true);
    }
    
    public function trackQuery($query, $response, $metadata = []) {
        $responseTime = round((microtime(true) - $this->startTime) * 1000, 2);
        
        $analyticsData = [
            'session_id' => $this->sessionId,
            'user_ip' => $this->userIp,
            'query' => substr($query, 0, 500),
            'response_time' => $responseTime,
            'tokens_used' => $metadata['tokens_used'] ?? 0,
            'model' => $metadata['model'] ?? 'unknown',
            'source' => $metadata['source'] ?? 'groq',
            'cache_hit' => $metadata['cache_hit'] ?? 0,
            'response_length' => strlen($response['response'] ?? ''),
            'error' => $metadata['error'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Update session activity
        $this->updateSessionActivity();
        
        // Insert analytics record
        $analyticsId = $this->db->insert('analytics', $analyticsData);
        
        // Log to file for backup
        $this->logToFile($analyticsData);
        
        return $analyticsId;
    }
    
    private function updateSessionActivity() {
        // Check if session exists
        $existingSession = $this->db->select('user_sessions', '*', 
            ['session_id' => $this->sessionId]);
        
        if (empty($existingSession)) {
            // Create new session
            $sessionData = [
                'session_id' => $this->sessionId,
                'user_ip' => $this->userIp,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'total_queries' => 1,
                'start_time' => date('Y-m-d H:i:s'),
                'last_activity' => date('Y-m-d H:i:s')
            ];
            
            $this->db->insert('user_sessions', $sessionData);
        } else {
            // Update existing session
            $this->db->update('user_sessions', 
                [
                    'total_queries' => $existingSession[0]['total_queries'] + 1,
                    'last_activity' => date('Y-m-d H:i:s')
                ],
                ['session_id' => $this->sessionId]
            );
        }
    }
    
    private function logToFile($data) {
        $logDir = 'logs/analytics/';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . date('Y-m-d') . '.json';
        $logEntry = json_encode($data) . PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    public function getSessionStats($sessionId = null) {
        $sessionId = $sessionId ?? $this->sessionId;
        
        $sql = "SELECT 
                    COUNT(*) as total_queries,
                    AVG(response_time) as avg_response_time,
                    SUM(tokens_used) as total_tokens,
                    MIN(created_at) as first_query,
                    MAX(created_at) as last_query,
                    SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits
                FROM analytics 
                WHERE session_id = ?";
        
        $stmt = $this->db->executeQuery($sql, [$sessionId], 's');
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        
        // Get query distribution by hour
        $hourSql = "SELECT 
                        HOUR(created_at) as hour,
                        COUNT(*) as query_count
                    FROM analytics 
                    WHERE session_id = ? 
                    GROUP BY HOUR(created_at)
                    ORDER BY hour";
        
        $hourStmt = $this->db->executeQuery($hourSql, [$sessionId], 's');
        $hourResult = $hourStmt->get_result();
        
        $hourDistribution = [];
        while ($row = $hourResult->fetch_assoc()) {
            $hourDistribution[$row['hour']] = $row['query_count'];
        }
        
        $stats['hour_distribution'] = $hourDistribution;
        
        return $stats;
    }
    
    public function getSystemStats($period = 'today') {
        $startDate = date('Y-m-d 00:00:00');
        $endDate = date('Y-m-d 23:59:59');
        
        if ($period === 'week') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        } elseif ($period === 'month') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        } elseif ($period === 'all') {
            $startDate = '1970-01-01 00:00:00';
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_queries,
                    COUNT(DISTINCT session_id) as unique_sessions,
                    AVG(response_time) as avg_response_time,
                    SUM(tokens_used) as total_tokens,
                    SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits,
                    COUNT(CASE WHEN error IS NOT NULL THEN 1 END) as error_count,
                    model,
                    DATE(created_at) as stat_date
                FROM analytics 
                WHERE created_at BETWEEN ? AND ?
                GROUP BY model, DATE(created_at)
                ORDER BY stat_date DESC, total_queries DESC";
        
        $stmt = $this->db->executeQuery($sql, [$startDate, $endDate], 'ss');
        $result = $stmt->get_result();
        
        $stats = [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total' => [
                'queries' => 0,
                'sessions' => 0,
                'tokens' => 0,
                'cache_hits' => 0,
                'errors' => 0,
                'avg_response_time' => 0
            ],
            'daily' => [],
            'models' => [],
            'trends' => []
        ];
        
        while ($row = $result->fetch_assoc()) {
            $date = $row['stat_date'];
            $model = $row['model'];
            
            // Update totals
            $stats['total']['queries'] += $row['total_queries'];
            $stats['total']['tokens'] += $row['total_tokens'];
            $stats['total']['cache_hits'] += $row['cache_hits'];
            $stats['total']['errors'] += $row['error_count'];
            
            // Initialize daily stats
            if (!isset($stats['daily'][$date])) {
                $stats['daily'][$date] = [
                    'queries' => 0,
                    'tokens' => 0,
                    'cache_hits' => 0,
                    'errors' => 0,
                    'models' => []
                ];
            }
            
            $stats['daily'][$date]['queries'] += $row['total_queries'];
            $stats['daily'][$date]['tokens'] += $row['total_tokens'];
            $stats['daily'][$date]['cache_hits'] += $row['cache_hits'];
            $stats['daily'][$date]['errors'] += $row['error_count'];
            $stats['daily'][$date]['models'][$model] = $row['total_queries'];
            
            // Track models
            if (!isset($stats['models'][$model])) {
                $stats['models'][$model] = [
                    'queries' => 0,
                    'tokens' => 0,
                    'avg_response_time' => 0
                ];
            }
            
            $stats['models'][$model]['queries'] += $row['total_queries'];
            $stats['models'][$model]['tokens'] += $row['total_tokens'];
            
            // Calculate weighted average response time
            $currentAvg = $stats['models'][$model]['avg_response_time'];
            $currentQueries = $stats['models'][$model]['queries'] - $row['total_queries'];
            
            if ($currentQueries > 0) {
                $stats['models'][$model]['avg_response_time'] = 
                    ($currentAvg * $currentQueries + $row['avg_response_time'] * $row['total_queries']) / 
                    $stats['models'][$model]['queries'];
            } else {
                $stats['models'][$model]['avg_response_time'] = $row['avg_response_time'];
            }
        }
        
        // Calculate overall averages
        if ($stats['total']['queries'] > 0) {
            $stats['total']['avg_response_time'] = $this->calculateOverallAvgResponseTime($stats);
            
            // Unique sessions count
            $sessionSql = "SELECT COUNT(DISTINCT session_id) as unique_sessions 
                          FROM analytics 
                          WHERE created_at BETWEEN ? AND ?";
            $sessionStmt = $this->db->executeQuery($sessionSql, [$startDate, $endDate], 'ss');
            $sessionResult = $sessionStmt->get_result();
            $stats['total']['sessions'] = $sessionResult->fetch_assoc()['unique_sessions'] ?? 0;
        }
        
        // Calculate trends
        $stats['trends'] = $this->calculateTrends($stats['daily']);
        
        return $stats;
    }
    
    private function calculateOverallAvgResponseTime($stats) {
        $totalWeightedTime = 0;
        $totalQueries = 0;
        
        foreach ($stats['models'] as $model => $modelStats) {
            $totalWeightedTime += $modelStats['avg_response_time'] * $modelStats['queries'];
            $totalQueries += $modelStats['queries'];
        }
        
        return $totalQueries > 0 ? $totalWeightedTime / $totalQueries : 0;
    }
    
    private function calculateTrends($dailyStats) {
        $trends = [
            'queries' => 0,
            'tokens' => 0,
            'cache_hits' => 0,
            'errors' => 0
        ];
        
        $dates = array_keys($dailyStats);
        sort($dates);
        
        if (count($dates) < 2) {
            return $trends;
        }
        
        $firstDate = $dates[0];
        $lastDate = $dates[count($dates) - 1];
        
        $firstDay = $dailyStats[$firstDate];
        $lastDay = $dailyStats[$lastDate];
        
        // Calculate percentage changes
        foreach (['queries', 'tokens', 'cache_hits', 'errors'] as $metric) {
            $firstValue = $firstDay[$metric] ?? 0;
            $lastValue = $lastDay[$metric] ?? 0;
            
            if ($firstValue > 0) {
                $trends[$metric] = (($lastValue - $firstValue) / $firstValue) * 100;
            } elseif ($lastValue > 0) {
                $trends[$metric] = 100; // From 0 to positive
            }
        }
        
        return $trends;
    }
    
    public function getTopQueries($limit = 10, $period = 'today') {
        $startDate = date('Y-m-d 00:00:00');
        
        if ($period === 'week') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        } elseif ($period === 'month') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
        
        $sql = "SELECT 
                    query,
                    COUNT(*) as frequency,
                    AVG(response_time) as avg_response_time,
                    AVG(tokens_used) as avg_tokens,
                    MIN(created_at) as first_asked,
                    MAX(created_at) as last_asked
                FROM analytics 
                WHERE created_at >= ? 
                AND LENGTH(query) > 5
                GROUP BY query
                ORDER BY frequency DESC, last_asked DESC
                LIMIT ?";
        
        $stmt = $this->db->executeQuery($sql, [$startDate, $limit], 'si');
        $result = $stmt->get_result();
        
        $topQueries = [];
        while ($row = $result->fetch_assoc()) {
            $topQueries[] = $row;
        }
        
        return $topQueries;
    }
    
    public function getErrorAnalysis($period = 'today') {
        $startDate = date('Y-m-d 00:00:00');
        
        if ($period === 'week') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        } elseif ($period === 'month') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
        
        $sql = "SELECT 
                    error,
                    COUNT(*) as error_count,
                    model,
                    DATE(created_at) as error_date,
                    GROUP_CONCAT(DISTINCT session_id) as affected_sessions
                FROM analytics 
                WHERE created_at >= ? 
                AND error IS NOT NULL
                GROUP BY error, model, DATE(created_at)
                ORDER BY error_count DESC, error_date DESC";
        
        $stmt = $this->db->executeQuery($sql, [$startDate], 's');
        $result = $stmt->get_result();
        
        $errors = [];
        while ($row = $result->fetch_assoc()) {
            $errors[] = $row;
        }
        
        return $errors;
    }
    
    public function getPerformanceMetrics($period = 'today') {
        $startDate = date('Y-m-d 00:00:00');
        $endDate = date('Y-m-d 23:59:59');
        
        if ($period === 'week') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        } elseif ($period === 'month') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
        
        $sql = "SELECT 
                    HOUR(created_at) as hour_of_day,
                    COUNT(*) as query_count,
                    AVG(response_time) as avg_response_time,
                    AVG(tokens_used) as avg_tokens,
                    SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 as cache_hit_rate,
                    COUNT(CASE WHEN error IS NOT NULL THEN 1 END) / COUNT(*) * 100 as error_rate
                FROM analytics 
                WHERE created_at BETWEEN ? AND ?
                GROUP BY HOUR(created_at)
                ORDER BY hour_of_day";
        
        $stmt = $this->db->executeQuery($sql, [$startDate, $endDate], 'ss');
        $result = $stmt->get_result();
        
        $metrics = [];
        while ($row = $result->fetch_assoc()) {
            $metrics[$row['hour_of_day']] = [
                'query_count' => $row['query_count'],
                'avg_response_time' => round($row['avg_response_time'], 2),
                'avg_tokens' => round($row['avg_tokens'], 2),
                'cache_hit_rate' => round($row['cache_hit_rate'], 2),
                'error_rate' => round($row['error_rate'], 2)
            ];
        }
        
        // Fill missing hours
        for ($hour = 0; $hour < 24; $hour++) {
            if (!isset($metrics[$hour])) {
                $metrics[$hour] = [
                    'query_count' => 0,
                    'avg_response_time' => 0,
                    'avg_tokens' => 0,
                    'cache_hit_rate' => 0,
                    'error_rate' => 0
                ];
            }
        }
        
        ksort($metrics);
        
        return $metrics;
    }
    
    public function getUserEngagementMetrics() {
        $sql = "SELECT 
                    DATE(created_at) as engagement_date,
                    COUNT(DISTINCT session_id) as active_sessions,
                    COUNT(*) as total_queries,
                    AVG(CASE WHEN session_id IN (
                        SELECT session_id 
                        FROM analytics 
                        GROUP BY session_id 
                        HAVING COUNT(*) > 5
                    ) THEN 1 ELSE 0 END) * 100 as engaged_user_percentage,
                    AVG(response_time) as avg_response_time
                FROM analytics 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
                ORDER BY engagement_date DESC";
        
        $stmt = $this->db->executeQuery($sql);
        $result = $stmt->get_result();
        
        $engagement = [];
        while ($row = $result->fetch_assoc()) {
            $engagement[] = $row;
        }
        
        return $engagement;
    }
    
    public function exportData($format = 'json', $period = 'today') {
        $stats = $this->getSystemStats($period);
        
        switch ($format) {
            case 'json':
                return json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
            case 'csv':
                return $this->convertToCSV($stats);
                
            case 'html':
                return $this->convertToHTML($stats);
                
            default:
                return json_encode($stats);
        }
    }
    
    private function convertToCSV($stats) {
        $csv = "Metric,Value\n";
        
        // Total stats
        foreach ($stats['total'] as $metric => $value) {
            $csv .= "Total $metric,$value\n";
        }
        
        // Daily stats
        $csv .= "\nDaily Stats\n";
        $csv .= "Date,Queries,Tokens,Cache Hits,Errors\n";
        
        foreach ($stats['daily'] as $date => $daily) {
            $csv .= sprintf("%s,%d,%d,%d,%d\n",
                $date,
                $daily['queries'],
                $daily['tokens'],
                $daily['cache_hits'],
                $daily['errors']
            );
        }
        
        return $csv;
    }
    
    private function convertToHTML($stats) {
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <title>JARVIS Analytics Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .metric { font-weight: bold; color: #333; }
                .positive { color: green; }
                .negative { color: red; }
            </style>
        </head>
        <body>
            <h1>JARVIS AI Analytics Report</h1>
            <p>Period: ' . $stats['period'] . '</p>
            <p>Date Range: ' . $stats['start_date'] . ' to ' . $stats['end_date'] . '</p>
            
            <h2>Total Statistics</h2>
            <table>
                <tr>
                    <th>Metric</th>
                    <th>Value</th>
                </tr>';
        
        foreach ($stats['total'] as $metric => $value) {
            $html .= '<tr>
                <td class="metric">' . ucfirst(str_replace('_', ' ', $metric)) . '</td>
                <td>' . $value . '</td>
            </tr>';
        }
        
        $html .= '</table>
            
            <h2>Model Performance</h2>
            <table>
                <tr>
                    <th>Model</th>
                    <th>Queries</th>
                    <th>Tokens</th>
                    <th>Avg Response Time</th>
                </tr>';
        
        foreach ($stats['models'] as $model => $modelStats) {
            $html .= '<tr>
                <td>' . $model . '</td>
                <td>' . $modelStats['queries'] . '</td>
                <td>' . $modelStats['tokens'] . '</td>
                <td>' . round($modelStats['avg_response_time'], 2) . 'ms</td>
            </tr>';
        }
        
        $html .= '</table>
            
            <h2>Trends</h2>
            <table>
                <tr>
                    <th>Metric</th>
                    <th>Change</th>
                </tr>';
        
        foreach ($stats['trends'] as $metric => $change) {
            $class = $change >= 0 ? 'positive' : 'negative';
            $sign = $change >= 0 ? '+' : '';
            $html .= '<tr>
                <td class="metric">' . ucfirst(str_replace('_', ' ', $metric)) . '</td>
                <td class="' . $class . '">' . $sign . round($change, 2) . '%</td>
            </tr>';
        }
        
        $html .= '</table>
        </body>
        </html>';
        
        return $html;
    }
    
    public function cleanupOldData($daysToKeep = 30) {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysToKeep days"));
        
        // Delete old analytics
        $sql = "DELETE FROM analytics WHERE created_at < ?";
        $stmt = $this->db->executeQuery($sql, [$cutoffDate], 's');
        $deletedAnalytics = $this->db->getConnection()->affected_rows;
        
        // Delete old sessions
        $sql = "DELETE FROM user_sessions WHERE last_activity < ?";
        $stmt = $this->db->executeQuery($sql, [$cutoffDate], 's');
        $deletedSessions = $this->db->getConnection()->affected_rows;
        
        // Clean up old cache
        $cacheDir = 'audio_cache/';
        if (file_exists($cacheDir)) {
            $files = glob($cacheDir . '*');
            $deletedFiles = 0;
            
            foreach ($files as $file) {
                if (filemtime($file) < strtotime("-$daysToKeep days")) {
                    unlink($file);
                    $deletedFiles++;
                }
            }
        }
        
        return [
            'deleted_analytics' => $deletedAnalytics,
            'deleted_sessions' => $deletedSessions,
            'deleted_cache_files' => $deletedFiles ?? 0,
            'cutoff_date' => $cutoffDate
        ];
    }
    
    public function getRealTimeStats() {
        $currentTime = date('Y-m-d H:i:s');
        $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        
        $sql = "SELECT 
                    COUNT(*) as queries_last_5min,
                    AVG(response_time) as avg_response_time_5min,
                    COUNT(DISTINCT session_id) as active_sessions_5min,
                    SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits_5min
                FROM analytics 
                WHERE created_at BETWEEN ? AND ?";
        
        $stmt = $this->db->executeQuery($sql, [$fiveMinutesAgo, $currentTime], 'ss');
        $result = $stmt->get_result();
        $realtime = $result->fetch_assoc();
        
        // Current active sessions (last 10 minutes)
        $sql = "SELECT COUNT(DISTINCT session_id) as current_active_sessions
                FROM analytics 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
        
        $stmt = $this->db->executeQuery($sql);
        $result = $stmt->get_result();
        $activeSessions = $result->fetch_assoc()['current_active_sessions'] ?? 0;
        
        $realtime['current_active_sessions'] = $activeSessions;
        $realtime['timestamp'] = $currentTime;
        
        return $realtime;
    }
}
?>
