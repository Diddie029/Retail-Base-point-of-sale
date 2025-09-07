<?php
/**
 * Security Manager Class
 * Provides comprehensive security functions for the POS system
 */

class SecurityManager {
    private $conn;
    private $session;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->session = &$_SESSION;
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        if (!isset($this->session['csrf_token'])) {
            $this->session['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $this->session['csrf_token'];
    }
    
    /**
     * Validate CSRF token
     */
    public function validateCSRFToken($token) {
        if (!isset($this->session['csrf_token'])) {
            return false;
        }
        return hash_equals($this->session['csrf_token'], $token);
    }
    
    /**
     * Sanitize input data with comprehensive validation
     */
    public function sanitizeInput($data, $type = 'string', $maxLength = null) {
        if (is_array($data)) {
            return array_map(function($item) use ($type, $maxLength) {
                return $this->sanitizeInput($item, $type, $maxLength);
            }, $data);
        }
        
        // Convert to string and trim
        $data = trim((string)$data);
        
        // Apply length limit
        if ($maxLength && strlen($data) > $maxLength) {
            $data = substr($data, 0, $maxLength);
        }
        
        switch ($type) {
            case 'email':
                $data = filter_var($data, FILTER_SANITIZE_EMAIL);
                break;
            case 'url':
                $data = filter_var($data, FILTER_SANITIZE_URL);
                break;
            case 'int':
                $data = filter_var($data, FILTER_SANITIZE_NUMBER_INT);
                break;
            case 'float':
                $data = filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                break;
            case 'username':
                // Only allow alphanumeric, underscore, and hyphen
                $data = preg_replace('/[^a-zA-Z0-9_-]/', '', $data);
                break;
            case 'phone':
                // Only allow digits, spaces, hyphens, parentheses, and plus
                $data = preg_replace('/[^0-9\s\-\(\)\+]/', '', $data);
                break;
            case 'alphanumeric':
                $data = preg_replace('/[^a-zA-Z0-9]/', '', $data);
                break;
            case 'text':
                // Allow basic text with some special characters
                $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            case 'html':
                // Allow HTML but sanitize dangerous tags
                $data = $this->sanitizeHTML($data);
                break;
            default:
                // Default string sanitization
                $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        return $data;
    }
    
    /**
     * Sanitize HTML content
     */
    private function sanitizeHTML($html) {
        // List of allowed HTML tags
        $allowedTags = '<p><br><strong><em><u><b><i><ul><ol><li><h1><h2><h3><h4><h5><h6>';
        
        // Remove dangerous attributes
        $html = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
        $html = preg_replace('/javascript\s*:/i', '', $html);
        $html = preg_replace('/vbscript\s*:/i', '', $html);
        $html = preg_replace('/data\s*:/i', '', $html);
        
        // Strip all tags except allowed ones
        $html = strip_tags($html, $allowedTags);
        
        return $html;
    }
    
    /**
     * Validate input data
     */
    public function validateInput($data, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? '';
            $fieldErrors = [];
            
            // Required validation
            if (isset($rule['required']) && $rule['required'] && empty($value)) {
                $fieldErrors[] = ucfirst($field) . ' is required';
            }
            
            // Skip other validations if field is empty and not required
            if (empty($value) && !isset($rule['required'])) {
                continue;
            }
            
            // Length validation
            if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
                $fieldErrors[] = ucfirst($field) . ' must be at least ' . $rule['min_length'] . ' characters';
            }
            
            if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                $fieldErrors[] = ucfirst($field) . ' must not exceed ' . $rule['max_length'] . ' characters';
            }
            
            // Pattern validation
            if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                $fieldErrors[] = ucfirst($field) . ' format is invalid';
            }
            
            // Email validation
            if (isset($rule['type']) && $rule['type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $fieldErrors[] = ucfirst($field) . ' must be a valid email address';
            }
            
            // Numeric validation
            if (isset($rule['type']) && $rule['type'] === 'numeric' && !is_numeric($value)) {
                $fieldErrors[] = ucfirst($field) . ' must be a valid number';
            }
            
            // Integer validation
            if (isset($rule['type']) && $rule['type'] === 'integer' && !filter_var($value, FILTER_VALIDATE_INT)) {
                $fieldErrors[] = ucfirst($field) . ' must be a valid integer';
            }
            
            // Float validation
            if (isset($rule['type']) && $rule['type'] === 'float' && !filter_var($value, FILTER_VALIDATE_FLOAT)) {
                $fieldErrors[] = ucfirst($field) . ' must be a valid number';
            }
            
            // Date validation
            if (isset($rule['type']) && $rule['type'] === 'date') {
                $date = DateTime::createFromFormat('Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    $fieldErrors[] = ucfirst($field) . ' must be a valid date (YYYY-MM-DD)';
                }
            }
            
            // Custom validation
            if (isset($rule['custom']) && is_callable($rule['custom'])) {
                $customError = $rule['custom']($value, $data);
                if ($customError) {
                    $fieldErrors[] = $customError;
                }
            }
            
            if (!empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
            }
        }
        
        return $errors;
    }
    
    /**
     * Check rate limiting
     */
    public function checkRateLimit($action, $identifier = null, $maxAttempts = 10, $timeWindow = 300) {
        if (!$identifier) {
            $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
        
        $key = "rate_limit_{$action}_{$identifier}";
        $now = time();
        
        // Get current attempts
        $attempts = $this->session[$key] ?? [];
        
        // Remove old attempts outside time window
        $attempts = array_filter($attempts, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        // Check if limit exceeded
        if (count($attempts) >= $maxAttempts) {
            return false;
        }
        
        // Add current attempt
        $attempts[] = $now;
        $this->session[$key] = $attempts;
        
        return true;
    }
    
    /**
     * Log security event
     */
    public function logSecurityEvent($event, $details = [], $severity = 'medium') {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO security_logs (event_type, details, severity, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $event,
                json_encode($details),
                $severity,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (PDOException $e) {
            // Log to error log if database logging fails
            error_log("Security logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * Check for suspicious activity
     */
    public function checkSuspiciousActivity($data) {
        $suspicious = false;
        $reasons = [];
        
        // Check for SQL injection patterns
        $sqlPatterns = [
            '/(\bunion\b.*\bselect\b)/i',
            '/(\bselect\b.*\bfrom\b)/i',
            '/(\binsert\b.*\binto\b)/i',
            '/(\bupdate\b.*\bset\b)/i',
            '/(\bdelete\b.*\bfrom\b)/i',
            '/(\bdrop\b.*\btable\b)/i',
            '/(\bexec\b|\bexecute\b)/i',
            '/(\bscript\b.*\btype\b)/i',
            '/(\bjavascript\b)/i',
            '/(\bvbscript\b)/i'
        ];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                foreach ($sqlPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $suspicious = true;
                        $reasons[] = "SQL injection pattern detected in {$key}";
                    }
                }
                
                // Check for XSS patterns
                if (preg_match('/<script[^>]*>.*?<\/script>/i', $value) ||
                    preg_match('/javascript:/i', $value) ||
                    preg_match('/on\w+\s*=/i', $value)) {
                    $suspicious = true;
                    $reasons[] = "XSS pattern detected in {$key}";
                }
                
                // Check for excessive length
                if (strlen($value) > 10000) {
                    $suspicious = true;
                    $reasons[] = "Excessive input length in {$key}";
                }
            }
        }
        
        if ($suspicious) {
            $this->logSecurityEvent('suspicious_activity', [
                'reasons' => $reasons,
                'data' => $data
            ], 'high');
        }
        
        return $suspicious;
    }
    
    /**
     * Generate secure random password
     */
    public function generateSecurePassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
    
    /**
     * Validate password strength
     */
    public function validatePasswordStrength($password) {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }
        
        return $errors;
    }
    
    /**
     * Escape output for display
     */
    public function escapeOutput($data) {
        if (is_array($data)) {
            return array_map([$this, 'escapeOutput'], $data);
        }
        
        return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Get user input validation rules for user management
     */
    public function getUserValidationRules() {
        return [
            'username' => [
                'required' => true,
                'min_length' => 3,
                'max_length' => 50,
                'pattern' => '/^[a-zA-Z0-9_]+$/',
                'type' => 'username'
            ],
            'email' => [
                'required' => true,
                'max_length' => 255,
                'type' => 'email'
            ],
            'password' => [
                'required' => true,
                'min_length' => 6,
                'max_length' => 255,
                'custom' => function($value) {
                    return $this->validatePasswordStrength($value);
                }
            ],
            'first_name' => [
                'required' => true,
                'min_length' => 1,
                'max_length' => 100,
                'pattern' => '/^[a-zA-Z\s\'-]+$/'
            ],
            'last_name' => [
                'min_length' => 1,
                'max_length' => 100,
                'pattern' => '/^[a-zA-Z\s\'-]*$/'
            ],
            'phone' => [
                'max_length' => 20,
                'pattern' => '/^[\d\s\-\(\)\+]+$/',
                'type' => 'phone'
            ],
            'address' => [
                'max_length' => 500
            ],
            'department' => [
                'max_length' => 100,
                'pattern' => '/^[a-zA-Z0-9\s\-\&]+$/'
            ],
            'employee_id' => [
                'max_length' => 50,
                'pattern' => '/^[a-zA-Z0-9\-\_]+$/'
            ],
            'user_id' => [
                'max_length' => 20,
                'pattern' => '/^[0-9]+$/'
            ],
            'role_id' => [
                'required' => true,
                'type' => 'integer'
            ],
            'status' => [
                'required' => true,
                'pattern' => '/^(active|inactive|suspended)$/'
            ],
            'date_of_birth' => [
                'type' => 'date'
            ],
            'hire_date' => [
                'type' => 'date'
            ],
            'manager_id' => [
                'type' => 'integer'
            ]
        ];
    }
    
    /**
     * Sanitize user input data
     */
    public function sanitizeUserInput($data) {
        $sanitized = [];
        
        $sanitized['username'] = $this->sanitizeInput($data['username'] ?? '', 'username', 50);
        $sanitized['email'] = $this->sanitizeInput($data['email'] ?? '', 'email', 255);
        $sanitized['password'] = $data['password'] ?? ''; // Don't sanitize password
        $sanitized['first_name'] = $this->sanitizeInput($data['first_name'] ?? '', 'text', 100);
        $sanitized['last_name'] = $this->sanitizeInput($data['last_name'] ?? '', 'text', 100);
        $sanitized['phone'] = $this->sanitizeInput($data['phone'] ?? '', 'phone', 20);
        $sanitized['address'] = $this->sanitizeInput($data['address'] ?? '', 'text', 500);
        $sanitized['department'] = $this->sanitizeInput($data['department'] ?? '', 'text', 100);
        $sanitized['employee_id'] = $this->sanitizeInput($data['employee_id'] ?? '', 'alphanumeric', 50);
        $sanitized['user_id'] = $this->sanitizeInput($data['user_id'] ?? '', 'alphanumeric', 20);
        $sanitized['role_id'] = (int)($data['role_id'] ?? 0);
        $sanitized['status'] = $this->sanitizeInput($data['status'] ?? 'active', 'text', 20);
        $sanitized['date_of_birth'] = $this->sanitizeInput($data['date_of_birth'] ?? '', 'text', 10);
        $sanitized['hire_date'] = $this->sanitizeInput($data['hire_date'] ?? '', 'text', 10);
        $sanitized['manager_id'] = !empty($data['manager_id']) ? (int)$data['manager_id'] : null;
        
        return $sanitized;
    }
}
?>
