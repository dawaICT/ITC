<?php
/**
 * Password Security Testing Script for WUC Portal
 * 
 * This script tests password security by attempting to use weak passwords
 * and analyzing password policies and hashing methods.
 * 
 * WARNING: Use only on systems you own or have explicit permission to test.
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line.\n");
}

echo "=== Password Security Testing for ITC Portal ===\n\n";

// Test configuration
$config = [
    'staff_login' => 'http://localhost/wucportal/staffLogin.php',
    'student_login' => 'http://localhost/wucportal/studentLogin.php',
    'timeout' => 5
];

// Common weak passwords to test
$weak_passwords = [
    // Top 10 most common passwords
    '123456',
    'password',
    '123456789',
    '12345678',
    '12345',
    'qwerty',
    'abc123',
    'password123',
    'admin',
    'admin123',
    
    // Common variations
    '1234567',
    '1234567890',
    'qwerty123',
    'password1',
    'admin1',
    'root',
    'test',
    'guest',
    'user',
    'demo',
    
    // Simple patterns
    '111111',
    '000000',
    '123123',
    'abc123',
    'qwe123',
    'asd123',
    'zxc123',
    'qaz123',
    'wsx123',
    'edc123',
    
    // Keyboard patterns
    'qwertyuiop',
    'asdfghjkl',
    'zxcvbnm',
    'qazwsxedc',
    '1qaz2wsx',
    'q1w2e3r4',
    '1q2w3e4r',
    'q1w2e3r4t5',
    '1q2w3e4r5t',
    'q1w2e3r4t5y6'
];

// Test usernames to try
$test_usernames = [
    'admin',
    'administrator',
    'root',
    'test',
    'demo',
    'user',
    'guest',
    'staff',
    'student',
    'teacher'
];

/**
 * Test password strength against login form
 */
function test_password_strength($url, $username, $password, $form_type) {
    echo "Testing: $username / $password ";
    
    // Prepare form data based on form type
    if ($form_type === 'staff') {
        $form_data = [
            'user_id' => $username,
            'password' => $password,
            'login' => 'submit'
        ];
    } else {
        $form_data = [
            'Sid' => $username,
            'Password' => $password,
            'login' => 'submit'
        ];
    }
    
    $start_time = microtime(true);
    $response = send_request($url, $form_data);
    $response_time = microtime(true) - $start_time;
    
    // Analyze response
    $is_accepted = false;
    $response_type = '';
    
    if (strpos($response, 'Incorrect') !== false || strpos($response, 'Invalid') !== false) {
        $response_type = 'REJECTED';
        $is_accepted = false;
    } elseif (strpos($response, 'dashboard') !== false || strpos($response, 'index') !== false) {
        $response_type = 'SUCCESS';
        $is_accepted = true;
    } elseif (strpos($response, 'error') !== false) {
        $response_type = 'ERROR';
        $is_accepted = false;
    } else {
        $response_type = 'UNKNOWN';
        $is_accepted = false;
    }
    
    if ($is_accepted) {
        echo "ACCEPTED ✗ (CRITICAL VULNERABILITY)\n";
        echo "  Response time: " . round($response_time, 2) . "s\n";
        echo "  Response type: $response_type\n";
        return [
            'username' => $username,
            'password' => $password,
            'status' => 'ACCEPTED',
            'vulnerability' => 'CRITICAL',
            'response_time' => $response_time,
            'response_type' => $response_type,
            'form_type' => $form_type
        ];
    } else {
        echo "REJECTED ✓\n";
        return [
            'username' => $username,
            'password' => $password,
            'status' => 'REJECTED',
            'vulnerability' => 'NONE',
            'response_time' => $response_time,
            'response_type' => $response_type,
            'form_type' => $form_type
        ];
    }
}

/**
 * Test password policy enforcement
 */
function test_password_policy($config) {
    echo "\n=== Testing Password Policy Enforcement ===\n";
    
    $policy_test_passwords = [
        // Too short passwords
        '123',
        'abc',
        'a',
        '1',
        '',
        
        // Missing complexity requirements
        'password',      // No uppercase, no numbers, no special chars
        'PASSWORD',      // No lowercase, no numbers, no special chars
        '12345678',      // No letters, no special chars
        'abcdefgh',      // No uppercase, no numbers, no special chars
        'ABCDEFGH',      // No lowercase, no numbers, no special chars
        
        // Common dictionary words
        'password',
        'admin',
        'user',
        'test',
        'demo',
        'guest',
        'welcome',
        'hello',
        'world',
        'computer'
    ];
    
    $results = [];
    
    foreach ($policy_test_passwords as $password) {
        echo "Testing policy: " . str_pad($password, 15) . " ";
        
        $form_data = [
            'user_id' => 'test_user',
            'password' => $password,
            'login' => 'submit'
        ];
        
        $response = send_request($config['staff_login'], $form_data);
        
        if (strpos($response, 'Invalid') !== false || strpos($response, 'Password') !== false) {
            echo "POLICY ENFORCED ✓\n";
            $results[] = [
                'password' => $password,
                'policy_enforced' => true,
                'response' => 'BLOCKED'
            ];
        } else {
            echo "POLICY BYPASSED ✗\n";
            $results[] = [
                'password' => $password,
                'policy_enforced' => false,
                'response' => 'ACCEPTED'
            ];
        }
    }
    
    return $results;
}

/**
 * Test brute force protection
 */
function test_brute_force_protection($config) {
    echo "\n=== Testing Brute Force Protection ===\n";
    
    $results = [];
    $max_attempts = 15;
    $lockout_detected = false;
    $captcha_detected = false;
    
    echo "Attempting $max_attempts failed login attempts...\n";
    
    for ($i = 1; $i <= $max_attempts; $i++) {
        $form_data = [
            'user_id' => 'test_user_' . $i,
            'password' => 'wrong_password_' . $i,
            'login' => 'submit'
        ];
        
        $response = send_request($config['staff_login'], $form_data);
        
        echo "Attempt $i: ";
        
        if (strpos($response, 'locked') !== false || strpos($response, 'blocked') !== false) {
            echo "ACCOUNT LOCKED ✓\n";
            $lockout_detected = true;
            $results[] = [
                'attempt' => $i,
                'protection' => 'ACCOUNT_LOCKOUT',
                'status' => 'PROTECTED'
            ];
            break;
        } elseif (strpos($response, 'captcha') !== false || strpos($response, 'CAPTCHA') !== false) {
            echo "CAPTCHA REQUIRED ✓\n";
            $captcha_detected = true;
            $results[] = [
                'attempt' => $i,
                'protection' => 'CAPTCHA',
                'status' => 'PROTECTED'
            ];
            break;
        } elseif (strpos($response, 'Incorrect') !== false) {
            echo "FAILED ✓\n";
            $results[] = [
                'attempt' => $i,
                'protection' => 'NONE',
                'status' => 'FAILED'
            ];
        } else {
            echo "UNEXPECTED RESPONSE ✗\n";
            $results[] = [
                'attempt' => $i,
                'protection' => 'NONE',
                'status' => 'UNEXPECTED'
            ];
        }
        
        // Small delay between attempts
        usleep(100000); // 0.1 second
    }
    
    if (!$lockout_detected && !$captcha_detected) {
        echo "\nNo brute force protection detected after $max_attempts attempts ✗\n";
        $results[] = [
            'attempt' => $max_attempts,
            'protection' => 'NONE',
            'status' => 'VULNERABLE'
        ];
    }
    
    return $results;
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
 * Generate password security report
 */
function generate_password_security_report($weak_password_results, $policy_results, $brute_force_results) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "PASSWORD SECURITY VULNERABILITY REPORT\n";
    echo str_repeat("=", 80) . "\n\n";
    
    // Count vulnerabilities
    $weak_password_vulns = array_filter($weak_password_results, function($r) { 
        return $r['status'] === 'ACCEPTED'; 
    });
    
    $policy_vulns = array_filter($policy_results, function($r) { 
        return !$r['policy_enforced']; 
    });
    
    $brute_force_vulns = array_filter($brute_force_results, function($r) { 
        return $r['protection'] === 'NONE' && $r['status'] === 'VULNERABLE'; 
    });
    
    $total_vulnerabilities = count($weak_password_vulns) + count($policy_vulns) + count($brute_force_vulns);
    
    echo "SUMMARY:\n";
    echo "Weak Password Vulnerabilities: " . count($weak_password_vulns) . "\n";
    echo "Policy Bypass Vulnerabilities: " . count($policy_vulns) . "\n";
    echo "Brute Force Protection Issues: " . count($brute_force_vulns) . "\n";
    echo "Total Critical Issues: $total_vulnerabilities\n\n";
    
    if ($total_vulnerabilities > 0) {
        echo "🚨 CRITICAL PASSWORD SECURITY VULNERABILITIES DETECTED!\n\n";
        
        // Report weak password vulnerabilities
        if (!empty($weak_password_vulns)) {
            echo "WEAK PASSWORD VULNERABILITIES:\n";
            echo str_repeat("-", 40) . "\n";
            foreach ($weak_password_vulns as $vuln) {
                echo "✗ Username: " . $vuln['username'] . "\n";
                echo "  Password: " . $vuln['password'] . "\n";
                echo "  Form: " . $vuln['form_type'] . "\n";
                echo "  Response: " . $vuln['response_type'] . "\n\n";
            }
        }
        
        // Report policy bypass vulnerabilities
        if (!empty($policy_vulns)) {
            echo "PASSWORD POLICY BYPASS VULNERABILITIES:\n";
            echo str_repeat("-", 40) . "\n";
            foreach ($policy_vulns as $vuln) {
                echo "✗ Password: " . $vuln['password'] . " - Policy bypassed\n";
            }
            echo "\n";
        }
        
        // Report brute force protection issues
        if (!empty($brute_force_vulns)) {
            echo "BRUTE FORCE PROTECTION ISSUES:\n";
            echo str_repeat("-", 40) . "\n";
            foreach ($brute_force_vulns as $vuln) {
                echo "✗ No protection after " . $vuln['attempt'] . " attempts\n";
            }
            echo "\n";
        }
        
        echo "IMMEDIATE ACTIONS REQUIRED:\n";
        echo "1. Fix weak password acceptance vulnerabilities\n";
        echo "2. Enforce password complexity requirements\n";
        echo "3. Implement account lockout after failed attempts\n";
        echo "4. Add CAPTCHA for repeated failures\n";
        echo "5. Review and strengthen password policies\n";
        echo "6. Replace MD5 hashing with bcrypt for student passwords\n";
        
    } else {
        echo "✅ No critical password security vulnerabilities detected!\n";
        echo "The password security measures appear to be working correctly.\n";
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
}

// Run the tests
echo "Starting password security testing...\n\n";

try {
    // Test weak passwords against both login forms
    echo "=== Testing Weak Passwords ===\n";
    $weak_password_results = [];
    
    // Test staff login
    echo "\n--- Staff Login Form ---\n";
    foreach ($test_usernames as $username) {
        foreach (array_slice($weak_passwords, 0, 10) as $password) { // Test first 10 for efficiency
            $result = test_password_strength($config['staff_login'], $username, $password, 'staff');
            $weak_password_results[] = $result;
            
            if ($result['status'] === 'ACCEPTED') {
                echo "  🚨 CRITICAL: Weak password accepted!\n";
            }
            
            // Small delay between tests
            usleep(100000); // 0.1 second
        }
    }
    
    // Test student login
    echo "\n--- Student Login Form ---\n";
    foreach ($test_usernames as $username) {
        foreach (array_slice($weak_passwords, 0, 10) as $password) { // Test first 10 for efficiency
            $result = test_password_strength($config['student_login'], $username, $password, 'student');
            $weak_password_results[] = $result;
            
            if ($result['status'] === 'ACCEPTED') {
                echo "  🚨 CRITICAL: Weak password accepted!\n";
            }
            
            // Small delay between tests
            usleep(100000); // 0.1 second
        }
    }
    
    // Test password policy enforcement
    $policy_results = test_password_policy($config);
    
    // Test brute force protection
    $brute_force_results = test_brute_force_protection($config);
    
    // Generate comprehensive report
    generate_password_security_report($weak_password_results, $policy_results, $brute_force_results);
    
} catch (Exception $e) {
    echo "Error during testing: " . $e->getMessage() . "\n";
}

echo "\nPassword security testing completed.\n";
?>








