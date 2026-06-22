<?php
/**
 * Pagination Class - Secure Pagination Utility
 * Handles pagination logic with security considerations
 */

class Pagination {
    private $current_page;
    private $page_size;
    private $total_items;
    private $total_pages;
    private $offset;

    // Pagination Setup - Calculates current page, total pages, page size, and SQL offset.
    public function __construct($total_items, $page_size = null, $current_page = null) {
        require_once __DIR__ . '/../config/security.php';

        $this->page_size = $page_size ?? DEFAULT_PAGE_SIZE;
        $this->page_size = min($this->page_size, MAX_PAGE_SIZE);
        
        $this->current_page = $current_page ?? (isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $this->current_page = max(1, $this->current_page);
        
        $this->total_items = max(0, (int)$total_items);
        $this->total_pages = ceil($this->total_items / $this->page_size) ?: 1;
        
        // Prevent exceeding total pages
        if ($this->current_page > $this->total_pages) {
            $this->current_page = $this->total_pages;
        }
        
        $this->offset = ($this->current_page - 1) * $this->page_size;
    }

    /**
     * Get LIMIT clause for SQL
     */
    public function getLimitClause() {
        return "LIMIT {$this->offset}, {$this->page_size}";
    }

    /**
     * Get offset
     */
    public function getOffset() {
        return $this->offset;
    }

    /**
     * Get page size
     */
    public function getPageSize() {
        return $this->page_size;
    }

    /**
     * Get current page
     */
    public function getCurrentPage() {
        return $this->current_page;
    }

    /**
     * Get total pages
     */
    public function getTotalPages() {
        return $this->total_pages;
    }

    /**
     * Get total items
     */
    public function getTotalItems() {
        return $this->total_items;
    }

    /**
     * Check if has previous page
     */
    public function hasPrevious() {
        return $this->current_page > 1;
    }

    /**
     * Check if has next page
     */
    public function hasNext() {
        return $this->current_page < $this->total_pages;
    }

    /**
     * Get previous page number
     */
    public function getPreviousPage() {
        return max(1, $this->current_page - 1);
    }

    /**
     * Get next page number
     */
    public function getNextPage() {
        return min($this->total_pages, $this->current_page + 1);
    }

    /**
     * Get page range for display
     */
    public function getPageRange() {
        require_once __DIR__ . '/../config/security.php';
        
        $range = PAGINATION_RANGE;
        $start = max(1, $this->current_page - $range);
        $end = min($this->total_pages, $this->current_page + $range);
        
        return range($start, $end);
    }

    /**
     * Get pagination info array
     */
    public function toArray() {
        return [
            'current_page' => $this->current_page,
            'page_size' => $this->page_size,
            'total_items' => $this->total_items,
            'total_pages' => $this->total_pages,
            'offset' => $this->offset,
            'has_previous' => $this->hasPrevious(),
            'has_next' => $this->hasNext(),
            'previous_page' => $this->getPreviousPage(),
            'next_page' => $this->getNextPage(),
            'page_range' => $this->getPageRange()
        ];
    }

    /**
     * Render pagination HTML
     */
    public function render($base_url = '') {
        if ($this->total_pages <= 1) {
            return '';
        }

        $html = '<nav aria-label="Page navigation">';
        $html .= '<ul class="pagination">';

        // Previous button
        if ($this->hasPrevious()) {
            $html .= '<li class="page-item">';
            $html .= '<a class="page-link" href="' . $base_url . '?page=' . $this->getPreviousPage() . '">Previous</a>';
            $html .= '</li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
        }

        // Page numbers
        foreach ($this->getPageRange() as $page) {
            if ($page == $this->current_page) {
                $html .= '<li class="page-item active"><span class="page-link">' . $page . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?page=' . $page . '">' . $page . '</a></li>';
            }
        }

        // Next button
        if ($this->hasNext()) {
            $html .= '<li class="page-item">';
            $html .= '<a class="page-link" href="' . $base_url . '?page=' . $this->getNextPage() . '">Next</a>';
            $html .= '</li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
        }

        $html .= '</ul>';
        $html .= '</nav>';

        return $html;
    }
}

/**
 * Validator Class - Input Validation & Sanitization
 * Comprehensive validation for common data types
 */
class Validator {
    private $errors = [];

    /**
     * Validate email
     */
    public static function email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate URL
     */
    public static function url($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate IP address
     */
    public static function ip($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate password strength
     */
    public static function passwordStrength($password) {
        require_once __DIR__ . '/../config/security.php';

        $strength = 0;

        // Check length
        if (strlen($password) >= PASSWORD_MIN_LENGTH) {
            $strength += 25;
        }

        // Check for uppercase
        if (preg_match('/[A-Z]/', $password)) {
            $strength += 25;
        }

        // Check for lowercase
        if (preg_match('/[a-z]/', $password)) {
            $strength += 25;
        }

        // Check for numbers
        if (preg_match('/[0-9]/', $password)) {
            $strength += 12;
        }

        // Check for special characters
        if (preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
            $strength += 13;
        }

        return $strength;
    }

    /**
     * Validate password meets requirements
     */
    public static function passwordMeetsRequirements($password) {
        require_once __DIR__ . '/../config/security.php';

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            return false;
        }

        if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            return false;
        }

        if (PASSWORD_REQUIRE_NUMBERS && !preg_match('/[0-9]/', $password)) {
            return false;
        }

        if (PASSWORD_REQUIRE_SPECIAL_CHARS && !preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
            return false;
        }

        return true;
    }

    /**
     * Validate phone number
     */
    public static function phone($phone) {
        // Remove common formatting characters
        $phone = preg_replace('/[\s\-\(\)\.]/','', $phone);
        return preg_match('/^[0-9]{10,15}$/', $phone);
    }

    /**
     * Validate date format
     */
    public static function date($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Validate number (integer or float)
     */
    public static function number($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return false;
        }

        if ($min !== null && $value < $min) {
            return false;
        }

        if ($max !== null && $value > $max) {
            return false;
        }

        return true;
    }

    /**
     * Validate string length
     */
    public static function stringLength($string, $min, $max = null) {
        $length = strlen($string);
        
        if ($length < $min) {
            return false;
        }

        if ($max !== null && $length > $max) {
            return false;
        }

        return true;
    }

    /**
     * Validate required field
     */
    public static function required($value) {
        return !empty(trim((string)$value));
    }

    /**
     * Validate file upload
     */
    public static function file($file, $allowed_types = [], $max_size = null) {
        if (!isset($file['tmp_name'])) {
            return false;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        // Check MIME type
        if (!empty($allowed_types)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_types)) {
                return false;
            }
        }

        // Check file size
        if ($max_size !== null && $file['size'] > $max_size) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize string
     */
    public static function sanitizeString($string) {
        return htmlspecialchars(trim($string), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize HTML
     */
    public static function sanitizeHtml($html) {
        return strip_tags($html, '<p><br><strong><em><u><ol><ul><li><h1><h2><h3><h4><h5><h6>');
    }

    /**
     * Sanitize filename
     */
    public static function sanitizeFilename($filename) {
        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '', $filename);
        return preg_replace('/\.{2,}/', '.', $filename);
    }

    /**
     * Add error
     */
    public function addError($field, $message) {
        $this->errors[$field] = $message;
    }

    /**
     * Get all errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Check if has errors
     */
    public function hasErrors() {
        return !empty($this->errors);
    }

    /**
     * Get first error
     */
    public function getFirstError() {
        return reset($this->errors) ?: null;
    }
}
?>
