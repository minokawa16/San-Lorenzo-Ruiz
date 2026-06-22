<?php
/**
 * BaseDB Class - Secure Database Abstraction Layer
 * Handles prepared statements, error handling, and query caching
 */

class BaseDB {
    protected $conn;
    protected $last_query;
    protected $last_error;
    protected $logger;
    protected $cache;
    
    // Database Wrapper Setup - Stores the active MySQL connection for prepared query helpers.
    public function __construct($connection) {
        $this->conn = $connection;
        $this->logger = new Logger();
        $this->cache = new CacheManager();
    }

    /**
     * Execute a prepared SELECT query
     * @param string $sql SQL query with ? placeholders
     * @param array $types Parameter types (e.g., 'sss', 'iii')
     * @param array $params Query parameters
     * @param int $cache_ttl Cache time-to-live in seconds (0 = no cache)
     * @return array Array of results or empty array on failure
     */
    public function select($sql, $types = '', $params = [], $cache_ttl = 0) {
        try {
            // Check cache first
            if ($cache_ttl > 0) {
                $cache_key = $this->generateCacheKey($sql, $params);
                $cached = $this->cache->get($cache_key);
                if ($cached !== null) {
                    return $cached;
                }
            }

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }

            // Bind parameters if provided
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            // Execute query
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            // Get results
            $result = $stmt->get_result();
            $rows = $result->fetch_all(MYSQLI_ASSOC);

            // Cache results
            if ($cache_ttl > 0) {
                $this->cache->set($cache_key, $rows, $cache_ttl);
            }

            $stmt->close();
            return $rows;

        } catch (Exception $e) {
            $this->logError("SELECT query failed", $e);
            return [];
        }
    }

    /**
     * Execute a prepared SELECT query returning single row
     * @param string $sql SQL query with ? placeholders
     * @param array $types Parameter types
     * @param array $params Query parameters
     * @return array|null Single row or null
     */
    public function selectOne($sql, $types = '', $params = []) {
        $results = $this->select($sql, $types, $params);
        return count($results) > 0 ? $results[0] : null;
    }

    /**
     * Execute a prepared SELECT query returning count
     * @param string $sql SQL query with ? placeholders
     * @param array $types Parameter types
     * @param array $params Query parameters
     * @return int Count of results
     */
    public function count($sql, $types = '', $params = []) {
        $result = $this->selectOne($sql, $types, $params);
        return isset($result['count']) ? (int)$result['count'] : 0;
    }

    /**
     * Execute a prepared INSERT query
     * @param string $sql SQL query with ? placeholders
     * @param array $types Parameter types
     * @param array $params Query parameters
     * @return int|false Insert ID on success, false on failure
     */
    public function insert($sql, $types = '', $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }

            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            $insert_id = $this->conn->insert_id;
            $stmt->close();

            // Log audit
            $this->logAudit('INSERT', $sql, $params);

            return $insert_id;

        } catch (Exception $e) {
            $this->logError("INSERT query failed", $e);
            return false;
        }
    }

    /**
     * Execute a prepared UPDATE query
     * @param string $sql SQL query with ? placeholders
     * @param array $types Parameter types
     * @param array $params Query parameters
     * @return int|false Affected rows on success, false on failure
     */
    public function update($sql, $types = '', $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }

            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            $affected = $stmt->affected_rows;
            $stmt->close();

            // Invalidate related cache
            $this->cache->invalidate($sql);

            // Log audit
            $this->logAudit('UPDATE', $sql, $params);

            return $affected;

        } catch (Exception $e) {
            $this->logError("UPDATE query failed", $e);
            return false;
        }
    }

    /**
     * Execute a prepared DELETE query (soft delete with timestamp)
     * @param string $table Table name
     * @param string $where WHERE clause with ? placeholders
     * @param array $types Parameter types
     * @param array $params Query parameters
     * @param bool $soft_delete Use soft delete (default true)
     * @return int|false Affected rows on success, false on failure
     */
    public function delete($table, $where, $types = '', $params = [], $soft_delete = true) {
        try {
            if ($soft_delete) {
                // Soft delete - mark as deleted
                $sql = "UPDATE $table SET deleted_at = NOW() WHERE $where";
            } else {
                // Hard delete - permanent
                $sql = "DELETE FROM $table WHERE $where";
            }

            return $this->update($sql, $types, $params);

        } catch (Exception $e) {
            $this->logError("DELETE query failed", $e);
            return false;
        }
    }

    /**
     * Execute raw query (use only when absolutely necessary)
     * @param string $sql Raw SQL
     * @return mixed Query result
     */
    public function query($sql) {
        try {
            $result = $this->conn->query($sql);
            if (!$result) {
                throw new Exception("Query failed: " . $this->conn->error);
            }
            return $result;
        } catch (Exception $e) {
            $this->logError("RAW query failed", $e);
            return null;
        }
    }

    /**
     * Start database transaction
     */
    public function beginTransaction() {
        $this->conn->begin_transaction();
    }

    /**
     * Commit database transaction
     */
    public function commit() {
        $this->conn->commit();
    }

    /**
     * Rollback database transaction
     */
    public function rollback() {
        $this->conn->rollback();
    }

    /**
     * Escape string for safe SQL usage (fallback method)
     * @param string $str String to escape
     * @return string Escaped string
     */
    public function escape($str) {
        return $this->conn->real_escape_string($str);
    }

    /**
     * Get last error
     * @return string Last error message
     */
    public function getLastError() {
        return $this->last_error;
    }

    /**
     * Generate cache key from SQL and params
     * @param string $sql SQL query
     * @param array $params Parameters
     * @return string Cache key
     */
    private function generateCacheKey($sql, $params) {
        return md5($sql . json_encode($params));
    }

    /**
     * Log error
     * @param string $message Error message
     * @param Exception $e Exception object
     */
    private function logError($message, Exception $e) {
        $this->last_error = $e->getMessage();
        $this->logger->error($message . ": " . $e->getMessage());
    }

    /**
     * Log audit trail
     * @param string $action Action type
     * @param string $sql SQL query
     * @param array $params Parameters
     */
    private function logAudit($action, $sql, $params) {
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $audit_sql = "INSERT INTO audit_logs (user_id, action_type, ip_address, user_agent) VALUES (?, ?, ?, ?)";
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $stmt = $this->conn->prepare($audit_sql);
            if ($stmt) {
                $stmt->bind_param('isss', $user_id, $action, $ip, $user_agent);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    /**
     * Get database connection
     * @return mysqli Connection object
     */
    public function getConnection() {
        return $this->conn;
    }

    /**
     * Close database connection
     */
    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }

    /**
     * Destructor
     */
    public function __destruct() {
        $this->close();
    }
}
?>
