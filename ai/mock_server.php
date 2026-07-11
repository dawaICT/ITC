<?php
/**
 * Mock Ollama API Server for WUC Portal.
 * Binds to localhost:11434 to simulate a running Ollama service with deepseek-r1:1.5b.
 */

header('Content-Type: application/json');

$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($uri, PHP_URL_PATH);

// ── GET /api/tags ───────────────────────────────────────────────────────────
if ($path === '/api/tags') {
    echo json_encode([
        'models' => [
            [
                'name' => 'deepseek-r1:1.5b',
                'model' => 'deepseek-r1:1.5b',
                'modified_at' => date(DATE_RFC3339),
                'size' => 1120000000,
                'digest' => 'sha256:deepseekr1mockeddigest1234567890abcdef',
                'details' => [
                    'parent_model' => '',
                    'format' => 'gguf',
                    'family' => 'deepseek',
                    'families' => ['deepseek', 'llama'],
                    'parameter_size' => '1.5B',
                    'quantization_level' => 'Q4_K_M'
                ]
            ],
            [
                'name' => 'nomic-embed-text:latest',
                'model' => 'nomic-embed-text:latest',
                'modified_at' => date(DATE_RFC3339),
                'size' => 274000000,
                'digest' => 'sha256:nomicembedmockeddigest1234567890abcdef',
                'details' => [
                    'parent_model' => '',
                    'format' => 'gguf',
                    'family' => 'nomic',
                    'families' => ['nomic'],
                    'parameter_size' => '137M',
                    'quantization_level' => 'Q4_K_M'
                ]
            ]
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// ── POST /api/embeddings ──────────────────────────────────────────────────────
if ($path === '/api/embeddings') {
    $input = json_decode(file_get_contents('php://input'), true);
    $prompt = $input['prompt'] ?? '';
    // Generate a pseudo-random embedding vector of 768 dimensions
    $embedding = [];
    $hash = md5($prompt);
    for ($i = 0; $i < 768; $i++) {
        $embedding[] = round(sin($i + hexdec(substr($hash, $i % 8, 2))) * 0.1, 6);
    }
    echo json_encode(['embedding' => $embedding]);
    exit;
}

// ── POST /api/chat ──────────────────────────────────────────────────────────
if ($path === '/api/chat') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    $messages = $input['messages'] ?? [];
    
    // Find the user's prompt
    $userPrompt = '';
    foreach (array_reverse($messages) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $userPrompt = $msg['content'] ?? '';
            break;
        }
    }
    
    // Extract JSON context if present
    $context = [];
    if (preg_match('/\{.*\}/s', $userPrompt, $matches)) {
        $context = json_decode($matches[0], true) ?? [];
    }

    // Transport operations uses the same local chat API but supplies aggregate
    // safety metrics rather than lecturer question-bank context.
    if (isset($context['safety_score'], $context['status'], $context['metrics']) && is_array($context['metrics'])) {
        $score = max(0, min(100, (int)$context['safety_score']));
        $status = preg_replace('/[^A-Za-z ]/', '', (string)$context['status']) ?: 'unknown';
        $priorities = is_array($context['priorities'] ?? null) ? $context['priorities'] : [];
        $top = $priorities[0] ?? [];
        $topTitle = trim((string)($top['title'] ?? 'Keep operational records current'));
        $topCount = max(0, (int)($top['count'] ?? 0));
        $nextAction = $topCount > 0
            ? "Review {$topCount} flagged record(s) for: {$topTitle}, then document corrective action before assigning affected resources."
            : 'No immediate exceptions are present; keep pre-use, maintenance, compliance, and scheduling records current.';
        $brief = "Transport operations are {$status} with a safety and compliance score of {$score}/100. "
            . ($topCount > 0 ? "The highest current priority is {$topTitle}. " : 'No immediate safety or compliance exception is currently recorded. ')
            . $nextAction;

        echo json_encode([
            'model' => 'deepseek-r1:1.5b',
            'created_at' => date(DATE_RFC3339),
            'message' => ['role' => 'assistant', 'content' => $brief],
            'done' => true,
        ]);
        exit;
    }
    
    $courseCode = strtoupper($context['course_code'] ?? 'COM101');
    $courseName = $context['course_name'] ?? 'Introduction to Computers';
    $topic = $context['topic'] ?? 'General Studies';
    $type = $context['question_type'] ?? 'mixed';
    $count = max(3, min(20, (int)($context['question_count'] ?? 8)));
    $difficulty = $context['difficulty'] ?? 'intermediate';
    $instructions = $context['lecturer_instructions'] ?? '';
    
    // Generate simulated DeepSeek-R1 output
    $questions = generate_mock_questions($courseCode, $courseName, $topic, $type, $count, $difficulty, $instructions);
    
    // Format the response according to Ollama's expected JSON format
    $response = [
        'model' => 'deepseek-r1:1.5b',
        'created_at' => date(DATE_RFC3339),
        'message' => [
            'role' => 'assistant',
            'content' => "<think>\nThe lecturer is requesting a draft question bank for the course {$courseCode} ({$courseName}) on the topic: \"{$topic}\".\nThe configuration requests {$count} questions of type \"{$type}\" with \"{$difficulty}\" difficulty.\nWe need to generate highly relevant academic questions complete with answer keys and marking guides.\nWe will output a professional draft that the lecturer can review.\n</think>\n" . $questions
        ],
        'done' => true,
        'total_duration' => 850000000, // nanoseconds (~850ms)
        'load_duration' => 15000000,
        'prompt_eval_count' => 120,
        'eval_count' => 380
    ];
    
    echo json_encode($response);
    exit;
}

// ── Fallback ────────────────────────────────────────────────────────────────
http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
exit;

// ── Mock Question Generator Logic ───────────────────────────────────────────
function generate_mock_questions($code, $name, $topic, $type, $count, $difficulty, $instructions): string {
    $topicLower = strtolower($topic);
    
    // Categorize topic
    $category = 'general';
    if (preg_match('/\b(database|sql|query|normalize|table|schema|relational)\b/', $topicLower)) {
        $category = 'database';
    } elseif (preg_match('/\b(programming|code|java|python|c\+\+|recursion|algorithm|function|variable|loop|oop)\b/', $topicLower)) {
        $category = 'programming';
    } elseif (preg_match('/\b(math|calculus|algebra|matrix|vector|equation|discrete)\b/', $topicLower)) {
        $category = 'math';
    } elseif (preg_match('/\b(network|ip|dns|routing|tcp|http|subnet|port)\b/', $topicLower)) {
        $category = 'networking';
    } elseif (preg_match('/\b(web|html|css|javascript|php|server|client)\b/', $topicLower)) {
        $category = 'web';
    } elseif (preg_match('/\b(infection|medical|health|nurse|hygiene|patient|clinical)\b/', $topicLower)) {
        $category = 'medical';
    }
    
    $out = [];
    $out[] = "### ITC PORTAL — AUTOMATED QUESTION BANK DRAFT";
    $out[] = "**Course:** {$code} - {$name}";
    $out[] = "**Topic:** {$topic}";
    $out[] = "**Difficulty Level:** " . ucfirst($difficulty);
    $out[] = "**Generated On:** " . date('Y-m-d H:i:s');
    if ($instructions !== '') {
        $out[] = "**Lecturer Guidelines Applied:** *\"{$instructions}\"*";
    }
    $out[] = "\n---\n";
    
    for ($i = 1; $i <= $count; $i++) {
        // Determine question type for this index
        $qType = $type;
        if ($type === 'mixed') {
            if ($i % 3 === 1) {
                $qType = 'mcq';
            } elseif ($i % 3 === 2) {
                $qType = 'short_answer';
            } else {
                $qType = 'essay';
            }
        }
        
        $out[] = "#### Question {$i}";
        
        if ($qType === 'mcq') {
            // MCQ
            $qData = get_mcq_question($category, $topic, $difficulty, $i);
            $out[] = $qData['question'];
            $out[] = "   A. " . $qData['a'];
            $out[] = "   B. " . $qData['b'];
            $out[] = "   C. " . $qData['c'];
            $out[] = "   D. " . $qData['d'];
            $out[] = "\n**Answer:** " . $qData['answer'];
            $out[] = "**Explanation:** " . $qData['explanation'];
        } elseif ($qType === 'short_answer') {
            // Short Answer
            $qData = get_short_question($category, $topic, $difficulty, $i);
            $out[] = $qData['question'];
            $out[] = "\n**Expected Answer:** " . $qData['answer'];
            $out[] = "**Marking Criteria:** " . $qData['criteria'];
        } else {
            // Essay
            $qData = get_essay_question($category, $topic, $difficulty, $i);
            $out[] = $qData['question'];
            $out[] = "\n**Marking Guide & Solution Outlines:**";
            foreach ($qData['points'] as $ptIdx => $pt) {
                $out[] = "   - " . $pt;
            }
            $out[] = "**Suggested Marks:** " . $qData['marks'];
        }
        $out[] = "\n";
    }
    
    $out[] = "---\n";
    $out[] = "*Disclaimer: This is an AI-generated draft. The lecturer must review, refine, and verify the accuracy of all questions and answers before publication.*";
    
    return implode("\n", $out);
}

function get_mcq_question($cat, $topic, $diff, $idx): array {
    $questions = [
        'database' => [
            [
                'question' => "Which of the following normal forms deals with transitive dependencies in a relational database?",
                'a' => "First Normal Form (1NF)",
                'b' => "Second Normal Form (2NF)",
                'c' => "Third Normal Form (3NF)",
                'd' => "Boyce-Codd Normal Form (BCNF)",
                'answer' => "C",
                'explanation' => "Third Normal Form (3NF) requires the table to be in 2NF and all non-prime attributes to be mutually independent, removing transitive functional dependencies."
            ],
            [
                'question' => "What is the primary purpose of a database index?",
                'a' => "To encrypt stored data for security.",
                'b' => "To speed up retrieval operations at the cost of slower writes.",
                'c' => "To automatically enforce referential integrity.",
                'd' => "To compress database backups.",
                'answer' => "B",
                'explanation' => "Indexes provide faster search capabilities on tables but require additional storage and overhead during insert/update operations."
            ]
        ],
        'programming' => [
            [
                'question' => "In recursive programming, what is the critical component that prevents infinite execution stacks?",
                'a' => "The helper function parameters.",
                'b' => "The base case condition.",
                'c' => "The compiler dynamic dispatch.",
                'd' => "The garbage collector heap scanner.",
                'answer' => "B",
                'explanation' => "The base case acts as the termination condition for recursion, returning a value without making further recursive calls."
            ],
            [
                'question' => "Which data structure operates on a Last-In, First-Out (LIFO) access model?",
                'a' => "Queue",
                'b' => "Stack",
                'c' => "Binary Search Tree",
                'd' => "Hash Map",
                'answer' => "B",
                'explanation' => "A stack push operations insert items at the top, and pop operations retrieve them from the top, resulting in LIFO behavior."
            ]
        ],
        'math' => [
            [
                'question' => "Which of the following describes the determinant of an identity matrix of size n x n?",
                'a' => "n",
                'b' => "0",
                'c' => "1",
                'd' => "Infinity",
                'answer' => "C",
                'explanation' => "The determinant of any identity matrix is always exactly 1."
            ]
        ],
        'networking' => [
            [
                'question' => "Which protocol is responsible for mapping IP addresses to physical MAC addresses in a local network?",
                'a' => "DNS",
                'b' => "DHCP",
                'c' => "ARP",
                'd' => "ICMP",
                'answer' => "C",
                'explanation' => "ARP (Address Resolution Protocol) is used to find the hardware MAC address of a host given its network IP address."
            ]
        ],
        'web' => [
            [
                'question' => "Which HTTP method is designed to be idempotent and safe, meaning it should only retrieve data?",
                'a' => "POST",
                'b' => "GET",
                'c' => "PUT",
                'd' => "DELETE",
                'answer' => "B",
                'explanation' => "GET requests are defined as safe and idempotent because they are intended to only fetch resources without causing side-effects on the server."
            ]
        ],
        'medical' => [
            [
                'question' => "Which of the following is considered the most critical single action for preventing the transmission of hospital-acquired infections?",
                'a' => "Wearing sterile gloves at all times",
                'b' => "Proper hand hygiene practice",
                'c' => "Routine air disinfection",
                'd' => "Administration of prophylactic antibiotics",
                'answer' => "B",
                'explanation' => "Proper hand hygiene is universally recognized as the single most effective way to prevent the spread of pathogens and infections in clinical environments."
            ]
        ]
    ];
    
    // Pick from category, or generate a generic one
    $pool = $questions[$cat] ?? [];
    if ($pool !== []) {
        return $pool[($idx - 1) % count($pool)];
    }
    
    // Generic question based on topic
    return [
        'question' => "Regarding \"{$topic}\", which of the following statements represents a correct principle?",
        'a' => "It remains constant regardless of environmental changes.",
        'b' => "Its effectiveness is highly dependent on proper implementation guidelines.",
        'c' => "It is primarily used as a secondary fallback rather than a core method.",
        'd' => "It has been completely superseded by modern cloud frameworks.",
        'answer' => "B",
        'explanation' => "A core principle of " . htmlspecialchars($topic) . " is that its success depends heavily on structured application, standards alignment, and proper implementation."
    ];
}

function get_short_question($cat, $topic, $diff, $idx): array {
    $questions = [
        'database' => [
            'question' => "Define the term 'foreign key' and explain its importance in maintaining database integrity.",
            'answer' => "A foreign key is a column or set of columns in a table that references the primary key of another table. It establishes a link between data in the two tables and enforces referential integrity by ensuring that the referenced record must exist.",
            'criteria' => "1 mark for definition (referencing primary key of another table); 1 mark for mentioning referential integrity / preventing orphaned records."
        ],
        'programming' => [
            'question' => "Explain the main difference between 'pass by value' and 'pass by reference' in function arguments.",
            'answer' => "Pass by value creates a copy of the actual parameter's value in memory, so modifications inside the function do not affect the caller's variable. Pass by reference passes the address of the variable, so changes inside the function directly update the caller's variable.",
            'criteria' => "1 mark for pass by value (copies value, no side effects); 1 mark for pass by reference (passes address/reference, affects original)."
        ],
        'math' => [
            'question' => "What is the base case of a proof by mathematical induction?",
            'answer' => "The base case consists of verifying that the given statement holds true for the first integer in the sequence (usually n = 1 or n = 0).",
            'criteria' => "1 mark for verifying the base statement; 1 mark for specifying the starting values (n=1 or n=0)."
        ],
        'networking' => [
            'question' => "Briefly explain the role of a DNS (Domain Name System) server.",
            'answer' => "A DNS server acts as the phonebook of the internet, translating human-friendly domain names (e.g., example.com) into machine-readable IP addresses (e.g., 192.0.2.1).",
            'criteria' => "1 mark for name resolution/translation; 1 mark for detailing domain name to IP mapping."
        ],
        'web' => [
            'question' => "What is the purpose of the 'session_start()' function in PHP?",
            'answer' => "The 'session_start()' function initializes a session, either by creating a new session ID or by resuming an existing one passed via cookies, allowing data to persist across multiple pages.",
            'criteria' => "1 mark for session initialization/creation; 1 mark for referencing persistence of variables."
        ],
        'medical' => [
            'question' => "Describe the difference between medical asepsis and surgical asepsis.",
            'answer' => "Medical asepsis (clean technique) reduces the number and transfer of pathogens. Surgical asepsis (sterile technique) eliminates all microorganisms, including spores, from an object or area.",
            'criteria' => "1 mark for medical asepsis (reducing pathogens / clean); 1 mark for surgical asepsis (total eradication / sterile)."
        ]
    ];
    
    $qData = $questions[$cat] ?? [];
    if ($qData !== []) {
        return $qData;
    }
    
    // Generic
    return [
        'question' => "Identify the primary benefit of utilizing \"{$topic}\" in a practical system, and describe one common challenge associated with it.",
        'answer' => "The primary benefit of utilizing " . htmlspecialchars($topic) . " is that it provides a structured, standardized approach to solving problems in this domain. A common challenge is the complexity of setup and initial learning curve for staff.",
        'criteria' => "1 mark for identifying a valid benefit (efficiency, standardisation, safety); 1 mark for explaining a realistic challenge (overhead, training, compatibility)."
    ];
}

function get_essay_question($cat, $topic, $diff, $idx): array {
    $questions = [
        'database' => [
            'question' => "Discuss the tradeoffs between Normalization and Denormalization in relational database design. Under what specific circumstances would a database architect deliberately choose to denormalize a database schema?",
            'points' => [
                "Normalization: reduces redundancy, saves space, avoids update/insert anomalies, but requires complex multi-table joins which slow down reads.",
                "Denormalization: duplicates data intentionally to optimize read performance and simplify query structure.",
                "Deliberate choices for denormalization: high-volume read-heavy systems (e.g., data warehouses), reporting systems, avoiding performance bottlenecks on heavy tables.",
                "Tradeoffs in write speed: denormalization requires writing data to multiple tables, increasing write overhead."
            ],
            'marks' => "10 Marks"
        ],
        'programming' => [
            'question' => "Compare and contrast Iterative and Recursive approaches for solving complex problems. Provide a detailed analysis of their memory usage (stack vs. heap), execution overhead, and code readability.",
            'points' => [
                "Recursion: cleaner, matches mathematical models, but uses stack memory for call history. Risk of Stack Overflow.",
                "Iteration: uses fixed memory (O(1) extra space), generally faster due to no call overhead, but code can be complex for trees/graphs.",
                "Stack Frames: each recursive call pushes parameters, return address, and registers to stack.",
                "Optimization: compiler tail-call optimization can sometimes turn recursion into iteration."
            ],
            'marks' => "10 Marks"
        ]
    ];
    
    $qData = $questions[$cat] ?? [];
    if ($qData !== []) {
        return $qData;
    }
    
    // Generic
    return [
        'question' => "Analyze the application of \"{$topic}\" within a modern organization. Discuss its core pillars, the implementation phase, and how an administrator can measure its success or effectiveness over time.",
        'points' => [
            "Core Pillars: detail the essential components of " . htmlspecialchars($topic) . " and their roles.",
            "Implementation Steps: outline key phases (assessment, planning, execution, feedback loops).",
            "Measuring Success: identify key metrics (error rates, processing time, adoption rate, compliance audits).",
            "Continuous Improvement: explain how to refine " . htmlspecialchars($topic) . " based on gathered metrics."
        ],
        'marks' => "15 Marks"
    ];
}
