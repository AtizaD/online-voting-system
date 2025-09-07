<?php
/**
 * Common functions for the Online Voting System
 * Note: Core functions like hasPermission, isLoggedIn etc. are defined in config.php
 */

/**
 * Sanitize input data
 * @param mixed $data Input data to sanitize
 * @return mixed Sanitized data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Format bytes to human readable format
 * @param int $bytes Number of bytes
 * @param int $precision Decimal precision
 * @return string Formatted bytes
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Get POST data with optional default value
 * @param string $key POST key
 * @param mixed $default Default value if key not found
 * @return mixed POST value or default
 */
function getPostData($key, $default = null) {
    return $_POST[$key] ?? $default;
}

/**
 * Get GET data with optional default value
 * @param string $key GET key
 * @param mixed $default Default value if key not found  
 * @return mixed GET value or default
 */
function getGetData($key, $default = null) {
    return $_GET[$key] ?? $default;
}

/**
 * Check if string starts with substring
 * @param string $haystack String to search in
 * @param string $needle Substring to find
 * @return bool True if starts with, false otherwise
 */
function startsWith($haystack, $needle) {
    return strpos($haystack, $needle) === 0;
}

/**
 * Check if string ends with substring
 * @param string $haystack String to search in
 * @param string $needle Substring to find
 * @return bool True if ends with, false otherwise
 */
function endsWith($haystack, $needle) {
    return substr($haystack, -strlen($needle)) === $needle;
}

/**
 * Convert string to slug format
 * @param string $text Text to convert
 * @return string Slug format
 */
function createSlug($text) {
    // Replace non-alphanumeric characters with hyphens
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $text);
    // Remove leading/trailing hyphens and convert to lowercase
    return strtolower(trim($slug, '-'));
}

/**
 * Truncate text to specified length
 * @param string $text Text to truncate
 * @param int $length Maximum length
 * @param string $suffix Suffix to append if truncated
 * @return string Truncated text
 */
function truncateText($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length) . $suffix;
}

/**
 * Check if file has allowed extension
 * @param string $filename Filename to check
 * @param array $allowed_extensions Array of allowed extensions
 * @return bool True if allowed, false otherwise
 */
function hasAllowedExtension($filename, $allowed_extensions) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowed_extensions);
}

/**
 * Generate breadcrumb navigation
 * @param array $items Breadcrumb items [['title' => 'Home', 'url' => '/']]
 * @return string HTML breadcrumb
 */
function generateBreadcrumb($items) {
    $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    
    $total = count($items);
    foreach ($items as $index => $item) {
        $isLast = ($index === $total - 1);
        
        if ($isLast) {
            $html .= '<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($item['title']) . '</li>';
        } else {
            $html .= '<li class="breadcrumb-item"><a href="' . htmlspecialchars($item['url']) . '">' . htmlspecialchars($item['title']) . '</a></li>';
        }
    }
    
    $html .= '</ol></nav>';
    return $html;
}

/**
 * Calculate time difference in human readable format
 * @param string $datetime DateTime string
 * @return string Time ago format
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->d > 0) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

/**
 * Generate pagination HTML
 * @param int $current_page Current page number
 * @param int $total_pages Total number of pages
 * @param string $base_url Base URL for pagination links
 * @return string Pagination HTML
 */
function generatePagination($current_page, $total_pages, $base_url) {
    if ($total_pages <= 1) {
        return '';
    }
    
    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
    
    // Previous button
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?page=' . $prev_page . '">Previous</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    // Page numbers
    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?page=1">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $current_page) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?page=' . $i . '">' . $i . '</a></li>';
        }
    }
    
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?page=' . $total_pages . '">' . $total_pages . '</a></li>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?page=' . $next_page . '">Next</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Check if current request is POST
 * @return bool True if POST request, false otherwise
 */
function isPostRequest() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Check if current request is GET  
 * @return bool True if GET request, false otherwise
 */
function isGetRequest() {
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

/**
 * Redirect helper (wrapper for config.php redirectTo)
 * @param string $url URL to redirect to
 * @param int $code HTTP response code
 */
function redirect($url, $code = 302) {
    if (function_exists('redirectTo')) {
        redirectTo($url);
    } else {
        header("Location: $url", true, $code);
        exit();
    }
}
?>