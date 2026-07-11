<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';

$Sid = $_SESSION['Sid'] ?? null;

require_once __DIR__ . '/../includes/ai_portal.php';

// Handle AJAX AI Librarian Chat Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ai_chat') {
    header('Content-Type: application/json; charset=utf-8');
    
    // CSRF Protection
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }

    if (!$Sid) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized access.']);
        exit;
    }

    $message = trim($_POST['message'] ?? '');
    $reset = !empty($_POST['reset']);
    
    $historyKey = 'ai_library_history_' . md5((string)$Sid);
    if ($reset) {
        unset($_SESSION[$historyKey]);
        echo json_encode(['ok' => true, 'reset' => true]);
        exit;
    }

    if ($message === '') {
        echo json_encode(['ok' => false, 'message' => 'Please type a message.']);
        exit;
    }

    if (mb_strlen($message) > 800) {
        $message = mb_substr($message, 0, 800);
    }

    $rate = wuc_ai_rate_limit('student_library_ai', 30, 3600);
    if (!$rate['ok']) {
        echo json_encode(['ok' => false, 'message' => 'Too many AI messages. Please wait ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.']);
        exit;
    }

    // Get message history
    $history = $_SESSION[$historyKey] ?? [];
    if (!is_array($history)) {
        $history = [];
    }

    // Get active courses
    $courses = [];
    $sql = "SELECT DISTINCT course_code FROM course_registration WHERE Sid = ? AND COALESCE(is_active, 1) = 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $Sid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $courses[] = strtoupper(trim($row['course_code']));
        }
        $stmt->close();
    }

    // Get library resources
    $resources = [];
    $sql = "SELECT id, title, resource_type, url, file_path, subject, description FROM library_digital_resources LIMIT 60";
    $res = $db->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $resources[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'type' => $row['resource_type'],
                'url' => $row['url'] ?: $row['file_path'],
                'subject' => $row['subject'],
                'desc' => wuc_ai_truncate($row['description'] ?? '', 160)
            ];
        }
        $res->free();
    }

    $contextData = [
        'student_id' => $Sid,
        'registered_courses' => $courses,
        'available_library_resources' => $resources
    ];
    $contextJson = wuc_ai_context_json($contextData, 10000);

    $messages = [
        [
            'role' => 'system',
            'content' => 'You are the "ITC AI Librarian", a friendly conversational study and library assistant on the ITC portal. '
                       . 'Help the student search resources, recommend specific items from the digital library, and answer study questions. '
                       . 'Use the provided list of available library resources and registered courses to make tailored recommendations. '
                       . 'When suggesting a resource from the library context, provide its name and a brief description. Always use Markdown (bold, lists, etc.). '
                       . 'Be warm, encouraging, and highly structured in your advice.'
        ],
        [
            'role' => 'system',
            'content' => "Current Library and Course Context:\n{$contextJson}"
        ]
    ];

    // Replay history
    $recent = array_slice($history, -12);
    foreach ($recent as $turn) {
        $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $messages[] = ['role' => $role, 'content' => (string)($turn['content'] ?? '')];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $result = wuc_ai_generate($db, [
        'feature' => 'student_library_ai',
        'user_role' => 'student',
        'user_id' => (string)$Sid,
        'input_summary' => mb_substr($message, 0, 200),
        'context_hash' => hash('sha256', $contextJson),
        'messages' => $messages,
        'fallback' => static function () use ($courses): string {
            return "I'm having trouble connecting to the AI Librarian service right now. "
                 . "However, you can search and filter the resources list directly on the left using the Search bar and chips! "
                 . "Your active courses: " . implode(', ', $courses);
        }
    ]);

    $reply = (string)$result['text'];
    
    // Save to history
    $history[] = ['role' => 'user', 'content' => $message];
    $history[] = ['role' => 'assistant', 'content' => $reply];
    $_SESSION[$historyKey] = array_slice($history, -12);

    echo json_encode([
        'ok' => true,
        'reply_html' => '<div class="ai-output">' . wuc_ai_render_markdown($reply) . '</div>',
        'used_ai' => (bool)$result['used_ai'],
        'model' => (string)$result['model']
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Digital Library</title>
  <link rel="stylesheet" href="/wucportal/css/admin-style.css">
  <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    .dl-hero {
      background: linear-gradient(135deg, #12304a 0%, #1f6f78 56%, #2f8f6f 100%);
      color: #fff;
      border-radius: 8px;
      padding: 1.75rem 1.75rem 1.5rem;
      box-shadow: 0 12px 30px rgba(18,48,74,0.18);
    }
    .dl-hero h3 { font-weight: 700; letter-spacing: -0.01em; }
    .dl-hero .subtitle { color: rgba(255,255,255,0.85); }
    .dl-search-bar {
      background: #fff;
      border-radius: 12px;
      padding: 4px 4px 4px 16px;
      display: flex;
      align-items: center;
      box-shadow: 0 6px 18px rgba(0,0,0,0.08);
    }
    .dl-search-bar input {
      border: 0;
      outline: 0;
      flex: 1;
      font-size: 1rem;
      padding: .65rem .25rem;
      background: transparent;
      color: #333;
    }
    .dl-search-bar .kbd {
      background: #f1f3f5;
      color: #6c757d;
      border-radius: 6px;
      padding: 2px 8px;
      font-size: .75rem;
      margin-right: 8px;
      border: 1px solid #e9ecef;
    }
    .dl-search-bar button.search-btn {
      background: #1f6f78;
      color: #fff;
      border: 0;
      border-radius: 10px;
      padding: .55rem 1.1rem;
      font-weight: 500;
    }
    .dl-search-bar button.search-btn:hover { background: #185961; }
    .dl-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
    .dl-chip {
      cursor: pointer;
      user-select: none;
      background: #fff;
      border: 1px solid #e3e6eb;
      color: #495057;
      padding: .35rem .85rem;
      border-radius: 999px;
      font-size: .82rem;
      font-weight: 500;
      transition: all .15s ease;
      display: inline-flex;
      align-items: center;
      gap: .3rem;
    }
    .dl-chip:hover { border-color: #1f6f78; color: #1f6f78; }
    .dl-chip.active {
      background: #1f6f78;
      border-color: #1f6f78;
      color: #fff;
      box-shadow: 0 4px 10px rgba(31,111,120,0.18);
    }
    .learning-context {
      background: #fff;
      border: 1px solid #dce6ea;
      border-radius: 8px;
      padding: .9rem 1rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
    }
    .learning-context .label {
      color: #5f6f76;
      font-size: .76rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: .35rem;
    }
    .course-pills { display: flex; flex-wrap: wrap; gap: .4rem; }
    .course-pill {
      background: #eef7f5;
      color: #0f5c4c;
      border: 1px solid #cfe7df;
      padding: .22rem .58rem;
      border-radius: 999px;
      font-size: .78rem;
      font-weight: 600;
    }
    .dl-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: .75rem;
      margin: 1.25rem 0 1rem;
    }
    .dl-result-count { color: #6c757d; font-size: .9rem; }
    .dl-result-count strong { color: #212529; }
    .dl-sort {
      border: 1px solid #e3e6eb;
      border-radius: 8px;
      padding: .35rem .6rem;
      font-size: .85rem;
      background: #fff;
    }
    .resource-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 1.1rem;
    }
    .resource-card {
      background: #fff;
      border-radius: 8px;
      padding: 1.1rem 1.1rem 1rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
      border: 1px solid #eef0f3;
      display: flex;
      flex-direction: column;
      transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
      position: relative;
    }
    .resource-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 24px rgba(31,111,120,0.12);
      border-color: #b9d8dd;
    }
    .resource-icon {
      width: 48px; height: 48px;
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem;
      margin-bottom: .7rem;
      color: #fff;
    }
    .ri-ebook    { background: linear-gradient(135deg,#2563eb,#4f8df7); }
    .ri-journal  { background: linear-gradient(135deg,#2f8f6f,#5dbb94); }
    .ri-video    { background: linear-gradient(135deg,#c2410c,#f97316); }
    .ri-audio    { background: linear-gradient(135deg,#fb8c00,#ffa726); }
    .ri-dataset  { background: linear-gradient(135deg,#00897b,#26a69a); }
    .ri-document { background: linear-gradient(135deg,#5e35b1,#7e57c2); }
    .ri-other    { background: linear-gradient(135deg,#546e7a,#78909c); }

    .resource-card h6 {
      font-size: 1rem;
      font-weight: 600;
      margin: 0 0 .35rem;
      line-height: 1.35;
      color: #212529;
      padding-right: 28px;
    }
    .resource-card .desc {
      font-size: .82rem;
      color: #6c757d;
      line-height: 1.45;
      flex: 1;
      margin-bottom: .7rem;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .resource-meta {
      display: flex;
      flex-wrap: wrap;
      gap: .35rem;
      margin-bottom: .75rem;
      font-size: .72rem;
    }
    .meta-pill {
      background: #eef7f5;
      color: #0f5c4c;
      padding: .18rem .55rem;
      border-radius: 999px;
      font-weight: 500;
    }
    .meta-pill.new { background: #e8f5e9; color: #2e7d32; }
    .meta-pill.views { background: #fff3e0; color: #ef6c00; }
    .meta-pill.recommended { background: #eaf2ff; color: #1d4ed8; }
    .meta-pill.source { background: #f1f3f5; color: #5f6f76; }
    .resource-actions {
      display: flex;
      gap: .5rem;
      align-items: center;
    }
    .btn-open {
      flex: 1;
      background: #1f6f78;
      color: #fff;
      border: 0;
      border-radius: 8px;
      padding: .5rem .75rem;
      font-size: .85rem;
      font-weight: 500;
      text-decoration: none;
      text-align: center;
      transition: background .15s ease;
    }
    .btn-open:hover { background: #185961; color: #fff; }
    .btn-open.disabled {
      background: #e9ecef;
      color: #adb5bd;
      pointer-events: none;
    }
    .bookmark-btn {
      width: 36px; height: 36px;
      border-radius: 8px;
      border: 1px solid #e3e6eb;
      background: #fff;
      color: #adb5bd;
      transition: all .15s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .bookmark-btn:hover { color: #f5a623; border-color: #f5a623; }
    .bookmark-btn.active { color: #f5a623; border-color: #f5a623; background: #fff8e6; }
    .empty-state {
      text-align: center;
      padding: 3.5rem 1.5rem;
      color: #6c757d;
    }
    .empty-state i { font-size: 3rem; color: #d8c9f0; margin-bottom: 1rem; }
    .empty-state h5 { color: #495057; margin-bottom: .35rem; }
    .skeleton-card {
      background: #fff;
      border-radius: 8px;
      padding: 1.1rem;
      border: 1px solid #eef0f3;
      height: 220px;
    }
    .skeleton-shimmer {
      background: linear-gradient(90deg, #f3f4f6 0%, #e9ecef 50%, #f3f4f6 100%);
      background-size: 200% 100%;
      animation: shimmer 1.3s infinite;
      border-radius: 6px;
    }
    @keyframes shimmer { 0% {background-position: 200% 0;} 100% {background-position: -200% 0;} }
    .section-title {
      font-size: .8rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #6c757d;
      margin: 2rem 0 .75rem;
      display: flex; align-items: center; gap: .5rem;
    }
    .section-title i { color: #f5a623; }
    @media (max-width: 575px) {
      .dl-hero { padding: 1.25rem; }
      .learning-context { align-items: flex-start; }
      .resource-grid { grid-template-columns: 1fr; }
    }
    
    /* Premium AI Librarian Styling */
    .ai-lib-card {
      border: 1px solid rgba(111, 66, 193, 0.12);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 8px 30px rgba(111, 66, 193, 0.08);
      background: #fff;
      transition: box-shadow 0.2s ease;
    }
    .ai-lib-card:hover {
      box-shadow: 0 12px 35px rgba(111, 66, 193, 0.12);
    }
    .ai-lib-hdr {
      background: linear-gradient(135deg, #6f42c1, #5a32a3);
      color: #fff;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    .ai-lib-thread {
      height: 410px;
      overflow-y: auto;
      background: #faf9fd;
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      gap: 14px;
      scroll-behavior: smooth;
    }
    /* Thread Scrollbar */
    .ai-lib-thread::-webkit-scrollbar {
      width: 5px;
    }
    .ai-lib-thread::-webkit-scrollbar-track {
      background: rgba(111, 66, 193, 0.02);
    }
    .ai-lib-thread::-webkit-scrollbar-thumb {
      background: rgba(111, 66, 193, 0.2);
      border-radius: 4px;
    }
    .ai-lib-msg {
      display: flex;
      gap: 10px;
      max-width: 88%;
      align-items: flex-start;
    }
    .ai-lib-msg.user {
      align-self: flex-end;
      flex-direction: row-reverse;
    }
    .ai-lib-msg.assistant {
      align-self: flex-start;
    }
    .ai-lib-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.85rem;
      color: #fff;
      flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    }
    .ai-lib-msg.user .ai-lib-avatar {
      background: #495057;
    }
    .ai-lib-msg.assistant .ai-lib-avatar {
      background: #6f42c1;
    }
    .ai-lib-bubble {
      padding: 0.75rem 1rem;
      border-radius: 14px;
      font-size: 0.9rem;
      line-height: 1.55;
      box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .ai-lib-msg.user .ai-lib-bubble {
      background: #6f42c1;
      color: #fff;
      border-top-right-radius: 2px;
    }
    .ai-lib-msg.user .ai-lib-bubble * {
      color: #fff !important;
    }
    .ai-lib-msg.assistant .ai-lib-bubble {
      background: #fff;
      border: 1px solid #ece8f5;
      color: #2b2b2b;
      border-top-left-radius: 2px;
    }
    .ai-lib-msg.assistant .ai-lib-bubble p:last-child {
      margin-bottom: 0;
    }
    .ai-lib-chip {
      cursor: pointer;
      background: #f1ecfa;
      border: 1px solid #e2d9f3;
      color: #5a32a3;
      border-radius: 20px;
      padding: 0.35rem 0.8rem;
      font-size: 0.8rem;
      font-weight: 500;
      transition: all 0.15s ease;
      display: inline-block;
      margin: 2px;
    }
    .ai-lib-chip:hover {
      background: #6f42c1;
      color: #fff;
      border-color: #6f42c1;
      box-shadow: 0 3px 8px rgba(111,66,193,0.15);
    }
    .ai-lib-input-group {
      display: flex;
      gap: 10px;
      border-top: 1px solid #f1ecfa;
      padding: 0.8rem 1rem;
      background: #fff;
      align-items: center;
    }
    .ai-lib-input-group textarea {
      resize: none;
      font-size: 0.88rem;
      border-radius: 10px;
      border: 1px solid #e2d9f3;
      padding: 0.5rem 0.75rem;
    }
    .ai-lib-input-group textarea:focus {
      border-color: #6f42c1;
      box-shadow: 0 0 0 0.2rem rgba(111, 66, 193, 0.18);
      outline: 0;
    }
    .ai-lib-send-btn {
      background: #6f42c1;
      color: #fff;
      border: 0;
      border-radius: 10px;
      width: 42px;
      height: 42px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s ease;
      flex-shrink: 0;
      box-shadow: 0 3px 10px rgba(111,66,193,0.25);
    }
    .ai-lib-send-btn:hover {
      background: #5a32a3;
      transform: translateY(-1px);
    }
    .ai-lib-send-btn:active {
      transform: translateY(0);
    }
    /* Typing indicator */
    .ai-lib-typing {
      display: flex;
      align-items: center;
      gap: 3px;
      padding: 0.25rem 0.5rem;
    }
    .ai-lib-typing span {
      display: inline-block;
      width: 6px;
      height: 6px;
      background: #6f42c1;
      border-radius: 50%;
      animation: ailibblink 1.4s infinite both;
    }
    .ai-lib-typing span:nth-child(2) { animation-delay: .2s; }
    .ai-lib-typing span:nth-child(3) { animation-delay: .4s; }
    @keyframes ailibblink {
      0%, 80%, 100% { opacity: .2; transform: scale(0.8); }
      40% { opacity: 1; transform: scale(1.1); }
    }
    /* Glowing active state for robot button */
    #toggleAiBtn.active {
      background: #6f42c1 !important;
      color: #fff !important;
      border-color: #6f42c1 !important;
      box-shadow: 0 0 10px rgba(111,66,193,0.3);
    }
    #toggleAiBtn i {
      transition: transform 0.3s ease;
    }
    #toggleAiBtn.active i {
      transform: rotate(360deg);
    }
    @keyframes robot-pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.15); }
    }
    .pulse-robot {
      animation: robot-pulse 2s infinite ease-in-out;
      display: inline-block;
    }
  </style>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>
<div class="content-wrapper">
<div class="container py-4">

  <div class="dl-hero mb-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
      <div>
        <h3 class="mb-1"><i class="fas fa-cloud-download-alt me-2"></i>Student Digital Library</h3>
        <p class="subtitle mb-0">Resources, modules and video suggestions matched to your assigned courses.</p>
      </div>
      <div class="d-flex gap-2">
        <button id="toggleAiBtn" type="button" class="btn btn-light btn-sm rounded-pill px-3 active" style="border: 1px solid rgba(111,66,193,0.3); color: #5a32a3;">
          <i class="fas fa-robot me-1"></i> Close AI
        </button>
        <a href="index.php" class="btn btn-light btn-sm rounded-pill px-3">
          <i class="fas fa-arrow-left me-1"></i> Back
        </a>
      </div>
    </div>

    <form id="searchForm" class="dl-search-bar" autocomplete="off">
      <i class="fas fa-search text-muted me-2"></i>
      <input id="digitalSearch" type="search" name="q" placeholder="Search title, course code, topic or video...">
      <span class="kbd d-none d-md-inline" id="kbdHint">/</span>
      <button class="search-btn" type="submit"><i class="fas fa-search d-md-none"></i><span class="d-none d-md-inline">Search</span></button>
    </form>
  </div>

  <div class="learning-context mb-3" id="learningContext">
    <div>
      <div class="label">Learning context</div>
      <div class="course-pills" id="coursePills">
        <span class="course-pill">Loading assigned courses...</span>
      </div>
    </div>
    <span class="text-muted small" id="recommendationHint">Recommendations appear first.</span>
  </div>

  <div class="row" id="libraryLayoutRow">
    <div class="col-lg-8" id="libraryMainCol">
      <div class="dl-chips" id="typeChips">
        <span class="dl-chip active" data-type="__recommended"><i class="fas fa-star"></i> Recommended</span>
        <span class="dl-chip" data-type="__all"><i class="fas fa-layer-group"></i> All</span>
        <span class="dl-chip" data-type="ebook"><i class="fas fa-book"></i> Ebooks</span>
        <span class="dl-chip" data-type="journal"><i class="fas fa-newspaper"></i> Journals</span>
        <span class="dl-chip" data-type="video"><i class="fas fa-play-circle"></i> Videos</span>
        <span class="dl-chip" data-type="audio"><i class="fas fa-headphones"></i> Audio</span>
        <span class="dl-chip" data-type="dataset"><i class="fas fa-database"></i> Datasets</span>
        <span class="dl-chip" data-type="document"><i class="fas fa-file-alt"></i> Documents</span>
        <span class="dl-chip" data-type="__bookmarks"><i class="fas fa-bookmark"></i> Bookmarks</span>
      </div>

      <div class="dl-toolbar">
        <div class="dl-result-count" id="resultCount">Loading resources...</div>
        <select class="dl-sort" id="sortSelect">
          <option value="recommended">Recommended first</option>
          <option value="newest">Newest first</option>
          <option value="popular">Most viewed</option>
          <option value="title">Title A-Z</option>
        </select>
      </div>

      <div id="libraryMessage" class="alert d-none" role="alert"></div>

      <div id="resourceGrid" class="resource-grid"></div>
    </div>

    <!-- AI Librarian Sidebar Panel -->
    <div class="col-lg-4" id="libraryAiCol">
      <div class="card shadow-sm border-0 rounded-4 mb-4 ai-lib-card">
        <!-- Card Header -->
        <div class="card-header ai-lib-hdr d-flex justify-content-between align-items-center py-3">
          <h5 class="mb-0 fs-6"><i class="fas fa-robot me-2 pulse-robot"></i>AI Librarian</h5>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success bg-opacity-25 text-white border border-success border-opacity-50 small">Online</span>
            <button type="button" class="btn btn-link p-0 text-white opacity-75 hover-opacity-100" id="resetAiChat" title="Reset conversation">
              <i class="fas fa-rotate-left"></i>
            </button>
          </div>
        </div>
        
        <!-- Chat Thread -->
        <div class="ai-lib-thread" id="aiLibThread">
          <div class="ai-lib-msg assistant">
            <div class="ai-lib-avatar"><i class="fas fa-robot"></i></div>
            <div class="ai-lib-bubble">
              <p class="mb-2">Hi there! 👋 I am your <strong>ITC AI Librarian</strong>.</p>
              <p class="mb-2">Ask me to search open resources, explain study topics, or recommend books/videos for your courses.</p>
              <div class="mt-2">
                <span class="ai-lib-chip" data-text="Recommend study resources for my active courses.">Recommend resources</span>
                <span class="ai-lib-chip" data-text="What are some good external video tutorials for my studies?">Video lessons</span>
                <span class="ai-lib-chip" data-text="Explain the core topics of my registered courses.">Explain concepts</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Chat Input Area -->
        <div class="ai-lib-input-group">
          <textarea class="form-control" id="aiLibInput" rows="2" placeholder="Ask the AI Librarian..."></textarea>
          <button class="ai-lib-send-btn" id="aiLibSend" type="button" title="Send">
            <i class="fas fa-paper-plane"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

</div>
</div>

<script>
const csrf = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
const TYPE_ICONS = {
  ebook:    { icon: 'fa-book',         cls: 'ri-ebook' },
  journal:  { icon: 'fa-newspaper',    cls: 'ri-journal' },
  video:    { icon: 'fa-play-circle',  cls: 'ri-video' },
  audio:    { icon: 'fa-headphones',   cls: 'ri-audio' },
  dataset:  { icon: 'fa-database',     cls: 'ri-dataset' },
  document: { icon: 'fa-file-alt',     cls: 'ri-document' },
  other:    { icon: 'fa-link',         cls: 'ri-other' }
};

const BOOKMARK_KEY = 'wuc_digital_library_bookmarks_v1';

function esc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
function escAttr(v) { return (v == null ? '' : String(v)).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

function getBookmarks() {
  try {
    const raw = localStorage.getItem(BOOKMARK_KEY);
    return raw ? new Set(JSON.parse(raw)) : new Set();
  } catch (e) { return new Set(); }
}
function setBookmarks(set) {
  try { localStorage.setItem(BOOKMARK_KEY, JSON.stringify([...set])); } catch (e) {}
}
function toggleBookmark(id) {
  const set = getBookmarks();
  const sid = String(id);
  if (set.has(sid)) set.delete(sid); else set.add(sid);
  setBookmarks(set);
  return set.has(sid);
}

function isNew(createdAt) {
  if (!createdAt) return false;
  const created = new Date(createdAt.replace(' ', 'T'));
  if (isNaN(created)) return false;
  return (Date.now() - created.getTime()) < 7 * 24 * 60 * 60 * 1000;
}

function relativeDate(createdAt) {
  if (!createdAt) return '';
  const d = new Date(createdAt.replace(' ', 'T'));
  if (isNaN(d)) return '';
  const diff = Math.floor((Date.now() - d.getTime()) / 86400000);
  if (diff <= 0) return 'today';
  if (diff === 1) return 'yesterday';
  if (diff < 7) return diff + ' days ago';
  if (diff < 30) return Math.floor(diff/7) + 'w ago';
  if (diff < 365) return Math.floor(diff/30) + 'mo ago';
  return Math.floor(diff/365) + 'y ago';
}

function showLibraryMessage(type, msg) {
  $('#libraryMessage').removeClass('d-none alert-info alert-danger alert-warning alert-success')
    .addClass('alert-' + type).text(msg);
}
function hideLibraryMessage() { $('#libraryMessage').addClass('d-none').text(''); }

function renderSkeleton() {
  const $g = $('#resourceGrid').empty();
  for (let i = 0; i < 6; i++) {
    $g.append(`
      <div class="skeleton-card">
        <div class="skeleton-shimmer" style="width:48px;height:48px;border-radius:12px;"></div>
        <div class="skeleton-shimmer" style="width:80%;height:14px;margin-top:14px;"></div>
        <div class="skeleton-shimmer" style="width:60%;height:14px;margin-top:8px;"></div>
        <div class="skeleton-shimmer" style="width:100%;height:38px;margin-top:18px;"></div>
      </div>`);
  }
}

function renderEmpty(msg) {
  $('#resourceGrid').html(`
    <div class="empty-state" style="grid-column: 1/-1;">
      <i class="fas fa-folder-open"></i>
      <h5>${esc(msg || 'No resources found')}</h5>
      <p class="mb-0">Try a different search, or clear the filters to browse everything.</p>
    </div>`);
}

function sourceLabel(source) {
  const labels = {
    library: 'Library',
    elearning: 'eLearning',
    short_course: 'Short course',
    suggested_video: 'Suggested video',
    suggested_resource: 'Suggested resource'
  };
  return labels[source] || 'Resource';
}

function renderLearningContext(courses) {
  const $pills = $('#coursePills').empty();
  if (!courses || courses.length === 0) {
    $pills.append('<span class="course-pill">No assigned courses found</span>');
    $('#recommendationHint').text('Showing general library resources.');
    return;
  }
  courses.slice(0, 10).forEach(c => {
    const code = c.course_code || '';
    const name = c.course_name ? ' - ' + c.course_name : '';
    $pills.append(`<span class="course-pill">${esc(code + name)}</span>`);
  });
  $('#recommendationHint').text('Recommendations are ranked from your active course registrations.');
}

function renderCard(r) {
  const typeKey = (r.resource_type || 'other').toLowerCase();
  const ti = TYPE_ICONS[typeKey] || TYPE_ICONS.other;
  const url = r.url || '';
  const id = r.id;
  const bookmarks = getBookmarks();
  const isBookmarked = bookmarks.has(String(id));
  const views = parseInt(r.views || 0, 10);
  const fresh = isNew(r.created_at);
  const subject = (r.subject || '').trim();
  const rel = relativeDate(r.created_at);
  const recommended = parseInt(r.recommended || 0, 10) === 1;
  const source = sourceLabel(r.source || 'library');
  const reason = (r.match_reason || '').trim();
  const trackable = !r.synthetic && /^\d+$/.test(String(id));

  const pills = [];
  if (recommended) pills.push(`<span class="meta-pill recommended"><i class="fas fa-star"></i> Recommended</span>`);
  if (subject) pills.push(`<span class="meta-pill">${esc(subject)}</span>`);
  if (source) pills.push(`<span class="meta-pill source">${esc(source)}</span>`);
  if (fresh) pills.push(`<span class="meta-pill new"><i class="fas fa-bolt"></i> New</span>`);
  if (views > 0) pills.push(`<span class="meta-pill views"><i class="fas fa-eye"></i> ${views}</span>`);
  if (rel) pills.push(`<span class="meta-pill" style="background:#f1f3f5;color:#6c757d;">${esc(rel)}</span>`);
  if (reason) pills.push(`<span class="meta-pill" style="background:#fff7ed;color:#9a3412;">${esc(reason)}</span>`);

  const openBtn = url
    ? `<a href="${escAttr(url)}" class="btn-open view-btn" data-id="${escAttr(id)}" data-trackable="${trackable ? '1' : '0'}" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt me-1"></i> Open</a>`
    : `<span class="btn-open disabled"><i class="fas fa-lock me-1"></i> No Access</span>`;

  return `
    <div class="resource-card" data-id="${escAttr(id)}" data-type="${escAttr(typeKey)}">
      <button type="button" class="bookmark-btn ${isBookmarked ? 'active' : ''}" data-id="${escAttr(id)}" title="Bookmark" style="position:absolute;top:14px;right:14px;">
        <i class="${isBookmarked ? 'fas' : 'far'} fa-bookmark"></i>
      </button>
      <div class="resource-icon ${ti.cls}"><i class="fas ${ti.icon}"></i></div>
      <h6>${esc(r.title)}</h6>
      <div class="resource-meta">${pills.join('')}</div>
      <p class="desc">${esc(r.description || 'No description provided.')}</p>
      <div class="resource-actions">${openBtn}</div>
    </div>`;
}

let lastResults = [];
let activeType = '';
let activeSort = 'recommended';
let bookmarksOnly = false;
let recommendedOnly = true;

function applySortAndRender() {
  let data = lastResults.slice();
  if (recommendedOnly) {
    data = data.filter(r => parseInt(r.recommended || 0, 10) === 1 || parseInt(r.recommendation_score || 0, 10) >= 30);
  }
  if (bookmarksOnly) {
    const bm = getBookmarks();
    data = data.filter(r => bm.has(String(r.id)));
  }
  if (activeSort === 'recommended') {
    data.sort((a,b) => {
      const score = (parseInt(b.recommendation_score || 0, 10)) - (parseInt(a.recommendation_score || 0, 10));
      if (score !== 0) return score;
      return (parseInt(b.id || 0, 10)) - (parseInt(a.id || 0, 10));
    });
  } else if (activeSort === 'popular') {
    data.sort((a,b) => (parseInt(b.views||0,10)) - (parseInt(a.views||0,10)));
  } else if (activeSort === 'title') {
    data.sort((a,b) => String(a.title||'').localeCompare(String(b.title||'')));
  } else {
    data.sort((a,b) => (parseInt(b.id||0,10)) - (parseInt(a.id||0,10)));
  }

  $('#resultCount').html(
    data.length === 0
      ? 'No results'
      : `Showing <strong>${data.length}</strong> resource${data.length === 1 ? '' : 's'}`
  );

  if (data.length === 0) {
    renderEmpty(bookmarksOnly ? 'No bookmarks yet - tap the bookmark icon on any card to save it here.' : 'No course-matched resources found. Use All to browse the full library.');
    return;
  }

  const $g = $('#resourceGrid').empty();
  data.forEach(r => $g.append(renderCard(r)));
}

function load(params = {}) {
  hideLibraryMessage();
  renderSkeleton();
  $('#resultCount').text('Loading resources...');

  $.getJSON('portal-js/digital_api.php', params, function(d) {
    if (d.error) {
      showLibraryMessage('danger', d.error);
      lastResults = [];
      $('#resourceGrid').empty();
      renderEmpty('Unable to load resources.');
      $('#resultCount').text('');
      return;
    }
    lastResults = d.results || [];
    renderLearningContext(d.learning_context || []);
    applySortAndRender();
  }).fail(function() {
    showLibraryMessage('danger', 'Server error: could not load digital library resources.');
    lastResults = [];
    renderEmpty('Unable to load resources.');
    $('#resultCount').text('');
  });
}

function runSearch() {
  const q = $('#digitalSearch').val().trim();
  if (!q && !activeType) {
    bookmarksOnly = false;
    load({ action: 'list' });
    return;
  }
  bookmarksOnly = false;
  recommendedOnly = false;
  load({ action: 'search', q: q, type: activeType });
}

let debounceTimer = null;
function debouncedSearch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(runSearch, 300);
}

$(function() {
  load({ action: 'list' });

  $('#searchForm').on('submit', function(e) {
    e.preventDefault();
    runSearch();
  });

  $('#digitalSearch').on('input', debouncedSearch);

  $('#typeChips').on('click', '.dl-chip', function() {
    $('#typeChips .dl-chip').removeClass('active');
    $(this).addClass('active');
    const t = $(this).data('type') || '';
    if (t === '__bookmarks') {
      bookmarksOnly = true;
      recommendedOnly = false;
      activeType = '';
      applySortAndRender();
    } else if (t === '__recommended') {
      bookmarksOnly = false;
      recommendedOnly = true;
      activeType = '';
      load({ action: 'list' });
    } else {
      bookmarksOnly = false;
      recommendedOnly = false;
      activeType = t === '__all' ? '' : t;
      runSearch();
    }
  });

  $('#sortSelect').on('change', function() {
    activeSort = $(this).val();
    applySortAndRender();
  });

  // Bookmark toggle - does not navigate
  $('#resourceGrid').on('click', '.bookmark-btn', function(e) {
    e.preventDefault();
    e.stopPropagation();
    const id = $(this).data('id');
    const nowOn = toggleBookmark(id);
    $(this).toggleClass('active', nowOn);
    $(this).find('i').attr('class', (nowOn ? 'fas' : 'far') + ' fa-bookmark');
    if (bookmarksOnly) applySortAndRender();
  });

  // View tracking - fire-and-forget via sendBeacon so the popup blocker
  // never sees an async window.open() and the analytics call doesn't
  // delay the user reaching the resource.
  $('#resourceGrid').on('click', '.view-btn', function() {
    if ($(this).data('trackable') !== 1) {
      return;
    }
    const id = $(this).data('id');
    try {
      const fd = new FormData();
      fd.append('action', 'view');
      fd.append('id', id);
      if (navigator.sendBeacon) {
        navigator.sendBeacon('portal-js/digital_api.php', fd);
      } else {
        $.post('portal-js/digital_api.php', { action: 'view', id: id });
      }
    } catch (e) {}
    // Let the default anchor target=_blank behavior open the tab.
  });

  // Keyboard shortcut: "/" focuses search
  $(document).on('keydown', function(e) {
    if (e.key === '/' && !$(e.target).is('input,textarea,select')) {
      e.preventDefault();
      $('#digitalSearch').focus();
    }
  });

  // --- AI Librarian Interactions ---
  function scrollAiThread() {
    const thread = document.getElementById('aiLibThread');
    if (thread) thread.scrollTop = thread.scrollHeight;
  }

  function appendUserMsg(text) {
    const html = `
      <div class="ai-lib-msg user">
        <div class="ai-lib-avatar"><i class="fas fa-user"></i></div>
        <div class="ai-lib-bubble">${esc(text)}</div>
      </div>`;
    $('#aiLibThread').append(html);
    scrollAiThread();
  }

  function appendAssistantMsg(html) {
    const msgHtml = `
      <div class="ai-lib-msg assistant">
        <div class="ai-lib-avatar"><i class="fas fa-robot"></i></div>
        <div class="ai-lib-bubble">${html}</div>
      </div>`;
    $('#aiLibThread').append(msgHtml);
    scrollAiThread();
  }

  function showTypingIndicator() {
    const html = `
      <div class="ai-lib-msg assistant" id="aiLibTyping">
        <div class="ai-lib-avatar"><i class="fas fa-robot"></i></div>
        <div class="ai-lib-bubble">
          <div class="ai-lib-typing">
            <span></span><span></span><span></span>
          </div>
        </div>
      </div>`;
    $('#aiLibThread').append(html);
    scrollAiThread();
  }

  function removeTypingIndicator() {
    $('#aiLibTyping').remove();
  }

  let aiBusy = false;
  function sendAiMessage(messageText) {
    if (aiBusy || !messageText.trim()) return;
    aiBusy = true;
    
    appendUserMsg(messageText);
    $('#aiLibInput').val('').attr('disabled', true);
    $('#aiLibSend').attr('disabled', true);
    
    showTypingIndicator();
    
    $.post('digital_library.php', {
      action: 'ai_chat',
      message: messageText,
      csrf_token: csrf
    }, function(res) {
      removeTypingIndicator();
      $('#aiLibInput').attr('disabled', false).focus();
      $('#aiLibSend').attr('disabled', false);
      aiBusy = false;
      
      if (res.ok) {
        appendAssistantMsg(res.reply_html);
      } else {
        appendAssistantMsg(`<div class="text-danger"><i class="fas fa-circle-exclamation me-1"></i> ${esc(res.message || 'An error occurred.')}</div>`);
      }
    }, 'json').fail(function(xhr) {
      removeTypingIndicator();
      $('#aiLibInput').attr('disabled', false).focus();
      $('#aiLibSend').attr('disabled', false);
      aiBusy = false;
      
      let errMsg = 'Connection error. Please try again.';
      try {
        const data = JSON.parse(xhr.responseText);
        if (data && data.message) errMsg = data.message;
      } catch(e) {}
      
      appendAssistantMsg(`<div class="text-danger"><i class="fas fa-circle-exclamation me-1"></i> ${esc(errMsg)}</div>`);
    });
  }

  // Toggle AI Librarian Sidebar Panel
  $('#toggleAiBtn').on('click', function() {
    const isHidden = $('#libraryAiCol').hasClass('d-none');
    if (isHidden) {
      $('#libraryAiCol').removeClass('d-none');
      $('#libraryMainCol').removeClass('col-lg-12').addClass('col-lg-8');
      $(this).addClass('active').html('<i class="fas fa-robot me-1"></i> Close AI');
      scrollAiThread();
    } else {
      $('#libraryAiCol').addClass('d-none');
      $('#libraryMainCol').removeClass('col-lg-8').addClass('col-lg-12');
      $(this).removeClass('active').html('<i class="fas fa-robot me-1"></i> Ask AI Librarian');
    }
  });

  // Trigger send on click
  $('#aiLibSend').on('click', function() {
    const text = $('#aiLibInput').val();
    sendAiMessage(text);
  });

  // Trigger send on Enter (without Shift)
  $('#aiLibInput').on('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      const text = $(this).val();
      sendAiMessage(text);
    }
  });

  // Handle chip clicks inside chat thread
  $('#aiLibThread').on('click', '.ai-lib-chip', function() {
    const text = $(this).data('text');
    sendAiMessage(text);
  });

  // Handle reset chat
  $('#resetAiChat').on('click', function() {
    if (aiBusy) return;
    if (!confirm('Are you sure you want to clear the conversation history?')) return;
    
    aiBusy = true;
    $.post('digital_library.php', {
      action: 'ai_chat',
      reset: 1,
      csrf_token: csrf
    }, function(res) {
      aiBusy = false;
      if (res.ok) {
        $('#aiLibThread').html(`
          <div class="ai-lib-msg assistant">
            <div class="ai-lib-avatar"><i class="fas fa-robot"></i></div>
            <div class="ai-lib-bubble">
              <p class="mb-2">Conversation cleared. How else can I help you today? 👋</p>
              <div class="mt-2">
                <span class="ai-lib-chip" data-text="Recommend study resources for my active courses.">Recommend resources</span>
                <span class="ai-lib-chip" data-text="What are some good external video tutorials for my studies?">Video lessons</span>
                <span class="ai-lib-chip" data-text="Explain the core topics of my registered courses.">Explain concepts</span>
              </div>
            </div>
          </div>
        `);
      }
    }, 'json').fail(function() {
      aiBusy = false;
      alert('Failed to clear conversation history.');
    });
  });
});
</script>
</body></html>
