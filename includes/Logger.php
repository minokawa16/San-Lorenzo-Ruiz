<?php
/**
 * Logger Class - Application Logging System
 * Handles error logging, audit logs, and activity tracking
 */

class Logger {
    private $log_dir;
    private $log_level;
    private $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    // Logger Setup - Prepares log storage, severity level, and retention cleanup.
    public function __construct() {
        require_once __DIR__ . '/../config/security.php';
        
        $this->log_dir = LOG_DIR;
        $this->log_level = $this->levels[LOG_LEVEL] ?? 1;
        
        // Create log directory if it doesn't exist
        if (!is_dir($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }
    }

    /**
     * Debug level log
     */
    public function debug($message, $context = []) {
        $this->log('debug', $message, $context);
    }

    /**
     * Info level log
     */
    public function info($message, $context = []) {
        $this->log('info', $message, $context);
    }

    /**
     * Warning level log
     */
    public function warning($message, $context = []) {
        $this->log('warning', $message, $context);
    }

    /**
     * Error level log
     */
    public function error($message, $context = []) {
        $this->log('error', $message, $context);
    }

    /**
     * Main logging method
     */
    private function log($level, $message, $context = []) {
        if ($this->levels[$level] < $this->log_level) {
            return;
        }

        $log_file = $this->log_dir . 'app_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $user_id = $_SESSION['user_id'] ?? 'anonymous';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $log_entry = sprintf(
            "[%s] [%s] [User: %s] [IP: %s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $user_id,
            $ip,
            $message,
            !empty($context) ? json_encode($context) : ''
        );

        file_put_contents($log_file, $log_entry, FILE_APPEND);

        // Rotate logs if too large
        if (filesize($log_file) > LOG_MAX_SIZE) {
            $this->rotateLog($log_file);
        }
    }

    /**
     * Rotate log file when it gets too large
     */
    private function rotateLog($log_file) {
        $archived = $log_file . '.' . date('Y-m-d-H-i-s');
        rename($log_file, $archived);
        
        // Compress if available
        if (function_exists('gzcompress')) {
            system("gzip $archived");
        }

        // Clean old logs
        $this->cleanOldLogs();
    }

    /**
     * Clean logs older than retention period
     */
    private function cleanOldLogs() {
        $retention_seconds = LOG_RETENTION_DAYS * 86400;
        $now = time();

        foreach (glob($this->log_dir . 'app_*.log*') as $file) {
            if ($now - filemtime($file) > $retention_seconds) {
                unlink($file);
            }
        }
    }

    /**
     * Get log file contents
     */
    public function getLogs($days = 7, $level = null) {
        $logs = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $file = $this->log_dir . 'app_' . $date . '.log';
            
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if ($level) {
                    $pattern = '/\[' . strtoupper($level) . '\]/';
                    preg_match_all($pattern, $content, $matches);
                    if (!empty($matches[0])) {
                        $logs[$date] = $content;
                    }
                } else {
                    $logs[$date] = $content;
                }
            }
        }
        
        return $logs;
    }
}

/**
 * CacheManager Class - Application Caching System
 * Supports file-based, Redis, and Memcached drivers
 */
class CacheManager {
    private $driver;
    private $cache_dir;
    private $redis;
    private $memcached;

    // Cache Setup - Selects the configured cache driver and prepares fallback file storage.
    public function __construct() {
        require_once __DIR__ . '/../config/security.php';
        
        $this->cache_dir = CACHE_DIR;
        $this->driver = CACHE_DRIVER;

        // Create cache directory if needed
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }

        // Initialize driver
        $this->initializeDriver();
    }

    /**
     * Initialize cache driver
     */
    private function initializeDriver() {
        switch ($this->driver) {
            case 'redis':
                if (extension_loaded('redis')) {
                    $this->redis = new Redis();
                    $this->redis->connect(CACHE_REDIS_HOST, CACHE_REDIS_PORT);
                } else {
                    $this->driver = 'file'; // Fallback
                }
                break;

            case 'memcached':
                if (extension_loaded('memcached')) {
                    $this->memcached = new Memcached();
                    $this->memcached->addServer('localhost', 11211);
                } else {
                    $this->driver = 'file'; // Fallback
                }
                break;

            case 'file':
            default:
                // File cache - no initialization needed
                break;
        }
    }

    /**
     * Get value from cache
     */
    public function get($key) {
        switch ($this->driver) {
            case 'redis':
                return unserialize($this->redis->get($key));

            case 'memcached':
                return $this->memcached->get($key);

            case 'file':
            default:
                return $this->getFileCache($key);
        }
    }

    /**
     * Set value in cache
     */
    public function set($key, $value, $ttl = 0) {
        if ($ttl === 0) {
            $ttl = CACHE_TTL_DEFAULT;
        }

        switch ($this->driver) {
            case 'redis':
                return $this->redis->setex($key, $ttl, serialize($value));

            case 'memcached':
                return $this->memcached->set($key, $value, $ttl);

            case 'file':
            default:
                return $this->setFileCache($key, $value, $ttl);
        }
    }

    /**
     * Delete cache entry
     */
    public function delete($key) {
        switch ($this->driver) {
            case 'redis':
                return $this->redis->del($key);

            case 'memcached':
                return $this->memcached->delete($key);

            case 'file':
            default:
                $file = $this->getCacheFilePath($key);
                return file_exists($file) ? unlink($file) : true;
        }
    }

    /**
     * Clear all cache
     */
    public function flush() {
        switch ($this->driver) {
            case 'redis':
                return $this->redis->flushDb();

            case 'memcached':
                return $this->memcached->flush();

            case 'file':
            default:
                $files = glob($this->cache_dir . '*.cache');
                foreach ($files as $file) {
                    unlink($file);
                }
                return true;
        }
    }

    /**
     * Invalidate cache by pattern
     */
    public function invalidate($pattern) {
        switch ($this->driver) {
            case 'redis':
                $keys = $this->redis->keys($pattern . '*');
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
                break;

            case 'memcached':
                // Memcached doesn't support pattern delete
                // Consider manual invalidation
                break;

            case 'file':
            default:
                $pattern_hash = md5($pattern);
                $files = glob($this->cache_dir . '*' . $pattern_hash . '*.cache');
                foreach ($files as $file) {
                    unlink($file);
                }
                break;
        }
    }

    /**
     * File cache get
     */
    private function getFileCache($key) {
        $file = $this->getCacheFilePath($key);
        
        if (!file_exists($file)) {
            return null;
        }

        // Check expiration
        $data = unserialize(file_get_contents($file));
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }

        return $data['value'];
    }

    /**
     * File cache set
     */
    private function setFileCache($key, $value, $ttl) {
        $file = $this->getCacheFilePath($key);
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        return file_put_contents($file, serialize($data));
    }

    /**
     * Get cache file path
     */
    private function getCacheFilePath($key) {
        return $this->cache_dir . md5($key) . '.cache';
    }

    /**
     * Get cache stats
     */
    public function getStats() {
        switch ($this->driver) {
            case 'redis':
                return $this->redis->info();

            case 'memcached':
                return $this->memcached->getStats();

            case 'file':
            default:
                $files = glob($this->cache_dir . '*.cache');
                return [
                    'cached_items' => count($files),
                    'cache_dir_size' => array_sum(array_map('filesize', $files))
                ];
        }
    }
}
?>
