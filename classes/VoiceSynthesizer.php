<?php
class VoiceSynthesizer {
    private $db;
    private $enabled;
    private $voices = [];
    private $audioDir;
    
    public function __construct(Database $db) {
        $this->db = $db;
        $this->enabled = ENABLE_VOICE_SYNTHESIS;
        $this->audioDir = 'audio_cache/';
        $this->initAudioDirectory();
        $this->loadVoices();
    }
    
    private function initAudioDirectory() {
        if (!file_exists($this->audioDir)) {
            mkdir($this->audioDir, 0755, true);
        }
        
        // Create .htaccess to protect directory
        $htaccess = $this->audioDir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }
    
    private function loadVoices() {
        $this->voices = [
            'uz' => [
                'name' => 'Uzbek Voice',
                'lang' => 'uz-UZ',
                'gender' => 'male',
                'provider' => 'system'
            ],
            'en' => [
                'name' => 'English Voice',
                'lang' => 'en-US',
                'gender' => 'male',
                'provider' => 'system'
            ],
            'ru' => [
                'name' => 'Russian Voice',
                'lang' => 'ru-RU',
                'gender' => 'female',
                'provider' => 'system'
            ]
        ];
    }
    
    public function textToSpeech($text, $options = []) {
        if (!$this->enabled || empty($text)) {
            return null;
        }
        
        // Default options
        $defaults = [
            'language' => DEFAULT_VOICE_LANG,
            'speed' => VOICE_SPEED,
            'pitch' => VOICE_PITCH,
            'volume' => 0.8,
            'cache' => true,
            'format' => 'mp3',
            'voice' => null
        ];
        
        $options = array_merge($defaults, $options);
        
        // Generate audio file hash
        $audioHash = $this->generateAudioHash($text, $options);
        $audioFile = $this->audioDir . $audioHash . '.' . $options['format'];
        
        // Check cache
        if ($options['cache'] && file_exists($audioFile)) {
            return [
                'file' => $audioFile,
                'url' => $this->getAudioUrl($audioHash, $options['format']),
                'cached' => true,
                'hash' => $audioHash,
                'size' => filesize($audioFile)
            ];
        }
        
        // Generate speech
        $result = $this->generateSpeech($text, $options);
        
        if ($result && isset($result['file'])) {
            // Save to cache
            if ($options['cache']) {
                $this->saveToCache($audioFile, $result['content']);
                
                // Log audio generation
                $this->logAudioGeneration($text, $audioHash, $options);
            }
            
            return [
                'file' => $result['file'],
                'url' => $this->getAudioUrl($audioHash, $options['format']),
                'cached' => false,
                'hash' => $audioHash,
                'size' => strlen($result['content']),
                'duration' => $result['duration'] ?? 0
            ];
        }
        
        return null;
    }
    
    private function generateSpeech($text, $options) {
        // Try multiple TTS providers in order
        $providers = ['system', 'google', 'azure'];
        
        foreach ($providers as $provider) {
            try {
                switch ($provider) {
                    case 'system':
                        return $this->useSystemTTS($text, $options);
                        
                    case 'google':
                        if (defined('GOOGLE_TTS_KEY')) {
                            return $this->useGoogleTTS($text, $options);
                        }
                        break;
                        
                    case 'azure':
                        if (defined('AZURE_TTS_KEY')) {
                            return $this->useAzureTTS($text, $options);
                        }
                        break;
                }
            } catch (Exception $e) {
                error_log("TTS provider $provider failed: " . $e->getMessage());
                continue;
            }
        }
        
        throw new Exception("All TTS providers failed");
    }
    
    private function useSystemTTS($text, $options) {
        // This is a placeholder for system TTS
        // In production, you might use exec() with system TTS tools
        // For now, we'll simulate with a placeholder
        
        $audioContent = $this->generatePlaceholderAudio($text);
        
        return [
            'content' => $audioContent,
            'file' => 'system_generated',
            'duration' => strlen($text) / 10 // Rough estimate
        ];
    }
    
    private function useGoogleTTS($text, $options) {
        $apiKey = GOOGLE_TTS_KEY;
        $url = "https://texttospeech.googleapis.com/v1/text:synthesize";
        
        $requestData = [
            'input' => ['text' => $text],
            'voice' => [
                'languageCode' => $options['language'],
                'name' => $options['voice'] ?? $this->getGoogleVoice($options['language']),
                'ssmlGender' => 'MALE'
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate' => $options['speed'],
                'pitch' => $options['pitch'],
                'volumeGainDb' => ($options['volume'] - 1) * 16
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $apiKey
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            throw new Exception("Google TTS API error: HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['audioContent'])) {
            throw new Exception("Invalid response from Google TTS");
        }
        
        $audioContent = base64_decode($data['audioContent']);
        
        return [
            'content' => $audioContent,
            'file' => 'google_tts.mp3',
            'duration' => $this->estimateAudioDuration(strlen($audioContent))
        ];
    }
    
    private function useAzureTTS($text, $options) {
        $apiKey = AZURE_TTS_KEY;
        $region = 'eastus';
        $url = "https://$region.tts.speech.microsoft.com/cognitiveservices/v1";
        
        $ssml = $this->generateSSML($text, $options);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $ssml,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/ssml+xml',
                'Ocp-Apim-Subscription-Key: ' . $apiKey,
                'X-Microsoft-OutputFormat' => 'audio-16khz-128kbitrate-mono-mp3'
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            throw new Exception("Azure TTS API error: HTTP $httpCode");
        }
        
        return [
            'content' => $response,
            'file' => 'azure_tts.mp3',
            'duration' => $this->estimateAudioDuration(strlen($response))
        ];
    }
    
    private function generateSSML($text, $options) {
        $voiceName = $this->getAzureVoice($options['language']);
        
        $ssml = '<?xml version="1.0" encoding="UTF-8"?>
        <speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" 
               xmlns:mstts="https://www.w3.org/2001/mstts" xml:lang="' . $options['language'] . '">
            <voice name="' . $voiceName . '">
                <prosody rate="' . $options['speed'] . '" pitch="' . $options['pitch'] . '">
                    ' . htmlspecialchars($text) . '
                </prosody>
            </voice>
        </speak>';
        
        return $ssml;
    }
    
    private function generatePlaceholderAudio($text) {
        // Generate a simple beep sound as placeholder
        // In production, replace with actual TTS
        $duration = strlen($text) / 20; // seconds
        $sampleRate = 44100;
        $frequency = 440; // A4 note
        
        $samples = $sampleRate * $duration;
        $audioData = '';
        
        for ($i = 0; $i < $samples; $i++) {
            $sample = sin(2 * M_PI * $frequency * $i / $sampleRate);
            $audioData .= pack('s', $sample * 32767);
        }
        
        // Simple WAV header
        $header = $this->generateWavHeader(strlen($audioData), $sampleRate);
        
        return $header . $audioData;
    }
    
    private function generateWavHeader($dataSize, $sampleRate) {
        $byteRate = $sampleRate * 2; // 16-bit mono
        $blockAlign = 2;
        
        $header = 'RIFF';
        $header .= pack('V', 36 + $dataSize);
        $header .= 'WAVE';
        $header .= 'fmt ';
        $header .= pack('V', 16);
        $header .= pack('v', 1); // PCM
        $header .= pack('v', 1); // mono
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $byteRate);
        $header .= pack('v', $blockAlign);
        $header .= pack('v', 16); // bits per sample
        $header .= 'data';
        $header .= pack('V', $dataSize);
        
        return $header;
    }
    
    private function getGoogleVoice($language) {
        $voices = [
            'uz-UZ' => 'uz-UZ-Standard-A',
            'en-US' => 'en-US-Standard-B',
            'ru-RU' => 'ru-RU-Standard-B'
        ];
        
        return $voices[$language] ?? 'en-US-Standard-B';
    }
    
    private function getAzureVoice($language) {
        $voices = [
            'uz-UZ' => 'uz-UZ-MadinaNeural',
            'en-US' => 'en-US-GuyNeural',
            'ru-RU' => 'ru-RU-SvetlanaNeural'
        ];
        
        return $voices[$language] ?? 'en-US-GuyNeural';
    }
    
    private function generateAudioHash($text, $options) {
        $hashData = [
            'text' => substr($text, 0, 1000),
            'lang' => $options['language'],
            'speed' => $options['speed'],
            'pitch' => $options['pitch']
        ];
        
        return md5(serialize($hashData));
    }
    
    private function getAudioUrl($hash, $format) {
        return "audio.php?hash=$hash&format=$format";
    }
    
    private function saveToCache($filePath, $content) {
        file_put_contents($filePath, $content);
        
        // Clean old cache files (older than 7 days)
        $this->cleanOldCache();
    }
    
    private function cleanOldCache() {
        $files = glob($this->audioDir . '*.mp3');
        $now = time();
        $maxAge = 7 * 24 * 60 * 60; // 7 days
        
        foreach ($files as $file) {
            if ($now - filemtime($file) > $maxAge) {
                unlink($file);
            }
        }
    }
    
    private function estimateAudioDuration($audioSize) {
        // Rough estimate: 16kbps MP3 ≈ 2KB per second
        return $audioSize / 2000;
    }
    
    private function logAudioGeneration($text, $hash, $options) {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'text_length' => strlen($text),
            'language' => $options['language'],
            'speed' => $options['speed'],
            'hash' => $hash,
            'provider' => $options['voice_provider'] ?? 'system'
        ];
        
        $logFile = 'logs/tts_' . date('Y-m-d') . '.log';
        
        if (!file_exists('logs')) {
            mkdir('logs', 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
    }
    
    public function speakResponse($response, $sessionId = null) {
        if (!$this->enabled) {
            return null;
        }
        
        // Extract text to speak (limit length)
        $text = $response['response'] ?? $response;
        $text = substr($text, 0, 1000); // Limit for TTS
        
        // Determine language
        $language = $this->detectLanguage($text);
        
        // Generate speech
        $audio = $this->textToSpeech($text, [
            'language' => $language,
            'speed' => VOICE_SPEED,
            'pitch' => VOICE_PITCH
        ]);
        
        if ($audio) {
            // Log voice interaction
            $this->logVoiceInteraction($text, $language, $sessionId);
            
            return $audio;
        }
        
        return null;
    }
    
    private function detectLanguage($text) {
        // Simple language detection
        $uzbekPattern = '/[chsho\'g\'qxh]/i';
        $russianPattern = '/[щшчцж]/iu';
        
        $uzbekCount = preg_match_all($uzbekPattern, $text);
        $russianCount = preg_match_all($russianPattern, $text);
        
        if ($uzbekCount > $russianCount && $uzbekCount > 2) {
            return 'uz-UZ';
        } elseif ($russianCount > $uzbekCount && $russianCount > 2) {
            return 'ru-RU';
        }
        
        return 'en-US';
    }
    
    private function logVoiceInteraction($text, $language, $sessionId) {
        $data = [
            'session_id' => $sessionId ?? $_SESSION['jarvis_session_id'] ?? 'unknown',
            'text' => substr($text, 0, 200),
            'language' => $language,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $this->db->insert('voice_interactions', $data);
    }
    
    public function getVoiceStats($period = 'today') {
        $startDate = date('Y-m-d 00:00:00');
        
        if ($period === 'week') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        } elseif ($period === 'month') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
        
        $sql = "SELECT 
                    COUNT(*) as total_requests,
                    language,
                    DATE(timestamp) as request_date,
                    AVG(LENGTH(text)) as avg_text_length
                FROM voice_interactions 
                WHERE timestamp >= ?
                GROUP BY language, DATE(timestamp)
                ORDER BY request_date DESC";
        
        $stmt = $this->db->executeQuery($sql, [$startDate], 's');
        $result = $stmt->get_result();
        
        $stats = [
            'period' => $period,
            'total_requests' => 0,
            'languages' => [],
            'daily' => []
        ];
        
        while ($row = $result->fetch_assoc()) {
            $lang = $row['language'];
            $date = $row['request_date'];
            
            $stats['total_requests'] += $row['total_requests'];
            
            if (!isset($stats['languages'][$lang])) {
                $stats['languages'][$lang] = 0;
            }
            $stats['languages'][$lang] += $row['total_requests'];
            
            if (!isset($stats['daily'][$date])) {
                $stats['daily'][$date] = [
                    'total' => 0,
                    'languages' => []
                ];
            }
            $stats['daily'][$date]['total'] += $row['total_requests'];
            $stats['daily'][$date]['languages'][$lang] = $row['total_requests'];
        }
        
        return $stats;
    }
    
    public function getAvailableVoices() {
        return $this->voices;
    }
    
    public function setVoice($language, $voiceSettings) {
        if (isset($this->voices[$language])) {
            $this->voices[$language] = array_merge($this->voices[$language], $voiceSettings);
            return true;
        }
        return false;
    }
    
    public function isEnabled() {
        return $this->enabled;
    }
    
    public function enable() {
        $this->enabled = true;
        return $this->enabled;
    }
    
    public function disable() {
        $this->enabled = false;
        return $this->enabled;
    }
}
?>
