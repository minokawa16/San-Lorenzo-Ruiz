<?php
/**
 * Error Handler & Response Class
 * Centralized error handling and API response management
 */

class ErrorHandler {
    private $logger;
    private $environment = 'production';

    // Error Handler Setup - Loads logging support used by centralized error reporting.
    public function __construct() {
        require_once __DIR__ . '/../config/security.php';
        $this->logger = new Logger();
        $this->environment = DEBUG_MODE ? 'development' : 'production';
    }

    /**
     * Register error handler
     */
    public function register() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Handle PHP errors
     */
    public function handleError($errno, $errstr, $errfile, $errline) {
        $error_types = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Runtime Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated'
        ];

        $type = $error_types[$errno] ?? 'Unknown Error';

        $error_message = sprintf(
            "%s: %s in %s on line %d",
            $type,
            $errstr,
            $errfile,
            $errline
        );

        $this->logger->error($error_message);

        // Show user-friendly error page
        if ($errno === E_ERROR || $errno === E_PARSE) {
            $this->showErrorPage(500, 'An error occurred');
        }

        return true; // Prevent default PHP error handler
    }

    /**
     * Handle exceptions
     */
    public function handleException(Throwable $e) {
        $error_message = sprintf(
            "Exception: %s in %s on line %d",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        $this->logger->error($error_message, [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString()
        ]);

        $this->showErrorPage(500, 'An unexpected error occurred');
    }

    /**
     * Handle fatal errors on shutdown
     */
    public function handleShutdown() {
        $error = error_get_last();
        if ($error !== null) {
            $this->handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
        }
    }

    /**
     * Show error page
     */
    private function showErrorPage($code, $message) {
        http_response_code($code);
        
        if ($this->environment === 'development') {
            echo "Error $code: $message";
        } else {
            echo "An error occurred. Please try again later.";
        }
        exit;
    }
}

/**
 * Response Class - Unified API Response Format
 * Standardizes JSON responses across the application
 */
class Response {
    private $data = [];
    private $status = 'success';
    private $code = 200;
    private $message = '';
    private $errors = [];

    /**
     * Set success response
     */
    public function success($data = null, $message = 'Request successful') {
        $this->status = 'success';
        $this->code = 200;
        $this->data = $data;
        $this->message = $message;
        return $this;
    }

    /**
     * Set created response
     */
    public function created($data = null, $message = 'Resource created successfully') {
        $this->status = 'success';
        $this->code = 201;
        $this->data = $data;
        $this->message = $message;
        return $this;
    }

    /**
     * Set bad request response
     */
    public function badRequest($message = 'Bad request', $errors = []) {
        $this->status = 'error';
        $this->code = 400;
        $this->message = $message;
        $this->errors = $errors;
        return $this;
    }

    /**
     * Set unauthorized response
     */
    public function unauthorized($message = 'Unauthorized') {
        $this->status = 'error';
        $this->code = 401;
        $this->message = $message;
        return $this;
    }

    /**
     * Set forbidden response
     */
    public function forbidden($message = 'Access denied') {
        $this->status = 'error';
        $this->code = 403;
        $this->message = $message;
        return $this;
    }

    /**
     * Set not found response
     */
    public function notFound($message = 'Resource not found') {
        $this->status = 'error';
        $this->code = 404;
        $this->message = $message;
        return $this;
    }

    /**
     * Set conflict response
     */
    public function conflict($message = 'Resource conflict') {
        $this->status = 'error';
        $this->code = 409;
        $this->message = $message;
        return $this;
    }

    /**
     * Set unprocessable entity response
     */
    public function unprocessable($message = 'Unprocessable entity', $errors = []) {
        $this->status = 'error';
        $this->code = 422;
        $this->message = $message;
        $this->errors = $errors;
        return $this;
    }

    /**
     * Set server error response
     */
    public function serverError($message = 'Internal server error') {
        $this->status = 'error';
        $this->code = 500;
        $this->message = $message;
        return $this;
    }

    /**
     * Get response as array
     */
    public function toArray() {
        $response = [
            'status' => $this->status,
            'code' => $this->code,
            'message' => $this->message
        ];

        if ($this->data !== null) {
            $response['data'] = $this->data;
        }

        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;
        }

        return $response;
    }

    /**
     * Get response as JSON
     */
    public function toJson() {
        header('Content-Type: application/json');
        http_response_code($this->code);
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Send response and exit
     */
    public function send() {
        echo $this->toJson();
        exit;
    }

    /**
     * Send redirect
     */
    public function redirect($url, $code = 302) {
        http_response_code($code);
        header("Location: $url");
        exit;
    }

    /**
     * Send file download
     */
    public function download($file_path, $filename = null) {
        if (!file_exists($file_path)) {
            $this->notFound('File not found')->send();
        }

        $filename = $filename ?? basename($file_path);
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        
        readfile($file_path);
        exit;
    }
}

/**
 * ValidationResponse - Unified validation response format
 */
class ValidationResponse extends Response {
    // Validation Response - Returns a standardized API error for invalid submitted data.
    public function validation($errors) {
        return $this->unprocessable('Validation failed', $errors);
    }
}
?>
